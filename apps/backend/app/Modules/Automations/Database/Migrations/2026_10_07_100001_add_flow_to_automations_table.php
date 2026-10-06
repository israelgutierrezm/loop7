<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editor visual de automatizaciones (docs/05): las condiciones en Y y la lista
 * de acciones pasan a un flujo de pasos (acción, esperar, condición Sí/No).
 * Cada regla existente se convierte en su equivalente: una condición con las
 * acciones en «Sí» (o sólo las acciones si no tenía condiciones).
 *
 * Las ejecuciones guardan la traza por paso y, si están en una espera, cuándo
 * y desde qué paso se reanudan.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table): void {
            $table->json('flow')->nullable()->after('trigger_config');
        });

        DB::table('automations')->select(['id', 'conditions', 'actions'])->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('automations')->where('id', $row->id)->update([
                        'flow' => json_encode(['steps' => $this->toSteps(
                            $this->decode($row->conditions),
                            $this->decode($row->actions),
                        )], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            });

        Schema::table('automations', function (Blueprint $table): void {
            $table->dropColumn(['conditions', 'actions']);
        });

        Schema::table('automation_runs', function (Blueprint $table): void {
            // Marca del evento: se necesita para reanudar tras una espera.
            $table->foreignId('brand_id')->nullable()->after('automation_id')->constrained('brands')->nullOnDelete();
            $table->json('steps')->nullable()->after('context');
            $table->timestamp('resume_at')->nullable()->after('steps');
            $table->string('resume_step', 40)->nullable()->after('resume_at');

            $table->index(['status', 'resume_at'], 'automation_runs_waiting_idx');
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->dropIndex('automation_runs_waiting_idx');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['steps', 'resume_at', 'resume_step']);
        });

        Schema::table('automations', function (Blueprint $table): void {
            $table->json('conditions')->nullable()->after('trigger_config');
            $table->json('actions')->nullable()->after('conditions');
        });

        // Aproximación: condición inicial → condiciones; acciones de su «Sí» (o de todo el flujo).
        DB::table('automations')->select(['id', 'flow'])->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $steps = $this->decode($row->flow)['steps'] ?? [];
                    $first = $steps[0] ?? null;
                    $conditions = [];
                    if (count($steps) === 1 && ($first['type'] ?? null) === 'branch' && ($first['no'] ?? []) === []) {
                        $conditions = $first['conditions'] ?? [];
                        $steps = $first['yes'] ?? [];
                    }
                    DB::table('automations')->where('id', $row->id)->update([
                        'conditions' => json_encode($conditions, JSON_UNESCAPED_UNICODE),
                        'actions' => json_encode($this->legacyActions($steps), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            });

        Schema::table('automations', function (Blueprint $table): void {
            $table->dropColumn('flow');
        });
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<int|string, mixed>  $conditions
     * @param  array<int|string, mixed>  $actions
     * @return list<array<string, mixed>>
     */
    private function toSteps(array $conditions, array $actions): array
    {
        $steps = [];
        foreach (array_values($actions) as $i => $action) {
            $steps[] = [
                'id' => 'a' . ($i + 1),
                'type' => 'action',
                'action' => (string) ($action['type'] ?? ''),
                'config' => is_array($action['config'] ?? null) ? $action['config'] : [],
            ];
        }

        if ($conditions === []) {
            return $steps;
        }

        return [[
            'id' => 'b1',
            'type' => 'branch',
            'match' => 'all',
            'conditions' => array_values($conditions),
            'yes' => $steps,
            'no' => [],
        ]];
    }

    /**
     * @param  array<int|string, mixed>  $steps
     * @return list<array{type: string, config: array<string, mixed>}>
     */
    private function legacyActions(array $steps): array
    {
        $actions = [];
        foreach ($steps as $step) {
            if (($step['type'] ?? null) === 'action') {
                $actions[] = ['type' => (string) ($step['action'] ?? ''), 'config' => $step['config'] ?? []];
            } elseif (($step['type'] ?? null) === 'branch') {
                array_push($actions, ...$this->legacyActions($step['yes'] ?? []), ...$this->legacyActions($step['no'] ?? []));
            }
        }

        return $actions;
    }
};
