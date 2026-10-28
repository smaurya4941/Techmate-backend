<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    public const VALUE_TYPES = ['string', 'number', 'boolean', 'json'];

    /** Never store passwords, API secrets or tokens here, whatever is_public says. */
    protected $fillable = ['key', 'value', 'value_type', 'group', 'is_public', 'description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /** The value converted according to value_type. */
    public function typedValue(): mixed
    {
        return match ($this->value_type) {
            'number' => $this->value === null ? null : $this->value + 0,
            'boolean' => $this->value === null ? null : filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => $this->value === null ? null : json_decode($this->value, true, 512, JSON_THROW_ON_ERROR),
            default => $this->value,
        };
    }
}
