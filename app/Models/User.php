<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Support\MediaStorage;
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

        'email',
        'email_verified_at',

        'password',
        'date_of_birth',
        'gender',
        'location',
        'latitude',
        'longitude',
        'location_updated_at',
        'location_sharing_enabled',
        'occupation',   // ✅ added
        'education',
        'about_me',

        'fcm_token',
        'platform',
        'token_updated_at',
        'is_active',
        'deactivated_at',
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
        'email_verified_at' => 'datetime',
        'token_updated_at' => 'datetime',
        'location_updated_at' => 'datetime',
        'location_sharing_enabled' => 'boolean',
        'is_active'        => 'boolean', // ✅ important
        'deactivated_at'   => 'datetime',
        'country_id'       => 'integer',
        'latitude'         => 'float',
        'longitude'        => 'float',
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

        return MediaStorage::url($this->profile_image);
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

    public function faceEmbedding()
    {
        return $this->hasOne(UserFaceEmbedding::class);
    }

}

