<script setup lang="ts">
import { ref } from 'vue'
import type { CompetitorTopPost } from '@/types/competitors'
import { formatCompact } from '@/utils/numbers'
import ProviderIcon from '@/components/social/ProviderIcon.vue'

/** Publicaciones de la competencia con más interacción en el periodo. */
defineProps<{ posts: CompetitorTopPost[] }>()

const broken = ref(new Set<string>())
const types: Record<string, string> = { reel: 'Reel', carousel: 'Carrusel', video: 'Video', image: 'Imagen' }

function key(post: CompetitorTopPost): string {
  return `${post.handle}-${post.published_at}-${post.permalink}`
}

function fmt(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('es', { day: 'numeric', month: 'short' }) : ''
}

function safeUrl(url: string | null): string | null {
  return url !== null && /^https:\/\//.test(url) ? url : null
}
</script>

<template>
  <p v-if="posts.length === 0" class="py-6 text-center text-sm text-slate-500">Sin publicaciones con interacción en el periodo.</p>
  <ol v-else class="divide-y divide-slate-100 dark:divide-slate-800">
    <li v-for="(p, i) in posts" :key="key(p)" class="flex items-start gap-3 py-3">
      <span class="w-5 shrink-0 pt-0.5 text-right text-xs font-semibold tabular-nums text-slate-400">{{ i + 1 }}</span>
      <img
        v-if="safeUrl(p.thumbnail_url) && !broken.has(key(p))"
        :src="safeUrl(p.thumbnail_url) ?? ''"
        alt=""
        loading="lazy"
        referrerpolicy="no-referrer"
        class="h-14 w-14 shrink-0 rounded-lg object-cover"
        @error="broken.add(key(p))"
      />
      <span v-else class="grid h-14 w-14 shrink-0 place-items-center rounded-lg bg-slate-100 dark:bg-slate-800">
        <ProviderIcon :provider="p.provider" :size="24" />
      </span>
      <div class="min-w-0 flex-1">
        <p class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
          <span class="font-medium text-slate-700 dark:text-slate-200">{{ p.competitor }}</span>
          <span>@{{ p.handle }}</span>
          <span v-if="p.type">· {{ types[p.type] ?? p.type }}</span>
          <span>· {{ fmt(p.published_at) }}</span>
        </p>
        <p class="line-clamp-2 text-sm text-slate-700 dark:text-slate-200">{{ p.caption || 'Sin texto' }}</p>
        <p class="mt-1 flex flex-wrap gap-x-3 text-xs tabular-nums text-slate-500">
          <span v-if="p.likes !== null">{{ formatCompact(p.likes) }} «me gusta»</span>
          <span v-if="p.comments !== null">{{ formatCompact(p.comments) }} comentarios</span>
          <span v-if="p.views !== null">{{ formatCompact(p.views) }} vistas</span>
          <a v-if="safeUrl(p.permalink)" :href="safeUrl(p.permalink) ?? ''" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-600 hover:underline">Ver publicación</a>
        </p>
      </div>
    </li>
  </ol>
</template>
