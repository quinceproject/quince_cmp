<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Ui;

/**
 * Kontrast pary kolorów według WCAG 2.1.
 *
 * Moduł zbierający zgody musi być dostępny, a przycisk odrzucenia nie może być
 * słabiej widoczny od przycisku akceptacji. Odczyt na ekranie banera pokazuje
 * wartości policzone z wybranych kolorów, nie wpisane ręcznie.
 */
final class Contrast
{
    /** Próg dla tekstu poniżej 24 px. */
    public const AA_TEXT = 4.5;

    /** Próg dla dużego tekstu, ikon, obramowań i wskaźnika focusu. */
    public const AA_LARGE = 3.0;

    /** Stosunek kontrastu dwóch kolorów w zapisie `#rrggbb`. */
    public static function ratio(string $hexA, string $hexB): float
    {
        $a = self::luminancja($hexA);
        $b = self::luminancja($hexB);

        $jasniejszy = max($a, $b);
        $ciemniejszy = min($a, $b);

        return round(($jasniejszy + 0.05) / ($ciemniejszy + 0.05), 2);
    }

    /** Czarny albo biały napis — ten, który daje wyższy kontrast na podanym tle. */
    public static function onColour(string $hexTla): string
    {
        return self::ratio($hexTla, '#000000') >= self::ratio($hexTla, '#ffffff') ? '#000000' : '#ffffff';
    }

    public static function passes(float $ratio, float $prog = self::AA_TEXT): bool
    {
        return $ratio >= $prog;
    }

    private static function luminancja(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $kanal = static function (float $wartosc): float {
            $wartosc /= 255;

            return $wartosc <= 0.03928 ? $wartosc / 12.92 : (($wartosc + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $kanal($r) + 0.7152 * $kanal($g) + 0.0722 * $kanal($b);
    }

    /** @return array{0:float,1:float,2:float} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return [0.0, 0.0, 0.0];
        }

        return [
            (float) hexdec(substr($hex, 0, 2)),
            (float) hexdec(substr($hex, 2, 2)),
            (float) hexdec(substr($hex, 4, 2)),
        ];
    }
}
