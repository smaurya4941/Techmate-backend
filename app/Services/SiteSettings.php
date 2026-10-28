<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Rules\HttpUrl;
use App\Rules\PhoneNumber;
use App\Rules\SiteLink;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Typed allowlist of the public website settings. Only keys declared here can
 * be written, each with its own validation, so the settings table can never
 * become a free-form store (and never holds secrets). All declared keys are
 * public by design: they feed GET /api/v1/settings.
 */
class SiteSettings
{
    /**
     * key => [group, label, rules, input]
     * input: text | textarea | url | email | tel
     *
     * @return array<string, array{group: string, label: string, rules: list<mixed>, input: string}>
     */
    public static function definitions(): array
    {
        $url = ['nullable', 'string', 'max:255', new HttpUrl];
        $link = ['nullable', 'string', 'max:255', new SiteLink];

        return [
            'company_name' => ['group' => 'company', 'label' => 'Company name', 'rules' => ['required', 'string', 'max:150'], 'input' => 'text'],
            'company_tagline' => ['group' => 'company', 'label' => 'Tagline', 'rules' => ['nullable', 'string', 'max:200'], 'input' => 'text'],

            'contact_phone' => ['group' => 'contact', 'label' => 'Public phone', 'rules' => ['nullable', new PhoneNumber], 'input' => 'tel'],
            'contact_email' => ['group' => 'contact', 'label' => 'Public email', 'rules' => ['nullable', 'email:rfc', 'max:255'], 'input' => 'email'],
            'contact_address' => ['group' => 'contact', 'label' => 'Public address', 'rules' => ['nullable', 'string', 'max:500'], 'input' => 'textarea'],
            'business_hours' => ['group' => 'contact', 'label' => 'Business hours', 'rules' => ['nullable', 'string', 'max:300'], 'input' => 'textarea'],
            'whatsapp_number' => ['group' => 'contact', 'label' => 'WhatsApp number (country code + digits only)', 'rules' => ['nullable', 'regex:/^[0-9]{8,15}$/'], 'input' => 'text'],

            'social_linkedin' => ['group' => 'social', 'label' => 'LinkedIn URL', 'rules' => $url, 'input' => 'url'],
            'social_facebook' => ['group' => 'social', 'label' => 'Facebook URL', 'rules' => $url, 'input' => 'url'],
            'social_instagram' => ['group' => 'social', 'label' => 'Instagram URL', 'rules' => $url, 'input' => 'url'],
            'social_youtube' => ['group' => 'social', 'label' => 'YouTube URL', 'rules' => $url, 'input' => 'url'],

            'footer_text' => ['group' => 'footer', 'label' => 'Footer text', 'rules' => ['nullable', 'string', 'max:500'], 'input' => 'textarea'],

            'seo_default_title' => ['group' => 'seo', 'label' => 'Default meta title', 'rules' => ['nullable', 'string', 'max:160'], 'input' => 'text'],
            'seo_default_description' => ['group' => 'seo', 'label' => 'Default meta description', 'rules' => ['nullable', 'string', 'max:320'], 'input' => 'textarea'],

            'hero_heading' => ['group' => 'homepage', 'label' => 'Hero heading', 'rules' => ['nullable', 'string', 'max:160'], 'input' => 'text'],
            'hero_subheading' => ['group' => 'homepage', 'label' => 'Hero supporting copy', 'rules' => ['nullable', 'string', 'max:400'], 'input' => 'textarea'],
            'cta_primary_label' => ['group' => 'homepage', 'label' => 'Primary CTA label', 'rules' => ['nullable', 'string', 'max:40'], 'input' => 'text'],
            'cta_primary_link' => ['group' => 'homepage', 'label' => 'Primary CTA link', 'rules' => $link, 'input' => 'text'],
            'cta_quote_label' => ['group' => 'homepage', 'label' => 'Quote CTA label', 'rules' => ['nullable', 'string', 'max:40'], 'input' => 'text'],
            'cta_quote_link' => ['group' => 'homepage', 'label' => 'Quote CTA link', 'rules' => $link, 'input' => 'text'],
            'final_cta_heading' => ['group' => 'homepage', 'label' => 'Final enquiry heading', 'rules' => ['nullable', 'string', 'max:160'], 'input' => 'text'],
            'final_cta_text' => ['group' => 'homepage', 'label' => 'Final enquiry copy', 'rules' => ['nullable', 'string', 'max:400'], 'input' => 'textarea'],
        ];
    }

    public const GROUP_LABELS = [
        'company' => 'Company',
        'contact' => 'Contact details',
        'social' => 'Social links',
        'footer' => 'Footer',
        'seo' => 'Default SEO',
        'homepage' => 'Homepage hero & calls to action',
    ];

    /** @return array<string, ?string> every declared key, null when unset */
    public static function all(): array
    {
        $stored = Setting::query()->whereIn('key', array_keys(self::definitions()))->pluck('value', 'key');

        return collect(self::definitions())->map(fn ($d, $key) => $stored[$key] ?? null)->all();
    }

    /** @return array<string, ?string> only filled public values, for the API */
    public static function publicValues(): array
    {
        return array_filter(self::all(), fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, ?string> the validated, normalised values
     */
    public function save(array $input, User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', Setting::class);

        $definitions = self::definitions();
        $rules = collect($definitions)->map(fn ($d) => $d['rules'])->all();
        $unknown = array_diff(array_keys($input), array_keys($definitions));

        // Unknown keys are rejected rather than silently dropped.
        $validator = Validator::make($input, $rules);
        $validator->after(function ($v) use ($unknown) {
            foreach ($unknown as $key) {
                $v->errors()->add($key, 'This setting does not exist.');
            }
        });
        $validated = $validator->validate();

        $changed = [];
        DB::transaction(function () use ($validated, $definitions, &$changed) {
            $current = self::all();

            foreach ($validated as $key => $value) {
                $value = is_string($value) ? trim(strip_tags($value)) : $value;
                $value = $value === '' ? null : $value;

                if (($current[$key] ?? null) === $value) {
                    continue;
                }

                $setting = Setting::firstOrNew(['key' => $key]);
                $setting->fill(['value' => $value, 'value_type' => 'string', 'group' => $definitions[$key]['group'], 'is_public' => true])->save();
                $changed[] = $key;
            }
        });

        if ($changed !== []) {
            // Keys only: values are never written to the log.
            AuditLog::record('settings.updated', ['keys' => $changed]);
        }

        return self::all();
    }
}
