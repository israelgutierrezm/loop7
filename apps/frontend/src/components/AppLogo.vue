<script setup lang="ts">
import { ref, watch } from 'vue'
import type { Branding } from '@/types/models'

/** Logo de la plataforma o, con marca blanca, el de la organización. */
const props = withDefaults(defineProps<{ showText?: boolean; branding?: Branding | null }>(), {
  showText: true,
  branding: null,
})

const logoFailed = ref(false)
watch(() => props.branding?.logo_url, () => (logoFailed.value = false))
</script>

<template>
  <div v-if="branding" class="flex min-w-0 items-center gap-2.5">
    <img
      v-if="branding.logo_url && !logoFailed"
      :src="branding.logo_url"
      :alt="`Logo de ${branding.name}`"
      class="h-9 w-9 shrink-0 rounded-xl bg-white object-contain"
      @error="logoFailed = true"
    />
    <span
      v-else
      class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-600 text-sm font-bold text-white shadow-sm"
      aria-hidden="true"
    >
      {{ branding.name.charAt(0).toUpperCase() }}
    </span>
    <span v-if="showText" class="truncate text-lg font-bold tracking-tight text-slate-900 dark:text-white">
      {{ branding.name }}
    </span>
  </div>

  <div v-else class="flex items-center gap-2.5">
    <span
      class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-sm"
      aria-hidden="true"
    >
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
        <path
          d="M8 12a4 4 0 108 0 4 4 0 00-8 0zm0 0c0-3.5-2-5.5-4.5-5.5M16 12c0 3.5 2 5.5 4.5 5.5"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
        />
      </svg>
    </span>
    <span v-if="showText" class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">
      Loop<span class="text-brand-600">7</span>
    </span>
  </div>
</template>
