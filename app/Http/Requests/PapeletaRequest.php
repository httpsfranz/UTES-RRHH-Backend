<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Solicitudes\MotivoPapeleta;
use App\Models\Solicitudes\Papeleta;
use App\Models\Solicitudes\TipoPapeleta;
use App\Models\Soporte\DocumentoSustento;
use App\Support\HoraLocal;
use App\Support\ReglasDeSolicitud;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Papeleta de permiso o salida (RIT, Art. 16 y 57 a 63). Nace PENDIENTE; la autoriza el jefe con la accion aprobar.
 * RIT: la papeleta de comision de servicios vale como maximo 3 horas; el exceso se justifica con documento.
 */
class PapeletaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** Horas maximas de una papeleta de comision de servicios (RIT, Art. 16). */
    public const MAXIMO_COMISION_MINUTOS = 180;

    /** Lactancia: una hora diaria, dos en parto multiple, hasta el primer anio (RIT, Art. 60). */
    public const MINUTOS_LACTANCIA = 60;

    /** Permiso por docencia o estudios: seis horas semanales, compensables (RIT, Art. 63). */
    public const MINUTOS_SEMANALES_ESTUDIOS = 360;

    /** Anticipacion del permiso, en horas (RIT, Art. 57). */
    public const HORAS_DE_ANTICIPACION = 24;

    /** Tipos que no se piden: los registra el sistema o tienen fecha fija. */
    private const SIN_ANTICIPACION = ['COMISION', 'LACTANCIA', 'ONOMASTICO', 'SINDICAL'];

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'TipoPapeletaId' => [$this->obligatorio(), 'integer', $this->existeActivo(TipoPapeleta::class, 'TipoPapeletaEstado', 'TipoPapeletaId')],
            'MotivoPapeletaId' => ['nullable', 'integer', $this->existeActivo(MotivoPapeleta::class, 'MotivoPapeletaEstado', 'MotivoPapeletaId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            'UsuarioRegistroId' => ['nullable', 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioRegistroId')],
            'PapeletaNumero' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/.]*$/D', $this->unico(Papeleta::class, 'PapeletaNumero')],
            'PapeletaFecha' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'PapeletaHoraSalida' => ['nullable', 'date_format:H:i,H:i:s'],
            'PapeletaHoraRetorno' => ['nullable', 'date_format:H:i,H:i:s'],
            'PapeletaEsDiaCompleto' => $this->booleano(),
            'PapeletaMinutosUtilizados' => ['nullable', 'integer', 'between:0,1440'],
            'PapeletaMotivo' => $this->texto(1000),
            'PapeletaObservacion' => $this->texto(1000),
            'PapeletaEsUrgente' => $this->booleano(),
            'PapeletaPartoMultiple' => $this->booleano(),
            'PapeletaFechaNacimientoHijo' => ['nullable', 'date_format:Y-m-d'],
            'PapeletaUnidadDestino' => $this->texto(200),
            'PapeletaConformeDestino' => $this->texto(150),
        ];
    }

    public function messages(): array
    {
        return [
            'PapeletaNumero.regex' => 'El número solo admite letras, números, guion, barra y punto.',
            'PapeletaHoraSalida.date_format' => 'La hora de salida debe tener el formato HH:MM.',
            'PapeletaHoraRetorno.date_format' => 'La hora de retorno debe tener el formato HH:MM.',
            'PapeletaMinutosUtilizados.between' => 'Los minutos utilizados deben estar entre 0 y 1440.',
        ];
    }

    /**
     * - El vinculo vigente ese dia y el periodo de asistencia no cerrado.
     * - Dia completo = sin horas; si no, hora de salida obligatoria y retorno no anterior a la salida.
     * - La comision de servicios no pasa de 3 horas e indica la unidad de destino (RIT, Art. 16).
     * - El permiso se pide con 24 horas, salvo urgencia (Art. 57); lactancia, estudios y docencia tienen tope (Art. 60 y 63).
     * - El motivo debe pertenecer al tipo (FK compuesta de la tabla).
     * - Sin superposicion con otra papeleta (pendiente o aprobada) del mismo trabajador ese dia.
     * - Solo se modifica pendiente; el estado y quien autoriza no se envian.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'PapeletaEstado');
            $this->soloSiPendiente($validator, 'PapeletaEstado', 'PapeletaEstado', 'La papeleta');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fecha = $this->fechaEfectiva('PapeletaFecha');
            $diaCompleto = $this->booleanoEfectivo('PapeletaEsDiaCompleto', false);
            $salida = $this->hora('PapeletaHoraSalida');
            $retorno = $this->hora('PapeletaHoraRetorno');

            $this->vinculoVigenteEn($validator, 'PapeletaFecha', $fecha);
            $this->rechazaPeriodoCerrado($validator, 'PapeletaFecha', $fecha);

            if ($diaCompleto && ($salida || $retorno)) {
                $validator->errors()->add('PapeletaEsDiaCompleto', 'Una papeleta de día completo no lleva horas de salida ni de retorno.');
            }
            if (! $diaCompleto && ! $salida) {
                $validator->errors()->add('PapeletaHoraSalida', 'Indica la hora de salida (o marca la papeleta como de día completo).');
            }
            if ($salida && $retorno && $retorno < $salida) {
                $validator->errors()->add('PapeletaHoraRetorno', 'La hora de retorno no puede ser anterior a la de salida.');
            }

            $tipo = TipoPapeleta::query()->find($this->valorEfectivo('TipoPapeletaId'));
            if ($tipo?->TipoPapeletaCodigo === 'COMISION' && $salida && $retorno
                && (strtotime("1970-01-01 {$retorno}") - strtotime("1970-01-01 {$salida}")) / 60 > self::MAXIMO_COMISION_MINUTOS) {
                $validator->errors()->add('PapeletaHoraRetorno', 'La papeleta de comisión de servicios vale como máximo 3 horas (RIT, Art. 16); el exceso se justifica con documento.');
            }

            $this->reglasDelTipo($validator, $tipo?->TipoPapeletaCodigo, $fecha, $salida, $retorno, $diaCompleto);

            $motivoId = $this->valorEfectivo('MotivoPapeletaId');
            if ($motivoId && MotivoPapeleta::query()->whereKey($motivoId)->where('TipoPapeletaId', $this->valorEfectivo('TipoPapeletaId'))->doesntExist()) {
                $validator->errors()->add('MotivoPapeletaId', 'El motivo no pertenece al tipo de papeleta elegido.');
            }

            $hasta = $retorno ?? '23:59:59';
            $solapa = Papeleta::query()
                ->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))
                ->whereDate('PapeletaFecha', $fecha)
                ->whereNotIn('PapeletaEstado', ['RECHAZADO', 'ANULADO'])
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))
                // Un dia completo choca con cualquier otra papeleta del dia; las papeletas por horas, solo si las horas se cruzan.
                ->when(! $diaCompleto, fn ($q) => $q->where(fn ($w) => $w->where('PapeletaEsDiaCompleto', 1)
                    ->orWhereNull('PapeletaHoraSalida')
                    ->orWhere(fn ($h) => $h->where('PapeletaHoraSalida', '<', $hasta)
                        ->where(fn ($r) => $r->whereNull('PapeletaHoraRetorno')->orWhere('PapeletaHoraRetorno', '>', $salida ?? '00:00:00')))))
                ->exists();
            if ($solapa) {
                $validator->errors()->add('PapeletaFecha', 'El trabajador ya tiene una papeleta pendiente o aprobada que se superpone ese día.');
            }
        });
    }

    /** Reglas propias de cada tipo de permiso (RIT, Art. 16, 44, 57, 60 y 63). */
    private function reglasDelTipo(Validator $validator, ?string $codigo, string $fecha, ?string $salida, ?string $retorno, bool $diaCompleto): void
    {
        $vinculo = VinculoLaboral::query()->find($this->valorEfectivo('VinculoLaboralId'));
        $minutos = $salida && $retorno ? (int) ((strtotime("1970-01-01 {$retorno}") - strtotime("1970-01-01 {$salida}")) / 60) : 0;

        if ($this->esCreacion() && ReglasDeSolicitud::controlDePlazos() && ! in_array($codigo, self::SIN_ANTICIPACION, true) && ! $this->booleanoEfectivo('PapeletaEsUrgente', false)) {
            $momento = strtotime($fecha.' '.($salida ?? '07:30:00'));
            if ($momento - HoraLocal::ahora()->getTimestamp() < self::HORAS_DE_ANTICIPACION * 3600) {
                $validator->errors()->add('PapeletaFecha', 'El permiso se solicita con 24 horas de anticipación; si es urgente, márcalo como tal e indica el motivo (RIT, Art. 57).');
            }
        }
        if ($this->booleanoEfectivo('PapeletaEsUrgente', false) && blank($this->valorEfectivo('PapeletaMotivo'))) {
            $validator->errors()->add('PapeletaMotivo', 'Indica el motivo de la urgencia (RIT, Art. 57).');
        }

        if ($codigo === 'COMISION' && blank($this->valorEfectivo('PapeletaUnidadDestino'))) {
            $validator->errors()->add('PapeletaUnidadDestino', 'Indica la unidad de destino, que firma la papeleta (RIT, Art. 16).');
        }
        if ($codigo === 'LACTANCIA') {
            if (ReglasDeSolicitud::sexoDe($vinculo) === 'M') {
                $validator->errors()->add('VinculoLaboralId', 'El permiso por lactancia corresponde a la servidora (RIT, Art. 60).');
            }
            $tope = self::MINUTOS_LACTANCIA * ($this->booleanoEfectivo('PapeletaPartoMultiple', false) ? 2 : 1);
            if ($diaCompleto || $minutos > $tope) {
                $validator->errors()->add('PapeletaHoraRetorno', 'El permiso por lactancia es de '.($tope / 60).' hora(s) diaria(s) (RIT, Art. 60).');
            }
            $nacimiento = $this->valorEfectivo('PapeletaFechaNacimientoHijo');
            if (blank($nacimiento)) {
                $validator->errors()->add('PapeletaFechaNacimientoHijo', 'Indica la fecha de nacimiento: el permiso rige hasta el primer año (RIT, Art. 60).');
            } elseif (strtotime($fecha) > strtotime(substr((string) $nacimiento, 0, 10).' +1 year')) {
                $validator->errors()->add('PapeletaFecha', 'El hijo ya cumplió un año: el permiso por lactancia terminó (RIT, Art. 60).');
            }
        }
        if (in_array($codigo, ['ESTUDIOS', 'DOCENCIA'], true)) {
            $lunes = date('Y-m-d', strtotime($fecha.' -'.(((int) date('N', strtotime($fecha))) - 1).' days'));
            $domingo = date('Y-m-d', strtotime($lunes.' +6 days'));
            $usados = (int) Papeleta::query()->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))->where('TipoPapeletaId', $this->valorEfectivo('TipoPapeletaId'))
                ->whereNotIn('PapeletaEstado', ['RECHAZADO', 'ANULADO'])->whereDate('PapeletaFecha', '>=', $lunes)->whereDate('PapeletaFecha', '<=', $domingo)
                ->when($this->registroId(), fn ($q, $id) => $q->whereKeyNot($id))->sum('PapeletaMinutosUtilizados');
            if ($usados + $minutos > self::MINUTOS_SEMANALES_ESTUDIOS) {
                $validator->errors()->add('PapeletaHoraRetorno', 'El permiso por docencia o estudios llega hasta 6 horas semanales (RIT, Art. 63).');
            }
        }
        if ($codigo === 'ONOMASTICO') {
            $nacimiento = DB::table('Personal.Trabajador')->where('TrabajadorId', $vinculo->TrabajadorId)->value('TrabajadorFechaNacimiento');
            if ($nacimiento !== null && ! $this->esDiaDeOnomastico(substr((string) $nacimiento, 5, 5), $fecha)) {
                $validator->errors()->add('PapeletaFecha', 'La licencia por onomástico se toma el día del cumpleaños o, si cae en día no hábil, el primer día hábil siguiente (RIT, Art. 44).');
            }
        }
        if ($codigo === 'CAPACITACION' && ReglasDeSolicitud::tienePadEnCurso((int) $vinculo->VinculoLaboralId)) {
            $validator->errors()->add('TipoPapeletaId', 'Con un procedimiento disciplinario en curso no se reciben capacitaciones de la Entidad (RIT, Art. 120).');
        }
    }

    /** El cumpleaños (MM-DD), o el primer dia habil siguiente si cae en sabado, domingo o feriado. */
    private function esDiaDeOnomastico(string $mesDia, string $fecha): bool
    {
        $dia = substr($fecha, 0, 4).'-'.$mesDia;
        while (date('N', strtotime($dia)) >= 6 || DB::table('Soporte.CalendarioNoLaborable')->where('CalendarioNoLaborableEstado', 1)->whereDate('CalendarioNoLaborableFecha', $dia)->exists()) {
            $dia = date('Y-m-d', strtotime($dia.' +1 day'));
        }

        return $dia === $fecha;
    }

    /** "HH:MM:SS" de la hora que quedara guardada (null si no hay). */
    private function hora(string $campo): ?string
    {
        $valor = $this->valorEfectivo($campo);
        if (blank($valor)) {
            return null;
        }

        return strlen((string) $valor) === 5 ? "{$valor}:00" : substr((string) $valor, 0, 8);
    }

    public function datos(): array
    {
        $datos = $this->validated();

        foreach (['PapeletaHoraSalida', 'PapeletaHoraRetorno'] as $campo) {
            if (isset($datos[$campo]) && strlen($datos[$campo]) === 5) {
                $datos[$campo] .= ':00';
            }
        }
        // Dia completo: se limpian las horas que hubieran quedado de una edicion anterior.
        if ($this->booleanoEfectivo('PapeletaEsDiaCompleto', false)) {
            $datos['PapeletaHoraSalida'] = null;
            $datos['PapeletaHoraRetorno'] = null;
        }
        // Un valor vacio (el formulario lo envia asi) significa "calcular", no NULL.
        if (array_key_exists('PapeletaMinutosUtilizados', $datos) && $datos['PapeletaMinutosUtilizados'] === null) {
            unset($datos['PapeletaMinutosUtilizados']);
        }
        // Minutos utilizados = retorno - salida, salvo que se indiquen.
        $salida = $this->hora('PapeletaHoraSalida');
        $retorno = $this->hora('PapeletaHoraRetorno');
        if (! array_key_exists('PapeletaMinutosUtilizados', $datos) && $salida && $retorno && ! $this->booleanoEfectivo('PapeletaEsDiaCompleto', false)) {
            $datos['PapeletaMinutosUtilizados'] = (int) ((strtotime("1970-01-01 {$retorno}") - strtotime("1970-01-01 {$salida}")) / 60);
        }

        return $datos;
    }
}
