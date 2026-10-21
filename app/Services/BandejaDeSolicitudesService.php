<?php

namespace App\Services;

use App\Models\Seguridad\Usuario;
use App\Support\AccesoUsuario;
use App\Support\AlcanceDeEess;
use App\Support\PermisosDeRuta;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bandeja unica de solicitudes: todo lo que espera aprobacion (justificaciones, ajustes de marcacion, papeletas,
 * licencias, descansos medicos, vacaciones, cambios de turno, informes de guardia y horas de compensacion) en una sola
 * lista, las mas antiguas primero. Aprobar y rechazar siguen siendo las acciones de cada recurso
 * (POST /{recurso}/{id}/aprobar|rechazar), con sus reglas del RIT; aqui solo se reune y se resume.
 *
 * Cada tipo aparece si la sesion puede consultar ese recurso, dentro de su alcance (sus establecimientos o, si solo es
 * TRABAJADOR, sus propias solicitudes: "Mis solicitudes"). `puede_aprobar` sale del permiso de la accion.
 */
class BandejaDeSolicitudesService
{
    /** recurso de la API => [nombre, ruta de la pantalla, tiene rechazo] */
    public const TIPOS = [
        'justificaciones-falta' => ['Justificación de falta', '/solicitudes/registro?pestana=justificaciones', true],
        'ajustes-marcacion' => ['Ajuste de marcación', '/solicitudes/registro?pestana=ajustes', true],
        'papeletas' => ['Papeleta', '/solicitudes/registro?pestana=papeletas', true],
        'licencias' => ['Licencia', '/solicitudes/registro?pestana=licencias', true],
        'descansos-medicos' => ['Descanso médico', '/solicitudes/registro?pestana=descansos', true],
        'goces-vacacionales' => ['Vacaciones', '/vacaciones/gestion', true],
        'cambios-turno' => ['Cambio de turno', '/programacion/gestion?pestana=cambios', true],
        'informes-guardia-comunitaria' => ['Informe de guardia comunitaria', '/programacion/gestion?pestana=guardias', true],
        'compensaciones-horarias' => ['Horas por compensar', '/consolidacion/gestion?pestana=compensaciones', false],
    ];

    private const ESTADOS = ['PENDIENTE', 'APROBADO', 'RECHAZADO', 'ANULADO'];

    /**
     * @param  'PENDIENTE'|'TODOS'  $estado
     * @return array{solicitudes:list<array<string,mixed>>,resumen:list<array<string,mixed>>,propias:bool}
     */
    public function listar(Usuario $usuario, string $estado = 'PENDIENTE', ?string $tipo = null): array
    {
        $acceso = AccesoUsuario::de($usuario);
        $propias = $acceso->propio;
        $eess = $propias ? null : AlcanceDeEess::permitidos($usuario);
        $vinculos = $propias ? $acceso->vinculoIds : null;
        $estados = $estado === 'TODOS' ? self::ESTADOS : ['PENDIENTE'];

        $filas = collect();
        $resumen = [];
        foreach (self::TIPOS as $recurso => [$nombre, $ruta, $conRechazo]) {
            $ver = PermisosDeRuta::para('GET', [$recurso]);
            if ($ver !== null && ! $acceso->tiene($ver)) {
                continue;
            }
            $aprobar = PermisosDeRuta::para('POST', [$recurso, '1', 'aprobar']);
            $puedeAprobar = ! $propias && $acceso->tiene($aprobar);

            $deTipo = DB::query()->fromSub($this->consulta($recurso), 's')
                ->whereIn('estado', $estados)
                ->when($vinculos !== null, fn (Builder $q) => $q->whereIn('vinculo', $vinculos ?: [0]))
                ->when($eess !== null, fn (Builder $q) => $q->whereIn('vinculo', DB::table('Personal.VinculoLaboral')->whereIn('EessId', $eess ?: [0])->select('VinculoLaboralId')))
                ->get();
            $resumen[] = ['tipo' => $recurso, 'nombre' => $nombre, 'pendientes' => $deTipo->where('estado', 'PENDIENTE')->count(), 'puede_aprobar' => $puedeAprobar];
            if ($tipo !== null && $tipo !== $recurso) {
                continue;
            }
            $filas = $filas->concat($deTipo->map(fn (object $f) => [
                'clave' => "{$recurso}-{$f->id}", 'tipo' => $recurso, 'tipo_nombre' => $nombre, 'id' => (int) $f->id, 'vinculo_id' => (int) $f->vinculo,
                'estado' => $f->estado, 'fecha' => substr((string) $f->fecha, 0, 10), 'fecha_fin' => $f->fecha_fin ? substr((string) $f->fecha_fin, 0, 10) : null,
                'registrado' => $f->registrado ? substr((string) $f->registrado, 0, 16) : null, 'detalle' => trim(preg_replace('/\s+/', ' ', (string) $f->detalle)),
                'puede_aprobar' => $puedeAprobar && $f->estado === 'PENDIENTE', 'puede_rechazar' => $puedeAprobar && $conRechazo && $f->estado === 'PENDIENTE', 'pantalla' => $ruta,
            ]));
        }

        return ['solicitudes' => $this->conTrabajador($filas)->values()->all(), 'resumen' => $resumen, 'propias' => $propias];
    }

