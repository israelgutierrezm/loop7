<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useDebounceFn } from '@vueuse/core'
import http from '@/services/http'
import { navigation } from '@/config/navigation'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import AppIcon from '@/components/AppIcon.vue'

/**
 * Buscador de comandos (Ctrl/⌘+K, docs/10): ir a cualquier sección, acciones
 * rápidas (crear, invitar, cambiar de organización) y búsqueda de contenido,
 * marcas, campañas y miembros (GET /search, acotado por permisos y marcas).
 */
interface SearchHit {
  id: string
  title: string
  subtitle: string | null
  url: string
}
interface Command {
  id: string
  group: string
  label: string
  hint?: string | null
  icon: string
  keywords?: string
  run: () => void | Promise<void>
}

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()

const query = ref('')
const active = ref(0)
const remote = ref<Record<string, SearchHit[]>>({})
const searching = ref(false)
const input = ref<HTMLInputElement | null>(null)
let previousFocus: HTMLElement | null = null
let requestId = 0

const REMOTE_GROUPS: Record<string, { label: string; icon: string }> = {
  content: { label: 'Contenido', icon: 'content' },
  brands: { label: 'Marcas', icon: 'brands' },
  campaigns: { label: 'Campañas', icon: 'sparkles' },
  members: { label: 'Equipo', icon: 'user' },
}
const GROUP_ORDER = ['Acciones', 'Ir a', 'Organizaciones', ...Object.values(REMOTE_GROUPS).map((g) => g.label), 'Cuenta']

function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

function go(to: string | { path: string; query?: Record<string, string> }): void {
  void router.push(to)
}

const localCommands = computed<Command[]>(() => {
  const list: Command[] = []
  const hasBrand = auth.brands.length > 0

  if (auth.can('content.create') && hasBrand) {
    list.push({ id: 'new-content', group: 'Acciones', label: 'Crear contenido', icon: 'plus', keywords: 'post publicacion nuevo', run: () => go({ path: '/app/content', query: { crear: '1' } }) })
  }
  if (auth.can('brands.create')) {
    list.push({ id: 'new-brand', group: 'Acciones', label: 'Nueva marca', icon: 'plus', keywords: 'crear marca cliente', run: () => go({ path: '/app/brands', query: { crear: '1' } }) })
  }
  if (auth.can('campaigns.create') && hasBrand) {
    list.push({ id: 'new-campaign', group: 'Acciones', label: 'Nueva campaña', icon: 'plus', keywords: 'crear campana', run: () => go({ path: '/app/campaigns', query: { crear: '1' } }) })
  }
  if (auth.can('members.invite')) {
    list.push({ id: 'invite', group: 'Acciones', label: 'Invitar a alguien al equipo', icon: 'team', keywords: 'miembro invitacion', run: () => go({ path: '/app/team', query: { invitar: '1' } }) })
  }
  if (auth.can('roles.create')) {
    list.push({ id: 'new-role', group: 'Acciones', label: 'Nuevo rol personalizado', icon: 'lock', keywords: 'permisos', run: () => go({ path: '/app/team/roles', query: { crear: '1' } }) })
  }

  for (const item of navigation.flatMap((g) => g.items)) {
    if (item.permission && !auth.can(item.permission)) continue
    list.push({ id: `nav:${item.to}`, group: 'Ir a', label: item.label, icon: item.icon, run: () => go(item.to) })
  }
  list.push({ id: 'nav:notifications', group: 'Ir a', label: 'Notificaciones', icon: 'bell', run: () => go('/app/notifications') })
  if (auth.isPlatformAdmin && !auth.impersonating) {
    list.push({ id: 'nav:platform', group: 'Ir a', label: 'Panel de plataforma', icon: 'shield', keywords: 'superadmin', run: () => go('/platform') })
  }

  for (const org of auth.organizations) {
    if (org.id === auth.currentOrganization?.id) continue
    list.push({
      id: `org:${org.id}`,
      group: 'Organizaciones',
      label: `Cambiar a ${org.name}`,
      icon: 'building',
      keywords: 'organizacion',
      run: async () => {
        await auth.selectOrganization(org.id)
        go('/app')
      },
    })
  }

  list.push({ id: 'profile', group: 'Cuenta', label: 'Mi perfil', icon: 'user', keywords: 'contrasena seguridad doble factor sesiones', run: () => go('/app/profile') })
  list.push({
    id: 'logout',
    group: 'Cuenta',
    label: 'Cerrar sesión',
    icon: 'logout',
    keywords: 'salir',
    run: async () => {
      await auth.logout()
      go('/login')
    },
  })

  return list
})

const results = computed<Command[]>(() => {
  const q = normalize(query.value.trim())
  const local = q
    ? localCommands.value.filter((c) => normalize(`${c.label} ${c.keywords ?? ''}`).includes(q))
    : localCommands.value.filter((c) => c.group !== 'Cuenta')

  const hits: Command[] = Object.entries(remote.value).flatMap(([type, list]) =>
    (list ?? []).map((hit) => ({
      id: `${type}:${hit.id}`,
      group: REMOTE_GROUPS[type]?.label ?? type,
      label: hit.title,
      hint: hit.subtitle,
      icon: REMOTE_GROUPS[type]?.icon ?? 'search',
      run: () => go(hit.url),
    })),
  )

  return [...local, ...hits].sort((a, b) => GROUP_ORDER.indexOf(a.group) - GROUP_ORDER.indexOf(b.group))
})

