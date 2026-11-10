<?php

declare(strict_types=1);

namespace MiniMailer\Laravel\Webhooks;

use DateTimeImmutable;
use Exception;
use ValueError;

final class Payload
{
    public static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function boolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0) ? $date : null;
        } catch (Exception|ValueError) {
            return null;
        }
    }
}
