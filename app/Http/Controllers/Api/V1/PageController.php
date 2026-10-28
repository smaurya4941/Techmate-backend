<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ApiCollection;
use App\Http\Resources\V1\PageResource;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    /** Published content only; drafts and archived rows never leave the database. */
    public function index(Request $request): ApiCollection
    {
        $validated = $request->validate([
            'section' => ['sometimes', Rule::in(Page::SECTIONS)],
        ]);

        $pages = Page::query()
            ->published()
            ->when($validated['section'] ?? null, fn ($q, $section) => $q->section($section))
            ->orderBy('section')->orderBy('sort_order')->orderBy('title')->orderBy('id')
            ->get();

        return new ApiCollection($pages, PageResource::class, 'Pages retrieved successfully.');
    }

    public function show(string $slug): PageResource
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        return PageResource::make($page)->additional(['message' => 'Page retrieved successfully.']);
    }
}
