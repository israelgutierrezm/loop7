<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

/**
 * Opciones que TikTok obliga a pedir antes de publicar (Content Sharing
 * Guidelines): cuenta que publica, privacidad sin valor por defecto,
 * interacciones desmarcadas, divulgación comercial y consentimiento.
 */
interface CreatorOptions {
  creator_nickname: string
  creator_username: string
  privacy_level_options: string[]
  comment_disabled: boolean
  duet_disabled: boolean
  stitch_disabled: boolean
  max_video_post_duration_sec: number
  can_post: boolean
  blocked_reason: string | null
}
interface Account { destination: string; name: string; options: CreatorOptions | null; error: string | null }

const props = defineProps<{ variantId: string; options: Record<string, unknown> | null; editable: boolean }>()
const emit = defineEmits<{ saved: [] }>()
const toasts = useToastStore()

const PRIVACY_LABELS: Record<string, string> = {
  PUBLIC_TO_EVERYONE: 'Todo el mundo',
  MUTUAL_FOLLOW_FRIENDS: 'Amigos (seguidores mutuos)',
  FOLLOWER_OF_CREATOR: 'Seguidores',
  SELF_ONLY: 'Solo yo',
}

const accounts = ref<Account[]>([])
const loading = ref(true)
const saving = ref(false)
const form = reactive({
  privacy_level: '',
  allow_comment: false,
  allow_duet: false,
  allow_stitch: false,
  commercial: false,
  brand_organic: false,
  brand_content: false,
  is_aigc: false,
  consent: false,
})

function fill(): void {
  const o = props.options ?? {}
  form.privacy_level = typeof o.privacy_level === 'string' ? o.privacy_level : ''
  for (const key of ['allow_comment', 'allow_duet', 'allow_stitch', 'commercial', 'brand_organic', 'brand_content', 'is_aigc', 'consent'] as const) {
    form[key] = o[key] === true
  }
}

const ready = computed(() => accounts.value.filter((a) => a.options !== null).map((a) => a.options as CreatorOptions))
// Sólo las privacidades que admiten todas las cuentas de la marca.
const privacyOptions = computed(() => {
  if (ready.value.length === 0) return Object.keys(PRIVACY_LABELS)
  return Object.keys(PRIVACY_LABELS).filter((p) => ready.value.every((o) => o.privacy_level_options.includes(p)))
})
const commentLocked = computed(() => ready.value.some((o) => o.comment_disabled))
const duetLocked = computed(() => ready.value.some((o) => o.duet_disabled))
const stitchLocked = computed(() => ready.value.some((o) => o.stitch_disabled))
const maxDuration = computed(() => Math.min(...ready.value.map((o) => o.max_video_post_duration_sec).filter((n) => n > 0)))
const commercialIncomplete = computed(() => form.commercial && !form.brand_organic && !form.brand_content)
const brandedPrivate = computed(() => form.commercial && form.brand_content && form.privacy_level === 'SELF_ONLY')
const complete = computed(() => form.privacy_level !== '' && form.consent && !commercialIncomplete.value && !brandedPrivate.value)

