<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Target {
  id: string
  status: string
  status_label: string
  destination: string | null
  remote_url: string | null
  error: string | null
}
interface Variant { id: string; provider: string; body: string | null; format: string; targets: Target[] }
interface Comment { id: string; body: string; author: string | null; created_at: string | null }
interface Content {
  id: string
  brand: string | null
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
const confirmDialog = useConfirmStore()
const id = route.params.content as string

const content = ref<Content | null>(null)
const loading = ref(true)
const failed = ref(false)
const busy = ref(false)

const newVariant = reactive({ provider: 'facebook', body: '', format: 'text' })
const newComment = ref('')
const scheduleAt = ref('')

// Asistente de IA
const canUseAi = computed(() => auth.can('ai.generate_text'))
const aiBrief = ref('')
const showAiBrief = ref(false)
const aiBusy = ref(false)

async function generateBase(): Promise<void> {
  if (!content.value?.brand) return
  if (!aiBrief.value.trim()) {
    toasts.error('Describe qué quieres publicar.')
    return
  }
  aiBusy.value = true
  try {
    const { data } = await http.post(`/brands/${content.value.brand}/ai/text`, {
      prompt: aiBrief.value,
      operation: 'generate_post',
    })
    content.value.body = data.data.text
    toasts.success(`Contenido generado (${data.data.credits} créditos).`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    aiBusy.value = false
  }
}

async function adaptVariant(): Promise<void> {
  if (!content.value?.brand) return
  const base = content.value.body?.trim() || content.value.title
  if (!base) {
    toasts.error('Escribe primero el contenido base.')
    return
  }
  aiBusy.value = true
  try {
    const { data } = await http.post(`/brands/${content.value.brand}/ai/text`, {
      prompt: base,
      operation: 'adapt_variant',
      network: newVariant.provider,
    })
    newVariant.body = data.data.text
    toasts.success(`Variante adaptada (${data.data.credits} créditos).`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    aiBusy.value = false
  }
}

// Clases del badge según el estado del destino de publicación.
const targetBadgeClass: Record<string, string> = {
  pending: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  scheduled: 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
  publishing: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
  published: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
  failed: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
  cancelled: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
}
const badgeClass = (status: string): string => targetBadgeClass[status] ?? targetBadgeClass.pending

// ¿Hay algún destino aún en curso? Si es así, refrescamos en segundo plano.
const isPublishing = computed(() =>
  content.value?.variants.some((v) =>
    v.targets.some((t) => ['scheduled', 'publishing', 'pending'].includes(t.status)),
  ) ?? false,
)

let poll: ReturnType<typeof setInterval> | null = null

function stopPolling(): void {
  if (poll) {
    clearInterval(poll)
    poll = null
  }
}

function ensurePolling(): void {
  if (isPublishing.value && !poll) {
    poll = setInterval(() => void load(true), 4000)
  } else if (!isPublishing.value) {
    stopPolling()
  }
}

async function load(silent = false): Promise<void> {
  if (!silent) {
    loading.value = true
    failed.value = false
  }
  try {
    const { data } = await http.get(`/content/${id}`)
    content.value = data.data
    ensurePolling()
  } catch {
    if (!silent) failed.value = true
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

async function publishNow(): Promise<void> {
  const ok = await confirmDialog.ask({ title: 'Publicar ahora', message: 'Se publicará en todas las cuentas conectadas de las redes de este contenido. No se puede deshacer.', confirmText: 'Publicar' })
  if (!ok) return
  await act(() => http.post(`/content/${id}/publish-now`), 'Publicación en marcha.')
}

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

onMounted(() => load())
onUnmounted(stopPolling)
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
        <div class="flex items-center gap-3">
          <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
            {{ content.status_label }}
          </span>
          <span v-if="isPublishing" class="flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400">
            <AppIcon name="refresh" :size="14" class="animate-spin" /> Publicando…
          </span>
        </div>
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
          <button
            v-if="['approved', 'scheduled'].includes(content.status) && auth.can('content.publish_now')"
            class="btn-primary text-sm"
            :disabled="busy"
            @click="publishNow"
          >
            <AppIcon name="social" :size="16" /> Publicar ahora
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Contenido base + variantes -->
        <div class="space-y-6 lg:col-span-2">
          <div class="card p-6">
            <div class="mb-4 flex items-center justify-between">
              <h3 class="font-semibold text-slate-900 dark:text-white">Contenido base</h3>
              <button
                v-if="content.editable && canUseAi"
                class="btn-secondary text-sm"
                :disabled="aiBusy"
                @click="showAiBrief = !showAiBrief"
              >
                <AppIcon name="sparkles" :size="16" /> Generar con IA
              </button>
            </div>

            <!-- Brief para generación con IA -->
            <div v-if="showAiBrief && content.editable && canUseAi" class="mb-4 rounded-lg border border-brand-100 bg-brand-50/50 p-3 dark:border-brand-900 dark:bg-brand-950/30">
              <textarea
                v-model="aiBrief"
                rows="2"
                class="input"
                placeholder="Describe qué quieres comunicar (p. ej. lanzamiento de producto, promoción de verano…)"
              />
              <div class="mt-2 flex justify-end">
                <button class="btn-primary text-sm" :disabled="aiBusy" @click="generateBase">
                  <AppIcon name="sparkles" :size="16" /> {{ aiBusy ? 'Generando…' : 'Generar borrador' }}
                </button>
              </div>
            </div>

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

              <!-- Estado de publicación por destino -->
              <div v-if="v.targets.length" class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
                <div v-for="t in v.targets" :key="t.id" class="flex flex-wrap items-center gap-2 text-xs">
                  <span class="rounded-full px-2 py-0.5 font-semibold" :class="badgeClass(t.status)">{{ t.status_label }}</span>
                  <span class="text-slate-500 dark:text-slate-400">{{ t.destination || 'Destino' }}</span>
                  <a
                    v-if="t.remote_url"
                    :href="t.remote_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-brand-600 hover:underline dark:text-brand-300"
                  >
                    Ver publicación <AppIcon name="chevron-right" :size="12" />
                  </a>
                  <span v-if="t.error" class="text-rose-500" :title="t.error">· {{ t.error }}</span>
                </div>
              </div>
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
              <button
                v-if="canUseAi"
                type="button"
                class="btn-secondary"
                :disabled="aiBusy"
                :title="'Adaptar el contenido base para ' + newVariant.provider"
                @click="adaptVariant"
              >
                <AppIcon name="sparkles" :size="16" /> IA
              </button>
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
