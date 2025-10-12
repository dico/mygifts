<?php
namespace App\Model\Utils;

/**
 * Small, dependency-free ULID generator (26 chars, Crockford Base32).
 * Time (48 bits, ms) + randomness (80 bits).
 */
final class Id
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function __construct() {}

    public static function ulid(): string
    {
        $enc = self::ALPHABET;

        // 48 bits time in ms
        $time = (int) (microtime(true) * 1000);
        $timeChars = '';
        for ($i = 0; $i < 10; $i++) {
            $timeChars = $enc[$time % 32] . $timeChars;
            $time = intdiv($time, 32);
        }

        // 80 bits randomness
        $randChars = '';
        for ($i = 0; $i < 16; $i++) {
            $randChars .= $enc[random_int(0, 31)];
        }

        return $timeChars . $randChars; // 26 chars
    }
}
