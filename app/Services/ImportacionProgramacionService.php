<?php

namespace App\Services;

use App\Models\Seguridad\Usuario;
use App\Support\HojaDeCalculo;
use DomainException;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Programacion mensual de turnos desde Excel (RIT, Art. 16: cada servicio remite su programacion hasta el dia 05 de cada
 * mes). La plantilla es la misma grilla trabajador x dia de la pantalla, ya llena con el personal del establecimiento y lo
 * programado hasta ahora; el jefe escribe el codigo del turno en cada dia y la sube.
 *
 *   - Cada fila del archivo reemplaza el mes de ESE trabajador: celda vacia = dia libre. El personal que no esta en el
 *     archivo no se toca, ni tampoco los dias con una ausencia aprobada (vacaciones, licencia, descanso medico).
 *   - Cada celda que cambia pasa por ProgramacionMensualService::asignar(), con las mismas reglas que pintar la grilla
 *     (vinculo vigente, periodo abierto, guardia solo para D.L. 276 y SERUMS, sin superposiciones ni 24 horas continuas).
 *   - Primero se retiran los turnos que sobran y despues se agregan los nuevos, para que el cambio de un dia no choque
 *     con lo que aun no se retiro del dia siguiente.
 *   - Analizar ensaya todo y lo deshace; importar lo guarda (todo, o solo las celdas validas si se pide omitir errores).
 */
class ImportacionProgramacionService
{
    /** Separadores de varios turnos en un mismo dia: "M+T", "M, T", "M/T". */
    private const SEPARADORES = '/\s*[+,;\/]\s*/';

    public function __construct(private readonly ProgramacionMensualService $programacion) {}

    /** Escribe la plantilla del mes en un archivo temporal y devuelve su ruta. */
    public function plantilla(int $eessId, int $anio, int $mes): string
    {
        $grilla = $this->programacion->grilla($eessId, $anio, $mes);
        $dias = $grilla['periodo']['dias'];

        $ruta = tempnam(sys_get_temp_dir(), 'prg').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($ruta);
        $escritor->getCurrentSheet()->setName('Programación');

        $titulo = (new Style)->withFontBold(true)->withFontSize(13);
        $cabecera = (new Style)->withFontBold(true)->withBackgroundColor('D9EEF0');
        $finDeSemana = (new Style)->withFontBold(true)->withBackgroundColor('FCE9C8');
        $texto = (new Style)->withFormat('@');
        $ausente = (new Style)->withFontColor('94A3B8')->withBackgroundColor('F1F5F9');

        $escritor->addRow(Row::fromValuesWithStyle(["Programación de turnos · {$grilla['eess']['nombre']} · {$grilla['periodo']['etiqueta']}"], $titulo));
        $escritor->addRow(Row::fromValues(['Escribe el código del turno en cada día (dos turnos: M+T). Vacío = día libre. Los códigos están en la hoja «Turnos». No cambies el DNI.']));
        $estilos = array_map(fn ($d) => $d['es_domingo'] || $d['no_laborable'] ? $finDeSemana : $cabecera, $dias);
        $escritor->addRow(Row::fromValuesWithStyles(['DNI', 'Trabajador', 'Cargo', ...array_map(fn ($d) => $d['dia'], $dias)], [$cabecera, $cabecera, $cabecera, ...$estilos]));
        $escritor->addRow(Row::fromValuesWithStyles(['', '', '', ...array_map(fn ($d) => $d['dia_semana'], $dias)], [null, null, null, ...$estilos]));

        foreach ($grilla['trabajadores'] as $fila) {
            $valores = [$fila['dni'], $fila['nombre'], $fila['cargo']];
            $estilosFila = [$texto, null, null];
            foreach ($dias as $dia) {
                $celda = $fila['celdas'][$dia['fecha']];
                $codigos = implode('+', array_column($celda['turnos'], 'codigo'));
                // Un dia con ausencia aprobada se muestra entre parentesis: al subir el archivo se ignora.
                $valores[] = $celda['ausencia'] && $codigos === '' ? "({$celda['ausencia']['abreviatura']})" : $codigos;
                $estilosFila[] = $celda['ausencia'] ? $ausente : $texto;
            }
            $escritor->addRow(Row::fromValuesWithStyles($valores, $estilosFila));
        }

        $escritor->addNewSheetAndMakeItCurrent()->setName('Turnos');
        $escritor->addRow(Row::fromValuesWithStyle(['Código', 'Turno', 'Entrada', 'Salida', 'Horas', 'Guardia'], $cabecera));
        foreach ($grilla['turnos'] as $turno) {
            $escritor->addRow(Row::fromValues([$turno['codigo'], $turno['nombre'], $turno['entrada'], $turno['salida'], $turno['horas'], $turno['es_guardia'] ? 'Sí (solo D.L. 276 y SERUMS)' : 'No']));
        }
        $escritor->addRow(Row::fromValues(['']));
        foreach ([
            '• Cada fila reemplaza el mes de ese trabajador: un día vacío queda libre.',
            '• El personal que borres del archivo no se modifica (conserva lo que ya tenía programado).',
            '• Los días entre paréntesis tienen una ausencia aprobada (vacaciones, licencia, descanso médico): no se programan.',
            '• Se aplican las mismas reglas que en la pantalla: vínculo vigente, período abierto, guardias solo D.L. 276 y SERUMS, sin turnos que se crucen ni más de 24 horas seguidas.',
        ] as $linea) {
            $escritor->addRow(Row::fromValues([$linea]));
        }
        $escritor->close();

        return $ruta;
    }

