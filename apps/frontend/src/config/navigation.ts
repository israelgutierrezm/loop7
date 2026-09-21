export interface NavItem {
  label: string
  to: string
  icon: string
  /** Permiso requerido para mostrar (la UI oculta; el backend siempre valida). */
  permission?: string
  /** Módulo aún no implementado: se muestra con badge "Pronto". */
  soon?: boolean
}

export interface NavGroup {
  label?: string
  items: NavItem[]
}

export const navigation: NavGroup[] = [
  {
    items: [{ label: 'Dashboard', to: '/app', icon: 'dashboard' }],
  },
  {
    label: 'Contenido',
    items: [
      { label: 'Crear', to: '/app/content', icon: 'content', permission: 'content.view', soon: true },
      { label: 'Biblioteca', to: '/app/media', icon: 'brands', soon: true },
      { label: 'Calendario', to: '/app/calendar', icon: 'calendar', soon: true },
    ],
  },
  {
    label: 'Gestión',
    items: [
      { label: 'Marcas', to: '/app/brands', icon: 'brands', permission: 'brands.view' },
      { label: 'Redes sociales', to: '/app/social', icon: 'social', permission: 'social_accounts.view', soon: true },
      { label: 'Campañas', to: '/app/campaigns', icon: 'sparkles', permission: 'campaigns.view', soon: true },
      { label: 'Inbox', to: '/app/inbox', icon: 'inbox', permission: 'social_accounts.inbox', soon: true },
      { label: 'Analítica', to: '/app/analytics', icon: 'analytics', permission: 'analytics.view', soon: true },
    ],
  },
  {
    label: 'Organización',
    items: [
      { label: 'Equipo', to: '/app/team', icon: 'team', permission: 'members.view' },
      { label: 'Automatizaciones', to: '/app/automations', icon: 'automations', soon: true },
      { label: 'Facturación', to: '/app/billing', icon: 'billing', permission: 'billing.view', soon: true },
      { label: 'Auditoría', to: '/app/audit', icon: 'shield', permission: 'organization.update' },
      { label: 'Configuración', to: '/app/settings', icon: 'settings', permission: 'organization.view' },
    ],
  },
]
