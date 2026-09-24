<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import LegalShell from '@/components/legal/LegalShell.vue'
import { useCompany } from '@/composables/useCompany'

interface Status {
  confirmation_code: string
  provider: string
  status: string
  requested_at: string | null
  completed_at: string | null
}

const route = useRoute()
const { email } = useCompany()
const code = ref('')
const result = ref<Status | null>(null)
const notFound = ref(false)
const loading = ref(false)

async function check(): Promise<void> {
  if (!code.value.trim()) return
  loading.value = true
  result.value = null
  notFound.value = false
  try {
    const res = await fetch(`/api/v1/data-deletion/status/${encodeURIComponent(code.value.trim())}`, {
      headers: { Accept: 'application/json' },
    })
    if (res.ok) {
      result.value = await res.json()
    } else {
      notFound.value = true
    }
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

const statusLabel: Record<string, string> = { pending: 'En proceso', completed: 'Completada' }

onMounted(() => {
  const q = route.query.code as string | undefined
  if (q) {
    code.value = q
    check()
  }
})
</script>

<template>
  <LegalShell title="Borrado de datos">
    <p>
      Puedes solicitar la eliminación de los datos asociados a tu cuenta o a las cuentas de redes sociales que hayas
      conectado a Loop7.
    </p>

    <h2>Cómo solicitarlo</h2>
    <ul>
      <li>
        <strong>Desde tu cuenta:</strong> inicia sesión y, en cada marca, desconecta la red social; o escríbenos a
        <a :href="`mailto:${email}`">{{ email }}</a> pidiendo la eliminación de tus datos.
      </li>
      <li>
        <strong>Desde Facebook/Meta:</strong> en la configuración de tu cuenta de Facebook puedes eliminar el acceso
        de Loop7. Meta nos notificará y procesaremos el borrado automáticamente, entregándote un código de
        confirmación.
      </li>
    </ul>
    <p>
      Al procesar la solicitud eliminamos las conexiones sociales cuya cuenta te pertenece y las conversaciones del
      inbox en las que participas.
    </p>

    <h2>Consultar el estado</h2>
    <p>Introduce tu código de confirmación para ver el estado de tu solicitud:</p>

    <div class="not-prose flex flex-wrap items-center gap-2">
      <label for="dd-code" class="sr-only">Código de confirmación</label>
      <input
        id="dd-code"
        v-model="code"
        class="input w-auto flex-1"
        placeholder="Código de confirmación"
        @keyup.enter="check"
      />
      <button class="btn-primary text-sm" :disabled="loading" @click="check">
        {{ loading ? 'Consultando…' : 'Consultar' }}
      </button>
    </div>

    <div v-if="result" class="not-prose rounded-lg border border-slate-200 p-4 dark:border-slate-700">
      <p class="text-sm">
        Estado:
        <span
          class="font-semibold"
          :class="result.status === 'completed' ? 'text-emerald-600' : 'text-amber-600'"
        >
          {{ statusLabel[result.status] ?? result.status }}
        </span>
      </p>
      <p class="mt-1 text-xs text-slate-400">Código: {{ result.confirmation_code }} · Proveedor: {{ result.provider }}</p>
    </div>
    <p v-else-if="notFound" class="text-sm text-rose-600">No encontramos ninguna solicitud con ese código.</p>

    <h2>Contacto</h2>
    <p>Si tienes dudas, escríbenos a <a :href="`mailto:${email}`">{{ email }}</a>.</p>
  </LegalShell>
</template>
