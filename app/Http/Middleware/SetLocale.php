<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Language;

class SetLocale
{
    public function handle($request, Closure $next)
    {
        $locale = session('locale');

        if (!$locale) {
            $defaultLanguage = Language::where('is_default', 1)
                ->where('is_active', 1)
                ->first();

            $locale = $defaultLanguage?->code ?? 'en';
        }

        app()->setLocale($locale);

        $currentLanguage = Language::where('code', $locale)
            ->where('is_active', 1)
            ->first();

        view()->share('currentLanguage', $currentLanguage);

        return $next($request);
    }
}