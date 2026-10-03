<?php

namespace Tests\Feature;

use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Tests\Support\Nivel3Modulos;

/**
 * Nivel 3, lote A: marcaciones, asistencia diaria, justificacion de faltas, carga de asistencia manual y notificaciones.
 * Los casos genericos vienen de CrudModulosTestCase; aqui van las reglas propias (RIT Art. 21, 22, 23, 24).
 */
class Nivel3AsistenciaTest extends CrudModulosTestCase
{
    protected static function especificaciones(): array
    {
        return Nivel3Modulos::loteA();
    }

    private function id(string $tabla, string $pk, array $filtro): int
    {
        return (int) DB::table($tabla)->where($filtro)->value($pk);
    }

    private function metodo(string $codigo): int
    {
        return $this->id('Biometria.MetodoMarcacion', 'MetodoMarcacionId', ['MetodoMarcacionCodigo' => $codigo]);
    }

    private function estado(string $codigo): int
    {
        return $this->id('Asistencia.EstadoAsistencia', 'EstadoAsistenciaId', ['EstadoAsistenciaCodigo' => $codigo]);
    }

    private function usuario(string $nombre = 'rvargas'): int
    {
        return $this->id('Seguridad.Usuario', 'UsuarioId', ['UsuarioNombre' => $nombre]);
    }

    private function trabajadorDe(int $vinculo): int
    {
        return (int) DB::table('Personal.VinculoLaboral')->where('VinculoLaboralId', $vinculo)->value('TrabajadorId');
    }

    private function autorizar(int $vinculo, string $metodo, string $inicio = '2026-01-01', ?string $fin = null, bool $activa = true): void
    {
        DB::table('Biometria.AutorizacionMetodo')->insert([
            'TrabajadorId' => $this->trabajadorDe($vinculo), 'MetodoMarcacionId' => $this->metodo($metodo),
            'AutorizacionMetodoFechaInicio' => $inicio, 'AutorizacionMetodoFechaFin' => $fin, 'AutorizacionMetodoEstado' => $activa ? 1 : 0,
        ]);
    }

    private function marcacion(int $vinculo, array $extra = []): array
    {
        return $extra + [
            'VinculoLaboralId' => $vinculo, 'MetodoMarcacionId' => $this->metodo('ROSTRO'),
            'MarcacionFechaHora' => '2026-09-21 07:30:00', 'MarcacionTipo' => 'ENTRADA',
        ];
    }

    private function carga(array $extra = []): int
    {
        return (int) DB::table('Asistencia.CargaAsistenciaManual')->insertGetId($extra + [
            'UsuarioId' => $this->usuario(), 'CargaAsistenciaManualEstado' => 'REGISTRADO',
        ], 'CargaAsistenciaManualId');
    }

    private function asistencia(int $vinculo, string $fecha, string $estado, array $extra = []): int
    {
        return (int) DB::table('Asistencia.AsistenciaDiaria')->insertGetId($extra + [
            'VinculoLaboralId' => $vinculo, 'EstadoAsistenciaId' => $this->estado($estado), 'AsistenciaDiariaFecha' => $fecha,
        ], 'AsistenciaDiariaId');
    }

    // ================================================================== Marcacion

