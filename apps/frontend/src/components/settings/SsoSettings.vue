<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { date, relativeTime } from '@/utils/format'
import type { SsoSettings, SsoTestResult } from '@/types/sso'
import AppIcon from '@/components/AppIcon.vue'
import CopyField from '@/components/ui/CopyField.vue'
import Spinner from '@/components/ui/Spinner.vue'
import SsoDomainList from '@/components/settings/SsoDomainList.vue'
import SsoTestResultPanel from '@/components/settings/SsoTestResult.vue'

/**
 * Inicio de sesión único (SAML) de la organización: datos para el proveedor de
 * identidad, dominios verificados, conexión, opciones y prueba (docs/03).
 */
const auth = useAuthStore()
const toasts = useToastStore()

const settings = ref<SsoSettings | null>(null)
const loaded = ref(false)
const roles = ref<{ value: string; label: string }[]>([])
const form = reactive({
  is_enabled: false,
  enforced: false,
  idp_entity_id: '',
  idp_sso_url: '',
  idp_certificate: '',
  jit_provisioning: false,
  default_role: 'VIEWER',
  email_attribute: '',
  name_attribute: '',
})
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const testing = ref(false)
const testResult = ref<SsoTestResult | null>(null)
const metadataOpen = ref(false)
const metadataXml = ref('')
const metadataError = ref('')
const importing = ref(false)
const disabled = computed(() => auth.impersonating)

const verifiedDomains = computed(() => settings.value?.domains.filter((d) => d.verified).length ?? 0)
const certificates = computed(() => settings.value?.connection.certificates ?? [])

function daysUntil(iso: string): number {
  return Math.floor((new Date(iso).getTime() - Date.now()) / 86_400_000)
}

function hydrate(data: SsoSettings): void {
  settings.value = data
  const c = data.connection
  form.is_enabled = c.is_enabled
  form.enforced = c.enforced
  form.idp_entity_id = c.idp_entity_id ?? ''
  form.idp_sso_url = c.idp_sso_url ?? ''
  form.idp_certificate = c.idp_certificate ?? ''
  form.jit_provisioning = c.jit_provisioning
  form.default_role = c.default_role
  form.email_attribute = c.email_attribute ?? ''
  form.name_attribute = c.name_attribute ?? ''
}

async function load(): Promise<void> {
  try {
    const { data } = await http.get('/organization/sso')
    hydrate(data.data)
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'No se pudo cargar la configuración de SSO.'))
  } finally {
    loaded.value = true
  }
  await loadRoles()
}

/** Roles que se pueden poner por defecto: los que quien administra puede asignar. */
async function loadRoles(): Promise<void> {
  try {
    const { data } = await http.get('/roles')
    const assignable: string[] = data.data.assignable ?? []
    roles.value = (data.data.roles as { value: string; label: string }[]).filter((role) => role.value !== 'OWNER' && assignable.includes(role.value))
  } catch {
    roles.value = [{ value: form.default_role, label: form.default_role }]
  }
}

