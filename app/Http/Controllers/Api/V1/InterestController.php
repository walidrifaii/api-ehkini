<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Interest;
use Illuminate\Http\Request;

class InterestController extends Controller
{
    /**
     * GET /api/v1/interests
     * Optional: ?search=music
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $q = Interest::query();

        if ($search !== '') {
            $q->where('name', 'like', "%{$search}%");
        }

        $interests = $q->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'interests' => $interests
        ]);
    }
}
