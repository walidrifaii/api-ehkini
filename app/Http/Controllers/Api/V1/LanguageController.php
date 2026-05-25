<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dictionary;
use App\Support\ApiLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LanguageController extends Controller
{
    /**
     * GET /api/v1/dictionary?lang=en
     * GET /api/v1/dictionary?lang=ar
     */
    public function dictionary(Request $request)
    {
        $lang = ApiLocale::normalize((string) $request->query('lang', ApiLocale::DEFAULT));
        app()->setLocale($lang);

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
     * Validates language code only (stored on the mobile app, not in DB).
     * Prefer header X-App-Language: ar on every API call.
     */
    public function changeLanguage(Request $request)
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(ApiLocale::supported())],
        ]);

        $language = ApiLocale::normalize($data['language']);
        app()->setLocale($language);

        return response()->json([
            'message' => api_trans('language_updated'),
            'language' => $language,
        ]);
    }

    /**
     * GET /api/v1/language/current
     * Returns locale resolved from this request (headers / ?lang=), not from database.
     */
    public function currentLanguage(Request $request)
    {
        return response()->json([
            'language' => ApiLocale::resolve($request),
        ]);
    }
}