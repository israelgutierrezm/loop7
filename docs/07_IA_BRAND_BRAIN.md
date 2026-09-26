# IA y Brand Brain

## Arquitectura agnóstica
Contratos separados para texto, imagen, video, embeddings y transcripción.

## Proveedores
- OpenAI (texto e imagen)
- Anthropic (texto)
- Otros se añaden implementando los contratos (un proveedor sin adaptador no se ofrece)

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

### Implementación (módulo `Knowledge`)
- **Documentos**: PDF (con texto seleccionable), Word `.docx`, TXT, Markdown y CSV de
  hasta 10 MB por marca (Brand Brain → Conocimiento → Documentos). El tipo real (finfo)
  debe corresponder a la extensión. Archivo privado en el disco por defecto
  (`knowledge/{org}/{marca}/{ulid}.{ext}`), descarga autenticada. Límite por plan
  `knowledge_documents.max` (docs/08). Subir/reindexar/eliminar exige `brands.update`;
  ver y probar, acceso a la marca. Auditado (`knowledge.document_uploaded|deleted`).
- **Indexado** (`IndexKnowledgeDocument`, cola `default`): `DocumentTextExtractor`
  (PDF con `smalot/pdfparser` sin imágenes y con límite de memoria; `.docx` leyendo
  `word/document.xml` con tope contra zip bombs; texto con conversión desde Latin‑1) →
  `TextChunker` (~1000 caracteres por párrafos y frases, solapamiento de 150) →
  vectores por lotes de 64 → `knowledge_chunks`. Topes: 400 fragmentos por documento y
  3.000 por marca. Un documento ilegible queda `failed` con el motivo; si el proveedor de
  embeddings no responde, el último intento indexa sin vectores. Estados: En cola,
  Procesando, Listo, Error (la ficha se refresca sola mientras indexa).
- **Embeddings** (`EmbeddingProviderInterface`): OpenAI `text-embedding-3-small` a 512
  dimensiones (modelo configurable en `config.embedding_model` del proveedor) y el de
  prueba (hashing determinista, sin red). `EmbeddingService::spaceFor()` elige: la clave
  propia (BYOK) si el plan lo permite, un proveedor real de plataforma configurado o el
  de prueba. Cada vector guarda su espacio (`proveedor:modelo:dimensiones`): vectores de
  espacios distintos nunca se comparan (tras cambiar de proveedor, «Reindexar»). El
  indexado registra el uso (`index_document`, sin coste en créditos).
- **Búsqueda híbrida** (`KnowledgeRetriever`, sólo la marca pedida): similitud del coseno
  si fragmentos y consulta comparten espacio; si no (sin proveedor, o documentos de otro
  espacio), palabras clave normalizadas (sin acentos ni palabras vacías, raíz de 6
  letras). Probador en la ficha: `GET /brands/{marca}/documents/search?q=`.
- **Generación**: `BrandContextBuilder::build($brand, $prompt)` añade hasta 4 fragmentos
  relevantes (900 caracteres cada uno) indicando que son datos de referencia y que **no
  se siguen instrucciones que contengan** (mitiga la inyección de prompts desde
  documentos). La IA depende del contrato `KnowledgeSource`, no del módulo.
- Eliminar un documento o su marca borra de verdad el archivo y sus fragmentos.

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
  fallo con el marcador `[[FAIL]]` y genera un PNG de muestra.
- **OpenAiProvider** (texto + imagen): Chat Completions con `max_completion_tokens`;
  los modelos de razonamiento (`o*`, `gpt-5*`) se llaman sin `temperature`. Imágenes con
  `gpt-image-1` (tamaños mapeados a los que admite el modelo).
- **AnthropicProvider** (texto): Messages API. Modelo por defecto `claude-opus-5`
  (catálogo semilla: `claude-opus-5`, `claude-sonnet-5`, `claude-haiku-4-5`); sin
  `temperature` (los modelos actuales la rechazan), `max_tokens` mínimo 16000, respaldo
  del lado del servidor en los modelos que lo admiten, rechazos (`stop_reason: refusal`)
  convertidos en un error claro y sólo se concatenan los bloques de texto.
- `AiProviderManager` resuelve el proveedor por modalidad (habilitado + por defecto
  con adaptador disponible). Sin `api_key` se lanza `AiProviderNotConfiguredException`.

### Probar conexión y modelos (SUPERADMIN)
Cada proveedor de texto expone `verify(credentials)` (OpenAI/Anthropic hacen un
`GET /models`; fake es no-op). Endpoint `POST /platform/ai-providers/{provider}/test`
verifica las credenciales guardadas y devuelve `{ ok, message }`.
`POST /platform/ai-providers/{provider}/models` consulta los modelos disponibles para
esas credenciales (`listModels`) y actualiza las listas de texto/imagen del panel. En el
panel: habilitar/deshabilitar, marcar por defecto, elegir modelo, probar conexión y
refrescar modelos.

### Imágenes generadas
Las imágenes de IA se guardan en la biblioteca de medios de la marca (respetando el
límite de almacenamiento del plan) y se pueden adjuntar a cualquier variante.

### Brand Brain como contexto
`BrandContextBuilder` compone descripción y sitio web, voz/tono, propuestas de valor,
CTA, hashtags, vocabulario preferido/prohibido, **notas del equipo**, audiencias (con su
descripción), oferta (productos con precio, descripción y enlace; servicios) y la **base
de conocimiento** (FAQs como pregunta/respuesta, notas y enlaces de referencia, que la IA
usa como datos verificados). Cada sección tiene tope (25 elementos, 30 de conocimiento,
textos recortados) para que el prompt no crezca sin límite. **Estrictamente acotado a la
Brand**: nunca mezcla contexto entre Organizations/Brands. Incluye adaptación por red
(`networkInstruction`).

En la ficha de la marca (Brand Brain) cada audiencia, producto, servicio y elemento de
conocimiento se crea, **edita en línea** y quita con confirmación
(`POST|PATCH|DELETE /brands/{brand}/{audiences|products|services|knowledge}`).

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
