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
 * Zakładka „Consent log" w menu PrestaShopa.
 *
 * Sam nie renderuje niczego. Ekran jest trasą Symfony (`quince_cmp_log`).
 */
class AdminQuinceCmpLogController extends ModuleAdminController
{
    public function initContent(): void
    {
        $router = SymfonyContainer::getInstance()?->get('router');

        if ($router === null) {
            parent::initContent();

            return;
        }

        Tools::redirectAdmin($router->generate('quince_cmp_log'));
    }
}
