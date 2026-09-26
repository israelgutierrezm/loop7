<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import type { PermissionGroup, RoleDefinition } from '@/types/models'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import Spinner from '@/components/ui/Spinner.vue'

/**
 * Crear o editar un rol personalizado: nombre, descripción y permisos por
 * grupos. Sólo se ofrecen los permisos que quien edita ya tiene (el backend
 * lo vuelve a comprobar) y nunca los exclusivos del propietario.
 */
const props = defineProps<{
  open: boolean
  role: RoleDefinition | null
  groups: PermissionGroup[]
  presets: RoleDefinition[]
  myPermissions: string[]
}>()
const emit = defineEmits<{ close: []; saved: [role: RoleDefinition] }>()

const toasts = useToastStore()
const form = reactive({ label: '', description: '', permissions: new Set<string>() })
const preset = ref('')
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)

const mine = computed(() => new Set(props.myPermissions))

function grantable(permission: { key: string; owner_only: boolean }): boolean {
  return !permission.owner_only && mine.value.has(permission.key)
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    form.label = props.role?.label ?? ''
    form.description = props.role?.description ?? ''
    form.permissions = new Set(props.role?.permissions ?? [])
    preset.value = ''
    errors.value = {}
  },
)

function applyPreset(): void {
  const source = props.presets.find((p) => p.value === preset.value)
  if (!source) return
  // Sólo lo que se puede conceder: el resto del rol de partida se descarta.
  form.permissions = new Set(source.permissions.filter((p) => mine.value.has(p) && !isOwnerOnly(p)))
}

function isOwnerOnly(key: string): boolean {
  return props.groups.some((g) => g.permissions.some((p) => p.key === key && p.owner_only))
}

function toggle(key: string, checked: boolean): void {
  const next = new Set(form.permissions)
  if (checked) next.add(key)
  else next.delete(key)
  form.permissions = next
}

function groupState(group: PermissionGroup): 'all' | 'some' | 'none' {
  const available = group.permissions.filter(grantable)
  const selected = available.filter((p) => form.permissions.has(p.key)).length
  if (selected === 0) return 'none'
  return selected === available.length ? 'all' : 'some'
}

function toggleGroup(group: PermissionGroup): void {
  const select = groupState(group) !== 'all'
  const next = new Set(form.permissions)
  for (const p of group.permissions.filter(grantable)) {
    if (select) next.add(p.key)
    else next.delete(p.key)
  }
  form.permissions = next
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  const payload = {
    label: form.label.trim(),
    description: form.description.trim() || null,
    permissions: [...form.permissions],
  }
  try {
    const { data } = props.role
      ? await http.patch(`/roles/${props.role.value}`, payload)
      : await http.post('/roles', payload)
    toasts.success(props.role ? 'Rol actualizado.' : 'Rol creado.')
    emit('saved', data.data)
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <ModalDialog
    :open="open"
    :title="role ? `Editar «${role.label}»` : 'Nuevo rol personalizado'"
    description="Elige exactamente qué puede hacer quien tenga este rol."
    size="lg"
    @close="emit('close')"
  >
    <form class="space-y-5" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="label" for="role-label">Nombre</label>
          <input id="role-label" v-model="form.label" required minlength="2" maxlength="60" class="input" placeholder="Community manager" />
          <p v-if="errors.label" class="mt-1 text-xs text-rose-600">{{ errors.label[0] }}</p>
        </div>
        <div v-if="!role">
          <label class="label" for="role-preset">Partir de <span class="text-slate-400">(opcional)</span></label>
          <select id="role-preset" v-model="preset" class="input" @change="applyPreset">
            <option value="">Sin permisos</option>
            <option v-for="p in presets" :key="p.value" :value="p.value">{{ p.label }}</option>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label class="label" for="role-description">Descripción <span class="text-slate-400">(opcional)</span></label>
          <input id="role-description" v-model="form.description" maxlength="300" class="input" placeholder="Qué hace y para quién es" />
        </div>
      </div>

      <fieldset>
        <legend class="label">Permisos <span class="font-normal text-slate-400">({{ form.permissions.size }} elegidos)</span></legend>
        <p v-if="errors.permissions" class="mb-2 text-xs text-rose-600">{{ errors.permissions[0] }}</p>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <div v-for="group in groups" :key="group.key" class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <label class="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <input
                type="checkbox"
                class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                :checked="groupState(group) === 'all'"
                :indeterminate="groupState(group) === 'some'"
                :disabled="!group.permissions.some(grantable)"
                @change="toggleGroup(group)"
              />
              {{ group.label }}
            </label>
            <ul class="mt-2 space-y-1.5 pl-6">
              <li v-for="p in group.permissions" :key="p.key">
                <label class="flex items-start gap-2 text-sm" :class="grantable(p) ? 'cursor-pointer text-slate-700 dark:text-slate-300' : 'text-slate-400'">
                  <input
                    type="checkbox"
                    class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    :checked="form.permissions.has(p.key)"
                    :disabled="!grantable(p)"
                    @change="toggle(p.key, ($event.target as HTMLInputElement).checked)"
                  />
                  <span>
                    {{ p.label }}
                    <span v-if="p.owner_only" class="block text-[11px]">Exclusivo del propietario</span>
                    <span v-else-if="!mine.has(p.key)" class="block text-[11px]">No lo tienes: no puedes concederlo</span>
                  </span>
                </label>
              </li>
            </ul>
          </div>
        </div>
      </fieldset>

      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="emit('close')">Cancelar</button>
        <button type="submit" class="btn-primary" :disabled="saving || !form.label.trim() || form.permissions.size === 0">
          <Spinner v-if="saving" :size="18" /> {{ role ? 'Guardar cambios' : 'Crear rol' }}
        </button>
      </div>
    </form>
  </ModalDialog>
</template>
