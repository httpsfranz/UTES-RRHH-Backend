<?php

namespace App\Support;

/**
 * Que permiso exige cada ruta de la API. Se deduce del recurso (primer segmento tras /api) y del metodo:
 *
 *   - consultar (GET)      -> {MODULO}_VER
 *   - crear/editar/borrar  -> {MODULO}_EDITAR
 *   - acciones de flujo (aprobar, rechazar, publicar...) -> permiso propio (ver ACCIONES)
 *
 * Los catalogos de consulta (tipos, cargos, turnos, establecimientos...) los necesita cualquier pantalla para llenar sus
 * listas, asi que su lectura solo exige estar autenticado; modificarlos si exige el permiso del modulo.
 *
 * Un recurso que no esta en el mapa se rechaza (404/403 seguro): al agregar un modulo nuevo hay que declararlo aqui.
 */
class PermisosDeRuta
{
    public const MODULOS = [
        'ORGANIZACION' => ['microredes', 'establecimientos', 'tipos-establecimiento', 'responsables-eess', 'tipos-responsabilidad'],
        'PERSONAL' => ['trabajadores', 'vinculos-laborales', 'asignaciones-horario', 'cargos', 'grupos-ocupacionales', 'profesiones', 'colegiaturas', 'tipos-colegiatura', 'condiciones-laborales', 'regimenes-laborales', 'tipos-documento-identidad', 'consulta-dni'],
        'ASISTENCIA' => ['marcaciones', 'asistencia-diaria', 'ajustes-marcacion', 'cargas-asistencia-manual', 'justificaciones-falta', 'conceptos-justificacion', 'estados-asistencia'],
        'PROGRAMACION' => ['programaciones-periodo', 'programaciones-trabajador', 'turnos-programados', 'cambios-turno', 'tipos-cambio-turno', 'tipos-periodo-programacion', 'cargas-programacion', 'informes-guardia-comunitaria', 'programacion-calendario'],
        'SOLICITUDES' => ['papeletas', 'tipos-papeleta', 'motivos-papeleta', 'licencias', 'tipos-licencia', 'descansos-medicos', 'constataciones-domiciliarias', 'ocurrencias-porteria', 'goces-vacacionales', 'periodos-vacacionales', 'roles-vacacionales', 'documentos-sustento'],
        'CONFIGURACION' => ['turnos', 'horarios', 'horarios-detalle', 'tipos-jornada', 'parametros-jornada', 'tablas-tolerancia', 'tramos-tolerancia', 'calendario-no-laborable',
            'dispositivos-marcacion', 'metodos-marcacion', 'plantillas-biometricas', 'autorizaciones-metodo', 'consentimientos-biometricos'],
        'REPORTES' => ['periodos-asistencia', 'consolidados-asistencia', 'detalles-consolidado', 'liquidaciones-descuento', 'detalles-liquidacion', 'compensaciones-horarias', 'tipos-compensacion', 'conceptos-descuento',
            'expedientes-pad', 'supervisiones-inopinadas', 'tipos-falta-disciplinaria'],
        'SEGURIDAD' => ['usuarios', 'usuarios-roles', 'usuarios-ambitos', 'roles', 'roles-permisos', 'permisos', 'sesiones-acceso'],
    ];

    /** Recursos cuya lectura solo exige autenticacion (listas de apoyo de los formularios). */
    public const CATALOGOS_DE_LECTURA = [
        'microredes', 'establecimientos', 'tipos-establecimiento', 'tipos-responsabilidad', 'cargos', 'grupos-ocupacionales', 'profesiones', 'tipos-colegiatura',
        'condiciones-laborales', 'regimenes-laborales', 'tipos-documento-identidad', 'estados-asistencia', 'conceptos-justificacion', 'tipos-cambio-turno',
        'tipos-periodo-programacion', 'tipos-papeleta', 'motivos-papeleta', 'tipos-licencia', 'turnos', 'tipos-jornada', 'tipos-compensacion', 'conceptos-descuento',
        'tipos-falta-disciplinaria', 'metodos-marcacion', 'calendario-no-laborable', 'notificaciones',
    ];

