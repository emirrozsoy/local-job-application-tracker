<?php

declare(strict_types=1);

namespace App\Services;

final class PhoneFormatter
{
    public function format(?string $phoneNumber): ?string
    {
        if ($phoneNumber === null || trim($phoneNumber) === '') {
            return null;
        }

        $clean = preg_replace('/[\s\-\(\)]+/', '', trim($phoneNumber));

        if ($clean === null || $clean === '') {
            return null;
        }

        $clean = preg_replace('/(?!^)\+/', '', $clean) ?? $clean;

        if (str_starts_with($clean, '00')) {
            return '+' . substr($clean, 2);
        }

        if (str_starts_with($clean, '+')) {
            return $clean;
        }

        if (str_starts_with($clean, '05')) {
            return '+90' . substr($clean, 1);
        }

        if (str_starts_with($clean, '5') && strlen($clean) === 10) {
            return '+90' . $clean;
        }

        if (str_starts_with($clean, '90')) {
            return '+' . $clean;
        }

        return $clean;
    }
}
