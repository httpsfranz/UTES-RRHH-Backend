<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalogos que exige el RIT: tipos de licencia con sus topes (Art. 30 a 53), parametros y avisos. Es idempotente y seguro
 * en produccion: agrega lo que falta y actualiza los topes de lo que ya existe.
 *
 * php artisan db:seed --class=CatalogosRitSeeder
 */
class CatalogosRitSeeder extends Seeder
{
    /** codigo, nombre, con goce, maximo, maximo CAS, maximo al anio, sexo, a cuenta de vacaciones, exige anticipacion, base */
    private const LICENCIAS = [
        ['MATERNIDAD', 'Licencia por maternidad', 1, 98, null, null, 'F', 0, 0, 'RIT, Art. 34'],
        ['PATERNIDAD', 'Licencia por paternidad', 1, 10, null, null, 'M', 0, 0, 'RIT, Art. 35'],
        ['ENFERMEDAD', 'Licencia por enfermedad', 1, null, null, null, null, 0, 0, 'RIT, Art. 32'],
        ['DISCAPACIDAD', 'Asistencia médica y terapia por discapacidad', 1, null, null, 9, null, 1, 1, 'RIT, Art. 33 (56 horas al año)'],
        ['ADOPCION', 'Licencia por adopción', 1, 30, null, 30, null, 0, 1, 'RIT, Art. 36'],
        ['FALLECIMIENTO', 'Licencia por fallecimiento', 1, 5, 3, null, null, 0, 0, 'RIT, Art. 37'],
        ['FAMILIAR', 'Licencia por familiar grave', 1, 7, null, null, null, 0, 0, 'RIT, Art. 38'],
        ['CAPACITACION', 'Licencia por capacitacion oficial', 1, null, null, null, null, 0, 1, 'RIT, Art. 39'],
        ['CITACION', 'Citación judicial, fiscal, militar o policial', 1, null, null, null, null, 0, 0, 'RIT, Art. 40'],
        ['EDIL', 'Licencia por función edil', 1, null, null, null, null, 0, 1, 'RIT, Art. 41'],
        ['REPRESENT', 'Licencia por representacion', 1, null, null, null, null, 0, 1, 'RIT, Art. 42'],
        ['SINDICAL', 'Licencia sindical', 1, null, null, 30, null, 0, 1, 'RIT, Art. 43'],
        ['ONOMASTICO', 'Licencia por onomástico', 1, 1, null, 1, null, 0, 0, 'RIT, Art. 44'],
        ['CANCER_MAMA', 'Exámenes de detección de cáncer de mama y cuello uterino', 1, 1, null, 1, 'F', 0, 1, 'RIT, Art. 45'],
        ['ONCOLOGICO', 'Exámenes oncológicos preventivos', 1, 1, null, 1, null, 0, 1, 'RIT, Art. 46'],
        ['RADIACIONES', 'Descanso físico por radiaciones', 1, 10, null, 20, null, 0, 1, 'RIT, Art. 47'],
        ['A_CUENTA', 'Licencia a cuenta del período vacacional', 1, 30, null, null, null, 1, 1, 'RIT, Art. 48'],
        ['MATRIMONIO', 'Licencia por matrimonio', 1, 30, null, null, null, 1, 1, 'RIT, Art. 31 y 48'],
        ['SIN_GOCE', 'Licencia sin goce de haber', 0, null, 90, null, null, 0, 1, 'RIT, Art. 50'],
        ['ESTUDIOS', 'Licencia por estudios', 0, null, null, null, null, 0, 1, 'RIT, Art. 118'],
        ['CANDIDATO_LOCAL', 'Candidatura a elecciones regionales o locales', 0, null, null, null, null, 0, 1, 'RIT, Art. 51'],
        ['CANDIDATO_CONGRESO', 'Candidatura al Congreso de la República', 0, null, null, null, null, 0, 1, 'RIT, Art. 52'],
        ['CARGO_CONFIANZA', 'Licencia para cargo de confianza', 0, null, null, null, null, 0, 1, 'RIT, Art. 53'],
    ];

