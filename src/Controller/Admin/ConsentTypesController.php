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
use Quince\Cmp\Front\ClauseText;
use Quince\Cmp\Front\CoreBridge;
use Quince\Cmp\Integration\Engine;
use Quince\Cmp\Storage\ConsentTypes;
use Quince\Cmp\Storage\ConsentVersions;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\PdfStore;
use Quince\Cmp\Storage\Schema;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rodzaje zgód: lista, szczegół, wydawanie wersji.
 *
 * Ekran dotyczy pojedynczego sklepu, ponieważ rodzaje zgód należą do sklepu.
 * W kontekście „wszystkie sklepy" prezentowany jest sklep domyślny wraz
 * z informacją o zawężeniu.
 */
final class ConsentTypesController extends CmpController
{
    /** Zakładki karty typu zgody. Klucz trafia do adresu, wartość na ekran. */
    private const ZAKLADKI = [
        'content' => 'Content',
        'history' => 'Version history',
        'appears' => 'Where it appears',
    ];

    /**
     * Kolumny `ps_customer`, które rodzaj zgody może odwzorowywać.
     *
     * Jedyne dwie w rdzeniu PrestaShopa o znaczeniu zgody marketingowej —
     * zamiast wolnego pola na nazwę kolumny, bo błędna wartość zapisywałaby
     * się prosto do karty klienta.
     */
    private const POLA_CORE = [
        'newsletter' => 'Newsletter subscription (ps_customer.newsletter)',
        'optin' => 'Partner offers by e-mail (ps_customer.optin)',
    ];

    public function __construct(
        private readonly ConsentTypes $types,
        private readonly ConsentVersions $versions
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $idShop = Options::biezacySklep();
        $idLang = (int) \Context::getContext()->language->id;

        $search = trim((string) $request->query->get('search', ''));
        $kind = trim((string) $request->query->get('kind', ''));

        // Kategorie ciasteczek mają własną listę i własny formularz dodawania
        // w zakładce „Ciasteczka" (`CookiesController::indexAction`) — tutaj
        // zostają rodzaje bez tamtej listy: formularz i dokument.
        $typy = array_values(array_filter(
            $this->types->all($idShop, $idLang, $search, $kind),
            static fn (array $typ): bool => $typ['kind'] !== Schema::KIND_COOKIE
        ));

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/consent_types.html.twig', [
            'types' => $typy,
            'versionCounts' => $this->types->liczbaWersji($idShop),
            'kinds' => $this->rodzaje(),
            'kindFilters' => ['' => 'All'] + $this->rodzaje(),
            'search' => $search,
            'kind' => $kind,
            'shopName' => (string) (new Shop($idShop))->name,
            'shopScoped' => Shop::getContextShopID() === null,
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Consent types',
            'quinceRoute' => 'quince_cmp_types',
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function editAction(int $idType, Request $request): Response
    {
        $idShop = Options::biezacySklep();
        $typ = $this->types->find($idType, $idShop);

        if ($typ === null) {
            $this->addFlash('error', $this->trans('This consent type does not exist in this shop.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_types');
        }

        $jezyki = $this->languages();
        $idLang = (int) \Context::getContext()->language->id;
        $zakladka = (string) $request->query->get('tab', 'content');

        $wersje = $this->versions->forType($idType, $typ['code'], $idShop);
        $biezaca = $this->versions->obowiazujacaTresc($typ['code'], $idShop, $idLang);

        $podpisy = [];
        $klauzule = [];

        foreach ($jezyki as $jezyk) {
            $tresc = $this->versions->obowiazujacaTresc($typ['code'], $idShop, $jezyk['id_lang']);
            $podpisy[$jezyk['id_lang']] = $tresc['short_label'] ?? '';
            $klauzule[$jezyk['id_lang']] = $tresc['full_clause'] ?? '';
        }

        $natywny = in_array($typ['code'], CoreBridge::NATYWNE, true);

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/consent_type.html.twig', [
            'type' => $typ,
            'typeName' => $typ['names'][$idLang] ?? $typ['code'],
            'tab' => in_array($zakladka, array_keys(self::ZAKLADKI), true) ? $zakladka : 'content',
            'tabs' => self::ZAKLADKI,
            'documentUrl' => \Context::getContext()->link->getModuleLink('quince_cmp', 'document', [], true) . '?version=',
            'versions' => $wersje,
            'current' => $biezaca,
            'currentLabels' => $podpisy,
            'currentClauses' => $klauzule,
            'currentPdf' => $wersje !== [] ? ($wersje[0]['pdf'] ?: '') : '',
            'currentLabelHtml' => $biezaca !== null ? ClauseText::toHtml($biezaca['short_label'], $idLang) : '',
            'collectedCurrent' => $wersje !== [] ? $wersje[0]['collected'] : 0,
            'isNative' => $natywny,
            'appears' => $this->gdzieWystepuje($typ, $idShop),
            'kinds' => $this->rodzaje(),
            'languages' => $jezyki,
            'cmsPages' => $this->stronyCms($idShop),
            'coreFields' => self::POLA_CORE,
            'nextVersion' => $this->versions->nastepnyNumer($idType),
            'shopName' => (string) (new Shop($idShop))->name,
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Consent type',
            'quinceRoute' => 'quince_cmp_types',
        ]);
    }

