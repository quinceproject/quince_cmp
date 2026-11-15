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
 * 0.3.9 -> 0.4.0
 *
 * Zgoda na newsletter zapisuje się dopiero po przyjęciu zapisu przez rdzeń,
 * na `actionNewsletterRegistrationAfter`. Przedtem zapisywała się na samą
 * treść formularza, dla dowolnego wpisanego adresu.
 *
 * Tylko API rdzenia: klasa modułu w trakcie aktualizacji może być jeszcze
 * starą wersją.
 */
function upgrade_module_0_4_0(Module $module): bool
{
    return $module->registerHook('actionNewsletterRegistrationAfter');
}
