<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\SiteSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class CmsAndSettingsTest extends AdminTestCase
{
    private function pageData(array $overrides = []): array
    {
        return [
            'title' => 'About Techmate', 'slug' => 'about', 'section' => 'page', 'sort_order' => 0,
            'content' => '<p>Hello</p>', 'status' => 'draft', ...$overrides,
        ];
    }

    public function test_catalogue_managers_create_and_publish_cms_pages(): void
    {
        $manager = $this->catalogueManager();

        Livewire::actingAs($manager)->test(CreatePage::class)
            ->fillForm($this->pageData())->call('create')->assertHasNoFormErrors();

        $page = Page::where('slug', 'about')->firstOrFail();
        $this->assertSame('draft', $page->status);
        $this->getJson('/api/v1/pages/about')->assertNotFound();

        Livewire::actingAs($manager)->test(ListPages::class)->callAction(TestAction::make('publish')->table($page));

        $this->getJson('/api/v1/pages/about')->assertOk()
            ->assertJsonPath('data.title', 'About Techmate')
            ->assertJsonPath('data.content', '<p>Hello</p>')
            ->assertJsonMissingPath('data.status')
            ->assertJsonMissingPath('data.created_by');
    }

    public function test_unpublishing_removes_a_page_from_the_public_api(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'faq-1', 'section' => 'faq']);
        $this->getJson('/api/v1/pages?section=faq')->assertJsonCount(1, 'data');

        Livewire::actingAs($this->catalogueManager())->test(ListPages::class)
            ->callAction(TestAction::make('unpublish')->table($page));

        $this->getJson('/api/v1/pages?section=faq')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pages/faq-1')->assertNotFound();
    }

    public function test_drafts_and_soft_deleted_pages_never_appear_publicly(): void
    {
        Page::factory()->create(['slug' => 'draft-page']);
        Page::factory()->published()->create(['slug' => 'live-page']);
        Page::factory()->published()->create(['slug' => 'gone-page'])->delete();
        Page::factory()->published()->create(['slug' => 'scheduled', 'published_at' => now()->addDay()]);

        $slugs = collect($this->getJson('/api/v1/pages')->assertOk()->json('data'))->pluck('slug')->all();

        $this->assertSame(['live-page'], $slugs);
        $this->getJson('/api/v1/pages/draft-page')->assertNotFound();
        $this->getJson('/api/v1/pages/gone-page')->assertNotFound();
        $this->getJson('/api/v1/pages/scheduled')->assertNotFound();
    }

    public function test_page_list_omits_bodies_and_filters_by_section(): void
    {
        Page::factory()->published()->create(['slug' => 'privacy', 'section' => 'policy', 'content' => '<p>Policy text</p>']);
        Page::factory()->published()->create(['slug' => 'oven-solution', 'section' => 'solution']);

        $response = $this->getJson('/api/v1/pages?section=policy')->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('content', $response->json('data.0'));
        $this->getJson('/api/v1/pages?section=bogus')->assertUnprocessable();
    }

    public function test_rich_text_is_sanitised_on_every_save_path(): void
    {
        $dirty = '<p onclick="steal()">Hi</p><script>alert(1)</script><iframe src="https://evil.test"></iframe>'
            .'<a href="javascript:alert(1)">bad</a><a href="https://ok.test" target="_blank">ok</a><img src=x onerror=alert(1)>';

        Livewire::actingAs($this->catalogueManager())->test(CreatePage::class)
            ->fillForm($this->pageData(['content' => $dirty]))->call('create');

        $stored = Page::where('slug', 'about')->firstOrFail()->content;

        foreach (['<script', 'onclick', 'onerror', '<iframe', 'javascript:', '<img'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored);
        }
        $this->assertStringContainsString('Hi', $stored);
        $this->assertStringContainsString('href="https://ok.test"', $stored);
        $this->assertStringContainsString('rel="noopener noreferrer"', $stored);

        // Direct model writes (seeders, tinker, imports) are sanitised too.
        $direct = Page::factory()->create(['content' => '<p>x</p><script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script', $direct->fresh()->content);
    }

    public function test_page_slug_must_be_unique_and_well_formed(): void
    {
        Page::factory()->create(['slug' => 'about']);

        Livewire::actingAs($this->catalogueManager())->test(CreatePage::class)
            ->fillForm($this->pageData())->call('create')->assertHasFormErrors(['slug' => 'unique']);

        Livewire::actingAs($this->catalogueManager())->test(CreatePage::class)
            ->fillForm($this->pageData(['slug' => 'Bad Slug!']))->call('create')->assertHasFormErrors(['slug']);
    }

    public function test_sales_roles_cannot_touch_cms_pages(): void
    {
        $page = Page::factory()->create();

        foreach ([User::ROLE_SALES_EXECUTIVE, User::ROLE_SALES_MANAGER] as $role) {
            Livewire::actingAs($this->staff($role))->test(ListPages::class)->assertForbidden();
            Livewire::actingAs($this->staff($role))->test(EditPage::class, ['record' => $page->getKey()])->assertForbidden();
        }
    }

    public function test_only_super_admins_can_open_or_save_settings(): void
    {
        foreach ([User::ROLE_CATALOGUE_MANAGER, User::ROLE_SALES_EXECUTIVE, User::ROLE_SALES_MANAGER] as $role) {
            $user = $this->staff($role);

            Livewire::actingAs($user)->test(ManageSiteSettings::class)->assertForbidden();

            try {
                app(SiteSettings::class)->save(['company_name' => 'Hijacked'], $user);
                $this->fail("{$role} must not save settings.");
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(0, Setting::count());
    }

    public function test_super_admin_saves_settings_and_the_api_serves_only_declared_public_values(): void
    {
        Setting::create(['key' => 'internal_note', 'value' => 'private', 'is_public' => false]);

        Livewire::actingAs($this->superAdmin())->test(ManageSiteSettings::class)
            ->fillForm([
                'company_name' => 'Techmate Industries',
                'contact_email' => 'sales@techmate.example',
                'contact_phone' => '+91 80083 24628',
                'whatsapp_number' => '918008324628',
                'social_linkedin' => 'https://www.linkedin.com/company/techmate',
                'cta_quote_link' => '/request-quote',
                'hero_heading' => 'Commercial kitchens, engineered.',
            ])
            ->call('save')->assertHasNoFormErrors();

        $response = $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.company_name', 'Techmate Industries')
            ->assertJsonPath('data.cta_quote_link', '/request-quote');

        $this->assertArrayNotHasKey('internal_note', $response->json('data'));
        $this->assertArrayNotHasKey('contact_address', $response->json('data'), 'Unset keys are omitted.');
    }

    public function test_settings_validate_urls_phone_email_and_lengths(): void
    {
        $admin = $this->superAdmin();
        $invalid = [
            ['social_linkedin' => 'javascript:alert(1)'],
            ['social_facebook' => 'ftp://files.example.test'],
            ['cta_primary_link' => '//evil.test/phish'],
            ['cta_primary_link' => 'javascript:alert(1)'],
            ['contact_email' => 'not-an-email'],
            ['contact_phone' => 'call me maybe'],
            ['whatsapp_number' => '+91 800'],
            ['hero_heading' => str_repeat('x', 161)],
            ['company_name' => ''],
        ];

        foreach ($invalid as $change) {
            try {
                app(SiteSettings::class)->save(['company_name' => 'Techmate', ...$change], $admin);
                $this->fail('Expected validation failure for '.json_encode($change));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey(array_key_first($change), $e->errors());
            }
        }
    }

    public function test_arbitrary_setting_keys_are_rejected_so_secrets_cannot_be_stored(): void
    {
        foreach (['smtp_password', 'api_key', 'db_password'] as $key) {
            try {
                app(SiteSettings::class)->save(['company_name' => 'Techmate', $key => 'secret-value'], $this->superAdmin());
                $this->fail('Unknown key accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }

        $this->assertSame(0, Setting::whereIn('key', ['smtp_password', 'api_key', 'db_password'])->count());
    }

    public function test_markup_in_plain_settings_is_stripped_and_changes_are_logged_by_key_only(): void
    {
        Log::spy();

        app(SiteSettings::class)->save(['company_name' => 'Techmate <script>alert(1)</script>'], $this->superAdmin());

        $this->assertSame('Techmate alert(1)', Setting::where('key', 'company_name')->value('value'));
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'admin.settings.updated'
            && $context['keys'] === ['company_name']
            && ! str_contains(json_encode($context), 'Techmate'))->once();
    }
}
