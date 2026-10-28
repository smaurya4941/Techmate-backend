<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, SoftDeletes;

    public const STATUSES = ['draft', 'published'];

    public const SECTIONS = ['page', 'policy', 'faq', 'solution', 'service'];

    /**
     * status/published_at/created_by/updated_by are set by trusted code.
     * `content` is rich HTML and MUST be sanitized before it is saved.
     *
     * @var list<string>
     */
    protected $fillable = ['title', 'slug', 'section', 'sort_order', 'excerpt', 'content', 'meta_title', 'meta_description'];

    protected static function booted(): void
    {
        static::saving(function (Page $page) {
            // Rich text is sanitized on every write path, not just in the admin form.
            $page->content = HtmlSanitizer::clean($page->content);

            if ($userId = auth()->id()) {
                $page->updated_by = $userId;
                $page->created_by ??= $userId;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function scopeSection(Builder $query, string $section): Builder
    {
        return $query->where('section', $section);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
