<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Ui;

/**
 * Pozycje bocznego paska panelu.
 *
 * Pozycja bez klucza `route` odpowiada ekranowi jeszcze niezbudowanemu i jest
 * renderowana jako nieaktywna. Trasa dodawana jest razem z kontrolerem.
 */
final class PanelNavigation
{
    /**
     * @return array<int,array{label:string,icon:string,route?:string}>
     */
    public function items(): array
    {
        return [
            ['label' => 'Overview', 'icon' => 'layout', 'route' => 'quince_cmp_overview'],
            ['label' => 'Consent types', 'icon' => 'edit', 'route' => 'quince_cmp_types'],
            ['label' => 'Cookies', 'icon' => 'box', 'route' => 'quince_cmp_cookies'],
            ['label' => 'Scripts & blocking', 'icon' => 'shield', 'route' => 'quince_cmp_scripts'],
            ['label' => 'Banner & preferences', 'icon' => 'eye', 'route' => 'quince_cmp_banner'],
            ['label' => 'Consent log', 'icon' => 'search', 'route' => 'quince_cmp_log'],
            ['label' => 'Sync', 'icon' => 'refresh', 'route' => 'quince_cmp_sync'],
            ['label' => 'Settings', 'icon' => 'settings', 'route' => 'quince_cmp_settings'],
            ['label' => 'API', 'icon' => 'link', 'route' => 'quince_cmp_api'],
            ['label' => 'Support', 'icon' => 'info', 'route' => 'quince_cmp_support'],
        ];
    }
}
