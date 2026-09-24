<script setup lang="ts">
import { computed } from 'vue'

/** Selector de zona horaria IANA agrupado por región (valores válidos para el backend). */
const model = defineModel<string>({ required: true })
defineProps<{ id?: string; disabled?: boolean }>()

const REGIONS: Record<string, string> = {
  America: 'América',
  Europe: 'Europa',
  Africa: 'África',
  Asia: 'Asia',
  Australia: 'Australia',
  Pacific: 'Pacífico',
  Atlantic: 'Atlántico',
  Indian: 'Índico',
  Antarctica: 'Antártida',
  Arctic: 'Ártico',
}
const FALLBACK = [
  'America/Mexico_City', 'America/Monterrey', 'America/Cancun', 'America/Tijuana', 'America/Bogota',
  'America/Lima', 'America/Santiago', 'America/Argentina/Buenos_Aires', 'America/Caracas',
  'America/New_York', 'America/Los_Angeles', 'Europe/Madrid', 'Europe/London',
]

const groups = computed(() => {
  const intl = Intl as unknown as { supportedValuesOf?: (key: 'timeZone') => string[] }
  const zones = new Set(intl.supportedValuesOf?.('timeZone') ?? FALLBACK)
  if (model.value) zones.add(model.value)

  const byRegion = new Map<string, string[]>()
  for (const zone of zones) {
    const region = REGIONS[zone.split('/')[0] ?? ''] ?? 'Otras'
    byRegion.set(region, [...(byRegion.get(region) ?? []), zone])
  }
  return [...byRegion.entries()]
    .sort(([a], [b]) => (a === 'Otras' ? 1 : b === 'Otras' ? -1 : a.localeCompare(b, 'es')))
    .map(([label, list]) => ({ label, zones: list.sort() }))
})

function label(zone: string): string {
  return zone.split('/').slice(1).join(' / ').replaceAll('_', ' ') || zone
}
</script>

<template>
  <select :id="id" v-model="model" class="input" :disabled="disabled">
    <option value="UTC">UTC (tiempo universal)</option>
    <optgroup v-for="g in groups" :key="g.label" :label="g.label">
      <option v-for="z in g.zones.filter((z) => z !== 'UTC')" :key="z" :value="z">{{ label(z) }}</option>
    </optgroup>
  </select>
</template>
