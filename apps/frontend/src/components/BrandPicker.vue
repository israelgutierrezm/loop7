<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ modelValue: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const auth = useAuthStore()
const brands = computed(() => auth.brands)

function onChange(event: Event): void {
  emit('update:modelValue', (event.target as HTMLSelectElement).value)
}
</script>

<template>
  <div class="flex items-center gap-2">
    <label class="text-sm text-slate-500">Marca:</label>
    <select :value="modelValue ?? ''" class="input w-auto py-1.5 text-sm" @change="onChange">
      <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
    </select>
  </div>
</template>
