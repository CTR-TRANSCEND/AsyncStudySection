<?php
declare(strict_types=1);

/**
 * Composes / decomposes user display name. The single canonical source for
 * name formatting in this codebase. See SPEC-NAME-SPLIT-001.
 *
 * Strategy B (hybrid): users.full_name is kept as a denormalized cache,
 * recomputed by MariaDB BEFORE INSERT/UPDATE triggers whenever
 * first_name / last_name / degrees change. Existing read sites that use
 * $user['full_name'] are INTENTIONAL and remain supported. Migrate a read
 * site to structured columns only when the calling context needs them
 * (e.g. sort by family name, formal citations).
 */
class UserNameHelper
{
    /**
     * Compose full_name from structured parts. Returns "First Last, Degrees"
     * with degrees omitted when empty. Mirrors the trigger logic in
     * migrations/023_user_name_split.sql.
     */
    public static function compose(?string $first, ?string $last, ?string $degrees): string
    {
        $first = trim((string) $first);
        $last  = trim((string) $last);
        $deg   = trim((string) $degrees);
        $name  = trim($first . ' ' . $last);
        return $deg !== '' ? $name . ', ' . $deg : $name;
    }

    /**
     * Backfill: parse legacy full_name into parts.
     *
     * Hardening: strip null bytes (otherwise preserved by explode/trim/substr);
     * cap input at 250 chars (column is VARCHAR(100) but this guards against
     * pathological lengths before truncation); use mb_* so non-ASCII names
     * split on the correct character boundary.
     */
    public static function decompose(string $fullName): array
    {
        $fullName = str_replace("\0", '', $fullName);
        if (mb_strlen($fullName) > 250) {
            $fullName = mb_substr($fullName, 0, 250);
        }

        $parts    = explode(',', $fullName, 2);
        $namePart = trim($parts[0]);
        $degrees  = isset($parts[1]) ? trim($parts[1]) : '';

        $pos = mb_strrpos($namePart, ' ');
        if ($pos === false) {
            return ['first_name' => '', 'last_name' => $namePart, 'degrees' => $degrees];
        }
        return [
            'first_name' => trim(mb_substr($namePart, 0, $pos)),
            'last_name'  => trim(mb_substr($namePart, $pos + 1)),
            'degrees'    => $degrees,
        ];
    }
}
