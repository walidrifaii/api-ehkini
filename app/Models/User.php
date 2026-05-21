<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'profile_image',

        'country_code',
        'country_id',
        'phone',

        'password',
        'date_of_birth',
        'gender',
        'location',
        'occupation',   // ✅ added
        'education',
        'about_me',

        'fcm_token',
        'platform',
        'token_updated_at',
        'is_active',
    ];

    /**
     * Hidden fields in API responses
     */
    protected $hidden = [
        'password',
        'fcm_token',
    ];

    /**
     * Cast fields
     */
    protected $casts = [
        'date_of_birth'    => 'date',
        'token_updated_at' => 'datetime',
        'is_active'        => 'boolean', // ✅ important
        'country_id'       => 'integer',
    ];

    /**
     * Append computed attributes
     */
    protected $appends = [
        'profile_image_url',
        'age',
    ];

    /**
     * ✅ FULL PROFILE IMAGE URL
     * Matches your server structure
     */
    public function getProfileImageUrlAttribute()
    {
        if (! $this->profile_image) {
            return null;
        }

        // APP_URL = https://amcserver.com/app/taaruf
        $base = rtrim(config('app.url'), '/');

        // DB value: profiles/filename.png
        return $base . '/storage/app/public/' . ltrim($this->profile_image, '/');
    }

    /**
     * ✅ AGE calculated from date_of_birth
     */
    public function getAgeAttribute()
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return Carbon::parse($this->date_of_birth)->age;
    }
    
    public function posts()
{
    return $this->hasMany(\App\Models\Post::class);
}

public function interests()
{
    return $this->belongsToMany(
        \App\Models\Interest::class,
        'user_interests',
        'user_id',
        'interest_id'
    )->withTimestamps(); // optional (created_at)
}

public function wallet()
{
    return $this->hasOne(\App\Models\UserWallet::class);
}

public function country()
{
    return $this->belongsTo(Country::class);
}

    public function lastUserSearch()
    {
        return $this->hasOne(UserLastSearch::class);
    }

}

