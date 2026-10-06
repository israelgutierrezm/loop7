<script setup lang="ts">
import { ref } from 'vue'
import http from '@/services/http'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { relativeTime } from '@/utils/format'
import type { SsoDomain } from '@/types/sso'
import AppIcon from '@/components/AppIcon.vue'
import CopyField from '@/components/ui/CopyField.vue'
import Spinner from '@/components/ui/Spinner.vue'

const props = defineProps<{ domains: SsoDomain[]; max: number; disabled?: boolean }>()
const emit = defineEmits<{ changed: [] }>()

const toasts = useToastStore()
const confirmDialog = useConfirmStore()
const newDomain = ref('')
const adding = ref(false)
const addError = ref('')
const verifying = ref<string | null>(null)

async function add(): Promise<void> {
  adding.value = true
  addError.value = ''
  try {
    await http.post('/organization/sso/domains', { domain: newDomain.value })
    newDomain.value = ''
    toasts.success('Dominio añadido. Publica el registro TXT y pulsa «Verificar».')
    emit('changed')
  } catch (e) {
    addError.value = apiValidationErrors(e).domain?.[0] ?? apiErrorMessage(e)
  } finally {
    adding.value = false
  }
}

async function verify(domain: SsoDomain): Promise<void> {
  verifying.value = domain.id
  try {
    const { data } = await http.post(`/organization/sso/domains/${domain.id}/verify`)
    if (data.data.verified) toasts.success(data.message)
    else toasts.push(data.message, 'info', 6000)
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    verifying.value = null
  }
}

async function remove(domain: SsoDomain): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Quitar ${domain.domain}`,
    message: domain.verified ? 'Las personas con este dominio dejarán de poder entrar con SSO.' : 'Se descartará el dominio pendiente de verificar.',
    confirmText: 'Quitar',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/organization/sso/domains/${domain.id}`)
    toasts.success('Dominio eliminado.')
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}
</script>

<template>
  <div class="space-y-3">
    <ul v-if="props.domains.length" class="space-y-3">
      <li v-for="d in props.domains" :key="d.id" class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="flex items-center gap-2 font-medium text-slate-800 dark:text-slate-100">
            {{ d.domain }}
            <span
              class="rounded-full px-2 py-0.5 text-xs font-medium"
              :class="d.verified ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'"
            >{{ d.verified ? 'Verificado' : 'Pendiente' }}</span>
          </span>
          <span class="flex gap-2">
            <button
              v-if="!d.verified"
              type="button"
              class="btn-secondary px-3 text-xs"
              :disabled="props.disabled || verifying === d.id"
              @click="verify(d)"
            >
              <Spinner v-if="verifying === d.id" :size="14" /> Verificar
            </button>
            <button type="button" class="btn-ghost px-2 text-xs text-rose-600" :disabled="props.disabled" :aria-label="`Quitar ${d.domain}`" @click="remove(d)">
              <AppIcon name="trash" :size="14" />
            </button>
          </span>
        </div>
        <div v-if="!d.verified" class="mt-3 space-y-2">
          <p class="text-xs text-slate-500">
            Crea en el DNS de <strong>{{ d.domain }}</strong> un registro <strong>TXT</strong> con este valor
            <template v-if="d.last_checked_at">(última comprobación {{ relativeTime(d.last_checked_at) }})</template>:
          </p>
          <CopyField :label="`Valor TXT para ${d.domain}`" :value="d.txt_value" />
        </div>
      </li>
    </ul>
    <p v-else class="text-sm text-slate-500">Aún no hay dominios. Añade el de los correos de tu empresa.</p>

    <form v-if="props.domains.length < props.max" class="flex flex-wrap items-start gap-2" @submit.prevent="add">
      <div class="min-w-48 flex-1">
        <label class="sr-only" for="sso-domain">Dominio</label>
        <input id="sso-domain" v-model="newDomain" class="input" placeholder="empresa.com" maxlength="255" :disabled="props.disabled" required />
        <p v-if="addError" class="mt-1 text-xs text-rose-600">{{ addError }}</p>
      </div>
      <button type="submit" class="btn-secondary" :disabled="props.disabled || adding">
        <Spinner v-if="adding" :size="16" /> Añadir dominio
      </button>
    </form>
  </div>
</template>
