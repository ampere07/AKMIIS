<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class AgentReferral
{
    public const ROLE_ID = 4;

    public const HIDDEN_APPLICATION_FIELDS = ['status', 'remarks'];

    public const LOCKED_APPLICATION_FIELDS = ['status', 'remarks', 'referred_by', 'organization_id'];

    public static function isAgent(?object $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ((int) ($user->role_id ?? 0) === self::ROLE_ID) {
            return true;
        }
        $role = $user->role ?? null;
        return is_object($role) && strtolower((string) ($role->role_name ?? '')) === 'agent';
    }

    public static function nameVariants(object $user): array
    {
        $first  = trim((string) ($user->first_name ?? ''));
        $middle = trim((string) ($user->middle_initial ?? ''));
        $last   = trim((string) ($user->last_name ?? ''));

        $variants = [];

        $simple = trim($first . ' ' . $last);
        if ($simple !== '') {
            $variants[] = strtolower($simple);
        }

        $full = trim($first . ' ' . ($middle !== '' ? $middle . '. ' : '') . $last);
        if ($full !== '') {
            $variants[] = strtolower($full);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    public static function restrictToReferrals(Builder $query, object $agent, string $column = 'referred_by'): Builder
    {
        $variants = self::nameVariants($agent);

        return $query->where(function ($owned) use ($variants, $column) {
            if (empty($variants)) {
                $owned->whereRaw('1 = 0');
                return;
            }
            foreach ($variants as $variant) {
                $owned->orWhereRaw("LOWER({$column}) LIKE ?", ['%' . $variant . '%']);
            }
        });
    }
}
