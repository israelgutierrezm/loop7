<script setup lang="ts">
import { computed } from 'vue'
import { navigation, type NavGroup } from '@/config/navigation'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/AppIcon.vue'

const props = withDefaults(defineProps<{ collapsed?: boolean }>(), { collapsed: false })
const emit = defineEmits<{ navigate: [] }>()

const auth = useAuthStore()

const groups = computed<NavGroup[]>(() =>
  navigation
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.permission || auth.can(item.permission)),
    }))
    .filter((group) => group.items.length > 0),
)
</script>

<template>
  <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4" aria-label="Navegación principal">
    <div v-for="(group, gi) in groups" :key="gi">
      <p
        v-if="group.label && !props.collapsed"
        class="mb-1 px-2 text-xs font-semibold uppercase tracking-wider text-slate-400"
      >
        {{ group.label }}
      </p>
      <ul class="space-y-0.5">
        <li v-for="item in group.items" :key="item.to">
          <RouterLink
            :to="item.to"
            :title="props.collapsed ? item.label : undefined"
            class="group flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
            active-class="!bg-brand-50 !text-brand-700 dark:!bg-brand-950/60 dark:!text-brand-300"
            :class="props.collapsed ? 'justify-center' : ''"
            @click="emit('navigate')"
          >
            <AppIcon :name="item.icon" :size="20" class="shrink-0" />
            <template v-if="!props.collapsed">
              <span class="flex-1 truncate">{{ item.label }}</span>
              <span
                v-if="item.soon"
                class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-400 dark:bg-slate-800"
              >
                Pronto
              </span>
            </template>
          </RouterLink>
        </li>
      </ul>
    </div>
  </nav>
</template>
