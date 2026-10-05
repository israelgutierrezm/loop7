<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PushDeviceSetting from '@/components/profile/PushDeviceSetting.vue'
import WhatsAppNumberSetting from '@/components/profile/WhatsAppNumberSetting.vue'

/**
 * Qué avisos llegan además de la campana y por qué canal: correo, push del
 * navegador o WhatsApp (docs/05).
 */
type Channel = 'mail' | 'push' | 'whatsapp'

interface Category {
  key: string
  label: string
  description: string
  mail: boolean
  push: boolean
  whatsapp: boolean
}

interface Preferences {
  channels: {
    mail: { label: string; available: boolean }
    push: { label: string; available: boolean; public_key: string | null; devices: number }
    whatsapp: { label: string; available: boolean; phone: string | null }
  }
  categories: Category[]
}

const emit = defineEmits<{ loaded: [] }>()

const toasts = useToastStore()
const prefs = ref<Preferences | null>(null)
const saving = ref<string | null>(null)

/** Columnas de la tabla: WhatsApp sólo con un número verificado. */
const columns = computed<Channel[]>(() => {
  const channels = prefs.value?.channels
  if (!channels) return ['mail']
  const list: Channel[] = ['mail']
  if (channels.push.available) list.push('push')
  if (channels.whatsapp.available && channels.whatsapp.phone) list.push('whatsapp')
  return list
})

async function load(): Promise<void> {
  try {
    const { data } = await http.get('/me/notification-preferences')
    prefs.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    emit('loaded')
  }
}

async function toggle(category: Category, channel: Channel): Promise<void> {
  if (!prefs.value) return
  saving.value = `${category.key}.${channel}`
  try {
    const { data } = await http.put('/me/notification-preferences', { [channel]: { [category.key]: !category[channel] } })
    prefs.value.categories = data.data.categories
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = null
  }
}

function setDevices(count: number): void {
  if (prefs.value) prefs.value.channels.push.devices = count
}

function setPhone(phone: string | null): void {
  if (prefs.value) prefs.value.channels.whatsapp.phone = phone
}

onMounted(load)
</script>

<template>
  <section id="notificaciones" class="card scroll-mt-20 p-6" aria-labelledby="notif-title">
    <h2 id="notif-title" class="font-semibold text-slate-900 dark:text-white">Notificaciones</h2>
    <p class="mt-1 text-sm text-slate-500">
      En la app (campana) recibes todos los avisos. Elige cuáles quieres además por
      {{ columns.length > 1 ? 'correo, push o WhatsApp' : 'correo' }}.
    </p>

    <div v-if="!prefs" class="mt-4 skeleton h-40 w-full" />

    <template v-else>
      <div v-if="prefs.channels.push.available || prefs.channels.whatsapp.available || prefs.channels.whatsapp.phone" class="mt-4 space-y-3">
        <PushDeviceSetting
          v-if="prefs.channels.push.available && prefs.channels.push.public_key"
          :public-key="prefs.channels.push.public_key"
          @devices="setDevices"
        />
        <WhatsAppNumberSetting
          v-if="prefs.channels.whatsapp.available || prefs.channels.whatsapp.phone"
          :phone="prefs.channels.whatsapp.phone"
          @changed="setPhone"
        />
        <p v-if="prefs.channels.whatsapp.phone && !prefs.channels.whatsapp.available" class="text-xs text-amber-700 dark:text-amber-400">
          Ahora mismo no se envían avisos por WhatsApp: el plan de tus organizaciones no lo incluye o el canal está en pausa.
        </p>
      </div>

      <div class="mt-4 overflow-x-auto">
        <table class="w-full text-sm">
          <caption class="sr-only">Canales por tipo de aviso</caption>
          <thead>
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
              <th scope="col" class="py-2 pr-4 font-semibold">Aviso</th>
              <th v-for="channel in columns" :key="channel" scope="col" class="w-20 px-2 py-2 text-center font-semibold">
                {{ prefs.channels[channel].label }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="c in prefs.categories" :key="c.key">
              <th scope="row" class="py-3 pr-4 text-left font-normal">
                <span class="block font-medium text-slate-800 dark:text-slate-100">{{ c.label }}</span>
                <span class="block text-xs text-slate-500">{{ c.description }}</span>
              </th>
              <td v-for="channel in columns" :key="channel" class="px-2 py-3 text-center">
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:opacity-60 dark:border-slate-600 dark:bg-slate-800"
                  :checked="c[channel]"
                  :disabled="saving === `${c.key}.${channel}`"
                  :aria-label="`${c.label}: recibir por ${prefs.channels[channel].label}`"
                  @change="toggle(c, channel)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="columns.includes('push') && prefs.channels.push.devices === 0" class="mt-2 text-xs text-slate-500">
        Los avisos push llegan a los navegadores donde los actives.
      </p>
    </template>
  </section>
</template>
