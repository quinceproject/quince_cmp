<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Sync;

use Configuration;
use Mail;
use Quince\Cmp\Storage\Options;
use Shop;

/**
 * Powiadomienie o zerwanym połączeniu z odbiorcą.
 *
 * Wysyłany najwyżej raz na dobę, niezależnie od liczby nieudanych pozycji.
 */
final class Alert
{
    /** Odstęp między listami o tej samej awarii. */
    private const ODSTEP_GODZIN = 24;

    private const SZABLON = 'quince_cmp_sync_failed';

    /**
     * Zgłasza, że pozycje wyczerpały próby.
     *
     * @return bool czy list poszedł; `false` oznacza też „jeszcze za wcześnie"
     */
    public static function sendFailure(int $idShop, int $ile, string $ostatniBlad): bool
    {
        if ($ile < 1 || !SyncConfig::bool(Options::SYNC_ALERT_ON, true, $idShop)) {
            return false;
        }

        if (!self::czasMinal($idShop)) {
            return false;
        }

        $sklep = new Shop($idShop);
        $adres = (string) Configuration::get('PS_SHOP_EMAIL', null, null, $idShop);

        if ($adres === '' || !\Validate::isEmail($adres)) {
            return false;
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        $poszlo = Mail::Send(
            $idLang,
            self::SZABLON,
            'Consent sync is failing on ' . $sklep->name,
            [
                '{shop_name}' => (string) $sklep->name,
                '{count}' => (string) $ile,
                '{error}' => $ostatniBlad,
                '{endpoint}' => SyncConfig::text(Options::SYNC_ENDPOINT, '', $idShop),
                '{attempts}' => (string) \Quince\Cmp\Storage\SyncQueue::MAX_ATTEMPTS,
            ],
            $adres,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'quince_cmp/mails/',
            false,
            $idShop
        );

        if ($poszlo) {
            Options::save(Options::SYNC_ALERT_LAST, date('Y-m-d H:i:s'), $idShop);
        }

        return (bool) $poszlo;
    }

    private static function czasMinal(int $idShop): bool
    {
        $ostatni = Options::text(Options::SYNC_ALERT_LAST, '', $idShop);

        if ($ostatni === '') {
            return true;
        }

        return strtotime($ostatni) < strtotime('-' . self::ODSTEP_GODZIN . ' hours');
    }
}