    /**
     * Lee el archivo y aplica los cambios: con $confirmar = false (o con errores sin $omitir) todo se deshace al terminar.
     *
     * @return array{filas:int,cambios:int,validos:int,aplicados:int,celdas_con_error:int,errores:list<array{fila:int,campo:string,mensaje:string}>}
     */
    public function importar(int $eessId, int $anio, int $mes, string $ruta, string $extension, Usuario $usuario, bool $confirmar, bool $omitir = false): array
    {
        $grilla = $this->programacion->grilla($eessId, $anio, $mes);
        if ($grilla['bloqueo'] !== null) {
            throw new DomainException($grilla['bloqueo']);
        }
        [$filas, $cambios, $errores] = $this->leer($grilla, $ruta, $extension);

        $aplicados = 0;
        DB::beginTransaction();
        try {
            // 1) Se retiran los turnos que ya no van (nunca choca con nada); 2) se deja cada celda como dice el archivo.
            foreach ($cambios as $c) {
                $quedan = array_values(array_intersect($c['actuales'], $c['turnos']));
                if (count($quedan) < count($c['actuales'])) {
                    $this->programacion->asignar($eessId, $c['vinculo_id'], $c['fecha'], $quedan, $usuario);
                }
            }
            foreach ($cambios as $c) {
                try {
                    $this->programacion->asignar($eessId, $c['vinculo_id'], $c['fecha'], $c['turnos'], $usuario);
                    $aplicados++;
                } catch (DomainException $e) {
                    $errores[] = ['fila' => $c['fila'], 'campo' => $c['campo'], 'mensaje' => $e->getMessage()];
                    $this->restaurar($eessId, $c, $usuario);
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        $confirmar && ($errores === [] || $omitir) ? DB::commit() : DB::rollBack();

        usort($errores, fn ($a, $b) => [$a['fila'], $a['campo']] <=> [$b['fila'], $b['campo']]);

        return [
            'filas' => $filas, 'cambios' => count($cambios), 'validos' => $aplicados, 'aplicados' => $confirmar && ($errores === [] || $omitir) ? $aplicados : 0,
            'celdas_con_error' => count(array_unique(array_map(fn ($e) => $e['fila'].'|'.$e['campo'], $errores))), 'errores' => $errores,
        ];
    }

    /**
     * La celda que no se pudo cambiar vuelve a lo que tenia (el paso 1 pudo haberle retirado turnos). Si ni eso se puede
     * (otra celda del archivo ya ocupo ese horario), queda con lo que no se retiro y el error ya informa la fila.
     *
     * @param  array{vinculo_id:int,fecha:string,actuales:list<int>}  $cambio
     */
    private function restaurar(int $eessId, array $cambio, Usuario $usuario): void
    {
        try {
            $this->programacion->asignar($eessId, $cambio['vinculo_id'], $cambio['fecha'], $cambio['actuales'], $usuario);
        } catch (DomainException) {
            // se informa con el error de la celda
        }
    }

    /**
     * Celdas del archivo que difieren de lo programado.
     *
     * @param  array<string,mixed>  $grilla
     * @return array{0:int,1:list<array{fila:int,campo:string,vinculo_id:int,fecha:string,turnos:list<int>,actuales:list<int>}>,2:list<array{fila:int,campo:string,mensaje:string}>}
     */
    private function leer(array $grilla, string $ruta, string $extension): array
    {
        $turnos = [];
        foreach ($grilla['turnos'] as $t) {
            $turnos[HojaDeCalculo::normalizar($t['codigo'])] = $t['id'];
        }
        $porDni = collect($grilla['trabajadores'])->groupBy('dni');
        $fechaDe = collect($grilla['periodo']['dias'])->mapWithKeys(fn ($d) => [$d['dia'] => $d['fecha']]);
        $mesTexto = $grilla['periodo']['etiqueta'];

        $columnas = null;
        $filas = 0;
        $cambios = [];
        $errores = [];
        $vistos = [];
        foreach (HojaDeCalculo::filas($ruta, $extension) as $numero => $celdas) {
            if ($columnas === null) {
                $columnas = $this->columnasDeDias($celdas, $fechaDe->all());

                continue;
            }
            $dni = HojaDeCalculo::dni($celdas[0] ?? null);
            if ($dni === null) {
                continue; // fila de los dias de la semana, o una fila en blanco
            }
            $filas++;
            $error = function (string $campo, string $mensaje) use (&$errores, $numero): void {
                $errores[] = ['fila' => $numero, 'campo' => $campo, 'mensaje' => $mensaje];
            };

            $deEsteDni = $porDni->get($dni);
            if ($deEsteDni === null) {
                $error('DNI', "No figura en el personal del establecimiento en {$mesTexto}.");

                continue;
            }
            if ($deEsteDni->count() > 1) {
                $error('DNI', 'Tiene dos vínculos en el establecimiento: prográmalo en la grilla de la pantalla.');

                continue;
            }
            if (isset($vistos[$dni])) {
                $error('DNI', "Repetido en el archivo (también en la fila {$vistos[$dni]}).");

                continue;
            }
            $vistos[$dni] = $numero;
            $trabajador = $deEsteDni->first();

            foreach ($columnas as $indice => $fecha) {
                $campo = 'Día '.(int) substr($fecha, 8, 2);
                $celda = $trabajador['celdas'][$fecha];
                $texto = HojaDeCalculo::texto($celdas[$indice] ?? null);
                if ($celda['ausencia']) {
                    if ($texto !== null && ! preg_match('/^\(.*\)$/', $texto)) {
                        $error($campo, 'Ese día tiene '.mb_strtolower($celda['ausencia']['nombre']).': no se programa.');
                    }

                    continue;
                }

                $ids = [];
                foreach ($texto === null ? [] : preg_split(self::SEPARADORES, $texto) as $codigo) {
                    $id = $turnos[HojaDeCalculo::normalizar($codigo)] ?? null;
                    if ($id === null) {
                        $error($campo, "«{$codigo}» no es un código de turno (ver la hoja «Turnos»).");

                        continue 2;
                    }
                    $ids[] = $id;
                }
                $ids = array_values(array_unique($ids));
                $actuales = array_column($celda['turnos'], 'turno_id');
                if (count($ids) > 3) {
                    $error($campo, 'Como máximo tres turnos por día.');

                    continue;
                }
                if (array_diff($ids, $actuales) !== [] || array_diff($actuales, $ids) !== []) {
                    $cambios[] = ['fila' => $numero, 'campo' => $campo, 'vinculo_id' => $trabajador['vinculo_id'], 'fecha' => $fecha, 'turnos' => $ids, 'actuales' => $actuales];
                }
            }
        }
        if ($columnas === null) {
            throw new DomainException('No se encontró la fila de cabecera (DNI y los días del mes). Descarga la plantilla del mes y úsala.');
        }

        return [$filas, $cambios, $errores];
    }

    /**
     * Si la fila es la cabecera (empieza con "DNI" y trae los numeros de dia), indice de columna => fecha.
     *
     * @param  list<mixed>  $celdas
     * @param  array<int,string>  $fechaDe  dia del mes => fecha
     * @return array<int,string>|null
     */
    private function columnasDeDias(array $celdas, array $fechaDe): ?array
    {
        if (HojaDeCalculo::normalizar((string) HojaDeCalculo::texto($celdas[0] ?? null)) !== 'dni') {
            return null;
        }
        $columnas = [];
        foreach ($celdas as $i => $valor) {
            $texto = HojaDeCalculo::texto($valor);
            if ($i > 0 && $texto !== null && ctype_digit($texto) && isset($fechaDe[(int) $texto])) {
                $columnas[$i] = $fechaDe[(int) $texto];
            }
        }

        return $columnas === [] ? null : $columnas;
    }
}
