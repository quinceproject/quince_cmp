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
 * 0.4.6 -> 0.4.7
 *
 * Znacznik `[quince_cookies]` w treści strony CMS zamienia się na tabelę
 * ciasteczek, przez `filterCmsContent`.
 *
 * Tylko API rdzenia: klasa modułu w trakcie aktualizacji może być jeszcze
 * starą wersją.
 */
function upgrade_module_0_4_7(Module $module): bool
{
    return $module->registerHook('filterCmsContent');
}
