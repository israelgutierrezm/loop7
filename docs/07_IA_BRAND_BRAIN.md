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

## Implementación (Fase 7)

### Contratos (agnósticos de proveedor)
`TextAIProviderInterface::generateText(TextGenerationRequest, array $credentials): TextGenerationResult`
y `ImageAIProviderInterface::generateImage(ImageGenerationRequest, array $credentials): ImageGenerationResult`.
DTOs de petición/resultado en `app/Modules/Ai/Contracts`.

### Proveedores
- **FakeAiProvider** (texto + imagen): funcional sin red, para dev/pruebas; simula
  fallo con el marcador `[[FAIL]]`.
- **OpenAiProvider** (skeleton real): usa `Http` contra la API de OpenAI; sin
  `api_key` lanza `AiProviderNotConfiguredException` (HTTP 503).
- `AiProviderManager` resuelve el proveedor por modalidad (habilitado + por defecto
  con adaptador disponible). Anthropic/Gemini quedan en el catálogo (sin adaptador aún).

### Brand Brain como contexto
`BrandContextBuilder` compone voz/tono, propuestas de valor, CTA, hashtags,
vocabulario preferido/prohibido, audiencias y oferta de la Brand. **Estrictamente
acotado a la Brand**: nunca mezcla contexto entre Organizations/Brands. Incluye
adaptación por red (`networkInstruction`).

### Créditos y límites
`AiCreditService` usa el entitlement `ai_credits.month` (docs/08) y acumula el
consumo en `usage_counters` por periodo `YYYY-MM`. Al superar el límite lanza
`PlanLimitExceededException` (HTTP 402). El coste por operación por defecto vive en
`AiOperation` y puede sobrescribirse por proveedor (`config.credit_costs`).

### BYOK
`organization_ai_keys` (cifrada at-rest, `encrypted:array`, oculta al frontend).
Sólo con `feature.byok`. Si hay clave propia activa, la generación la usa y **no
consume créditos** de plataforma. Gestión: permiso `ai.manage_own_keys`.

### Registro de uso
`ai_usage_logs` (tenant-owned): provider, model, modality, operation, units,
credits, cost_cents, latency_ms, status, byok. **No se guarda el prompt.**

### Endpoints
- `POST /api/v1/brands/{brand}/ai/text` — permiso `ai.generate_text`.
- `POST /api/v1/brands/{brand}/ai/image` — permiso `ai.generate_image`.
- `GET /api/v1/ai/usage` — permiso `ai.view_usage`.
- `GET|POST /api/v1/ai/keys`, `DELETE /api/v1/ai/keys/{provider}` — permiso `ai.manage_own_keys`.
- SUPERADMIN: `GET/PUT /api/v1/platform/ai-providers[/{provider}][/credentials]`.

### Frontend
Asistente IA en el detalle de contenido (generar borrador y adaptar variante por
red), vista **Asistente IA** (`/app/ai`: créditos, actividad, BYOK) y panel
SUPERADMIN de proveedores de IA.
