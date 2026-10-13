<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Piezas comunes de la "expansion de volumen" de DatosPruebaSeeder: la Red simulada como un sistema ya en uso (decenas de
 * trabajadores por establecimiento, programacion, marcaciones, solicitudes, cierres de mes...). Todo es DETERMINISTICO:
 * nada usa aleatorios; cada decision sale de crc32 de una clave de texto (trabajador + fecha + motivo), asi que dos
 * corridas dan exactamente el mismo estado sin importar el orden en que se genere.
 *
 * Los trabajadores de volumen tienen DNI 46NNNNNN y vinculos VL-1NNN (los de los otros seeders son 700000NN y VL-00NN),
 * y la expansion nunca toca a estos ultimos: siguen siendo los casos puntuales que recorren los tests.
 */
trait VolumenBase
{
    /** La Red simula la operacion desde el inicio de julio (los meses cerrados, para ver historial) hasta este dia. */
    protected const VOLUMEN_DESDE = '2026-07-01';

    protected const VOLUMEN_HASTA = '2026-10-20';

    /** Primer vinculo de volumen: si existe, la expansion ya se sembro y no se repite. */
    protected const VOLUMEN_MARCADOR = 'VL-1001';

    /** Contrasena de prueba "Prueba2026*" (hash fijo: con Hash::make cada corrida daria otro). */
    protected const VOLUMEN_HASH = '$2y$10$.IbfgeF.ziFNknf4iEwjO.KjBum5k.mLUNc22ZfSK5tfYmY.IYVb.';

    /**
     * Trabajadores de volumen en memoria, en el orden en que se sembraron. Cada uno:
     * vinculo, trabajador, codigo, documento, eess (codigo), eess_id, cargo, condicion, regimen, profesion, sexo, inicio, modo, jefe.
     *
     * @var list<array<string,mixed>>
     */
    protected array $dotacion = [];

    /** @var array<string,int> nombre de usuario => UsuarioId (cache) */
    protected array $usuariosCache = [];

    protected function yaTieneVolumen(): bool
    {
        return DB::table('Personal.VinculoLaboral')->where('VinculoLaboralCodigo', self::VOLUMEN_MARCADOR)->exists();
    }

    /** Entero de 0 a 99 que depende solo de la clave. */
    protected function azar(string $clave): int
    {
        return crc32($clave) % 100;
    }

    /** Entero entre $min y $max (inclusive) que depende solo de la clave. */
    protected function azarEntre(string $clave, int $min, int $max): int
    {
        return $min + (crc32($clave.'#') % ($max - $min + 1));
    }

    /**
     * Un elemento de la lista elegido por la clave.
     *
     * @template T
     *
     * @param  list<T>  $opciones
     * @return T
     */
    protected function elegir(string $clave, array $opciones): mixed
    {
        return $opciones[crc32($clave.'*') % count($opciones)];
    }

    /**
     * Elige segun porcentajes acumulados: ['APROBADO' => 70, 'PENDIENTE' => 10, ...] (la suma debe dar 100).
     *
     * @param  array<string,int>  $reparto
     */
    protected function porcentaje(string $clave, array $reparto): string
    {
        $r = $this->azar($clave.'%');
        $acumulado = 0;
        foreach ($reparto as $valor => $peso) {
            $acumulado += $peso;
            if ($r < $acumulado) {
                return $valor;
            }
        }

        return array_key_last($reparto);
    }

    /**
     * Inserta muchas filas de una vez (en lotes que respetan el limite de 2100 parametros de SQL Server). Todas las filas
     * deben tener las mismas columnas.
     *
     * @param  list<array<string,mixed>>  $filas
     */
    protected function insertarPorLotes(string $tabla, array $filas): void
    {
        if ($filas === []) {
            return;
        }
        $lote = max(1, intdiv(2000, count($filas[0])));
        foreach (array_chunk($filas, $lote) as $parte) {
            DB::table($tabla)->insert($parte);
        }
    }

    /** UsuarioId por nombre de usuario (los sembrados antes y los de volumen). */
    protected function usuarioId(string $nombre): int
    {
        return $this->usuariosCache[$nombre] ??= (int) DB::table('Seguridad.Usuario')->where('UsuarioNombre', $nombre)->value('UsuarioId');
    }

