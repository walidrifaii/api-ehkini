<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\GiftCategory;
use Illuminate\Http\Request;

class GiftController extends Controller
{
    /**
     * GET /api/v1/gift-categories
     */
    public function categories()
    {
        $items = GiftCategory::query()
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'categories' => $items,
        ]);
    }

    /**
     * GET /api/v1/gifts
     * Optional query:
     * - category_id=2
     * - category_slug=romantic
     */
    public function index(Request $request)
    {
        $q = Gift::query()
            ->where('is_active', 1)
            ->with(['category:id,name,slug']);

        // filter by category_id
        if ($request->filled('category_id')) {
            $q->where('category_id', (int) $request->query('category_id'));
        }

        // filter by category_slug
        if ($request->filled('category_slug')) {
            $slug = trim((string) $request->query('category_slug'));
            $q->whereHas('category', function ($qq) use ($slug) {
                $qq->where('slug', $slug)->where('is_active', 1);
            });
        }

        $items = $q->orderBy('id')->get([
            'id', 'category_id', 'name', 'price', 'image', 'is_active', 'created_at'
        ]);

        return response()->json([
            'gifts' => $items,
        ]);
    }
}