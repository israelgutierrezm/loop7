<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Variant { id: string; provider: string; body: string | null; format: string }
interface Comment { id: string; body: string; author: string | null; created_at: string | null }
interface Content {
  id: string
  title: string
  body: string | null
  type: string
  status: string
  status_label: string
  editable: boolean
  scheduled_at: string | null
  variants: Variant[]
  comments: Comment[]
}

const route = useRoute()
const auth = useAuthStore()
const toasts = useToastStore()
const id = route.params.content as string

const content = ref<Content | null>(null)
const loading = ref(true)
const failed = ref(false)
const busy = ref(false)

const newVariant = reactive({ provider: 'facebook', body: '', format: 'text' })
const newComment = ref('')
const scheduleAt = ref('')

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get(`/content/${id}`)
    content.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function act(fn: () => Promise<unknown>, successMsg?: string): Promise<void> {
  busy.value = true
  try {
    await fn()
    if (successMsg) toasts.success(successMsg)
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

const submit = () => act(() => http.post(`/content/${id}/submit`), 'Enviado a revisión.')
const approve = () => act(() => http.post(`/content/${id}/approve`), 'Aprobado.')

async function requestChanges(): Promise<void> {
  const note = prompt('¿Qué cambios solicitas?')
  if (!note) return
  await act(() => http.post(`/content/${id}/request-changes`, { note }), 'Cambios solicitados.')
}

async function schedule(): Promise<void> {
  if (!scheduleAt.value) {
    toasts.error('Elige fecha y hora.')
    return
  }
  await act(() => http.post(`/content/${id}/schedule`, { scheduled_at: new Date(scheduleAt.value).toISOString() }), 'Programado.')
}

async function addVariant(): Promise<void> {
  if (!newVariant.provider) return
  await act(async () => {
    await http.post(`/content/${id}/variants`, { ...newVariant })
    newVariant.body = ''
  }, 'Variante añadida.')
}

const deleteVariant = (variantId: string) => act(() => http.delete(`/variants/${variantId}`))

async function addComment(): Promise<void> {
  if (!newComment.value.trim()) return
  await act(async () => {
    await http.post(`/content/${id}/comments`, { body: newComment.value })
    newComment.value = ''
  })
}

async function saveBase(): Promise<void> {
  if (!content.value) return
  await act(
    () => http.patch(`/content/${id}`, { title: content.value!.title, body: content.value!.body }),
    'Guardado.',
  )
}

onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading" class="card p-6"><div class="skeleton h-48 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else-if="content">
      <PageHeader :title="content.title">
        <template #actions>
          <RouterLink to="/app/content" class="btn-secondary text-sm">
            <AppIcon name="chevron-left" :size="16" /> Contenido
          </RouterLink>
        </template>
      </PageHeader>

      <!-- Estado + acciones de flujo -->
      <div class="card mb-6 flex flex-wrap items-center justify-between gap-3 p-4">
        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
          {{ content.status_label }}
        </span>
        <div class="flex flex-wrap items-center gap-2">
          <button v-if="content.editable && auth.can('content.submit_for_review')" class="btn-secondary text-sm" :disabled="busy" @click="submit">
            Enviar a revisión
          </button>
          <template v-if="content.status === 'in_review'">
            <button v-if="auth.can('content.approve')" class="btn-primary text-sm" :disabled="busy" @click="approve">Aprobar</button>
            <button v-if="auth.can('content.reject')" class="btn-secondary text-sm text-rose-600" :disabled="busy" @click="requestChanges">Solicitar cambios</button>
          </template>
          <template v-if="content.status === 'approved' && auth.can('content.schedule')">
            <input v-model="scheduleAt" type="datetime-local" class="input w-auto py-1.5 text-sm" />
            <button class="btn-primary text-sm" :disabled="busy" @click="schedule">Programar</button>
          </template>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Contenido base + variantes -->
        <div class="space-y-6 lg:col-span-2">
          <div class="card p-6">
            <h3 class="mb-4 font-semibold text-slate-900 dark:text-white">Contenido base</h3>
            <fieldset :disabled="!content.editable || !auth.can('content.update')" class="space-y-4">
              <input v-model="content.title" class="input" placeholder="Título" />
              <textarea v-model="content.body" rows="4" class="input" placeholder="Texto base" />
            </fieldset>
            <div v-if="content.editable && auth.can('content.update')" class="mt-4 flex justify-end">
              <button class="btn-primary text-sm" :disabled="busy" @click="saveBase">Guardar</button>
            </div>
          </div>

          <div class="card p-6">
            <h3 class="mb-4 font-semibold text-slate-900 dark:text-white">Variantes por red</h3>
            <div v-if="content.variants.length === 0" class="text-sm text-slate-500">Sin variantes todavía.</div>
            <div v-for="v in content.variants" :key="v.id" class="mb-3 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
              <div class="mb-1 flex items-center justify-between">
                <span class="text-sm font-semibold capitalize text-brand-700 dark:text-brand-300">{{ v.provider }}</span>
                <button v-if="content.editable && auth.can('content.update')" class="text-rose-500" @click="deleteVariant(v.id)">
                  <AppIcon name="close" :size="16" />
                </button>
              </div>
              <p class="text-sm text-slate-600 dark:text-slate-300">{{ v.body || '(sin texto)' }}</p>
            </div>

            <form v-if="content.editable && auth.can('content.update')" class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="addVariant">
              <select v-model="newVariant.provider" class="input w-auto">
                <option value="facebook">Facebook</option>
                <option value="instagram">Instagram</option>
                <option value="linkedin">LinkedIn</option>
                <option value="x">X</option>
                <option value="tiktok">TikTok</option>
              </select>
              <input v-model="newVariant.body" class="input flex-1" placeholder="Texto adaptado para esta red" />
              <button type="submit" class="btn-primary" :disabled="busy">Añadir</button>
            </form>
          </div>
        </div>

        <!-- Comentarios -->
        <div class="card p-6">
          <h3 class="mb-4 font-semibold text-slate-900 dark:text-white">Comentarios</h3>
          <div class="space-y-3">
            <div v-for="c in content.comments" :key="c.id" class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/50">
              <p class="text-sm text-slate-700 dark:text-slate-200">{{ c.body }}</p>
              <p class="mt-1 text-xs text-slate-400">{{ c.author }}</p>
            </div>
            <p v-if="content.comments.length === 0" class="text-sm text-slate-400">Sin comentarios.</p>
          </div>
          <form class="mt-4 flex gap-2" @submit.prevent="addComment">
            <input v-model="newComment" class="input flex-1" placeholder="Escribe un comentario" />
            <button type="submit" class="btn-secondary" :disabled="busy">Enviar</button>
          </form>
        </div>
      </div>
    </template>
  </div>
</template>
