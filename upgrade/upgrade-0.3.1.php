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
 * 0.3.0 -> 0.3.1
 *
 * Jeden widoczny wpis w menu sklepu, pod gałęzią „Quince project" silnika.
 *
 * PrestaShop czyta deklarację zakładek **tylko przy instalacji**, więc sklepy,
 * które mają moduł od wcześniej, nie dostaną nowego układu same. Ten skrypt
 * przestawia im to, co zrobiłaby świeża instalacja:
 *
 * - kontener `AdminQuinceCmp` ląduje pod gałęzią Quince, staje się widoczny
 *   i dostaje trasę na ekran przeglądu,
 * - dziewięć ekranów pod nim zostaje ukrytych. Nie kasujemy ich: trasy modułu
 *   wskazują je w `_legacy_controller`, a to po tej nazwie PrestaShop sprawdza,
 *   czy pracownik ma prawo wejść. Skasowanie zabrałoby dostęp do 45 tras.
 *
 * Bez silnika gałęzi nie ma i wtedy rodzica nie ruszamy. Moduł działa wtedy
 * samodzielnie, a PrestaShop zostawia go tam, gdzie wiesza moduły bez rodzica.
 */
function upgrade_module_0_3_1(Module $module): bool
{
    $idKontenera = (int) Tab::getIdFromClassName(Quince_Cmp::TAB_PARENT);

    if ($idKontenera === 0) {
        // Moduł bez zakładek: nic do przestawienia.
        return true;
    }

    $kontener = new Tab($idKontenera);
    $kontener->active = 1;
    $kontener->route_name = 'quince_cmp_overview';

    // Kopia z przyrostkiem `_MTR` idzie pierwsza: gdy do gałęzi trafia pierwsze
    // dziecko, PrestaShop kopiuje rodzica i to kopia staje się kontenerem.
    foreach (['AdminQuinceProject_MTR', 'AdminQuinceProject'] as $klasa) {
        $idGalezi = (int) Tab::getIdFromClassName($klasa);

        if ($idGalezi > 0) {
            $kontener->id_parent = $idGalezi;

            break;
        }
    }

    foreach (Language::getLanguages(false) as $jezyk) {
        $kontener->name[(int) $jezyk['id_lang']] = 'Consents and cookies';
    }

    if (!$kontener->save()) {
        return false;
    }

    Db::getInstance()->update(
        'tab',
        ['active' => 0],
        '`class_name` LIKE "AdminQuinceCmp%" AND `class_name` <> "' . pSQL(Quince_Cmp::TAB_PARENT) . '"'
    );

    return true;
}
