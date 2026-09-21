<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Conversation {
  id: string
  type: string
  provider: string
  participant: string | null
  preview: string | null
  status: string
  status_label: string
  assignee: string | null
  unread: number
  tags: string[]
  last_message_at: string | null
}
interface Message {
  id: string
  type: string
  author: string | null
  body: string
  sent_at: string | null
}
interface Thread extends Conversation {
  messages: Message[]
}

const auth = useAuthStore()
const toasts = useToastStore()

const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const statusFilter = ref('')
const conversations = ref<Conversation[]>([])
const loading = ref(false)
const syncing = ref(false)

const active = ref<Thread | null>(null)
const loadingThread = ref(false)
const replyBody = ref('')
const noteBody = ref('')
const sending = ref(false)
const suggesting = ref(false)
const showNote = ref(false)

const canUseAi = auth.can('ai.generate_text')
const statuses = [
  { key: '', label: 'Todas' },
  { key: 'open', label: 'Abiertas' },
  { key: 'pending', label: 'Pendientes' },
  { key: 'resolved', label: 'Resueltas' },
]
const typeIcons: Record<string, string> = { comment: 'content', dm: 'inbox', mention: 'bell' }

async function loadList(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  try {
    const { data } = await http.get(`/brands/${brandId.value}/inbox`, {
      params: statusFilter.value ? { status: statusFilter.value } : {},
    })
    conversations.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function open(c: Conversation): Promise<void> {
  loadingThread.value = true
  try {
    const { data } = await http.get(`/inbox/${c.id}`)
    active.value = data.data
    c.unread = 0
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loadingThread.value = false
  }
}

async function sync(): Promise<void> {
  if (!brandId.value) return
  syncing.value = true
  try {
    const { data } = await http.post(`/brands/${brandId.value}/inbox/sync`)
    toasts.success(`Inbox actualizado: ${data.data.conversations} conversaciones nuevas.`)
    await loadList()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    syncing.value = false
  }
}

async function sendReply(): Promise<void> {
  if (!active.value || !replyBody.value.trim()) return
  sending.value = true
  try {
    const { data } = await http.post(`/inbox/${active.value.id}/reply`, { body: replyBody.value })
    active.value.messages.push(data.data)
    replyBody.value = ''
    toasts.success('Respuesta enviada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    sending.value = false
  }
}

async function addNote(): Promise<void> {
  if (!active.value || !noteBody.value.trim()) return
  sending.value = true
  try {
    const { data } = await http.post(`/inbox/${active.value.id}/note`, { body: noteBody.value })
    active.value.messages.push(data.data)
    noteBody.value = ''
    showNote.value = false
    toasts.success('Nota añadida.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    sending.value = false
  }
}

async function suggest(): Promise<void> {
  if (!active.value) return
  suggesting.value = true
  try {
    const { data } = await http.post(`/inbox/${active.value.id}/suggest`)
    replyBody.value = data.data.suggestion
    toasts.success(`Sugerencia lista (${data.data.credits} créditos).`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    suggesting.value = false
  }
}

async function setStatus(status: string): Promise<void> {
  if (!active.value) return
  try {
    await http.post(`/inbox/${active.value.id}/status`, { status })
    active.value.status = status
    const inList = conversations.value.find((c) => c.id === active.value!.id)
    if (inList) inList.status = status
    toasts.success('Estado actualizado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function assignToMe(): Promise<void> {
  if (!active.value || !auth.user) return
  try {
    const { data } = await http.post(`/inbox/${active.value.id}/assign`, { assignee: auth.user.id })
    active.value.assignee = data.data.assignee
    toasts.success('Asignada a ti.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function bubbleClass(type: string): string {
  if (type === 'reply') return 'ml-auto bg-brand-600 text-white'
  if (type === 'note') return 'mx-auto bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200'
  return 'mr-auto bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100'
}
function time(value: string | null): string {
  return value ? new Date(value).toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' }) : ''
}

watch([brandId, statusFilter], () => {
  active.value = null
  loadList()
})
onMounted(loadList)
</script>

<template>
  <div>
    <PageHeader title="Inbox" description="Comentarios y mensajes de tus redes en un solo lugar.">
      <template #actions>
        <BrandPicker v-model="brandId" />
        <button class="btn-secondary text-sm" :disabled="syncing || !brandId" @click="sync">
          <AppIcon name="refresh" :size="16" :class="syncing ? 'animate-spin' : ''" /> Actualizar
        </button>
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" description="El inbox se organiza por marca." />

    <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <!-- Lista -->
      <div class="card flex flex-col overflow-hidden lg:col-span-1">
        <div class="flex gap-1 border-b border-slate-100 p-2 dark:border-slate-800">
          <button
            v-for="s in statuses"
            :key="s.key"
            class="flex-1 rounded-md px-2 py-1.5 text-xs font-medium transition"
            :class="statusFilter === s.key ? 'bg-brand-600 text-white' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'"
            @click="statusFilter = s.key"
          >
            {{ s.label }}
          </button>
        </div>

        <div v-if="loading" class="p-4"><div v-for="n in 5" :key="n" class="skeleton my-2 h-12 w-full" /></div>
        <EmptyState v-else-if="conversations.length === 0" icon="inbox" title="Sin conversaciones" description="Pulsa «Actualizar» para traer mensajes." class="border-0" />
        <ul v-else class="max-h-[70vh] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
          <li v-for="c in conversations" :key="c.id">
            <button
              class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
              :class="active?.id === c.id ? 'bg-slate-50 dark:bg-slate-800/50' : ''"
              @click="open(c)"
            >
              <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-950/50">
                <AppIcon :name="typeIcons[c.type] ?? 'inbox'" :size="16" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="flex items-center justify-between gap-2">
                  <span class="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{{ c.participant || 'Anónimo' }}</span>
                  <span v-if="c.unread > 0" class="shrink-0 rounded-full bg-brand-600 px-1.5 text-[10px] font-bold text-white">{{ c.unread }}</span>
                </span>
                <span class="line-clamp-1 block text-xs text-slate-500">{{ c.preview }}</span>
                <span class="mt-0.5 flex items-center gap-1.5 text-[10px] text-slate-400">
                  <span class="capitalize">{{ c.provider }}</span> · <span>{{ c.status_label }}</span>
                  <span v-if="c.assignee">· {{ c.assignee }}</span>
                </span>
              </span>
            </button>
          </li>
        </ul>
      </div>

      <!-- Hilo -->
      <div class="card flex min-h-[60vh] flex-col overflow-hidden lg:col-span-2">
        <EmptyState v-if="!active && !loadingThread" icon="inbox" title="Selecciona una conversación" description="Elige un mensaje de la lista para responder." class="my-auto border-0" />
        <div v-else-if="loadingThread" class="p-6"><div class="skeleton h-64 w-full" /></div>

        <template v-else-if="active">
          <!-- Toolbar -->
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-3 dark:border-slate-800">
            <div class="min-w-0">
              <p class="truncate font-semibold text-slate-900 dark:text-white">{{ active.participant || 'Anónimo' }}</p>
              <p class="text-xs capitalize text-slate-400">{{ active.provider }} · {{ active.type }}</p>
            </div>
            <div class="flex items-center gap-2">
              <button class="btn-ghost text-xs" @click="assignToMe">
                <AppIcon name="user" :size="14" /> {{ active.assignee || 'Asignarme' }}
              </button>
              <select
                class="input w-auto py-1 text-xs"
                :value="active.status"
                @change="setStatus(($event.target as HTMLSelectElement).value)"
              >
                <option value="open">Abierta</option>
                <option value="pending">Pendiente</option>
                <option value="resolved">Resuelta</option>
                <option value="snoozed">Pospuesta</option>
              </select>
            </div>
          </div>

          <!-- Mensajes -->
          <div class="flex-1 space-y-3 overflow-y-auto p-4">
            <div v-for="m in active.messages" :key="m.id" class="flex flex-col">
              <div class="max-w-[80%] rounded-2xl px-3.5 py-2 text-sm" :class="bubbleClass(m.type)">
                <p v-if="m.type === 'note'" class="mb-1 text-[10px] font-bold uppercase tracking-wide">Nota interna</p>
                <p class="whitespace-pre-wrap">{{ m.body }}</p>
              </div>
              <span class="mt-1 px-1 text-[10px] text-slate-400" :class="m.type === 'reply' ? 'ml-auto' : ''">
                {{ m.author }} · {{ time(m.sent_at) }}
              </span>
            </div>
          </div>

          <!-- Composer -->
          <div class="border-t border-slate-100 p-3 dark:border-slate-800">
            <div v-if="showNote" class="mb-2">
              <textarea v-model="noteBody" rows="2" class="input" placeholder="Nota interna (no se envía)…" />
              <div class="mt-1 flex justify-end gap-2">
                <button class="btn-ghost text-xs" @click="showNote = false">Cancelar</button>
                <button class="btn-secondary text-xs" :disabled="sending" @click="addNote">Guardar nota</button>
              </div>
            </div>
            <textarea v-model="replyBody" rows="2" class="input" placeholder="Escribe una respuesta…" />
            <div class="mt-2 flex items-center justify-between">
              <div class="flex items-center gap-2">
                <button v-if="canUseAi" class="btn-secondary text-xs" :disabled="suggesting" @click="suggest">
                  <AppIcon name="sparkles" :size="14" /> {{ suggesting ? 'Sugiriendo…' : 'Sugerir con IA' }}
                </button>
                <button class="btn-ghost text-xs" @click="showNote = !showNote">
                  <AppIcon name="content" :size="14" /> Nota
                </button>
              </div>
              <button class="btn-primary text-sm" :disabled="sending || !replyBody.trim()" @click="sendReply">
                <AppIcon name="chevron-right" :size="16" /> Responder
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
