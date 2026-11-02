<?php

namespace Database\Seeders;

use App\Support\HoraLocal;
use Database\Seeders\Concerns\VolumenBase;
use Database\Seeders\Concerns\VolumenCierre;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Registros que exige el RIT para la base de trabajo (solo con la expansion de volumen activa): remuneraciones, internos y
 * residentes, Microred Trujillo, fotochecks, legajos, entregas de cargo, desplazamientos, avisos, tolerancias, ingresos
 * autorizados, facilidades, contingencias y reconocimientos. Es reproducible: cada eleccion sale del azar con semilla.
 */
class DatosRitRealistasSeeder extends Seeder
{
    use VolumenBase;
    use VolumenCierre;

    private const CENTROS_MICRORED_TRUJILLO = [
        ['EESS-TR-01', 'C.S. Los Granados', 'I-3', 'Av. Los Granados 340, Trujillo'],
        ['EESS-TR-02', 'P.S. Liberación Social', 'I-2', 'Jr. Unión 215, Trujillo'],
    ];

    public function run(): void
    {
        mt_srand(409);
        $vinculos = DB::table('Personal.VinculoLaboral as v')->join('Personal.Cargo as c', 'c.CargoId', '=', 'v.CargoId')
            ->where('v.VinculoLaboralEstado', 1)->orderBy('v.VinculoLaboralId')
            ->get(['v.VinculoLaboralId', 'v.TrabajadorId', 'v.EessId', 'v.CondicionLaboralId', 'v.VinculoLaboralFechaInicio', 'c.CargoNombre']);

        $this->remuneraciones($vinculos);
        $this->internosYResidentes($vinculos);
        $this->microredTrujillo();
        $this->fotochecks($vinculos);
        $this->legajos($vinculos);
        $this->movimientos($vinculos);
        $this->avisosYAutorizaciones($vinculos);
        $this->facilidadesTolerancias($vinculos);
        $this->reconocimientos($vinculos);
    }

