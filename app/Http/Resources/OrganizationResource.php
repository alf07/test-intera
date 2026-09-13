<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Приводит Organization к стабильному API-формату.
 */
final class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'external_id' => $this->external_id,

            'url' => $this->source_url,

            'name' => $this->name,

            /**
             * Model cast decimal:2 уже отдаст
             * значение с двумя знаками после запятой.
             */
            'rating' => $this->rating,

            'ratings_count' => $this->ratings_count,

            'reviews_count' => $this->reviews_count,

            'parse' => [
                'status' => $this->parse_status?->value,

                'progress' => $this->parse_progress,

                'error' => $this->parse_error,

                'parsed_at' => $this->parsed_at?->toIso8601String(),
            ],
        ];
    }
}
