<script setup lang="ts">
import { computed } from 'vue'
import { ssoErrorMessage } from '@/utils/sso'
import type { SsoTestResult } from '@/types/sso'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps<{ result: SsoTestResult }>()
defineEmits<{ close: [] }>()

const attributes = computed(() => Object.entries(props.result.attributes))
const outcome = computed(() =>
  props.result.outcome === 'provision'
    ? 'No tiene cuenta todavía: se le daría de alta automáticamente con el rol por defecto.'
    : 'Es miembro de la organización: entraría con su cuenta.',
)
</script>

<template>
  <div
    class="rounded-lg border p-4 text-sm"
    :class="props.result.ok ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30' : 'border-rose-200 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/30'"
    role="status"
  >
    <div class="flex items-start justify-between gap-3">
      <p class="flex items-center gap-2 font-semibold" :class="props.result.ok ? 'text-emerald-800 dark:text-emerald-300' : 'text-rose-800 dark:text-rose-300'">
        <AppIcon :name="props.result.ok ? 'check' : 'alert'" :size="16" />
        {{ props.result.ok ? 'La conexión funciona' : 'La prueba falló' }}
      </p>
      <button type="button" class="btn-ghost px-2 text-xs" aria-label="Cerrar el resultado de la prueba" @click="$emit('close')">
        <AppIcon name="close" :size="14" />
      </button>
    </div>

    <p v-if="props.result.ok" class="mt-2 text-slate-700 dark:text-slate-200">{{ outcome }}</p>
    <template v-else>
      <p class="mt-2 text-slate-700 dark:text-slate-200">{{ ssoErrorMessage(props.result.reason) }}</p>
      <p v-if="props.result.detail" class="mt-1 break-words font-mono text-xs text-slate-500">{{ props.result.detail }}</p>
    </template>

    <dl v-if="props.result.email || props.result.name_id" class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
      <template v-if="props.result.email">
        <dt class="text-slate-500">Correo</dt>
        <dd class="break-all text-slate-800 dark:text-slate-100">{{ props.result.email }}</dd>
      </template>
      <template v-if="props.result.name">
        <dt class="text-slate-500">Nombre</dt>
        <dd class="text-slate-800 dark:text-slate-100">{{ props.result.name }}</dd>
      </template>
      <template v-if="props.result.name_id">
        <dt class="text-slate-500">NameID</dt>
        <dd class="break-all text-slate-800 dark:text-slate-100">{{ props.result.name_id }}</dd>
      </template>
    </dl>

    <details v-if="attributes.length" class="mt-3">
      <summary class="cursor-pointer text-xs font-medium text-slate-600 dark:text-slate-300">Atributos recibidos ({{ attributes.length }})</summary>
      <table class="mt-2 w-full table-fixed text-left text-xs">
        <thead>
          <tr class="text-slate-500">
            <th class="w-1/2 pb-1 font-medium">Atributo</th>
            <th class="pb-1 font-medium">Valor</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="[name, value] in attributes" :key="name" class="align-top">
            <td class="break-all py-0.5 pr-2 font-mono text-slate-600 dark:text-slate-300">{{ name }}</td>
            <td class="break-all py-0.5 text-slate-800 dark:text-slate-100">{{ value }}</td>
          </tr>
        </tbody>
      </table>
    </details>
  </div>
</template>
