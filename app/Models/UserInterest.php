<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInterest extends Model
{
    protected $table = 'user_interests';
    protected $fillable = ['user_id','interest_id'];
}
