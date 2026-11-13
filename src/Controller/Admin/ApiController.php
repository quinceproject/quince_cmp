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

use Configuration;
use PrestaShop\PrestaShop\Core\Domain\ApiClient\Command\AddApiClientCommand;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Cmp\Api\ApiSurface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tools;

/**
 * Ekran API: jak się podłączyć, czym, i czy ktoś już to zrobił.
 *
 * Klucze, zakresy i odwoływanie dostępu obsługuje OAuth2 rdzenia PrestaShop 9,
 * moduł nie ma własnego generatora kluczy. Ten ekran mówi, **co** moduł
 * wystawia, zakłada klienta z **zakresami modułu już zaznaczonymi**
 * i pokazuje, **czy ktoś się połączył**.
 */
final class ApiController extends CmpController
{
    /** Ile godzin żyje token dostępu utworzonego stąd klienta. */
    private const ZYCIE_TOKENU = 3600;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/api.html.twig', [
            'layoutTitle' => 'API',
            'active' => 'quince_cmp_api',
            'readiness' => ApiSurface::readiness(),
            'operations' => ApiSurface::operations(),
            'scopes' => ApiSurface::scopes(),
            'baseUrl' => ApiSurface::baseUrl(),
            'clients' => ApiSurface::clients(),
            // Zadanie bierzemy z argumentu akcji. `FrameworkBundleAdminController`
            // nie ma `getRequest()`, wywołanie go wywraca cały ekran.
            'freshSecret' => $request->getSession()->get('quince_cmp_api_secret'),
        ]);
    }

    /**
     * Włączenie Admin API całego sklepu.
     *
     * Osobna akcja z potwierdzeniem, bo to ustawienie **sklepu**, nie modułu:
     * od tej chwili API wystawiają wszystkie moduły, które je zadeklarowały.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to do this.')]
    public function enableAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_api_enable', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_api');
        }

        Configuration::updateGlobalValue('PS_ENABLE_ADMIN_API', 1);

        $this->addFlash('success', $this->trans(
            'The Admin API is on. It serves every module that declares resources, not only this one.',
            [],
            'Modules.Quincecmp.Admin'
        ));

        return $this->redirectToRoute('quince_cmp_api');
    }

    /**
     * Założenie klienta API z zakresami tego modułu.
     *
     * Przez polecenie rdzenia, więc klient ląduje na liście klientów API jak
     * każdy inny i tam można go wyłączyć albo skasować.
     *
     * **Sekret pokazujemy raz.** Rdzeń trzyma go zahaszowany i drugi raz go nie
     * odda. Zgubiony sekret trzeba wygenerować od nowa.
     */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to do this.')]
    public function createClientAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_api_client', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_api');
        }

        $nazwa = trim((string) $request->request->get('name', ''));

        if ($nazwa === '') {
            $this->addFlash('error', $this->trans('Name the system that will connect.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_api');
        }

        $zapis = (bool) $request->request->get('write');
        $zakresy = $zapis ? ApiSurface::scopes() : [ApiSurface::SCOPE_READ];

        try {
            $utworzony = $this->dispatchCommand(new AddApiClientCommand(
                mb_substr($nazwa, 0, 250),
                'quince-cmp-' . Tools::passwdGen(12, 'NO_NUMERIC'),
                true,
                $this->trans('Created from the Quince CMP screen.', [], 'Modules.Quincecmp.Admin'),
                self::ZYCIE_TOKENU,
                $zakresy
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->trans(
                'The client could not be created: %reason%',
                ['%reason%' => $e->getMessage()],
                'Modules.Quincecmp.Admin'
            ));

            return $this->redirectToRoute('quince_cmp_api');
        }

        // Sekret idzie przez sesję, nie przez adres. W adresie zostawałby
        // w historii przeglądarki i w dzienniku serwera.
        $request->getSession()->set('quince_cmp_api_secret', [
            'clientId' => $utworzony->getApiClientId()->getValue(),
            'secret' => $utworzony->getSecret(),
            'scopes' => $zakresy,
        ]);

        return $this->redirectToRoute('quince_cmp_api');
    }

    /** Zapomnienie pokazanego sekretu. Po tym nie da się go już odczytać. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function forgetSecretAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_api_forget', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_api');
        }

        $request->getSession()->remove('quince_cmp_api_secret');

        return $this->redirectToRoute('quince_cmp_api');
    }
}
