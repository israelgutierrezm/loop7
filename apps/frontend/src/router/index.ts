import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const ComingSoon = () => import('@/views/app/ComingSoonView.vue')

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/app' },

  {
    path: '/',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      { path: 'login', name: 'login', component: () => import('@/views/auth/LoginView.vue'), meta: { guestOnly: true } },
      { path: 'registro', name: 'register', component: () => import('@/views/auth/RegisterView.vue'), meta: { guestOnly: true } },
      { path: 'recuperar-contrasena', name: 'forgot-password', component: () => import('@/views/auth/ForgotPasswordView.vue'), meta: { guestOnly: true } },
      { path: 'restablecer-contrasena', name: 'reset-password', component: () => import('@/views/auth/ResetPasswordView.vue'), meta: { guestOnly: true } },
      { path: 'verificar-correo', name: 'verify-email', component: () => import('@/views/auth/VerifyEmailView.vue') },
      { path: 'aceptar-invitacion', name: 'accept-invitation', component: () => import('@/views/auth/AcceptInvitationView.vue'), meta: { requiresAuth: true } },
    ],
  },

  {
    path: '/app',
    component: () => import('@/layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/views/app/DashboardView.vue'), meta: { title: 'Dashboard' } },
      { path: 'brands', name: 'brands', component: () => import('@/views/app/BrandsView.vue'), meta: { title: 'Marcas' } },
      { path: 'brands/:brand', name: 'brand-detail', component: () => import('@/views/app/BrandDetailView.vue'), meta: { title: 'Marca' } },
      { path: 'team', name: 'team', component: () => import('@/views/app/TeamView.vue'), meta: { title: 'Equipo' } },
      { path: 'audit', name: 'audit', component: () => import('@/views/app/AuditView.vue'), meta: { title: 'Auditoría' } },
      { path: 'settings', name: 'settings', component: () => import('@/views/app/SettingsView.vue'), meta: { title: 'Configuración' } },
      { path: 'profile', name: 'profile', component: () => import('@/views/app/ProfileView.vue'), meta: { title: 'Mi perfil' } },

      { path: 'content', component: ComingSoon, meta: { title: 'Crear contenido' } },
      { path: 'media', component: ComingSoon, meta: { title: 'Biblioteca' } },
      { path: 'calendar', component: ComingSoon, meta: { title: 'Calendario' } },
      { path: 'social', component: ComingSoon, meta: { title: 'Redes sociales' } },
      { path: 'campaigns', component: ComingSoon, meta: { title: 'Campañas' } },
      { path: 'inbox', component: ComingSoon, meta: { title: 'Inbox' } },
      { path: 'analytics', component: ComingSoon, meta: { title: 'Analítica' } },
      { path: 'automations', component: ComingSoon, meta: { title: 'Automatizaciones' } },
      { path: 'billing', name: 'billing', component: () => import('@/views/app/BillingView.vue'), meta: { title: 'Facturación' } },
    ],
  },

  {
    path: '/platform',
    component: () => import('@/layouts/PlatformLayout.vue'),
    meta: { requiresAuth: true, requiresPlatformAdmin: true },
    children: [
      { path: '', name: 'platform-dashboard', component: () => import('@/views/platform/PlatformDashboardView.vue'), meta: { title: 'Dashboard' } },
      { path: 'organizations', name: 'platform-organizations', component: () => import('@/views/platform/PlatformOrganizationsView.vue'), meta: { title: 'Organizaciones' } },
      { path: 'users', name: 'platform-users', component: () => import('@/views/platform/PlatformUsersView.vue'), meta: { title: 'Usuarios' } },
      { path: 'gateways', name: 'platform-gateways', component: () => import('@/views/platform/PlatformGatewaysView.vue'), meta: { title: 'Pasarelas' } },
    ],
  },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue') },
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

  return true
})

export default router