    /**
     * Crea un documento de sustento ficticio y devuelve su id. El hash (unico en la tabla) se deriva del nombre.
     *
     * @param  string  $tipo  certificado-medico | citt | resolucion | constancia | informe | acta | foto
     */
    protected function documento(string $tipo, int $numero, string $fecha): int
    {
        $extension = $tipo === 'foto' ? 'jpg' : 'pdf';
        $nombre = sprintf('%s-%04d.%s', $tipo, $numero, $extension);

        return (int) DB::table('Soporte.DocumentoSustento')->insertGetId([
            'DocumentoSustentoNombre' => $nombre,
            'DocumentoSustentoRuta' => '/storage/documentos/'.substr($fecha, 0, 4).'/'.substr($fecha, 5, 2).'/'.$nombre,
            'DocumentoSustentoTipo' => $extension === 'jpg' ? 'image/jpeg' : 'application/pdf',
            'DocumentoSustentoExtension' => $extension,
            'DocumentoSustentoTamanoBytes' => 60000 + crc32($nombre) % 900000,
            'DocumentoSustentoHash' => hash('sha256', 'volumen-'.$nombre),
            'DocumentoSustentoFechaRegistro' => $fecha.' 09:00:00',
        ], 'DocumentoSustentoId');
    }

    /** "Av. Prueba 12" con un nombre de via ficticio. */
    protected function direccionDe(int $n, string $distrito): string
    {
        $vias = ['Av. Los Incas', 'Jr. Bolívar', 'Calle Las Flores', 'Av. España', 'Jr. Pizarro', 'Av. Perú', 'Calle Los Pinos', 'Jr. Gamarra', 'Av. Húsares de Junín', 'Calle San Martín'];

        return $vias[$n % count($vias)].' '.(100 + ($n * 17) % 880).', '.$distrito;
    }

    /** @return list<string> */
    protected function nombresFemeninos(): array
    {
        return ['María', 'Rosa', 'Carmen', 'Luz', 'Ana', 'Julia', 'Elena', 'Patricia', 'Gladys', 'Sonia', 'Milagros', 'Yessenia', 'Katherine', 'Lizbeth', 'Diana', 'Silvia', 'Mirtha', 'Flor', 'Sandra', 'Verónica', 'Paola', 'Nancy', 'Jessica', 'Karla', 'Betty', 'Noemí', 'Rocío', 'Fiorella', 'Giovanna', 'Teresa'];
    }

    /** @return list<string> */
    protected function nombresMasculinos(): array
    {
        return ['Juan', 'Luis', 'Carlos', 'José', 'Miguel', 'Pedro', 'Jorge', 'Víctor', 'Walter', 'César', 'Héctor', 'Raúl', 'Óscar', 'Henry', 'Edwin', 'Ronald', 'Segundo', 'Manuel', 'Wilmer', 'Alex', 'Jhon', 'Franklin', 'Marco', 'Elmer', 'Santos', 'Percy', 'Ángel', 'Fernando', 'Ricardo', 'Roberto'];
    }

    /** @return list<string> */
    protected function segundosNombres(): array
    {
        return ['', 'Elena', '', 'Isabel', '', 'del Pilar', '', 'Alejandra', '', 'Antonio', '', 'Alberto', '', 'Eduardo', '', 'Enrique', '', 'Daniel', '', 'de los Ángeles'];
    }

    /** @return list<string> */
    protected function apellidos(): array
    {
        return ['Quiroz', 'García', 'Cotrina', 'Villa', 'Morales', 'Peche', 'Gálvez', 'Rosas', 'Paz', 'Cedrón', 'Paredes', 'Rojas', 'Chávez', 'León', 'Díaz', 'Silva', 'Cruz', 'Mendoza', 'Vera', 'Salas', 'Ríos', 'Ramos', 'Torres', 'Flores', 'Castillo', 'Vargas', 'Huamán', 'Sánchez', 'Pérez', 'Gutiérrez', 'Alvarado', 'Cabanillas', 'Cueva', 'Zavaleta', 'Lozano', 'Ventura', 'Abanto', 'Briones', 'Calderón', 'Carranza', 'Delgado', 'Espinoza', 'Gonzales', 'Honorio', 'Jara', 'Julca', 'Llamo', 'Mego', 'Neyra', 'Olórtegui', 'Plasencia', 'Reyes', 'Sandoval', 'Tantalean', 'Urbina', 'Valverde', 'Yupanqui', 'Zelada', 'Aguilar', 'Benites'];
    }

    /** Nombre de usuario ascii: inicial del nombre + apellido paterno, en minusculas y sin espacios. */
    protected function usuarioDe(string $nombres, string $paterno): string
    {
        return strtolower(Str::ascii(mb_substr($nombres, 0, 1).preg_replace('/[^A-Za-z]/', '', Str::ascii($paterno))));
    }
}
