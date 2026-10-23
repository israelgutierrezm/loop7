{{-- Informe para clientes (docs/05). Se pinta con dompdf: CSS 2.1, tablas y nada remoto. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $report['name'] }} · {{ $report['brand']['name'] }}</title>
<style>
    @page { margin: 24mm 16mm 20mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1e293b; line-height: 1.4; }
    .header { position: fixed; top: -17mm; left: 0; right: 0; height: 12mm; border-bottom: 1px solid #e2e8f0; }
    .header td { vertical-align: middle; }
    .header .product { font-size: 10pt; font-weight: bold; color: {{ $color }}; }
    .header .meta { text-align: right; font-size: 8pt; color: #64748b; }
    .footer { position: fixed; bottom: -13mm; left: 0; right: 0; height: 8mm; font-size: 7.5pt; color: #94a3b8; }
    .footer .page { text-align: right; }
    .pagenum:before { content: counter(page); }
    .logo { max-height: 10mm; max-width: 45mm; }
    h1 { font-size: 20pt; margin: 0 0 2px 0; color: #0f172a; }
    h2 { font-size: 12.5pt; color: #0f172a; margin: 18px 0 8px 0; padding-bottom: 4px; border-bottom: 2px solid {{ $color }}; }
    .subtitle { color: #64748b; font-size: 10pt; margin: 0 0 12px 0; }
    .intro { background: #f8fafc; border-left: 3px solid {{ $color }}; padding: 8px 10px; margin: 10px 0 4px 0; }
    .muted { color: #64748b; }
    .small { font-size: 8pt; }
    table { border-collapse: collapse; width: 100%; }
    .kpis td { width: 25%; padding: 8px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
    .kpi-label { font-size: 7.5pt; text-transform: uppercase; color: #64748b; letter-spacing: 0.3px; }
    .kpi-value { font-size: 15pt; font-weight: bold; color: #0f172a; margin-top: 2px; }
    .kpi-change { font-size: 8pt; margin-top: 2px; }
    .data { font-size: 8.5pt; }
    .data th { background: #f1f5f9; color: #475569; font-size: 7pt; text-transform: uppercase; text-align: left; padding: 4px 5px; vertical-align: bottom; }
    .data td { padding: 4px 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .num { text-align: right; }
    td.num, .nowrap { white-space: nowrap; }
    .up { color: #047857; }
    .down { color: #b91c1c; }
    .flat { color: #64748b; }
    .section { page-break-inside: avoid; }
    .bar-track { background: #f1f5f9; height: 9px; width: 100%; }
    .bar { background: {{ $color }}; height: 9px; }
    .own { font-weight: bold; }
    .chart-labels td { font-size: 7.5pt; color: #64748b; }
    .empty { color: #64748b; font-style: italic; padding: 6px 0; }
    a { color: {{ $color }}; text-decoration: none; }
</style>
</head>
<body>

<div class="header">
    <table>
        <tr>
            <td>
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="{{ $report['branding']['name'] }}">
                @else
                    <span class="product">{{ $report['branding']['name'] }}</span>
                @endif
            </td>
            <td class="meta">{{ $report['brand']['name'] }} · {{ $f::period($report['period']['from'], $report['period']['to']) }}</td>
        </tr>
    </table>
</div>

<div class="footer">
    <table>
        <tr>
            <td>{{ $report['branding']['name'] }} · Generado el {{ $f::date($report['generated_at'], 'j \d\e F \d\e Y', $report['brand']['timezone']) }}</td>
            <td class="page">Página <span class="pagenum"></span></td>
        </tr>
    </table>
</div>

<h1>{{ $report['name'] }}</h1>
<p class="subtitle">{{ $report['brand']['name'] }} · {{ $f::period($report['period']['from'], $report['period']['to']) }}</p>

@if (! empty($report['intro']))
    <div class="intro">{!! nl2br(e($report['intro'])) !!}</div>
@endif

@forelse ($report['sections'] as $section)
    @include('reports::pdf.' . $section, ['data' => $report[$section] ?? null])
@empty
    <p class="empty">Este informe no tiene secciones disponibles con el plan actual.</p>
@endforelse

</body>
</html>
