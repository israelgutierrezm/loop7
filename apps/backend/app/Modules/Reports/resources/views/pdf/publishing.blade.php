<div class="section">
    <h2>Actividad de publicación</h2>
    <p>
        <strong>{{ $f::number($data['published']) }}</strong> publicaciones en el periodo
        @if ($data['failed'] > 0)
            · <span class="down">{{ $f::number($data['failed']) }} no se pudieron publicar</span>
        @endif
    </p>
    @if ($data['published'] > 0)
        @php
            $maxDay = max(array_column($data['by_weekday'], 'posts')) ?: 1;
            $maxProvider = max(array_column($data['by_provider'], 'posts') ?: [1]) ?: 1;
        @endphp
        <table>
            <tr>
                <td style="width: 48%; vertical-align: top; padding-right: 4%;">
                    <table class="data">
                        <thead><tr><th>Red</th><th class="num">Publicaciones</th><th style="width: 40%;"></th></tr></thead>
                        <tbody>
                            @foreach ($data['by_provider'] as $row)
                                <tr>
                                    <td>{{ $row['provider_label'] }}</td>
                                    <td class="num">{{ $f::number($row['posts']) }}</td>
                                    <td><div class="bar-track"><div class="bar" style="width: {{ round($row['posts'] / $maxProvider * 100) }}%;"></div></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
                <td style="width: 48%; vertical-align: top;">
                    <table class="data">
                        <thead><tr><th>Día</th><th class="num">Publicaciones</th><th style="width: 40%;"></th></tr></thead>
                        <tbody>
                            @foreach ($data['by_weekday'] as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td class="num">{{ $f::number($row['posts']) }}</td>
                                    <td><div class="bar-track"><div class="bar" style="width: {{ round($row['posts'] / $maxDay * 100) }}%;"></div></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>
    @endif
</div>
