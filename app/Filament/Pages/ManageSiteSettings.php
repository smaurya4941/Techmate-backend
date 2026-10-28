<?php

namespace App\Filament\Pages;

use App\Filament\Support\Guarded;
use App\Services\SiteSettings;
use App\Support\Permissions;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Typed editor for the public website settings. Fields are generated from
 * SiteSettings::definitions(), and saving goes through SiteSettings::save(),
 * which re-validates every value and rejects keys that are not declared.
 *
 * @property Schema $form
 */
class ManageSiteSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Website settings';

    protected static ?string $title = 'Website settings';

    protected string $view = 'filament.pages.manage-site-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission(Permissions::SETTINGS_MANAGE) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill(SiteSettings::all());
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (SiteSettings::GROUP_LABELS as $group => $label) {
            $fields = [];
            foreach (SiteSettings::definitions() as $key => $definition) {
                if ($definition['group'] !== $group) {
                    continue;
                }

                $field = match ($definition['input']) {
                    'textarea' => Textarea::make($key)->rows(3),
                    'email' => TextInput::make($key)->email(),
                    'tel' => TextInput::make($key)->tel(),
                    default => TextInput::make($key),
                };

                $fields[] = $field->label($definition['label'])
                    ->required(in_array('required', $definition['rules'], true))
                    ->maxLength(self::maxLength($definition['rules']));
            }

            $sections[] = Section::make($label)->columns(2)->schema($fields)->collapsible();
        }

        return $schema->components($sections)->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        Guarded::run(fn () => app(SiteSettings::class)->save($data, auth()->user()), 'Settings saved');
    }

    /** @param  list<mixed>  $rules */
    private static function maxLength(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                return (int) substr($rule, 4);
            }
        }

        return null;
    }
}
