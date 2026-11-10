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
 * 0.3.2 -> 0.3.3
 *
 * `CoreBridge::MAPOWANIE` zostaje usunięta: odwzorowanie kolumn `ps_customer`
 * czyta się teraz z `core_field` na rodzaju zgody, ustawianego w panelu.
 * Dotąd `core_field` było puste dla wszystkich — ta aktualizacja wypełnia je
 * dla dwóch kodów, które stała obsługiwała, żeby żaden sklep nie przestał
 * zwierciadlić newslettera i zgody na oferty partnerów po aktualizacji.
 */
function upgrade_module_0_3_3(Module $module): bool
{
    return Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
            SET `core_field` = "newsletter"
          WHERE `code` = "newsletter" AND `core_field` = ""'
    ) && Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
            SET `core_field` = "optin"
          WHERE `code` = "marketing_email" AND `core_field` = ""'
    );
}
