<?php

namespace App\Models;

use App\Support\ReferenceNumbers;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    public const TYPES = ['general', 'product', 'quotation'];

    /**
     * Definitions (see docs/sales-workflow.md):
     *  new                    enquiry received, not yet reviewed
     *  assigned               a sales executive owns it
     *  contacted              first contact attempted or made
     *  qualified              requirements, fit and buying intent assessed
     *  quotation_in_progress  a quotation is being prepared
     *  quotation_sent         an approved quotation was issued to the customer
     *  negotiation            customer and sales are discussing changes
     *  won                    customer accepted a quotation and an order exists
     *  lost                   closed without a sale (reason recorded)
     *  spam                   not a genuine enquiry
     */
    public const STATUSES = ['new', 'assigned', 'contacted', 'qualified', 'quotation_in_progress', 'quotation_sent', 'negotiation', 'won', 'lost', 'spam'];

    public const OPEN_STATUSES = ['new', 'assigned', 'contacted', 'qualified', 'quotation_in_progress', 'quotation_sent', 'negotiation'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const LOST_REASONS = [
        'price_budget' => 'Price or budget',
        'competitor' => 'Competitor selected',
        'specification_mismatch' => 'Product or specification mismatch',
        'delivery_timeline' => 'Delivery timeline',
        'project_postponed' => 'Project postponed or cancelled',
        'unresponsive' => 'Customer unresponsive after agreed follow-ups',
        'other' => 'Other (explain in notes)',
    ];

    /**
     * Every legal move, including those made by trusted system code (quotation sent,
     * outcome recorded). Won and lost are only reachable through LeadOutcomeService.
     */
    public const TRANSITIONS = [
        'new' => ['assigned', 'contacted', 'qualified', 'quotation_in_progress', 'quotation_sent', 'lost', 'spam'],
        'assigned' => ['contacted', 'qualified', 'quotation_in_progress', 'quotation_sent', 'lost', 'spam'],
        'contacted' => ['qualified', 'quotation_in_progress', 'quotation_sent', 'lost', 'spam'],
        'qualified' => ['contacted', 'quotation_in_progress', 'quotation_sent', 'lost'],
        'quotation_in_progress' => ['qualified', 'quotation_sent', 'negotiation', 'lost'],
        'quotation_sent' => ['negotiation', 'quotation_in_progress', 'qualified', 'won', 'lost'],
        'negotiation' => ['quotation_in_progress', 'quotation_sent', 'qualified', 'won', 'lost'],
        'won' => [],
        'lost' => ['contacted', 'qualified'],
        'spam' => ['new'],
    ];

    /** Moves a person may pick by hand. Quotation stages follow the quotation, closing needs an outcome. */
    public const MANUAL_TRANSITIONS = [
        'new' => ['contacted', 'qualified', 'spam'],
        'assigned' => ['contacted', 'qualified', 'spam'],
        'contacted' => ['qualified', 'spam'],
        'qualified' => ['contacted'],
        'quotation_in_progress' => ['qualified'],
        'quotation_sent' => ['negotiation', 'qualified'],
        'negotiation' => ['qualified'],
        'won' => [],
        'lost' => [],
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
        'source_page', 'campaign',
    ];

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            $lead->public_reference ??= ReferenceNumbers::lead();
            $lead->submitted_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
            'consent_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
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

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
