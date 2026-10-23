<div class="section">
    <h2>Evolución de seguidores</h2>
    @if ($growthChart === null)
        <p class="empty">No hay mediciones suficientes para dibujar la evolución en este periodo.</p>
    @else
        @php
            $values = array_values(array_filter($data['followers'], fn ($v) => $v !== null));
            $first = $values[0] ?? null;
            $last = $values[count($values) - 1] ?? null;
        @endphp
        <img src="{{ $growthChart['uri'] }}" style="width: 100%; height: 45mm;" alt="Evolución de seguidores">
        <table class="chart-labels">
            <tr>
                <td>{{ $f::date($data['dates'][0] ?? null, 'j M') }}</td>
                <td class="num">{{ $f::date($data['dates'][count($data['dates']) - 1] ?? null, 'j M') }}</td>
            </tr>
        </table>
        <p class="small muted">
            De {{ $f::number($first) }} a {{ $f::number($last) }} seguidores
            @if ($first !== null && $last !== null)
                (<span class="{{ $f::trend($last - $first) }}">{{ $f::signed($last - $first) }}</span>)
            @endif
            sumando todas las cuentas de la marca · mínimo {{ $f::number($growthChart['min']) }}, máximo {{ $f::number($growthChart['max']) }}.
        </p>
    @endif
</div>
