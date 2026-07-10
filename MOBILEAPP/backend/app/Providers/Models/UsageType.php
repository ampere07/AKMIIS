<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageType extends Model
{
    protected $table = 'usage_type';
    
    protected $fillable = [
        'usage_name',
        'created_by_user_id',
        'updated_by_user_id'
    ];
    
    public $timestamps = true;
}
