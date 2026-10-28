<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductDocument;
use App\Support\AuditLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff-only download of a product document from the private disk. */
class ProductDocumentDownloadController extends Controller
{
    public function __invoke(ProductDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        $disk = Storage::disk(ProductDocument::PRIVATE_DISK);
        abort_unless($disk->exists($document->file_path), 404);

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: $document->title);

        AuditLog::record('document.downloaded', ['document_id' => $document->id]);

        return $disk->download($document->file_path, trim($name).'.'.$extension, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
