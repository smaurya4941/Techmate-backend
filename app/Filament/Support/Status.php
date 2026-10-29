<?php

namespace App\Filament\Support;

/** Display helpers shared by the sales screens: one label and one colour per status everywhere. */
final class Status
{
    public const LEAD = [
        'new' => 'info', 'assigned' => 'gray', 'contacted' => 'gray', 'qualified' => 'primary',
        'quotation_in_progress' => 'warning', 'quotation_sent' => 'warning', 'negotiation' => 'warning',
        'won' => 'success', 'lost' => 'danger', 'spam' => 'gray',
    ];

    public const PRIORITY = ['low' => 'gray', 'normal' => 'info', 'high' => 'warning', 'urgent' => 'danger'];

    public const QUOTATION = [
        'draft' => 'gray', 'pending_approval' => 'warning', 'approved' => 'info', 'sent' => 'primary',
        'accepted' => 'success', 'rejected' => 'danger', 'expired' => 'warning', 'superseded' => 'gray', 'cancelled' => 'gray',
    ];

    public const ORDER = [
        'pending_confirmation' => 'warning', 'confirmed' => 'info', 'processing' => 'info', 'ready_for_dispatch' => 'primary',
        'dispatched' => 'primary', 'delivered' => 'success', 'completed' => 'success', 'cancelled' => 'gray',
    ];

    public const PAYMENT = ['unpaid' => 'danger', 'partial' => 'warning', 'awaiting_verification' => 'info', 'paid' => 'success'];

    public const FOLLOW_UP = ['open' => 'warning', 'in_progress' => 'info', 'completed' => 'success', 'cancelled' => 'gray', 'overdue' => 'danger'];

    public static function label(?string $state): string
    {
        return $state === null ? '' : ucfirst(str_replace('_', ' ', $state));
    }

    /**
     * @param  list<string>  $states
     * @return array<string, string>
     */
    public static function options(array $states): array
    {
        return array_combine($states, array_map([self::class, 'label'], $states));
    }

    public static function money(?string $currency, mixed $amount): string
    {
        return trim(($currency ? $currency.' ' : '').number_format((float) $amount, 2));
    }
}
