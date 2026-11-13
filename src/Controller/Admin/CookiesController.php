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
use Quince\Cmp\Scan\Scanner;
use Quince\Cmp\Storage\ConsentTypes;
use Quince\Cmp\Storage\Cookies;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Schema;
use Configuration;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tools;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Tabela ciasteczek prezentowana klientowi w drugiej warstwie banera. */
final class CookiesController extends CmpController
{
    public function __construct(
        private readonly Cookies $cookies,
        private readonly ConsentTypes $types
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $idShop = Options::biezacySklep();
        $idLang = (int) \Context::getContext()->language->id;

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/cookies.html.twig', [
            'cookies' => $this->cookies->all($idShop, $idLang),
            'categories' => $this->cookies->categories($idShop, $idLang),
            // Kategorie banera: ta sama zgoda co regulamin czy newsletter, z treścią
            // i wersją wydawaną na jej karcie. Widoczna i edytowalna tylko tutaj,
            // nie na liście „Rodzaje zgód" (patrz indexAction w ConsentTypesController).
            'categoryTypes' => $this->types->all($idShop, $idLang, '', Schema::KIND_COOKIE),
            'shopName' => (string) (new Shop($idShop))->name,
            'shopScoped' => Shop::getContextShopID() === null,
            'findings' => (new Scanner())->findings($idShop),
            'scanPending' => (new Scanner())->pending($idShop),
            'scanLast' => (new Scanner())->lastSeen($idShop),
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Cookies',
            'quinceRoute' => 'quince_cmp_cookies',
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function editAction(Request $request, int $idCookie): Response
    {
        $idShop = Options::biezacySklep();
        $ciasteczko = $idCookie > 0 ? $this->cookies->find($idCookie, $idShop) : null;

        if ($idCookie > 0 && $ciasteczko === null) {
            return $this->redirectToRoute('quince_cmp_cookies');
        }

        // Nazwa podana w adresie przychodzi ze skanera: sprzedawca opisuje coś,
        // co sklep faktycznie zapisał, i nie ma powodu przepisywać nazwy ręcznie.
        // Osobna zmienna, nie `cookie`: ten rekord jeszcze nie istnieje, więc
        // `cookie` zostaje `null`, tak jak przy zwykłym „nowe". Inaczej odznaka
        // źródła i przycisk usuwania, które sprawdzają tylko czy `cookie` nie
        // jest puste, pokazałyby się dla czegoś, co nie ma jeszcze żadnych pól.
        $zeSkanera = trim((string) $request->query->get('name', ''));
        $podpowiedzNazwy = '';

        if ($ciasteczko === null && $zeSkanera !== '' && preg_match('/^[A-Za-z0-9_.\-*]{1,190}$/', $zeSkanera) === 1) {
            $podpowiedzNazwy = $zeSkanera;
        }

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/cookie_edit.html.twig', [
            'cookie' => $ciasteczko,
            'prefillName' => $podpowiedzNazwy,
            'categories' => $this->cookies->categories($idShop, (int) \Context::getContext()->language->id),
            'units' => Schema::LIFETIME_UNITS,
            'languages' => $this->languages(),
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Cookie',
            'quinceRoute' => 'quince_cmp_cookies',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_cookie_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_cookies');
        }

        $idShop = Options::biezacySklep();
        $idCookie = (int) $request->request->get('id_cmp_cookie', 0);
        $nazwa = trim((string) $request->request->get('name', ''));

        if ($nazwa === '') {
            $this->addFlash('error', $this->trans('The cookie name cannot be empty.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_cookie_edit', ['idCookie' => $idCookie]);
        }

        $opisy = [];

        foreach ($this->languages() as $jezyk) {
            $opisy[$jezyk['id_lang']] = trim((string) $request->request->get('description_' . $jezyk['id_lang'], ''));
        }

        $zapisane = $this->cookies->save(
            $idCookie,
            $idShop,
            $nazwa,
            trim((string) $request->request->get('provider', '')),
            trim((string) $request->request->get('category', '')),
            (int) $request->request->get('lifetime_value', 0),
            trim((string) $request->request->get('lifetime_unit', 'session')),
            $opisy
        );

        $this->addFlash(
            $zapisane ? 'success' : 'error',
            $zapisane
                ? $this->trans('Cookie saved.', [], 'Modules.Quincecmp.Admin')
                : $this->trans('Could not save: a cookie with this name already exists in this shop.', [], 'Modules.Quincecmp.Admin')
        );

        return $zapisane
            ? $this->redirectToRoute('quince_cmp_cookies')
            : $this->redirectToRoute('quince_cmp_cookie_edit', ['idCookie' => $idCookie]);
    }

    /**
     * Uruchamia skanowanie i odsyła do sklepu.
     *
     * Token wchodzi w adres sklepu, bo skan musi się odbyć w przeglądarce:
     * z serwera nie widać ciasteczek zakładanych przez JavaScript.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function scanStartAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_scan_start', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_cookies');
        }

        $idShop = Options::biezacySklep();
        $skaner = new Scanner();
        $skaner->reset($idShop);

        $sklep = new Shop($idShop);
        // Protokół z ustawień sklepu: na sklepie bez SSL adres pod „https://"
        // kończy się błędem protokołu, zanim skrypt skanera się załaduje.
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED', null, null, $idShop);
        $domena = $ssl && $sklep->domain_ssl ? $sklep->domain_ssl : $sklep->domain;
        $adres = Tools::getProtocol($ssl) . $domena . $sklep->getBaseURI() . '?' . Scanner::PARAM . '=' . $skaner->start($idShop);

        $this->addFlash('success', $this->trans(
            'Scan started. Browse the shop in the tab that just opened. Every page you visit is checked.', [],
            'Modules.Quincecmp.Admin'
        ));

        return $this->redirect($adres);
    }

    /** Znalezisko opisane albo świadomie pominięte. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function scanMarkAction(Request $request, int $idFinding): RedirectResponse
    {
        $status = (string) $request->query->get('status', Schema::FOUND_IGNORED);

        (new Scanner())->mark(Options::biezacySklep(), $idFinding, $status);

        return $this->redirectToRoute('quince_cmp_cookies');
    }

    /** Czyści listę znalezisk przed nowym przebiegiem. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function scanResetAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_scan_reset', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_cookies');
        }

        (new Scanner())->reset(Options::biezacySklep());

        $this->addFlash('success', $this->trans('Scan results cleared.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_cookies');
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteAction(int $idCookie, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_cookie_delete', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_cookies');
        }

        $this->cookies->delete($idCookie, Options::biezacySklep());

        $this->addFlash('success', $this->trans(
            'Cookie removed from the table. The cookie itself is set by the service. This list only describes it.', [],
            'Modules.Quincecmp.Admin'
        ));

        return $this->redirectToRoute('quince_cmp_cookies');
    }
}
