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

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

/**
 * Zakładka „Overview" w menu PrestaShopa.
 *
 * Sam nie renderuje niczego. Ekran jest trasą Symfony (`quince_cmp_overview`),
 * a ta klasa istnieje z dwóch powodów: PrestaShop musi mieć klasę dla pozycji
 * w menu, a `_legacy_controller` w `config/routes.yml` musi mieć na czym oprzeć
 * sprawdzenie uprawnień do trasy.
 *
 * Każdy kolejny ekran modułu dostanie taki sam plik obok — to jest cała
 * ceremonia potrzebna, żeby wszedł do menu.
 */
class AdminQuinceCmpOverviewController extends ModuleAdminController
{
    public function initContent(): void
    {
        $router = SymfonyContainer::getInstance()?->get('router');

        // Kontener bywa pusty poza kontekstem kernela (np. przy wywołaniu z CLI).
        // Wtedy lepiej nie przekierowywać nigdzie niż wywrócić się na `null`.
        if ($router === null) {
            parent::initContent();

            return;
        }

        Tools::redirectAdmin($router->generate('quince_cmp_overview'));
    }
}
