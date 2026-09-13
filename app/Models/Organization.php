<?php

namespace App\Models;

use App\Enums\ParseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'external_id',
        'source_url',
        'name',
        'rating',
        'ratings_count',
        'reviews_count',
        'parse_status',
        'parse_progress',
        'parse_error',
        'parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'parse_status' => ParseStatus::class,
            'parsed_at' => 'datetime',
        ];
    }

    /**
     * Отзывы организации.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
