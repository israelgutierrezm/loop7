<script setup lang="ts">
import { ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Número de WhatsApp para recibir avisos, verificado con un código. */
const props = defineProps<{ phone: string | null }>()
const emit = defineEmits<{ changed: [phone: string | null] }>()

const toasts = useToastStore()
const confirm = useConfirmStore()

const editing = ref(false)
const phoneInput = ref('')
const code = ref('')
const sentTo = ref<string | null>(null)
const error = ref('')
const busy = ref(false)

function startEditing(): void {
  editing.value = true
  sentTo.value = null
  code.value = ''
  error.value = ''
}

async function sendCode(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/me/whatsapp', { phone: phoneInput.value })
    sentTo.value = data.data.phone
    code.value = ''
  } catch (e) {
    error.value = apiValidationErrors(e).phone?.[0] ?? apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function verify(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/me/whatsapp/verify', { code: code.value })
    emit('changed', data.data.phone)
    toasts.success(data.message ?? 'Número verificado.')
    editing.value = false
    sentTo.value = null
    phoneInput.value = ''
  } catch (e) {
    error.value = apiValidationErrors(e).code?.[0] ?? apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function remove(): Promise<void> {
  const ok = await confirm.ask({
    title: 'Quitar número de WhatsApp',
    message: 'Dejarás de recibir avisos por WhatsApp. Puedes volver a añadirlo cuando quieras.',
    confirmText: 'Quitar número',
    danger: true,
  })
  if (!ok) return
  busy.value = true
  try {
    const { data } = await http.delete('/me/whatsapp')
    emit('changed', null)
    toasts.success(data.message ?? 'Número eliminado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex min-w-0 items-start gap-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40">
          <AppIcon name="inbox" :size="18" />
        </span>
        <div class="min-w-0">
          <p class="text-sm font-medium text-slate-800 dark:text-slate-100">Avisos por WhatsApp</p>
          <p class="text-xs text-slate-500">
            <template v-if="props.phone">Llegan a <span class="font-medium text-slate-700 dark:text-slate-200">{{ props.phone }}</span>.</template>
            <template v-else>Recibe los avisos urgentes en tu WhatsApp. Verificaremos el número con un código.</template>
          </p>
        </div>
      </div>
      <div v-if="!editing" class="flex flex-wrap gap-2">
        <button v-if="props.phone" type="button" class="btn-ghost text-sm text-rose-600" :disabled="busy" @click="remove">Quitar</button>
        <button type="button" class="text-sm" :class="props.phone ? 'btn-secondary' : 'btn-primary'" @click="startEditing">
          {{ props.phone ? 'Cambiar número' : 'Añadir número' }}
        </button>
      </div>
    </div>

    <form v-if="editing && !sentTo" class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="sendCode">
      <div class="min-w-0 flex-1">
        <label class="label" for="wa-phone">Número con código de país</label>
        <input
          id="wa-phone"
          v-model="phoneInput"
          type="tel"
          inputmode="tel"
          autocomplete="tel"
          class="input"
          placeholder="+52 55 1234 5678"
          required
          :aria-invalid="!!error"
          aria-describedby="wa-error"
        />
      </div>
      <button type="button" class="btn-ghost text-sm" @click="editing = false">Cancelar</button>
      <button type="submit" class="btn-primary text-sm" :disabled="busy || !phoneInput.trim()">
        <Spinner v-if="busy" :size="16" /> Enviar código
      </button>
    </form>

    <form v-else-if="editing && sentTo" class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="verify">
      <div class="min-w-0 flex-1">
        <label class="label" for="wa-code">Código que te enviamos a {{ sentTo }}</label>
        <input
          id="wa-code"
          v-model="code"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          class="input tracking-widest"
          placeholder="123456"
          required
          :aria-invalid="!!error"
          aria-describedby="wa-error"
        />
      </div>
      <button type="button" class="btn-ghost text-sm" :disabled="busy" @click="sentTo = null">Cambiar número</button>
      <button type="submit" class="btn-primary text-sm" :disabled="busy || code.length !== 6">
        <Spinner v-if="busy" :size="16" /> Verificar
      </button>
    </form>

    <p v-if="error" id="wa-error" class="mt-2 text-xs text-rose-600" role="alert">{{ error }}</p>
  </div>
</template>
