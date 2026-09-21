<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import AppIcon from '@/components/AppIcon.vue'

const auth = useAuthStore()

const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? '')

const steps = computed(() => [
  {
    icon: 'brands',
    title: 'Crea tu primera marca',
    description: 'Define la identidad de tu negocio para empezar a publicar.',
    done: auth.brands.length > 0,
    to: '/app/brands',
    cta: 'Ir a marcas',
    enabled: auth.can('brands.create') || auth.can('brands.view'),
  },
  {
    icon: 'team',
    title: 'Invita a tu equipo',
    description: 'Colabora con permisos granulares por rol.',
    done: false,
    to: '/app/team',
    cta: 'Gestionar equipo',
    enabled: auth.can('members.view'),
  },
  {
    icon: 'social',
    title: 'Conecta tus redes sociales',
    description: 'Publica en Facebook, Instagram, LinkedIn y más (próximamente).',
    done: false,
    to: '/app/social',
    cta: 'Próximamente',
    enabled: true,
  },
])
</script>

<template>
  <div>
    <PageHeader :title="`Hola, ${firstName} 👋`" description="Este es el resumen de tu organización." />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <StatCard label="Marcas" :value="auth.brands.length" icon="brands" hint="Marcas accesibles" />
      <StatCard
        label="Tu rol"
        :value="auth.currentOrganization?.roles?.[0] ?? '—'"
        icon="shield"
        :hint="auth.currentOrganization?.name ?? ''"
      />
      <StatCard
        label="Estado"
        :value="auth.currentOrganization?.status === 'active' ? 'Activa' : (auth.currentOrganization?.status ?? '—')"
        icon="check"
        hint="Suscripción"
      />
    </div>

    <div class="card mt-6 p-6">
      <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Primeros pasos</h2>
      <p class="mt-1 text-sm text-slate-500">Completa la configuración para sacar el máximo provecho.</p>

      <ul class="mt-5 space-y-3">
        <li
          v-for="(step, i) in steps"
          :key="i"
          class="flex items-center gap-4 rounded-lg border border-slate-100 p-4 dark:border-slate-800"
        >
          <span
            class="grid h-10 w-10 shrink-0 place-items-center rounded-full"
            :class="step.done ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'"
          >
            <AppIcon :name="step.done ? 'check' : step.icon" :size="20" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="font-medium text-slate-800 dark:text-slate-100">{{ step.title }}</p>
            <p class="text-sm text-slate-500">{{ step.description }}</p>
          </div>
          <RouterLink v-if="step.enabled" :to="step.to" class="btn-secondary shrink-0 text-xs">
            {{ step.cta }}
          </RouterLink>
        </li>
      </ul>
    </div>
  </div>
</template>
