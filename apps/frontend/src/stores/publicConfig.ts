import { defineStore } from 'pinia'
import { ref } from 'vue'
import http from '@/services/http'

export interface CompanyInfo {
  name: string
  legal_name: string
  tax_id: string
  contact_email: string
  support_email: string
  country: string
  address: string
}

export interface Announcement {
  message: string
  tone: 'info' | 'warning'
}

/**
 * Configuración pública de la plataforma (SUPERADMIN → Configuración): datos
 * legales, registro abierto y aviso del sistema. Se carga una vez por sesión.
 */
export const usePublicConfigStore = defineStore('publicConfig', () => {
  const company = ref<CompanyInfo | null>(null)
  const registrationOpen = ref(true)
  const announcement = ref<Announcement | null>(null)
  const loaded = ref(false)
  let pending: Promise<void> | null = null

  async function load(force = false): Promise<void> {
    if (loaded.value && !force) return
    pending ??= http
      .get('/public-config')
      .then(({ data }) => {
        company.value = data.data.company
        registrationOpen.value = Boolean(data.data.registration_open)
        announcement.value = data.data.announcement
        loaded.value = true
      })
      .catch(() => {
        // Sin configuración pública la app sigue funcionando con los valores por defecto.
      })
      .finally(() => {
        pending = null
      })
    return pending
  }

  return { company, registrationOpen, announcement, loaded, load }
})
