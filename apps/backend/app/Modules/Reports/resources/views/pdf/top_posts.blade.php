<div class="section">
    <h2>Publicaciones destacadas</h2>
    @if (empty($data))
        <p class="empty">No hay publicaciones medidas en este periodo.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Publicación</th>
                    <th>Red</th>
                    <th>Fecha</th>
                    <th class="num">Me gusta</th>
                    <th class="num">Coment.</th>
                    <th class="num">Compart.</th>
                    <th class="num">Alcance</th>
                    <th class="num">Interacciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($data as $post)
                    <tr>
                        <td>
                            @if ($f::safeUrl($post['url']))
                                <a href="{{ $f::safeUrl($post['url']) }}">{{ \Illuminate\Support\Str::limit($post['title'], 70) }}</a>
                            @else
                                {{ \Illuminate\Support\Str::limit($post['title'], 70) }}
                            @endif
                            @if ($post['account'])
                                <br><span class="small muted">{{ $post['account'] }}</span>
                            @endif
                        </td>
                        <td>{{ $post['provider_label'] }}</td>
                        <td class="nowrap">{{ $f::date($post['published_at'], 'j M') }}</td>
                        <td class="num">{{ $f::number($post['likes']) }}</td>
                        <td class="num">{{ $f::number($post['comments']) }}</td>
                        <td class="num">{{ $f::number($post['shares']) }}</td>
                        <td class="num">{{ $f::number($post['reach']) }}</td>
                        <td class="num"><strong>{{ $f::number($post['engagement']) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="small muted">Publicadas en el periodo y ordenadas por interacciones (última medición disponible).</p>
    @endif
</div>
