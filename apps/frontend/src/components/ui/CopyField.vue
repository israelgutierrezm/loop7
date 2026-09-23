<script setup lang="ts">
import { ref, useId } from 'vue'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps<{ label: string; value: string; hint?: string }>()

const id = useId()
const copied = ref(false)

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.value)
    copied.value = true
    window.setTimeout(() => (copied.value = false), 1500)
  } catch {
    // Portapapeles no disponible (contexto no seguro): el usuario puede seleccionar el texto.
  }
}
</script>

<template>
  <div>
    <label :for="id" class="mb-1 block text-xs font-medium text-slate-500">{{ label }}</label>
    <div class="flex gap-2">
      <input :id="id" :value="value" readonly class="input font-mono text-xs" @focus="($event.target as HTMLInputElement).select()" />
      <button
        type="button"
        class="btn-secondary shrink-0 px-3 text-xs"
        :aria-label="`Copiar ${label}`"
        @click="copy"
      >
        <AppIcon :name="copied ? 'check' : 'copy'" :size="14" />
        {{ copied ? 'Copiado' : 'Copiar' }}
      </button>
    </div>
    <p v-if="hint" class="mt-1 text-xs text-slate-400">{{ hint }}</p>
  </div>
</template>
