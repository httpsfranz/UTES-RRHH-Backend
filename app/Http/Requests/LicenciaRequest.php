<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReglasDeNegocio;
use App\Models\Personal\VinculoLaboral;
use App\Models\Seguridad\Usuario;
use App\Models\Solicitudes\Licencia;
use App\Models\Solicitudes\TipoLicencia;
use App\Models\Soporte\DocumentoSustento;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Licencia (RIT, Art. 30 a 56). Nace PENDIENTE; la oficializa Recursos Humanos con la accion aprobar.
 */
class LicenciaRequest extends CatalogoRequest
{
    use ReglasDeNegocio;

    /** Dias de licencia sin goce por motivos particulares a partir de los cuales el PAD en curso la impide (RIT, Art. 107). */
    public const DIAS_PARTICULARES_CON_PAD = 5;

    public function rules(): array
    {
        return [
            'VinculoLaboralId' => [$this->obligatorio(), 'integer', $this->existeActivo(VinculoLaboral::class, 'VinculoLaboralEstado', 'VinculoLaboralId')],
            'TipoLicenciaId' => [$this->obligatorio(), 'integer', $this->existeActivo(TipoLicencia::class, 'TipoLicenciaEstado', 'TipoLicenciaId')],
            'DocumentoSustentoId' => ['nullable', 'integer', $this->existe(DocumentoSustento::class)],
            // Quien registra. Se completara con el usuario autenticado cuando exista el login (M04).
            'UsuarioRegistroId' => ['nullable', 'integer', $this->existeActivo(Usuario::class, 'UsuarioEstado', 'UsuarioRegistroId')],
            'LicenciaNumeroResolucion' => ['nullable', 'string', 'max:60'],
            'LicenciaFechaInicio' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'LicenciaFechaFin' => [$this->obligatorio(), 'date_format:Y-m-d'],
            'LicenciaMotivo' => $this->texto(1000),
        ];
    }

    public function messages(): array
    {
        return [
            'LicenciaFechaInicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'LicenciaFechaFin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
        ];
    }

    /**
     * - fin >= inicio; el vinculo vigente en todo el rango.
     * - Los dias se cuentan corridos, con sabados, domingos y feriados (RIT, Art. 55) y no superan el maximo del tipo.
     * - Sin superposicion con otra licencia (pendiente o aprobada) del mismo trabajador.
     * - El servidor con un PAD en curso no puede tomar licencia sin goce por motivos particulares de mas de 5 dias (Art. 107).
     * - Solo se modifica pendiente; el estado no se envia.
     */
    public function withValidator(Validator $validator): void
    {
        $this->despuesDeValidar($validator, function (Validator $validator) {
            $this->rechazaEstadoEnviado($validator, 'LicenciaEstado');
            $this->soloSiPendiente($validator, 'LicenciaEstado', 'LicenciaEstado', 'La licencia');
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $inicio = $this->fechaEfectiva('LicenciaFechaInicio');
            $fin = $this->fechaEfectiva('LicenciaFechaFin');
            if ($fin < $inicio) {
                $validator->errors()->add('LicenciaFechaFin', 'La fecha de fin no puede ser anterior a la fecha de inicio.');

                return;
            }

            $this->vinculoVigenteEn($validator, 'VinculoLaboralId', $inicio, $fin);

            $dias = (int) ((strtotime($fin) - strtotime($inicio)) / 86400) + 1;
            $tipo = TipoLicencia::query()->find($this->valorEfectivo('TipoLicenciaId'));
            if ($tipo?->TipoLicenciaMaximoDias !== null && $dias > $tipo->TipoLicenciaMaximoDias) {
                $validator->errors()->add('LicenciaFechaFin', "La licencia por \"{$tipo->TipoLicenciaNombre}\" admite como máximo {$tipo->TipoLicenciaMaximoDias} días corridos (se piden {$dias}).");
            }

            $solapa = $this->haySuperposicion(
                Licencia::class, 'LicenciaFechaInicio', 'LicenciaFechaFin', $inicio, $fin,
                ['VinculoLaboralId' => $this->valorEfectivo('VinculoLaboralId')], 'LicenciaEstado', ['RECHAZADO', 'ANULADO'],
            );
            if ($solapa) {
                $validator->errors()->add('LicenciaFechaInicio', 'El trabajador ya tiene una licencia pendiente o aprobada que se superpone con esas fechas.');
            }

            if ($tipo?->TipoLicenciaCodigo === 'SIN_GOCE' && $dias > self::DIAS_PARTICULARES_CON_PAD
                && DB::table('Disciplina.ExpedientePad')->where('VinculoLaboralId', $this->valorEfectivo('VinculoLaboralId'))->whereIn('ExpedientePadEstado', ['INICIADO', 'EN_PROCESO'])->exists()) {
                $validator->errors()->add('TipoLicenciaId', 'El servidor tiene un procedimiento disciplinario en curso: no puede tomar licencia sin goce por motivos particulares de más de 5 días (RIT, Art. 107).');
            }
        });
    }
}
