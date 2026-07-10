<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    protected $table = 'roles';

    protected $fillable = [
        'role_name',
        'description',
        'permissions',
        'created_by_user_id',
        'updated_by_user_id',
        'organization_id'
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'role_id', 'id');
    }
}
