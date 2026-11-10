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

// Autoloader Composera, gdy moduł jedzie z `vendor/`; własny PSR-4, gdy nie.
// Zapasowy ma znaczenie w pracy nad modułem: zmiany w `src/` łapią się bez
// przepuszczania wszystkiego przez Composera.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Quince\\Cmp\\';

        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }

        $sciezka = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($sciezka)) {
            require_once $sciezka;
        }
    });
}

use Quince\Cmp\Front\CoreBridge;
use Quince\Cmp\Integration\Engine;
use Quince\Cmp\Scan\Scanner;
use Quince\Cmp\Storage\ConsentLog;
use Quince\Cmp\Storage\ConsentTypes;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\PdfStore;
use Quince\Cmp\Storage\ConsentVersions;
use Quince\Cmp\Front\BannerData;
use Quince\Cmp\Front\Blocker;
use Quince\Cmp\Front\Signals;
use Quince\Cmp\Front\Visitor;
use Quince\Cmp\Storage\DefaultConsentTypes;
use Quince\Cmp\Storage\DefaultCookies;
use Quince\Cmp\Storage\Schema;
use Quince\Cmp\Storage\VisitorLink;
use Quince\Cmp\Sync\SyncConfig;

/**
 * Quince CMP — zgody, ciasteczka i regulaminy.
 *
 * Jedno miejsce w sklepie, w którym mieszkają **wszystkie zgody**: ciasteczkowe,
 * marketingowe, regulaminy i zgody z formularzy. Razem z dowodem, co i kiedy
 * pokazano klientowi, oraz z dwukierunkową wymianą z systemem zewnętrznym.
 *
 * Moduł zakłada własną zakładkę w menu i rysuje własny panel z ośmioma ekranami.
 * Działa samodzielnie; przy obecności `quince_engine` zgłasza się do jego paska
 * nawigacyjnego i korzysta z jego tokenów kolorów, bez wpisu w `dependencies`.
 *
 * Zakres standardów: Consent Mode v2 w komplecie czterech sygnałów. TCF
 * (`window.__tcfapi`) nie jest obsługiwany — dotyczy wydawców sprzedających
 * powierzchnię reklamową, nie sklepów kupujących reklamy.
 */
class Quince_Cmp extends Module
{
    /** Zakładka-kontener w menu PrestaShopa. Sama nie prowadzi do żadnego ekranu. */
    public const TAB_PARENT = 'AdminQuinceCmp';

    /**
     * Ekrany modułu jako zakładki podrzędne. Pozycja dodawana jest razem
     * z kontrolerem, który ją obsługuje.
     *
     * @var array<int,array{class_name:string,name:string}>
     */
    private const TABS = [
        ['class_name' => 'AdminQuinceCmpOverview', 'name' => 'Overview'],
        ['class_name' => 'AdminQuinceCmpTypes', 'name' => 'Consent types'],
        ['class_name' => 'AdminQuinceCmpCookies', 'name' => 'Cookies'],
        ['class_name' => 'AdminQuinceCmpScripts', 'name' => 'Scripts & blocking'],
        ['class_name' => 'AdminQuinceCmpBanner', 'name' => 'Banner & preferences'],
        ['class_name' => 'AdminQuinceCmpLog', 'name' => 'Consent log'],
        ['class_name' => 'AdminQuinceCmpSync', 'name' => 'Sync'],
        ['class_name' => 'AdminQuinceCmpSettings', 'name' => 'Settings'],
        ['class_name' => 'AdminQuinceCmpApi', 'name' => 'API'],
        ['class_name' => 'AdminQuinceCmpSupport', 'name' => 'Support'],
    ];

    public function __construct()
    {
        $this->name = 'quince_cmp';
        $this->tab = 'administration';
        $this->version = '0.3.8';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.2.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Quince CMP', [], 'Modules.Quincecmp.Admin');
        $this->description = $this->trans(
            'One place for every consent in the shop: cookies, marketing, terms and form consents, with proof of what was shown and when.',
            [],
            'Modules.Quincecmp.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall the module? The consent log goes with it, including the proof of what each customer accepted. Export it first if you may need it.',
            [],
            'Modules.Quincecmp.Admin'
        );
    }

