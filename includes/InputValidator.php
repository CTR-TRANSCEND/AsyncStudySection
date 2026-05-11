<?php
declare(strict_types=1);

class InputValidator
{
    public static function required(mixed $value, string $field): void
    {
        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            throw new InvalidArgumentException("Field '{$field}' is required.");
        }
    }

    public static function string(mixed $value, string $field, int $minLength = 0, int $maxLength = PHP_INT_MAX): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("Field '{$field}' must be a string.");
        }
        $trimmed = trim($value);
        $len = strlen($trimmed);
        if ($len < $minLength) {
            throw new InvalidArgumentException("Field '{$field}' must be at least {$minLength} characters.");
        }
        if ($len > $maxLength) {
            throw new InvalidArgumentException("Field '{$field}' must not exceed {$maxLength} characters.");
        }
        return $trimmed;
    }

    public static function integer(mixed $value, string $field, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        if (!is_numeric($value) || is_float($value + 0)) {
            throw new InvalidArgumentException("Field '{$field}' must be an integer.");
        }
        $int = (int) $value;
        if ($int < $min) {
            throw new InvalidArgumentException("Field '{$field}' must be at least {$min}.");
        }
        if ($int > $max) {
            throw new InvalidArgumentException("Field '{$field}' must not exceed {$max}.");
        }
        return $int;
    }

    public static function email(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("Field '{$field}' must be a string.");
        }
        $trimmed = trim($value);
        if (!filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Field '{$field}' must be a valid email address.");
        }
        return $trimmed;
    }

    public static function inArray(mixed $value, string $field, array $allowed): mixed
    {
        if (!in_array($value, $allowed, true)) {
            $list = implode(', ', array_map('strval', $allowed));
            throw new InvalidArgumentException("Field '{$field}' must be one of: {$list}.");
        }
        return $value;
    }

    public static function id(mixed $value, string $field): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Field '{$field}' must be a valid ID.");
        }
        $int = (int) $value;
        if ($int <= 0) {
            throw new InvalidArgumentException("Field '{$field}' must be a positive integer.");
        }
        return $int;
    }
}
