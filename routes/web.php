<?php

use App\Http\Controllers\Admin\ProductDocumentDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Private product documents are streamed only to authorised staff; they are never in the public storage link.
Route::middleware(['web', 'auth', 'throttle:60,1'])
    ->get('/admin/product-documents/{document}/download', ProductDocumentDownloadController::class)
    ->name('admin.product-documents.download');
