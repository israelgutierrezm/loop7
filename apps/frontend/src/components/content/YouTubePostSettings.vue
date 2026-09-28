<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'

/**
 * Opciones que YouTube exige que elija la persona (Required Minimum
 * Functionality): título, privacidad y si es contenido para niños, con el
 * aviso de certificación de sus Condiciones.
 */
interface Account { destination: string; name: string; options: { channel_title: string } | null; error: string | null }

const props = defineProps<{ variantId: string; options: Record<string, unknown> | null; editable: boolean; defaultTitle: string }>()
const emit = defineEmits<{ saved: [] }>()
const toasts = useToastStore()

const PRIVACY: Record<string, string> = { public: 'Público', unlisted: 'Oculto (sólo con el enlace)', private: 'Privado' }
const TITLE_MAX = 100

const accounts = ref<Account[]>([])
const loading = ref(true)
const saving = ref(false)
const form = reactive({ title: '', privacy_status: '', made_for_kids: null as boolean | null, synthetic_media: false })

function fill(): void {
  const o = props.options ?? {}
  form.title = typeof o.title === 'string' ? o.title : ''
  form.privacy_status = typeof o.privacy_status === 'string' ? o.privacy_status : ''
  form.made_for_kids = typeof o.made_for_kids === 'boolean' ? o.made_for_kids : null
  form.synthetic_media = o.synthetic_media === true
}

const titleTooLong = computed(() => form.title.length > TITLE_MAX || /[<>]/.test(form.title))
const complete = computed(() => form.privacy_status !== '' && form.made_for_kids !== null && !titleTooLong.value)

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data } = await http.get(`/variants/${props.variantId}/publish-options`)
    accounts.value = data.data.accounts
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true
  try {
    await http.patch(`/variants/${props.variantId}`, {
      options: {
        title: form.title.trim() || null,
        privacy_status: form.privacy_status || null,
        made_for_kids: form.made_for_kids,
        synthetic_media: form.synthetic_media,
      },
    })
    toasts.success('Opciones de YouTube guardadas.')
    emit('saved')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

watch(() => props.options, fill)
onMounted(() => {
  fill()
  load()
})
</script>

<template>
  <div class="mt-3 space-y-3 rounded-lg border border-slate-100 p-3 text-sm dark:border-slate-800">
    <div class="flex items-center justify-between gap-2">
      <p class="font-medium text-slate-800 dark:text-slate-200">Subir a YouTube</p>
      <span v-if="!complete" class="text-xs text-amber-600">Faltan opciones obligatorias</span>
    </div>

    <div v-if="loading" class="flex items-center gap-2 text-xs text-slate-500"><Spinner :size="14" /> Consultando el canal…</div>
    <ul v-else class="text-xs">
      <li v-if="accounts.length === 0" class="text-slate-500">Conecta un canal de YouTube en la marca para publicar.</li>
      <li v-for="a in accounts" :key="a.destination">
        <span class="font-medium text-slate-700 dark:text-slate-200">{{ a.options?.channel_title || a.name }}</span>
        <span v-if="a.error" class="text-rose-600"> · {{ a.error }}</span>
      </li>
    </ul>

    <fieldset :disabled="!editable" class="space-y-3">
      <div>
        <label :for="`yt-title-${variantId}`" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Título del video</label>
        <input :id="`yt-title-${variantId}`" v-model="form.title" class="input" :maxlength="TITLE_MAX + 20" :placeholder="defaultTitle || 'Título del video'" />
        <p class="mt-1 text-[11px]" :class="titleTooLong ? 'text-rose-600' : 'text-slate-400'">
          {{ form.title.length }} / {{ TITLE_MAX }} · Si lo dejas vacío se usa el título interno. Sin «&lt;» ni «&gt;».
        </p>
      </div>

      <div>
        <label :for="`yt-privacy-${variantId}`" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Visibilidad</label>
        <select :id="`yt-privacy-${variantId}`" v-model="form.privacy_status" class="input">
          <option value="" disabled>Elige una opción</option>
          <option v-for="(label, value) in PRIVACY" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>

      <fieldset>
        <legend class="mb-1 text-xs font-medium text-slate-600 dark:text-slate-400">¿Es contenido creado para niños?</legend>
        <label class="flex items-center gap-2 text-xs"><input v-model="form.made_for_kids" type="radio" :value="true" /> Sí, está creado para niños</label>
        <label class="flex items-center gap-2 text-xs"><input v-model="form.made_for_kids" type="radio" :value="false" /> No, no está creado para niños</label>
      </fieldset>

      <label class="flex items-start gap-2 text-xs">
        <input v-model="form.synthetic_media" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600" />
        Contiene contenido alterado o sintético realista (por ejemplo, generado con IA)
      </label>
    </fieldset>

    <p class="rounded bg-slate-50 p-2 text-[11px] text-slate-500 dark:bg-slate-900">
      Al subir el video confirmas que cumple las
      <a href="https://www.youtube.com/t/terms" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline">Condiciones del servicio de YouTube</a>
      (incluidas sus Normas de la comunidad) y que no infringe los derechos de autor ni la privacidad de otras personas.
    </p>
    <ul class="space-y-0.5 text-[11px] text-slate-400">
      <li>Un video vertical o cuadrado de hasta 3 minutos se publica como Short.</li>
      <li>Mientras el proyecto de Google no supere la auditoría de YouTube, los videos se suben como privados.</li>
    </ul>

    <div v-if="editable" class="flex justify-end">
      <button type="button" class="btn-secondary text-xs" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar opciones de YouTube' }}</button>
    </div>
  </div>
</template>
