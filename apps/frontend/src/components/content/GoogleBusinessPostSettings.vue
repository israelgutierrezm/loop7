<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'

/**
 * Botón de llamada a la acción de la novedad de Google Business Profile
 * (opcional): Reservar, Pedir, Comprar, Más información, Registrarse o Llamar.
 */
interface Account { destination: string; name: string; error: string | null }

const props = defineProps<{ variantId: string; options: Record<string, unknown> | null; editable: boolean }>()
const emit = defineEmits<{ saved: [] }>()
const toasts = useToastStore()

const CTA: Record<string, string> = {
  BOOK: 'Reservar',
  ORDER: 'Pedir en línea',
  SHOP: 'Comprar',
  LEARN_MORE: 'Más información',
  SIGN_UP: 'Registrarse',
  CALL: 'Llamar',
}

const accounts = ref<Account[]>([])
const loading = ref(true)
const saving = ref(false)
const form = reactive({ call_to_action: '', cta_url: '' })

function fill(): void {
  const o = props.options ?? {}
  form.call_to_action = typeof o.call_to_action === 'string' ? o.call_to_action : ''
  form.cta_url = typeof o.cta_url === 'string' ? o.cta_url : ''
}

const needsUrl = computed(() => form.call_to_action !== '' && form.call_to_action !== 'CALL')
const urlInvalid = computed(() => needsUrl.value && !/^https?:\/\/\S+$/i.test(form.cta_url.trim()))

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
        call_to_action: form.call_to_action || null,
        cta_url: needsUrl.value ? form.cta_url.trim() : null,
      },
    })
    toasts.success('Botón de Google Business Profile guardado.')
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
    <p class="font-medium text-slate-800 dark:text-slate-200">Novedad en Google Business Profile</p>

    <div v-if="loading" class="flex items-center gap-2 text-xs text-slate-500"><Spinner :size="14" /> Consultando las fichas…</div>
    <ul v-else class="text-xs">
      <li v-if="accounts.length === 0" class="text-slate-500">Conecta una ficha de Google Business Profile en la marca para publicar.</li>
      <li v-for="a in accounts" :key="a.destination">
        <span class="font-medium text-slate-700 dark:text-slate-200">{{ a.name }}</span>
        <span v-if="a.error" class="text-rose-600"> · {{ a.error }}</span>
      </li>
    </ul>

    <fieldset :disabled="!editable" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
      <div>
        <label :for="`gb-cta-${variantId}`" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Botón (opcional)</label>
        <select :id="`gb-cta-${variantId}`" v-model="form.call_to_action" class="input">
          <option value="">Sin botón</option>
          <option v-for="(label, value) in CTA" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>
      <div v-if="needsUrl">
        <label :for="`gb-url-${variantId}`" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Enlace del botón</label>
        <input :id="`gb-url-${variantId}`" v-model="form.cta_url" type="url" class="input" placeholder="https://" />
        <p v-if="urlInvalid" class="mt-1 text-[11px] text-rose-600">Escribe un enlace que empiece por https://</p>
      </div>
    </fieldset>

    <ul class="space-y-0.5 text-[11px] text-slate-400">
      <li v-if="form.call_to_action === 'CALL'">«Llamar» usa el teléfono de la ficha.</li>
      <li>Texto de hasta 1500 caracteres y, si quieres, una foto (JPG o PNG). Google no admite video en las novedades.</li>
      <li>Google puede rechazar novedades con números de teléfono en el texto: usa el botón «Llamar».</li>
    </ul>

    <div v-if="editable" class="flex justify-end">
      <button type="button" class="btn-secondary text-xs" :disabled="saving || urlInvalid" @click="save">
        {{ saving ? 'Guardando…' : 'Guardar botón' }}
      </button>
    </div>
  </div>
</template>
