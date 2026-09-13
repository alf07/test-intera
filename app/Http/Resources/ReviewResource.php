<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API-представление одного отзыва.
 */
final class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->external_id,

            'author' => $this->author,

            'date' => $this->reviewed_at?->toIso8601String(),

            'rating' => $this->rating,

            'text' => $this->text,
        ];
    }
}
