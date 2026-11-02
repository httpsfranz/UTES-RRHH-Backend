<?php

namespace App\Support;

use App\Models\Personal\VinculoLaboral;
use App\Models\Solicitudes\Licencia;
use App\Models\Solicitudes\TipoLicencia;
use Illuminate\Support\Facades\DB;

/**
 * Reglas del RIT que comparten licencias, papeletas, descansos medicos y justificaciones: topes por tipo y caso especial,
 * plazos en dias habiles y restricciones durante un procedimiento disciplinario.
 */
class ReglasDeSolicitud
{
    /** Plazo para regularizar un tramite no presentado a tiempo (RIT, Art. 64). */
    public const DIAS_HABILES_EXTEMPORANEO = 7;

    /** Plazo para presentar el descanso medico (RIT, Art. 32). */
    public const DIAS_HABILES_DESCANSO_MEDICO = 3;

    /** Dias de enfermedad del anio que asume la Entidad; desde el 21 se canjea por CITT de EsSalud (RIT, Art. 32). */
    public const DIAS_A_CARGO_DE_LA_ENTIDAD = 20;

    /** Topes por caso especial: tipo => caso => dias maximos (RIT, Art. 34, 35 y 37). */
    public const CASOS_ESPECIALES = [
        'MATERNIDAD' => ['PARTO_MULTIPLE' => 128, 'NINO_CON_DISCAPACIDAD' => 128],
        'PATERNIDAD' => ['PREMATURO' => 20, 'PARTO_MULTIPLE' => 20, 'ENFERMEDAD_CONGENITA' => 30, 'COMPLICACION_MADRE' => 30],
        'FALLECIMIENTO' => ['OTRO_LUGAR' => 3],
    ];

    /** Licencias que se cuentan en dias habiles y no corridos (RIT, Art. 37). */
    private const EN_DIAS_HABILES = ['FALLECIMIENTO'];

    /** Licencia particular de personal 276: tres anios dentro de cinco (RIT, Art. 50). */
    private const DIAS_PARTICULARES_276 = 1095;

    /** Plazos activos; se apagan solo para cargar informacion historica (config/rit.php). */
    public static function controlDePlazos(): bool
    {
        return (bool) config('rit.control_de_plazos', true);
    }

    public static function esCas(VinculoLaboral $vinculo): bool
    {
        return (bool) DB::table('Personal.RegimenLaboral')->where('RegimenLaboralId', $vinculo->RegimenLaboralId)->where('RegimenLaboralCodigo', 'DL1057')->exists();
    }

    public static function sexoDe(VinculoLaboral $vinculo): ?string
    {
        return DB::table('Personal.Trabajador')->where('TrabajadorId', $vinculo->TrabajadorId)->value('TrabajadorSexo');
    }

    public static function tienePadEnCurso(int $vinculoId): bool
    {
        return DB::table('Disciplina.ExpedientePad')->where('VinculoLaboralId', $vinculoId)->whereIn('ExpedientePadEstado', ['INICIADO', 'EN_PROCESO'])->exists();
    }

