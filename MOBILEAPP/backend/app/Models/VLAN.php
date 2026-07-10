<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VLAN extends Model
{
    protected $table = 'vlans';
    protected $primaryKey = 'vlan_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = true;
    
    protected $fillable = [
        'vlan_id',
        'value',
    ];
}
