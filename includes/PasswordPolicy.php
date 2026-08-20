<?php

final class PasswordPolicy
{
    /**
     * @return array{ok: bool, error?: string}
     */
    public static function validate(string $newPassword, ?string $currentPassword = null): array
    {
        if ($currentPassword !== null && hash_equals($currentPassword, $newPassword)) {
            return ['ok' => false, 'error' => 'Choose a password different from your current password.'];
        }
        if (strlen($newPassword) < 12) {
            return ['ok' => false, 'error' => 'New password must be at least 12 characters.'];
        }
        if (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword)) {
            return ['ok' => false, 'error' => 'New password must include uppercase and lowercase letters.'];
        }
        if (!preg_match('/[0-9]/', $newPassword)) {
            return ['ok' => false, 'error' => 'New password must include at least one number.'];
        }
        if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            return ['ok' => false, 'error' => 'New password must include at least one symbol.'];
        }

        return ['ok' => true];
    }
}
