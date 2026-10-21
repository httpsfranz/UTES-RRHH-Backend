<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Deja habilitado solo lo que la Red usa de verdad (revision del tester, 2026-10-19, contrastada con el RIT):
 *
 *   - Documento de identidad: solo DNI (el personal de la Red se identifica con DNI).
 *   - Metodos de marcacion: reconocimiento facial (RIT, Art. 21: "unicamente mediante reconocimiento facial") y registro
 *     manual (la excepcion del mismo articulo: parte diario / control manual autorizado por RRHH). Los demas se desactivan.
 *   - Dispositivos: solo los relojes faciales; la marca manual se hace sin dispositivo.
 *   - Plantillas biometricas: solo las faciales.
 *   - Autorizaciones de metodo: solo las del registro manual (el facial no necesita autorizacion).
 *
 * No borra nada (los registros quedan inactivos y se pueden ver con el filtro "Vigente: No") y es idempotente.
 */
class AlcanceRitSeeder extends Seeder
{
    public const METODOS_VIGENTES = ['ROSTRO', 'MANUAL'];

    public function run(): void
    {
        DB::table('Personal.TipoDocumentoIdentidad')->where('TipoDocumentoIdentidadCodigo', '<>', 'DNI')->update(['TipoDocumentoIdentidadEstado' => 0]);

        DB::table('Biometria.MetodoMarcacion')->whereNotIn('MetodoMarcacionCodigo', self::METODOS_VIGENTES)->update(['MetodoMarcacionEstado' => 0]);

        DB::table('Biometria.DispositivoMarcacion')->where('DispositivoMarcacionTipo', 'not like', '%acial%')->update(['DispositivoMarcacionEstado' => 0]);

        DB::table('Biometria.PlantillaBiometrica')->where('PlantillaBiometricaTipo', '<>', 'ROSTRO')->update(['PlantillaBiometricaEstado' => 0]);

        $manual = DB::table('Biometria.MetodoMarcacion')->where('MetodoMarcacionCodigo', 'MANUAL')->value('MetodoMarcacionId');
        DB::table('Biometria.AutorizacionMetodo')->where('MetodoMarcacionId', '<>', $manual)->update(['AutorizacionMetodoEstado' => 0]);
    }
}
