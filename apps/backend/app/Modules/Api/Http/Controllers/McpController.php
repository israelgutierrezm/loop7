<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\AnalyticsQueryService;
use App\Modules\Api\Models\ApiKey;
use App\Modules\Api\Support\ApiScope;
use App\Modules\Brands\Models\Brand;
use App\Modules\Content\Enums\ContentType;
use App\Modules\Content\Models\ContentItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Servidor MCP mínimo (JSON-RPC 2.0) sobre la misma API pública. Autenticado por
 * API key; expone herramientas acotadas por los scopes de la key (docs/11).
 */
class McpController extends Controller
{
    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $method = (string) $request->input('method');

        return match ($method) {
            'initialize' => $this->rpc($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'loop7-mcp', 'version' => '1.0.0'],
            ]),
            'ping' => $this->rpc($id, (object) []),
            'tools/list' => $this->rpc($id, ['tools' => $this->availableTools($request)]),
            'tools/call' => $this->callTool($request, $id),
            default => $this->rpcError($id, -32601, 'Método no soportado: ' . $method),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableTools(Request $request): array
    {
        $key = $this->key($request);

        return array_values(array_map(
            fn (array $t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema']],
            array_filter($this->tools(), fn (array $t) => $key->hasScope($t['scope'])),
        ));
    }

    private function callTool(Request $request, mixed $id): JsonResponse
    {
        $name = (string) $request->input('params.name');
        $args = (array) $request->input('params.arguments', []);
        $tools = collect($this->tools())->keyBy('name');

        $tool = $tools->get($name);
        if ($tool === null) {
            return $this->rpcError($id, -32602, 'Herramienta desconocida: ' . $name);
        }
        if (! $this->key($request)->hasScope($tool['scope'])) {
            return $this->rpcError($id, -32001, 'La API key no tiene el scope: ' . $tool['scope']);
        }

        try {
            $data = $this->execute($name, $args);
        } catch (Throwable $e) {
            return $this->rpc($id, [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
            ]);
        }

        return $this->rpc($id, [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function execute(string $name, array $args): array
    {
        return match ($name) {
            'list_brands' => Brand::query()->orderBy('name')->get()
                ->map(fn (Brand $b) => ['id' => $b->public_id, 'name' => $b->name])->all(),
            'list_content' => ContentItem::query()
                ->where('brand_id', $this->brand($args)->id)->latest()->limit(50)->get()
                ->map(fn (ContentItem $c) => [
                    'id' => $c->public_id, 'title' => $c->title, 'status' => $c->status->value,
                ])->all(),
            'create_content' => $this->createContent($args),
            'get_analytics' => $this->analytics->overview(
                $this->brand($args),
                Carbon::today()->subDays(29),
                Carbon::today(),
            ),
            default => throw new \RuntimeException('Herramienta no implementada.'),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function createContent(array $args): array
    {
        $brand = $this->brand($args);
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('El título es obligatorio.');
        }

        $content = ContentItem::query()->create([
            'brand_id' => $brand->id,
            'title' => $title,
            'body' => isset($args['body']) ? (string) $args['body'] : null,
            'type' => ContentType::POST->value,
            'status' => 'draft',
        ]);

        return ['id' => $content->public_id, 'title' => $content->title, 'status' => $content->status->value];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function brand(array $args): Brand
    {
        return Brand::query()->where('public_id', (string) ($args['brand'] ?? ''))->firstOrFail();
    }

    private function key(Request $request): ApiKey
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        return $key;
    }

    /**
     * Catálogo interno de herramientas con su scope requerido.
     *
     * @return list<array{name: string, description: string, scope: string, inputSchema: array<string, mixed>}>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'list_brands',
                'description' => 'Lista las marcas de la organización.',
                'scope' => ApiScope::BRANDS_READ,
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'list_content',
                'description' => 'Lista el contenido reciente de una marca.',
                'scope' => ApiScope::CONTENT_READ,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['brand' => ['type' => 'string', 'description' => 'ID público de la marca']],
                    'required' => ['brand'],
                ],
            ],
            [
                'name' => 'create_content',
                'description' => 'Crea un borrador de contenido en una marca.',
                'scope' => ApiScope::CONTENT_WRITE,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'brand' => ['type' => 'string', 'description' => 'ID público de la marca'],
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                    ],
                    'required' => ['brand', 'title'],
                ],
            ],
            [
                'name' => 'get_analytics',
                'description' => 'Devuelve el resumen de analítica de una marca (últimos 30 días).',
                'scope' => ApiScope::ANALYTICS_READ,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['brand' => ['type' => 'string', 'description' => 'ID público de la marca']],
                    'required' => ['brand'],
                ],
            ],
        ];
    }

    private function rpc(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function rpcError(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
