<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dictionary;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    /**
     * GET /api/v1/dictionary?lang=en
     * GET /api/v1/dictionary?lang=ar
     */
    public function dictionary(Request $request)
    {
        $lang = strtolower((string) $request->query('lang', 'en'));
        $lang = in_array($lang, ['en', 'ar'], true) ? $lang : 'en';

        $items = Dictionary::query()
            ->orderBy('id')
            ->get(['key_name', 'en', 'ar']);

        $result = [];
        foreach ($items as $item) {
            $result[$item->key_name] = $item->{$lang} ?? '';
        }

        return response()->json([
            'language' => $lang,
            'dictionary' => $result,
        ]);
    }
    
    public function languages()
{
    $languages = \App\Models\Language::where('is_active', 1)->get();

    return response()->json([
        'success' => true,
        'data' => $languages
    ]);
}

    /**
     * POST /api/v1/language/change
     * body: { "language": "en" } or { "language": "ar" }
     */
    public function changeLanguage(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'language' => ['required', 'in:en,ar'],
        ]);

        $user->update([
            'language' => $data['language'],
        ]);

        return response()->json([
            'message' => 'Language updated successfully.',
            'language' => $user->language,
        ]);
    }

    /**
     * GET /api/v1/language/current
     */
    public function currentLanguage(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'language' => $user->language ?? 'en',
        ]);
    }
}