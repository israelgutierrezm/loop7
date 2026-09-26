<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { usePublicConfigStore } from '@/stores/publicConfig'
import CreateOrganizationForm from '@/components/organization/CreateOrganizationForm.vue'

const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const publicConfig = usePublicConfigStore()

onMounted(() => publicConfig.load())

function created(): void {
  toasts.success('Organización creada.')
  router.push('/app')
}

async function logout(): Promise<void> {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
      {{ publicConfig.registrationOpen ? 'Crea tu organización' : 'Sin organización' }}
    </h1>
    <p class="mt-2 text-sm text-slate-500">
      Tu cuenta no pertenece a ninguna organización.
      {{ publicConfig.registrationOpen
        ? 'Crea una para empezar o pide a tu equipo que te invite: el enlace de la invitación te sumará a la suya.'
        : 'El alta de organizaciones nuevas está cerrada por el momento: pide a tu equipo que te invite y abre el enlace de la invitación.' }}
    </p>

    <div v-if="publicConfig.registrationOpen" class="mt-6">
      <CreateOrganizationForm @created="created" />
    </div>

    <p class="mt-8 text-center text-sm text-slate-500">
      ¿No es tu cuenta?
      <button type="button" class="font-medium text-brand-600 hover:underline" @click="logout">Cerrar sesión</button>
    </p>
  </div>
</template>
