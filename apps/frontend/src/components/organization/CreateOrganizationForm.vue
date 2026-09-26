<script setup lang="ts">
import { reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'
import TimezoneSelect from '@/components/ui/TimezoneSelect.vue'

/**
 * Crea una organización (con su periodo de prueba) y la deja seleccionada.
 * Lo usan el selector de organizaciones y la pantalla de "sin organización".
 */
withDefaults(defineProps<{ cancellable?: boolean }>(), { cancellable: false })
const emit = defineEmits<{ created: []; cancel: [] }>()

const auth = useAuthStore()

function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
  } catch {
    return 'UTC'
  }
}

const form = reactive({ name: '', timezone: browserTimezone() })
const errors = ref<Record<string, string[]>>({})
const generalError = ref('')
const submitting = ref(false)

async function submit(): Promise<void> {
  submitting.value = true
  errors.value = {}
  generalError.value = ''
  try {
    const { data } = await http.post('/organizations', { name: form.name.trim(), timezone: form.timezone })
    await auth.fetchMe()
    await auth.selectOrganization(data.data.id)
    form.name = ''
    emit('created')
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) generalError.value = apiErrorMessage(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <div>
      <label class="label" for="new-org-name">Nombre de la organización</label>
      <input id="new-org-name" v-model="form.name" required maxlength="255" class="input" placeholder="Mi agencia" autocomplete="organization" />
      <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
    </div>
    <div>
      <label class="label" for="new-org-tz">Zona horaria</label>
      <TimezoneSelect id="new-org-tz" v-model="form.timezone" />
      <p v-if="errors.timezone" class="mt-1 text-xs text-rose-600">{{ errors.timezone[0] }}</p>
    </div>
    <p v-if="generalError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/40 dark:text-rose-300" role="alert">
      {{ generalError }}
    </p>
    <p class="text-xs text-slate-500">La organización nueva empieza con su propio periodo de prueba; puedes invitar a tu equipo después.</p>
    <div class="flex justify-end gap-2">
      <button v-if="cancellable" type="button" class="btn-secondary" @click="emit('cancel')">Cancelar</button>
      <button type="submit" class="btn-primary" :disabled="submitting || !form.name.trim()">
        <Spinner v-if="submitting" :size="18" /> Crear organización
      </button>
    </div>
  </form>
</template>
