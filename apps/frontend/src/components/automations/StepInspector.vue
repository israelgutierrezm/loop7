<script setup lang="ts">
import { computed } from 'vue'
import { useFlowEditor } from '@/composables/useFlowEditor'
import type { Automation } from '@/types/automations'
import { locate, stepTitle } from '@/utils/automationFlow'
import ActionPanel from '@/components/automations/ActionPanel.vue'
import BranchPanel from '@/components/automations/BranchPanel.vue'
import TraceBadge from '@/components/automations/TraceBadge.vue'
import TriggerPanel from '@/components/automations/TriggerPanel.vue'
import WaitPanel from '@/components/automations/WaitPanel.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Panel de edición del paso seleccionado en el diagrama. */
defineProps<{ current: Automation | null }>()
const emit = defineEmits<{ updated: [automation: Automation] }>()

const editor = useFlowEditor()

const location = computed(() => {
  const id = editor.selected.value
  return id && id !== 'trigger' ? locate(editor.flow.steps, id) : null
})
const title = computed(() => {
  if (editor.selected.value === 'trigger') return 'Disparador'
  return location.value ? stepTitle(location.value.step, editor.meta.value) : ''
})
const trace = computed(() => (location.value ? editor.overlay.value?.entries[location.value.step.id] ?? null : null))
const generalErrors = computed(() => (location.value ? editor.errors.value[`flow.${location.value.step.id}`] ?? [] : []))
</script>

<template>
  <section class="card p-4" aria-labelledby="inspector-title">
    <div class="mb-3 flex items-center justify-between gap-2">
      <h2 id="inspector-title" class="truncate font-semibold text-slate-900 dark:text-white">{{ title }}</h2>
      <button type="button" class="btn-ghost p-1" aria-label="Cerrar el panel" title="Cerrar" @click="editor.select(null)">
        <AppIcon name="close" :size="16" />
      </button>
    </div>

    <p v-for="message in generalErrors" :key="message" class="mb-3 flex items-start gap-1 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">
      <AppIcon name="alert" :size="12" class="mt-0.5 shrink-0" /> {{ message }}
    </p>
    <div v-if="trace" class="mb-3 rounded-lg border border-slate-200 p-2.5 dark:border-slate-700">
      <p class="mb-1 text-xs font-medium text-slate-500">{{ editor.overlay.value?.label }}</p>
      <TraceBadge :entry="trace" />
    </div>

    <TriggerPanel v-if="editor.selected.value === 'trigger'" :current="current" @updated="(a) => emit('updated', a)" />
    <ActionPanel v-else-if="location?.step.type === 'action'" :key="location.step.id" :step="location.step" />
    <WaitPanel v-else-if="location?.step.type === 'wait'" :key="location.step.id" :step="location.step" />
    <BranchPanel v-else-if="location?.step.type === 'branch'" :key="location.step.id" :step="location.step" />
  </section>
</template>