    /**
     * Cuantas solicitudes pendientes ve la sesion: las mismas de "Por resolver" (o, si solo es TRABAJADOR, las suyas en
     * tramite). Es el contador del menu; cuenta sin traer las filas.
     */
    public function cantidadPendiente(Usuario $usuario): int
    {
        $acceso = AccesoUsuario::de($usuario);
        $eess = $acceso->propio ? null : AlcanceDeEess::permitidos($usuario);
        $vinculos = $acceso->propio ? $acceso->vinculoIds : null;

        $total = 0;
        foreach (array_keys(self::TIPOS) as $recurso) {
            $ver = PermisosDeRuta::para('GET', [$recurso]);
            if ($ver !== null && ! $acceso->tiene($ver)) {
                continue;
            }
            $total += DB::query()->fromSub($this->consulta($recurso), 's')
                ->where('estado', 'PENDIENTE')
                ->when($vinculos !== null, fn (Builder $q) => $q->whereIn('vinculo', $vinculos ?: [0]))
                ->when($eess !== null, fn (Builder $q) => $q->whereIn('vinculo', DB::table('Personal.VinculoLaboral')->whereIn('EessId', $eess ?: [0])->select('VinculoLaboralId')))
                ->count();
        }

        return $total;
    }

    /**
     * Columnas comunes de cada tipo: id, vinculo, estado, fecha (y fecha_fin), registrado (cuando se pidio) y detalle.
     */
    private function consulta(string $recurso): Builder
    {
        $hhmm = fn (string $columna) => "LEFT(CONVERT(varchar(8), {$columna}, 108), 5)";

        return match ($recurso) {
            'justificaciones-falta' => DB::table('Asistencia.JustificacionFalta as j')
                ->join('Asistencia.ConceptoJustificacion as c', 'c.ConceptoJustificacionId', '=', 'j.ConceptoJustificacionId')
                ->select(['j.JustificacionFaltaId as id', 'j.VinculoLaboralId as vinculo', 'j.JustificacionFaltaEstado as estado', 'j.JustificacionFaltaFechaInicio as fecha',
                    'j.JustificacionFaltaFechaFin as fecha_fin', 'j.JustificacionFaltaFechaRegistro as registrado', 'c.ConceptoJustificacionNombre as detalle']),
            'ajustes-marcacion' => DB::table('Asistencia.AjusteMarcacion as a')
                ->join('Asistencia.Marcacion as m', 'm.MarcacionId', '=', 'a.MarcacionId')
                ->select(['a.AjusteMarcacionId as id', 'm.VinculoLaboralId as vinculo', 'a.AjusteMarcacionEstado as estado', 'a.AjusteMarcacionFechaHoraAnterior as fecha',
                    DB::raw('NULL as fecha_fin'), 'a.AjusteMarcacionFechaHora as registrado',
                    DB::raw("CONCAT(m.MarcacionTipo, ' ', {$hhmm('a.AjusteMarcacionFechaHoraAnterior')}, ' → ', COALESCE({$hhmm('a.AjusteMarcacionFechaHoraNueva')}, 'anular'), ' · ', a.AjusteMarcacionMotivo) as detalle")]),
            'papeletas' => DB::table('Solicitudes.Papeleta as p')
                ->join('Solicitudes.TipoPapeleta as t', 't.TipoPapeletaId', '=', 'p.TipoPapeletaId')
                ->select(['p.PapeletaId as id', 'p.VinculoLaboralId as vinculo', 'p.PapeletaEstado as estado', 'p.PapeletaFecha as fecha', DB::raw('NULL as fecha_fin'),
                    'p.PapeletaFechaRegistro as registrado',
                    DB::raw("CONCAT(t.TipoPapeletaNombre, ' · ', CASE WHEN p.PapeletaEsDiaCompleto = 1 THEN 'día completo' ELSE CONCAT({$hhmm('p.PapeletaHoraSalida')}, '–', COALESCE({$hhmm('p.PapeletaHoraRetorno')}, '')) END, COALESCE(' · ' + p.PapeletaMotivo, '')) as detalle")]),
            'licencias' => DB::table('Solicitudes.Licencia as l')
                ->join('Solicitudes.TipoLicencia as t', 't.TipoLicenciaId', '=', 'l.TipoLicenciaId')
                ->select(['l.LicenciaId as id', 'l.VinculoLaboralId as vinculo', 'l.LicenciaEstado as estado', 'l.LicenciaFechaInicio as fecha', 'l.LicenciaFechaFin as fecha_fin',
                    'l.LicenciaFechaRegistro as registrado', 't.TipoLicenciaNombre as detalle']),
            'descansos-medicos' => DB::table('Solicitudes.DescansoMedico as d')
                ->select(['d.DescansoMedicoId as id', 'd.VinculoLaboralId as vinculo', 'd.DescansoMedicoEstado as estado', 'd.DescansoMedicoFechaInicio as fecha', 'd.DescansoMedicoFechaFin as fecha_fin',
                    'd.DescansoMedicoFechaRegistro as registrado', DB::raw("COALESCE('CITT ' + d.DescansoMedicoNumeroCitt, 'Sin CITT') as detalle")]),
            'goces-vacacionales' => DB::table('Vacaciones.GoceVacacional as g')
                ->join('Vacaciones.RolVacacional as r', 'r.RolVacacionalId', '=', 'g.RolVacacionalId')
                ->join('Vacaciones.PeriodoVacacional as pv', 'pv.PeriodoVacacionalId', '=', 'r.PeriodoVacacionalId')
                ->select(['g.GoceVacacionalId as id', 'pv.VinculoLaboralId as vinculo', 'g.GoceVacacionalEstado as estado', 'g.GoceVacacionalFechaInicio as fecha', 'g.GoceVacacionalFechaFin as fecha_fin',
                    DB::raw('NULL as registrado'), DB::raw("CONCAT(g.GoceVacacionalDias, ' días de vacaciones (récord ', pv.PeriodoVacacionalAnio, ')') as detalle")]),
            'cambios-turno' => DB::table('Programacion.CambioTurno as c')
                ->join('Programacion.TipoCambioTurno as t', 't.TipoCambioTurnoId', '=', 'c.TipoCambioTurnoId')
                ->join('Programacion.TurnoProgramado as tp', 'tp.TurnoProgramadoId', '=', 'c.TurnoProgramadoId')
                ->join('Configuracion.Turno as tu', 'tu.TurnoId', '=', 'tp.TurnoId')
                ->select(['c.CambioTurnoId as id', 'c.VinculoLaboralSolicitanteId as vinculo', 'c.CambioTurnoEstado as estado', 'tp.TurnoProgramadoFecha as fecha', DB::raw('NULL as fecha_fin'),
                    'c.CambioTurnoFechaSolicitud as registrado', DB::raw("CONCAT(t.TipoCambioTurnoNombre, ' · turno ', tu.TurnoCodigo, COALESCE(' · ' + c.CambioTurnoMotivo, '')) as detalle")]),
            'informes-guardia-comunitaria' => DB::table('Programacion.InformeGuardiaComunitaria as i')
                ->select(['i.InformeGuardiaComunitariaId as id', 'i.VinculoLaboralId as vinculo', 'i.InformeGuardiaComunitariaEstado as estado', 'i.InformeGuardiaComunitariaFecha as fecha',
                    DB::raw('NULL as fecha_fin'), DB::raw('NULL as registrado'),
                    DB::raw("CONCAT({$hhmm('i.InformeGuardiaComunitariaHoraInicio')}, '–', {$hhmm('i.InformeGuardiaComunitariaHoraFin')}, ' · ', i.InformeGuardiaComunitariaDescripcion) as detalle")]),
            'compensaciones-horarias' => DB::table('Compensaciones.CompensacionHoraria as h')
                ->select(['h.CompensacionHorariaId as id', 'h.VinculoLaboralId as vinculo', 'h.CompensacionHorariaEstado as estado', DB::raw('COALESCE(h.CompensacionHorariaFechaTrabajo, h.CompensacionHorariaFechaGeneracion) as fecha'),
                    DB::raw('NULL as fecha_fin'), 'h.CompensacionHorariaFechaGeneracion as registrado',
                    DB::raw("CONCAT(CAST(h.CompensacionHorariaHorasGeneradas AS decimal(6,1)), ' h fuera de jornada', COALESCE(' · ' + h.CompensacionHorariaObservacion, '')) as detalle")]),
        };
    }

