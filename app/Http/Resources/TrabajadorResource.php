<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrabajadorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->TrabajadorId,
            'tipo_documento_id' => $this->TipoDocumentoIdentidadId,
            'profesion_id' => $this->ProfesionId,
            'numero_documento' => $this->TrabajadorNumeroDocumento,
            'nombres' => $this->TrabajadorNombres,
            'apellido_paterno' => $this->TrabajadorApellidoPaterno,
            'apellido_materno' => $this->TrabajadorApellidoMaterno,
            // La base arma "Paterno Materno, Nombres" y deja espacios de mas cuando no hay apellido materno.
            'nombre_completo' => trim(preg_replace('/\s+/', ' ', str_replace(' ,', ',', (string) $this->TrabajadorNombreCompleto))),
            'sexo' => $this->TrabajadorSexo,
            'fecha_nacimiento' => optional($this->TrabajadorFechaNacimiento)->format('Y-m-d'),
            'correo' => $this->TrabajadorCorreo,
            'telefono' => $this->TrabajadorTelefono,
            'direccion' => $this->TrabajadorDireccion,
            'foto_ruta' => $this->TrabajadorFotoRuta,
            'fecha_registro' => optional($this->TrabajadorFechaRegistro)->format('Y-m-d H:i:s'),
            'activo' => (bool) $this->TrabajadorEstado,
            'tipo_documento' => $this->whenLoaded('tipoDocumento', fn () => [
                'id' => $this->tipoDocumento->TipoDocumentoIdentidadId,
                'codigo' => $this->tipoDocumento->TipoDocumentoIdentidadCodigo,
                'nombre' => $this->tipoDocumento->TipoDocumentoIdentidadNombre,
                'abreviatura' => $this->tipoDocumento->TipoDocumentoIdentidadAbreviatura,
            ]),
            // Vinculos que rigen hoy (normalmente uno): donde trabaja y con que cargo y condicion.
            'vinculos' => $this->whenLoaded('vinculosVigentes', fn () => $this->vinculosVigentes->map(fn ($v) => [
                'id' => $v->VinculoLaboralId,
                'cargo' => $v->cargo?->CargoNombre,
                'condicion' => $v->condicionLaboral?->CondicionLaboralNombre,
                'eess' => $v->eess?->EessNombre,
                'sin_horario' => (bool) $v->getAttribute('sin_horario'),
            ])->values()->all()),
            // Colegiaturas activas con su colegio (Personal.Colegiatura INNER JOIN Personal.ColegiaturaTipo).
            'colegiaturas' => $this->whenLoaded('colegiaturas', fn () => $this->colegiaturas->map(fn ($c) => [
                'id' => $c->ColegiaturaId,
                'tipo_id' => $c->ColegiaturaTipoId,
                'tipo_codigo' => $c->tipo?->ColegiaturaTipoCodigo,
                'tipo_nombre' => $c->tipo?->ColegiaturaTipoNombre,
                'numero' => $c->ColegiaturaNumero,
                'fecha_colegiatura' => optional($c->ColegiaturaFechaColegiatura)->format('Y-m-d'),
                'fecha_vencimiento' => optional($c->ColegiaturaFechaVencimiento)->format('Y-m-d'),
                'es_habilitado' => (bool) $c->ColegiaturaEsHabilitado,
                'es_principal' => (bool) $c->ColegiaturaEsPrincipal,
            ])->values()->all()),
            'profesion' => $this->whenLoaded('profesion', fn () => $this->profesion ? [
                'id' => $this->profesion->ProfesionId,
                'nombre' => $this->profesion->ProfesionNombre,
            ] : null),
        ];
    }
}