    private function remuneraciones($vinculos): void
    {
        foreach ($vinculos as $v) {
            $base = self::INGRESOS[$v->CargoNombre] ?? 1650;
            DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $v->VinculoLaboralId)
                ->update(['VinculoLaboralIngresoMensual' => $base + 50 * ($v->VinculoLaboralId % 5)]);
        }
    }

    /** Pasan a interno y a medico residente algunos contratados de los centros de mayor nivel: alimentan los Anexos 015 y 016. */
    private function internosYResidentes($vinculos): void
    {
        $contratado = DB::table('Personal.CondicionLaboral')->where('CondicionLaboralCodigo', 'CONTRATADO')->value('CondicionLaboralId');
        $condicion = fn (string $codigo) => DB::table('Personal.CondicionLaboral')->where('CondicionLaboralCodigo', $codigo)->value('CondicionLaboralId');
        $contratados = $vinculos->where('CondicionLaboralId', $contratado)->values();
        $medicos = $contratados->filter(fn ($v) => str_contains($v->CargoNombre, 'Médico'))->take(3)->pluck('VinculoLaboralId');
        $apoyo = $contratados->filter(fn ($v) => preg_match('/Enfermero|Obstetra|Odont/u', $v->CargoNombre))->take(4)->pluck('VinculoLaboralId');

        DB::table('Personal.VinculoLaboral')->whereIn('VinculoLaboralId', $medicos)->update(['CondicionLaboralId' => $condicion('RESIDENTE')]);
        DB::table('Personal.VinculoLaboral')->whereIn('VinculoLaboralId', $apoyo)->update(['CondicionLaboralId' => $condicion('INTERNO')]);
    }

    private function microredTrujillo(): void
    {
        $microred = DB::table('Organizacion.Microred')->where('MicroredCodigo', 'MR-TR')->value('MicroredId');
        $tipo = DB::table('Organizacion.EstablecimientoSalud')->value('TipoEstablecimientoId');
        if ($microred === null) {
            return;
        }
        foreach (self::CENTROS_MICRORED_TRUJILLO as [$codigo, $nombre, $categoria, $direccion]) {
            DB::table('Organizacion.EstablecimientoSalud')->updateOrInsert(['EessCodigo' => $codigo], [
                'MicroredId' => $microred, 'TipoEstablecimientoId' => $tipo, 'EessNombre' => $nombre, 'EessCategoria' => $categoria, 'EessDireccion' => $direccion, 'EessEstado' => 1,
            ]);
        }
    }

    private function fotochecks($vinculos): void
    {
        $filas = [];
        foreach ($vinculos->unique('TrabajadorId')->values() as $i => $v) {
            $perdido = $i % 53 === 7;
            $entrega = substr((string) $v->VinculoLaboralFechaInicio, 0, 10);
            $filas[] = ['TrabajadorId' => $v->TrabajadorId, 'FotocheckNumero' => sprintf('UE409-%05d', $i + 1), 'FotocheckFechaEntrega' => $entrega,
                'FotocheckEstado' => $perdido ? 'PERDIDO' : 'VIGENTE', 'FotocheckFechaDenuncia' => $perdido ? '2026-09-14' : null, 'FotocheckEsDuplicado' => 0];
        }
        $this->insertarDe100('Personal.Fotocheck', $filas);
    }

    private function legajos($vinculos): void
    {
        $filas = [];
        foreach ($vinculos->unique('TrabajadorId')->take(80)->values() as $i => $v) {
            $inicio = substr((string) $v->VinculoLaboralFechaInicio, 0, 10);
            $filas[] = ['TrabajadorId' => $v->TrabajadorId, 'LegajoDocumentoTipo' => 'CONTRATO', 'LegajoDocumentoNombre' => "Contrato o resolución de ingreso {$inicio}", 'LegajoDocumentoFecha' => $inicio];
            $filas[] = ['TrabajadorId' => $v->TrabajadorId, 'LegajoDocumentoTipo' => 'DNI', 'LegajoDocumentoNombre' => 'Copia del DNI', 'LegajoDocumentoFecha' => $inicio];
            if ($i % 2 === 0) {
                $filas[] = ['TrabajadorId' => $v->TrabajadorId, 'LegajoDocumentoTipo' => 'HOJA_DE_VIDA', 'LegajoDocumentoNombre' => 'Hoja de vida documentada', 'LegajoDocumentoFecha' => $inicio];
            }
            if ($i % 3 === 0) {
                $filas[] = ['TrabajadorId' => $v->TrabajadorId, 'LegajoDocumentoTipo' => 'CAPACITACION', 'LegajoDocumentoNombre' => 'Certificado de capacitación en atención primaria', 'LegajoDocumentoFecha' => '2026-05-15'];
            }
        }
        $this->insertarDe100('Personal.LegajoDocumento', $filas);
    }

    private function movimientos($vinculos): void
    {
        $eess = DB::table('Organizacion.EstablecimientoSalud')->where('EessEstado', 1)->orderBy('EessId')->pluck('EessId')->values();
        $muestra = $vinculos->filter(fn ($v) => $v->VinculoLaboralId % 17 === 3)->values();

        foreach ($muestra->take(12) as $i => $v) {
            $destino = $eess->first(fn ($id) => $id !== $v->EessId && $id % 3 === $i % 3) ?? $eess->first(fn ($id) => $id !== $v->EessId);
            $tipo = ['ROTACION', 'DESTAQUE', 'ENCARGATURA'][$i % 3];
            $mes = 7 + ($i % 4);
            DB::table('Personal.DesplazamientoTemporal')->insert(['VinculoLaboralId' => $v->VinculoLaboralId, 'EessDestinoId' => $destino, 'DesplazamientoTemporalTipo' => $tipo,
                'DesplazamientoTemporalFechaInicio' => sprintf('2026-%02d-01', $mes), 'DesplazamientoTemporalFechaFin' => sprintf('2026-%02d-28', $mes),
                'DesplazamientoTemporalMotivo' => 'Cobertura de necesidad de servicio', 'DesplazamientoTemporalEstado' => 'CONCLUIDO']);
        }
        foreach ($vinculos->filter(fn ($v) => $v->VinculoLaboralId % 11 === 6)->values()->take(14) as $i => $v) {
            $recibe = $vinculos->first(fn ($o) => $o->EessId === $v->EessId && $o->VinculoLaboralId !== $v->VinculoLaboralId);
            DB::table('Personal.EntregaCargo')->insert(['VinculoLaboralId' => $v->VinculoLaboralId, 'VinculoLaboralRecibeId' => $recibe?->VinculoLaboralId, 'EntregaCargoMotivo' => $i % 2 ? 'LICENCIA' : 'VACACIONES',
                'EntregaCargoFecha' => sprintf('2026-%02d-%02d', 8 + ($i % 3), 5 + $i), 'EntregaCargoDetalle' => 'Entrega de pendientes, acervo documentario y bienes a cargo.']);
        }
    }

    private function avisosYAutorizaciones($vinculos): void
    {
        $motivos = ['Atención médica de emergencia', 'Hijo(a) enfermo(a)', 'Accidente de tránsito en la ruta al trabajo', 'Fallecimiento de un familiar'];
        $medios = ['TELEFONO', 'MENSAJE', 'PERSONAL', 'TELEFONO'];
        $usuario = DB::table('Seguridad.Usuario')->where('UsuarioNombre', 'gdangelo')->value('UsuarioId');
        $fechaBase = HoraLocal::ahora()->subDays(30);

        foreach ($vinculos->filter(fn ($v) => $v->VinculoLaboralId % 13 === 5)->values()->take(18) as $i => $v) {
            $fecha = $fechaBase->addDays($i % 28)->toDateString();
            DB::table('Asistencia.AvisoAusencia')->insert(['VinculoLaboralId' => $v->VinculoLaboralId, 'UsuarioId' => $usuario, 'AvisoAusenciaFecha' => $fecha, 'AvisoAusenciaFechaHora' => "{$fecha} 08:".sprintf('%02d', 5 + $i * 3 % 50).':00',
                'AvisoAusenciaMedio' => $medios[$i % 4], 'AvisoAusenciaMotivo' => $motivos[$i % 4], 'AvisoAusenciaDentroDePlazo' => $i % 7 === 6 ? 0 : 1]);
        }
        foreach ($vinculos->filter(fn ($v) => $v->VinculoLaboralId % 19 === 2)->values()->take(10) as $i => $v) {
            $fecha = $fechaBase->addDays($i * 3)->toDateString();
            DB::table('Asistencia.AutorizacionIngreso')->insert(['VinculoLaboralId' => $v->VinculoLaboralId, 'UsuarioId' => $usuario, 'AutorizacionIngresoFecha' => $fecha, 'AutorizacionIngresoFechaRegistro' => "{$fecha} 07:00:00",
                'AutorizacionIngresoMotivo' => $i % 2 ? 'Cierre de informe de producción' : 'Campaña de vacunación en día no laborable']);
        }
    }

    private function facilidadesTolerancias($vinculos): void
    {
        $usuario = DB::table('Seguridad.Usuario')->where('UsuarioNombre', 'gdangelo')->value('UsuarioId');
        $eess = DB::table('Organizacion.EstablecimientoSalud')->orderBy('EessId')->pluck('EessId');
        foreach ($vinculos->filter(fn ($v) => $v->VinculoLaboralId % 71 === 9)->values()->take(2) as $i => $v) {
            DB::table('Asistencia.FacilidadHoraria')->insert(['VinculoLaboralId' => $v->VinculoLaboralId, 'DocumentoSustentoId' => $this->documento('certificado', 9100 + $i, '2026-09-01'),
                'FacilidadHorariaMomento' => $i ? 'SALIDA' : 'INGRESO', 'FacilidadHorariaMinutos' => 60, 'FacilidadHorariaFechaInicio' => '2026-09-01', 'FacilidadHorariaFechaFin' => '2026-12-31', 'FacilidadHorariaObservacion' => 'Tratamiento supervisado.']);
        }
        DB::table('Asistencia.ToleranciaExtraordinaria')->insert(['EessId' => $eess[8] ?? $eess[0], 'UsuarioId' => $usuario, 'ToleranciaExtraordinariaFechaInicio' => '2026-08-18', 'ToleranciaExtraordinariaFechaFin' => '2026-08-18', 'ToleranciaExtraordinariaMinutos' => 90, 'ToleranciaExtraordinariaMotivo' => 'Paralización de transporte público en la provincia.']);
        DB::table('Biometria.ContingenciaMarcacion')->insert(['EessId' => $eess[2] ?? $eess[0], 'UsuarioId' => $usuario, 'ContingenciaMarcacionFechaInicio' => '2026-09-22', 'ContingenciaMarcacionFechaFin' => '2026-09-23', 'ContingenciaMarcacionMotivo' => 'Corte de fluido eléctrico: reloj facial fuera de servicio.']);
    }

    private function reconocimientos($vinculos): void
    {
        $tipos = ['FELICITACION', 'AGRADECIMIENTO', 'DIPLOMA'];
        foreach ($vinculos->filter(fn ($v) => $v->VinculoLaboralId % 23 === 4)->values()->take(9) as $i => $v) {
            DB::table('Disciplina.ReconocimientoServidor')->insert(['VinculoLaboralId' => $v->VinculoLaboralId, 'ReconocimientoServidorAnio' => 2026, 'ReconocimientoServidorTipo' => $tipos[$i % 3],
                'ReconocimientoServidorMotivo' => 'Puntualidad y responsabilidad en el primer semestre.', 'ReconocimientoServidorResolucion' => sprintf('RD N.° %04d-2026-UTES', 1800 + $i), 'ReconocimientoServidorFecha' => '2026-07-28']);
        }
    }

    /** @param  list<array<string,mixed>>  $filas */
    private function insertarDe100(string $tabla, array $filas): void
    {
        foreach (array_chunk($filas, 100) as $lote) {
            DB::table($tabla)->insert($lote);
        }
    }
}
