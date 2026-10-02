<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Tests\Feature\CrudModulosTestCase;

/**
 * Especificacion de los modulos de Nivel 2 con el formato de Nivel1Modulos. En `fk`, una funcion crea al vuelo
 * el trabajador (u otra fila) que el modulo necesita, para no chocar con los trabajadores sembrados.
 * ConsentimientoBiometrico NO esta aqui: es un historial inmutable (sin PUT/PATCH/DELETE) y se prueba aparte.
 */
final class Nivel2Modulos
{
    private static function sembrado(string $tabla, string $pk, array $filtro): array
    {
        return [$tabla, $pk, $filtro];
    }

    /** Trabajador nuevo con la profesion indicada (codigo) para que coincida con el colegio. */
    public static function trabajadorConProfesion(string $codigo): \Closure
    {
        return fn (CrudModulosTestCase $t) => $t->nuevoTrabajador([
            'ProfesionId' => DB::table('Personal.Profesion')->where('ProfesionCodigo', $codigo)->value('ProfesionId'),
        ]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $nuevoTrabajador = fn (CrudModulosTestCase $t) => $t->nuevoTrabajador();
        $trabajadorConConsentimiento = fn (CrudModulosTestCase $t) => $t->conConsentimiento($t->nuevoTrabajador());

        return [
            'vinculos-laborales' => [
                'endpoint' => '/api/vinculos-laborales', 'table' => 'Personal.VinculoLaboral', 'pk' => 'VinculoLaboralId',
                'estado' => 'VinculoLaboralEstado',
                'fk' => [
                    'TrabajadorId' => $nuevoTrabajador,
                    'EessId' => self::sembrado('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']),
                    // DESTACADO no exige AIRHSP; las condiciones que si lo exigen se prueban aparte.
                    'CondicionLaboralId' => self::sembrado('Personal.CondicionLaboral', 'CondicionLaboralId', ['CondicionLaboralCodigo' => 'DESTACADO']),
                    'RegimenLaboralId' => self::sembrado('Personal.RegimenLaboral', 'RegimenLaboralId', ['RegimenLaboralCodigo' => 'OTRO']),
                    'CargoId' => self::sembrado('Personal.Cargo', 'CargoId', ['CargoNombre' => 'Contador(a)']),
                ],
                'create' => [
                    'VinculoLaboralCodigo' => 'ZZ-VL', 'VinculoLaboralCodigoAirhsp' => '9000001', 'VinculoLaboralNumeroPlaza' => 'P-ZZ-1',
                    'VinculoLaboralFechaInicio' => '2026-01-01',
                ],
                'keys' => ['id', 'trabajador_id', 'eess_id', 'regimen_laboral_id', 'condicion_laboral_id', 'cargo_id', 'codigo', 'codigo_airhsp',
                    'numero_plaza', 'fecha_inicio', 'fecha_fin', 'motivo_cese', 'vigente', 'activo', 'trabajador', 'eess', 'regimen', 'condicion', 'cargo'],
                'required' => ['TrabajadorId', 'EessId', 'RegimenLaboralId', 'CondicionLaboralId', 'CargoId', 'VinculoLaboralFechaInicio'],
                'unique' => ['VinculoLaboralCodigo', 'VinculoLaboralCodigoAirhsp'],
                'maxlen' => ['VinculoLaboralCodigo' => 50, 'VinculoLaboralCodigoAirhsp' => 20, 'VinculoLaboralNumeroPlaza' => 30, 'VinculoLaboralMotivoCese' => 300],
                'patch' => ['VinculoLaboralNumeroPlaza' => 'P-ZZ-2'], 'patchKey' => 'numero_plaza',
                'invalid' => [
                    [['VinculoLaboralCodigo' => 'con espacios'], 'VinculoLaboralCodigo'],
                    [['VinculoLaboralCodigoAirhsp' => 'AB-12'], 'VinculoLaboralCodigoAirhsp'],
                    [['VinculoLaboralNumeroPlaza' => '***'], 'VinculoLaboralNumeroPlaza'],
                    [['VinculoLaboralFechaInicio' => '01/01/2026'], 'VinculoLaboralFechaInicio'],
                    [['VinculoLaboralFechaFin' => '31/12/2026'], 'VinculoLaboralFechaFin'],
                    [['VinculoLaboralFechaFin' => '2025-12-31'], 'VinculoLaboralFechaFin'],
                    [['VinculoLaboralMotivoCese' => 'Renuncia'], 'VinculoLaboralMotivoCese'],
                    [['TrabajadorId' => 999999], 'TrabajadorId'],
                    [['EessId' => 999999], 'EessId'],
                    [['RegimenLaboralId' => 999999], 'RegimenLaboralId'],
                    [['CondicionLaboralId' => 999999], 'CondicionLaboralId'],
                    [['CargoId' => 'abc'], 'CargoId'],
                    [['VinculoLaboralEstado' => 'quizas'], 'VinculoLaboralEstado'],
                ],
            ],
            'usuarios' => [
                'endpoint' => '/api/usuarios', 'table' => 'Seguridad.Usuario', 'pk' => 'UsuarioId', 'estado' => 'UsuarioEstado',
                'fk' => ['TrabajadorId' => $nuevoTrabajador],
                'create' => [
                    'UsuarioNombre' => 'zz.usuario', 'UsuarioPassword' => 'Clave2026', 'UsuarioPasswordConfirmacion' => 'Clave2026',
                    'UsuarioCorreo' => 'zz@ejemplo.pe',
                ],
                'keys' => ['id', 'trabajador_id', 'nombre', 'correo', 'fecha_creacion', 'activo', 'trabajador'],
                'required' => ['TrabajadorId', 'UsuarioNombre', 'UsuarioPassword'],
                'unique' => ['TrabajadorId', 'UsuarioNombre', 'UsuarioCorreo'],
                'maxlen' => ['UsuarioNombre' => 100, 'UsuarioCorreo' => 200],
                'patch' => ['UsuarioCorreo' => 'otro@ejemplo.pe'], 'patchKey' => 'correo',
                'invalid' => [
                    [['UsuarioNombre' => 'Con Espacios'], 'UsuarioNombre'],
                    [['UsuarioNombre' => 'abc'], 'UsuarioNombre'],
                    [['UsuarioNombre' => 'ñandú.01'], 'UsuarioNombre'],
                    [['UsuarioPassword' => 'corta1', 'UsuarioPasswordConfirmacion' => 'corta1'], 'UsuarioPassword'],
                    [['UsuarioPassword' => 'sololetrasaqui', 'UsuarioPasswordConfirmacion' => 'sololetrasaqui'], 'UsuarioPassword'],
                    [['UsuarioPassword' => '1234567890', 'UsuarioPasswordConfirmacion' => '1234567890'], 'UsuarioPassword'],
                    [['UsuarioPasswordConfirmacion' => 'OtraClave2026'], 'UsuarioPasswordConfirmacion'],
                    [['UsuarioCorreo' => 'a@b'], 'UsuarioCorreo'],
                    [['UsuarioCorreo' => 'no-es-correo'], 'UsuarioCorreo'],
                    [['TrabajadorId' => 999999], 'TrabajadorId'],
                    [['UsuarioEstado' => 'quizas'], 'UsuarioEstado'],
                ],
            ],
            'horarios-detalle' => [
                'endpoint' => '/api/horarios-detalle', 'table' => 'Configuracion.HorarioDetalle', 'pk' => 'HorarioDetalleId', 'estado' => null,
                // HOR-ESS-M trabaja el turno M de lunes a sabado: el domingo con el turno T esta libre.
                'fk' => [
                    'HorarioId' => self::sembrado('Configuracion.Horario', 'HorarioId', ['HorarioCodigo' => 'HOR-ESS-M']),
                    'TurnoId' => self::sembrado('Configuracion.Turno', 'TurnoId', ['TurnoCodigo' => 'T']),
                ],
                'create' => ['HorarioDetalleDia' => 7, 'HorarioDetalleOrden' => 1, 'HorarioDetalleEsDescanso' => false],
                'keys' => ['id', 'horario_id', 'turno_id', 'dia', 'dia_nombre', 'orden', 'es_descanso', 'horario', 'turno'],
                'required' => ['HorarioId', 'TurnoId', 'HorarioDetalleDia'],
                'unique' => [], 'uniqueComposite' => 'TurnoId',
                'maxlen' => [],
                'patch' => ['HorarioDetalleEsDescanso' => true], 'patchKey' => 'es_descanso',
                'invalid' => [
                    [['HorarioDetalleDia' => 0], 'HorarioDetalleDia'],
                    [['HorarioDetalleDia' => 8], 'HorarioDetalleDia'],
                    [['HorarioDetalleDia' => 'lunes'], 'HorarioDetalleDia'],
                    [['HorarioDetalleOrden' => 0], 'HorarioDetalleOrden'],
                    [['HorarioDetalleOrden' => 11], 'HorarioDetalleOrden'],
                    [['HorarioDetalleEsDescanso' => 'x'], 'HorarioDetalleEsDescanso'],
                    [['HorarioId' => 999999], 'HorarioId'],
                    [['TurnoId' => 999999], 'TurnoId'],
                ],
            ],
            'colegiaturas' => [
                'endpoint' => '/api/colegiaturas', 'table' => 'Personal.Colegiatura', 'pk' => 'ColegiaturaId', 'estado' => 'ColegiaturaEstado',
                'fk' => [
                    'TrabajadorId' => self::trabajadorConProfesion('ENFERMERIA'),
                    'ColegiaturaTipoId' => self::sembrado('Personal.ColegiaturaTipo', 'ColegiaturaTipoId', ['ColegiaturaTipoCodigo' => 'CEP']),
                ],
                'create' => [
                    'ColegiaturaNumero' => 'ZZ0001', 'ColegiaturaFechaColegiatura' => '2015-01-10', 'ColegiaturaFechaHabilitacion' => '2026-01-01',
                    'ColegiaturaFechaVencimiento' => '2026-12-31', 'ColegiaturaEsHabilitado' => true, 'ColegiaturaEsPrincipal' => true,
                    'ColegiaturaObservacion' => 'Prueba',
                ],
                'keys' => ['id', 'trabajador_id', 'colegiatura_tipo_id', 'documento_sustento_id', 'numero', 'fecha_colegiatura', 'fecha_habilitacion',
                    'fecha_vencimiento', 'es_habilitado', 'es_principal', 'observacion', 'vencida', 'vigente', 'activo', 'trabajador', 'tipo', 'documento'],
                'required' => ['TrabajadorId', 'ColegiaturaTipoId', 'ColegiaturaNumero'],
                'unique' => [], 'uniqueComposite' => 'ColegiaturaTipoId',
                'maxlen' => ['ColegiaturaNumero' => 30, 'ColegiaturaObservacion' => 500],
                'patch' => ['ColegiaturaObservacion' => 'Actualizada'], 'patchKey' => 'observacion',
                'invalid' => [
                    [['ColegiaturaNumero' => 'AB-12'], 'ColegiaturaNumero'],
                    [['ColegiaturaFechaColegiatura' => '2999-01-01'], 'ColegiaturaFechaColegiatura'],
                    [['ColegiaturaFechaColegiatura' => '10/01/2015'], 'ColegiaturaFechaColegiatura'],
                    [['ColegiaturaFechaHabilitacion' => '2014-01-01'], 'ColegiaturaFechaHabilitacion'],
                    [['ColegiaturaFechaVencimiento' => '2025-12-31'], 'ColegiaturaFechaVencimiento'],
                    [['ColegiaturaEsHabilitado' => 'x'], 'ColegiaturaEsHabilitado'],
                    [['ColegiaturaEsPrincipal' => 'x'], 'ColegiaturaEsPrincipal'],
                    [['ColegiaturaTipoId' => 999999], 'ColegiaturaTipoId'],
                    [['TrabajadorId' => 999999], 'TrabajadorId'],
                    [['DocumentoSustentoId' => 999999], 'DocumentoSustentoId'],
                    [['ColegiaturaEstado' => 'quizas'], 'ColegiaturaEstado'],
                ],
            ],
            'plantillas-biometricas' => [
                'endpoint' => '/api/plantillas-biometricas', 'table' => 'Biometria.PlantillaBiometrica', 'pk' => 'PlantillaBiometricaId',
                'estado' => 'PlantillaBiometricaEstado',
                'fk' => ['TrabajadorId' => $trabajadorConConsentimiento],
                'create' => ['PlantillaBiometricaTipo' => 'ROSTRO'],
                'keys' => ['id', 'trabajador_id', 'tipo', 'dedo', 'tiene_referencia', 'referencia_bytes', 'fecha_registro', 'activo', 'trabajador'],
                'required' => ['TrabajadorId', 'PlantillaBiometricaTipo'],
                'unique' => [],
                'maxlen' => [],
                'patch' => ['PlantillaBiometricaEstado' => false], 'patchKey' => 'activo',
                'invalid' => [
                    [['PlantillaBiometricaTipo' => 'IRIS'], 'PlantillaBiometricaTipo'],
                    [['PlantillaBiometricaDedo' => 'DEDO_GORDO'], 'PlantillaBiometricaDedo'],
                    [['PlantillaBiometricaDedo' => 'INDICE_DERECHO'], 'PlantillaBiometricaDedo'],
                    [['PlantillaBiometricaTipo' => 'HUELLA'], 'PlantillaBiometricaDedo'],
                    [['PlantillaBiometricaTipo' => 'HUELLA', 'PlantillaBiometricaDedo' => 'INDICE_DERECHO'], 'PlantillaBiometricaTipo'],
                    [['PlantillaBiometricaReferencia' => 'esto no es base64!!'], 'PlantillaBiometricaReferencia'],
                    [['PlantillaBiometricaReferencia' => base64_encode(str_repeat('a', 5000))], 'PlantillaBiometricaReferencia'],
                    [['PlantillaBiometricaEstado' => 'quizas'], 'PlantillaBiometricaEstado'],
                    [['TrabajadorId' => 999999], 'TrabajadorId'],
                ],
            ],
            'autorizaciones-metodo' => [
                'endpoint' => '/api/autorizaciones-metodo', 'table' => 'Biometria.AutorizacionMetodo', 'pk' => 'AutorizacionMetodoId',
                'estado' => 'AutorizacionMetodoEstado',
                'fk' => [
                    'TrabajadorId' => $nuevoTrabajador,
                    'MetodoMarcacionId' => self::sembrado('Biometria.MetodoMarcacion', 'MetodoMarcacionId', ['MetodoMarcacionCodigo' => 'MANUAL']),
                ],
                'create' => ['AutorizacionMetodoFechaInicio' => '2026-01-01', 'AutorizacionMetodoFechaFin' => '2026-12-31'],
                'keys' => ['id', 'trabajador_id', 'metodo_marcacion_id', 'fecha_inicio', 'fecha_fin', 'vigente', 'activo', 'trabajador', 'metodo'],
                'required' => ['TrabajadorId', 'MetodoMarcacionId', 'AutorizacionMetodoFechaInicio'],
                'unique' => [], 'uniqueComposite' => 'MetodoMarcacionId',
                'maxlen' => [],
                'patch' => ['AutorizacionMetodoFechaFin' => '2027-12-31'], 'patchKey' => 'fecha_fin',
                'invalid' => [
                    [['AutorizacionMetodoFechaFin' => '2025-12-31'], 'AutorizacionMetodoFechaFin'],
                    [['AutorizacionMetodoFechaInicio' => '01/01/2026'], 'AutorizacionMetodoFechaInicio'],
                    [['AutorizacionMetodoFechaFin' => '31/12/2026'], 'AutorizacionMetodoFechaFin'],
                    [['TrabajadorId' => 999999], 'TrabajadorId'],
                    [['MetodoMarcacionId' => 999999], 'MetodoMarcacionId'],
                    [['AutorizacionMetodoEstado' => 'quizas'], 'AutorizacionMetodoEstado'],
                ],
            ],
            'ocurrencias-porteria' => [
                'endpoint' => '/api/ocurrencias-porteria', 'table' => 'Solicitudes.OcurrenciaPorteria', 'pk' => 'OcurrenciaPorteriaId',
                'estado' => 'OcurrenciaPorteriaEstado', 'anula' => true,
                'fk' => [
                    'EessId' => self::sembrado('Organizacion.EstablecimientoSalud', 'EessId', ['EessCodigo' => 'EESS-LE-01']),
                    'VinculoLaboralId' => self::sembrado('Personal.VinculoLaboral', 'VinculoLaboralId', ['VinculoLaboralCodigo' => 'VL-0001']),
                ],
                'create' => [
                    'OcurrenciaPorteriaTipo' => 'SALIDA_CON_PAPELETA', 'OcurrenciaPorteriaFechaHora' => '2026-09-01 10:00',
                    'OcurrenciaPorteriaDescripcion' => 'Prueba',
                ],
                'keys' => ['id', 'eess_id', 'vinculo_laboral_id', 'usuario_id', 'fecha_hora', 'tipo', 'descripcion', 'estado', 'activo', 'eess', 'trabajador', 'usuario'],
                'required' => ['EessId', 'OcurrenciaPorteriaTipo'],
                'unique' => [],
                'maxlen' => ['OcurrenciaPorteriaDescripcion' => 1000],
                'patch' => ['OcurrenciaPorteriaEstado' => 'ATENDIDO'], 'patchKey' => 'estado',
                'invalid' => [
                    [['OcurrenciaPorteriaTipo' => 'OTRO_TIPO'], 'OcurrenciaPorteriaTipo'],
                    [['OcurrenciaPorteriaFechaHora' => '2999-01-01 10:00'], 'OcurrenciaPorteriaFechaHora'],
                    [['OcurrenciaPorteriaFechaHora' => '01/09/2026 10:00'], 'OcurrenciaPorteriaFechaHora'],
                    [['OcurrenciaPorteriaEstado' => 'CERRADO'], 'OcurrenciaPorteriaEstado'],
                    [['VinculoLaboralId' => null], 'VinculoLaboralId'],
                    [['OcurrenciaPorteriaTipo' => 'OTRO', 'OcurrenciaPorteriaDescripcion' => null], 'OcurrenciaPorteriaDescripcion'],
                    [['EessId' => 999999], 'EessId'],
                    [['VinculoLaboralId' => 999999], 'VinculoLaboralId'],
                    [['UsuarioId' => 999999], 'UsuarioId'],
                ],
            ],
        ];
    }
}
