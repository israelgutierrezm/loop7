<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage } from '@/utils/errors'
import { dateTime } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'
import ProviderIcon from '@/components/social/ProviderIcon.vue'
import MediaPicker, { type PickedMedia } from '@/components/media/MediaPicker.vue'
import type { SocialProviderOption } from '@/types/models'

interface Target {
  id: string
  status: string
  status_label: string
  destination: string | null
  scheduled_at: string | null
  published_at: string | null
  remote_url: string | null
  error: string | null
}
interface Variant { id: string; provider: string; body: string | null; format: string; media: PickedMedia[]; targets: Target[] }
interface Comment { id: string; body: string; author: string | null; created_at: string | null }
interface Content {
  id: string
  brand: string | null
  title: string
  body: string | null
  type: string
  type_label: string
  campaign: { id: string; name: string } | null
  status: string
  status_label: string
  editable: boolean
  scheduled_at: string | null
  variants: Variant[]
  comments: Comment[]
}

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()
const id = route.params.content as string

const content = ref<Content | null>(null)
const providers = ref<SocialProviderOption[]>([])
const campaigns = ref<{ id: string; name: string; status: string }[]>([])
const loading = ref(true)
const failed = ref(false)
const busy = ref(false)

const newVariant = reactive({ provider: '', body: '' })
const newComment = ref('')
const scheduleAt = ref('')
const editingVariant = ref<string | null>(null)
const variantDraft = ref('')
const pickerFor = ref<Variant | null>(null)
const changesOpen = ref(false)
const changesNote = ref('')

const approvalsEnabled = computed(() => auth.hasFeature('feature.approvals'))
const canEdit = computed(() => (content.value?.editable ?? false) && auth.can('content.update'))
const minSchedule = computed(() => {
  const d = new Date(Date.now() + 5 * 60_000)
  d.setSeconds(0, 0)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 16)
})
const availableProviders = computed(() => providers.value.filter((p) => !content.value?.variants.some((v) => v.provider === p.key)))

function providerName(key: string): string {
  return providers.value.find((p) => p.key === key)?.name ?? key
}

/** Instagram y otras redes sin texto solo exigen imagen o video. */
function needsMedia(v: Variant): boolean {
  const caps = providers.value.find((p) => p.key === v.provider)?.capabilities
  return caps?.text === false && v.media.length === 0
}

