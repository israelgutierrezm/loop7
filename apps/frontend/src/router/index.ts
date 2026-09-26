import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    requiresAuth?: boolean
    guestOnly?: boolean
    requiresPlatformAdmin?: boolean
    /** Permiso necesario para abrir la vista (la UI redirige; el backend siempre valida). */
    permission?: string
  }
}

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/app' },

  // Páginas legales públicas (requisitos de Meta / privacidad).
  { path: '/privacidad', name: 'privacy', component: () => import('@/views/legal/PrivacyView.vue'), meta: { title: 'Política de privacidad' } },
  { path: '/terminos', name: 'terms', component: () => import('@/views/legal/TermsView.vue'), meta: { title: 'Términos del servicio' } },
  { path: '/eliminar-datos', name: 'data-deletion', component: () => import('@/views/legal/DataDeletionView.vue'), meta: { title: 'Eliminación de datos' } },

  {
    path: '/',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      { path: 'login', name: 'login', component: () => import('@/views/auth/LoginView.vue'), meta: { guestOnly: true, title: 'Iniciar sesión' } },
      { path: 'registro', name: 'register', component: () => import('@/views/auth/RegisterView.vue'), meta: { guestOnly: true, title: 'Crear cuenta' } },
      { path: 'recuperar-contrasena', name: 'forgot-password', component: () => import('@/views/auth/ForgotPasswordView.vue'), meta: { guestOnly: true, title: 'Recuperar contraseña' } },
      { path: 'restablecer-contrasena', name: 'reset-password', component: () => import('@/views/auth/ResetPasswordView.vue'), meta: { guestOnly: true, title: 'Restablecer contraseña' } },
      { path: 'verificar-correo', name: 'verify-email', component: () => import('@/views/auth/VerifyEmailView.vue'), meta: { title: 'Verificar correo' } },
      { path: 'aceptar-invitacion', name: 'accept-invitation', component: () => import('@/views/auth/AcceptInvitationView.vue'), meta: { requiresAuth: true, title: 'Aceptar invitación' } },
      { path: 'nueva-organizacion', name: 'new-organization', component: () => import('@/views/auth/NewOrganizationView.vue'), meta: { requiresAuth: true, title: 'Crear organización' } },
    ],
  },

  {
    path: '/app',
    component: () => import('@/layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/views/app/DashboardView.vue'), meta: { title: 'Dashboard' } },
      { path: 'brands', name: 'brands', component: () => import('@/views/app/BrandsView.vue'), meta: { title: 'Marcas', permission: 'brands.view' } },
      { path: 'brands/:brand', name: 'brand-detail', component: () => import('@/views/app/BrandDetailView.vue'), meta: { title: 'Marca', permission: 'brands.view' } },
      { path: 'team', name: 'team', component: () => import('@/views/app/TeamView.vue'), meta: { title: 'Equipo', permission: 'members.view' } },
      { path: 'team/roles', name: 'roles', component: () => import('@/views/app/RolesView.vue'), meta: { title: 'Roles y permisos', permission: 'roles.view' } },
      { path: 'audit', name: 'audit', component: () => import('@/views/app/AuditView.vue'), meta: { title: 'Auditoría', permission: 'organization.update' } },
      { path: 'settings', name: 'settings', component: () => import('@/views/app/SettingsView.vue'), meta: { title: 'Configuración', permission: 'organization.view' } },
      { path: 'profile', name: 'profile', component: () => import('@/views/app/ProfileView.vue'), meta: { title: 'Mi perfil' } },
      { path: 'notifications', name: 'notifications', component: () => import('@/views/app/NotificationsView.vue'), meta: { title: 'Notificaciones' } },

      { path: 'content', name: 'content', component: () => import('@/views/app/ContentView.vue'), meta: { title: 'Contenido', permission: 'content.view' } },
      { path: 'content/:content', name: 'content-detail', component: () => import('@/views/app/ContentDetailView.vue'), meta: { title: 'Contenido', permission: 'content.view' } },
      { path: 'ai', name: 'ai', component: () => import('@/views/app/AiView.vue'), meta: { title: 'Asistente IA', permission: 'ai.view_usage' } },
      { path: 'media', name: 'media', component: () => import('@/views/app/MediaLibraryView.vue'), meta: { title: 'Biblioteca', permission: 'content.view' } },
      { path: 'calendar', name: 'calendar', component: () => import('@/views/app/CalendarView.vue'), meta: { title: 'Calendario', permission: 'content.view' } },
      { path: 'social', name: 'social', component: () => import('@/views/app/SocialConnectionsView.vue'), meta: { title: 'Redes sociales', permission: 'social_accounts.view' } },
      { path: 'campaigns', name: 'campaigns', component: () => import('@/views/app/CampaignsView.vue'), meta: { title: 'Campañas', permission: 'campaigns.view' } },
      { path: 'inbox', name: 'inbox', component: () => import('@/views/app/InboxView.vue'), meta: { title: 'Inbox', permission: 'social_accounts.inbox' } },
      { path: 'analytics', name: 'analytics', component: () => import('@/views/app/AnalyticsView.vue'), meta: { title: 'Analítica', permission: 'analytics.view' } },
      { path: 'automations', name: 'automations', component: () => import('@/views/app/AutomationsView.vue'), meta: { title: 'Automatizaciones', permission: 'automations.view' } },
      { path: 'billing', name: 'billing', component: () => import('@/views/app/BillingView.vue'), meta: { title: 'Facturación', permission: 'billing.view' } },
      { path: 'api-keys', name: 'api-keys', component: () => import('@/views/app/ApiKeysView.vue'), meta: { title: 'API y accesos', permission: 'api.manage' } },
    ],
  },

  {
    path: '/platform',
    component: () => import('@/layouts/PlatformLayout.vue'),
    meta: { requiresAuth: true, requiresPlatformAdmin: true },
    children: [
      { path: '', name: 'platform-dashboard', component: () => import('@/views/platform/PlatformDashboardView.vue'), meta: { title: 'Plataforma' } },
      { path: 'organizations', name: 'platform-organizations', component: () => import('@/views/platform/PlatformOrganizationsView.vue'), meta: { title: 'Organizaciones' } },
      { path: 'organizations/:organization', name: 'platform-organization', component: () => import('@/views/platform/PlatformOrganizationDetailView.vue'), meta: { title: 'Organización' } },
      { path: 'users', name: 'platform-users', component: () => import('@/views/platform/PlatformUsersView.vue'), meta: { title: 'Usuarios' } },
      { path: 'subscriptions', name: 'platform-subscriptions', component: () => import('@/views/platform/PlatformSubscriptionsView.vue'), meta: { title: 'Suscripciones' } },
      { path: 'plans', name: 'platform-plans', component: () => import('@/views/platform/PlatformPlansView.vue'), meta: { title: 'Planes' } },
      { path: 'payments', name: 'platform-payments', component: () => import('@/views/platform/PlatformPaymentsView.vue'), meta: { title: 'Pagos y facturas' } },
      { path: 'gateways', name: 'platform-gateways', component: () => import('@/views/platform/PlatformGatewaysView.vue'), meta: { title: 'Pasarelas' } },
      { path: 'social', name: 'platform-social', component: () => import('@/views/platform/PlatformSocialProvidersView.vue'), meta: { title: 'Redes sociales' } },
      { path: 'ai-providers', name: 'platform-ai', component: () => import('@/views/platform/PlatformAiProvidersView.vue'), meta: { title: 'Proveedores de IA' } },
      { path: 'jobs', name: 'platform-jobs', component: () => import('@/views/platform/PlatformJobsView.vue'), meta: { title: 'Colas' } },
      { path: 'audit', name: 'platform-audit', component: () => import('@/views/platform/PlatformAuditView.vue'), meta: { title: 'Auditoría global' } },
      { path: 'settings', name: 'platform-settings', component: () => import('@/views/platform/PlatformSettingsView.vue'), meta: { title: 'Configuración' } },
    ],
  },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { title: 'Página no encontrada' } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) {
    await auth.init()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  if (to.meta.requiresPlatformAdmin && !auth.isPlatformAdmin) {
    return { name: 'dashboard' }
  }

  // Sin organizaciones (eliminó la suya, lo quitaron de su equipo…): crear una.
  if (auth.isAuthenticated && to.path.startsWith('/app') && auth.organizations.length === 0) {
    return auth.isPlatformAdmin ? { name: 'platform-dashboard' } : { name: 'new-organization' }
  }

  // Enlaces de correos y avisos (?org=…): cambia a esa organización si el usuario pertenece a ella.
  if (typeof to.query.org === 'string' && auth.isAuthenticated) {
    const orgId = to.query.org
    if (orgId !== auth.currentOrganization?.id && auth.organizations.some((o) => o.id === orgId)) {
      await auth.selectOrganization(orgId)
    }
    const query = { ...to.query }
    delete query.org
    return { path: to.path, query, hash: to.hash, replace: true }
  }

  // Una organización suspendida no tiene permisos cargados: el panel ya muestra el aviso.
  if (to.meta.permission && auth.currentOrganization && auth.currentOrganization.status !== 'suspended' && !auth.can(to.meta.permission)) {
    useToastStore().error('Tu rol no tiene acceso a esa sección.')
    return { name: 'dashboard' }
  }

  return true
})

router.afterEach((to) => {
  // Con marca blanca, el panel de la organización se titula con su nombre.
  const product = to.path.startsWith('/app') ? (useAuthStore().branding?.name ?? 'Loop7') : 'Loop7'
  document.title = to.meta.title ? `${to.meta.title} · ${product}` : `${product} · Gestión de redes sociales`
})

export default router