// El contenido de marca no puede ser privado: si se marca, «Solo yo» deja de estar disponible.
watch(() => [form.commercial, form.brand_content], () => {
  if (form.commercial && form.brand_content && form.privacy_level === 'SELF_ONLY') form.privacy_level = ''
})
watch(commentLocked, (locked) => { if (locked) form.allow_comment = false })
watch(duetLocked, (locked) => { if (locked) form.allow_duet = false })
watch(stitchLocked, (locked) => { if (locked) form.allow_stitch = false })

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
    await http.patch(`/variants/${props.variantId}`, { options: { ...form } })
    toasts.success('Opciones de TikTok guardadas.')
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
      <p class="font-medium text-slate-800 dark:text-slate-200">Publicar en TikTok</p>
      <span v-if="!complete" class="text-xs text-amber-600">Faltan opciones obligatorias</span>
    </div>

    <!-- Cuenta(s) que publican -->
    <div v-if="loading" class="flex items-center gap-2 text-xs text-slate-500"><Spinner :size="14" /> Consultando la cuenta…</div>
    <ul v-else class="space-y-1 text-xs">
      <li v-if="accounts.length === 0" class="text-slate-500">Conecta una cuenta de TikTok en la marca para publicar.</li>
      <li v-for="a in accounts" :key="a.destination" class="flex flex-wrap items-center gap-1.5">
        <span class="font-medium text-slate-700 dark:text-slate-200">{{ a.options?.creator_nickname || a.name }}</span>
        <span v-if="a.options?.creator_username" class="text-slate-400">@{{ a.options.creator_username }}</span>
        <span v-if="a.error" class="text-rose-600">· {{ a.error }}</span>
        <span v-else-if="a.options && !a.options.can_post" class="text-rose-600">· No puede publicar ahora: {{ a.options.blocked_reason }}</span>
      </li>
    </ul>

    <fieldset :disabled="!editable" class="space-y-3">
      <div>
        <label :for="`tt-privacy-${variantId}`" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Quién puede verlo</label>
        <select :id="`tt-privacy-${variantId}`" v-model="form.privacy_level" class="input">
          <option value="" disabled>Elige una opción</option>
          <option
            v-for="p in privacyOptions"
            :key="p"
            :value="p"
            :disabled="p === 'SELF_ONLY' && form.commercial && form.brand_content"
            :title="p === 'SELF_ONLY' && form.commercial && form.brand_content ? 'El contenido de marca no puede ser privado.' : undefined"
          >{{ PRIVACY_LABELS[p] }}</option>
        </select>
      </div>

      <div class="space-y-1">
        <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Permitir a los usuarios</p>
        <label class="flex items-center gap-2 text-xs" :class="commentLocked ? 'text-slate-400' : ''">
          <input v-model="form.allow_comment" type="checkbox" class="rounded border-slate-300 text-brand-600" :disabled="commentLocked" /> Comentar
        </label>
        <label class="flex items-center gap-2 text-xs" :class="duetLocked ? 'text-slate-400' : ''">
          <input v-model="form.allow_duet" type="checkbox" class="rounded border-slate-300 text-brand-600" :disabled="duetLocked" /> Dúo
        </label>
        <label class="flex items-center gap-2 text-xs" :class="stitchLocked ? 'text-slate-400' : ''">
          <input v-model="form.allow_stitch" type="checkbox" class="rounded border-slate-300 text-brand-600" :disabled="stitchLocked" /> Stitch
        </label>
        <p v-if="commentLocked || duetLocked || stitchLocked" class="text-[11px] text-slate-400">Las opciones en gris están desactivadas en la configuración de la cuenta.</p>
      </div>

      <div class="space-y-1">
        <label class="flex items-center gap-2 text-xs font-medium">
          <input v-model="form.commercial" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Divulgación de contenido comercial
        </label>
        <div v-if="form.commercial" class="ml-6 space-y-1">
          <label class="flex items-start gap-2 text-xs">
            <input v-model="form.brand_organic" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600" />
            <span><strong>Tu marca</strong> — promocionas tu propio negocio. Se etiquetará como «Contenido promocional».</span>
          </label>
          <label class="flex items-start gap-2 text-xs">
            <input v-model="form.brand_content" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600" />
            <span><strong>Contenido de marca</strong> — promocionas a un tercero. Se etiquetará como «Colaboración pagada».</span>
          </label>
          <p v-if="commercialIncomplete" class="text-xs text-rose-600">Indica si tu contenido te promociona a ti, a un tercero o a ambos.</p>
        </div>
      </div>

      <label class="flex items-center gap-2 text-xs">
        <input v-model="form.is_aigc" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Contenido generado con IA (TikTok lo etiqueta)
      </label>

      <label class="flex items-start gap-2 rounded bg-slate-50 p-2 text-xs dark:bg-slate-900">
        <input v-model="form.consent" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600" />
        <span v-if="form.commercial && form.brand_content">
          Al publicar, aceptas la
          <a href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline">Política de contenido de marca</a>
          y la
          <a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline">Confirmación de uso de música</a>
          de TikTok.
        </span>
        <span v-else>
          Al publicar, aceptas la
          <a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener noreferrer" class="text-brand-600 hover:underline">Confirmación de uso de música</a>
          de TikTok.
        </span>
      </label>
    </fieldset>

    <ul class="space-y-0.5 text-[11px] text-slate-400">
      <li v-if="Number.isFinite(maxDuration)"><AppIcon name="info" :size="12" class="inline" /> Duración máxima del video para esta cuenta: {{ Math.floor(maxDuration / 60) }} min.</li>
      <li>Tras publicar, TikTok puede tardar unos minutos en procesar el video antes de que aparezca en el perfil.</li>
      <li>Mientras la app no supere la auditoría de TikTok, las publicaciones quedan como «Solo yo».</li>
    </ul>

    <div v-if="editable" class="flex justify-end">
      <button type="button" class="btn-secondary text-xs" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar opciones de TikTok' }}</button>
    </div>
  </div>
</template>
