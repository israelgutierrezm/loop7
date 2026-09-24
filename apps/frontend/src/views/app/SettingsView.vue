<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import Spinner from '@/components/ui/Spinner.vue'
import TimezoneSelect from '@/components/ui/TimezoneSelect.vue'

const auth = useAuthStore()
const toasts = useToastStore()

const form = reactive({ name: '', billing_email: '', timezone: 'UTC' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const canEdit = auth.can('organization.update')

function hydrate(): void {
  const org = auth.currentOrganization
  if (!org) return
  form.name = org.name
  form.billing_email = org.billing_email ?? ''
  form.timezone = org.timezone
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await http.patch('/organization', { ...form })
    if (auth.currentOrganization) {
      auth.currentOrganization = { ...auth.currentOrganization, ...data.data }
    }
    toasts.success('Organización actualizada.')
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

onMounted(hydrate)
</script>

<template>
  <div>
    <PageHeader title="Configuración" description="Ajustes generales de tu organización." />

    <form class="card max-w-2xl p-6" @submit.prevent="save">
      <fieldset :disabled="!canEdit" class="space-y-4">
        <div>
          <label class="label" for="o-name">Nombre de la organización</label>
          <input id="o-name" v-model="form.name" type="text" class="input" />
          <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
        </div>
        <div>
          <label class="label" for="o-email">Correo de facturación</label>
          <input id="o-email" v-model="form.billing_email" type="email" class="input" />
          <p v-if="errors.billing_email" class="mt-1 text-xs text-rose-600">{{ errors.billing_email[0] }}</p>
        </div>
        <div>
          <label class="label" for="o-tz">Zona horaria de la organización</label>
          <TimezoneSelect id="o-tz" v-model="form.timezone" />
          <p class="mt-1 text-xs text-slate-500">Se usa en las fechas de los avisos y correos de facturación.</p>
          <p v-if="errors.timezone" class="mt-1 text-xs text-rose-600">{{ errors.timezone[0] }}</p>
        </div>
      </fieldset>

      <p v-if="!canEdit" class="mt-4 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-500 dark:bg-slate-800">
        Solo lectura: no tienes permiso para editar la organización.
      </p>

      <div v-if="canEdit" class="mt-6 flex justify-end">
        <button type="submit" class="btn-primary" :disabled="saving">
          <Spinner v-if="saving" :size="18" /> Guardar cambios
        </button>
      </div>
    </form>
  </div>
</template>
