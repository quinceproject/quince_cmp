<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Controller\Admin;

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Cmp\Integration\Engine;
use Quince\Cmp\Report\Deployment;
use Symfony\Component\HttpFoundation\Response;

/** Przegląd: stan wdrożenia per sklep, diagnostyka, decyzje z 30 dni, ostatnia synchronizacja. */
final class OverviewController extends CmpController
{
    public function __construct(private readonly Deployment $deployment)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/overview.html.twig', [
            'rows' => $this->deployment->rows(),
            'columns' => $this->deployment->columns(),
            'diagnostics' => $this->deployment->diagnostics(),
            'decisions' => $this->deployment->decisions(),
            'lastSync' => $this->deployment->lastSync(),
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Overview',
            'quinceRoute' => 'quince_cmp_overview',
        ]);
    }
}
