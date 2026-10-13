@php
    /*
     * Calendario mensual de asistencia (PDF apaisado). Recibe $c = CalendarioAsistenciaService::armar() y $papel = a4|a3.
     * Es el mismo arreglo que consume la pantalla: aqui solo se dibuja. CSS 2.1 (dompdf): sin flex ni grid.
     */
    $a3 = $papel === 'a3';
    $ancho = $a3 ? 404 : 281;                       // mm utiles de la hoja apaisada (sin margenes)
    $mn = $a3 ? 8 : 6;
    $mdni = $a3 ? 20 : 16;
    $mpersonal = $a3 ? 58 : 44;
    $dias = $c['periodo']['dias'];
    $mdia = round(($ancho - $mn - $mdni - $mpersonal) / max(count($dias), 1), 2);
    $fs = $a3 ? 9 : 6.5;                            // pt del cuerpo
    $fuerte = $a3 ? 10.5 : 7.5;
    $fondo = [
        'tardanza' => '#fde9b8', 'salida' => '#fdd8b5', 'falta' => '#f6c4c4', 'justificada' => '#cfe4f6', 'cobertura' => '#e2d8f3', 'descanso' => '#e4e6ea',
        'normal' => '#cdeccf', 'programado' => '#ffffff',
    ];
    $rojo = '#c0392b';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asistencia - {{ $c['eess']['nombre'] }} - {{ $c['periodo']['etiqueta'] }}</title>
