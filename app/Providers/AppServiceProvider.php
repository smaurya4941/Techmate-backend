<?php

namespace App\Providers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductSpecification;
use App\Models\Setting;
use App\Models\User;
use App\Observers\PublicContentObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production traffic is expected to arrive over TLS (terminated at the proxy).
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }

        $this->configureRateLimiting();
        $this->registerPublicContentObservers();

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        // Make notification/mail failures observable without logging recipients or bodies.
        Event::listen(JobFailed::class, function (JobFailed $event) {
            Log::error('Queued job failed.', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'exception' => $event->exception::class,
            ]);
        });
    }

    private function configureRateLimiting(): void
    {
        // Config is read inside the closures so limits can be tuned per environment/test.
        RateLimiter::for('public-read', fn (Request $request) => Limit::perMinute(
            (int) config('techmate.rate_limits.read_per_minute')
        )->by($request->ip()));

        // Burst limit plus a slower hourly ceiling; keyed per IP and per route
        // so one noisy form cannot lock a visitor out of the others.
        RateLimiter::for('public-submissions', function (Request $request) {
            $key = $request->ip().'|'.$request->path();

            return [
                Limit::perMinute((int) config('techmate.rate_limits.submit_per_minute'))->by($key),
                Limit::perHour((int) config('techmate.rate_limits.submit_per_hour'))->by($key),
            ];
        });
    }

    /** Public API rows changed in the admin ping the frontend revalidation webhook (when configured). */
    private function registerPublicContentObservers(): void
    {
        foreach ([Product::class, Category::class, Brand::class, Page::class, Setting::class, ProductImage::class, ProductSpecification::class, ProductDocument::class] as $model) {
            $model::observe(PublicContentObserver::class);
        }
    }
}
