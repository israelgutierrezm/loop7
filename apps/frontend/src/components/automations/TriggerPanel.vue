<script setup lang="ts">
import { computed, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { useFlowEditor } from '@/composables/useFlowEditor'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import type { Automation } from '@/types/automations'
import CopyField from '@/components/ui/CopyField.vue'
import VariableChips from '@/components/automations/VariableChips.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Configuración del disparador: cuándo, en qué marca y sus datos propios (feed o webhook). */
const props = defineProps<{ current: Automation | null }>()
const emit = defineEmits<{ updated: [automation: Automation] }>()

const editor = useFlowEditor()
const auth = useAuthStore()
const toasts = useToastStore()
const confirm = useConfirmStore()

interface FeedPreview {
  title: string
  items: { id: string; title: string; link: string | null }[]
}
const preview = ref<FeedPreview | null>(null)
const previewing = ref(false)
const rotating = ref(false)

const trigger = computed(() => editor.meta.value?.triggers.find((t) => t.value === editor.form.trigger))
// La URL que se muestra es la guardada: si se cambió el disparador, se genera al guardar.
const inboundUrl = computed(() => (props.current?.trigger === 'webhook.received' ? props.current.inbound_url : null))
const feedError = computed(() => editor.errors.value['trigger_config.feed_url']?.[0])

function fmt(value: string | null): string {
  return value ? new Date(value).toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' }) : '—'
}

async function previewFeed(): Promise<void> {
  if (!editor.form.feed_url.trim()) return
  previewing.value = true
  preview.value = null
  try {
    const { data } = await http.post('/automations/feed-preview', { url: editor.form.feed_url.trim() })
    preview.value = data.data
    const rest = { ...editor.errors.value }
    delete rest['trigger_config.feed_url']
    editor.errors.value = rest
  } catch (e) {
    const url = apiValidationErrors(e).url
    if (url) editor.errors.value = { ...editor.errors.value, 'trigger_config.feed_url': url }
    else toasts.error(apiErrorMessage(e))
  } finally {
    previewing.value = false
  }
}

async function copyToken(token: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(token)
    toasts.success(`Copiado: ${token}`)
  } catch {
    // Sin permiso de portapapeles: no pasa nada.
  }
}

async function rotate(): Promise<void> {
  if (!props.current) return
  const ok = await confirm.ask({
    title: 'Renovar la URL',
    message: 'La URL actual dejará de funcionar al momento: tendrás que poner la nueva en la herramienta que envía los datos.',
    confirmText: 'Renovar',
  })
  if (!ok) return
  rotating.value = true
  try {
    const { data } = await http.post(`/automations/${props.current.id}/rotate-inbound-url`)
    emit('updated', data.data)
    toasts.success('URL renovada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    rotating.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div>
      <label for="trigger-type" class="label">Cuándo se ejecuta</label>
      <select id="trigger-type" v-model="editor.form.trigger" class="input" @change="preview = null">
        <option v-for="t in editor.meta.value?.triggers" :key="t.value" :value="t.value">{{ t.label }}</option>
      </select>
      <p v-if="trigger" class="mt-1 text-xs text-slate-500">{{ trigger.description }}</p>
    </div>

    <div>
      <label for="trigger-brand" class="label">Marca</label>
      <select id="trigger-brand" v-model="editor.form.brand" class="input">
        <option value="">Todas las marcas</option>
        <option v-for="b in auth.brands" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>
      <p class="mt-1 text-xs text-slate-500">
        {{ trigger?.external ? 'Los borradores se crean en esta marca.' : 'Sólo se ejecuta con eventos de esta marca.' }}
      </p>
    </div>

    <!-- Feed RSS -->
    <div v-if="editor.form.trigger === 'rss.item_published'" class="space-y-2">
      <label for="trigger-feed" class="label">URL del feed RSS o Atom</label>
      <div class="flex gap-2">
        <input
          id="trigger-feed"
          v-model="editor.form.feed_url"
          type="url"
          class="input font-mono text-xs"
          placeholder="https://tu-blog.com/feed"
          :aria-invalid="!!feedError"
        />
        <button type="button" class="btn-secondary shrink-0 text-xs" :disabled="previewing || !editor.form.feed_url.trim()" @click="previewFeed">
          {{ previewing ? 'Leyendo…' : 'Probar feed' }}
        </button>
      </div>
      <p v-if="feedError" class="text-xs text-rose-600">{{ feedError }}</p>
      <div v-if="preview" class="rounded-lg bg-slate-50 p-2.5 text-xs dark:bg-slate-800/60">
        <p class="mb-1 font-medium text-slate-700 dark:text-slate-200">{{ preview.title || 'Feed sin título' }} · últimas entradas:</p>
        <ul class="space-y-0.5 text-slate-600 dark:text-slate-300">
          <li v-for="item in preview.items" :key="item.id" class="truncate">• {{ item.title || item.link }}</li>
          <li v-if="preview.items.length === 0" class="text-slate-400">El feed no tiene entradas.</li>
        </ul>
      </div>
      <p v-if="current?.feed" class="text-xs" :class="current.feed.last_error ? 'text-rose-600' : 'text-slate-500'">
        <template v-if="current.feed.last_error">Último error: {{ current.feed.last_error }}</template>
        <template v-else-if="current.feed.last_polled_at">Revisado {{ fmt(current.feed.last_polled_at) }}{{ current.feed.title ? ` · ${current.feed.title}` : '' }}</template>
        <template v-else>Se revisará en los próximos minutos (cada {{ editor.meta.value?.feed_poll_minutes ?? 15 }} min).</template>
      </p>
    </div>

    <!-- Webhook entrante -->
    <div v-if="editor.form.trigger === 'webhook.received'" class="space-y-2">
      <template v-if="inboundUrl">
        <CopyField label="URL secreta (POST con JSON o formulario)" :value="inboundUrl" />
        <p class="text-xs text-slate-500">
          Trátala como una contraseña. Envía <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">Idempotency-Key</code> para no procesar dos veces el mismo envío.
        </p>
        <button type="button" class="btn-ghost text-xs" :disabled="rotating" @click="rotate">
          <AppIcon name="refresh" :size="14" /> Renovar URL
        </button>
      </template>
      <p v-else class="text-xs text-slate-500">La URL secreta se generará al guardar.</p>
    </div>

    <VariableChips :fields="editor.fields.value" :free="editor.form.trigger === 'webhook.received'" @pick="copyToken" />
  </div>
</template>
