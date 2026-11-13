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
 * 0.3.3 -> 0.3.4
 *
 * Nowa sekcja „Quince consents" na natywnej karcie klienta w rdzeniu, przez
 * hook `displayAdminCustomers`. Rejestracja tutaj, bo `install()` odpala się
 * tylko raz. Bez tego istniejące instalacje nigdy by go nie dostały.
 */
function upgrade_module_0_3_4(Module $module): bool
{
    return $module->registerHook('displayAdminCustomers');
}
