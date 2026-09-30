<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Traduce un error de SQL Server en una respuesta JSON legible. Los Form Request ya
 * rechazan casi todo con 422; esto es la ultima defensa cuando algo llega a la base
 * (concurrencia, un DELETE fisico sobre una fila referenciada, etc.) para que el
 * cliente nunca reciba un 500 con texto crudo del motor.
 */
class ErroresDeBaseDeDatos
{
    /**
     * Devuelve null si el error no es una violacion de restriccion conocida
     * (se deja al manejador por defecto: 500).
     */
    public static function aRespuesta(QueryException $e): ?JsonResponse
    {
        $mensaje = $e->getMessage();

        return match (true) {
            str_contains($mensaje, 'DELETE statement conflicted') => self::json(409, 'No se puede eliminar: el registro está siendo usado por otros datos. Desactívalo en su lugar.'),
            str_contains($mensaje, 'FOREIGN KEY constraint') => self::json(422, 'El registro relacionado indicado no existe.'),
            str_contains($mensaje, 'UNIQUE KEY constraint') || str_contains($mensaje, 'duplicate key') => self::json(409, 'Ya existe un registro con esos datos.'),
            str_contains($mensaje, 'CHECK constraint') => self::json(422, 'Algún valor no cumple las reglas del sistema.'),
            str_contains($mensaje, 'would be truncated') => self::json(422, 'Un texto supera el largo permitido.'),
            str_contains($mensaje, 'Cannot insert the value NULL') => self::json(422, 'Falta un dato obligatorio.'),
            default => null,
        };
    }

    private static function json(int $estado, string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], $estado);
    }
}
