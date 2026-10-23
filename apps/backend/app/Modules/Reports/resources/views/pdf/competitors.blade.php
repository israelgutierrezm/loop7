<div class="section">
    <h2>Comparación con la competencia</h2>
    @if (! $data['has_competitors'])
        <p class="empty">No hay competidores configurados para esta marca.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Cuenta</th>
                    <th>Red</th>
                    <th class="num">Seguidores</th>
                    <th class="num">Crecimiento</th>
                    <th class="num">Publicaciones</th>
                    <th class="num">Interacción media</th>
                    <th class="num">Tasa</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data['rows'] as $row)
                    <tr class="{{ $row['kind'] === 'own' ? 'own' : '' }}">
                        <td>{{ $row['kind'] === 'own' ? $row['name'] . ' (tú)' : $row['name'] }}<br><span class="small muted">{{ $row['account'] }}</span></td>
                        <td>{{ $row['provider_label'] }}</td>
                        <td class="num">{{ $f::number($row['followers']) }}</td>
                        <td class="num {{ $f::trend($row['followers_change_pct']) }}">{{ $f::percent($row['followers_change_pct'], true, 2) }}</td>
                        <td class="num">{{ $f::number($row['posts']) }}</td>
                        <td class="num">{{ $f::number($row['avg_engagement'], 1) }}</td>
                        <td class="num">{{ $f::percent($row['engagement_rate'], false, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="small muted">Datos públicos de las APIs oficiales; interacción media = «me gusta» + comentarios por publicación.</p>
    @endif
</div>