    /** Recursos con informacion de personas que el rol TRABAJADOR (solo consulta lo propio) puede abrir; los listados se filtran a sus vinculos. */
    public const RECURSOS_PROPIOS = [
        'marcaciones', 'asistencia-diaria', 'justificaciones-falta', 'ajustes-marcacion', 'papeletas', 'licencias', 'descansos-medicos',
        'constataciones-domiciliarias', 'periodos-vacacionales', 'roles-vacacionales', 'goces-vacacionales',
    ];

    /** Accion de flujo (ultimo segmento de la ruta) => permiso. 'recurso' restringe la accion a esos recursos. */
    public const ACCIONES = [
        'aprobar' => ['ajustes-marcacion' => 'ASISTENCIA_APROBAR', 'justificaciones-falta' => 'ASISTENCIA_APROBAR', 'liquidaciones-descuento' => 'REPORTES_EDITAR', '*' => 'SOLICITUDES_APROBAR'],
        'rechazar' => ['ajustes-marcacion' => 'ASISTENCIA_APROBAR', 'justificaciones-falta' => 'ASISTENCIA_APROBAR', '*' => 'SOLICITUDES_APROBAR'],
        'publicar' => ['*' => 'PROGRAMACION_APROBAR'],
        'cerrar' => ['*' => 'PROGRAMACION_APROBAR'],
        'cumplir' => ['*' => 'PROGRAMACION_EDITAR'],
        'remitir' => ['*' => 'REPORTES_EDITAR'],
        'devolver' => ['*' => 'REPORTES_EDITAR'],
        'reprogramar' => ['*' => 'SOLICITUDES_EDITAR'],
        'generar' => ['*' => 'REPORTES_EDITAR'],
        'calcular' => ['*' => 'ASISTENCIA_EDITAR'],
    ];

    /** Rutas con permiso propio (recurso => permiso para cualquier metodo). */
    public const ESPECIALES = [
        'auditoria' => 'SEGURIDAD_AUDITORIA',
        'logs-integracion' => 'SEGURIDAD_AUDITORIA',
        'parametros-sistema' => 'SISTEMA_PARAMETROS',
    ];

    /**
     * @param  list<string>  $segmentos  segmentos de la ruta despues de "api/"
     * @return string|null permiso requerido; null = basta con estar autenticado; '' = recurso desconocido (se rechaza)
     */
    public static function para(string $metodo, array $segmentos): ?string
    {
        $recurso = $segmentos[0] ?? '';
        $metodo = strtoupper($metodo);
        $lectura = in_array($metodo, ['GET', 'HEAD'], true);

        if ($recurso === 'user' || $recurso === 'dashboard') {
            return null;
        }
        if ($recurso === 'notificaciones' && ! $lectura) {
            return in_array($metodo, ['PUT', 'PATCH'], true) ? null : 'SEGURIDAD_EDITAR';
        }
        if (isset(self::ESPECIALES[$recurso])) {
            if ($recurso === 'parametros-sistema' && $lectura) {
                return 'CONFIGURACION_VER';
            }

            return self::ESPECIALES[$recurso];
        }

        $accion = end($segmentos);
        if (! $lectura && is_string($accion) && isset(self::ACCIONES[$accion]) && count($segmentos) > 1) {
            return self::ACCIONES[$accion][$recurso] ?? self::ACCIONES[$accion]['*'];
        }
        if ($recurso === 'asistencia-diaria' && ($segmentos[1] ?? null) === 'calcular') {
            return 'ASISTENCIA_EDITAR';
        }
        if ($recurso === 'consolidados-asistencia' && ($segmentos[1] ?? null) === 'generar') {
            return 'REPORTES_EDITAR';
        }
        if ($recurso === 'roles' && ($segmentos[2] ?? null) === 'permisos') {
            return $lectura ? 'SEGURIDAD_VER' : 'SEGURIDAD_EDITAR';
        }

        if ($lectura && in_array($recurso, self::CATALOGOS_DE_LECTURA, true)) {
            return null;
        }
        foreach (self::MODULOS as $modulo => $recursos) {
            if (in_array($recurso, $recursos, true)) {
                return $modulo.($lectura ? '_VER' : '_EDITAR');
            }
        }

        return '';
    }
}
