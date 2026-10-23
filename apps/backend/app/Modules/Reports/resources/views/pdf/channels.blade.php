<div class="section">
    <h2>Resultados por red</h2>
    @if (empty($data))
        <p class="empty">No hay cuentas con métricas en este periodo.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Red</th>
                    <th>Cuenta</th>
                    <th class="num">Seguidores</th>
                    <th class="num">Crecimiento</th>
                    <th class="num">Publicaciones</th>
                    <th class="num">Alcance</th>
                    <th class="num">Interacciones</th>
                    <th class="num">Int. / alcance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data as $row)
                    <tr>
                        <td>{{ $row['provider_label'] }}</td>
                        <td>{{ $row['account'] }}</td>
                        <td class="num">{{ $f::number($row['followers']) }}</td>
                        <td class="num {{ $f::trend($row['followers_change']) }}">
                            {{ $f::signed($row['followers_change']) }}
                            @if ($row['followers_change_pct'] !== null)
                                <span class="small">({{ $f::percent($row['followers_change_pct'], true) }})</span>
                            @endif
                        </td>
                        <td class="num">{{ $f::number($row['posts']) }}</td>
                        <td class="num">{{ $f::number($row['reach']) }}</td>
                        <td class="num">{{ $f::number($row['engagement']) }}</td>
                        <td class="num">{{ $f::percent($row['engagement_rate'], false, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