/** Sólo los datos del formulario cambian en el servidor; los dominios recargan todo. */
async function reloadDomains(): Promise<void> {
  try {
    const { data } = await http.get('/organization/sso')
    if (settings.value) settings.value = { ...settings.value, domains: data.data.domains }
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await http.put('/organization/sso', {
      ...form,
      enforced: form.is_enabled && form.enforced,
      email_attribute: form.email_attribute || null,
      name_attribute: form.name_attribute || null,
      idp_entity_id: form.idp_entity_id || null,
      idp_sso_url: form.idp_sso_url || null,
      idp_certificate: form.idp_certificate || null,
    })
    hydrate(data.data)
    toasts.success('Configuración de SSO guardada.')
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function importMetadata(): Promise<void> {
  importing.value = true
  metadataError.value = ''
  try {
    const { data } = await http.post('/organization/sso/metadata', { xml: metadataXml.value })
    form.idp_entity_id = data.data.idp_entity_id
    form.idp_sso_url = data.data.idp_sso_url
    form.idp_certificate = data.data.idp_certificate
    metadataOpen.value = false
    metadataXml.value = ''
    toasts.success('Metadatos leídos: revisa los campos y guarda.')
  } catch (e) {
    metadataError.value = apiValidationErrors(e).xml?.[0] ?? apiErrorMessage(e)
  } finally {
    importing.value = false
  }
}

/** Lleva al proveedor de identidad; al volver se muestra qué llegó (sin iniciar sesión). */
async function test(): Promise<void> {
  testing.value = true
  try {
    const { data } = await http.post('/organization/sso/test')
    const url = String(data.data.redirect_url ?? '')
    if (!/^https?:\/\//i.test(url)) throw new Error('sso_invalid_redirect')
    window.location.assign(url)
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'No se pudo iniciar la prueba.'))
    testing.value = false
  }
}

async function loadTestResult(token: string): Promise<void> {
  try {
    const { data } = await http.get(`/organization/sso/test/${token}`)
    testResult.value = data.data
  } catch {
    toasts.error('El resultado de la prueba caducó o es de otra persona. Vuelve a probar.')
  }
}

onMounted(async () => {
  await load()
  // Vuelta de la prueba de conexión: #sso_test=<token> (se limpia de la URL).
  const match = window.location.hash.match(/^#sso_test=([A-Za-z0-9]{40})$/)
  if (match) {
    window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search)
    await loadTestResult(match[1])
  }
})
</script>

<template>
  <section class="card p-6" aria-labelledby="sso-title">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 id="sso-title" class="font-semibold text-slate-900 dark:text-white">Inicio de sesión único (SSO)</h2>
        <p class="mt-1 text-sm text-slate-500">
          Tu equipo entra con la cuenta de la empresa (Microsoft Entra ID, Okta, Google Workspace u otro proveedor SAML 2.0).
        </p>
      </div>
      <span
        v-if="settings?.available && settings.connection.is_enabled"
        class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
      >Activo</span>
    </div>

    <div v-if="!loaded" class="skeleton mt-4 h-24 w-full" />

    <div v-else-if="settings && !settings.available" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
      <span class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
        <AppIcon name="sparkles" :size="16" /> Disponible en el plan Enterprise.
      </span>
      <RouterLink v-if="auth.can('billing.view')" to="/app/billing" class="btn-secondary text-sm">Ver planes</RouterLink>
    </div>

    <div v-else-if="settings" class="mt-5 space-y-6">
      <p v-if="disabled" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
        Mientras impersonas a un usuario no se puede cambiar el SSO.
      </p>

      <SsoTestResultPanel v-if="testResult" :result="testResult" @close="testResult = null" />

      <!-- 1. Datos para el proveedor de identidad -->
      <div>
        <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">1. Registra Loop7 en tu proveedor de identidad</h3>
        <p class="mt-1 text-xs text-slate-500">Crea una aplicación SAML con estos datos o importa la URL de metadatos.</p>
        <div class="mt-3 space-y-3">
          <CopyField label="Identificador (Entity ID) y URL de metadatos" :value="settings.service_provider.entity_id" />
          <CopyField label="URL de respuesta (ACS)" :value="settings.service_provider.acs_url" hint="Enlace HTTP-POST. Las aserciones deben ir firmadas." />
        </div>
      </div>

      <!-- 2. Dominios -->
      <div>
        <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">2. Verifica los dominios de correo</h3>
        <p class="mt-1 text-xs text-slate-500">
          Sólo quien tenga un correo de un dominio verificado puede entrar con SSO. Un dominio verificado pertenece a una sola organización.
        </p>
        <div class="mt-3">
          <SsoDomainList :domains="settings.domains" :max="settings.max_domains" :disabled="disabled" @changed="reloadDomains" />
        </div>
      </div>

      <!-- 3. Conexión y opciones -->
      <form class="space-y-4" @submit.prevent="save">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">3. Conecta tu proveedor de identidad</h3>
          <button type="button" class="btn-ghost text-xs" :disabled="disabled" :aria-expanded="metadataOpen" @click="metadataOpen = !metadataOpen">
            <AppIcon name="upload" :size="14" /> Importar metadatos XML
          </button>
        </div>

        <div v-if="metadataOpen" class="space-y-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
          <label class="label" for="sso-metadata">Metadatos del proveedor de identidad</label>
          <textarea id="sso-metadata" v-model="metadataXml" rows="5" class="input font-mono text-xs" placeholder="<md:EntityDescriptor …>" />
          <p v-if="metadataError" class="text-xs text-rose-600">{{ metadataError }}</p>
          <div class="flex justify-end">
            <button type="button" class="btn-secondary text-sm" :disabled="importing || !metadataXml.trim()" @click="importMetadata">
              <Spinner v-if="importing" :size="16" /> Leer metadatos
            </button>
          </div>
        </div>

        <fieldset :disabled="disabled" class="space-y-4">
          <div>
            <label class="label" for="sso-entity">Identificador del proveedor (Entity ID / emisor)</label>
            <input id="sso-entity" v-model="form.idp_entity_id" class="input" maxlength="1024" placeholder="https://sts.windows.net/…/" />
            <p v-if="errors.idp_entity_id" class="mt-1 text-xs text-rose-600">{{ errors.idp_entity_id[0] }}</p>
          </div>
          <div>
            <label class="label" for="sso-url">URL de inicio de sesión (SSO, HTTP-Redirect)</label>
            <input id="sso-url" v-model="form.idp_sso_url" type="url" class="input" maxlength="2048" placeholder="https://login.microsoftonline.com/…/saml2" />
            <p v-if="errors.idp_sso_url" class="mt-1 text-xs text-rose-600">{{ errors.idp_sso_url[0] }}</p>
          </div>
          <div>
            <label class="label" for="sso-cert">Certificado de firma (X.509, PEM)</label>
            <textarea id="sso-cert" v-model="form.idp_certificate" rows="4" class="input font-mono text-xs" placeholder="-----BEGIN CERTIFICATE-----" />
            <ul v-if="certificates.length" class="mt-1 space-y-0.5 text-xs">
              <li v-for="c in certificates" :key="c.expires_at + c.subject" :class="daysUntil(c.expires_at) < 30 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500'">
                {{ c.subject || 'Certificado' }} · caduca el {{ date(c.expires_at) }}
                <template v-if="daysUntil(c.expires_at) < 0">(caducado: renuévalo en tu proveedor)</template>
                <template v-else-if="daysUntil(c.expires_at) < 30">(pronto: añade el nuevo certificado junto al actual)</template>
              </li>
            </ul>
            <p v-if="errors.idp_certificate" class="mt-1 text-xs text-rose-600">{{ errors.idp_certificate[0] }}</p>
          </div>

          <details class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <summary class="cursor-pointer text-sm font-medium text-slate-700 dark:text-slate-200">Atributos (opcional)</summary>
            <p class="mt-2 text-xs text-slate-500">Por defecto se usan los habituales (email, mail, emailaddress…) y, si no hay, el NameID.</p>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="label" for="sso-attr-email">Atributo del correo</label>
                <input id="sso-attr-email" v-model="form.email_attribute" class="input" maxlength="255" placeholder="email" />
              </div>
              <div>
                <label class="label" for="sso-attr-name">Atributo del nombre</label>
                <input id="sso-attr-name" v-model="form.name_attribute" class="input" maxlength="255" placeholder="displayName" />
              </div>
            </div>
          </details>

          <div class="space-y-3 border-t border-slate-200 pt-4 dark:border-slate-700">
            <label class="flex items-start gap-3 text-sm">
              <input v-model="form.jit_provisioning" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
              <span>
                <span class="font-medium text-slate-800 dark:text-slate-100">Alta automática</span>
                <span class="block text-xs text-slate-500">
                  Quien entre por primera vez sin cuenta en Loop7 se une con el rol por defecto (si quedan plazas en el plan). Las cuentas que ya existen necesitan invitación.
                </span>
              </span>
            </label>
            <div v-if="form.jit_provisioning" class="pl-7">
              <label class="label" for="sso-role">Rol por defecto</label>
              <select id="sso-role" v-model="form.default_role" class="input sm:w-64">
                <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
              </select>
              <p v-if="errors.default_role" class="mt-1 text-xs text-rose-600">{{ errors.default_role[0] }}</p>
            </div>

            <label class="flex items-start gap-3 text-sm">
              <input v-model="form.is_enabled" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
              <span>
                <span class="font-medium text-slate-800 dark:text-slate-100">Activar el inicio de sesión único</span>
                <span class="block text-xs text-slate-500">
                  Requiere la configuración completa y al menos un dominio verificado ({{ verifiedDomains }} ahora). Pruébala antes de activarla.
                </span>
              </span>
            </label>
            <p v-if="errors.is_enabled" class="pl-7 text-xs text-rose-600">{{ errors.is_enabled[0] }}</p>

            <label class="flex items-start gap-3 text-sm" :class="!form.is_enabled ? 'opacity-60' : ''">
              <input v-model="form.enforced" type="checkbox" :disabled="!form.is_enabled" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
              <span>
                <span class="font-medium text-slate-800 dark:text-slate-100">Hacerlo obligatorio</span>
                <span class="block text-xs text-slate-500">
                  Los miembros con correo de tus dominios ya no podrán entrar con contraseña. La persona propietaria conserva su contraseña como acceso de emergencia.
                </span>
              </span>
            </label>
            <p v-if="errors.enforced" class="pl-7 text-xs text-rose-600">{{ errors.enforced[0] }}</p>
          </div>
        </fieldset>

        <p v-if="settings.connection.last_login_at" class="text-xs text-slate-500">
          Último acceso con SSO {{ relativeTime(settings.connection.last_login_at) }}.
        </p>

        <div class="flex flex-wrap justify-end gap-2">
          <button
            type="button"
            class="btn-secondary"
            :disabled="disabled || testing || !settings.connection.configured"
            :title="settings.connection.configured ? undefined : 'Guarda primero la configuración'"
            @click="test"
          >
            <Spinner v-if="testing" :size="16" /> Probar conexión
          </button>
          <button type="submit" class="btn-primary" :disabled="disabled || saving">
            <Spinner v-if="saving" :size="18" /> Guardar SSO
          </button>
        </div>
      </form>
    </div>
  </section>
</template>
