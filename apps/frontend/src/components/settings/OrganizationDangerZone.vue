<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorCode, apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import type { MemberEntry } from '@/types/models'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import Spinner from '@/components/ui/Spinner.vue'

/**
 * Acciones irreversibles de la organización, sólo para su propietario:
 * transferir la propiedad y eliminarla. Ambas piden la contraseña.
 */
const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const orgName = computed(() => auth.currentOrganization?.name ?? '')

// --- Transferir la propiedad ---
const transferOpen = ref(false)
const candidates = ref<{ id: string; name: string; email: string; roles: string[] }[]>([])
const loadingCandidates = ref(false)
const transfer = reactive({ user: '', password: '' })
const transferErrors = ref<Record<string, string[]>>({})
const transferring = ref(false)

async function openTransfer(): Promise<void> {
  transferOpen.value = true
  transfer.user = ''
  transfer.password = ''
  transferErrors.value = {}
  loadingCandidates.value = true
  try {
    const { data } = await http.get('/organization/members')
    candidates.value = (data.data as MemberEntry[])
      .filter((m) => m.membership.status === 'active' && !m.membership.is_owner)
      .map((m) => ({ id: m.user.id, name: m.user.name, email: m.user.email, roles: m.roles ?? [] }))
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    transferOpen.value = false
  } finally {
    loadingCandidates.value = false
  }
}

async function submitTransfer(): Promise<void> {
  transferring.value = true
  transferErrors.value = {}
  try {
    const { data } = await http.post('/organization/transfer-ownership', { ...transfer })
    transferOpen.value = false
    toasts.success(data.message)
    await auth.fetchMe()
  } catch (e) {
    transferErrors.value = apiValidationErrors(e)
    if (!Object.keys(transferErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    transferring.value = false
  }
}

// --- Eliminar la organización ---
const deleteOpen = ref(false)
const removal = reactive({ confirm_name: '', password: '' })
const deleteErrors = ref<Record<string, string[]>>({})
const deleteBlocked = ref('')
const deleting = ref(false)
const nameMatches = computed(() => removal.confirm_name.trim().toLowerCase() === orgName.value.trim().toLowerCase())

function openDelete(): void {
  deleteOpen.value = true
  removal.confirm_name = ''
  removal.password = ''
  deleteErrors.value = {}
  deleteBlocked.value = ''
}

async function submitDelete(): Promise<void> {
  deleting.value = true
  deleteErrors.value = {}
  deleteBlocked.value = ''
  try {
    await http.delete('/organization', { data: { ...removal } })
    deleteOpen.value = false
    toasts.success(`«${orgName.value}» se eliminó.`)
    await auth.fetchMe()
    router.push('/app')
  } catch (e) {
    if (apiErrorCode(e) === 'subscription_active') {
      deleteBlocked.value = apiErrorMessage(e)
    } else {
      deleteErrors.value = apiValidationErrors(e)
      if (!Object.keys(deleteErrors.value).length) toasts.error(apiErrorMessage(e))
    }
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <section class="card border-rose-200 p-6 dark:border-rose-900/60" aria-labelledby="danger-zone-title">
    <h2 id="danger-zone-title" class="font-semibold text-rose-700 dark:text-rose-400">Zona de peligro</h2>
    <p class="mt-1 text-sm text-slate-500">Sólo tú, como propietario, ves estas acciones.</p>

    <div class="mt-5 divide-y divide-slate-100 dark:divide-slate-800">
      <div class="flex flex-wrap items-center justify-between gap-3 pb-4">
        <div class="min-w-0 flex-1">
          <p class="font-medium text-slate-800 dark:text-slate-100">Transferir la propiedad</p>
          <p class="text-sm text-slate-500">Otro miembro pasa a ser el propietario y tú quedas como administrador.</p>
        </div>
        <button type="button" class="btn-secondary text-sm" @click="openTransfer">Transferir…</button>
      </div>
      <div class="flex flex-wrap items-center justify-between gap-3 pt-4">
        <div class="min-w-0 flex-1">
          <p class="font-medium text-slate-800 dark:text-slate-100">Eliminar la organización</p>
          <p class="text-sm text-slate-500">Todo el equipo pierde el acceso a sus marcas, contenido y cuentas conectadas.</p>
        </div>
        <button type="button" class="btn-secondary text-sm text-rose-600" @click="openDelete">Eliminar…</button>
      </div>
    </div>

    <ModalDialog :open="transferOpen" title="Transferir la propiedad" size="sm" @close="transferOpen = false">
      <div v-if="loadingCandidates" class="skeleton h-24 w-full" />
      <p v-else-if="candidates.length === 0" class="text-sm text-slate-500">
        No hay otros miembros activos. Invita primero a la persona desde Equipo.
      </p>
      <form v-else class="space-y-4" @submit.prevent="submitTransfer">
        <div>
          <label class="label" for="transfer-user">Nuevo propietario</label>
          <select id="transfer-user" v-model="transfer.user" required class="input">
            <option value="" disabled>Elige un miembro</option>
            <option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.name }} · {{ c.email }}</option>
          </select>
          <p v-if="transferErrors.user" class="mt-1 text-xs text-rose-600">{{ transferErrors.user[0] }}</p>
        </div>
        <div>
          <label class="label" for="transfer-password">Tu contraseña</label>
          <input id="transfer-password" v-model="transfer.password" type="password" required autocomplete="current-password" class="input" />
          <p v-if="transferErrors.password" class="mt-1 text-xs text-rose-600">{{ transferErrors.password[0] }}</p>
        </div>
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
          La facturación y la eliminación de la organización quedarán en sus manos. Sólo esa persona podrá devolverte la propiedad.
        </p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="transferOpen = false">Cancelar</button>
          <button type="submit" class="btn-primary" :disabled="transferring || !transfer.user || !transfer.password">
            <Spinner v-if="transferring" :size="18" /> Transferir
          </button>
        </div>
      </form>
    </ModalDialog>

    <ModalDialog :open="deleteOpen" title="Eliminar la organización" size="sm" @close="deleteOpen = false">
      <form class="space-y-4" @submit.prevent="submitDelete">
        <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
          <li>Se cancelan las publicaciones programadas y se desconectan las cuentas sociales.</li>
          <li>Se revocan las API keys y las invitaciones pendientes.</li>
          <li>La suscripción se cancela y el equipo pierde el acceso.</li>
        </ul>
        <p v-if="deleteBlocked" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
          {{ deleteBlocked }}
          <RouterLink to="/app/billing" class="font-medium underline" @click="deleteOpen = false">Ir a Facturación</RouterLink>
        </p>
        <div>
          <label class="label" for="delete-name">Escribe <strong>{{ orgName }}</strong> para confirmar</label>
          <input id="delete-name" v-model="removal.confirm_name" required autocomplete="off" class="input" />
          <p v-if="deleteErrors.confirm_name" class="mt-1 text-xs text-rose-600">{{ deleteErrors.confirm_name[0] }}</p>
        </div>
        <div>
          <label class="label" for="delete-password">Tu contraseña</label>
          <input id="delete-password" v-model="removal.password" type="password" required autocomplete="current-password" class="input" />
          <p v-if="deleteErrors.password" class="mt-1 text-xs text-rose-600">{{ deleteErrors.password[0] }}</p>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="deleteOpen = false">Cancelar</button>
          <button type="submit" class="btn-danger" :disabled="deleting || !nameMatches || !removal.password">
            <Spinner v-if="deleting" :size="18" /> Eliminar para siempre
          </button>
        </div>
      </form>
    </ModalDialog>
  </section>
</template>
