<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF del calendario mensual de asistencia, apaisado. Renderiza la vista pdf.asistencia-calendario con EXACTAMENTE el
 * arreglo de CalendarioAsistenciaService (el mismo que consume la pantalla), no una captura de la pagina. La tabla repite
 * su encabezado en cada hoja y no parte una fila entre dos paginas; cada hoja lleva el numero de pagina.
 */
class CalendarioAsistenciaPdf
{
    public const PAPELES = ['a4' => 'A4', 'a3' => 'A3'];

    /** Nombre sugerido del archivo, por ejemplo Asistencia_EESS-LE-01_2026-10.pdf. */
    public static function nombreDeArchivo(array $calendario): string
    {
        return sprintf('Asistencia_%s_%d-%02d.pdf', preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $calendario['eess']['codigo']), $calendario['periodo']['anio'], $calendario['periodo']['mes']);
    }

    /** Memoria que reserva el armado del PDF: dompdf necesita unos 110 MB para un establecimiento de 25 trabajadores. */
    public const MEMORIA_MINIMA_MB = 512;

    /** dompdf construye un objeto por cada nodo y estilo de la tabla: con los 128 MB por omision de PHP no alcanza para un C.S. real. */
    private function reservarMemoria(): void
    {
        $actual = trim((string) ini_get('memory_limit'));
        if ($actual === '-1') {
            return;
        }
        $bytes = (int) $actual * match (strtoupper(substr($actual, -1))) {
            'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1,
        };
        if ($bytes < self::MEMORIA_MINIMA_MB * 1048576) {
            ini_set('memory_limit', self::MEMORIA_MINIMA_MB.'M');
        }
    }

    /**
     * @param  array<string,mixed>  $calendario  salida de CalendarioAsistenciaService::armar()
     * @return string contenido binario del PDF
     */
    public function generar(array $calendario, string $papel = 'a4'): string
    {
        $papel = array_key_exists($papel, self::PAPELES) ? $papel : 'a4';
        $this->reservarMemoria();

        $opciones = new Options;
        $opciones->set('defaultFont', 'Helvetica');
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('isHtml5ParserEnabled', true);

        $pdf = new Dompdf($opciones);
        $pdf->setPaper(self::PAPELES[$papel], 'landscape');
        $pdf->loadHtml(view('pdf.asistencia-calendario', ['c' => $calendario, 'papel' => $papel])->render(), 'UTF-8');
        $pdf->render();

        $lienzo = $pdf->getCanvas();
        $fuente = $pdf->getFontMetrics()->getFont('Helvetica');
        $pie = 'Generado el '.date('d/m/Y H:i', strtotime((string) $calendario['generado_en'])).' · '.$calendario['eess']['nombre'].' · '.$calendario['periodo']['etiqueta'];
        $lienzo->page_text(28, $lienzo->get_height() - 22, $pie, $fuente, 7, [0.35, 0.35, 0.35]);
        $lienzo->page_text($lienzo->get_width() - 90, $lienzo->get_height() - 22, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 7, [0.35, 0.35, 0.35]);

        return $pdf->output();
    }
}
