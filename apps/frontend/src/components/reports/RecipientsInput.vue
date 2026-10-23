<script setup lang="ts">
import { ref, useId } from 'vue'
import { looksLikeEmail } from '@/utils/reports'
import AppIcon from '@/components/AppIcon.vue'

/**
 * Destinatarios como «chips»: se añaden con Intro, coma o al salir del campo
 * (también pegando varios a la vez) y se quitan con su botón.
 */
const props = defineProps<{ modelValue: string[]; max: number; error?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const id = useId()
const draft = ref('')
const invalid = ref('')

function commit(): void {
  const parts = draft.value.split(/[\s,;]+/).map((p) => p.trim().toLowerCase()).filter(Boolean)
  if (!parts.length) return
  const bad = parts.filter((p) => !looksLikeEmail(p))
  const next = [...props.modelValue]
  for (const email of parts.filter(looksLikeEmail)) {
    if (!next.includes(email) && next.length < props.max) next.push(email)
  }
  emit('update:modelValue', next)
  invalid.value = bad.length ? `No parece un correo: ${bad.join(', ')}` : ''
  draft.value = bad.join(' ')
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault()
    commit()
  } else if (event.key === 'Backspace' && draft.value === '' && props.modelValue.length) {
    emit('update:modelValue', props.modelValue.slice(0, -1))
  }
}

function remove(email: string): void {
  emit('update:modelValue', props.modelValue.filter((e) => e !== email))
}
</script>

<template>
  <div>
    <label :for="id" class="label">Destinatarios <span class="text-slate-400">({{ modelValue.length }}/{{ max }})</span></label>
    <div class="input flex min-h-10 flex-wrap items-center gap-1.5 py-1.5" :class="error ? 'border-rose-400' : ''">
      <span
        v-for="email in modelValue"
        :key="email"
        class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-xs text-brand-700 dark:bg-brand-950/40 dark:text-brand-300"
      >
        {{ email }}
        <button type="button" class="rounded-full p-0.5 hover:bg-brand-100 dark:hover:bg-brand-900" :aria-label="`Quitar ${email}`" @click="remove(email)">
          <AppIcon name="close" :size="10" />
        </button>
      </span>
      <input
        :id="id"
        v-model="draft"
        type="text"
        inputmode="email"
        autocomplete="off"
        class="min-w-40 flex-1 border-0 bg-transparent p-0 text-sm focus:outline-none focus:ring-0"
        :placeholder="modelValue.length ? '' : 'cliente@empresa.com'"
        :disabled="modelValue.length >= max"
        @keydown="onKeydown"
        @blur="commit"
        @paste="$nextTick(commit)"
      />
    </div>
    <p v-if="invalid" class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ invalid }}</p>
    <p v-else-if="error" class="mt-1 text-xs text-rose-600">{{ error }}</p>
    <p v-else class="mt-1 text-xs text-slate-500">Cada persona recibe su propio correo con el PDF; nadie ve las direcciones de los demás.</p>
  </div>
</template>
