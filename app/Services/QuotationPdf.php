<?php

namespace App\Services;

use App\Models\Quotation;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the customer-facing quotation from the stored snapshot. Totals printed are
 * the values saved by QuotationService (never recomputed from live prices), so the
 * document always matches the record. Files live on the private disk.
 */
class QuotationPdf
{
    public function render(Quotation $quotation): string
    {
        $quotation->loadMissing(['items', 'approver:id,name', 'creator:id,name']);

        $options = new Options;
        $options->set('isRemoteEnabled', false);   // no external fetches while rendering
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('pdf.quotation', [
            'q' => $quotation,
            'company' => $this->company(),
            'tz' => config('techmate.business_timezone'),
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @return array{path: string, checksum: string} */
    public function store(Quotation $quotation): array
    {
        $binary = $this->render($quotation);
        $path = 'quotations/'.$quotation->quotation_number.'.pdf';

        Storage::disk('local')->put($path, $binary);

        return ['path' => $path, 'checksum' => hash('sha256', $binary)];
    }

    /** @return array<string, ?string> */
    private function company(): array
    {
        $s = SiteSettings::all();

        return [
            'name' => $s['company_name'] ?: 'Techmate Industries',
            'address' => $s['contact_address'] ?? null,
            'email' => $s['contact_email'] ?? null,
            'phone' => $s['contact_phone'] ?? null,
        ];
    }
}