<style>
    @page { margin: 8mm 8mm 12mm 8mm; }
    body { font-family: Helvetica, sans-serif; font-size: {{ $fs }}pt; color: #111; margin: 0; }
    .cabecera { text-align: center; margin-bottom: 3mm; }
    .entidad { font-size: {{ $fs + 1 }}pt; color: #444; margin: 0; }
    .titulo { font-size: {{ $fuerte + 5 }}pt; font-weight: bold; margin: 1mm 0 0 0; }
    .eess { font-size: {{ $fuerte + 3 }}pt; font-weight: bold; margin: 1mm 0 0 0; }
    .periodo { font-size: {{ $fuerte + 1 }}pt; margin: 1mm 0 0 0; }
    table.cal { width: {{ $ancho }}mm; border-collapse: collapse; }
    table.cal thead { display: table-header-group; }
    table.cal tr { page-break-inside: avoid; }
    table.cal th, table.cal td { border: 0.4pt solid #8a929c; padding: 0.6mm 0.4mm; text-align: center; vertical-align: middle; overflow: hidden; }
    table.cal th { background: #e9edf2; font-size: {{ $fs }}pt; }
    table.cal td.izq { text-align: left; padding-left: 1mm; }
    .nombre { font-weight: bold; text-transform: uppercase; font-size: {{ $fs }}pt; }
    .sub { font-size: {{ $fs - 0.5 }}pt; color: #333; }
    .l1 { font-weight: bold; font-size: {{ $fs + 0.5 }}pt; }
    .l2 { font-size: {{ $fs - 0.5 }}pt; }
    .corto { font-size: {{ $fs - 1.8 }}pt; }
    .programado .l1 { color: #777; font-weight: normal; }
    .do { border-left: 0.8pt solid {{ $rojo }} !important; border-right: 0.8pt solid {{ $rojo }} !important; }
    .sa { background: #f4f6f8; }
    .nl { background: #fbeaea; }
    h3 { font-size: {{ $fs + 1 }}pt; margin: 4mm 0 1mm 0; }
    table.ley { border-collapse: collapse; }
    table.ley td { border: 0.4pt solid #8a929c; padding: 0.6mm 1.6mm; font-size: {{ $fs }}pt; }
    .chip { width: 5mm; }
    .vacio { color: #bbb; }
    .nota { margin: 1.5mm 0; font-size: {{ $fs }}pt; color: #333; }
    .sin { text-align: center; margin: 10mm 0; font-size: {{ $fuerte }}pt; color: #555; }
</style>
</head>
<body>
    <div class="cabecera">
        <p class="entidad">{{ $c['entidad'] }} · {{ $c['eess']['microred'] }}</p>
        <p class="titulo">ASISTENCIA</p>
        <p class="eess">{{ $c['eess']['nombre'] }}</p>
        <p class="periodo">{{ $c['periodo']['etiqueta'] }}</p>
    </div>

    <table class="cal">
        <thead>
            <tr>
                <th style="width: {{ $mn - 1.0 }}mm">N°</th>
                <th style="width: {{ $mdni - 1.0 }}mm">DNI</th>
                <th style="width: {{ $mpersonal - 1.6 }}mm">PERSONAL</th>
                @foreach ($dias as $d)
                    <th style="width: {{ $mdia - 1.0 }}mm" class="{{ $d['es_domingo'] ? 'do' : ($d['es_sabado'] ? 'sa' : '') }} {{ $d['no_laborable'] ? 'nl' : '' }}">
                        <b>{{ str_pad((string) $d['dia'], 2, '0', STR_PAD_LEFT) }}</b><br>{{ $d['dia_semana'] }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($c['trabajadores'] as $t)
                <tr>
                    <td>{{ $t['n'] }}</td>
                    <td>{{ $t['dni'] }}</td>
                    <td class="izq">
                        <span class="nombre">{{ $t['nombre'] }}</span><br>
                        <span class="sub">{{ $t['cargo'] }} · {{ $t['condicion'] }}</span>
                    </td>
                    @foreach ($dias as $d)
                        @php($x = $t['celdas'][$d['fecha']])
                        <td class="{{ $d['es_domingo'] ? 'do' : ($d['es_sabado'] ? 'sa' : '') }} {{ ! empty($x['vacia']) && $d['no_laborable'] ? 'nl' : '' }} {{ ($x['categoria'] ?? '') === 'programado' ? 'programado' : '' }}"
                            @if (empty($x['vacia']) && ($fondo[$x['categoria']] ?? '#ffffff') !== '#ffffff') style="background: {{ $fondo[$x['categoria']] }}" @endif>
                            @if (empty($x['vacia']))
                                <span class="l1 {{ strlen($x['linea1']) > 3 ? 'corto' : '' }}">{{ $x['linea1'] }}</span><br><span class="l2">{{ $x['linea2'] !== '' ? $x['linea2'] : ' ' }}</span>
                            @else
                                <span class="vacio">&middot;</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (count($c['trabajadores']) === 0)
        <p class="sin">No hay trabajadores con vínculo en este establecimiento durante el mes.</p>
    @endif

    <h3>Leyenda</h3>
    <table class="ley">
        <tr>
            @foreach ($c['leyenda']['categorias'] as $cat)
                <td class="chip" style="background: {{ $fondo[$cat['clave']] ?? '#ffffff' }}">&nbsp;</td>
                <td>{{ $cat['nombre'] }}</td>
            @endforeach
        </tr>
    </table>
    <p class="nota">Cada celda muestra el turno del día (primera línea) y, debajo, los minutos de descuento por tardanza o la abreviatura del estado. Sin turno, la primera línea es la abreviatura del estado.</p>
    <table class="ley">
        @foreach (array_chunk($c['leyenda']['estados'], 4) as $grupo)
            <tr>
                @foreach ($grupo as $e)
                    <td class="chip" style="background: {{ $fondo[$e['categoria']] ?? '#ffffff' }}"><b>{{ $e['abreviatura'] }}</b></td>
                    <td>{{ $e['nombre'] }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
    @if (count($c['leyenda']['turnos']) > 0)
        <p class="nota"><b>Turnos:</b>
            @foreach ($c['leyenda']['turnos'] as $turno)
                <b>{{ $turno['codigo'] }}</b> = {{ $turno['nombre'] }} ({{ $turno['entrada'] }}–{{ $turno['salida'] }}){{ $loop->last ? '' : ' · ' }}
            @endforeach
        </p>
    @endif
</body>
</html>
