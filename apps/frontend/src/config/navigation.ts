export interface NavItem {
  label: string
  to: string
  icon: string
  /** Permiso requerido para mostrar (la UI oculta; el backend siempre valida). */
  permission?: string
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
      { label: 'Crear', to: '/app/content', icon: 'content', permission: 'content.view' },
      { label: 'Asistente IA', to: '/app/ai', icon: 'ai', permission: 'ai.view_usage' },
      { label: 'Biblioteca', to: '/app/media', icon: 'brands', permission: 'content.view' },
      { label: 'Calendario', to: '/app/calendar', icon: 'calendar', permission: 'content.view' },
    ],
  },
  {
    label: 'Gestión',
    items: [
      { label: 'Marcas', to: '/app/brands', icon: 'brands', permission: 'brands.view' },
      { label: 'Redes sociales', to: '/app/social', icon: 'social', permission: 'social_accounts.view' },
      { label: 'Campañas', to: '/app/campaigns', icon: 'sparkles', permission: 'campaigns.view' },
      { label: 'Inbox', to: '/app/inbox', icon: 'inbox', permission: 'social_accounts.inbox' },
      { label: 'Analítica', to: '/app/analytics', icon: 'analytics', permission: 'analytics.view' },
    ],
  },
  {
    label: 'Organización',
    items: [
      { label: 'Equipo', to: '/app/team', icon: 'team', permission: 'members.view' },
      { label: 'Roles y permisos', to: '/app/team/roles', icon: 'lock', permission: 'roles.view' },
      { label: 'Automatizaciones', to: '/app/automations', icon: 'automations', permission: 'automations.view' },
      { label: 'Facturación', to: '/app/billing', icon: 'billing', permission: 'billing.view' },
      { label: 'API y accesos', to: '/app/api-keys', icon: 'key', permission: 'api.manage' },
      { label: 'Auditoría', to: '/app/audit', icon: 'shield', permission: 'organization.update' },
      { label: 'Configuración', to: '/app/settings', icon: 'settings', permission: 'organization.view' },
    ],
  },
]