    /**
     * Problemas de una licencia por su tipo: sexo, caso especial, tope, tope anual y capacitacion con PAD.
     *
     * @return array<string,string> campo => mensaje
     */
    public static function problemasDeLicencia(TipoLicencia $tipo, VinculoLaboral $vinculo, string $inicio, string $fin, ?string $caso, ?int $excluirId): array
    {
        $errores = [];
        $codigo = $tipo->TipoLicenciaCodigo;
        $nombre = $tipo->TipoLicenciaNombre;

        if ($tipo->TipoLicenciaSexo !== null && ($sexo = self::sexoDe($vinculo)) !== null && $sexo !== $tipo->TipoLicenciaSexo) {
            $errores['TipoLicenciaId'] = "\"{$nombre}\" corresponde solo a ".($tipo->TipoLicenciaSexo === 'F' ? 'servidoras' : 'servidores').'.';
        }
        if ($caso !== null && ! isset(self::CASOS_ESPECIALES[$codigo][$caso])) {
            $errores['LicenciaCasoEspecial'] = "Ese caso especial no aplica a \"{$nombre}\".";
        }

        $maximo = self::esCas($vinculo) && $tipo->TipoLicenciaMaximoDiasCas !== null ? $tipo->TipoLicenciaMaximoDiasCas : $tipo->TipoLicenciaMaximoDias;
        $enHabiles = in_array($codigo, self::EN_DIAS_HABILES, true);
        $dias = $enHabiles ? DiasHabiles::entre($inicio, $fin, DiasHabiles::esSede($vinculo->VinculoLaboralId)) : (int) ((strtotime($fin) - strtotime($inicio)) / 86400) + 1;
        if ($caso !== null && isset(self::CASOS_ESPECIALES[$codigo][$caso])) {
            $especial = self::CASOS_ESPECIALES[$codigo][$caso];
            $maximo = $codigo === 'FALLECIMIENTO' ? (int) $maximo + $especial : $especial;
        }
        if ($maximo !== null && $dias > $maximo) {
            $errores['LicenciaFechaFin'] = "La licencia por \"{$nombre}\" admite como máximo {$maximo} días ".($enHabiles ? 'hábiles' : 'corridos')." (se piden {$dias}).";
        }

        if ($tipo->TipoLicenciaMaximoDiasAnio !== null) {
            $anio = substr($inicio, 0, 4);
            $usados = Licencia::query()->where('VinculoLaboralId', $vinculo->VinculoLaboralId)->where('TipoLicenciaId', $tipo->TipoLicenciaId)
                ->whereNotIn('LicenciaEstado', ['RECHAZADO', 'ANULADO'])->whereYear('LicenciaFechaInicio', $anio)->when($excluirId, fn ($q) => $q->whereKeyNot($excluirId))->get()
                ->sum(fn (Licencia $l) => $l->LicenciaFechaInicio->diffInDays($l->LicenciaFechaFin) + 1);
            if ($usados + $dias > $tipo->TipoLicenciaMaximoDiasAnio) {
                $errores['LicenciaFechaFin'] = "\"{$nombre}\" admite {$tipo->TipoLicenciaMaximoDiasAnio} días al año y ya se usaron {$usados}.";
            }
        }
        if ($codigo === 'SIN_GOCE' && ! self::esCas($vinculo)) {
            $usados = Licencia::query()->where('VinculoLaboralId', $vinculo->VinculoLaboralId)->where('TipoLicenciaId', $tipo->TipoLicenciaId)
                ->whereNotIn('LicenciaEstado', ['RECHAZADO', 'ANULADO'])->whereDate('LicenciaFechaInicio', '>=', date('Y-m-d', strtotime($inicio.' -5 years')))->when($excluirId, fn ($q) => $q->whereKeyNot($excluirId))->get()
                ->sum(fn (Licencia $l) => $l->LicenciaFechaInicio->diffInDays($l->LicenciaFechaFin) + 1);
            if ($usados + $dias > self::DIAS_PARTICULARES_276) {
                $errores['LicenciaFechaFin'] = 'La licencia por motivos particulares llega hasta tres años dentro de cinco (RIT, Art. 50).';
            }
        }
        if ($codigo === 'CAPACITACION' && self::tienePadEnCurso($vinculo->VinculoLaboralId)) {
            $errores['TipoLicenciaId'] = 'Con un procedimiento disciplinario en curso no se reciben capacitaciones de la Entidad (RIT, Art. 120).';
        }

        return $errores;
    }

    /**
     * Situacion de un tramite que se presenta despues de su fecha. Devuelve null si esta a tiempo, 'extemporaneo' si aun
     * se puede regularizar con justificacion (Art. 64) o 'vencido' si ya paso el plazo.
     */
    public static function situacionDelPlazo(string $fecha, int $diasHabilesATiempo = 0): ?string
    {
        if (! self::controlDePlazos()) {
            return null;
        }
        $pasados = DiasHabiles::transcurridosDesde($fecha);
        if ($pasados <= $diasHabilesATiempo) {
            return $pasados === 0 && $diasHabilesATiempo === 0 && $fecha < HoraLocal::hoy()->toDateString() ? 'extemporaneo' : null;
        }

        return $pasados <= $diasHabilesATiempo + self::DIAS_HABILES_EXTEMPORANEO ? 'extemporaneo' : 'vencido';
    }

    /** Dias de descanso medico del trabajador en el anio, sin contar el registro que se edita. */
    public static function diasDescansoEnElAnio(int $vinculoId, string $fecha, ?int $excluirId = null): int
    {
        return (int) DB::table('Solicitudes.DescansoMedico')->where('VinculoLaboralId', $vinculoId)->whereNotIn('DescansoMedicoEstado', ['RECHAZADO', 'ANULADO'])
            ->whereYear('DescansoMedicoFechaInicio', substr($fecha, 0, 4))->when($excluirId, fn ($q) => $q->where('DescansoMedicoId', '<>', $excluirId))
            ->sum(DB::raw('DATEDIFF(day, DescansoMedicoFechaInicio, DescansoMedicoFechaFin) + 1'));
    }
}
