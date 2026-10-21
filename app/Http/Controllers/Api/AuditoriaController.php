<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditoriaResource;
use App\Models\Seguridad\Auditoria;
use App\Support\RegistroAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Controller de SOLO LECTURA a proposito: un registro de auditoria no se crea, edita ni borra via API (y la base lo
// impide con el trigger TR_Auditoria_Inmutable, V005). Lo escribe el propio sistema: altas, ediciones y bajas
// (RegistroAuditoria::registrar) y eventos de seguridad y consultas sensibles (RegistroAuditoria::evento).
class AuditoriaController extends Controller
{
    // GET /api/auditoria?usuario_id=&usuario=texto&modulo=&operacion=&tabla=&resultado=&registro_id=&desde=&hasta=&buscar=
    public function index(Request $request)
    {
        return AuditoriaResource::collection($this->filtrada($request)->with('usuario:UsuarioId,UsuarioNombre')
            ->paginate(max(1, min($request->integer('por_pagina', 15), 100))));
    }

    // GET /api/auditoria/{auditoria}
    public function show(Auditoria $auditoria)
    {
        return new AuditoriaResource($auditoria->load('usuario:UsuarioId,UsuarioNombre'));
    }

    // GET /api/auditoria/exportar?(mismos filtros)   Excel con hasta 20 000 registros; la exportacion queda auditada
    public function exportar(Request $request): BinaryFileResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'aud').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($ruta);
        $escritor->getCurrentSheet()->setName('Auditoria');
        $escritor->addRow(Row::fromValuesWithStyle(['Fecha y hora', 'Usuario', 'Módulo', 'Entidad', 'Acción', 'Resultado', 'Registro', 'Detalle', 'Datos anteriores', 'Datos nuevos', 'IP'],
            (new Style)->withFontBold(true)->withBackgroundColor('D9EEF0')));
        $total = 0;
        $this->filtrada($request)->with('usuario:UsuarioId,UsuarioNombre')->limit(20000)->get()->each(function (Auditoria $a) use ($escritor, &$total) {
            $total++;
            $escritor->addRow(Row::fromValues([
                optional($a->AuditoriaFechaHora)->format('Y-m-d H:i:s'), $a->usuario?->UsuarioNombre, $a->AuditoriaModulo ?? RegistroAuditoria::modulo($a->AuditoriaEsquema),
                $a->AuditoriaEsquema.'.'.$a->AuditoriaTabla, $a->AuditoriaOperacion, $a->AuditoriaResultado, $a->AuditoriaRegistroId, $a->AuditoriaDetalle,
                mb_substr((string) $a->AuditoriaDatosAnteriores, 0, 30000), mb_substr((string) $a->AuditoriaDatosNuevos, 0, 30000), $a->AuditoriaDireccionIp,
            ]));
        });
        $escritor->close();
        RegistroAuditoria::evento('EXPORTAR', 'Seguridad', 'Auditoria', null, "Exportó {$total} registros de auditoría (filtros: ".json_encode(array_filter($request->query()), JSON_UNESCAPED_UNICODE).').');

        return response()->download($ruta, 'Auditoria_'.now()->format('Ymd_His').'.xlsx')->deleteFileAfterSend();
    }

    /** @return Builder<Auditoria> */
    private function filtrada(Request $request): Builder
    {
        $texto = fn (string $campo) => $request->string($campo)->toString();

        return Auditoria::query()
            ->when($request->filled('esquema'), fn ($q) => $q->where('AuditoriaEsquema', $texto('esquema')))
            ->when($request->filled('modulo'), fn ($q) => $q->where(fn ($s) => $s->where('AuditoriaModulo', $texto('modulo'))->orWhere('AuditoriaEsquema', $texto('modulo'))))
            ->when($request->filled('tabla'), fn ($q) => $q->where('AuditoriaTabla', 'like', '%'.$texto('tabla').'%'))
            ->when($request->filled('operacion'), fn ($q) => $q->where('AuditoriaOperacion', $texto('operacion')))
            ->when($request->filled('resultado'), fn ($q) => $q->where('AuditoriaResultado', $texto('resultado')))
            ->when($request->filled('registro_id'), fn ($q) => $q->where('AuditoriaRegistroId', $texto('registro_id')))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('UsuarioId', $request->integer('usuario_id')))
            ->when($request->filled('usuario'), fn ($q) => $q->whereHas('usuario', fn ($u) => $u->where('UsuarioNombre', 'like', '%'.$texto('usuario').'%')))
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($s) => $s->where('AuditoriaTabla', 'like', '%'.$texto('buscar').'%')
                ->orWhere('AuditoriaDetalle', 'like', '%'.$texto('buscar').'%')->orWhere('AuditoriaRegistroId', $texto('buscar'))
                ->orWhereHas('usuario', fn ($u) => $u->where('UsuarioNombre', 'like', '%'.$texto('buscar').'%'))))
            ->when($request->filled('desde'), fn ($q) => $q->where('AuditoriaFechaHora', '>=', $request->date('desde')->startOfDay()))
            ->when($request->filled('hasta'), fn ($q) => $q->where('AuditoriaFechaHora', '<=', $request->date('hasta')->endOfDay()))
            ->orderByDesc('AuditoriaFechaHora')->orderByDesc('AuditoriaId');
    }
}
