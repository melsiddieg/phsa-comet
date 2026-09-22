<?php

namespace App\Support;

/**
 * Generates one-time passwords that an admin hands to a user.
 *
 * Format: four groups of four characters, e.g. "k7Qm-Xp3R-bN9t-Hw4e".
 * The alphabet leaves out look-alike characters (0/O, 1/l/I) so the password
 * can be read aloud or retyped without mistakes. 16 characters from a
 * 57-character alphabet is about 93 bits of entropy.
 */
class TemporaryPassword
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= self::ALPHABET[random_int(0, $max)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }
}
