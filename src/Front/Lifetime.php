<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Language;
use Quince\Cmp\Storage\Schema;

/** Okres ważności ciasteczka jako napis w danym języku, z odmianą liczebnika. */
final class Lifetime
{
    /** Klucze tłumaczeń wg jednostki z `Schema::LIFETIME_UNITS`. */
    private const OKRESY = [
        'minutes' => '%count% minute|%count% minutes',
        'hours' => '%count% hour|%count% hours',
        'days' => '%count% day|%count% days',
        'months' => '%count% month|%count% months',
        'years' => '%count% year|%count% years',
    ];

    public static function label(int $wartosc, string $jednostka, int $idLang): string
    {
        if ($jednostka === Schema::LIFETIME_PERSISTENT) {
            return self::tlumacz('until deleted', $idLang);
        }

        if ($wartosc <= 0 || !isset(self::OKRESY[$jednostka])) {
            return self::tlumacz('session', $idLang);
        }

        // Formy rozdzielone `|` w kolejności reguły z `formaLiczebnika()`.
        // `%count%` podstawiamy sami: przekazany translatorowi uruchomiłby jego
        // odmianę, która nie rozpoznaje lokalizacji w zapisie `pl-PL`.
        $formy = explode('|', self::tlumacz(self::OKRESY[$jednostka], $idLang));
        $iso = (string) Language::getIsoById($idLang);
        $forma = $formy[min(self::formaLiczebnika($wartosc, $iso), count($formy) - 1)];

        return str_replace('%count%', (string) $wartosc, $forma);
    }

    /** Numer formy liczebnika w tłumaczeniu: 0 dla jednej sztuki, dalej wg języka. */
    private static function formaLiczebnika(int $n, string $iso): int
    {
        if ($iso === 'pl') {
            if ($n === 1) {
                return 0;
            }

            return $n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) ? 1 : 2;
        }

        return $n === 1 ? 0 : 1;
    }

    private static function tlumacz(string $tekst, int $idLang): string
    {
        return BannerData::tlumacz($tekst, $idLang);
    }
}
