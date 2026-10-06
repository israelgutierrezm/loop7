import type { Flow } from '@/types/automations'

/** Puntos de partida del editor visual de automatizaciones. */
export interface AutomationTemplate {
  key: string
  name: string
  description: string
  icon: string
  trigger: string
  flow: Flow
  /** Pide elegir marca (p. ej. para crear borradores). */
  needsBrand?: boolean
}

export const AUTOMATION_TEMPLATES: AutomationTemplate[] = [
  {
    key: 'en-blanco',
    name: 'En blanco',
    description: 'Empieza con un disparador y una acción, y arma tu propio flujo.',
    icon: 'plus',
    trigger: 'content.published',
    flow: { steps: [{ id: 'a1', type: 'action', action: 'notify', config: { message: '', audience: 'managers' } }] },
  },
  {
    key: 'aviso-publicacion',
    name: 'Avisar al equipo al publicar',
    description: 'Cada vez que se publica contenido, avisa a los responsables con el título y la marca.',
    icon: 'bell',
    trigger: 'content.published',
    flow: {
      steps: [{ id: 'a1', type: 'action', action: 'notify', config: { message: 'Se publicó «{content_title}» en {brand}.', audience: 'managers' } }],
    },
  },
  {
    key: 'seguimiento-publicacion',
    name: 'Revisar una publicación al día siguiente',
    description: 'Espera un día tras publicar y pide a quienes publican que revisen cómo va.',
    icon: 'clock',
    trigger: 'content.published',
    flow: {
      steps: [
        { id: 'w1', type: 'wait', amount: 1, unit: 'days' },
        { id: 'a1', type: 'action', action: 'notify', config: { message: 'Ya pasó un día desde «{content_title}»: revisa sus resultados.', audience: 'publishers' } },
      ],
    },
  },
  {
    key: 'blog-a-borrador',
    name: 'Borrador por cada entrada del blog',
    description: 'Cuando tu blog publica una entrada (RSS), crea un borrador con su título, resumen y enlace.',
    icon: 'document',
    trigger: 'rss.item_published',
    needsBrand: true,
    flow: {
      steps: [{ id: 'a1', type: 'action', action: 'create_draft', config: { title: '{title}', body: '{summary}\n\nLee más: {link}' } }],
    },
  },
  {
    key: 'inbox-precios',
    name: 'Responder preguntas de precios',
    description: 'Si un mensaje del inbox pregunta por precios, responde y etiqueta la conversación; si no, avisa al equipo.',
    icon: 'inbox',
    trigger: 'inbox.message_received',
    flow: {
      steps: [
        {
          id: 'b1',
          type: 'branch',
          match: 'any',
          conditions: [
            { field: 'text', operator: 'contains', value: 'precio' },
            { field: 'text', operator: 'contains', value: 'cuánto cuesta' },
          ],
          yes: [
            { id: 'a1', type: 'action', action: 'inbox_reply', config: { message: 'Hola {participant}, te enviamos los precios por mensaje directo.' } },
            { id: 'a2', type: 'action', action: 'inbox_tag', config: { tag: 'precios' } },
          ],
          no: [{ id: 'a3', type: 'action', action: 'notify', config: { message: 'Mensaje nuevo de {participant}: {text}', audience: 'team' } }],
        },
      ],
    },
  },
  {
    key: 'webhook-integracion',
    name: 'Enviar a Zapier, Make o tu servidor',
    description: 'Al publicar, envía los datos del contenido a otra herramienta con un webhook.',
    icon: 'social',
    trigger: 'content.published',
    flow: { steps: [{ id: 'a1', type: 'action', action: 'webhook', config: { url: '' } }] },
  },
]

export function findTemplate(key: string | null | undefined): AutomationTemplate {
  return AUTOMATION_TEMPLATES.find((t) => t.key === key) ?? AUTOMATION_TEMPLATES[0]
}
