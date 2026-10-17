<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AgentReferral
{
    public const ROLE_ID = 4;

    // Application fields left out of what agents receive (none at present)
    public const HIDDEN_APPLICATION_FIELDS = [];

    public const LOCKED_APPLICATION_FIELDS = ['referred_by', 'organization_id'];

    // Agents see every application except these statuses (compared lowercased and trimmed).
    public const HIDDEN_APPLICATION_STATUSES = ['scheduled', 'schedule'];

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

    // Agents only see applications and job orders created within the last month, counting back from today
    public static function visibleSince(): Carbon
    {
        return now()->subMonthNoOverflow()->startOfDay();
    }

    public static function restrictToLastMonth(Builder $query, string $column = 'timestamp'): Builder
    {
        return $query->where($query->qualifyColumn($column), '>=', self::visibleSince());
    }

    public static function isHiddenStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::HIDDEN_APPLICATION_STATUSES, true);
    }

    public static function excludeHiddenStatuses(Builder $query, string $column = 'status'): Builder
    {
        $placeholders = implode(', ', array_fill(0, count(self::HIDDEN_APPLICATION_STATUSES), '?'));

        // NOT IN alone would also drop applications with no status
        return $query->where(function ($visible) use ($column, $placeholders) {
            $visible->whereNull($column)
                ->orWhereRaw("LOWER(TRIM({$column})) NOT IN ({$placeholders})", self::HIDDEN_APPLICATION_STATUSES);
        });
    }
}
