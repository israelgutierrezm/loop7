<script setup lang="ts">
/** Variables del disparador que se pueden insertar en los textos ({campo}). */
defineProps<{ fields: string[]; free?: boolean }>()
const emit = defineEmits<{ pick: [token: string] }>()

// En funciones y no en la plantilla: «}}» dentro de {{ }} corta la interpolación.
const token = (field: string): string => `{${field}}`
</script>

<template>
  <div class="rounded-lg bg-slate-50 p-2.5 text-xs dark:bg-slate-800/60">
    <p class="mb-1.5 text-slate-500">
      <template v-if="fields.length">Inserta datos del disparador en los textos:</template>
      <template v-else>Aún no hay variables conocidas.</template>
      <template v-if="free"> Del JSON recibido usa <code class="rounded bg-white px-1 dark:bg-slate-900">{campo}</code> o <code class="rounded bg-white px-1 dark:bg-slate-900">{objeto.campo}</code>.</template>
    </p>
    <div v-if="fields.length" class="flex flex-wrap gap-1">
      <button
        v-for="f in fields"
        :key="f"
        type="button"
        class="rounded-md border border-slate-200 bg-white px-1.5 py-0.5 font-mono text-[11px] text-slate-700 transition hover:border-brand-400 hover:text-brand-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
        :title="`Insertar ${token(f)}`"
        :aria-label="`Insertar ${token(f)}`"
        @mousedown.prevent
        @click="emit('pick', token(f))"
      >
        {{ token(f) }}
      </button>
    </div>
  </div>
</template>
