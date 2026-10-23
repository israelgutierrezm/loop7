{{-- Parte text/plain: sin HTML, así que no se escapan entidades. --}}
{!! $report['name'] !!}
{!! $report['brand']['name'] !!} · {!! $period !!}

@if (! empty($report['intro']))
{!! $report['intro'] !!}

@endif
@if (! empty($report['summary']))
Seguidores: {{ $f::number($report['summary']['followers']) }}
Alcance: {{ $f::number($report['summary']['reach']['value']) }}
Interacciones: {{ $f::number($report['summary']['engagement']['value']) }}
Publicaciones: {{ $f::number($report['summary']['posts']['value']) }}

@endif
El informe completo va adjunto en PDF.

Enviado por {!! $report['organization']['name'] !!}@if (! $report['branding']['white_label']) con {!! $report['branding']['name'] !!}@endif.
Si no esperabas este correo, puedes ignorarlo o responder para avisar.
