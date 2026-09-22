<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps<{
  open: boolean
  brandId: string | null
  providers: { key: string; name: string }[]
}>()
const emit = defineEmits<{ close: []; connected: [] }>()

const toasts = useToastStore()
const saving = ref(false)

interface Destination { external_id: string; name: string }
const form = reactive({
  provider: '',
  external_account_name: '',
  external_account_id: '',
  access_token: '',
  destinations: [] as Destination[],
})

function reset(): void {
  form.provider = props.providers[0]?.key ?? ''
  form.external_account_name = ''
  form.external_account_id = ''
  form.access_token = ''
  form.destinations = []
}

watch(() => props.open, (open) => { if (open) reset() })

function addDestination(): void {
  form.destinations.push({ external_id: '', name: '' })
}

async function save(): Promise<void> {
  if (!props.brandId) return
  if (!form.provider || !form.external_account_name.trim() || !form.access_token.trim()) {
    toasts.error('Proveedor, nombre de la cuenta y token son obligatorios.')
    return
  }
  saving.value = true
  try {
    await http.post(`/brands/${props.brandId}/social/connections/${form.provider}/manual`, {
      external_account_name: form.external_account_name,
      external_account_id: form.external_account_id || null,
      access_token: form.access_token,
      destinations: form.destinations.filter((d) => d.external_id && d.name),
    })
    toasts.success('Conexión creada.')
    emit('connected')
    emit('close')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <ModalDialog :open="open" title="Conexión manual" @close="emit('close')">
    <div class="space-y-4">
      <p class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/50">
        Pega un token de acceso obtenido de forma manual (p. ej. un token de usuario de sistema o de pruebas). Se
        guarda cifrado. Útil para conectar antes de completar el flujo OAuth del proveedor.
      </p>

      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Proveedor</label>
        <select v-model="form.provider" class="input">
          <option v-for="p in providers" :key="p.key" :value="p.key">{{ p.name }}</option>
        </select>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Nombre de la cuenta</label>
          <input v-model="form.external_account_name" class="input" placeholder="Ej. Mi Página" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">ID externo (opcional)</label>
          <input v-model="form.external_account_id" class="input" placeholder="Ej. 1234567890" />
        </div>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Access token</label>
        <textarea v-model="form.access_token" rows="3" class="input font-mono text-xs" placeholder="Pega aquí el token…" />
      </div>

      <div>
        <div class="mb-1 flex items-center justify-between">
          <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Destinos (páginas/perfiles)</label>
          <button class="btn-ghost text-xs" @click="addDestination"><AppIcon name="plus" :size="14" /> Añadir</button>
        </div>
        <p v-if="form.destinations.length === 0" class="text-xs text-slate-400">
          Opcional. Añade las páginas/cuentas donde se publicará.
        </p>
        <div v-for="(d, i) in form.destinations" :key="i" class="mb-2 flex items-center gap-2">
          <input v-model="d.external_id" class="input flex-1" placeholder="ID del destino" />
          <input v-model="d.name" class="input flex-1" placeholder="Nombre" />
          <button class="text-rose-500" @click="form.destinations.splice(i, 1)"><AppIcon name="close" :size="14" /></button>
        </div>
      </div>

      <div class="flex justify-end gap-2">
        <button class="btn-secondary text-sm" @click="emit('close')">Cancelar</button>
        <button class="btn-primary text-sm" :disabled="saving" @click="save">{{ saving ? 'Conectando…' : 'Conectar' }}</button>
      </div>
    </div>
  </ModalDialog>
</template>
