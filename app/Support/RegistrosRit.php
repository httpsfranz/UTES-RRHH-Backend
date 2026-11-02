<?php

namespace App\Support;

use App\Models\Asistencia\AutorizacionIngreso;
use App\Models\Asistencia\AvisoAusencia;
use App\Models\Asistencia\FacilidadHoraria;
use App\Models\Asistencia\ToleranciaExtraordinaria;
use App\Models\Biometria\ContingenciaMarcacion;
use App\Models\Disciplina\ReconocimientoServidor;
use App\Models\Organizacion\EstablecimientoSalud;
use App\Models\Personal\DesplazamientoTemporal;
use App\Models\Personal\EntregaCargo;
use App\Models\Personal\Fotocheck;
use App\Models\Personal\LegajoDocumento;
use App\Models\Personal\Trabajador;
use App\Models\Personal\VinculoLaboral;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Registros sencillos que exige el RIT: una tabla, un formulario y sus reglas, sin flujo de aprobacion. Comparten
 * RegistroRitController; cada uno se describe aqui. Para agregar otro: su fila en RECURSOS, sus reglas y su permiso en
 * App\Support\PermisosDeRuta.
 */
class RegistrosRit
{
    /** recurso de la API => [modelo, prefijo de columnas, columna de orden, columna de estado, valor al anular] */
    public const RECURSOS = [
        'fotochecks' => [Fotocheck::class, 'Fotocheck', 'FotocheckFechaEntrega', 'FotocheckEstado', 'ANULADO'],
        'legajo-documentos' => [LegajoDocumento::class, 'LegajoDocumento', 'LegajoDocumentoFecha', 'LegajoDocumentoEstado', false],
        'entregas-cargo' => [EntregaCargo::class, 'EntregaCargo', 'EntregaCargoFecha', 'EntregaCargoEstado', 'ANULADO'],
        'desplazamientos-temporales' => [DesplazamientoTemporal::class, 'DesplazamientoTemporal', 'DesplazamientoTemporalFechaInicio', 'DesplazamientoTemporalEstado', 'ANULADO'],
        'avisos-ausencia' => [AvisoAusencia::class, 'AvisoAusencia', 'AvisoAusenciaFecha', 'AvisoAusenciaEstado', 'ANULADO'],
        'tolerancias-extraordinarias' => [ToleranciaExtraordinaria::class, 'ToleranciaExtraordinaria', 'ToleranciaExtraordinariaFechaInicio', 'ToleranciaExtraordinariaEstado', false],
        'autorizaciones-ingreso' => [AutorizacionIngreso::class, 'AutorizacionIngreso', 'AutorizacionIngresoFecha', 'AutorizacionIngresoEstado', false],
        'facilidades-horarias' => [FacilidadHoraria::class, 'FacilidadHoraria', 'FacilidadHorariaFechaInicio', 'FacilidadHorariaEstado', false],
        'contingencias-marcacion' => [ContingenciaMarcacion::class, 'ContingenciaMarcacion', 'ContingenciaMarcacionFechaInicio', 'ContingenciaMarcacionEstado', false],
        'reconocimientos' => [ReconocimientoServidor::class, 'ReconocimientoServidor', 'ReconocimientoServidorFecha', 'ReconocimientoServidorEstado', false],
    ];

    public const TIPOS_LEGAJO = ['DNI', 'HOJA_DE_VIDA', 'TITULO_PROFESIONAL', 'COLEGIATURA', 'CONTRATO', 'RESOLUCION', 'CAPACITACION', 'DECLARACION_JURADA', 'CERTIFICADO_TRABAJO', 'OTRO'];

    public const TIPOS_DESPLAZAMIENTO = ['ROTACION', 'DESTAQUE', 'ENCARGATURA', 'COMISION_DE_SERVICIOS', 'DESIGNACION_TEMPORAL', 'OTRO'];

    public const MEDIOS_DE_AVISO = ['PERSONAL', 'TELEFONO', 'MENSAJE', 'ESCRITO'];

    /** Reconocimientos por servidor y anio (RIT, Art. 96). */
    public const RECONOCIMIENTOS_POR_ANIO = 2;

