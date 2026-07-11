<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Barangay extends Model
{
    use HasFactory;

    protected $table = 'barangay';
    
    public $timestamps = false;
    
    protected $fillable = [
        'barangay',
        'city_id',
        'radius_config_id',
        'organization_id',
        'modified_by',
        'modified_at'
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'radius_config_id' => 'integer',
        'modified_at' => 'datetime'
    ];

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function radiusConfig()
    {
        return $this->belongsTo(RadiusConfig::class, 'radius_config_id');
    }

    public function locations()
    {
        return $this->hasMany(LocationDetail::class, 'barangay_id');
    }
}
