<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    use HasFactory;

    protected $table = 'region';
    
    public $timestamps = false;
    
    protected $fillable = [
        'region',
        'modified_by',
        'modified_at'
    ];

    public function cities()
    {
        return $this->hasMany(City::class, 'region_id');
    }
}
