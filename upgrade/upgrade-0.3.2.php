<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Cmp\Storage\Schema;

/**
 * 0.3.1 -> 0.3.2
 *
 * Dwie nowe kolumny w rodzajach zgód: `show_in_checkout` (czy zgoda ma stać
 * w checkoucie, niezależnie od `kind`) i `core_field` (kolumna `ps_customer`,
 * którą zgoda odzwierciedla — zastępuje sztywną stałą `CoreBridge::MAPOWANIE`).
 *
 * Do dziś w checkoucie stawało KAŻDE aktywne `kind = document` — bez wyjątku.
 * Żeby sklep po aktualizacji nie stracił zgód, które klient już akceptuje,
 * istniejące aktywne zgody dokumentowe dostają `show_in_checkout = 1`: to
 * odtwarza dzisiejsze zachowanie, a nie zmienia je. Nowe zgody, założone od
 * teraz, startują z `0` — sprzedawca włącza checkout świadomie.
 *
 * `core_field` zostaje pusty dla wszystkich: `CoreBridge::MAPOWANIE` nadal
 * obsługuje `newsletter`/`optin` po kodzie, dopóki panel nie dostanie pola do
 * wpisania tego wprost.
 */
function upgrade_module_0_3_2(Module $module): bool
{
    if (!(new Schema())->uzupelnijKolumny()) {
        return false;
    }

    return Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
            SET `show_in_checkout` = 1
          WHERE `kind` = "' . Schema::KIND_DOCUMENT . '" AND `active` = 1'
    );
}
