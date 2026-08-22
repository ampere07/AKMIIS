<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    protected $table = 'roles';

    /**
     * Seeded role IDs (see RolesSeeder). Named here so authorization checks read
     * as roles rather than as bare numbers scattered across controllers.
     */
    public const ADMINISTRATOR = 1;
    public const SUPER_ADMIN   = 7;

    /**
     * Slugs the `role` middleware accepts, mapped onto the IDs above.
     *
     * Only roles that actually gate something are listed; the middleware also
     * takes a numeric ID directly, so this does not have to mirror the full
     * seeder.
     */
    private const SLUGS = [
        'administrator' => self::ADMINISTRATOR,
        'super_admin'   => self::SUPER_ADMIN,
        'superadmin'    => self::SUPER_ADMIN,
    ];

    /**
     * Resolve a middleware argument - a slug like "super_admin" or a bare ID
     * like "7" - to a role ID. Returns null when it matches neither.
     */
    public static function idForSlug(string $role): ?int
    {
        $key = strtolower(trim($role));

        if ($key === '') {
            return null;
        }

        if (isset(self::SLUGS[$key])) {
            return self::SLUGS[$key];
        }

        return ctype_digit($key) ? (int) $key : null;
    }

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
