<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\QuotationPdf;
use App\Support\AuditLog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Staff-only quotation PDF. An issued quotation streams the exact file that was sent
 * (so its checksum matches the record); a draft is rendered on demand as a watermark-free
 * preview from the saved figures.
 */
class QuotationPdfController extends Controller
{
    public function __invoke(Quotation $quotation, QuotationPdf $pdf): Response
    {
        Gate::authorize('view', $quotation);

        $disk = Storage::disk('local');
        $binary = $quotation->pdf_path && $disk->exists($quotation->pdf_path)
            ? $disk->get($quotation->pdf_path)
            : $pdf->render($quotation);

        AuditLog::record('quotation.pdf_downloaded', ['quotation_id' => $quotation->id], $quotation);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $quotation->quotation_number).'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
