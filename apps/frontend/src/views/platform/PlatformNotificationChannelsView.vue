<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Channels {
  webpush: {
    is_enabled: boolean
    ready: boolean
    public_key: string | null
    subject: string
    default_subject: string
    subscriptions: number
  }
  whatsapp: {
    is_enabled: boolean
    ready: boolean
    phone_number_id: string
    notice_template: string
    verification_template: string
    language: string
    access_token: string | null
    verified_users: number
  }
}

const toasts = useToastStore()
const confirm = useConfirmStore()

const channels = ref<Channels | null>(null)
const loading = ref(true)
const failed = ref(false)

const pushForm = reactive({ is_enabled: false, subject: '' })
const savingPush = ref(false)
const generating = ref(false)

const waForm = reactive({
  is_enabled: false,
  phone_number_id: '',
  access_token: '',
  notice_template: '',
  verification_template: '',
  language: 'es_MX',
})
const waErrors = ref<Record<string, string[]>>({})
const savingWa = ref(false)
const testTo = ref('')
const test = reactive<{ loading: boolean; ok?: boolean; message?: string }>({ loading: false })

function apply(data: Channels): void {
  channels.value = data
  pushForm.is_enabled = data.webpush.is_enabled
  pushForm.subject = data.webpush.subject
  Object.assign(waForm, {
    is_enabled: data.whatsapp.is_enabled,
    phone_number_id: data.whatsapp.phone_number_id,
    access_token: '',
    notice_template: data.whatsapp.notice_template,
    verification_template: data.whatsapp.verification_template,
    language: data.whatsapp.language,
  })
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/notification-channels')
    apply(data.data)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function generateKeys(): Promise<void> {
  const current = channels.value?.webpush
  if (current?.public_key) {
    const ok = await confirm.ask({
      title: 'Regenerar claves VAPID',
      message: current.subscriptions > 0
        ? `Los ${current.subscriptions} navegador(es) registrados dejarán de recibir avisos hasta que cada persona los vuelva a activar.`
        : 'Las claves actuales dejarán de servir.',
      confirmText: 'Regenerar',
      danger: true,
    })
    if (!ok) return
  }
  generating.value = true
  try {
    const { data } = await http.post('/platform/notification-channels/webpush/keys')
    apply(data.data)
    toasts.success(data.message ?? 'Claves generadas.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    generating.value = false
  }
}

async function savePush(): Promise<void> {
  savingPush.value = true
  try {
    const { data } = await http.put('/platform/notification-channels/webpush', {
      is_enabled: pushForm.is_enabled,
      subject: pushForm.subject.trim() || null,
    })
    apply(data.data)
    toasts.success(data.message ?? 'Avisos push actualizados.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    savingPush.value = false
  }
}

async function saveWhatsApp(): Promise<void> {
  savingWa.value = true
  waErrors.value = {}
  try {
    const { data } = await http.put('/platform/notification-channels/whatsapp', {
      ...waForm,
      // Vacío conserva el token guardado.
      access_token: waForm.access_token.trim() || null,
    })
    apply(data.data)
    test.message = undefined
    toasts.success(data.message ?? 'WhatsApp actualizado.')
  } catch (e) {
    waErrors.value = apiValidationErrors(e)
    toasts.error(apiErrorMessage(e))
  } finally {
    savingWa.value = false
  }
}

async function testWhatsApp(): Promise<void> {
  Object.assign(test, { loading: true, ok: undefined, message: undefined })
  try {
    const { data } = await http.post('/platform/notification-channels/whatsapp/test', { to: testTo.value.trim() || null })
    Object.assign(test, { loading: false, ok: data.data.ok, message: data.data.message })
  } catch (e) {
    Object.assign(test, { loading: false, ok: false, message: apiErrorMessage(e) })
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      title="Canales de aviso"
      description="Avisos push del navegador y por WhatsApp, además de la app y el correo. Los secretos se guardan cifrados."
    />

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed || !channels" @retry="load" />

    <div v-else class="space-y-4">
      <!-- Push -->
      <section class="card" aria-labelledby="ch-push">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5">
          <div class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
              <AppIcon name="bell" :size="20" />
            </span>
            <div>
              <h2 id="ch-push" class="font-semibold text-slate-900 dark:text-white">Avisos push del navegador</h2>
              <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <StatusBadge :tone="channels.webpush.ready ? 'success' : 'neutral'" dot>
                  {{ channels.webpush.ready ? 'Activo' : 'Inactivo' }}
                </StatusBadge>
                <span>{{ channels.webpush.subscriptions }} navegador(es) registrados</span>
              </p>
            </div>
          </div>
          <button type="button" class="btn-secondary text-sm" :disabled="generating" @click="generateKeys">
            <Spinner v-if="generating" :size="16" />
            {{ channels.webpush.public_key ? 'Regenerar claves' : 'Generar claves VAPID' }}
          </button>
        </div>

        <form class="space-y-4 border-t border-slate-100 p-5 dark:border-slate-800" @submit.prevent="savePush">
          <div>
            <p class="label">Clave pública VAPID</p>
            <code v-if="channels.webpush.public_key" class="block break-all rounded-lg bg-slate-100 px-3 py-2 text-xs dark:bg-slate-800">
              {{ channels.webpush.public_key }}
            </code>
            <p v-else class="text-sm text-slate-500">Sin claves: genera un par para poder activar los avisos push.</p>
            <p class="mt-1 text-xs text-slate-400">La clave privada se guarda cifrada y nunca se muestra.</p>
          </div>
          <div>
            <label for="push-subject" class="label">Contacto del operador</label>
            <input id="push-subject" v-model="pushForm.subject" class="input" :placeholder="channels.webpush.default_subject" />
            <p class="mt-1 text-xs text-slate-400">
              Correo (mailto:) o URL https con la que los servicios push pueden contactarte. Vacío usa el remitente del correo.
            </p>
          </div>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
              <input v-model="pushForm.is_enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" :disabled="!channels.webpush.public_key" />
              Activar avisos push
            </label>
            <button type="submit" class="btn-primary text-sm" :disabled="savingPush">
              <Spinner v-if="savingPush" :size="16" /> Guardar
            </button>
          </div>
        </form>
      </section>

      <!-- WhatsApp -->
      <section class="card" aria-labelledby="ch-wa">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5">
          <div class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40">
              <AppIcon name="inbox" :size="20" />
            </span>
            <div>
              <h2 id="ch-wa" class="font-semibold text-slate-900 dark:text-white">WhatsApp (Cloud API de Meta)</h2>
              <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <StatusBadge :tone="channels.whatsapp.ready ? 'success' : 'neutral'" dot>
                  {{ channels.whatsapp.ready ? 'Activo' : 'Inactivo' }}
                </StatusBadge>
                <span>{{ channels.whatsapp.verified_users }} persona(s) con número verificado</span>
              </p>
            </div>
          </div>
        </div>

        <div class="grid gap-6 border-t border-slate-100 p-5 lg:grid-cols-2 dark:border-slate-800">
          <form class="space-y-3" @submit.prevent="saveWhatsApp">
            <div>
              <label for="wa-pnid" class="label">Identificador del número</label>
              <input id="wa-pnid" v-model="waForm.phone_number_id" class="input font-mono text-xs" inputmode="numeric" placeholder="1098765432…" />
              <p v-if="waErrors.phone_number_id" class="mt-1 text-xs text-rose-600">{{ waErrors.phone_number_id[0] }}</p>
            </div>
            <div>
              <label for="wa-token" class="label">Token de acceso (usuario del sistema)</label>
              <input
                id="wa-token"
                v-model="waForm.access_token"
                type="password"
                class="input"
                autocomplete="new-password"
                :placeholder="channels.whatsapp.access_token ? `${channels.whatsapp.access_token} guardado · escribe para reemplazar` : ''"
              />
              <p v-if="waErrors.access_token" class="mt-1 text-xs text-rose-600">{{ waErrors.access_token[0] }}</p>
              <p v-else class="mt-1 text-xs text-slate-400">Se guarda cifrado y nunca vuelve a mostrarse.</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label for="wa-notice" class="label">Plantilla de avisos</label>
                <input id="wa-notice" v-model="waForm.notice_template" class="input font-mono text-xs" placeholder="loop7_aviso" />
                <p v-if="waErrors.notice_template" class="mt-1 text-xs text-rose-600">{{ waErrors.notice_template[0] }}</p>
              </div>
              <div>
                <label for="wa-code" class="label">Plantilla del código</label>
                <input id="wa-code" v-model="waForm.verification_template" class="input font-mono text-xs" placeholder="loop7_codigo" />
                <p v-if="waErrors.verification_template" class="mt-1 text-xs text-rose-600">{{ waErrors.verification_template[0] }}</p>
              </div>
            </div>
            <div>
              <label for="wa-lang" class="label">Idioma de las plantillas</label>
              <input id="wa-lang" v-model="waForm.language" class="input font-mono text-xs" placeholder="es_MX" />
              <p v-if="waErrors.language" class="mt-1 text-xs text-rose-600">{{ waErrors.language[0] }}</p>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
              <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                <input v-model="waForm.is_enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                Activar avisos por WhatsApp
              </label>
              <button type="submit" class="btn-primary text-sm" :disabled="savingWa">
                <Spinner v-if="savingWa" :size="16" /> Guardar
              </button>
            </div>
          </form>

          <div class="space-y-4">
            <div class="rounded-lg bg-slate-50 p-4 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
              <p class="font-semibold text-slate-800 dark:text-slate-100">Plantillas que debes aprobar en WhatsApp Manager</p>
              <ul class="mt-2 list-disc space-y-1.5 pl-4">
                <li>
                  <strong>Avisos</strong> (categoría Utilidad), con tres variables:
                  <code v-pre>{{1}}</code> organización, <code v-pre>{{2}}</code> título y <code v-pre>{{3}}</code> detalle.
                  Ejemplo: <em v-pre>«{{2}}: {{3}} ({{1}})»</em>.
                </li>
                <li>
                  <strong>Código</strong> (categoría Autenticación) con el botón «Copiar código».
                </li>
              </ul>
              <p class="mt-2">Sólo se envían avisos a organizaciones cuyo plan incluye «Avisos por WhatsApp»: cada mensaje tiene coste.</p>
            </div>

            <form class="space-y-2" @submit.prevent="testWhatsApp">
              <label for="wa-test-to" class="label">Enviar un aviso de prueba a (opcional)</label>
              <div class="flex flex-wrap gap-2">
                <input id="wa-test-to" v-model="testTo" type="tel" class="input min-w-0 flex-1" placeholder="+52 55 1234 5678" />
                <button type="submit" class="btn-secondary text-sm" :disabled="test.loading">
                  <Spinner v-if="test.loading" :size="16" /> Probar conexión
                </button>
              </div>
              <p class="text-xs text-slate-400">Prueba los datos guardados. Sin número, sólo comprueba la conexión.</p>
            </form>
            <p
              v-if="test.message"
              class="rounded-lg px-3 py-2 text-sm"
              :class="test.ok ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'"
              role="status"
            >
              {{ test.ok ? '✓' : '✗' }} {{ test.message }}
            </p>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
