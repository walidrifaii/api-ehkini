<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Country;

class CountryController extends Controller
{
    public function index()
    {
        $countries = Country::query()
            ->where('is_active', true)
            ->select('id', 'name', 'iso2', 'phone_code')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $countries,
        ]);
    }
}
