<?php

namespace App\Models;

use App\Support\ReferenceGenerator;
use Database\Factories\DistributorApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributorApplication extends Model
{
    /** @use HasFactory<DistributorApplicationFactory> */
    use HasFactory;

    public const STATUSES = ['new', 'under_review', 'approved', 'rejected', 'withdrawn'];

    public const BUSINESS_TYPES = ['dealer', 'bakery-supplier', 'refrigeration-mep', 'turnkey-consultant', 'other'];

    public const EXPERIENCE_RANGES = ['0-2', '3-5', '6-10', '10+'];

    /**
     * Only applicant-supplied fields. status, reviewer, notes and the reference
     * are set by trusted code.
     *
     * @var list<string>
     */
    protected $fillable = [
        'contact_name', 'company_name', 'email', 'phone', 'city', 'state', 'country_code',
        'website_url', 'business_type', 'experience_range', 'regions_served',
        'product_interests', 'message',
    ];

    protected static function booted(): void
    {
        static::creating(function (DistributorApplication $application) {
            $application->public_reference ??= ReferenceGenerator::unique(
                'DA-', 10, fn (string $ref) => static::where('public_reference', $ref)->exists()
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'regions_served' => 'array',
            'product_interests' => 'array',
            'reviewed_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