const targetTone: Record<string, BadgeTone> = {
  pending: 'neutral', scheduled: 'info', publishing: 'warning', published: 'success', failed: 'danger', cancelled: 'neutral',
}
const statusTone: Record<string, BadgeTone> = {
  idea: 'neutral', draft: 'neutral', in_review: 'info', changes_requested: 'warning', approved: 'brand',
  scheduled: 'info', publishing: 'warning', published: 'success', partial: 'warning', failed: 'danger', archived: 'neutral',
}

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
    const { data } = await http.post(`/brands/${content.value.brand}/ai/text`, { prompt: aiBrief.value, operation: 'generate_post' })
    content.value.body = data.data.text
    toasts.success(`Borrador generado (${data.data.credits} créditos). Revísalo y guarda.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    aiBusy.value = false
  }
}

async function adaptVariant(): Promise<void> {
  if (!content.value?.brand || !newVariant.provider) return
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
    toasts.success(`Texto adaptado para ${providerName(newVariant.provider)} (${data.data.credits} créditos).`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    aiBusy.value = false
  }
}

// Auto-refresco mientras se publica.
const isPublishing = computed(() =>
  content.value?.status === 'publishing'
  || (content.value?.variants.some((v) => v.targets.some((t) => t.status === 'publishing')) ?? false),
)
let poll: ReturnType<typeof setInterval> | null = null
function stopPolling(): void {
  if (poll) {
    clearInterval(poll)
    poll = null
  }
}
function ensurePolling(): void {
  if (isPublishing.value && !poll) poll = setInterval(() => void load(true), 4000)
  else if (!isPublishing.value) stopPolling()
}

async function load(silent = false): Promise<void> {
  if (!silent) {
    loading.value = true
    failed.value = false
  }
  try {
    const { data } = await http.get(`/content/${id}`)
    content.value = data.data
    if (providers.value.length === 0 && content.value?.brand) {
      const [res, camp] = await Promise.all([
        http.get(`/brands/${content.value.brand}/social/providers`),
        auth.can('campaigns.view') ? http.get(`/brands/${content.value.brand}/campaigns`) : Promise.resolve(null),
      ])
      providers.value = res.data.data
      campaigns.value = camp?.data.data ?? []
    }
    if (!newVariant.provider) newVariant.provider = availableProviders.value[0]?.key ?? ''
    ensurePolling()
  } catch {
    if (!silent) failed.value = true
  } finally {
    loading.value = false
  }
}

async function act(fn: () => Promise<unknown>, successMsg?: string): Promise<boolean> {
  busy.value = true
  try {
    await fn()
    if (successMsg) toasts.success(successMsg)
    await load(true)
    return true
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    return false
  } finally {
    busy.value = false
  }
}

const submit = () => act(() => http.post(`/content/${id}/submit`), 'Enviado a revisión.')
const approve = () => act(() => http.post(`/content/${id}/approve`), 'Aprobado.')

async function publishNow(): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Publicar ahora',
    message: 'Se publicará en todas las cuentas conectadas de las redes de este contenido. No se puede deshacer.',
    confirmText: 'Publicar',
  })
  if (ok) await act(() => http.post(`/content/${id}/publish-now`), 'Publicación en marcha.')
}

async function requestChanges(): Promise<void> {
  if (!changesNote.value.trim()) {
    toasts.error('Explica qué cambios necesitas.')
    return
  }
  const ok = await act(() => http.post(`/content/${id}/request-changes`, { note: changesNote.value }), 'Cambios solicitados.')
  if (ok) {
    changesOpen.value = false
    changesNote.value = ''
  }
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
  const ok = await act(() => http.post(`/content/${id}/variants`, { provider: newVariant.provider, body: newVariant.body || null }), 'Variante añadida.')
  if (ok) {
    newVariant.body = ''
    newVariant.provider = availableProviders.value[0]?.key ?? ''
  }
}

function startEdit(v: Variant): void {
  editingVariant.value = v.id
  variantDraft.value = v.body ?? ''
}

async function saveVariant(v: Variant): Promise<void> {
  const ok = await act(() => http.patch(`/variants/${v.id}`, { body: variantDraft.value }), 'Variante guardada.')
  if (ok) editingVariant.value = null
}

async function deleteVariant(v: Variant): Promise<void> {
  const ok = await confirmDialog.ask({ title: `Quitar la variante de ${providerName(v.provider)}`, confirmText: 'Quitar', danger: true })
  if (ok) await act(() => http.delete(`/variants/${v.id}`), 'Variante eliminada.')
}

async function setMedia(v: Variant, media: PickedMedia[]): Promise<void> {
  pickerFor.value = null
  await act(() => http.put(`/variants/${v.id}/media`, { media: media.map((m) => m.id) }), 'Multimedia actualizada.')
}

async function removeMedia(v: Variant, mediaId: string): Promise<void> {
  await act(() => http.put(`/variants/${v.id}/media`, { media: v.media.filter((m) => m.id !== mediaId).map((m) => m.id) }))
}

async function addComment(): Promise<void> {
  if (!newComment.value.trim()) return
  const ok = await act(() => http.post(`/content/${id}/comments`, { body: newComment.value }))
  if (ok) newComment.value = ''
}

async function saveBase(): Promise<void> {
  if (!content.value) return
  await act(() => http.patch(`/content/${id}`, { title: content.value!.title, body: content.value!.body }), 'Guardado.')
}

async function changeCampaign(campaignId: string): Promise<void> {
  await act(() => http.patch(`/content/${id}`, { campaign: campaignId || null }), campaignId ? 'Campaña asignada.' : 'Quitado de la campaña.')
}

async function removeContent(): Promise<void> {
  if (!content.value) return
  const ok = await confirmDialog.ask({
    title: 'Eliminar contenido',
    message: `Se eliminará «${content.value.title}». Si estaba programado, se cancela su publicación.`,
    confirmText: 'Eliminar',
    danger: true,
  })
  if (!ok) return
  busy.value = true
  try {
    await http.delete(`/content/${id}`)
    toasts.success('Contenido eliminado.')
    router.push('/app/content')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

onMounted(() => load())
onUnmounted(stopPolling)
</script>

<template>
  <div>
    <div v-if="loading" class="card p-6"><div class="skeleton h-48 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else-if="content">
      <PageHeader :title="content.title" :description="content.type_label">
        <template #actions>
          <template v-if="campaigns.length || content.campaign">
            <label class="sr-only" for="content-campaign">Campaña</label>
            <select
              v-if="auth.can('content.update')"
              id="content-campaign"
              class="input w-auto py-1.5 text-sm"
              :value="content.campaign?.id ?? ''"
              :disabled="busy"
              @change="changeCampaign(($event.target as HTMLSelectElement).value)"
            >
              <option value="">Sin campaña</option>
              <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <span v-else-if="content.campaign" class="rounded-md bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800">
              {{ content.campaign.name }}
            </span>
          </template>
          <button v-if="auth.can('content.delete')" type="button" class="btn-ghost text-sm text-rose-600" :disabled="busy" @click="removeContent">
            Eliminar
          </button>
          <RouterLink to="/app/content" class="btn-secondary text-sm">
            <AppIcon name="chevron-left" :size="16" /> Contenido
          </RouterLink>
        </template>
      </PageHeader>

      <!-- Estado + acciones de flujo -->
      <section class="card mb-6 flex flex-wrap items-center justify-between gap-3 p-4" aria-label="Estado y acciones">
        <div class="flex flex-wrap items-center gap-3">
          <StatusBadge :tone="statusTone[content.status] ?? 'neutral'" dot>{{ content.status_label }}</StatusBadge>
          <span v-if="content.scheduled_at && ['scheduled', 'publishing'].includes(content.status)" class="text-sm text-slate-500">
            {{ dateTime(content.scheduled_at) }}
          </span>
          <span v-if="isPublishing" class="flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400">
            <AppIcon name="refresh" :size="14" class="animate-spin" /> Publicando…
          </span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <template v-if="content.editable">
            <button
              v-if="approvalsEnabled && auth.can('content.submit_for_review')"
              type="button"
              class="btn-secondary text-sm"
              :disabled="busy"
              @click="submit"
            >
              Enviar a revisión
            </button>
            <button
              v-else-if="!approvalsEnabled && auth.can('content.approve')"
              type="button"
              class="btn-primary text-sm"
              :disabled="busy"
              @click="approve"
            >
              Marcar como listo
            </button>
          </template>
          <template v-if="content.status === 'in_review'">
            <button v-if="auth.can('content.approve')" type="button" class="btn-primary text-sm" :disabled="busy" @click="approve">Aprobar</button>
            <button v-if="auth.can('content.reject')" type="button" class="btn-secondary text-sm text-rose-600" :disabled="busy" @click="changesOpen = true">
              Solicitar cambios
            </button>
          </template>
          <form
            v-if="['approved', 'scheduled'].includes(content.status) && auth.can('content.schedule')"
            class="flex items-center gap-2"
            @submit.prevent="schedule"
          >
            <label for="schedule-at" class="sr-only">Fecha y hora de publicación</label>
            <input id="schedule-at" v-model="scheduleAt" type="datetime-local" :min="minSchedule" class="input w-auto py-1.5 text-sm" />
            <button type="submit" class="btn-secondary text-sm" :disabled="busy">
              {{ content.status === 'scheduled' ? 'Reprogramar' : 'Programar' }}
            </button>
          </form>
          <button
            v-if="['approved', 'scheduled'].includes(content.status) && auth.can('content.publish_now')"
            type="button"
            class="btn-primary text-sm"
            :disabled="busy"
            @click="publishNow"
          >
            <AppIcon name="social" :size="16" /> Publicar ahora
          </button>
        </div>
      </section>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <!-- Contenido base -->
          <section class="card p-6" aria-labelledby="base-title">
            <div class="mb-4 flex items-center justify-between gap-2">
              <h2 id="base-title" class="font-semibold text-slate-900 dark:text-white">Contenido base</h2>
              <button v-if="canEdit && canUseAi" type="button" class="btn-secondary text-sm" :disabled="aiBusy" @click="showAiBrief = !showAiBrief">
                <AppIcon name="sparkles" :size="16" /> Generar con IA
              </button>
            </div>

            <form
              v-if="showAiBrief && canEdit && canUseAi"
              class="mb-4 rounded-lg border border-brand-100 bg-brand-50/50 p-3 dark:border-brand-900 dark:bg-brand-950/30"
              @submit.prevent="generateBase"
            >
              <label for="ai-brief" class="label">¿Qué quieres comunicar?</label>
              <textarea id="ai-brief" v-model="aiBrief" rows="2" class="input" placeholder="Ej. lanzamiento del producto X con 20 % de descuento esta semana" />
              <div class="mt-2 flex justify-end">
                <button type="submit" class="btn-primary text-sm" :disabled="aiBusy">
                  <Spinner v-if="aiBusy" :size="16" /> {{ aiBusy ? 'Generando…' : 'Generar borrador' }}
                </button>
              </div>
            </form>

            <fieldset :disabled="!canEdit" class="space-y-4">
              <div>
                <label for="c-title" class="label">Título interno</label>
                <input id="c-title" v-model="content.title" class="input" />
              </div>
              <div>
                <label for="c-body" class="label">Texto base</label>
                <textarea id="c-body" v-model="content.body" rows="5" class="input" />
              </div>
            </fieldset>
            <div v-if="canEdit" class="mt-4 flex justify-end">
              <button type="button" class="btn-primary text-sm" :disabled="busy" @click="saveBase">Guardar</button>
            </div>
          </section>

          <!-- Variantes -->
          <section class="card p-6" aria-labelledby="var-title">
            <h2 id="var-title" class="font-semibold text-slate-900 dark:text-white">Publicaciones por red</h2>
            <p class="mb-4 text-sm text-slate-500">Cada red recibe su propio texto e imágenes; se publica en todas las cuentas conectadas de la marca.</p>

            <p v-if="content.variants.length === 0" class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500 dark:border-slate-700">
              Añade al menos una red para poder programar o publicar.
            </p>

            <article v-for="v in content.variants" :key="v.id" class="mb-4 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
              <header class="mb-2 flex items-center justify-between gap-2">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                  <ProviderIcon :provider="v.provider" :size="24" /> {{ providerName(v.provider) }}
                </span>
                <div v-if="canEdit" class="flex items-center gap-1">
                  <button v-if="editingVariant !== v.id" type="button" class="btn-ghost px-2 py-1 text-xs" @click="startEdit(v)">Editar texto</button>
                  <button type="button" class="grid h-7 w-7 place-items-center rounded-lg text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30" :aria-label="`Quitar ${providerName(v.provider)}`" @click="deleteVariant(v)">
                    <AppIcon name="close" :size="14" />
                  </button>
                </div>
              </header>

              <form v-if="editingVariant === v.id" class="space-y-2" @submit.prevent="saveVariant(v)">
                <label :for="`vb-${v.id}`" class="sr-only">Texto para {{ providerName(v.provider) }}</label>
                <textarea :id="`vb-${v.id}`" v-model="variantDraft" rows="4" class="input" />
                <div class="flex justify-end gap-2">
                  <button type="button" class="btn-ghost text-xs" @click="editingVariant = null">Cancelar</button>
                  <button type="submit" class="btn-primary text-xs" :disabled="busy">Guardar</button>
                </div>
              </form>
              <p v-else class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ v.body || '(usa el texto base)' }}</p>

              <!-- Multimedia -->
              <div class="mt-3 flex flex-wrap items-center gap-2">
                <div v-for="m in v.media" :key="m.id" class="group relative h-16 w-16 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
                  <img v-if="m.is_image" :src="m.url" :alt="m.original_name" class="h-full w-full object-cover" />
                  <video v-else :src="m.url" class="h-full w-full object-cover" muted preload="metadata" />
                  <button
                    v-if="canEdit"
                    type="button"
                    class="absolute right-0.5 top-0.5 hidden h-5 w-5 place-items-center rounded-full bg-slate-900/70 text-white group-hover:grid group-focus-within:grid"
                    :aria-label="`Quitar ${m.original_name}`"
                    @click="removeMedia(v, m.id)"
                  >
                    <AppIcon name="close" :size="12" />
                  </button>
                </div>
                <button v-if="canEdit" type="button" class="btn-secondary h-16 px-3 text-xs" @click="pickerFor = v">
                  <AppIcon name="plus" :size="14" /> {{ v.media.length ? 'Cambiar' : 'Imagen o video' }}
                </button>
              </div>
              <p v-if="needsMedia(v)" class="mt-2 flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300">
                <AppIcon name="alert" :size="14" /> {{ providerName(v.provider) }} necesita al menos una imagen o un video.
              </p>

              <!-- Estado por cuenta -->
              <ul v-if="v.targets.length" class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
                <li v-for="t in v.targets" :key="t.id" class="flex flex-wrap items-center gap-2 text-xs">
                  <StatusBadge :tone="targetTone[t.status] ?? 'neutral'">{{ t.status_label }}</StatusBadge>
                  <span class="text-slate-500 dark:text-slate-400">{{ t.destination || 'Cuenta' }}</span>
                  <span v-if="t.published_at" class="text-slate-400">· {{ dateTime(t.published_at) }}</span>
                  <a v-if="t.remote_url" :href="t.remote_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-brand-600 hover:underline dark:text-brand-300">
                    Ver publicación <AppIcon name="chevron-right" :size="12" />
                  </a>
                  <span v-if="t.error" class="w-full text-rose-600">{{ t.error }}</span>
                </li>
              </ul>
            </article>

            <form v-if="canEdit && availableProviders.length" class="mt-4 space-y-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/40" @submit.prevent="addVariant">
              <div class="flex flex-wrap items-center gap-2">
                <label for="nv-provider" class="sr-only">Red social</label>
                <select id="nv-provider" v-model="newVariant.provider" class="input w-auto">
                  <option v-for="p in availableProviders" :key="p.key" :value="p.key">{{ p.name }}</option>
                </select>
                <button v-if="canUseAi" type="button" class="btn-secondary text-sm" :disabled="aiBusy" @click="adaptVariant">
                  <Spinner v-if="aiBusy" :size="14" /><AppIcon v-else name="sparkles" :size="14" /> Adaptar con IA
                </button>
              </div>
              <label for="nv-body" class="sr-only">Texto para esta red</label>
              <textarea id="nv-body" v-model="newVariant.body" rows="2" class="input" placeholder="Texto para esta red (vacío = usa el texto base)" />
              <div class="flex justify-end">
                <button type="submit" class="btn-primary text-sm" :disabled="busy || !newVariant.provider">Añadir red</button>
              </div>
            </form>
            <p v-else-if="canEdit && providers.length === 0" class="mt-2 text-sm text-slate-500">
              No hay redes habilitadas en la plataforma.
            </p>
          </section>
        </div>

        <!-- Comentarios -->
        <section class="card h-fit p-6" aria-labelledby="com-title">
          <h2 id="com-title" class="mb-4 font-semibold text-slate-900 dark:text-white">Comentarios del equipo</h2>
          <ul class="space-y-3">
            <li v-for="c in content.comments" :key="c.id" class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/50">
              <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ c.body }}</p>
              <p class="mt-1 text-xs text-slate-400">{{ c.author }} · {{ dateTime(c.created_at) }}</p>
            </li>
            <li v-if="content.comments.length === 0" class="text-sm text-slate-400">Sin comentarios.</li>
          </ul>
          <form class="mt-4 flex gap-2" @submit.prevent="addComment">
            <label for="new-comment" class="sr-only">Comentario</label>
            <input id="new-comment" v-model="newComment" class="input flex-1" placeholder="Escribe un comentario" />
            <button type="submit" class="btn-secondary" :disabled="busy">Enviar</button>
          </form>
        </section>
      </div>
    </template>

    <MediaPicker
      v-if="content?.brand"
      :open="pickerFor !== null"
      :brand-id="content.brand"
      :selected="pickerFor?.media.map((m) => m.id) ?? []"
      @close="pickerFor = null"
      @confirm="(media) => pickerFor && setMedia(pickerFor, media)"
    />

    <ModalDialog :open="changesOpen" title="Solicitar cambios" description="El autor verá tu comentario y el contenido volverá a borrador." size="sm" @close="changesOpen = false">
      <label for="changes-note" class="label">¿Qué hay que cambiar?</label>
      <textarea id="changes-note" v-model="changesNote" rows="4" class="input" />
      <template #footer>
        <button type="button" class="btn-secondary text-sm" @click="changesOpen = false">Cancelar</button>
        <button type="button" class="btn-primary text-sm" :disabled="busy" @click="requestChanges">Enviar solicitud</button>
      </template>
    </ModalDialog>
  </div>
</template>
