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
use Quince\Cmp\Sync\SyncConfig;

/**
 * 0.1.0 -> 0.2.0
 *
 * Consent Mode w pelnym zakresie (siedem sygnalow zamiast czterech), wymiana
 * z systemem zewnetrznym, ekran ustawien i warstwa sklepu.
 *
 * Kolumny dokladane sa metoda, ktora pomija to, co juz zastanie — dzieki temu
 * skrypt wolno uruchomic ponownie, a instalacja od zera i aktualizacja koncza
 * sie tym samym schematem.
 */
function upgrade_module_0_2_0(Module $module): bool
{
    // `installTables()` uzywa `CREATE TABLE IF NOT EXISTS`, wiec doklada
    // brakujace tabele i nie rusza istniejacych.
    if (!(new Schema())->installTables()) {
        return false;
    }

    // Sklep bez klucza nadawcy odrzuca wszystko, co przyjdzie z zewnatrz, i robi
    // to po cichu. Klucz nic nie wlacza, wiec zakladamy go od razu.
    foreach (Shop::getShops(false, null, true) as $id) {
        SyncConfig::zapewnijKlucze((int) $id);
    }

    return $module->registerHook('displayHeader')
        && $module->registerHook('actionOutputHTMLBefore')
        && $module->registerHook('actionFrontControllerSetMedia');
}