    public function run(): void
    {
        foreach (self::LICENCIAS as [$codigo, $nombre, $conGoce, $maximo, $maximoCas, $anual, $sexo, $aCuenta, $anticipacion, $base]) {
            DB::table('Solicitudes.TipoLicencia')->updateOrInsert(['TipoLicenciaCodigo' => $codigo], [
                'TipoLicenciaNombre' => $nombre, 'TipoLicenciaConGoce' => $conGoce, 'TipoLicenciaMaximoDias' => $maximo, 'TipoLicenciaMaximoDiasCas' => $maximoCas,
                'TipoLicenciaMaximoDiasAnio' => $anual, 'TipoLicenciaSexo' => $sexo, 'TipoLicenciaACuentaVacaciones' => $aCuenta, 'TipoLicenciaExigeAnticipacion' => $anticipacion,
                'TipoLicenciaBaseLegal' => $base, 'TipoLicenciaEstado' => 1,
            ]);
        }

        if (DB::table('Solicitudes.TipoPapeleta')->where('TipoPapeletaCodigo', 'DOCENCIA')->doesntExist()) {
            DB::table('Solicitudes.TipoPapeleta')->insert(['TipoPapeletaCodigo' => 'DOCENCIA', 'TipoPapeletaNombre' => 'Permiso por docencia', 'TipoPapeletaRequiereSustento' => 1, 'TipoPapeletaEsCompensable' => 1, 'TipoPapeletaEstado' => 1, 'TipoPapeletaDescripcion' => 'Hasta 6 horas semanales, compensables (RIT, Art. 63)']);
        }

        foreach ([['DIA_NO_LABORABLE', 'Compensación de día no laborable decretado'], ['ACTO_CIVICO', 'Compensación por acto cívico']] as [$codigo, $nombre]) {
            DB::table('Compensaciones.TipoCompensacion')->updateOrInsert(['TipoCompensacionCodigo' => $codigo], ['TipoCompensacionNombre' => $nombre]);
        }

        DB::table('Configuracion.ParametroSistema')->updateOrInsert(['ParametroSistemaCodigo' => 'TARDANZA_MODO_DESCUENTO'], [
            'ParametroSistemaValor' => 'UMBRAL_MENSUAL',
            'ParametroSistemaDescripcion' => 'UMBRAL_MENSUAL: descuenta al superar 1 hora al mes (RIT, Art. 22). PROPORCIONAL: descuenta cada minuto (Art. 25).',
        ]);

        $avisos = [
            ['OCURRENCIA_PORTERIA', 'Ocurrencia de portería', 'Salida sin autorización o exceso de papeleta informado por portería', 'RESP_EESS,JEFE_MICRORED', 'EESS', 0, 1],
            ['ALERTA_TARDANZA_MENSUAL', 'Tardanza acumulada', 'Más de 60 minutos de tardanza en el mes (RIT, Art. 104.1)', 'ADMIN,RRHH_RED', 'RED', 0, 0],
            ['ALERTA_COLEGIATURA', 'Colegiatura vencida', 'Personal con habilitación vencida (RIT, Art. 84.37)', 'ADMIN,RRHH_RED', 'RED', 0, 1],
            ['ALERTA_LIMITE_EDAD', 'Límite de edad', 'Personal que cumple 70 años (RIT, Art. 129)', 'ADMIN,RRHH_RED', 'RED', 0, 0],
            ['ALERTA_NO_REINCORPORACION', 'No reincorporación', 'Personal que no volvió tras una ausencia autorizada (RIT, Art. 28)', 'ADMIN,RRHH_RED', 'RED', 0, 1],
        ];
        foreach ($avisos as [$codigo, $nombre, $descripcion, $roles, $alcance, $interesado, $correo]) {
            DB::table('Soporte.TipoNotificacion')->updateOrInsert(['TipoNotificacionCodigo' => $codigo], [
                'TipoNotificacionNombre' => $nombre, 'TipoNotificacionDescripcion' => $descripcion, 'TipoNotificacionRolesDestino' => $roles,
                'TipoNotificacionAlcance' => $alcance, 'TipoNotificacionAlInteresado' => $interesado, 'TipoNotificacionEnviaCorreo' => $correo,
            ]);
        }

        // El RIT nombra nueve microredes: Trujillo faltaba en el catalogo y Poroto no es parte de la lista.
        DB::table('Organizacion.Microred')->updateOrInsert(['MicroredCodigo' => 'MR-TR'], ['MicroredNombre' => 'Microred Trujillo', 'MicroredDistrito' => 'Trujillo', 'MicroredEstado' => 1]);
    }
}
