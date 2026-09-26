import { defineStore } from 'pinia'
import { ref } from 'vue'

const COLLAPSE_KEY = 'loop7.sidebarCollapsed'

export const useUiStore = defineStore('ui', () => {
  const readCollapsed = (): boolean => {
    try {
      return localStorage.getItem(COLLAPSE_KEY) === '1'
    } catch {
      return false
    }
  }

  const sidebarCollapsed = ref(readCollapsed())
  const mobileDrawerOpen = ref(false)
  /** Buscador de comandos (Ctrl/⌘+K). */
  const commandPaletteOpen = ref(false)

  function openCommandPalette(): void {
    mobileDrawerOpen.value = false
    commandPaletteOpen.value = true
  }

  function closeCommandPalette(): void {
    commandPaletteOpen.value = false
  }

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value
    try {
      localStorage.setItem(COLLAPSE_KEY, sidebarCollapsed.value ? '1' : '0')
    } catch {
      /* noop */
    }
  }

  function openMobileDrawer(): void {
    mobileDrawerOpen.value = true
  }

  function closeMobileDrawer(): void {
    mobileDrawerOpen.value = false
  }

  return {
    sidebarCollapsed,
    mobileDrawerOpen,
    commandPaletteOpen,
    toggleSidebar,
    openMobileDrawer,
    closeMobileDrawer,
    openCommandPalette,
    closeCommandPalette,
  }
})
