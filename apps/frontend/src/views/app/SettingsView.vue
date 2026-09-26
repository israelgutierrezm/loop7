<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { contrastWithWhite } from '@/composables/useBrandingTheme'
import PageHeader from '@/components/ui/PageHeader.vue'
import Spinner from '@/components/ui/Spinner.vue'
import TimezoneSelect from '@/components/ui/TimezoneSelect.vue'
import AppIcon from '@/components/AppIcon.vue'
import OrganizationDangerZone from '@/components/settings/OrganizationDangerZone.vue'

const auth = useAuthStore()
const toasts = useToastStore()
const canEdit = auth.can('organization.update')

// --- Datos generales ---
const form = reactive({ name: '', billing_email: '', tax_id: '', country: '', timezone: 'UTC' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

// Países ISO 3166-1 alfa-2 con nombre en español (Intl).
const COUNTRY_CODES =
  'AR BO BR CA CL CO CR CU DO EC ES GT HN MX NI PA PE PR PY SV US UY VE AD AE AF AG AI AL AM AO AQ AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BQ BS BT BV BW BY BZ CC CD CF CG CH CI CK CM CN CV CW CX CY CZ DE DJ DK DM DZ EE EG EH ER ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GU GW GY HK HM HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MY MZ NA NC NE NF NG NL NO NP NR NU NZ OM PF PG PH PK PL PM PN PS PT PW QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM UZ VA VC VG VI VN VU WF WS YE YT ZA ZM ZW'.split(' ')
const countries = computed(() => {
  const names = new Intl.DisplayNames(['es'], { type: 'region' })
  return COUNTRY_CODES.map((code) => ({ code, name: names.of(code) ?? code })).sort((a, b) => a.name.localeCompare(b.name, 'es'))
})

function hydrate(): void {
  const org = auth.currentOrganization
  if (!org) return
  form.name = org.name
  form.billing_email = org.billing_email ?? ''
  form.tax_id = org.tax_id ?? ''
  form.country = org.country ?? ''
  form.timezone = org.timezone
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await http.patch('/organization', {
      name: form.name,
      billing_email: form.billing_email || null,
      tax_id: form.tax_id || null,
      country: form.country || null,
      timezone: form.timezone,
    })
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

// --- Marca blanca ---
const white = reactive({ available: false, display_name: '', primary_color: '#4f46e5', logo_url: null as string | null })
const whiteLoaded = ref(false)
const whiteErrors = ref<Record<string, string[]>>({})
const savingWhite = ref(false)
const uploadingLogo = ref(false)
const colorContrast = computed(() => contrastWithWhite(white.primary_color))
const colorValid = computed(() => /^#[0-9a-f]{6}$/i.test(white.primary_color))

async function loadWhiteLabel(): Promise<void> {
  try {
    const { data } = await http.get('/organization/branding')
    white.available = data.data.available
    white.display_name = data.data.display_name ?? ''
    white.primary_color = data.data.primary_color ?? '#4f46e5'
    white.logo_url = data.data.logo_url
  } catch {
    white.available = false
  } finally {
    whiteLoaded.value = true
  }
}

async function saveWhiteLabel(): Promise<void> {
  savingWhite.value = true
  whiteErrors.value = {}
  try {
    await http.put('/organization/branding', {
      display_name: white.display_name || null,
      primary_color: white.primary_color || null,
    })
    await auth.loadContext()
    toasts.success('Marca blanca guardada.')
  } catch (e) {
    whiteErrors.value = apiValidationErrors(e)
    if (!Object.keys(whiteErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    savingWhite.value = false
  }
}

async function uploadLogo(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploadingLogo.value = true
  const body = new FormData()
  body.append('logo', file)
  try {
    const { data } = await http.post('/organization/branding/logo', body)
    white.logo_url = data.data.logo_url
    await auth.loadContext()
    toasts.success('Logo actualizado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploadingLogo.value = false
    input.value = ''
  }
}

async function removeLogo(): Promise<void> {
  try {
    await http.delete('/organization/branding/logo')
    white.logo_url = null
    await auth.loadContext()
    toasts.success('Logo quitado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(() => {
  hydrate()
  void loadWhiteLabel()
})
</script>

<template>
  <div class="max-w-2xl space-y-6">
    <PageHeader title="Configuración" description="Ajustes generales de tu organización." />

    <form class="card p-6" @submit.prevent="save">
      <h2 class="mb-4 font-semibold text-slate-900 dark:text-white">Datos de la organización</h2>
      <fieldset :disabled="!canEdit" class="space-y-4">
        <div>
          <label class="label" for="o-name">Nombre de la organización</label>
          <input id="o-name" v-model="form.name" type="text" required maxlength="255" class="input" />
          <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
        </div>
        <div>
          <label class="label" for="o-email">Correo de facturación</label>
          <input id="o-email" v-model="form.billing_email" type="email" class="input" />
          <p v-if="errors.billing_email" class="mt-1 text-xs text-rose-600">{{ errors.billing_email[0] }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="o-country">País</label>
            <select id="o-country" v-model="form.country" class="input">
              <option value="">Sin especificar</option>
              <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
            </select>
            <p v-if="errors.country" class="mt-1 text-xs text-rose-600">{{ errors.country[0] }}</p>
          </div>
          <div>
            <label class="label" for="o-tax">Identificación fiscal <span class="text-slate-400">(RFC, NIF, CUIT…)</span></label>
            <input id="o-tax" v-model="form.tax_id" type="text" maxlength="64" class="input" />
            <p v-if="errors.tax_id" class="mt-1 text-xs text-rose-600">{{ errors.tax_id[0] }}</p>
          </div>
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

    <!-- Marca blanca -->
    <section class="card p-6" aria-labelledby="wl-title">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 id="wl-title" class="font-semibold text-slate-900 dark:text-white">Marca blanca</h2>
          <p class="mt-1 text-sm text-slate-500">
            Presenta la plataforma con el nombre, el color y el logo de tu organización: en el panel de todo tu equipo y en los correos.
          </p>
        </div>
        <span v-if="whiteLoaded && white.available" class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
          Incluida en tu plan
        </span>
      </div>

      <div v-if="!whiteLoaded" class="skeleton mt-4 h-24 w-full" />

      <div v-else-if="!white.available" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
        <span class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
          <AppIcon name="sparkles" :size="16" /> Disponible en los planes Agency y Enterprise.
        </span>
        <RouterLink v-if="auth.can('billing.view')" to="/app/billing" class="btn-secondary text-sm">Ver planes</RouterLink>
      </div>

      <form v-else class="mt-5 space-y-5" @submit.prevent="saveWhiteLabel">
        <fieldset :disabled="!canEdit" class="space-y-5">
          <div>
            <label class="label" for="wl-name">Nombre visible</label>
            <input id="wl-name" v-model="white.display_name" maxlength="60" class="input" :placeholder="auth.currentOrganization?.name" />
            <p class="mt-1 text-xs text-slate-500">Sustituye a «Loop7» en el panel, el título de la pestaña y el remitente de los correos.</p>
            <p v-if="whiteErrors.display_name" class="mt-1 text-xs text-rose-600">{{ whiteErrors.display_name[0] }}</p>
          </div>

          <div>
            <label class="label" for="wl-color">Color principal</label>
            <div class="flex flex-wrap items-center gap-3">
              <input id="wl-color" v-model="white.primary_color" type="color" class="h-10 w-14 cursor-pointer rounded border border-slate-300 bg-white p-1 dark:border-slate-700" />
              <input v-model="white.primary_color" class="input w-32 font-mono" maxlength="7" aria-label="Color principal (hexadecimal)" />
              <span
                class="rounded-lg px-4 py-2 text-sm font-semibold text-white"
                :style="{ backgroundColor: colorValid ? white.primary_color : '#94a3b8' }"
                aria-hidden="true"
              >Vista previa</span>
            </div>
            <p v-if="colorValid && colorContrast < 4.5" class="mt-1 text-xs text-amber-700 dark:text-amber-400">
              Contraste {{ colorContrast.toFixed(1) }}:1 con texto blanco (mínimo 4.5:1): elige un tono más oscuro.
            </p>
            <p v-if="whiteErrors.primary_color" class="mt-1 text-xs text-rose-600">{{ whiteErrors.primary_color[0] }}</p>
          </div>

          <div>
            <p class="label">Logo</p>
            <div class="flex flex-wrap items-center gap-3">
              <img v-if="white.logo_url" :src="white.logo_url" alt="Logo actual" class="h-14 w-14 rounded-xl bg-white object-contain ring-1 ring-slate-200 dark:ring-slate-700" />
              <span v-else class="grid h-14 w-14 place-items-center rounded-xl bg-slate-100 text-slate-400 dark:bg-slate-800">
                <AppIcon name="building" :size="22" />
              </span>
              <label class="btn-secondary cursor-pointer text-sm" :class="!canEdit ? 'pointer-events-none opacity-60' : ''">
                <Spinner v-if="uploadingLogo" :size="16" />
                {{ white.logo_url ? 'Cambiar logo' : 'Subir logo' }}
                <input type="file" class="sr-only" accept="image/png,image/jpeg,image/webp" :disabled="uploadingLogo || !canEdit" @change="uploadLogo" />
              </label>
              <button v-if="white.logo_url && canEdit" type="button" class="btn-ghost text-sm text-rose-600" @click="removeLogo">Quitar</button>
            </div>
            <p class="mt-1 text-xs text-slate-500">PNG, JPG o WEBP de hasta 1 MB; cuadrado de al menos 128 px se ve mejor.</p>
          </div>
        </fieldset>

        <div v-if="canEdit" class="flex justify-end">
          <button type="submit" class="btn-primary" :disabled="savingWhite || (colorValid && colorContrast < 4.5)">
            <Spinner v-if="savingWhite" :size="18" /> Guardar marca blanca
          </button>
        </div>
      </form>
    </section>

    <OrganizationDangerZone v-if="auth.currentOrganization?.is_owner && !auth.impersonating" />
  </div>
</template>
