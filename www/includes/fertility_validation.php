<?php
declare(strict_types=1);

if (!function_exists('ahdisNonNegativeWholeNumberError')) {
    function ahdisNonNegativeWholeNumberError($raw, string $label): string
    {
        $value = trim((string)$raw);
        if ($value === '') {
            return '';
        }

        if (!ctype_digit($value)) {
            return $label . ' must be a non-negative whole number.';
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        if ($parsed === false) {
            return $label . ' is outside the supported numeric range.';
        }

        return '';
    }
}

if (!function_exists('ahdisParseNonNegativeWholeNumber')) {
    function ahdisParseNonNegativeWholeNumber($raw, string $label): ?int
    {
        $error = ahdisNonNegativeWholeNumberError($raw, $label);
        if ($error !== '') {
            throw new InvalidArgumentException($error);
        }

        $value = trim((string)$raw);
        return $value === '' ? null : (int)$value;
    }
}
