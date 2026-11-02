<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\VolumenBase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ultimo retoque de la base de trabajo (solo con la expansion de volumen activa): los casos puntuales de los otros seeders
 * usan documentos de prueba faciles de recordar (DNI 700000NN, 9900000N, celulares 99100000N, "Av. Prueba 01"), que los tests
 * necesitan tal cual. Aqui, con todo ya sembrado y enlazado por Id, reciben DNI, celular, correo y direccion verosimiles para
 * que el sistema se vea como en uso real. Los tests nunca pasan por aqui: corren con SEED_VOLUMEN=false en su propia base.
 */
class DatosPersonalesRealistasSeeder extends Seeder
{
    use VolumenBase;

    /** Cuentas del sistema que no son personas (conservan su nombre). */
    private const CUENTAS_DEL_SISTEMA = ['Administrador'];

    /** Nombres para las cuentas predeterminadas y el trabajador de baja: [nombres, paterno, materno, sexo, nacimiento]. */
    private const NOMBRES_PREDETERMINADOS = [
        ['Karina Elizabeth', 'Rebaza', 'Otiniano', 'F', '1983-04-17'],
        ['Jorge Luis', 'Alfaro', 'Rodríguez', 'M', '1978-09-02'],
        ['Milagros del Rosario', 'Bazán', 'Esquivel', 'F', '1987-01-25'],
        ['Hugo Fernando', 'Vásquez', 'Lavado', 'M', '1981-06-11'],
        ['Ana Cecilia', 'Rodríguez', 'Pretell', 'F', '1990-11-30'],
        ['Segundo Wilfredo', 'Ruiz', 'Castañeda', 'M', '1969-03-08'],
        ['Juan Carlos', 'Mostacero', 'Polo', 'M', '1972-12-12'],
    ];

    public function run(): void
    {
        $tipoDni = (int) DB::table('Personal.TipoDocumentoIdentidad')->where('TipoDocumentoIdentidadCodigo', 'DNI')->value('TipoDocumentoIdentidadId');
        $usados = DB::table('Personal.Trabajador')->pluck('TrabajadorNumeroDocumento')->mapWithKeys(fn ($d) => [(string) $d => true])->all();
        $distritos = DB::table('Personal.VinculoLaboral as v')->join('Organizacion.EstablecimientoSalud as e', 'e.EessId', '=', 'v.EessId')
            ->join('Organizacion.Microred as m', 'm.MicroredId', '=', 'e.MicroredId')->pluck('m.MicroredDistrito', 'v.TrabajadorId')->all();

        $dePrueba = DB::table('Personal.Trabajador')
            ->where(fn ($q) => $q->where('TrabajadorNumeroDocumento', 'like', '700000__')->orWhere('TrabajadorNumeroDocumento', 'like', '9900000%'))
            ->where('TipoDocumentoIdentidadId', $tipoDni)->orderBy('TrabajadorId')->get();

        $k = 0;
        foreach ($dePrueba as $t) {
            $clave = 'base'.$t->TrabajadorId;
            $cambios = [];
            $predeterminado = $t->TrabajadorApellidoMaterno === 'Predeterminado' || $t->TrabajadorApellidoPaterno === 'Retirado';
            if ($predeterminado && ! in_array($t->TrabajadorNombres, self::CUENTAS_DEL_SISTEMA, true)) {
                [$nombres, $paterno, $materno, $sexo, $nacimiento] = self::NOMBRES_PREDETERMINADOS[$k++ % count(self::NOMBRES_PREDETERMINADOS)];
                $cambios += ['TrabajadorNombres' => $nombres, 'TrabajadorApellidoPaterno' => $paterno, 'TrabajadorApellidoMaterno' => $materno, 'TrabajadorSexo' => $sexo, 'TrabajadorFechaNacimiento' => $nacimiento];
            }
            $nombres = $cambios['TrabajadorNombres'] ?? $t->TrabajadorNombres;
            $paterno = $cambios['TrabajadorApellidoPaterno'] ?? $t->TrabajadorApellidoPaterno;
            $nacimiento = $cambios['TrabajadorFechaNacimiento'] ?? $t->TrabajadorFechaNacimiento;
            $anio = $nacimiento === null ? 1985 : (int) substr((string) $nacimiento, 0, 4);

            unset($usados[(string) $t->TrabajadorNumeroDocumento]);
            $cambios['TrabajadorNumeroDocumento'] = $this->dniRealista($clave, $anio, $usados);
            if ($t->TrabajadorTelefono !== null || $predeterminado) {
                $cambios['TrabajadorTelefono'] = $this->celularRealista($clave);
            }
            if ($t->TrabajadorCorreo !== null && str_ends_with((string) $t->TrabajadorCorreo, '@ejemplo.pe')) {
                $cambios['TrabajadorCorreo'] = $this->correoRealista($clave, $nombres, $paterno);
            }
            if ($t->TrabajadorDireccion === null || str_starts_with((string) $t->TrabajadorDireccion, 'Av. Prueba')) {
                $cambios['TrabajadorDireccion'] = $this->direccionDe((int) $t->TrabajadorId * 3 + 7, $distritos[$t->TrabajadorId] ?? 'Trujillo');
            }
            DB::table('Personal.Trabajador')->where('TrabajadorId', $t->TrabajadorId)->update($cambios);
        }

        // Los demas celulares de prueba (99100000N) y los telefonos de las microredes.
        foreach (DB::table('Personal.Trabajador')->where('TrabajadorTelefono', 'like', '9910000%')->pluck('TrabajadorId') as $id) {
            DB::table('Personal.Trabajador')->where('TrabajadorId', $id)->update(['TrabajadorTelefono' => $this->celularRealista("tel{$id}")]);
        }
        foreach (DB::table('Organizacion.Microred')->whereNotNull('MicroredTelefono')->pluck('MicroredId') as $id) {
            DB::table('Organizacion.Microred')->where('MicroredId', $id)->update(['MicroredTelefono' => $this->celularRealista("microred{$id}")]);
        }
    }
}
