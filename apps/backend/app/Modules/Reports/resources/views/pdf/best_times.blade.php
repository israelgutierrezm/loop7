<div class="section">
    <h2>Mejores horarios para publicar</h2>
    @if (! $data['sufficient'] || empty($data['top']))
        <p class="empty">
            Aún no hay suficientes publicaciones medidas para recomendar horarios
            ({{ $f::number($data['sample']) }} de {{ $f::number($data['min_posts']) }} necesarias).
        </p>
    @else
        <table class="data">
            <thead><tr><th>Día</th><th>Hora</th><th class="num">Interacción sobre lo habitual</th></tr></thead>
            <tbody>
                @foreach ($data['top'] as $slot)
                    <tr>
                        <td>{{ $slot['label'] }}</td>
                        <td>{{ $f::hour((int) $slot['hour']) }}</td>
                        <td class="num up">{{ $f::percent($slot['lift'], true, 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="small muted">Según las publicaciones de los últimos 90 días, en la zona horaria {{ $data['timezone'] }}.</p>
    @endif
</div>
