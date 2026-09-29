const NAMES: Record<string, string> = {
  fake: 'Proveedor de prueba',
  facebook: 'Facebook',
  instagram: 'Instagram',
  threads: 'Threads',
  linkedin: 'LinkedIn',
  x: 'X',
  youtube: 'YouTube',
  tiktok: 'TikTok',
}

/** Nombre visible de una red social a partir de su clave. */
export function providerName(key: string): string {
  return NAMES[key] ?? key
}
