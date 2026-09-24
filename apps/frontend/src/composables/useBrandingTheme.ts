import { onBeforeUnmount, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

/** Mezclas sobre blanco (tonos claros) y negro (oscuros) del color base = tono 600. */
const SHADES: Record<string, string> = {
  '50': 'color-mix(in oklab, BASE 8%, white)',
  '100': 'color-mix(in oklab, BASE 16%, white)',
  '200': 'color-mix(in oklab, BASE 30%, white)',
  '300': 'color-mix(in oklab, BASE 48%, white)',
  '400': 'color-mix(in oklab, BASE 70%, white)',
  '500': 'color-mix(in oklab, BASE 86%, white)',
  '600': 'BASE',
  '700': 'color-mix(in oklab, BASE 84%, black)',
  '800': 'color-mix(in oklab, BASE 70%, black)',
  '900': 'color-mix(in oklab, BASE 56%, black)',
  '950': 'color-mix(in oklab, BASE 36%, black)',
}

function apply(color: string | null): void {
  const root = document.documentElement.style
  for (const [shade, value] of Object.entries(SHADES)) {
    const variable = `--color-brand-${shade}`
    if (color) root.setProperty(variable, value.replace('BASE', color))
    else root.removeProperty(variable)
  }
}

/**
 * Marca blanca: sustituye la paleta "brand" de Tailwind (variables CSS del
 * tema) por la derivada del color de la organización, mientras dure el panel.
 */
export function useBrandingTheme(): void {
  const auth = useAuthStore()
  watch(() => auth.branding?.color ?? null, apply, { immediate: true })
  onBeforeUnmount(() => apply(null))
}

/** Relación de contraste WCAG de un color #rrggbb con el blanco (texto de botones). */
export function contrastWithWhite(hex: string): number {
  const match = /^#?([0-9a-f]{6})$/i.exec(hex)
  if (!match) return 0
  const channels = [0, 2, 4].map((i) => {
    const c = parseInt(match[1].slice(i, i + 2), 16) / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  const luminance = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
  return 1.05 / (luminance + 0.05)
}
