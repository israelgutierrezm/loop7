<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import type { Competitor, CompetitorSource } from '@/types/competitors'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

/**
 * Alta de un competidor con sus cuentas, o de una cuenta más de uno existente
 * (`competitor`). Cada cuenta se busca en su red al guardar.
 */
const props = defineProps<{ open: boolean; brandId: string; sources: CompetitorSource[]; competitor?: Competitor | null; max: number }>()
const emit = defineEmits<{ close: []; saved: [competitor: Competitor] }>()

const toasts = useToastStore()
const name = ref('')
const rows = reactive<{ provider: string; handle: string }[]>([])
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

const available = computed(() => props.sources.filter((s) => s.available))
const unavailable = computed(() => props.sources.filter((s) => !s.available))
const title = computed(() => (props.competitor ? `Añadir cuenta a ${props.competitor.name}` : 'Añadir competidor'))

function blankRow(): { provider: string; handle: string } {
  return { provider: available.value[0]?.key ?? '', handle: '' }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    name.value = ''
    rows.splice(0, rows.length, blankRow())
    errors.value = {}
  },
)

function hint(provider: string): string {
  return props.sources.find((s) => s.key === provider)?.hint ?? ''
}

function error(i: number, field: 'provider' | 'handle'): string | undefined {
  return (props.competitor ? errors.value[field] : errors.value[`accounts.${i}.${field}`])?.[0]
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const base = `/brands/${props.brandId}/competitors`
    const { data } = props.competitor
      ? await http.post(`${base}/${props.competitor.id}/accounts`, rows[0])
      : await http.post(base, { name: name.value.trim(), accounts: rows })
    toasts.success(data.message ?? 'Guardado.')
    emit('saved', data.data)
    emit('close')
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <ModalDialog :open="open" :title="title" description="Sólo cuentas públicas y en las redes cuya API oficial lo permite." size="lg" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="save">
      <div v-if="!competitor">
        <label for="competitor-name" class="label">Nombre del competidor</label>
        <input id="competitor-name" v-model="name" class="input" maxlength="120" placeholder="Ej. Café Rival" required :aria-invalid="!!errors.name" />
        <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
      </div>

      <fieldset class="space-y-3">
        <legend class="label">{{ competitor ? 'Cuenta' : 'Sus cuentas' }}</legend>
        <div v-for="(row, i) in rows" :key="i" class="grid gap-2 sm:grid-cols-[150px_1fr_auto] sm:items-start">
          <select v-model="row.provider" class="input" :aria-label="`Red de la cuenta ${i + 1}`">
            <option v-for="s in available" :key="s.key" :value="s.key">{{ s.label }}</option>
          </select>
          <div>
            <input
              v-model="row.handle"
              class="input"
              :placeholder="hint(row.provider)"
              :aria-label="`Cuenta ${i + 1}`"
              :aria-invalid="!!error(i, 'handle')"
              required
            />
            <p v-if="error(i, 'handle')" class="mt-1 text-xs text-rose-600">{{ error(i, 'handle') }}</p>
            <p v-else-if="error(i, 'provider')" class="mt-1 text-xs text-rose-600">{{ error(i, 'provider') }}</p>
          </div>
          <button
            v-if="!competitor && rows.length > 1"
            type="button"
            class="btn-ghost p-2 text-rose-600"
            :aria-label="`Quitar la cuenta ${i + 1}`"
            @click="rows.splice(i, 1)"
          >
            <AppIcon name="close" :size="14" />
          </button>
        </div>
        <button v-if="!competitor && rows.length < max" type="button" class="btn-ghost text-xs" :disabled="available.length === 0" @click="rows.push(blankRow())">
          <AppIcon name="plus" :size="14" /> Otra cuenta (otra red)
        </button>
      </fieldset>

      <div v-if="unavailable.length" class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
        <p class="mb-1 font-medium">Redes no disponibles por ahora</p>
        <ul class="space-y-1">
          <li v-for="s in unavailable" :key="s.key"><strong>{{ s.label }}:</strong> {{ s.reason }}</li>
        </ul>
      </div>
      <p v-if="errors.handle && competitor" class="sr-only" role="alert">{{ errors.handle[0] }}</p>

      <div class="flex justify-end gap-2 pt-1">
        <button type="button" class="btn-secondary text-sm" @click="emit('close')">Cancelar</button>
        <button type="submit" class="btn-primary text-sm" :disabled="saving || available.length === 0">
          <Spinner v-if="saving" :size="16" /> {{ saving ? 'Buscando…' : 'Guardar' }}
        </button>
      </div>
    </form>
  </ModalDialog>
</template>
