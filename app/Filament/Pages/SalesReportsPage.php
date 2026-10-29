<?php

namespace App\Filament\Pages;

use App\Services\SalesReports;
use App\Support\Permissions;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Lead, quotation, order and payment reporting. Numbers come from SalesReports, which scopes
 * every query to what the signed-in user may see: executives see their own leads, managers
 * and administrators see the team. Nothing here is exported or includes customer contact data.
 *
 * @property Schema $form
 */
class SalesReportsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Sales reports';

    protected static ?string $slug = 'sales-reports';

    protected string $view = 'filament.pages.sales-reports';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasPermission(Permissions::REPORTS_TEAM) || $user->hasPermission(Permissions::REPORTS_OWN));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $tz = config('techmate.business_timezone');
        $this->form->fill([
            'preset' => '30',
            'from' => now($tz)->subDays(29)->toDateString(),
            'until' => now($tz)->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $tz = config('techmate.business_timezone');

        return $schema->components([
            Select::make('preset')->label('Period')->native(false)->live()
                ->options(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'month' => 'This month', 'custom' => 'Custom range'])
                ->afterStateUpdated(function ($state, $set) use ($tz) {
                    if ($state === 'custom') {
                        return;
                    }
                    $set('until', now($tz)->toDateString());
                    $set('from', $state === 'month' ? now($tz)->startOfMonth()->toDateString() : now($tz)->subDays((int) $state - 1)->toDateString());
                }),
            DatePicker::make('from')->native(false)->live()->maxDate(now($tz))->afterStateUpdated(fn ($set) => $set('preset', 'custom')),
            DatePicker::make('until')->native(false)->live()->maxDate(now($tz))->afterOrEqual('from')->afterStateUpdated(fn ($set) => $set('preset', 'custom')),
        ])->columns(3)->statePath('data');
    }

    /** @return array<string, mixed>|null */
    public function getReport(): ?array
    {
        $from = $this->data['from'] ?? null;
        $until = $this->data['until'] ?? null;

        if (! $from || ! $until || $until < $from) {
            return null;
        }

        return (new SalesReports(auth()->user(), $from, $until))->build();
    }

    public function scopeNote(): string
    {
        return auth()->user()->hasPermission(Permissions::REPORTS_TEAM)
            ? 'Showing the whole team.'
            : 'Showing your own leads and follow-ups.';
    }
}
