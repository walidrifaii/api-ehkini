<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Page;

class PageController extends Controller
{
    /**
     * GET /api/v1/pages/{slug}
     */
    public function show($slug)
    {
        $page = Page::where('slug', $slug)->first();

        if (! $page) {
            return response()->json([
                'message' => 'Page not found.'
            ], 404);
        }

        return response()->json([
            'id'      => $page->id,
            'slug'    => $page->slug,
            'title'   => $page->title,
            'content' => $page->content,
            'updated_at' => $page->updated_at,
        ]);
    }
}