    public function install(): bool
    {
        return parent::install()
            && (new Schema())->installTables()
            && (new DefaultConsentTypes())->seedAllShops()
            && (new DefaultCookies())->seedAllShops()
            && $this->zalozKlucze()
            && $this->installTabs()
            && $this->registerHook('actionQuinceRegisterFeature')
            && $this->registerHook('actionObjectShopAddAfter')
            // Most do zgód wbudowanych w PrestaShopa — patrz `Front\CoreBridge`.
            && $this->registerHook('termsAndConditions')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionCustomerAccountUpdate')
            && $this->registerHook('actionNewsletterRegistrationBefore')
            // Panel: sekcja ze zgodami na karcie klienta w rdzeniu.
            && $this->registerHook('displayAdminCustomers')
            // Front: sygnaly Consent Mode i warstwa dla innych modulow.
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionOutputHTMLBefore')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionAuthentication');
    }

    /**
     * Sygnały Consent Mode w sekcji `<head>`.
     *
     * Musi wyjść przed jakimkolwiek tagiem Google, dlatego siedzi w `displayHeader`,
     * a nie w `actionFrontControllerSetMedia`. Tagi Google nie są wstrzymywane —
     * ładują się zawsze i pytają o zgodę tym kanałem.
     */
    public function hookDisplayHeader(): string
    {
        if (!Options::bool(Options::BLOCKING_ON, true, (int) $this->context->shop->id)
            && !Options::bool(Options::BANNER_ON, false, (int) $this->context->shop->id)) {
            return '';
        }

        return (new Signals())->headScript(
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
    }

    /**
     * Warstwa sklepu: zwalnianie wstrzymanych usług i wystawienie stanu.
     *
     * Konfiguracja idzie w osobnym znaczniku przed skryptem, bo warstwa musi
     * znać adres zapisu i teksty zastępnika, zanim cokolwiek wyrenderuje.
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        $idShop = (int) $this->context->shop->id;

        if (!Options::bool(Options::BLOCKING_ON, true, $idShop)
            && !Options::bool(Options::BANNER_ON, false, $idShop)) {
            return;
        }

        // Obie warstwy na dole strony, za definicją `quinceCmpConfig`, którą
        // PrestaShop wstawia w treści. W nagłówku skrypt startowałby bez adresu
        // zapisu i bez danych banera. Wcześnie musi wyjść tylko deklaracja
        // Consent Mode — a ta idzie z PHP, w `displayHeader`.
        $this->context->controller->registerJavascript(
            'quince-cmp-front',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 190]
        );

        // Baner ładuje się na końcu strony: nie ma nic do zablokowania, a jego
        // rysowanie przed treścią opóźniałoby pierwsze wyświetlenie.
        $this->context->controller->registerJavascript(
            'quince-cmp-banner',
            'modules/' . $this->name . '/views/js/banner.js',
            ['position' => 'bottom', 'priority' => 200]
        );

        $this->context->controller->registerStylesheet(
            'quince-cmp-front',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 100]
        );

        Media::addJsDef(['quinceCmpConfig' => $this->konfiguracjaFrontu($idShop)]);

        $this->wpnijSkaner($idShop);
    }

    /**
     * Zbieracz skanera — tylko dla przeglądarki, która ma ważny token.
     *
     * Token przychodzi raz w adresie i od razu ląduje w ciasteczku, żeby skan
     * przetrwał przejście na kolejną podstronę. Bez tego trzeba by doklejać
     * parametr do każdego odnośnika w sklepie.
     */
    private function wpnijSkaner(int $idShop): void
    {
        $skaner = new Scanner();

        if (!$skaner->wTrybie($idShop)) {
            return;
        }

        $zAdresu = (string) Tools::getValue(Scanner::PARAM, '');

        if ($zAdresu !== '' && $skaner->tokenWazny($zAdresu, $idShop)) {
            setcookie(Scanner::COOKIE, $zAdresu, [
                'expires' => time() + 1800,
                'path' => '/',
                'secure' => Tools::usingSecureMode(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        $this->context->controller->registerJavascript(
            'quince-cmp-scan',
            'modules/' . $this->name . '/views/js/scan.js',
            ['position' => 'bottom', 'priority' => 210]
        );

        Media::addJsDef(['quinceCmpScan' => [
            'endpoint' => $this->context->link->getModuleLink($this->name, 'scan', [], true),
            'label' => $this->trans(
                'Cookie scan in progress: all services are temporarily unblocked for this browser.',
                [],
                'Modules.Quincecmp.Admin'
            ),
        ]]);
    }

    /**
     * @return array<string,mixed>
     */
    private function konfiguracjaFrontu(int $idShop): array
    {
        $idLang = (int) $this->context->language->id;

        return [
            'endpoint' => $this->context->link->getModuleLink($this->name, 'consent', [], true),
            'signals' => (new Signals())->forCodes($idShop, $idLang, Visitor::granted($idShop)),
            'decided' => Visitor::stillValid($idShop),
            'banner' => (new BannerData())->config($idShop, $idLang),
            'placeholder' => [
                'title' => Options::lang(Options::PH_TITLE, $idLang, 'Content blocked', $idShop),
                'body' => Options::lang(Options::PH_BODY, $idLang, 'This content needs your consent before it can load.', $idShop),
                'allow' => Options::lang(Options::PH_ALLOW, $idLang, 'Allow and show', $idShop),
                'settings' => Options::lang(Options::PH_SETTINGS, $idLang, 'Preferences', $idShop),
            ],
        ];
    }

    /**
     * Wstrzymuje usługi, na które nie ma zgody.
     *
     * Działa na gotowym HTML-u, bo tylko wtedy obejmuje także skrypty wstawione
     * przez szablon i inne moduły. Zawartość przekazywana jest przez referencję.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionOutputHTMLBefore(array $params): void
    {
        if (!isset($params['html']) || !is_string($params['html'])) {
            return;
        }

        $idShop = (int) $this->context->shop->id;

        $params['html'] = (new Blocker())->rewrite(
            $params['html'],
            $idShop,
            Visitor::granted($idShop)
        );
    }

    /**
     * Zalogowanie łączy przeglądarkę z osobą.
     *
     * To najczęstszy moment, w którym gość staje się kimś znanym z imienia:
     * klika zgodę przy pierwszej wizycie, wraca po tygodniu i się loguje.
     * Bez tego jego wcześniejsze wybory zostają w dzienniku bez adresu.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionAuthentication(array $params): void
    {
        $klient = $params['customer'] ?? null;

        if (!$klient instanceof Customer || !Validate::isEmail((string) $klient->email)) {
            return;
        }

        VisitorLink::stitch(
            (int) $this->context->shop->id,
            Visitor::key(),
            (string) $klient->email,
            (int) $klient->id
        );
    }

    /**
     * Czy odwiedzający zgodził się na wskazany rodzaj zgody.
     *
     * Punkt wejścia dla innych modułów — tagów, mierników, integracji:
     *
     *     $cmp = Module::getInstanceByName('quince_cmp');
     *     if ($cmp && $cmp->allowed('cookies_analytics')) { … }
     *
     * Odpowiedź jest liczona po stronie serwera, więc działa też tam, gdzie
     * przeglądarki nie ma — w potwierdzeniu zamówienia, w zadaniu crona,
     * w wysyłce protokołu pomiarowego. Pomiar server-side, który tego nie
     * sprawdza, omija zgody i psuje atrybucję.
     */
    public function allowed(string $code, ?int $idShop = null): bool
    {
        $idShop = $idShop ?? (int) $this->context->shop->id;

        return in_array($code, Visitor::granted($idShop), true);
    }

    /**
     * Pełny stan zgód odwiedzającego.
     *
     * @return array{codes:array<int,string>,signals:array<string,string>,decided:bool}
     */
    public function consentState(?int $idShop = null, ?int $idLang = null): array
    {
        $idShop = $idShop ?? (int) $this->context->shop->id;
        $idLang = $idLang ?? (int) $this->context->language->id;
        $kody = Visitor::granted($idShop);

        return [
            'codes' => $kody,
            'signals' => (new Signals())->forCodes($idShop, $idLang, $kody),
            'decided' => Visitor::stillValid($idShop),
        ];
    }

    /**
     * Zgody dokumentowe dokładane do listy warunków w kasie.
     *
     * `ConditionsToApproveFinder` dołącza zwrócone pozycje do natywnej listy;
     * natywne pole wyboru pozostaje sterowane przez `PS_CONDITIONS`.
     *
     * @return array<int,PrestaShop\PrestaShop\Core\Checkout\TermsAndConditions>
     */
    public function hookTermsAndConditions(): array
    {
        return $this->most()->checkoutConditions(
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
    }

    /**
     * Zapisuje akceptację warunków przy złożeniu zamówienia.
     *
     * PrestaShop nie dopuszcza zamówienia bez zaznaczenia warunków i nie zapisuje
     * tego faktu — `ps_orders` nie zawiera odpowiedniej kolumny.
     *
     * @param array{order?:object} $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        $zamowienie = $params['order'] ?? null;

        if ($zamowienie instanceof Order) {
            $this->most()->recordOrder($zamowienie);
        }
    }

    /**
     * Zapisuje stan zgód z karty klienta przy zakładaniu konta.
     *
     * @param array{newCustomer?:object} $params
     */
    public function hookActionCustomerAccountAdd(array $params): void
    {
        $klient = $params['newCustomer'] ?? null;

        if ($klient instanceof Customer) {
            $this->most()->recordCustomer($klient, ConsentLog::SOURCE_REGISTRATION);
        }
    }

    /**
     * Zapisuje zmiany zgód przy edycji konta. Zdarzenia bez zmiany stanu są pomijane.
     *
     * @param array{customer?:object} $params
     */
    public function hookActionCustomerAccountUpdate(array $params): void
    {
        $klient = $params['customer'] ?? null;

        if ($klient instanceof Customer) {
            $this->most()->recordCustomer($klient, ConsentLog::SOURCE_ACCOUNT);
        }
    }

    /**
     * Newsletter zapisany bez konta.
     *
     * @param array{email?:string} $params
     */
    public function hookActionNewsletterRegistrationBefore(array $params): void
    {
        $this->most()->recordGuestNewsletter(
            (string) ($params['email'] ?? ''),
            (int) $this->context->shop->id,
            (int) $this->context->language->id
        );
    }

    /**
     * Sekcja ze zgodami tej osoby na natywnej karcie klienta w rdzeniu.
     *
     * Jedyny punkt zaczepienia, jaki rdzeń tu daje — pełna szerokość, pod
     * istniejącymi sekcjami. Widok, nic więcej: admin nie zmienia tu niczego,
     * zgodę wyraża klient.
     *
     * @param array{id_customer?:int} $params
     */
    public function hookDisplayAdminCustomers(array $params): string
    {
        $idCustomer = (int) ($params['id_customer'] ?? 0);
        $klient = new Customer($idCustomer);

        if (!$klient->id) {
            return '';
        }

        $idShop = (int) ($klient->id_shop ?: $this->context->shop->id);
        $idLang = (int) $this->context->language->id;

        $stany = (new ConsentLog())->ostatnieDlaOsoby($idShop, $idCustomer, (string) $klient->email);

        $wiersze = [];

        foreach ((new ConsentTypes())->all($idShop, $idLang) as $typ) {
            if (!$typ['active']) {
                continue;
            }

            $wiersze[] = [
                'name' => $typ['name'],
                'kind' => $typ['kind'],
                'state' => $stany[$typ['code']] ?? null,
            ];
        }

        return $this->get('twig')->render(
            '@Modules/quince_cmp/views/templates/hook/admin_customer.html.twig',
            ['rows' => $wiersze, 'typesUrl' => $this->get('router')->generate('quince_cmp_types')]
        );
    }

    /**
     * Instancja mostu do zgód wbudowanych.
     *
     * Tworzona bezpośrednio, ponieważ hooki wywoływane są z front office, gdzie
     * kontener usług Symfony nie zawsze jest dostępny.
     */
    private function most(): CoreBridge
    {
        return new CoreBridge(new ConsentLog(), new ConsentVersions(), new ConsentTypes());
    }

    /**
     * Zakłada domyślny zestaw rodzajów zgód w nowo utworzonym sklepie.
     *
     * @param array{object?:object} $params
     */
    public function hookActionObjectShopAddAfter(array $params): void
    {
        $sklep = $params['object'] ?? null;

        if (!$sklep instanceof Shop || !$sklep->id) {
            return;
        }

        (new DefaultConsentTypes())->seedForShop((int) $sklep->id);
        (new DefaultCookies())->seedForShop((int) $sklep->id);
        SyncConfig::zapewnijKlucze((int) $sklep->id);
    }

    /** Klucze wymiany dla każdej witryny instalacji. */
    private function zalozKlucze(): bool
    {
        foreach (Shop::getShops(false, null, true) as $id) {
            SyncConfig::zapewnijKlucze((int) $id);
        }

        return true;
    }

    /**
     * Zakłada zakładkę-kontener i pozycje ekranów.
     *
     * Zakładki tworzone są obiektami `Tab`, a nie przez właściwość `$this->tabs`:
     * tę obsługuje usługa `ModuleTabRegister`, uruchamiana wyłącznie w przepływie
     * menedżera modułów, przez co instalacja z linii poleceń pomijałaby menu.
     *
     * Kontener nie wymaga własnego kontrolera; każdy ekran podrzędny ma klasę
     * w `controllers/admin/`.
     */
    private function installTabs(): bool
    {
        $idRodzica = $this->idKontenera();

        if ($idRodzica === 0) {
            return false;
        }

        $jezyki = Language::getLanguages(false);

        foreach (self::TABS as $opis) {
            $istniejaca = (int) Tab::getIdFromClassName($opis['class_name']);

            // Zakładka pozostała po wcześniejszej instalacji jest aktualizowana,
            // nie duplikowana — PrestaShop dopuszcza wiele wpisów o tej samej klasie.
            $tab = $istniejaca ? new Tab($istniejaca) : new Tab();

            // Zakładka istnieje, ale **nie pokazuje się w menu**. Potrzebna jest
            // wyłącznie jako wieszak na uprawnienia: atrybuty `#[AdminSecurity]`
            // pytają o prawa do `_legacy_controller`, a te siedzą w rolach
            // wypisanych przy zakładce, nie w jej widoczności.
            //
            // Dziewięć pozycji w bocznym pasku PrestaShopa to nie jest docelowa
            // postać — decyzja właściciela projektu z 03.09.2026. Wszystkie moduły
            // Quince mają się kiedyś zejść pod jedną zakładkę, w kolejności, którą
            // ustali sam. Do tego czasu ich tam nie ma.
            $tab->active = 0;
            $tab->class_name = $opis['class_name'];
            $tab->module = $this->name;
            $tab->id_parent = $idRodzica;

            foreach ($jezyki as $jezyk) {
                $tab->name[(int) $jezyk['id_lang']] = $opis['name'];
            }

            if (!($istniejaca ? $tab->save() : $tab->add())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zwraca identyfikator zakładki-kontenera, zakładając ją w razie potrzeby
     * pod sekcją IMPROVE. Gdy sekcja nie istnieje, używany jest rodzic modułów.
     */
    private function idKontenera(): int
    {
        $istniejacy = (int) Tab::getIdFromClassName(self::TAB_PARENT);

        if ($istniejacy) {
            return $istniejacy;
        }

        $kontener = new Tab();
        // **Widoczny.** To jedyna pozycja modułu w menu sklepu; dziewięć ekranów
        // pod nią zostaje ukrytych, bo każdy ma własny boczny pasek po wejściu,
        // a menu sklepu urosłoby o dziesięć wpisów zamiast jednego.
        $kontener->active = 1;
        $kontener->route_name = 'quince_cmp_overview';
        $kontener->class_name = self::TAB_PARENT;
        $kontener->id_parent = $this->idGalezi();
        $kontener->module = '';

        if (property_exists($kontener, 'icon')) {
            $kontener->icon = 'privacy_tip';
        }

        foreach (Language::getLanguages(false) as $jezyk) {
            $kontener->name[(int) $jezyk['id_lang']] = 'Consents and cookies';
        }

        return $kontener->add() ? (int) $kontener->id : 0;
    }

    /**
     * Gałąź menu, pod którą wieszamy moduł.
     *
     * Najpierw „Quince project" silnika, żeby wszystkie nasze moduły stały razem.
     * Gdy silnika w sklepie nie ma, gałęzi nie ma też — wtedy zostaje sekcja
     * „Ulepszanie", a moduł działa dalej: CMP nie zależy od silnika.
     *
     * **Kopia z przyrostkiem `_MTR` idzie pierwsza.** Gdy do gałęzi trafia pierwsze
     * dziecko, PrestaShop kopiuje rodzica i to właśnie kopia staje się kontenerem
     * (`ModuleTabRegister::duplicateParentIfAlone`). Szukanie wyłącznie oryginału
     * podwiesza moduł pod pustą gałąź, której nikt nie widzi.
     */
    private function idGalezi(): int
    {
        foreach (['AdminQuinceProject_MTR', 'AdminQuinceProject', 'IMPROVE', 'AdminParentModulesSf'] as $klasa) {
            $id = (int) Tab::getIdFromClassName($klasa);

            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * Usuwa zakładki modułu. Kontener usuwany jest tylko wtedy, gdy nie pozostała
     * pod nim żadna pozycja.
     */
    private function uninstallTabs(): bool
    {
        foreach (self::TABS as $opis) {
            $id = (int) Tab::getIdFromClassName($opis['class_name']);

            if ($id) {
                (new Tab($id))->delete();
            }
        }

        $idRodzica = (int) Tab::getIdFromClassName(self::TAB_PARENT);

        if ($idRodzica && !Tab::getTabs((int) Configuration::get('PS_LANG_DEFAULT'), $idRodzica)) {
            (new Tab($idRodzica))->delete();
        }

        return true;
    }

    /** Odinstalowanie usuwa tabele modułu wraz z dziennikiem zgód. */
    public function uninstall(): bool
    {
        $this->uninstallTabs();

        // Kolejność ma znaczenie: nazwy plików znamy tylko z tabeli wersji,
        // więc dokumenty kasujemy zanim tabela zniknie.
        $this->usunDokumenty();

        return (new Schema())->dropTables()
            && $this->usunUstawienia()
            && parent::uninstall();
    }

    /**
     * Kasuje dokumenty dołączone do wersji klauzul.
     *
     * Zostawione pliki nie zaszkodzą, ale są danymi sklepu leżącymi bez
     * właściciela — po odinstalowaniu nic już nie wie, czemu służyły.
     */
    private function usunDokumenty(): void
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `pdf` FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` WHERE `pdf` <> ""'
        ) ?: [];

        foreach ($wiersze as $wiersz) {
            PdfStore::delete((string) $wiersz['pdf']);
        }

        $katalog = _PS_DOWNLOAD_DIR_ . 'quince_cmp';

        if (!is_dir($katalog)) {
            return;
        }

        // Katalog należy wyłącznie do nas, więc sprzątamy go w całości. Pliki
        // osierocone — po wersji skasowanej wcześniej — inaczej zostałyby na
        // dysku bez niczego, co by o nich wiedziało.
        foreach (glob($katalog . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $plik) {
            @unlink($plik);
        }

        @unlink($katalog . DIRECTORY_SEPARATOR . '.htaccess');
        @rmdir($katalog);
    }

    /**
     * Kasuje ustawienia modułu ze wszystkich sklepów.
     *
     * `Configuration::deleteByName()` zdejmuje wpis razem z wariantami dla
     * poszczególnych witryn, ale trzeba znać nazwę — dlatego pytamy bazę
     * o wszystko z naszym przedrostkiem, zamiast wypisywać klucze ręcznie
     * i zapominać o połowie przy każdej kolejnej zmianie.
     */
    private function usunUstawienia(): bool
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE "QUINCE_CMP_%"'
        ) ?: [];

        foreach ($wiersze as $wiersz) {
            Configuration::deleteByName((string) $wiersz['name']);
        }

        return true;
    }

    /** Czy silnik jest na miejscu. Sprawdzenie mieszka w `Integration\Engine`. */
    public static function engineDostepny(): bool
    {
        return Engine::dostepny();
    }

    /**
     * Zgłasza moduł do paska nawigacyjnego silnika jako jedną pozycję.
     *
     * @return array<int,array{type:string,id:string,label:string,route:string}>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            'type' => 'consents',
            'id' => 'quince_cmp',
            'label' => $this->trans('Consents & cookies', [], 'Modules.Quincecmp.Admin'),
            'route' => 'quince_cmp_overview',
        ]];
    }

    /** Przekierowuje przycisk „Konfiguruj" na ekran przeglądu modułu. */
    public function getContent(): void
    {
        Tools::redirectAdmin(
            $this->get('router')->generate('quince_cmp_overview')
        );
    }
}
