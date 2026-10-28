<?php

namespace App\Models;

use App\Support\ReferenceGenerator;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    public const TYPES = ['general', 'product', 'quotation'];

    public const STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost', 'spam'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Permitted status transitions (current => allowed next statuses). */
    public const TRANSITIONS = [
        'new' => ['contacted', 'qualified', 'lost', 'spam'],
        'contacted' => ['qualified', 'proposal', 'lost', 'spam'],
        'qualified' => ['contacted', 'proposal', 'lost'],
        'proposal' => ['qualified', 'won', 'lost'],
        'won' => [],
        'lost' => ['contacted', 'qualified'],
        'spam' => ['new'],
    ];

    /**
     * Only fields a visitor may supply. status, priority, assigned_to,
     * public_reference and the *_at workflow columns are set by trusted code.
     *
     * @var list<string>
     */
    protected $fillable = [
        'lead_type', 'customer_name', 'company_name', 'email', 'phone', 'alternate_phone',
        'city', 'state', 'postal_code', 'country_code', 'subject', 'message', 'source',
    ];

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            $lead->public_reference ??= ReferenceGenerator::unique(
                'TM-', 10, fn (string $ref) => static::where('public_reference', $ref)->exists()
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
            'closed_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function products(): HasMany
    {
        return $this->hasMany(LeadProduct::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }
}
