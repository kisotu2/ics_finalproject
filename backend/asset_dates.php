<?php
declare(strict_types=1);

function validate_purchase_date(mixed $value): ?string
{
    $value = validate_calendar_date($value, 'Purchase date');

    if ($value !== null && $value > date('Y-m-d')) {
        throw new InvalidArgumentException('Purchase date cannot be in the future.');
    }

    return $value;
}

function validate_warranty_expiry(mixed $value): ?string
{
    return validate_calendar_date($value, 'Warranty expiry date');
}

function validate_calendar_date(mixed $value, string $label): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_string($value)) {
        throw new InvalidArgumentException($label . ' must be a date string.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        || $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new InvalidArgumentException($label . ' must use the valid YYYY-MM-DD format.');
    }

    return $value;
}
