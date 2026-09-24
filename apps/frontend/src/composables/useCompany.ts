import { computed, onMounted } from 'vue'
import { usePublicConfigStore } from '@/stores/publicConfig'

/**
 * Datos de la empresa configurados en SUPERADMIN → Configuración, con un
 * marcador visible cuando falta alguno (para las páginas legales).
 */
export function useCompany() {
  const store = usePublicConfigStore()
  onMounted(() => store.load())

  const value = (field: keyof NonNullable<typeof store.company>, placeholder: string) =>
    computed(() => (store.company?.[field] ? String(store.company[field]) : placeholder))

  const name = value('name', 'Loop7')
  const legalName = value('legal_name', '[NOMBRE DE LA EMPRESA]')
  const email = value('contact_email', '[CORREO DE CONTACTO]')
  const supportEmail = value('support_email', '[CORREO DE SOPORTE]')
  const country = value('country', '[PAÍS/JURISDICCIÓN]')
  const address = computed(() => store.company?.address ?? '')
  const complete = computed(() => Boolean(store.company?.legal_name && store.company?.contact_email && store.company?.country))

  return { loaded: computed(() => store.loaded), name, legalName, email, supportEmail, country, address, complete }
}
