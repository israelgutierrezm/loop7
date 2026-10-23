<div class="section">
    <h2>Resumen del periodo</h2>
    @php
        $previous = $f::period($report['period']['previous_from'], $report['period']['previous_to']);
        $kpis = [
            ['label' => 'Seguidores', 'value' => $data['followers'], 'change' => $data['followers_change_pct'], 'note' => $data['followers_change'] !== null ? $f::signed($data['followers_change']) . ' en el periodo' : 'Sin mediciones suficientes'],
            ['label' => 'Alcance', 'value' => $data['reach']['value'], 'change' => $data['reach']['change_pct'], 'note' => null],
            ['label' => 'Impresiones', 'value' => $data['impressions']['value'], 'change' => $data['impressions']['change_pct'], 'note' => null],
            ['label' => 'Interacciones', 'value' => $data['engagement']['value'], 'change' => $data['engagement']['change_pct'], 'note' => null],
        ];
    @endphp
    <table class="kpis">
        <tr>
            @foreach ($kpis as $kpi)
                <td>
                    <div class="kpi-label">{{ $kpi['label'] }}</div>
                    <div class="kpi-value">{{ $f::number($kpi['value']) }}</div>
                    <div class="kpi-change {{ $f::trend($kpi['change']) }}">
                        @if ($kpi['note'])
                            {{ $kpi['note'] }}
                        @elseif ($kpi['change'] !== null)
                            {{ $f::percent($kpi['change'], true) }} vs. periodo anterior
                        @else
                            <span class="muted">Sin periodo anterior comparable</span>
                        @endif
                    </div>
                </td>
            @endforeach
        </tr>
    </table>
    <table class="kpis" style="margin-top: 6px;">
        <tr>
            <td>
                <div class="kpi-label">Publicaciones</div>
                <div class="kpi-value">{{ $f::number($data['posts']['value']) }}</div>
                <div class="kpi-change {{ $f::trend($data['posts']['change_pct']) }}">
                    @if ($data['posts']['change_pct'] !== null)
                        {{ $f::percent($data['posts']['change_pct'], true) }} vs. periodo anterior
                    @else
                        <span class="muted">{{ $f::number($data['posts']['previous']) }} en el periodo anterior</span>
                    @endif
                </div>
            </td>
            <td>
                <div class="kpi-label">Interacción / alcance</div>
                <div class="kpi-value">{{ $f::percent($data['engagement_rate'], false, 2) }}</div>
                <div class="kpi-change muted">Interacciones por cada 100 personas alcanzadas</div>
            </td>
            <td colspan="2" class="small muted">
                Periodo anterior para comparar: {{ $previous }}.<br>
                Las cifras salen de las APIs oficiales de cada red; algunas redes no ofrecen todas las métricas.
            </td>
        </tr>
    </table>
</div>
