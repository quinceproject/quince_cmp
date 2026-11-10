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
 * 0.3.4 -> 0.3.5
 *
 * Klauzula informacyjna o przetwarzaniu danych obok checkboxa regulaminu —
 * `displayCheckoutBeforeConfirmation`, bez checkboxa, bo przetwarzanie danych
 * do realizacji zamówienia nie jest wyborem do odmówienia (RODO art. 6 ust. 1
 * lit. b). Historia zapisuje się bezwarunkowo niezależnie od tego hooka —
 * patrz `CoreBridge::recordOrder()`/`recordCustomer()`.
 */
function upgrade_module_0_3_5(Module $module): bool
{
    return $module->registerHook('displayCheckoutBeforeConfirmation');
}
