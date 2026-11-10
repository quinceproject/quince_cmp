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

use Module;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Cmp\Integration\Engine;
use Quince\Cmp\Integration\Release;
use Quince\Cmp\Integration\Updater;
use Quince\Cmp\Storage\ConfigTransfer;
use Quince\Cmp\Storage\Options;
use Shop;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tools;

/**
 * Wsparcie: czym moduł jest, gdzie szukać pomocy, czy jest nowsze wydanie,
 * oraz przenoszenie konfiguracji między sklepami.
 */
final class SupportController extends CmpController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        $idShop = Options::biezacySklep();
        $modul = Module::getInstanceByName('quince_cmp');
        $wersja = $modul instanceof Module ? (string) $modul->version : '0.0.0';

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/support.html.twig', [
            'version' => $wersja,
            'pendingUpgrade' => $this->wersjaWBazie(),
            'obstacles' => Updater::przeszkody(),
            'release' => Release::status($wersja, $idShop),
            'engineDetected' => Engine::dostepny(),
            'system' => $this->system(),
            'transfer' => (new ConfigTransfer())->export($idShop),
            'shopName' => (string) (new Shop($idShop))->name,
            'shopScoped' => Shop::getContextShopID() === null,
            'layoutTitle' => 'Support',
            'quinceRoute' => 'quince_cmp_support',
        ]);
    }

    /**
     * Podmiana plików modułu na nowsze wydanie.
     *
     * Wyłącznie po kliknięciu sprzedawcy. Stan wydania pobieramy tu ponownie
     * i to on decyduje, co się pobierze — gdyby przycisk niósł adres paczki,
     * wystarczyłoby podmienić go w formularzu, żeby moduł zainstalował cudzy kod.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function updateAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_support_update', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_support');
        }

        $idShop = Options::biezacySklep();
        $modul = Module::getInstanceByName('quince_cmp');
        $stan = Release::status($modul instanceof Module ? (string) $modul->version : '0.0.0', $idShop, true);

        if ($stan['error'] !== '') {
            $this->addFlash('error', $stan['error']);

            return $this->redirectToRoute('quince_cmp_support');
        }

        if (!$stan['newer']) {
            $this->addFlash('warning', $this->trans('There is nothing newer to install.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_support');
        }

        $wynik = (new Updater())->run($stan['zip'], $stan['latest'], $stan['sha256']);

        if (!$wynik['ok']) {
            $this->addFlash('error', $wynik['error']);

            return $this->redirectToRoute('quince_cmp_support');
        }

        // Od tej linii pliki na dysku są już nowe, a wczytany kod stary. Poza
        // wyczyszczeniem pamięci podręcznej i przekierowaniem nie wolno tu zrobić
        // nic, co sięgnęłoby po klasę modułu jeszcze niewczytaną.
        Options::save(Options::UPDATE_CACHE, '', $idShop);
        Tools::clearSf2Cache();

        $this->addFlash('success', $this->trans(
            'Version %v% is in place.',
            ['%v%' => $wynik['version']],
            'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_support');
    }

    /** Wymusza odpytanie GitHuba z pominięciem zapamiętanej odpowiedzi. */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function checkAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_support_check', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_support');
        }

        $idShop = Options::biezacySklep();
        $modul = Module::getInstanceByName('quince_cmp');
        $stan = Release::status($modul instanceof Module ? (string) $modul->version : '0.0.0', $idShop, true);

        if ($stan['error'] !== '') {
            $this->addFlash('error', $stan['error']);
        } elseif ($stan['newer']) {
            $this->addFlash('success', $this->trans('Version %v% is available.', ['%v%' => $stan['latest']], 'Modules.Quincecmp.Admin'));
        } else {
            $this->addFlash('success', $this->trans('You are on the latest version.', [], 'Modules.Quincecmp.Admin'));
        }

        return $this->redirectToRoute('quince_cmp_support');
    }

    /**
     * Wywóz konfiguracji jako plik.
     *
     * Nazwa niesie sklep i datę, bo pliki z dwóch witryn wyglądają identycznie
     * w środku i po tygodniu nie sposób ich rozróżnić.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function exportAction(): Response
    {
        $idShop = Options::biezacySklep();
        $dane = (new ConfigTransfer())->export($idShop);

        $nazwa = 'quince-cmp-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) (new Shop($idShop))->name))
            . '-' . date('Y-m-d') . '.json';

        $odpowiedz = new Response((string) json_encode($dane, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $odpowiedz->headers->set('Content-Type', 'application/json; charset=utf-8');
        $odpowiedz->headers->set('Content-Disposition', 'attachment; filename="' . $nazwa . '"');

        return $odpowiedz;
    }

    /**
     * Wgranie konfiguracji.
     *
     * Dwa kroki: bez potwierdzenia pokazujemy, co plik zmieni; z potwierdzeniem
     * wgrywamy. Nadpisanie skonfigurowanego sklepu jednym kliknięciem to strata
     * pracy, której nie da się cofnąć.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function importAction(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('quince_cmp_support_import', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_support');
        }

        $idShop = Options::biezacySklep();
        $plik = $request->files->get('config');

        if ($plik === null || !$plik->isValid()) {
            $this->addFlash('error', $this->trans('No file arrived.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_support');
        }

        $dane = json_decode((string) file_get_contents($plik->getPathname()), true);

        if (!is_array($dane)) {
            $this->addFlash('error', $this->trans('The file is not valid JSON.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_support');
        }

        $transfer = new ConfigTransfer();
        $podglad = $transfer->preview($dane, $idShop);

        if (!$podglad['valid']) {
            $this->addFlash('error', $podglad['error']);

            return $this->redirectToRoute('quince_cmp_support');
        }

        if (!$request->request->getBoolean('confirm')) {
            $this->addFlash('warning', $this->trans(
                'The file holds %t% consent types, %c% cookies and %s% services. Tick the confirmation to replace what is here now.',
                [
                    '%t%' => $podglad['summary']['types']['incoming'],
                    '%c%' => $podglad['summary']['cookies']['incoming'],
                    '%s%' => $podglad['summary']['scripts']['incoming'],
                ],
                'Modules.Quincecmp.Admin'
            ));

            // Usługi wymieniamy z nazwy i domeny. Liczba nie mówi nic o tym, czyj
            // kod wejdzie do stopki sklepu, a plik konfiguracyjny krąży między
            // ludźmi i zwykle nikt nie ogląda jego zawartości.
            foreach ($podglad['services'] as $usluga) {
                $this->addFlash('warning', $this->trans(
                    'Service to be added: %name% (%where%)',
                    [
                        '%name%' => $usluga['name'] !== '' ? $usluga['name'] : '(unnamed)',
                        '%where%' => $this->skad($usluga),
                    ],
                    'Modules.Quincecmp.Admin'
                ));
            }

            return $this->redirectToRoute('quince_cmp_support');
        }

        $transfer->import($dane, $idShop);

        $this->addFlash('success', $this->trans(
            'Configuration replaced. The consent log and published clause versions were left untouched.', [],
            'Modules.Quincecmp.Admin'
        ));

        return $this->redirectToRoute('quince_cmp_support');
    }

    /**
     * Uruchamia skrypty z `upgrade/`, gdy pliki są już nowe, a baza jeszcze nie.
     *
     * Osobna akcja, a nie skutek uboczny wyświetlenia ekranu: zapis do bazy przy
     * zwykłym wejściu na stronę jest zaskoczeniem, a przy dwóch kartach otwartych
     * naraz — dwoma przebiegami migracji jednocześnie.
     *
     * Podmiana plików i migracja bazy i tak muszą pójść w dwóch żądaniach: kod,
     * który podmienia pliki, działa jeszcze na starej wersji i nie widzi skryptów,
     * które właśnie wgrał.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function finishUpgradeAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_support_finish_upgrade', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_support');
        }

        $modul = Module::getInstanceByName('quince_cmp');
        $wBazie = $this->wersjaWBazie();

        if (!$modul instanceof Module || $wBazie === '') {
            return $this->redirectToRoute('quince_cmp_support');
        }

        $modul->installed = true;
        $modul->database_version = $wBazie;

        // Zwraca `true` tylko wtedy, gdy są skrypty do uruchomienia. Wydanie bez
        // migracji też przechodzi tędy — PrestaShop podnosi wtedy numer w bazie
        // samo, wewnątrz tego wywołania.
        if (Module::initUpgradeModule($modul)) {
            $modul->runUpgradeModule();
        }

        $this->addFlash('success', $this->trans(
            'The module was updated from version %from% to %to%.',
            ['%from%' => $wBazie, '%to%' => (string) $modul->version],
            'Modules.Quincecmp.Admin'
        ));

        return $this->redirectToRoute('quince_cmp_support');
    }

    /**
     * Wersja zapisana w bazie, gdy jest starsza od plików. Pusty napis znaczy,
     * że nie ma czego migrować.
     *
     * Odczytujemy ją sami, bo `getInstanceByName` jej nie wypełnia — robi to
     * dopiero `getModulesOnDisk`, wołane przy budowaniu listy modułów.
     * Pozostawiona pusta, każe `initUpgradeModule` przyjąć, że baza jest równa
     * plikom, i migracja nigdy nie rusza.
     */
    private function wersjaWBazie(): string
    {
        $modul = Module::getInstanceByName('quince_cmp');

        if (!$modul instanceof Module) {
            return '';
        }

        $wBazie = (string) \Db::getInstance()->getValue(
            'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = "quince_cmp"'
        );

        return $wBazie !== '' && version_compare((string) $modul->version, $wBazie, '>') ? $wBazie : '';
    }

    /**
     * Skąd usługa bierze kod: z podanej domeny albo z samej strony.
     *
     * @param array{name:string,category:string,hosts:string,inline:bool} $usluga
     */
    private function skad(array $usluga): string
    {
        if ($usluga['hosts'] !== '') {
            return $usluga['hosts'];
        }

        return $usluga['inline']
            ? $this->trans('code running in the page itself', [], 'Modules.Quincecmp.Admin')
            : '—';
    }

    /**
     * Informacje o środowisku, do wklejenia w zgłoszeniu błędu.
     *
     * @return array<string,string>
     */
    private function system(): array
    {
        return [
            'PrestaShop' => _PS_VERSION_,
            'PHP' => PHP_VERSION,
            'MySQL' => (string) \Db::getInstance()->getValue('SELECT VERSION()'),
            'Shops' => (string) count(Shop::getShops(false, null, true)),
            'Multistore' => Shop::isFeatureActive() ? 'on' : 'off',
            'Debug mode' => _PS_MODE_DEV_ ? 'on' : 'off',
            'cURL' => function_exists('curl_init') ? 'available' : 'missing',
            'Module dir writable' => is_writable(_PS_MODULE_DIR_ . 'quince_cmp') ? 'yes' : 'no',
        ];
    }
}