    /** @return array<string,array<int,mixed>> */
    public static function reglas(string $recurso, bool $crear): array
    {
        $req = $crear ? 'required' : 'sometimes';
        $trabajador = [$req, 'integer', Rule::exists(Trabajador::class, 'TrabajadorId')];
        $vinculo = [$req, 'integer', Rule::exists(VinculoLaboral::class, 'VinculoLaboralId')];
        $eess = [$req, 'integer', Rule::exists(EstablecimientoSalud::class, 'EessId')];
        $documento = ['nullable', 'integer', Rule::exists(DocumentoSustento::class, 'DocumentoSustentoId')];
        $fecha = [$req, 'date_format:Y-m-d'];
        $fechaOpcional = ['nullable', 'date_format:Y-m-d'];
        $texto = ['nullable', 'string', 'max:500'];
        $textoObligatorio = [$req, 'string', 'min:3', 'max:500'];

        return match ($recurso) {
            'fotochecks' => ['TrabajadorId' => $trabajador, 'DocumentoSustentoId' => $documento, 'FotocheckNumero' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9\-]+$/D'],
                'FotocheckFechaEntrega' => $fecha, 'FotocheckFechaDenuncia' => $fechaOpcional, 'FotocheckFechaDevolucion' => $fechaOpcional, 'FotocheckEsDuplicado' => ['sometimes', 'boolean'],
                'FotocheckObservacion' => $texto, 'FotocheckEstado' => ['sometimes', Rule::in(['VIGENTE', 'PERDIDO', 'DEVUELTO'])]],
            'legajo-documentos' => ['TrabajadorId' => $trabajador, 'DocumentoSustentoId' => $documento, 'LegajoDocumentoTipo' => [$req, Rule::in(self::TIPOS_LEGAJO)],
                'LegajoDocumentoNombre' => [$req, 'string', 'max:200'], 'LegajoDocumentoFecha' => $fecha, 'LegajoDocumentoObservacion' => $texto, 'LegajoDocumentoEstado' => ['sometimes', 'boolean']],
            'entregas-cargo' => ['VinculoLaboralId' => $vinculo, 'VinculoLaboralRecibeId' => ['nullable', 'integer', Rule::exists(VinculoLaboral::class, 'VinculoLaboralId')], 'DocumentoSustentoId' => $documento,
                'EntregaCargoMotivo' => [$req, Rule::in(['VACACIONES', 'LICENCIA', 'DESPLAZAMIENTO', 'CESE'])], 'EntregaCargoFecha' => $fecha, 'EntregaCargoDetalle' => ['nullable', 'string', 'max:1000'],
                'EntregaCargoEstado' => ['sometimes', Rule::in(['CONFORME', 'OBSERVADO'])]],
            'desplazamientos-temporales' => ['VinculoLaboralId' => $vinculo, 'EessDestinoId' => [$req, 'integer', Rule::exists(EstablecimientoSalud::class, 'EessId')], 'DocumentoSustentoId' => $documento,
                'DesplazamientoTemporalTipo' => [$req, Rule::in(self::TIPOS_DESPLAZAMIENTO)], 'DesplazamientoTemporalFechaInicio' => $fecha, 'DesplazamientoTemporalFechaFin' => $fecha,
                'DesplazamientoTemporalMotivo' => $texto, 'DesplazamientoTemporalEstado' => ['sometimes', Rule::in(['VIGENTE', 'CONCLUIDO'])]],
            'avisos-ausencia' => ['VinculoLaboralId' => $vinculo, 'AvisoAusenciaFecha' => $fecha, 'AvisoAusenciaMedio' => [$req, Rule::in(self::MEDIOS_DE_AVISO)], 'AvisoAusenciaMotivo' => $textoObligatorio],
            'tolerancias-extraordinarias' => ['EessId' => $eess, 'DocumentoSustentoId' => $documento, 'ToleranciaExtraordinariaFechaInicio' => $fecha, 'ToleranciaExtraordinariaFechaFin' => $fecha,
                'ToleranciaExtraordinariaMinutos' => [$req, 'integer', 'between:1,120'], 'ToleranciaExtraordinariaMotivo' => $textoObligatorio, 'ToleranciaExtraordinariaEstado' => ['sometimes', 'boolean']],
            'autorizaciones-ingreso' => ['VinculoLaboralId' => $vinculo, 'AutorizacionIngresoFecha' => $fecha, 'AutorizacionIngresoMotivo' => $textoObligatorio, 'AutorizacionIngresoEstado' => ['sometimes', 'boolean']],
            'facilidades-horarias' => ['VinculoLaboralId' => $vinculo, 'DocumentoSustentoId' => [$crear ? 'required' : 'sometimes', 'integer', Rule::exists(DocumentoSustento::class, 'DocumentoSustentoId')],
                'FacilidadHorariaMomento' => [$req, Rule::in(['INGRESO', 'SALIDA'])], 'FacilidadHorariaMinutos' => ['sometimes', 'integer', 'between:1,60'], 'FacilidadHorariaFechaInicio' => $fecha,
                'FacilidadHorariaFechaFin' => $fecha, 'FacilidadHorariaObservacion' => $texto, 'FacilidadHorariaEstado' => ['sometimes', 'boolean']],
            'contingencias-marcacion' => ['EessId' => $eess, 'DocumentoSustentoId' => $documento, 'ContingenciaMarcacionFechaInicio' => $fecha, 'ContingenciaMarcacionFechaFin' => $fecha,
                'ContingenciaMarcacionMotivo' => $textoObligatorio, 'ContingenciaMarcacionEstado' => ['sometimes', 'boolean']],
            'reconocimientos' => ['VinculoLaboralId' => $vinculo, 'DocumentoSustentoId' => $documento, 'ReconocimientoServidorAnio' => [$req, 'integer', 'between:2000,2100'],
                'ReconocimientoServidorTipo' => [$req, Rule::in(['AGRADECIMIENTO', 'FELICITACION', 'DIPLOMA'])], 'ReconocimientoServidorMotivo' => $textoObligatorio,
                'ReconocimientoServidorResolucion' => ['nullable', 'string', 'max:60'], 'ReconocimientoServidorFecha' => $fecha, 'ReconocimientoServidorEstado' => ['sometimes', 'boolean']],
        };
    }

    /**
     * Reglas entre columnas y con otras tablas. Devuelve [campo => mensaje].
     *
     * @param  array<string,mixed>  $d  valores efectivos del registro
     * @return array<string,string>
     */
    public static function problemas(string $recurso, array $d, ?int $id): array
    {
        $errores = [];
        $fechas = ['tolerancias-extraordinarias' => 'ToleranciaExtraordinaria', 'facilidades-horarias' => 'FacilidadHoraria', 'contingencias-marcacion' => 'ContingenciaMarcacion', 'desplazamientos-temporales' => 'DesplazamientoTemporal'];
        if (isset($fechas[$recurso]) && ($d[$fechas[$recurso].'FechaFin'] ?? '') < ($d[$fechas[$recurso].'FechaInicio'] ?? '')) {
            $errores[$fechas[$recurso].'FechaFin'] = 'La fecha de fin no puede ser anterior a la de inicio.';
        }

        if ($recurso === 'fotochecks' && ($d['FotocheckEstado'] ?? 'VIGENTE') === 'PERDIDO' && blank($d['FotocheckFechaDenuncia'] ?? null)) {
            $errores['FotocheckFechaDenuncia'] = 'La pérdida se denuncia dentro de las 24 horas: indica la fecha de la denuncia (RIT, Art. 13).';
        }
        if ($recurso === 'fotochecks' && ($d['FotocheckEstado'] ?? 'VIGENTE') === 'VIGENTE') {
            $otro = DB::table('Personal.Fotocheck')->where('TrabajadorId', $d['TrabajadorId'] ?? 0)->where('FotocheckEstado', 'VIGENTE')->when($id, fn ($q) => $q->where('FotocheckId', '<>', $id))->exists();
            if ($otro) {
                $errores['TrabajadorId'] = 'El trabajador ya tiene un fotocheck vigente: registra su pérdida o devolución antes de entregar otro.';
            }
        }
        if ($recurso === 'desplazamientos-temporales') {
            $propio = DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $d['VinculoLaboralId'] ?? 0)->value('EessId');
            if ((int) $propio === (int) ($d['EessDestinoId'] ?? 0)) {
                $errores['EessDestinoId'] = 'El establecimiento de destino es el mismo donde ya labora.';
            }
            $cruce = DB::table('Personal.DesplazamientoTemporal')->where('VinculoLaboralId', $d['VinculoLaboralId'] ?? 0)->where('DesplazamientoTemporalEstado', 'VIGENTE')
                ->when($id, fn ($q) => $q->where('DesplazamientoTemporalId', '<>', $id))
                ->whereDate('DesplazamientoTemporalFechaInicio', '<=', $d['DesplazamientoTemporalFechaFin'] ?? '1900-01-01')->whereDate('DesplazamientoTemporalFechaFin', '>=', $d['DesplazamientoTemporalFechaInicio'] ?? '9999-12-31')->exists();
            if ($cruce) {
                $errores['DesplazamientoTemporalFechaInicio'] = 'El trabajador ya tiene un desplazamiento vigente en esas fechas.';
            }
        }
        if ($recurso === 'reconocimientos') {
            $cantidad = DB::table('Disciplina.ReconocimientoServidor')->where('VinculoLaboralId', $d['VinculoLaboralId'] ?? 0)->where('ReconocimientoServidorAnio', $d['ReconocimientoServidorAnio'] ?? 0)
                ->where('ReconocimientoServidorEstado', 1)->when($id, fn ($q) => $q->where('ReconocimientoServidorId', '<>', $id))->count();
            if ($cantidad >= self::RECONOCIMIENTOS_POR_ANIO) {
                $errores['ReconocimientoServidorAnio'] = 'Se reconoce hasta dos veces al año (RIT, Art. 96).';
            }
        }

        return $errores;
    }

    /**
     * Valores que fija el sistema al crear.
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    public static function completar(string $recurso, array $datos, Request $request): array
    {
        $ahora = HoraLocal::ahora();
        if ($recurso === 'avisos-ausencia') {
            $datos += ['AvisoAusenciaFechaHora' => $ahora->toDateTimeString()];
            $datos['AvisoAusenciaDentroDePlazo'] = self::avisoDentroDePlazo((int) $datos['VinculoLaboralId'], (string) $datos['AvisoAusenciaFecha'], $ahora->toDateTimeString());
            $datos['UsuarioId'] = $request->user()?->UsuarioId;
        }
        if (in_array($recurso, ['tolerancias-extraordinarias', 'autorizaciones-ingreso', 'contingencias-marcacion'], true)) {
            $datos['UsuarioId'] = $request->user()?->UsuarioId;
        }
        if ($recurso === 'autorizaciones-ingreso') {
            $datos += ['AutorizacionIngresoFechaRegistro' => $ahora->toDateTimeString()];
        }

        return $datos;
    }

    /** Se avisa dentro de la primera hora de la jornada de ese dia (RIT, Art. 84.34). Sin turno ese dia, basta avisar antes de que termine. */
    private static function avisoDentroDePlazo(int $vinculoId, string $fecha, string $momento): bool
    {
        $entrada = DB::table('Programacion.TurnoProgramado as tp')
            ->join('Programacion.ProgramacionTrabajador as pt', 'pt.ProgramacionTrabajadorId', '=', 'tp.ProgramacionTrabajadorId')
            ->join('Configuracion.Turno as t', 't.TurnoId', '=', 'tp.TurnoId')
            ->where('pt.VinculoLaboralId', $vinculoId)->whereDate('tp.TurnoProgramadoFecha', $fecha)->where('tp.TurnoProgramadoEstado', '<>', 'ANULADO')
            ->orderBy('t.TurnoHoraEntrada')->value('t.TurnoHoraEntrada');
        $limite = $entrada !== null ? date('Y-m-d H:i:s', strtotime($fecha.' '.substr((string) $entrada, 0, 8)) + 3600) : "{$fecha} 23:59:59";

        return $momento <= $limite;
    }
}
