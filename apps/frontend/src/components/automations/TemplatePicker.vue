<script setup lang="ts">
import { AUTOMATION_TEMPLATES } from '@/utils/automationTemplates'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Elegir cómo empezar una automatización nueva. */
defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: []; choose: [key: string] }>()
</script>

<template>
  <ModalDialog :open="open" title="Nueva automatización" description="Empieza desde una plantilla y ajústala en el editor." size="xl" @close="emit('close')">
    <ul class="grid gap-3 sm:grid-cols-2">
      <li v-for="t in AUTOMATION_TEMPLATES" :key="t.key">
        <button
          type="button"
          class="flex h-full w-full items-start gap-3 rounded-xl border border-slate-200 p-4 text-left transition hover:border-brand-400 hover:bg-brand-50/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-slate-700 dark:hover:bg-brand-950/20"
          @click="emit('choose', t.key)"
        >
          <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
            <AppIcon :name="t.icon" :size="18" />
          </span>
          <span class="min-w-0">
            <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ t.name }}</span>
            <span class="mt-0.5 block text-xs text-slate-500">{{ t.description }}</span>
          </span>
        </button>
      </li>
    </ul>
  </ModalDialog>
</template>
