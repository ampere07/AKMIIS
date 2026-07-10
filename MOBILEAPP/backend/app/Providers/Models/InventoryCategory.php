<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    use HasFactory;

    protected $table = 'inventory_category';
    
    public $timestamps = true;
    
    protected $fillable = [
        'category_name',
        'created_by_user_id',
        'updated_by_user_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];


}