    /** Zapis wskazania strony CMS. Treść klauzuli tędy nie idzie — patrz `publishAction`. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(int $idType, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_type_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType, 'tab' => 'appears']);
        }

        $idShop = Options::biezacySklep();
        $typ = $this->types->find($idType, $idShop);

        if ($typ === null) {
            return $this->redirectToRoute('quince_cmp_types');
        }

        $poleCore = (string) $request->request->get('core_field', '');

        if ($poleCore !== '' && !array_key_exists($poleCore, self::POLA_CORE)) {
            $poleCore = '';
        }

        if ($poleCore !== '') {
            $idLang = (int) \Context::getContext()->language->id;
            $wlasciciel = $this->types->coreFieldOwner($poleCore, $idShop, $idType, $idLang);

            if ($wlasciciel !== null) {
                $this->addFlash('error', $this->trans(
                    'Already mirrored by "%name%". One consent per column, or the two would overwrite each other.',
                    ['%name%' => $wlasciciel['name'] ?: ('#' . $wlasciciel['id_cmp_type'])],
                    'Modules.Quincecmp.Admin'
                ));

                return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType, 'tab' => 'appears']);
            }
        }

        // `terms_shop`/`privacy_policy` mają już działający natywny odpowiednik —
        // ten checkbox moduł trwale ustępuje mu miejsca, patrz `CoreBridge::NATYWNE`.
        $pokazWCheckoucie = in_array($typ['code'], CoreBridge::NATYWNE, true)
            ? false
            : $request->request->getBoolean('show_in_checkout');

        $zapisane = $this->types->saveMeta(
            $idType,
            $idShop,
            $typ['names'],
            $typ['required'],
            $typ['active'],
            (int) $request->request->get('id_cms', $typ['id_cms']),
            $pokazWCheckoucie,
            $poleCore
        );

        $this->addFlash(
            $zapisane ? 'success' : 'error',
            $zapisane
                ? $this->trans('Consent type saved.', [], 'Modules.Quincecmp.Admin')
                : $this->trans('Could not save the consent type.', [], 'Modules.Quincecmp.Admin')
        );

        return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType, 'tab' => 'appears']);
    }

    /**
     * Wydaje nową wersję klauzuli.
     *
     * Numer wersji może pochodzić z zewnętrznego rejestru dokumentów albo być
     * nadany automatycznie (SPEC §10.5).
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function publishAction(int $idType, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_type_publish', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType]);
        }

        $idShop = Options::biezacySklep();
        $typ = $this->types->find($idType, $idShop);

        if ($typ === null) {
            return $this->redirectToRoute('quince_cmp_types');
        }

        $numer = trim((string) $request->request->get('version', ''));

        if ($numer === '') {
            $numer = $this->versions->nastepnyNumer($idType);
        }

        if ($this->versions->numerZajety($idType, $numer)) {
            $this->addFlash('error', $this->trans('Version "%v%" already exists for this consent type.', ['%v%' => $numer], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType]);
        }

        $dokument = $typ['kind'] === Schema::KIND_DOCUMENT;

        // Dokument dołączony do wersji jest alternatywą dla strony CMS, nie
        // dodatkiem: strona zmienia się w miejscu, plik zostaje zamrożony
        // w kształcie z chwili wydania.
        $pdf = $this->przyjmijDokument($request);

        if ($pdf['error'] !== '') {
            $this->addFlash('error', $this->trans($pdf['error'], [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType]);
        }

        // Zgoda dokumentowa musi wskazywać dokument: albo stronę CMS, albo plik.
        if ($dokument && $typ['id_cms'] === 0 && $pdf['file'] === '') {
            $this->addFlash('error', $this->trans(
                'A document consent needs a document: link a CMS page or attach a PDF.', [],
                'Modules.Quincecmp.Admin'
            ));

            return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType]);
        }

        $tresci = [];
        $pusta = true;

        foreach ($this->languages() as $jezyk) {
            $id = $jezyk['id_lang'];
            $podpis = trim((string) $request->request->get('short_label_' . $id, ''));
            $klauzula = $dokument ? '' : trim((string) $request->request->get('full_clause_' . $id, ''));

            $tresci[$id] = ['short_label' => $podpis, 'full_clause' => $klauzula];

            // Przy zgodzie dokumentowej skrót liczony jest z treści strony CMS;
            // treść nie jest kopiowana do modułu.
            if ($dokument) {
                $tresci[$id]['hash_source'] = $this->tresciStronyCms($typ['id_cms'], $id);
            }

            if ($podpis !== '' || $klauzula !== '') {
                $pusta = false;
            }
        }

        // Wersja bez treści miałaby skrót pustego napisu, jednakowy dla wszystkich
        // takich wersji.
        if ($pusta) {
            $this->addFlash('error', $this->trans('Write the wording before publishing: an empty version proves nothing.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType]);
        }

        $odKiedy = trim((string) $request->request->get('date_from', ''));
        $odKiedy = $odKiedy !== '' ? $odKiedy . ' 00:00:00' : date('Y-m-d H:i:s');

        $wydane = $this->versions->publish($idType, $numer, $odKiedy, $tresci, $pdf['file']);

        $this->addFlash(
            $wydane ? 'success' : 'error',
            $wydane
                ? $this->trans('Version %v% published.', ['%v%' => $numer], 'Modules.Quincecmp.Admin')
                : $this->trans('Could not publish the version.', [], 'Modules.Quincecmp.Admin')
        );

        return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idType]);
    }

    /**
     * Formularz założenia nowego rodzaju zgody.
     *
     * Osobny, krótki ekran zamiast trybu „nowy" w pełnym edytorze karty —
     * ten zakłada istniejący rodzaj (wersje, mapowanie, dziennik) na każdym
     * kroku. `kind` w adresie zawęża i blokuje wybór rodzaju, gdy formularz
     * wywołuje konkretna lista (np. zakładka „Ciasteczka" zakłada tylko
     * kategorie ciasteczek, nie formularze).
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function newAction(Request $request): Response
    {
        $wszystkie = $this->rodzaje() + [Schema::KIND_COOKIE => 'Cookie'];
        $kindZadany = trim((string) $request->query->get('kind', ''));
        $kindZablokowany = array_key_exists($kindZadany, $wszystkie) ? $kindZadany : '';

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/consent_type_new.html.twig', [
            // Lista rozwijana pomija „Cookie" — kategorię ciasteczka zakłada
            // się wyłącznie z zablokowanym `kind` przez link z „Ciasteczek".
            'kinds' => $this->rodzaje(),
            'lockedKindLabel' => $wszystkie[$kindZablokowany] ?? '',
            'lockedKind' => $kindZablokowany,
            'languages' => $this->languages(),
            'backRoute' => $kindZablokowany === Schema::KIND_COOKIE ? 'quince_cmp_cookies' : 'quince_cmp_types',
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'New consent type',
            'quinceRoute' => 'quince_cmp_types',
        ]);
    }

    /** Zakłada nowy rodzaj zgody i odsyła na jego kartę, do wpisania treści. */
    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to add this.')]
    public function createAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_type_create', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_type_new');
        }

        $idShop = Options::biezacySklep();
        $kind = trim((string) $request->request->get('kind', ''));
        $code = trim((string) $request->request->get('code', ''));
        $required = $request->request->getBoolean('required');

        $wszystkie = $this->rodzaje() + [Schema::KIND_COOKIE => 'Cookie'];

        if (!array_key_exists($kind, $wszystkie)) {
            $kind = Schema::KIND_FORM;
        }

        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code) !== 1) {
            $this->addFlash('error', $this->trans(
                'The code must start with a lowercase letter and contain only lowercase letters, digits and underscores.',
                [],
                'Modules.Quincecmp.Admin'
            ));

            return $this->redirectToRoute('quince_cmp_type_new', ['kind' => $kind]);
        }

        $nazwy = [];

        foreach ($this->languages() as $jezyk) {
            $nazwy[$jezyk['id_lang']] = trim((string) $request->request->get('name_' . $jezyk['id_lang'], ''));
        }

        $idTypu = $this->types->create($idShop, $code, $kind, $nazwy, $required);

        if ($idTypu === 0) {
            $this->addFlash('error', $this->trans('Could not add it: this code is already used in this shop.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_type_new', ['kind' => $kind]);
        }

        $this->addFlash('success', $this->trans('Consent type added. Write its wording and publish a version below.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_type_edit', ['idType' => $idTypu]);
    }

    /**
     * Zapisuje kolejność po przeciągnięciu wierszy na liście.
     *
     * `order` przychodzi jako lista id oddzielonych przecinkiem, spisana przez
     * JS z aktualnego układu DOM tuż przed wysłaniem formularza — ten sam
     * wzorzec, co rozstawianie elementów strony głównej w `quince_engine`.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function reorderAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_types_reorder', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_types');
        }

        $idShop = Options::biezacySklep();
        $surowe = trim((string) $request->request->get('order', ''));

        $idy = $surowe === ''
            ? []
            : array_values(array_filter(array_map('intval', explode(',', $surowe))));

        $zapisane = $idy !== [] && $this->types->reorder($idy, $idShop);

        $this->addFlash(
            $zapisane ? 'success' : 'error',
            $zapisane
                ? $this->trans('Order saved.', [], 'Modules.Quincecmp.Admin')
                : $this->trans('Could not save the order.', [], 'Modules.Quincecmp.Admin')
        );

        return $this->redirectToRoute('quince_cmp_types');
    }

    /** Włączenie albo zgaszenie rodzaju zgody prosto z listy. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function toggleAction(int $idType, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_type_toggle', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_types');
        }

        $idShop = Options::biezacySklep();
        $this->types->toggleActive($idType, $idShop);

        $typ = $this->types->find($idType, $idShop);
        $wlaczony = $typ !== null && (bool) $typ['active'];

        $this->addFlash('success', $wlaczony
            ? $this->trans('Consent type switched on.', [], 'Modules.Quincecmp.Admin')
            : $this->trans('Consent type switched off. Records already collected stay in the log.', [], 'Modules.Quincecmp.Admin'));

        // Kategorie ciasteczek mają ten sam przełącznik, ale swoją listę w
        // zakładce „Ciasteczka" — „Rodzaje zgód" ich w ogóle nie pokazuje
        // (patrz indexAction), więc przełączona kategoria musi wrócić tam,
        // skąd przyszła, inaczej ląduje na liście, na której jej nie ma.
        return $this->redirectToRoute(
            $typ !== null && $typ['kind'] === Schema::KIND_COOKIE ? 'quince_cmp_cookies' : 'quince_cmp_types'
        );
    }

    /**
     * Przyjmuje dokument dołączony do wydawanej wersji.
     *
     * @return array{file:string,error:string}
     */
    private function przyjmijDokument(Request $request): array
    {
        $plik = $request->files->get('pdf');

        if ($plik === null) {
            return ['file' => '', 'error' => ''];
        }

        if (!$plik->isValid()) {
            return ['file' => '', 'error' => 'The upload did not arrive.'];
        }

        return PdfStore::store($plik->getPathname(), (string) $plik->getClientOriginalName());
    }

    /**
     * Miejsca w sklepie, w których zgoda jest zbierana.
     *
     * @param array<string,mixed> $typ
     *
     * @return array<int,array{where:string,how:string,native:bool}>
     */
    private function gdzieWystepuje(array $typ, int $idShop): array
    {
        $out = [];

        if ($typ['code'] === 'terms_shop') {
            $out[] = [
                'where' => 'Checkout: PrestaShop’s own checkbox',
                'how' => 'Shown through PS_CONDITIONS, pointing at CMS page '
                    . (int) \Configuration::get('PS_CONDITIONS_CMS_ID', null, null, $idShop)
                    . '. The module does not add its own checkbox here. The page the setting points to is the same one this consent type links to, so the wording is already shared.',
                'native' => true,
            ];
        } elseif ($typ['code'] === 'privacy_policy') {
            $out[] = [
                'where' => 'Checkout and customer account forms: informational notice',
                'how' => 'A plain notice next to the terms checkbox (no checkbox of its own: processing data to fulfil an order is not something the customer can decline).',
                'native' => false,
            ];
            $out[] = [
                'where' => 'Recorded on every order, registration and account update',
                'how' => 'Unconditional: recording does not depend on any checkbox, native or otherwise.',
                'native' => true,
            ];
        } elseif ($typ['show_in_checkout']) {
            $out[] = [
                'where' => 'Checkout',
                'how' => 'Added to the conditions to approve through the native termsAndConditions hook. The order cannot be placed without it.',
                'native' => true,
            ];
        }

        $poleCore = array_search($typ['code'], $this->types->coreFieldMap($idShop), true);

        if ($poleCore !== false) {
            $out[] = [
                'where' => 'Registration and customer account',
                'how' => 'Mirrors ps_customer.' . $poleCore . '. PrestaShop keeps the current state, we record the history.',
                'native' => true,
            ];
        }

        if ($typ['code'] === 'newsletter') {
            $out[] = [
                'where' => 'Newsletter form',
                'how' => 'Recorded for guests too, through actionNewsletterRegistrationBefore.',
                'native' => true,
            ];
        }

        if ($typ['kind'] === Schema::KIND_COOKIE) {
            $out[] = [
                'where' => 'Cookie banner',
                'how' => 'Not built yet. The banner screen is still to come.',
                'native' => false,
            ];
        }

        return $out;
    }

    /** Treść strony CMS w danym języku, wyłącznie do policzenia skrótu. */
    private function tresciStronyCms(int $idCms, int $idLang): string
    {
        $strona = new \CMS($idCms, $idLang);

        if (!$strona->id) {
            return '';
        }

        return (string) $strona->meta_title . "\n" . (string) $strona->content;
    }

    /**
     * Strony CMS dostępne w danym sklepie, do wskazania dokumentu.
     *
     * @return array<int,array{id_cms:int,title:string}>
     */
    private function stronyCms(int $idShop): array
    {
        $idLang = (int) \Context::getContext()->language->id;
        $out = [];

        foreach (\CMS::listCms($idLang, null, true) as $strona) {
            // `listCms()` nie filtruje po sklepie.
            $wSklepie = (bool) \Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cms_shop`
                 WHERE `id_cms` = ' . (int) $strona['id_cms'] . ' AND `id_shop` = ' . $idShop
            );

            if (!$wSklepie) {
                continue;
            }

            $out[] = ['id_cms' => (int) $strona['id_cms'], 'title' => (string) $strona['meta_title']];
        }

        return $out;
    }

    /**
     * Rodzaje zgód i ich etykiety.
     *
     * @return array<string,string>
     */
    private function rodzaje(): array
    {
        // Bez „Cookie": kategorie ciasteczek mają własną listę w zakładce
        // „Ciasteczka" — na tej liście by tylko prowadziły w dwie strony.
        return [
            Schema::KIND_FORM => 'Form',
            Schema::KIND_DOCUMENT => 'Document',
        ];
    }
}
