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

/**
 * 0.3.5 -> 0.3.6
 *
 * Wycofuje `displayCheckoutBeforeConfirmation` z 0.3.5 — własny, wymyślony
 * tekst o przetwarzaniu danych. Treść o polityce prywatności i klauzula mają
 * już swoje natywne moduły (`psgdpr`, `ps_dataprivacy`) — temat `one-page-
 * checkout.tpl` wyświetla ich treść wprost, bez dopisywania czegoś własnego.
 * Historia zapisuje się bezwarunkowo niezależnie od tego hooka — patrz
 * `CoreBridge::recordOrder()`/`recordCustomer()`.
 */
function upgrade_module_0_3_6(Module $module): bool
{
    return $module->unregisterHook('displayCheckoutBeforeConfirmation');
}
