<?php

namespace App\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class Guarded
{
    /**
     * Runs a workflow call and turns business-rule failures into a visible
     * notification. Authorization failures are deliberately NOT caught: they
     * bubble up as a 403 so a crafted request is refused, not smoothed over.
     */
    public static function run(Closure $callback, ?string $successTitle = null): mixed
    {
        try {
            $result = $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first() ?? 'The change could not be saved.')->send();

            return null;
        }

        if ($successTitle) {
            Notification::make()->success()->title($successTitle)->send();
        }

        return $result ?? true;
    }
}
