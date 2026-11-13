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
 * którą zgoda odzwierciedla).
 *
 * Przed 0.3.2 w checkoucie stawało każde aktywne `kind = document`. Żeby sklep
 * po aktualizacji nie stracił zgód, które klient już akceptuje, istniejące
 * aktywne zgody dokumentowe dostają `show_in_checkout = 1`. Nowe zgody startują
 * z `0`.
 *
 * `core_field` zostaje pusty dla wszystkich; wypełnia go `upgrade-0.3.3.php`.
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
