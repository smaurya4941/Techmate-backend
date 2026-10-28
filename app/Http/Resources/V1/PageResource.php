<?php

namespace App\Http\Resources\V1;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allowlist: status, audit columns and ids of staff are never serialized.
 * `content` is sanitized HTML (see Page::booted), safe for the frontend to render.
 *
 * @mixin Page
 */
class PageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'section' => $this->section,
            'excerpt' => $this->excerpt,
            // The list endpoint omits the body; the detail endpoint includes it.
            'content' => $this->when($request->route('slug') !== null, $this->content),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