    /**
     * Agrega trabajador, DNI, cargo y establecimiento, y ordena: lo pendiente primero y, dentro, lo mas antiguo primero.
     *
     * @param  Collection<int,array<string,mixed>>  $filas
     * @return Collection<int,array<string,mixed>>
     */
    private function conTrabajador(Collection $filas): Collection
    {
        $datos = DB::table('Personal.VinculoLaboral as v')
            ->join('Personal.Trabajador as t', 't.TrabajadorId', '=', 'v.TrabajadorId')
            ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
            ->leftJoin('Personal.Cargo as c', 'c.CargoId', '=', 'v.CargoId')
            ->whereIn('v.VinculoLaboralId', $filas->pluck('vinculo_id')->unique()->values()->all() ?: [0])
            ->get(['v.VinculoLaboralId', 'v.EessId', 't.TrabajadorNombreCompleto', 't.TrabajadorNumeroDocumento', 'e.EessNombre', 'c.CargoNombre'])
            ->keyBy('VinculoLaboralId');
        $hoy = CarbonImmutable::today();

        return $filas->map(function (array $f) use ($datos, $hoy) {
            $v = $datos->get($f['vinculo_id']);
            $desde = $f['registrado'] ?? $f['fecha'];

            return $f + [
                'trabajador' => $v ? trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $v->TrabajadorNombreCompleto))) : null,
                'dni' => $v?->TrabajadorNumeroDocumento, 'cargo' => $v?->CargoNombre, 'eess' => $v?->EessNombre, 'eess_id' => $v ? (int) $v->EessId : null,
                'dias_esperando' => $f['estado'] === 'PENDIENTE' && $desde ? max(0, (int) CarbonImmutable::parse($desde)->startOfDay()->diffInDays($hoy, false)) : null,
            ];
        })->sort(function (array $a, array $b) {
            $pendienteA = $a['estado'] === 'PENDIENTE';
            $pendienteB = $b['estado'] === 'PENDIENTE';
            if ($pendienteA !== $pendienteB) {
                return $pendienteA ? -1 : 1;
            }
            $orden = strcmp((string) ($a['registrado'] ?? $a['fecha']), (string) ($b['registrado'] ?? $b['fecha']));

            // Lo que espera, del mas antiguo al mas nuevo; lo ya resuelto, del mas reciente al mas antiguo.
            return $pendienteA ? $orden : -$orden;
        });
    }
}
