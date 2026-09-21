# IA y Brand Brain

## Arquitectura agnóstica
Contratos separados para texto, imagen, video, embeddings y transcripción.

## Proveedores previstos
- OpenAI
- Anthropic
- Google Gemini
- otros configurables

## Configuración SUPERADMIN
- provider enabled
- environment/config
- modelos habilitados
- modelo por defecto
- coste interno estimado
- créditos cobrados por operación
- límites globales

## BYOK
Planes avanzados pueden permitir claves propias por Organization. Cifradas, nunca visibles completas y nunca enviadas al navegador tras guardado.

## Brand Brain
Contexto de una Brand:
- descripción
- voz y tono
- audiencias
- productos/servicios
- propuestas de valor
- CTA
- hashtags
- vocabulario preferido/prohibido
- documentos
- URLs importadas
- FAQs

## RAG
Los documentos del Brand Brain pueden indexarse para retrieval. Separar embeddings por Organization/Brand. Nunca mezclar contexto entre tenants.

## AI Usage
Registrar:
- organization_id
- brand_id
- user_id
- provider/model
- operation
- tokens/unidades
- coste estimado
- créditos consumidos
- latency
- status

No registrar prompts completos cuando contengan secretos o PII innecesaria; permitir políticas de retención.
