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
 * 0.2.0 -> 0.3.0
 *
 * Wpis wycofania zgody dokonany przez obsluge: dziennik dostaje podpis
 * pracownika i pole na podstawe, na jakiej wpis powstal.
 *
 * Kolumny dokladane sa metoda, ktora pomija to, co zastanie, wiec skrypt wolno
 * uruchomic ponownie, a instalacja od zera i aktualizacja koncza sie tym samym
 * schematem.
 */
function upgrade_module_0_3_0(Module $module): bool
{
    return (new Schema())->installTables();
}
