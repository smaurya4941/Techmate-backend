<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Publication state (status / published_at) is guarded against mass assignment,
 * so every create, edit and status change for products and pages is funnelled
 * through here. Authorization is re-checked on the server for each call.
 */
class ContentPublisher
{
    /**
     * @param  array<string, mixed>  $data  Form data; may contain a `status` key.
     */
    public function save(Model $record, array $data, User $actor): Model
    {
        Gate::forUser($actor)->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : $record::class);

        $status = $data['status'] ?? null;
        unset($data['status']);

        return DB::transaction(function () use ($record, $data, $status) {
            $record->fill($data);

            if ($status !== null) {
                $this->applyStatus($record, $status);
            }

            $record->save();

            if ($record->wasChanged('status')) {
                AuditLog::record('content.status_changed', [
                    'model' => class_basename($record), 'id' => $record->getKey(), 'status' => $record->status,
                ]);
            }

            return $record;
        });
    }

    public function publish(Model $record, User $actor): Model
    {
        return $this->save($record, ['status' => 'published'], $actor);
    }

    public function unpublish(Model $record, User $actor): Model
    {
        return $this->save($record, ['status' => 'draft'], $actor);
    }

    public function archive(Product $product, User $actor): Product
    {
        return $this->save($product, ['status' => Product::STATUS_ARCHIVED], $actor);
    }

    private function applyStatus(Model $record, string $status): void
    {
        $allowed = $record instanceof Product ? Product::STATUSES : Page::STATUSES;

        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown publication status.']);
        }

        $record->status = $status;

        if ($status === 'published') {
            $record->published_at ??= now();
        }
    }
}
