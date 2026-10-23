{{-- Correo del informe: HTML propio (sin markdown, todo escapado) para que nada de lo que escriben las personas se convierta en enlaces. --}}
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
                <tr><td style="background:{{ $color }};height:6px;font-size:0;line-height:0;">&nbsp;</td></tr>
                <tr>
                    <td style="padding:24px 28px 8px 28px;">
                        <p style="margin:0;font-size:13px;color:#64748b;">{{ $report['branding']['name'] }}</p>
                        <h1 style="margin:6px 0 4px 0;font-size:20px;color:#0f172a;">{{ $report['name'] }}</h1>
                        <p style="margin:0;font-size:14px;color:#475569;">{{ $report['brand']['name'] }} · {{ $period }}</p>
                    </td>
                </tr>
                @if (! empty($report['intro']))
                    <tr>
                        <td style="padding:12px 28px 0 28px;">
                            <div style="border-left:3px solid {{ $color }};background:#f8fafc;padding:10px 12px;font-size:14px;line-height:1.5;">{!! nl2br(e($report['intro'])) !!}</div>
                        </td>
                    </tr>
                @endif
                @if (! empty($report['summary']))
                    <tr>
                        <td style="padding:16px 28px 0 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    @foreach ([['Seguidores', $report['summary']['followers']], ['Alcance', $report['summary']['reach']['value']], ['Interacciones', $report['summary']['engagement']['value']], ['Publicaciones', $report['summary']['posts']['value']]] as [$label, $value])
                                        <td style="width:25%;padding:8px;border:1px solid #e2e8f0;">
                                            <div style="font-size:11px;text-transform:uppercase;color:#64748b;">{{ $label }}</div>
                                            <div style="font-size:18px;font-weight:bold;color:#0f172a;">{{ $f::number($value) }}</div>
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:18px 28px 24px 28px;font-size:14px;line-height:1.5;">
                        <p style="margin:0 0 10px 0;">El informe completo va adjunto en PDF.</p>
                        <p style="margin:0;color:#64748b;font-size:12px;">
                            Enviado por {{ $report['organization']['name'] }}@if (! $report['branding']['white_label']) con {{ $report['branding']['name'] }}@endif.
                            Si no esperabas este correo, puedes ignorarlo o responder para avisar.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
