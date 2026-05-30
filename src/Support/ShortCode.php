<?php

declare(strict_types=1);

namespace EduQR\Support;

final class ShortCode
{
    // Omits I, O, 0, 1 to avoid visual ambiguity
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH = 6;

    public static function generate(): string
    {
        $chars = str_split(self::CHARSET);
        $max = count($chars) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $chars[random_int(0, $max)];
        }

        return $code;
    }

    public static function charset(): string
    {
        return self::CHARSET;
    }
}
