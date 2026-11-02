<?php

namespace Database\Seeders\Concerns;

use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Piezas comunes de la "expansion de volumen" de DatosPruebaSeeder: la Red simulada como un sistema ya en uso (decenas de
 * trabajadores por establecimiento, programacion, marcaciones, solicitudes, cierres de mes...). Todo es DETERMINISTICO:
 * nada usa aleatorios; cada decision sale de crc32 de una clave de texto (trabajador + fecha + motivo), asi que dos
 * corridas dan exactamente el mismo estado sin importar el orden en que se genere.
 *
 * Los trabajadores de volumen tienen vinculos VL-1NNN y DNI verosimiles (los de los otros seeders son 700000NN y VL-00NN),
 * y la expansion nunca toca a estos ultimos: siguen siendo los casos puntuales que recorren los tests. Solo en la base de
 * trabajo, al final de DatabaseSeeder, DatosPersonalesRealistasSeeder les da DNI, celular y correo verosimiles.
 */
trait VolumenBase
{
    /**
     * La Red simula la operacion desde el inicio de julio (los meses cerrados, para ver historial) hasta el fin de la
     * programacion publicada. Lo que aun no ocurrio no se inventa: las marcas posteriores a la hora de la siembra se omiten
     * y el motor ignora los dias futuros, asi que la asistencia queda al dia sin importar cuando se corra el seeder.
     */
    protected const VOLUMEN_DESDE = '2026-07-01';

    protected const VOLUMEN_HASTA = '2026-11-30';

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

    /** Fecha de hoy en Lima: separa lo ya ocurrido (cumplido, resuelto) de lo que aun esta por venir (programado, pendiente). */
    protected function hoyVolumen(): string
    {
        return HoraLocal::ahora()->toDateString();
    }

    /**
     * DNI de 8 digitos verosimil para la edad: quien nacio antes de los 80 tiene un DNI de la serie 1NNNNNNN (La Libertad,
     * libretas antiguas); los nacidos en los 80 y 90, de la serie 4NNNNNNN; los mas jovenes, de la 7NNNNNNN. Unico dentro de
     * $usados (que se actualiza).
     *
     * @param  array<string,true>  $usados
     */
    protected function dniRealista(string $clave, int $anioNacimiento, array &$usados): string
    {
        $prefijos = match (true) {
            $anioNacimiento < 1972 => ['17', '18'],
            $anioNacimiento < 1980 => ['18', '19', '40'],
            $anioNacimiento < 1990 => ['40', '41', '42', '43', '44'],
            $anioNacimiento < 1996 => ['44', '45', '46', '47', '48'],
            default => ['70', '71', '72', '73', '74', '75', '76', '77'],
        };
        $intento = 0;
        do {
            $dni = $this->elegir("{$clave}|dni{$intento}", $prefijos).str_pad((string) (crc32("{$clave}|dni|{$intento}") % 1000000), 6, '0', STR_PAD_LEFT);
            $intento++;
        } while (isset($usados[$dni]) || preg_match('/(\d)\1{3}/', $dni));
        $usados[$dni] = true;

        return $dni;
    }

    /** Celular peruano de 9 digitos (empieza con 9, con los prefijos de los operadores). */
    protected function celularRealista(string $clave): string
    {
        $prefijo = $this->elegir("{$clave}|cel", ['940', '941', '942', '943', '944', '948', '949', '950', '951', '952', '956', '958', '959', '961', '962', '964', '965', '966', '968', '969', '970', '971', '972', '973', '975', '976', '978', '979', '980', '981', '982', '983', '984', '985', '986', '987', '988', '989', '991', '992', '993', '994', '995', '996', '997', '998']);

        return $prefijo.str_pad((string) (crc32("{$clave}|celn") % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Correo personal verosimil: nombre.apellido con un numero corto en un proveedor comun. */
    protected function correoRealista(string $clave, string $nombres, string $paterno): string
    {
        $usuario = Str::lower(Str::ascii(Str::before($nombres.' ', ' '))).$this->elegir("{$clave}|sep", ['.', '_', '']).Str::lower(preg_replace('/[^A-Za-z]/', '', Str::ascii($paterno)));
        $numero = $this->azar("{$clave}|num") < 65 ? (string) $this->azarEntre("{$clave}|n2", 1, 99) : '';

        return $usuario.$numero.'@'.$this->porcentaje("{$clave}|dom", ['gmail.com' => 72, 'hotmail.com' => 18, 'outlook.com' => 7, 'yahoo.es' => 3]);
    }

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

    /**
     * Segundo nombre (vacio = no tiene) que corresponde al sexo.
     *
     * @return list<string>
     */
    protected function segundosNombres(bool $esMujer): array
    {
        return $esMujer
            ? ['', 'Elena', '', 'Isabel', '', 'del Pilar', '', 'Alejandra', '', 'de los Ángeles', '', 'del Carmen', '', 'Beatriz']
            : ['', 'Antonio', '', 'Alberto', '', 'Eduardo', '', 'Enrique', '', 'Daniel', '', 'Martín', '', 'Javier'];
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
