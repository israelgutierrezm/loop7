<script setup lang="ts">
import { ref } from 'vue'
import http from '@/services/http'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import type { Competitor, CompetitorAccount } from '@/types/competitors'
import { formatCompact } from '@/utils/numbers'
import ProviderIcon from '@/components/social/ProviderIcon.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Competidores de la marca con sus cuentas y acciones. */
const props = defineProps<{ brandId: string; competitors: Competitor[]; canManage: boolean; maxAccounts: number }>()
const emit = defineEmits<{ changed: []; addAccount: [competitor: Competitor] }>()

const toasts = useToastStore()
const confirm = useConfirmStore()
const busy = ref<string | null>(null)
const editing = ref<string | null>(null)
const draft = ref('')

function fmt(value: string | null): string {
  return value ? new Date(value).toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' }) : 'nunca'
}

async function run(id: string, action: () => Promise<{ data: { message?: string } }>): Promise<void> {
  busy.value = id
  try {
    const { data } = await action()
    if (data.message) toasts.success(data.message)
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = null
  }
}

function sync(c: Competitor): void {
  run(c.id, () => http.post(`/brands/${props.brandId}/competitors/${c.id}/sync`))
}

function startRename(c: Competitor): void {
  editing.value = c.id
  draft.value = c.name
}

async function rename(c: Competitor): Promise<void> {
  const name = draft.value.trim()
  editing.value = null
  if (!name || name === c.name) return
  await run(c.id, () => http.patch(`/brands/${props.brandId}/competitors/${c.id}`, { name }))
}

async function remove(c: Competitor): Promise<void> {
  const ok = await confirm.ask({ title: 'Dejar de seguir', message: `Se borran ${c.name}, sus cuentas y su historial.`, confirmText: 'Eliminar', danger: true })
  if (ok) run(c.id, () => http.delete(`/brands/${props.brandId}/competitors/${c.id}`))
}

async function removeAccount(c: Competitor, a: CompetitorAccount): Promise<void> {
  const ok = await confirm.ask({ title: 'Quitar cuenta', message: `Dejarás de seguir @${a.handle} y se borra su historial.`, confirmText: 'Quitar', danger: true })
  if (ok) run(a.id, () => http.delete(`/brands/${props.brandId}/competitors/${c.id}/accounts/${a.id}`))
}
</script>

<template>
  <ul class="divide-y divide-slate-100 dark:divide-slate-800">
    <li v-for="c in competitors" :key="c.id" class="py-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <form v-if="editing === c.id" class="flex min-w-0 flex-1 items-center gap-2" @submit.prevent="rename(c)">
          <label :for="`rename-${c.id}`" class="sr-only">Nombre del competidor</label>
          <input :id="`rename-${c.id}`" v-model="draft" class="input max-w-xs py-1 text-sm" maxlength="120" required @keydown.esc="editing = null" />
          <button type="submit" class="btn-primary px-2.5 py-1 text-xs">Guardar</button>
          <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="editing = null">Cancelar</button>
        </form>
        <p v-else class="font-semibold text-slate-900 dark:text-white">{{ c.name }}</p>
        <div v-if="canManage" class="flex flex-wrap items-center gap-1">
          <button type="button" class="btn-ghost px-2 py-1 text-xs" :disabled="busy === c.id" :aria-label="`Actualizar ${c.name}`" @click="sync(c)">
            <AppIcon name="refresh" :size="14" :class="busy === c.id ? 'animate-spin' : ''" /> Actualizar
          </button>
          <button v-if="c.accounts.length < maxAccounts" type="button" class="btn-ghost px-2 py-1 text-xs" :aria-label="`Añadir cuenta a ${c.name}`" @click="emit('addAccount', c)">
            <AppIcon name="plus" :size="14" /> Cuenta
          </button>
          <button type="button" class="btn-ghost px-2 py-1 text-xs" :aria-label="`Renombrar ${c.name}`" @click="startRename(c)">Renombrar</button>
          <button type="button" class="btn-ghost px-2 py-1 text-xs text-rose-600" :aria-label="`Eliminar ${c.name}`" @click="remove(c)">
            <AppIcon name="trash" :size="14" />
          </button>
        </div>
      </div>
      <ul class="mt-2 space-y-1.5">
        <li v-for="a in c.accounts" :key="a.id" class="flex flex-wrap items-center gap-2 text-sm">
          <ProviderIcon :provider="a.provider" :size="22" />
          <a v-if="a.profile_url" :href="a.profile_url" target="_blank" rel="noopener noreferrer" class="font-medium text-slate-700 hover:text-brand-600 hover:underline dark:text-slate-200">@{{ a.handle }}</a>
          <span v-else class="font-medium text-slate-700 dark:text-slate-200">@{{ a.handle }}</span>
          <span v-if="a.display_name" class="text-xs text-slate-500">{{ a.display_name }}</span>
          <span class="text-xs tabular-nums text-slate-500">· {{ formatCompact(a.followers) }} seguidores</span>
          <StatusBadge v-if="a.status === 'error'" tone="danger" :title="a.last_error ?? ''">No se pudo actualizar</StatusBadge>
          <span class="text-[11px] text-slate-400">Actualizado: {{ fmt(a.last_synced_at) }}</span>
          <button
            v-if="canManage"
            type="button"
            class="btn-ghost ml-auto p-1 text-rose-600"
            :disabled="busy === a.id"
            :aria-label="`Quitar @${a.handle}`"
            @click="removeAccount(c, a)"
          >
            <AppIcon name="close" :size="12" />
          </button>
          <p v-if="a.status === 'error' && a.last_error" class="w-full pl-8 text-xs text-rose-600">{{ a.last_error }}</p>
        </li>
      </ul>
    </li>
  </ul>
</template>