const grouped = computed(() => {
  const groups: { label: string; items: { command: Command; index: number }[] }[] = []
  results.value.forEach((command, index) => {
    let group = groups.find((g) => g.label === command.group)
    if (!group) {
      group = { label: command.group, items: [] }
      groups.push(group)
    }
    group.items.push({ command, index })
  })
  return groups
})

const searchRemote = useDebounceFn(async (text: string) => {
  const current = ++requestId
  if (text.trim().length < 2) {
    remote.value = {}
    searching.value = false
    return
  }
  try {
    const { data } = await http.get('/search', { params: { q: text.trim() } })
    if (current === requestId) remote.value = data.data ?? {}
  } catch {
    if (current === requestId) remote.value = {}
  } finally {
    if (current === requestId) searching.value = false
  }
}, 250)

watch(query, (text) => {
  active.value = 0
  searching.value = text.trim().length >= 2
  void searchRemote(text)
})

watch(results, () => {
  if (active.value >= results.value.length) active.value = Math.max(0, results.value.length - 1)
})

watch(
  () => ui.commandPaletteOpen,
  async (open) => {
    if (open) {
      previousFocus = document.activeElement as HTMLElement | null
      query.value = ''
      remote.value = {}
      active.value = 0
      await nextTick()
      input.value?.focus()
    } else {
      previousFocus?.focus?.()
    }
  },
)

async function run(command: Command | undefined): Promise<void> {
  if (!command) return
  ui.closeCommandPalette()
  await command.run()
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    active.value = results.value.length ? (active.value + 1) % results.value.length : 0
    scrollActiveIntoView()
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    active.value = results.value.length ? (active.value - 1 + results.value.length) % results.value.length : 0
    scrollActiveIntoView()
  } else if (event.key === 'Enter') {
    event.preventDefault()
    void run(results.value[active.value])
  } else if (event.key === 'Escape') {
    event.preventDefault()
    ui.closeCommandPalette()
  }
}

function scrollActiveIntoView(): void {
  void nextTick(() => document.getElementById(`cmdk-opt-${active.value}`)?.scrollIntoView({ block: 'nearest' }))
}

// Atajo global: Ctrl+K (Windows/Linux) o ⌘+K (Mac).
function onGlobalKeydown(event: KeyboardEvent): void {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    if (ui.commandPaletteOpen) ui.closeCommandPalette()
    else ui.openCommandPalette()
  }
}

onMounted(() => window.addEventListener('keydown', onGlobalKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKeydown))
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0"
    >
      <div v-if="ui.commandPaletteOpen" class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 p-4 pt-[10vh]" @mousedown.self="ui.closeCommandPalette()">
        <div
          class="w-full max-w-xl overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
          role="dialog"
          aria-modal="true"
          aria-label="Buscador de comandos"
        >
          <div class="flex items-center gap-3 border-b border-slate-100 px-4 dark:border-slate-800">
            <AppIcon name="search" :size="18" class="shrink-0 text-slate-400" />
            <input
              ref="input"
              v-model="query"
              type="text"
              role="combobox"
              aria-expanded="true"
              aria-controls="cmdk-list"
              aria-autocomplete="list"
              :aria-activedescendant="results.length ? `cmdk-opt-${active}` : undefined"
              aria-label="Busca contenido, marcas o comandos"
              placeholder="Busca contenido, marcas, campañas, personas o una acción…"
              class="h-14 min-w-0 flex-1 bg-transparent text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none dark:text-white"
              @keydown="onKeydown"
            />
            <span v-if="searching" class="text-xs text-slate-400" aria-live="polite">Buscando…</span>
            <kbd class="hidden rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-400 sm:block dark:border-slate-700">Esc</kbd>
          </div>

          <ul id="cmdk-list" role="listbox" aria-label="Resultados" class="max-h-[60vh] overflow-y-auto py-2">
            <template v-for="group in grouped" :key="group.label">
              <li role="presentation" class="px-4 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400 first:pt-1">
                {{ group.label }}
              </li>
              <li
                v-for="{ command, index } in group.items"
                :id="`cmdk-opt-${index}`"
                :key="command.id"
                role="option"
                :aria-selected="index === active"
                class="mx-2 flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm"
                :class="index === active ? 'bg-brand-50 text-brand-800 dark:bg-brand-950/50 dark:text-brand-200' : 'text-slate-700 dark:text-slate-200'"
                @mousemove="active = index"
                @click="run(command)"
              >
                <AppIcon :name="command.icon" :size="16" class="shrink-0 opacity-70" />
                <span class="min-w-0 flex-1 truncate">{{ command.label }}</span>
                <span v-if="command.hint" class="max-w-[45%] shrink-0 truncate text-xs text-slate-400">{{ command.hint }}</span>
              </li>
            </template>
            <li v-if="results.length === 0 && !searching" role="presentation" class="px-4 py-8 text-center text-sm text-slate-500">
              Sin resultados para «{{ query }}».
            </li>
          </ul>

          <div class="flex items-center gap-4 border-t border-slate-100 px-4 py-2 text-[11px] text-slate-400 dark:border-slate-800">
            <span><kbd class="font-sans">↑↓</kbd> moverse</span>
            <span><kbd class="font-sans">↵</kbd> abrir</span>
            <span class="ml-auto">Ctrl/⌘ K para abrir o cerrar</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
