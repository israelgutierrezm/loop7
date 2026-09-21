<script setup lang="ts">
import { ref } from 'vue'
import { onClickOutside } from '@vueuse/core'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import AppIcon from '@/components/AppIcon.vue'

const auth = useAuthStore()
const toasts = useToastStore()
const open = ref(false)
const root = ref<HTMLElement | null>(null)
const switching = ref(false)

onClickOutside(root, () => (open.value = false))

async function select(id: string): Promise<void> {
  open.value = false
  if (id === auth.currentOrganization?.id) return
  switching.value = true
  try {
    await auth.selectOrganization(id)
    toasts.success('Organización cambiada.')
  } catch {
    toasts.error('No se pudo cambiar de organización.')
  } finally {
    switching.value = false
  }
}
</script>

<template>
  <div ref="root" class="relative">
    <button
      class="flex w-full items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-left text-sm transition hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800"
      :disabled="switching"
      @click="open = !open"
    >
      <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-brand-100 text-xs font-bold text-brand-700 dark:bg-brand-950/60">
        {{ (auth.currentOrganization?.name ?? '?').charAt(0).toUpperCase() }}
      </span>
      <span class="min-w-0 flex-1">
        <span class="block truncate font-semibold text-slate-800 dark:text-slate-100">
          {{ auth.currentOrganization?.name ?? 'Sin organización' }}
        </span>
        <span class="block truncate text-xs text-slate-400">
          {{ auth.currentOrganization?.roles?.[0] ?? '—' }}
        </span>
      </span>
      <AppIcon name="chevron-down" :size="16" class="shrink-0 text-slate-400" />
    </button>

    <Transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="scale-95 opacity-0"
      leave-active-class="transition duration-75 ease-in"
      leave-to-class="scale-95 opacity-0"
    >
      <div
        v-if="open"
        class="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-800 dark:bg-slate-900"
      >
        <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
          Organizaciones
        </p>
        <button
          v-for="org in auth.organizations"
          :key="org.id"
          class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
          @click="select(org.id)"
        >
          <span class="min-w-0 flex-1 truncate">{{ org.name }}</span>
          <AppIcon
            v-if="org.id === auth.currentOrganization?.id"
            name="check"
            :size="16"
            class="text-brand-600"
          />
        </button>
      </div>
    </Transition>
  </div>
</template>
