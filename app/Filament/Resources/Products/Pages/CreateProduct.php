<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\ContentPublisher;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /** status/published_at are not mass-assignable; the publisher applies them. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ContentPublisher::class)->save(new Product, $data, auth()->user());
    }
}
