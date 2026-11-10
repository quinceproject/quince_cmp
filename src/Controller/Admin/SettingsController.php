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

use CMS;
use Module;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Cmp\Front\Revision;
use Quince\Cmp\Integration\Engine;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Retention;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ustawienia modułu: retencja, ważność zgody, tryb testowy, zachowanie przed zgodą. */
final class SettingsController extends CmpController
{
    /** Moduł zgód wbudowany w PrestaShopa. Zbiera własną zgodę w tych samych formularzach. */
    private const PSGDPR = 'psgdpr';

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        $idShop = Options::biezacySklep();
        $idLang = (int) \Context::getContext()->language->id;

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/settings.html.twig', [
            'retentionMonths' => Options::int(Options::RETENTION_MONTHS, 36, $idShop),
            'retentionPending' => (new Retention())->pending($idShop),
            'lifetimeMonths' => Options::int(Options::LIFETIME_MONTHS, 12, $idShop),
            'reaskOnChange' => Options::bool(Options::REASK_ON_CHANGE, true, $idShop),
            'revision' => Revision::current($idShop),
            'anonMonths' => Options::int(Options::ANON_MONTHS, 6, $idShop),
            'policyCms' => Options::int(Options::POLICY_CMS, 0, $idShop),
            'cmsPages' => $this->stronyCms($idLang),
            'respectGpc' => Options::bool(Options::RESPECT_GPC, true, $idShop),
            'blockingOn' => Options::bool(Options::BLOCKING_ON, true, $idShop),
            'beforeConsent' => Options::text(Options::BEFORE_CONSENT, Options::BEFORE_ALL_BUT_NECESSARY, $idShop),
            'beforeChoices' => Options::BEFORE_CONSENT_CHOICES,
            'testMode' => Options::bool(Options::TEST_MODE, false, $idShop),
            'testIps' => $this->adresy(Options::text(Options::TEST_IPS, '', $idShop)),
            'myIp' => $this->mojAdres(),
            'engineDetected' => Engine::dostepny(),
            'psgdpr' => $this->psgdpr(),
            'shopName' => (string) (new Shop($idShop))->name,
            'shopScoped' => Shop::getContextShopID() === null,
            'layoutTitle' => 'Settings',
            'quinceRoute' => 'quince_cmp_settings',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_settings_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_settings');
        }

        $idShop = Options::biezacySklep();

        $przed = (string) $request->request->get('before_consent', Options::BEFORE_ALL_BUT_NECESSARY);

        Options::save(Options::RETENTION_MONTHS, $this->miesiace($request->request->get('retention_months'), 36, 1, 240), $idShop);
        Options::save(Options::LIFETIME_MONTHS, $this->miesiace($request->request->get('lifetime_months'), 12, 1, 60), $idShop);
        Options::save(Options::REASK_ON_CHANGE, (int) $request->request->getBoolean('reask_on_change'), $idShop);
        Options::save(Options::ANON_MONTHS, $this->miesiace($request->request->get('anon_months'), 6, 1, 60), $idShop);
        Options::save(Options::POLICY_CMS, (int) $request->request->get('policy_cms', 0), $idShop);
        Options::save(Options::RESPECT_GPC, (int) $request->request->getBoolean('respect_gpc'), $idShop);
        Options::save(Options::BLOCKING_ON, (int) $request->request->getBoolean('blocking_on'), $idShop);
        Options::save(Options::TEST_MODE, (int) $request->request->getBoolean('test_mode'), $idShop);
        Options::save(Options::TEST_IPS, implode(',', $this->adresy((string) $request->request->get('test_ips', ''))), $idShop);

        Options::save(
            Options::BEFORE_CONSENT,
            in_array($przed, Options::BEFORE_CONSENT_CHOICES, true) ? $przed : Options::BEFORE_ALL_BUT_NECESSARY,
            $idShop
        );

        $this->addFlash('success', $this->trans('Settings saved.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_settings');
    }

    /**
     * Kasuje zdarzenia po terminie od razu, bez czekania na cron.
     *
     * Przydaje się po skróceniu terminu: sprzedawca chce zobaczyć skutek
     * wtedy, gdy podejmuje decyzję, a nie następnego dnia.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function purgeAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_settings_purge', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_settings');
        }

        $wynik = (new Retention())->run(Options::biezacySklep());

        $this->addFlash('success', $this->trans(
            '%events% events and %links% browser links removed.',
            ['%events%' => $wynik['events'] + $wynik['anonymous'], '%links%' => $wynik['links']],
            'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_settings');
    }

    /**
     * Unieważnia wszystkie zgody w tym sklepie.
     *
     * Dla zmian, których odcisk konfiguracji nie wyłapie — nowego celu
     * przetwarzania w istniejącej kategorii albo zmiany podwykonawcy, która
     * nie dokłada ciasteczka. Dziennik zostaje nietknięty.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function reaskAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_settings_reask', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_settings');
        }

        Revision::bump(Options::biezacySklep());

        $this->addFlash('success', $this->trans(
            'Everyone will be asked again on their next visit. Records already collected stay in the log.', [],
            'Modules.Quincecmp.Admin'
        ));

        return $this->redirectToRoute('quince_cmp_settings');
    }

    /**
     * Liczba miesięcy w rozsądnych granicach.
     *
     * Zero oznaczałoby kasowanie dziennika przy każdym przebiegu, a wartość
     * bez górnej granicy — pole, w które ktoś wpisze rok zamiast liczby miesięcy.
     */
    private function miesiace(mixed $wartosc, int $domyslnie, int $min, int $max): int
    {
        $liczba = (int) $wartosc;

        return $liczba >= $min && $liczba <= $max ? $liczba : $domyslnie;
    }

    /**
     * Adresy IP z pola tekstowego, po jednym.
     *
     * @return array<int,string>
     */
    private function adresy(string $surowe): array
    {
        $out = [];

        foreach (preg_split('/[\s,;]+/', trim($surowe)) ?: [] as $adres) {
            if ($adres !== '' && filter_var($adres, FILTER_VALIDATE_IP) !== false && !in_array($adres, $out, true)) {
                $out[] = $adres;
            }
        }

        return $out;
    }

    /** Adres, z którego sprzedawca właśnie patrzy — żeby nie musiał go szukać. */
    private function mojAdres(): string
    {
        $adres = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($adres, FILTER_VALIDATE_IP) !== false ? $adres : '';
    }

    /**
     * Stan modułu `psgdpr`.
     *
     * Oba moduły dokładają własne pole zgody do tych samych formularzy. Sprzedawca
     * musi wiedzieć, że tak jest, zanim klient zobaczy dwa razy to samo pytanie.
     *
     * @return array{installed:bool,active:bool,version:string}
     */
    private function psgdpr(): array
    {
        $zainstalowany = Module::isInstalled(self::PSGDPR);
        $modul = $zainstalowany ? Module::getInstanceByName(self::PSGDPR) : null;

        return [
            'installed' => $zainstalowany,
            'active' => $zainstalowany && Module::isEnabled(self::PSGDPR),
            'version' => $modul instanceof Module ? (string) $modul->version : '',
        ];
    }

    /**
     * Strony CMS do wyboru polityki prywatności.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private function stronyCms(int $idLang): array
    {
        $out = [];

        foreach (CMS::listCms($idLang, null, true) as $strona) {
            $out[] = ['id' => (int) $strona['id_cms'], 'name' => (string) $strona['meta_title']];
        }

        return $out;
    }
}
