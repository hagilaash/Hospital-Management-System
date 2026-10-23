<?php

namespace App\Common;

/**
 * Small, stateless helpers for cleaning and validating request values.
 * Shared by the OP Consultation, Billing, Payment and RCM services so they do
 * not each carry their own copy.
 */
final class Input
{
    private function __construct()
    {
    }

    /**
     * Removes control characters, collapses any run of whitespace to a single
     * space and trims. Text that is not valid UTF-8 becomes '' (PostgreSQL
     * would reject it), which then fails the "required" or format rule.
     */
    public static function clean(?string $value): string
    {
        if ($value === null || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        if ($value === null) {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);

        return $value === null ? '' : trim($value);
    }

    /**
     * Like clean() but keeps line breaks (for notes and advice). Control
     * characters other than tab and newline are removed, "\r\n" becomes "\n",
     * and surrounding whitespace is trimmed.
     */
    public static function cleanMultiline(?string $value): string
    {
        if ($value === null || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value);

        return $value === null ? '' : trim($value);
    }

    public static function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** A search term: cleaned, cut to $max characters, null when empty. */
    public static function search(?string $value, int $max = 100): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(mb_substr(self::clean($value), 0, $max));

        return $clean === '' ? null : $clean;
    }

    /** Digits only, 1..999999999 (fits a PostgreSQL integer); anything else is null. */
    public static function positiveInt(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value) || strlen($value) > 9) {
            return null;
        }
        $number = (int) $value;

        return $number >= 1 ? $number : null;
    }

    /** A real calendar date in 'Y-m-d' form (2026-02-31 is rejected). */
    public static function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $problems = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    /** The date when valid, otherwise null. */
    public static function dateOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && self::isValidDate($value) ? $value : null;
    }

    /**
     * Case-insensitive match against the allowed list; returns the canonical
     * spelling, or the default for anything unknown.
     *
     * @param list<string> $allowed
     */
    public static function whitelist(?string $value, array $allowed, string $default): string
    {
        $value = trim((string) $value);
        foreach ($allowed as $candidate) {
            if (strcasecmp($value, $candidate) === 0) {
                return $candidate;
            }
        }

        return $default;
    }

    /** Booleans arrive from PDO as true/false or as 't'/'f' text depending on the driver. */
    public static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }
}