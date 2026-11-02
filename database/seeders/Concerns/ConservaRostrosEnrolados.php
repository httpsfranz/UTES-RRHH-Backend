<?php

namespace Database\Seeders\Concerns;

use App\Services\CalculoAsistenciaService;
use App\Services\MarcacionFacialService;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Reiniciar la base (php artisan db:seed) no debe dejar mudo al marcador facial: las personas que se enrolaron de verdad
 * desde el marcador facial (plantilla ROSTRO con su descriptor de 128 numeros, a diferencia de las plantillas ficticias de los
 * seeders) se respaldan antes de vaciar las tablas y se vuelven a crear al final, con su consentimiento, su vinculo vigente,
 * su horario y sus marcaciones. Las referencias a catalogos viajan por codigo, no por Id.
 *
 * Ademas del respaldo en memoria queda una copia en storage/app/respaldo-rostros-enrolados.json, que se usa si la siembra
 * empieza con la base vacia (php artisan migrate:fresh --seed).
 */
trait ConservaRostrosEnrolados
{
    private function archivoDeRespaldo(): string
    {
        return storage_path('app/respaldo-rostros-enrolados.json');
    }

    /** @return list<array<string,mixed>> */
    protected function respaldarRostrosEnrolados(): array
    {
        try {
            if (! DB::table('Personal.Trabajador')->exists()) {
                return File::exists($this->archivoDeRespaldo()) ? json_decode(File::get($this->archivoDeRespaldo()), true) : [];
            }
            $bytes = MarcacionFacialService::DIMENSION * 4;
            $ids = DB::table('Biometria.PlantillaBiometrica')->where('PlantillaBiometricaTipo', 'ROSTRO')->where('PlantillaBiometricaEstado', 1)
                ->whereRaw('DATALENGTH(PlantillaBiometricaReferencia) = ?', [$bytes])->distinct()->pluck('TrabajadorId')->all();
        } catch (Throwable) {
            return [];
        }

        $personas = [];
        foreach ($ids as $trabajadorId) {
            $trabajador = (array) DB::table('Personal.Trabajador as t')
                ->join('Personal.TipoDocumentoIdentidad as d', 'd.TipoDocumentoIdentidadId', '=', 't.TipoDocumentoIdentidadId')
                ->leftJoin('Personal.Profesion as p', 'p.ProfesionId', '=', 't.ProfesionId')
                ->where('t.TrabajadorId', $trabajadorId)
                ->first(['t.*', 'd.TipoDocumentoIdentidadCodigo', 'p.ProfesionCodigo']);
            unset($trabajador['TrabajadorNombreCompleto']);

            $vinculos = DB::table('Personal.VinculoLaboral as v')
                ->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
                ->join('Personal.Cargo as c', 'c.CargoId', '=', 'v.CargoId')
                ->join('Personal.CondicionLaboral as cl', 'cl.CondicionLaboralId', '=', 'v.CondicionLaboralId')
                ->join('Personal.RegimenLaboral as r', 'r.RegimenLaboralId', '=', 'v.RegimenLaboralId')
                ->where('v.TrabajadorId', $trabajadorId)->where('v.VinculoLaboralEstado', 1)
                ->get(['v.*', 'e.EessCodigo', 'c.CargoNombre', 'cl.CondicionLaboralCodigo', 'r.RegimenLaboralCodigo'])
                ->map(fn ($v) => (array) $v + [
                    'horarios' => DB::table('Personal.AsignacionHorario as a')->join('Configuracion.Horario as h', 'h.HorarioId', '=', 'a.HorarioId')
                        ->where('a.VinculoLaboralId', $v->VinculoLaboralId)->where('a.AsignacionHorarioEstado', 1)->get(['a.*', 'h.HorarioCodigo'])->map(fn ($a) => (array) $a)->all(),
                    'marcaciones' => DB::table('Asistencia.Marcacion as m')->join('Biometria.MetodoMarcacion as mm', 'mm.MetodoMarcacionId', '=', 'm.MetodoMarcacionId')
                        ->leftJoin('Biometria.DispositivoMarcacion as dm', 'dm.DispositivoMarcacionId', '=', 'm.DispositivoMarcacionId')
                        ->where('m.VinculoLaboralId', $v->VinculoLaboralId)->whereIn('m.MarcacionOrigen', ['DISPOSITIVO', 'MANUAL'])
                        ->get(['m.*', 'mm.MetodoMarcacionCodigo', 'dm.DispositivoMarcacionCodigo'])->map(fn ($m) => (array) $m)->all(),
                ])->all();

            $personas[] = [
                'trabajador' => $trabajador,
                'consentimientos' => DB::table('Biometria.ConsentimientoBiometrico')->where('TrabajadorId', $trabajadorId)->get()->map(fn ($c) => (array) $c)->all(),
                'plantillas' => DB::table('Biometria.PlantillaBiometrica')->where('TrabajadorId', $trabajadorId)->where('PlantillaBiometricaTipo', 'ROSTRO')->where('PlantillaBiometricaEstado', 1)
                    ->get(['PlantillaBiometricaId', 'PlantillaBiometricaTipo', 'PlantillaBiometricaFechaRegistro', DB::raw('CONVERT(VARCHAR(MAX), PlantillaBiometricaReferencia, 2) AS referencia')])
                    ->map(fn ($p) => (array) $p)->all(),
                'vinculos' => $vinculos,
            ];
        }

        if ($personas !== []) {
            File::ensureDirectoryExists(dirname($this->archivoDeRespaldo()));
            File::put($this->archivoDeRespaldo(), json_encode($personas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $personas;
    }

    /**
     * Vuelve a crear a las personas respaldadas sobre la base recien sembrada y recalcula su asistencia del mes.
     *
     * @param  list<array<string,mixed>>  $personas
     * @return list<string> nombres restaurados
     */
    protected function restaurarRostrosEnrolados(array $personas): array
    {
        $id = fn (string $tabla, string $columna, string $clave, ?string $valor) => $valor === null ? null : DB::table($tabla)->where($columna, $valor)->value($clave);
        $restaurados = [];

        foreach ($personas as $persona) {
            $t = $persona['trabajador'];
            $tipoDocumento = $id('Personal.TipoDocumentoIdentidad', 'TipoDocumentoIdentidadCodigo', 'TipoDocumentoIdentidadId', $t['TipoDocumentoIdentidadCodigo']);
            $ocupado = DB::table('Personal.Trabajador')->where(['TipoDocumentoIdentidadId' => $tipoDocumento, 'TrabajadorNumeroDocumento' => $t['TrabajadorNumeroDocumento']])->first(['TrabajadorId', 'TrabajadorNombres']);
            $trabajadorId = null;
            if ($ocupado !== null && $ocupado->TrabajadorNombres === $t['TrabajadorNombres']) {
                $trabajadorId = $ocupado->TrabajadorId;
            } elseif ($ocupado !== null) {
                // Un trabajador ficticio recibio por azar el mismo DNI: se le asigna otro y la persona real conserva el suyo.
                $otro = (int) $t['TrabajadorNumeroDocumento'];
                do {
                    $otro = $otro >= 99999999 ? 10000000 : $otro + 1;
                } while (DB::table('Personal.Trabajador')->where('TrabajadorNumeroDocumento', (string) $otro)->exists());
                DB::table('Personal.Trabajador')->where('TrabajadorId', $ocupado->TrabajadorId)->update(['TrabajadorNumeroDocumento' => (string) $otro]);
            }
            if ($trabajadorId === null) {
                $fila = array_diff_key($t, array_flip(['TrabajadorId', 'TipoDocumentoIdentidadCodigo', 'ProfesionCodigo']));
                $trabajadorId = DB::table('Personal.Trabajador')->insertGetId([
                    'TipoDocumentoIdentidadId' => $tipoDocumento,
                    'ProfesionId' => $id('Personal.Profesion', 'ProfesionCodigo', 'ProfesionId', $t['ProfesionCodigo']),
                ] + $fila, 'TrabajadorId');
            }

            foreach ($persona['consentimientos'] as $c) {
                DB::table('Biometria.ConsentimientoBiometrico')->insert(['TrabajadorId' => $trabajadorId, 'DocumentoSustentoId' => null]
                    + array_diff_key($c, array_flip(['ConsentimientoBiometricoId', 'TrabajadorId', 'DocumentoSustentoId'])));
            }

            $plantillaNueva = [];
            foreach ($persona['plantillas'] as $p) {
                $nuevo = (int) DB::table('Biometria.PlantillaBiometrica')->insertGetId([
                    'TrabajadorId' => $trabajadorId, 'PlantillaBiometricaTipo' => $p['PlantillaBiometricaTipo'], 'PlantillaBiometricaDedo' => null,
                    'PlantillaBiometricaFechaRegistro' => $p['PlantillaBiometricaFechaRegistro'], 'PlantillaBiometricaEstado' => 1,
                ], 'PlantillaBiometricaId');
                DB::update('UPDATE Biometria.PlantillaBiometrica SET PlantillaBiometricaReferencia = CONVERT(VARBINARY(MAX), ?, 2) WHERE PlantillaBiometricaId = ?', [$p['referencia'], $nuevo]);
                $plantillaNueva[$p['PlantillaBiometricaId']] = $nuevo;
            }

            foreach ($persona['vinculos'] as $v) {
                $codigo = $v['VinculoLaboralCodigo'];
                if (DB::table('Personal.VinculoLaboral')->where('VinculoLaboralCodigo', $codigo)->exists()) {
                    $mayor = DB::table('Personal.VinculoLaboral')->where('VinculoLaboralCodigo', 'like', 'VL-%')->pluck('VinculoLaboralCodigo')
                        ->map(fn ($c) => (int) substr((string) $c, 3))->max();
                    $codigo = 'VL-'.str_pad((string) ($mayor + 1), 4, '0', STR_PAD_LEFT);
                }
                $vinculoId = (int) DB::table('Personal.VinculoLaboral')->insertGetId([
                    'TrabajadorId' => $trabajadorId,
                    'EessId' => $id('Organizacion.EstablecimientoSalud', 'EessCodigo', 'EessId', $v['EessCodigo']),
                    'RegimenLaboralId' => $id('Personal.RegimenLaboral', 'RegimenLaboralCodigo', 'RegimenLaboralId', $v['RegimenLaboralCodigo']),
                    'CondicionLaboralId' => $id('Personal.CondicionLaboral', 'CondicionLaboralCodigo', 'CondicionLaboralId', $v['CondicionLaboralCodigo']),
                    'CargoId' => $id('Personal.Cargo', 'CargoNombre', 'CargoId', $v['CargoNombre']),
                    'VinculoLaboralCodigo' => $codigo, 'VinculoLaboralCodigoAirhsp' => $v['VinculoLaboralCodigoAirhsp'], 'VinculoLaboralNumeroPlaza' => $v['VinculoLaboralNumeroPlaza'],
                    'VinculoLaboralFechaInicio' => $v['VinculoLaboralFechaInicio'], 'VinculoLaboralFechaFin' => $v['VinculoLaboralFechaFin'],
                    'VinculoLaboralMotivoCese' => $v['VinculoLaboralMotivoCese'], 'VinculoLaboralEstado' => 1,
                ], 'VinculoLaboralId');

                foreach ($v['horarios'] as $a) {
                    DB::table('Personal.AsignacionHorario')->insert([
                        'VinculoLaboralId' => $vinculoId, 'HorarioId' => $id('Configuracion.Horario', 'HorarioCodigo', 'HorarioId', $a['HorarioCodigo']),
                        'AsignacionHorarioFechaInicio' => $a['AsignacionHorarioFechaInicio'], 'AsignacionHorarioFechaFin' => $a['AsignacionHorarioFechaFin'],
                        'AsignacionHorarioObservacion' => $a['AsignacionHorarioObservacion'], 'AsignacionHorarioFechaRegistro' => $a['AsignacionHorarioFechaRegistro'], 'AsignacionHorarioEstado' => 1,
                    ]);
                }

                foreach ($v['marcaciones'] as $m) {
                    DB::table('Asistencia.Marcacion')->insert([
                        'VinculoLaboralId' => $vinculoId,
                        'MetodoMarcacionId' => $id('Biometria.MetodoMarcacion', 'MetodoMarcacionCodigo', 'MetodoMarcacionId', $m['MetodoMarcacionCodigo']),
                        'DispositivoMarcacionId' => $id('Biometria.DispositivoMarcacion', 'DispositivoMarcacionCodigo', 'DispositivoMarcacionId', $m['DispositivoMarcacionCodigo']),
                        'PlantillaBiometricaId' => $m['PlantillaBiometricaId'] === null ? null : ($plantillaNueva[$m['PlantillaBiometricaId']] ?? null),
                        'CargaAsistenciaManualId' => null, 'MarcacionFechaHora' => $m['MarcacionFechaHora'], 'MarcacionTipo' => $m['MarcacionTipo'],
                        'MarcacionGeolocalizacion' => $m['MarcacionGeolocalizacion'], 'MarcacionObservacion' => $m['MarcacionObservacion'],
                        'MarcacionOrigen' => $m['MarcacionOrigen'], 'MarcacionEsValida' => $m['MarcacionEsValida'],
                    ]);
                }

                $hoy = HoraLocal::ahora()->startOfDay();
                $desde = max(substr((string) $v['VinculoLaboralFechaInicio'], 0, 10), $hoy->startOfMonth()->toDateString());
                if ($desde <= $hoy->toDateString()) {
                    app(CalculoAsistenciaService::class)->calcular($desde, $hoy->toDateString(), $vinculoId);
                }
            }

            $restaurados[] = trim($t['TrabajadorNombres'].' '.$t['TrabajadorApellidoPaterno']);
        }

        return $restaurados;
    }
}