    public function test_el_reconocimiento_facial_es_la_forma_de_registro_y_los_demas_metodos_piden_autorizacion(): void
    {
        $vinculo = $this->nuevoVinculo();

        // RIT Art. 21: unicamente reconocimiento facial; otro metodo, solo con autorizacion de Recursos Humanos.
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo))->assertCreated();

        $huella = $this->marcacion($vinculo, ['MetodoMarcacionId' => $this->metodo('HUELLA'), 'MarcacionFechaHora' => '2026-09-21 13:30:00', 'MarcacionTipo' => 'SALIDA']);
        $this->postJson('/api/marcaciones', $huella)->assertStatus(422)->assertJsonValidationErrors(['MetodoMarcacionId']);

        // Autorizacion vigente ese dia: ya se acepta.
        $this->autorizar($vinculo, 'HUELLA', '2026-01-01', '2026-12-31');
        $this->postJson('/api/marcaciones', $huella)->assertCreated()->assertJsonPath('data.origen', 'MANUAL');
        // Fuera de su vigencia, no.
        $this->postJson('/api/marcaciones', $huella + ['MarcacionFechaHora' => '2027-01-05 13:30:00'])->assertStatus(422);
    }

    public function test_una_autorizacion_vencida_o_revocada_no_habilita_el_metodo(): void
    {
        $vinculo = $this->nuevoVinculo();
        $this->autorizar($vinculo, 'TARJETA', '2026-01-01', '2026-06-30');
        $this->autorizar($vinculo, 'CLAVE', '2026-01-01', null, false);

        foreach (['TARJETA', 'CLAVE'] as $metodo) {
            $this->postJson('/api/marcaciones', $this->marcacion($vinculo, ['MetodoMarcacionId' => $this->metodo($metodo)]))
                ->assertStatus(422)->assertJsonValidationErrors(['MetodoMarcacionId']);
        }
    }

    public function test_el_registro_manual_sin_equipo_se_acepta_con_el_parte_diario_cargado(): void
    {
        $vinculo = $this->nuevoVinculo();
        $manual = $this->marcacion($vinculo, ['MetodoMarcacionId' => $this->metodo('MANUAL')]);

        $this->postJson('/api/marcaciones', $manual)->assertStatus(422)->assertJsonValidationErrors(['MetodoMarcacionId']);

        $carga = $this->carga();
        $this->postJson('/api/marcaciones', $manual + ['CargaAsistenciaManualId' => $carga])
            ->assertCreated()->assertJsonPath('data.origen', 'CARGA_MANUAL')->assertJsonPath('data.carga_asistencia_manual_id', $carga);
    }

    public function test_la_carga_de_la_marcacion_debe_ser_coherente(): void
    {
        $vinculo = $this->nuevoVinculo();
        $cargaOtroEess = $this->carga(['EessId' => $this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-EP-01'])]);
        $cargaAnulada = $this->carga(['CargaAsistenciaManualEstado' => 'ANULADO']);
        $manual = $this->marcacion($vinculo, ['MetodoMarcacionId' => $this->metodo('MANUAL')]);

        $this->postJson('/api/marcaciones', $manual + ['CargaAsistenciaManualId' => $cargaOtroEess])->assertStatus(422)->assertJsonValidationErrors(['CargaAsistenciaManualId']);
        $this->postJson('/api/marcaciones', $manual + ['CargaAsistenciaManualId' => $cargaAnulada])->assertStatus(422)->assertJsonValidationErrors(['CargaAsistenciaManualId']);
        // Un parte diario solo carga registros manuales.
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo) + ['CargaAsistenciaManualId' => $this->carga()])
            ->assertStatus(422)->assertJsonValidationErrors(['CargaAsistenciaManualId']);
    }

    public function test_la_plantilla_debe_ser_del_trabajador_y_del_tipo_del_metodo(): void
    {
        $vinculo = $this->nuevoVinculo();
        $ajena = $this->id('Biometria.PlantillaBiometrica', 'PlantillaBiometricaId', ['PlantillaBiometricaTipo' => 'ROSTRO']);   // de otro trabajador
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo, ['PlantillaBiometricaId' => $ajena]))
            ->assertStatus(422)->assertJsonValidationErrors(['PlantillaBiometricaId']);

        $propia = $this->conConsentimiento($this->trabajadorDe($vinculo));
        $plantilla = (int) DB::table('Biometria.PlantillaBiometrica')->insertGetId([
            'TrabajadorId' => $propia, 'PlantillaBiometricaTipo' => 'ROSTRO', 'PlantillaBiometricaEstado' => 1,
        ], 'PlantillaBiometricaId');
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo, ['PlantillaBiometricaId' => $plantilla]))->assertCreated();

        // Plantilla de rostro con el metodo huella (autorizado): no corresponde.
        $this->autorizar($vinculo, 'HUELLA');
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo, [
            'MetodoMarcacionId' => $this->metodo('HUELLA'), 'PlantillaBiometricaId' => $plantilla, 'MarcacionFechaHora' => '2026-09-21 13:30:00',
        ]))->assertStatus(422)->assertJsonValidationErrors(['PlantillaBiometricaId']);
    }

    public function test_no_se_admiten_marcaciones_duplicadas_y_se_acepta_la_fecha_del_navegador(): void
    {
        $vinculo = $this->nuevoVinculo();

        $this->postJson('/api/marcaciones', $this->marcacion($vinculo, ['MarcacionFechaHora' => '2026-09-21T07:30']))
            ->assertCreated()->assertJsonPath('data.fecha_hora', '2026-09-21 07:30:00')->assertJsonPath('data.origen', 'MANUAL');
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo))->assertStatus(422)->assertJsonValidationErrors(['MarcacionFechaHora']);
        // Otro tipo a la misma hora si es otro evento.
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo, ['MarcacionTipo' => 'SALIDA']))->assertCreated();
    }

    public function test_el_origen_por_omision_depende_de_donde_vino_la_marcacion(): void
    {
        $vinculo = $this->nuevoVinculo();
        $dispositivo = $this->id('Biometria.DispositivoMarcacion', 'DispositivoMarcacionId', ['DispositivoMarcacionCodigo' => 'DISP-LE-02']);

        $this->postJson('/api/marcaciones', $this->marcacion($vinculo, ['DispositivoMarcacionId' => $dispositivo]))->assertCreated()->assertJsonPath('data.origen', 'DISPOSITIVO');
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo, ['MarcacionTipo' => 'SALIDA', 'MarcacionOrigen' => 'API']))->assertCreated()->assertJsonPath('data.origen', 'API');
        // Un dispositivo dado de baja no se asigna.
        $retirado = $this->id('Biometria.DispositivoMarcacion', 'DispositivoMarcacionId', ['DispositivoMarcacionCodigo' => 'DISP-EP-99']);
        $this->postJson('/api/marcaciones', $this->marcacion($vinculo, ['DispositivoMarcacionId' => $retirado, 'MarcacionTipo' => 'SALIDA_REFRIGERIO']))
            ->assertStatus(422)->assertJsonValidationErrors(['DispositivoMarcacionId']);
    }

    public function test_invalidar_una_marcacion_y_reactivarla(): void
    {
        $vinculo = $this->nuevoVinculo();
        $id = $this->postJson('/api/marcaciones', $this->marcacion($vinculo))->assertCreated()->json('data.id');

        $this->deleteJson("/api/marcaciones/{$id}")->assertOk()->assertJsonPath('mensaje', 'Marcación invalidada.');
        $this->getJson("/api/marcaciones/{$id}")->assertJsonPath('data.es_valida', false)->assertJsonPath('data.activo', false);
        $this->patchJson("/api/marcaciones/{$id}", ['MarcacionEsValida' => true])->assertOk()->assertJsonPath('data.es_valida', true);
        // Una marcacion del periodo cerrado ya no se toca (se inserta directo, como si fuera historica).
        $historica = (int) DB::table('Asistencia.Marcacion')->insertGetId($this->marcacion($vinculo, ['MarcacionFechaHora' => '2026-08-03 07:30:00']), 'MarcacionId');
        $this->patchJson("/api/marcaciones/{$historica}", ['MarcacionObservacion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['MarcacionFechaHora']);
    }

    public function test_listado_de_marcaciones_filtros_y_datos_sembrados(): void
    {
        $r = $this->getJson('/api/marcaciones?por_pagina=100')->assertOk();
        $this->assertSame(20, $r->json('meta.total'));
        $this->getJson('/api/marcaciones?buscar=Quispe&por_pagina=100')->assertOk()->assertJsonPath('data.0.trabajador.nombre_completo', 'Quispe Huamán, María Elena');
        $this->getJson('/api/marcaciones?valida=0')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/marcaciones?tipo=SALIDA_PAPELETA')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.observacion', 'Comisión de servicio');
        $this->getJson('/api/marcaciones?desde=2026-09-29&hasta=2026-09-29&por_pagina=100')->assertOk()->assertJsonCount(8, 'data');
        $this->getJson('/api/marcaciones?metodo_marcacion_id='.$this->metodo('HUELLA'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/marcaciones?eess_id='.$this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LA-01']).'&por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        // La marcacion de la app trae geolocalizacion.
        $this->getJson('/api/marcaciones?metodo_marcacion_id='.$this->metodo('APP'))->assertOk()->assertJsonPath('data.0.geolocalizacion', '-8.1116,-79.0288');
    }

    // ================================================================== Carga de asistencia manual

    public function test_anular_la_carga_invalida_sus_marcaciones(): void
    {
        $vinculo = $this->nuevoVinculo();
        $carga = $this->carga(['CargaAsistenciaManualRegistros' => 2]);
        $otra = $this->carga();
        foreach (['07:30:00', '13:30:00'] as $i => $hora) {
            DB::table('Asistencia.Marcacion')->insert($this->marcacion($vinculo, [
                'MetodoMarcacionId' => $this->metodo('MANUAL'), 'CargaAsistenciaManualId' => $carga,
                'MarcacionFechaHora' => "2026-09-21 {$hora}", 'MarcacionTipo' => $i === 0 ? 'ENTRADA' : 'SALIDA',
            ]));
        }
        DB::table('Asistencia.Marcacion')->insert($this->marcacion($vinculo, ['MetodoMarcacionId' => $this->metodo('MANUAL'), 'CargaAsistenciaManualId' => $otra, 'MarcacionFechaHora' => '2026-09-22 07:30:00']));

        $this->getJson("/api/cargas-asistencia-manual/{$carga}")->assertJsonPath('data.marcaciones_registradas', 2);
        $this->deleteJson("/api/cargas-asistencia-manual/{$carga}")->assertOk()->assertJsonPath('mensaje', 'Carga anulada.');

        $this->assertSame(0, DB::table('Asistencia.Marcacion')->where('CargaAsistenciaManualId', $carga)->where('MarcacionEsValida', 1)->count());
        $this->assertSame(1, DB::table('Asistencia.Marcacion')->where('CargaAsistenciaManualId', $otra)->where('MarcacionEsValida', 1)->count());
        $this->deleteJson("/api/cargas-asistencia-manual/{$carga}")->assertOk();   // idempotente
        // Anulada: ya no se modifica.
        $this->patchJson("/api/cargas-asistencia-manual/{$carga}", ['CargaAsistenciaManualObservacion' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['CargaAsistenciaManualEstado']);
    }

    public function test_el_estado_de_la_carga_exige_sus_datos(): void
    {
        $id = $this->postJson('/api/cargas-asistencia-manual', ['UsuarioId' => $this->usuario()])->assertCreated()->assertJsonPath('data.estado', 'REGISTRADO')->json('data.id');

        $this->patchJson("/api/cargas-asistencia-manual/{$id}", ['CargaAsistenciaManualEstado' => 'PROCESADO'])
            ->assertStatus(422)->assertJsonValidationErrors(['CargaAsistenciaManualRegistros']);
        $this->patchJson("/api/cargas-asistencia-manual/{$id}", ['CargaAsistenciaManualEstado' => 'OBSERVADO'])
            ->assertStatus(422)->assertJsonValidationErrors(['CargaAsistenciaManualObservacion']);
        $this->patchJson("/api/cargas-asistencia-manual/{$id}", ['CargaAsistenciaManualEstado' => 'PROCESADO', 'CargaAsistenciaManualRegistros' => 12])
            ->assertOk()->assertJsonPath('data.estado', 'PROCESADO');
        $this->postJson('/api/cargas-asistencia-manual', ['UsuarioId' => $this->usuario(), 'CargaAsistenciaManualEstado' => 'PROCESADO', 'CargaAsistenciaManualRegistros' => 3])
            ->assertStatus(422)->assertJsonValidationErrors(['CargaAsistenciaManualEstado']);
    }

    public function test_datos_sembrados_y_filtros_de_cargas(): void
    {
        $this->getJson('/api/cargas-asistencia-manual?por_pagina=100')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/cargas-asistencia-manual?estado=ANULADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activo', false)->assertJsonPath('data.0.marcaciones_registradas', 2);
        $this->getJson('/api/cargas-asistencia-manual?buscar=parte-sede')->assertOk()->assertJsonPath('data.0.estado', 'OBSERVADO');
        $this->getJson('/api/cargas-asistencia-manual?eess_id='.$this->id('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LA-01']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.marcaciones_registradas', 4)->assertJsonPath('data.0.documento.nombre', 'foto-constatación-0005.jpg');
    }

    // ================================================================== Asistencia diaria

    public function test_los_minutos_trabajados_se_calculan_de_la_entrada_y_la_salida(): void
    {
        $vinculo = $this->nuevoVinculo();
        $base = ['VinculoLaboralId' => $vinculo, 'EstadoAsistenciaId' => $this->estado('ASISTIO'), 'AsistenciaDiariaFecha' => '2026-09-21'];

        $id = $this->postJson('/api/asistencia-diaria', $base + ['AsistenciaDiariaHoraEntrada' => '2026-09-21T07:30', 'AsistenciaDiariaHoraSalida' => '2026-09-21T13:30'])
            ->assertCreated()->assertJsonPath('data.minutos_trabajados', 360)->assertJsonPath('data.hora_entrada', '2026-09-21 07:30:00')->json('data.id');
        $this->assertNotNull($this->getJson("/api/asistencia-diaria/{$id}")->json('data.fecha_proceso'));

        // Al cambiar la salida se recalcula; indicando los minutos, se respetan (p. ej. descontando el refrigerio).
        $this->patchJson("/api/asistencia-diaria/{$id}", ['AsistenciaDiariaHoraSalida' => '2026-09-21 14:00:00'])->assertOk()->assertJsonPath('data.minutos_trabajados', 390);
        $this->patchJson("/api/asistencia-diaria/{$id}", ['AsistenciaDiariaMinutosTrabajados' => 345])->assertOk()->assertJsonPath('data.minutos_trabajados', 345);

        // La guardia nocturna sale al dia siguiente.
        $noche = $this->nuevoVinculo();
        $this->postJson('/api/asistencia-diaria', ['VinculoLaboralId' => $noche] + $base + ['AsistenciaDiariaHoraEntrada' => '2026-09-21 19:30:00', 'AsistenciaDiariaHoraSalida' => '2026-09-22 07:30:00'])
            ->assertCreated()->assertJsonPath('data.minutos_trabajados', 720);
    }

    public function test_la_justificacion_enlazada_debe_estar_aprobada_y_cubrir_la_fecha(): void
    {
        $vinculo = $this->nuevoVinculo();
        $justificacion = fn (string $estado, ?int $v = null) => (int) DB::table('Asistencia.JustificacionFalta')->insertGetId([
            'VinculoLaboralId' => $v ?? $vinculo, 'ConceptoJustificacionId' => $this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']),
            'UsuarioRegistroId' => $this->usuario(), 'JustificacionFaltaFechaInicio' => '2026-09-21', 'JustificacionFaltaFechaFin' => '2026-09-22', 'JustificacionFaltaEstado' => $estado,
        ], 'JustificacionFaltaId');
        $base = ['VinculoLaboralId' => $vinculo, 'EstadoAsistenciaId' => $this->estado('FALTA_JUST'), 'AsistenciaDiariaFecha' => '2026-09-21'];

        $this->postJson('/api/asistencia-diaria', $base + ['JustificacionFaltaId' => $justificacion('PENDIENTE')])->assertStatus(422)->assertJsonValidationErrors(['JustificacionFaltaId']);
        $this->postJson('/api/asistencia-diaria', $base + ['JustificacionFaltaId' => $justificacion('APROBADO', $this->nuevoVinculo())])->assertStatus(422)->assertJsonValidationErrors(['JustificacionFaltaId']);
        $this->postJson('/api/asistencia-diaria', ['AsistenciaDiariaFecha' => '2026-09-25'] + $base + ['JustificacionFaltaId' => $justificacion('APROBADO')])->assertStatus(422);
        $this->postJson('/api/asistencia-diaria', $base + ['JustificacionFaltaId' => $justificacion('APROBADO')])->assertCreated();
    }

    public function test_filtros_y_datos_sembrados_de_asistencia_diaria(): void
    {
        $this->getJson('/api/asistencia-diaria?por_pagina=100')->assertOk()->assertJsonCount(14, 'data');
        // Faltas sin justificar (estado EsFalta): la omision de marcacion de VL-0011 y las dos faltas de VL-0006.
        $r = $this->getJson('/api/asistencia-diaria?sin_justificar=1&por_pagina=100')->assertOk();
        $this->assertSame(['OMISION_MARCA', 'FALTA', 'FALTA'], array_column(array_column($r->json('data'), 'estado'), 'codigo'));
        $this->getJson('/api/asistencia-diaria?estado_asistencia_id='.$this->estado('TARDANZA'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.minutos_tardanza', 11)->assertJsonPath('data.0.trabajador.numero_documento', '70000004');
        $this->getJson('/api/asistencia-diaria?buscar=Quispe')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/asistencia-diaria?desde=2026-09-29&hasta=2026-09-29&por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame(1, DB::table('Asistencia.AsistenciaDiaria')->whereNotNull('JustificacionFaltaId')->count());
    }

    // ================================================================== Justificacion de faltas

    public function test_aprobar_justifica_las_faltas_de_esos_dias_y_deja_el_resto_intacto(): void
    {
        $vinculo = $this->nuevoVinculo();
        $falta1 = $this->asistencia($vinculo, '2026-09-21', 'FALTA');
        $falta2 = $this->asistencia($vinculo, '2026-09-22', 'FALTA');
        $asistio = $this->asistencia($vinculo, '2026-09-23', 'ASISTIO');
        $fuera = $this->asistencia($vinculo, '2026-09-25', 'FALTA');
        $id = $this->postJson('/api/justificaciones-falta', [
            'VinculoLaboralId' => $vinculo, 'ConceptoJustificacionId' => $this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']),
            'UsuarioRegistroId' => $this->usuario(), 'JustificacionFaltaFechaInicio' => '2026-09-21', 'JustificacionFaltaFechaFin' => '2026-09-23',
        ])->assertCreated()->assertJsonPath('data.estado', 'PENDIENTE')->json('data.id');

        $r = $this->postJson("/api/justificaciones-falta/{$id}/aprobar", ['UsuarioId' => $this->usuario('pgutierrez'), 'Motivo' => 'Constancia presentada'])->assertOk();
        $r->assertJsonPath('data.estado', 'APROBADO')->assertJsonPath('data.usuario_resolucion.nombre', 'pgutierrez')->assertJsonPath('asistencias_actualizadas', 2)
            ->assertJsonPath('data.observacion', 'Constancia presentada');
        $this->assertNotNull($r->json('data.fecha_resolucion'));

        $justa = $this->estado('FALTA_JUST');
        foreach ([$falta1, $falta2] as $f) {
            $fila = DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaId', $f)->first();
            $this->assertSame($justa, (int) $fila->EstadoAsistenciaId);
            $this->assertSame($id, (int) $fila->JustificacionFaltaId);
        }
        $this->assertSame($this->estado('ASISTIO'), (int) DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaId', $asistio)->value('EstadoAsistenciaId'));
        $this->assertSame($this->estado('FALTA'), (int) DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaId', $fuera)->value('EstadoAsistenciaId'));

        // Resuelta: no se aprueba ni rechaza otra vez, ni se modifica.
        $this->postJson("/api/justificaciones-falta/{$id}/aprobar", ['UsuarioId' => $this->usuario('pgutierrez')])->assertStatus(422);
        $this->postJson("/api/justificaciones-falta/{$id}/rechazar", ['UsuarioId' => $this->usuario('pgutierrez'), 'Motivo' => 'x'])->assertStatus(422);
        $this->patchJson("/api/justificaciones-falta/{$id}", ['JustificacionFaltaObservacion' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['JustificacionFaltaEstado']);

        // El registrador recibe un aviso.
        $aviso = DB::table('Soporte.Notificacion')->where('UsuarioId', $this->usuario())->where('NotificacionTipo', 'JUSTIFICACION_APROBADA')->latest('NotificacionId')->first();
        $this->assertNotNull($aviso);
        $this->assertSame(0, (int) $aviso->NotificacionLeida);
    }

    public function test_rechazar_exige_motivo_y_no_toca_la_asistencia(): void
    {
        $vinculo = $this->nuevoVinculo();
        $falta = $this->asistencia($vinculo, '2026-09-21', 'FALTA');
        $id = $this->postJson('/api/justificaciones-falta', [
            'VinculoLaboralId' => $vinculo, 'ConceptoJustificacionId' => $this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']),
            'UsuarioRegistroId' => $this->usuario(), 'JustificacionFaltaFechaInicio' => '2026-09-21', 'JustificacionFaltaFechaFin' => '2026-09-21',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/justificaciones-falta/{$id}/rechazar", ['UsuarioId' => $this->usuario('pgutierrez')])->assertStatus(422)->assertJsonValidationErrors(['Motivo']);
        $this->postJson("/api/justificaciones-falta/{$id}/rechazar", ['Motivo' => 'Sin sustento'])->assertStatus(422)->assertJsonValidationErrors(['UsuarioId']);
        $this->postJson("/api/justificaciones-falta/{$id}/rechazar", ['UsuarioId' => 999999, 'Motivo' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['UsuarioId']);
        $this->postJson("/api/justificaciones-falta/{$id}/rechazar", ['UsuarioId' => $this->usuario('pgutierrez'), 'Motivo' => 'Sin sustento'])
            ->assertOk()->assertJsonPath('data.estado', 'RECHAZADO')->assertJsonPath('data.motivo_rechazo', 'Sin sustento');

        $this->assertNull(DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaId', $falta)->value('JustificacionFaltaId'));
        $this->assertSame($this->estado('FALTA'), (int) DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaId', $falta)->value('EstadoAsistenciaId'));
        // Una rechazada ya no cuenta para la superposicion: se puede volver a presentar.
        $this->postJson('/api/justificaciones-falta', [
            'VinculoLaboralId' => $vinculo, 'ConceptoJustificacionId' => $this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']),
            'UsuarioRegistroId' => $this->usuario(), 'JustificacionFaltaFechaInicio' => '2026-09-21', 'JustificacionFaltaFechaFin' => '2026-09-21',
        ])->assertCreated();
    }

    public function test_anular_una_justificacion_aprobada_devuelve_las_faltas(): void
    {
        $vinculo = $this->nuevoVinculo();
        $falta = $this->asistencia($vinculo, '2026-09-21', 'FALTA');
        $id = $this->postJson('/api/justificaciones-falta', [
            'VinculoLaboralId' => $vinculo, 'ConceptoJustificacionId' => $this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']),
            'UsuarioRegistroId' => $this->usuario(), 'JustificacionFaltaFechaInicio' => '2026-09-21', 'JustificacionFaltaFechaFin' => '2026-09-21',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/justificaciones-falta/{$id}/aprobar", ['UsuarioId' => $this->usuario('pgutierrez')])->assertOk();

        $this->deleteJson("/api/justificaciones-falta/{$id}")->assertOk()->assertJsonPath('mensaje', 'Justificación anulada.');
        $fila = DB::table('Asistencia.AsistenciaDiaria')->where('AsistenciaDiariaId', $falta)->first();
        $this->assertSame($this->estado('FALTA'), (int) $fila->EstadoAsistenciaId);
        $this->assertNull($fila->JustificacionFaltaId);
        $this->getJson("/api/justificaciones-falta/{$id}")->assertJsonPath('data.estado', 'ANULADO')->assertJsonPath('data.activo', false);
        $this->deleteJson("/api/justificaciones-falta/{$id}")->assertOk();   // idempotente
    }

    public function test_no_se_anula_una_justificacion_con_faltas_en_un_periodo_cerrado(): void
    {
        $vinculo = $this->nuevoVinculo();
        $justificacion = (int) DB::table('Asistencia.JustificacionFalta')->insertGetId([
            'VinculoLaboralId' => $vinculo, 'ConceptoJustificacionId' => $this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']),
            'UsuarioRegistroId' => $this->usuario(), 'JustificacionFaltaFechaInicio' => '2026-08-10', 'JustificacionFaltaFechaFin' => '2026-08-10', 'JustificacionFaltaEstado' => 'APROBADO',
        ], 'JustificacionFaltaId');
        $this->asistencia($vinculo, '2026-08-10', 'FALTA_JUST', ['JustificacionFaltaId' => $justificacion]);

        $this->deleteJson("/api/justificaciones-falta/{$justificacion}")->assertStatus(422);
        $this->assertSame('APROBADO', DB::table('Asistencia.JustificacionFalta')->where('JustificacionFaltaId', $justificacion)->value('JustificacionFaltaEstado'));
    }

    public function test_el_concepto_puede_exigir_documento_de_sustento(): void
    {
        $vinculo = $this->nuevoVinculo();
        $datos = [
            'VinculoLaboralId' => $vinculo, 'ConceptoJustificacionId' => $this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'DESCANSO_MED']),
            'UsuarioRegistroId' => $this->usuario(), 'JustificacionFaltaFechaInicio' => '2026-09-21', 'JustificacionFaltaFechaFin' => '2026-09-21',
        ];
        $documento = $this->id('Soporte.DocumentoSustento', 'DocumentoSustentoId', ['DocumentoSustentoNombre' => 'certificado-medico-0001.pdf']);

        $this->postJson('/api/justificaciones-falta', $datos)->assertStatus(422)->assertJsonValidationErrors(['DocumentoSustentoId']);
        $this->postJson('/api/justificaciones-falta', $datos + ['DocumentoSustentoId' => $documento])->assertCreated()->assertJsonPath('data.documento.nombre', 'certificado-medico-0001.pdf');
    }

    public function test_un_dia_admite_a_lo_sumo_una_justificacion(): void
    {
        $vinculo = $this->nuevoVinculo();
        $datos = fn (string $i, string $f) => [
            'VinculoLaboralId' => $vinculo, 'ConceptoJustificacionId' => $this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']),
            'UsuarioRegistroId' => $this->usuario(), 'JustificacionFaltaFechaInicio' => $i, 'JustificacionFaltaFechaFin' => $f,
        ];

        $primera = $this->postJson('/api/justificaciones-falta', $datos('2026-09-21', '2026-09-23'))->assertCreated()->json('data.id');
        $this->postJson('/api/justificaciones-falta', $datos('2026-09-23', '2026-09-24'))->assertStatus(422)->assertJsonValidationErrors(['JustificacionFaltaFechaInicio']);
        $this->postJson('/api/justificaciones-falta', $datos('2026-09-24', '2026-09-25'))->assertCreated();
        // Al editar la primera para que invada la segunda, tambien se rechaza.
        $this->patchJson("/api/justificaciones-falta/{$primera}", ['JustificacionFaltaFechaFin' => '2026-09-24'])->assertStatus(422);
    }

    public function test_filtros_y_datos_sembrados_de_justificaciones(): void
    {
        $this->getJson('/api/justificaciones-falta?por_pagina=100')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/justificaciones-falta?estado=PENDIENTE')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/justificaciones-falta?estado=RECHAZADO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.motivo_rechazo', 'El reloj funcionó con normalidad esos días según el reporte técnico');
        $this->getJson('/api/justificaciones-falta?buscar=CITT-4471')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'APROBADO')->assertJsonPath('data.0.usuario_resolucion.nombre', 'pgutierrez');
        $this->getJson('/api/justificaciones-falta?concepto_justificacion_id='.$this->id('Asistencia.ConceptoJustificacion', 'ConceptoJustificacionId', ['ConceptoJustificacionCodigo' => 'EMERGENCIA']))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/justificaciones-falta?desde=2026-09-01&hasta=2026-09-30&por_pagina=100')->assertOk()->assertJsonCount(5, 'data');
    }

    // ================================================================== Notificacion

    public function test_marcar_una_notificacion_como_leida_y_filtrar(): void
    {
        $usuario = $this->usuario('gdangelo');
        $id = $this->postJson('/api/notificaciones', ['UsuarioId' => $usuario, 'NotificacionTipo' => 'GENERAL', 'NotificacionTitulo' => 'Hola', 'NotificacionMensaje' => 'Mundo'])
            ->assertCreated()->assertJsonPath('data.leida', false)->assertJsonPath('data.usuario.nombre', 'gdangelo')->json('data.id');
        $this->assertStringStartsWith(HoraLocal::hoy()->format('Y-m-d'), $this->getJson("/api/notificaciones/{$id}")->json('data.fecha'));

        $this->getJson("/api/notificaciones?usuario_id={$usuario}&leida=0&por_pagina=100")->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson("/api/notificaciones/{$id}", ['NotificacionLeida' => true])->assertOk()->assertJsonPath('data.leida', true);
        $this->getJson("/api/notificaciones?usuario_id={$usuario}&leida=0&por_pagina=100")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/notificaciones?buscar=Cierre de asistencia')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/notificaciones?tipo=JUSTIFICACION_RECHAZADA')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/notificaciones/{$id}")->assertOk();
        $this->getJson("/api/notificaciones/{$id}")->assertNotFound();
    }
}
