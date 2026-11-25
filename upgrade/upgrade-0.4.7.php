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
 * Bez `registerHook()`: sprawdza on metodę na obiekcie modułu, a w trakcie
 * aktualizacji to jeszcze stara klasa, bez `hookFilterCmsContent`. Rdzeń
 * zgłasza wtedy ostrzeżenie, a w trybie deweloperskim przerywa aktualizację.
 * Wpis w `hook_module` dodajemy sami, tak jak robi to `Hook::registerHook()`.
 */
function upgrade_module_0_4_7(Module $module): bool
{
    $db = Db::getInstance();
    $idHook = (int) Hook::getIdByName('filterCmsContent', false);

    if ($idHook <= 0) {
        $hook = new Hook();
        $hook->name = 'filterCmsContent';
        $hook->title = 'filterCmsContent';

        if (!$hook->add()) {
            return false;
        }

        $idHook = (int) $hook->id;
    }

    foreach (Shop::getCompleteListOfShopsID() as $idShop) {
        $warunek = '`id_hook` = ' . $idHook . ' AND `id_shop` = ' . (int) $idShop;

        if ($db->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'hook_module`
              WHERE ' . $warunek . ' AND `id_module` = ' . (int) $module->id
        )) {
            continue;
        }

        $pozycja = (int) $db->getValue(
            'SELECT MAX(`position`) FROM `' . _DB_PREFIX_ . 'hook_module` WHERE ' . $warunek
        );

        if (!$db->insert('hook_module', [
            'id_module' => (int) $module->id,
            'id_hook' => $idHook,
            'id_shop' => (int) $idShop,
            'position' => $pozycja + 1,
        ])) {
            return false;
        }
    }

    Cache::clean('hook_module_list');

    return true;
}
