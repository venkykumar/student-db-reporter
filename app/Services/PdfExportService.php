<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfExportService
{
    public function stream(string $html, string $filename = 'report.pdf'): void
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isPhpEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }
}
