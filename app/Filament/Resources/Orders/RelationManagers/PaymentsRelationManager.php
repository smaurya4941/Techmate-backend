<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Support\Guarded;
use App\Filament\Support\Status;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The payment ledger: entries are recorded, verified by a second person, or voided - never edited or deleted. */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['recorder:id,name', 'verifier:id,name']))
            ->columns([
                TextColumn::make('paid_at')->label('Paid on')->date('d M Y')->sortable(),
                TextColumn::make('amount')->formatStateUsing(fn ($state, Payment $record) => Status::money($record->order->currency_code, $state))
                    ->weight('medium')->color(fn (Payment $record) => $record->status === 'voided' ? 'gray' : null)
                    ->extraAttributes(fn (Payment $record) => $record->status === 'voided' ? ['style' => 'text-decoration: line-through'] : []),
                TextColumn::make('method')->formatStateUsing(fn ($state) => Payment::METHODS[$state] ?? $state),
                TextColumn::make('reference')->placeholder('-')->copyable(),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => ['recorded' => 'warning', 'verified' => 'success', 'voided' => 'gray'][$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('recorder.name')->label('Recorded by')->placeholder('-'),
                TextColumn::make('verifier.name')->label('Verified by')->placeholder('-'),
                TextColumn::make('void_reason')->label('Void reason')->placeholder('-')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('paid_at', 'desc')
            ->headerActions([
                Action::make('record')->label('Record payment')->icon('heroicon-o-banknotes')
                    ->visible(fn () => auth()->user()->can('recordPayment', $this->order()) && $this->order()->status !== 'cancelled')
                    ->modalDescription(fn () => 'Outstanding balance: '.Status::money($this->order()->currency_code, $this->order()->balanceDue()))
                    ->schema([
                        TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                        DatePicker::make('paid_at')->label('Payment date')->required()->default(now())->maxDate(now())->native(false),
                        Select::make('method')->required()->native(false)->options(Payment::METHODS)->default('bank_transfer'),
                        TextInput::make('reference')->label('Transaction / receipt reference')->maxLength(100),
                        Textarea::make('notes')->rows(2)->maxLength(1000),
                    ])
                    ->action(function (array $data) {
                        Guarded::run(fn () => app(OrderService::class)->recordPayment($this->order(), $data, auth()->user()), 'Payment recorded');
                        $this->getOwnerRecord()->refresh();
                    }),
            ])
            ->recordActions([
                Action::make('verify')->label('Verify')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Payment $record) => $record->status === 'recorded' && auth()->user()->can('verifyPayment', $record->order))
                    ->requiresConfirmation()
                    ->modalDescription('Confirm the money has been received (bank statement, receipt). You cannot verify your own entry.')
                    ->action(function (Payment $record) {
                        Guarded::run(fn () => app(OrderService::class)->verifyPayment($record, auth()->user()), 'Payment verified');
                        $this->getOwnerRecord()->refresh();
                    }),
                Action::make('void')->label('Void')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (Payment $record) => $record->status !== 'voided' && auth()->user()->can('verifyPayment', $record->order))
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(1000)])
                    ->modalDescription('The entry stays on record as voided and no longer counts towards the balance.')
                    ->action(function (Payment $record, array $data) {
                        Guarded::run(fn () => app(OrderService::class)->voidPayment($record, $data['reason'], auth()->user()), 'Payment voided');
                        $this->getOwnerRecord()->refresh();
                    }),
            ]);
    }

    private function order(): Order
    {
        return $this->getOwnerRecord();
    }
}
