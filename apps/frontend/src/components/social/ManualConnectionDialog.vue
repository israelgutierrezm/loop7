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
    toasts.error('Red, nombre de la cuenta y token son obligatorios.')
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
  <ModalDialog
    :open="open"
    title="Conexión manual"
    description="Conecta pegando un token de acceso, sin pasar por la autorización de la red."
    @close="emit('close')"
  >
    <form id="manual-connection" class="space-y-4" @submit.prevent="save">
      <p class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/50">
        Útil con un token de usuario de sistema o de pruebas. El token se guarda cifrado y nunca vuelve a mostrarse.
        Si no indicas destinos, se detectan automáticamente con el token cuando la red lo permite.
      </p>

      <div>
        <label for="mc-provider" class="label">Red social</label>
        <select id="mc-provider" v-model="form.provider" class="input">
          <option v-for="p in providers" :key="p.key" :value="p.key">{{ p.name }}</option>
        </select>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <label for="mc-name" class="label">Nombre de la cuenta</label>
          <input id="mc-name" v-model="form.external_account_name" class="input" placeholder="Ej. Mi Página" required />
        </div>
        <div>
          <label for="mc-id" class="label">ID de la cuenta <span class="text-slate-400">(opcional)</span></label>
          <input id="mc-id" v-model="form.external_account_id" class="input" placeholder="Ej. 1234567890" />
        </div>
      </div>

      <div>
        <label for="mc-token" class="label">Access token</label>
        <textarea
          id="mc-token"
          v-model="form.access_token"
          rows="3"
          class="input font-mono text-xs"
          placeholder="Pega aquí el token…"
          autocomplete="off"
          spellcheck="false"
          required
        />
      </div>

      <fieldset>
        <div class="mb-1 flex items-center justify-between">
          <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">Destinos (páginas/cuentas)</legend>
          <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="addDestination">
            <AppIcon name="plus" :size="14" /> Añadir
          </button>
        </div>
        <p v-if="form.destinations.length === 0" class="text-xs text-slate-400">Opcional.</p>
        <div v-for="(d, i) in form.destinations" :key="i" class="mb-2 flex items-center gap-2">
          <input v-model="d.external_id" class="input flex-1" placeholder="ID del destino" :aria-label="`ID del destino ${i + 1}`" />
          <input v-model="d.name" class="input flex-1" placeholder="Nombre" :aria-label="`Nombre del destino ${i + 1}`" />
          <button
            type="button"
            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30"
            :aria-label="`Quitar destino ${i + 1}`"
            @click="form.destinations.splice(i, 1)"
          >
            <AppIcon name="close" :size="14" />
          </button>
        </div>
      </fieldset>
    </form>

    <template #footer>
      <button type="button" class="btn-secondary text-sm" @click="emit('close')">Cancelar</button>
      <button type="submit" form="manual-connection" class="btn-primary text-sm" :disabled="saving">
        {{ saving ? 'Conectando…' : 'Conectar' }}
      </button>
    </template>
  </ModalDialog>
</template>
