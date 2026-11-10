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
use Quince\Cmp\Identity\IdentityScope;
use Quince\Cmp\Integration\Engine;
use Quince\Cmp\Storage\ConsentLog;
use Quince\Cmp\Storage\ConsentRegister;
use Quince\Cmp\Storage\Schema;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Rejestr zgód: strumień zdarzeń, profil po adresie, eksport. */
final class ConsentLogController extends CmpController
{
    public function __construct(private readonly ConsentRegister $register)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $sklepy = array_map('intval', Shop::getContextListShopID());
        $filtry = $this->filtry($request);
        $strona = max(1, (int) $request->query->get('page', 1));
        $ile = $this->register->count($sklepy, $filtry);

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/consent_log.html.twig', [
            'events' => $this->register->stream($sklepy, $filtry, $strona),
            'total' => $ile,
            'page' => $strona,
            'pages' => max(1, (int) ceil($ile / ConsentRegister::NA_STRONE)),
            'filters' => $filtry,
            'codes' => $this->register->uzyteKody($sklepy),
            'sources' => $this->register->uzyteZrodla($sklepy),
            'shops' => $this->sklepy($sklepy),
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Consent log',
            'quinceRoute' => 'quince_cmp_log',
        ]);
    }

    /**
     * Profil po adresie.
     *
     * Zasięg to grupa sklepów, w której siedzi sklep wybrany w panelu — o ile
     * grupa dzieli klientów. Adres w takiej grupie oznacza jedną osobę, więc jej
     * zgody są jednym rekordem z kolumną na sklep. Poza grupą ten sam adres jest
     * kimś innym i tam nie zaglądamy.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function profileAction(Request $request): Response
    {
        $email = trim((string) $request->query->get('email', ''));

        if ($email === '') {
            return $this->redirectToRoute('quince_cmp_log');
        }

        // Zasięg liczony jest od sklepu zdarzenia, z którego wiersz prowadzi
        // tutaj (parametr `shop` w adresie), a nie od sklepu wybranego akurat
        // w górnym pasku panelu — dwa niezwiązane ze sobą stany. Bez tego klik
        // w wiersz z innego sklepu niż kontekst panelu pokazywał pusty profil
        // dla osoby, która ma realne zgody. Pracownik musi mieć dostęp do tego
        // sklepu — inaczej zasięg wraca do sklepu z kontekstu panelu.
        $sklepZeZdarzenia = (int) $request->query->get('shop', 0);
        $sklepyPracownika = $this->sklepyPracownika();
        $sklepOdniesienia = $sklepZeZdarzenia > 0 && in_array($sklepZeZdarzenia, $sklepyPracownika, true)
            ? $sklepZeZdarzenia
            : $this->sklepBiezacy();

        $sklepy = array_values(array_intersect(
            IdentityScope::shopsFor($sklepOdniesienia),
            $sklepyPracownika
        ));

        sort($sklepy);

        $idLang = (int) \Context::getContext()->language->id;
        $idKlienta = $this->kartaKlienta($email);
        $macierz = $this->register->matrix($email, $sklepy);

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/consent_profile.html.twig', [
            'email' => $email,
            'customerId' => $idKlienta,
            'customerLink' => $idKlienta > 0 ? $this->linkDoKarty($idKlienta) : '',
            'scopeLabel' => IdentityScope::label($sklepOdniesienia),
            'shops' => $this->sklepy($sklepy),
            'codes' => $macierz['codes'],
            'cells' => $macierz['cells'],
            'names' => $this->register->nazwy($sklepy, $idLang),
            'timeline' => $this->register->timeline($email, $sklepy),
            'visitorKeys' => $this->register->visitorKeys($email),
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Consent profile',
            'quinceRoute' => 'quince_cmp_log',
        ]);
    }

    /**
     * Odnotowanie wycofania zgody na żądanie osoby.
     *
     * Żądanie przychodzi mailem albo telefonicznie, a więc poza sklepem —
     * i musi zostawić w dzienniku taki sam ślad jak kliknięcie, bo ma taką samą
     * moc. Zdarzenie dostaje pochodzenie `staff`, identyfikator pracownika oraz
     * podstawę, na jakiej powstało; bez podstawy wpis nie przechodzi, bo za rok
     * nikt nie odtworzy, skąd się wziął.
     *
     * Zasięg wybiera obsługa. Regulamin obowiązuje w jednym sklepie, ale żądanie
     * może dotyczyć osoby, a nie witryny — i wtedy schodzi na całą grupę.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function withdrawAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_log_withdraw', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_log');
        }

        $email = trim((string) $request->request->get('email', ''));
        $kod = trim((string) $request->request->get('code', ''));
        $podstawa = trim((string) $request->request->get('note', ''));
        $calaGrupa = $request->request->getBoolean('whole_group');

        $powrot = ['email' => $email];

        if ($email === '' || $kod === '') {
            return $this->redirectToRoute('quince_cmp_log');
        }

        if ($podstawa === '') {
            $this->addFlash('error', $this->trans(
                'Say what this is based on — a ticket number or the date of the message. The entry is not saved without it.', [],
                'Modules.Quincecmp.Admin'
            ));

            return $this->redirectToRoute('quince_cmp_log_profile', $powrot);
        }

        $wGrupie = array_values(array_intersect(
            IdentityScope::shopsFor($this->sklepBiezacy()),
            $this->sklepyPracownika()
        ));

        $sklepy = $calaGrupa ? $wGrupie : [$this->sklepBiezacy()];
        $pracownik = \Context::getContext()->employee;
        $idLang = (int) \Context::getContext()->language->id;
        $dziennik = new ConsentLog();
        $zapisane = 0;

        foreach ($sklepy as $idShop) {
            $ok = $dziennik->record($kod, false, (int) $idShop, $idLang, [
                'email' => $email,
                'source' => 'staff request',
                'origin' => Schema::ORIGIN_STAFF,
                'id_employee' => $pracownik instanceof \Employee ? (int) $pracownik->id : 0,
                'note' => $podstawa,
                // Klucz przeglądarki i user agent zostają puste: to nie osoba
                // siedzi po drugiej stronie, tylko pracownik przy panelu.
                'visitor_key' => '',
                'user_agent' => '',
            ]);

            $zapisane += $ok ? 1 : 0;
        }

        $this->addFlash($zapisane > 0 ? 'success' : 'error', $zapisane > 0
            ? $this->trans(
                'Withdrawal recorded in %count% shop(s). It goes out to connected systems on the next sync run.',
                ['%count%' => $zapisane],
                'Modules.Quincecmp.Admin')
            : $this->trans('Nothing was recorded.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_log_profile', $powrot);
    }

    /**
     * Sklep, względem którego liczymy grupę.
     *
     * Przy kontekście „wszystkie sklepy" bierzemy pierwszy dostępny — profil musi
     * pokazywać jedną grupę, a nie sklejać kilka, w których ten sam adres bywa
     * różnymi osobami.
     */
    private function sklepBiezacy(): int
    {
        $wybrany = Shop::getContextShopID();

        if ($wybrany !== null && (int) $wybrany > 0) {
            return (int) $wybrany;
        }

        $lista = array_map('intval', Shop::getContextListShopID());

        return $lista !== [] ? (int) reset($lista) : (int) \Configuration::get('PS_SHOP_DEFAULT');
    }

    /**
     * Konto klienta o tym adresie, jeśli w ogóle istnieje.
     *
     * Zgody zbieramy również od osób bez konta, więc brak wyniku jest zwykłym
     * stanem, a nie błędem — profil zgód i karta klienta to dwie różne rzeczy.
     */
    private function kartaKlienta(string $email): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer`
              WHERE `email` = "' . pSQL($email) . '" AND `deleted` = 0
              ORDER BY `id_customer` ASC'
        );
    }

    /** Adres karty klienta w panelu, razem z tokenem bezpieczeństwa. */
    private function linkDoKarty(int $idCustomer): string
    {
        return (new \Link())->getAdminLink('AdminCustomers', true, [], [
            'id_customer' => $idCustomer,
            'viewcustomer' => 1,
        ]);
    }

    /**
     * Sklepy, których dane wolno pokazać zalogowanemu pracownikowi.
     *
     * Profil celowo nie ogląda się na sklep wybrany w pasku kontekstu. Kontekst
     * jest filtrem widoku, nie granicą uprawnień, a zgody jednej osoby w grupie
     * dzielącej klientów są jednym rekordem — obcięcie ich do bieżącego sklepu
     * pokazywałoby połowę stanu i sugerowało, że reszty nie ma.
     *
     * Granicą pozostają sklepy przypisane pracownikowi. Strumień zdarzeń trzyma
     * się kontekstu, bo tam zawężenie do jednego sklepu jest sensownym filtrem.
     *
     * @return array<int,int>
     */
    private function sklepyPracownika(): array
    {
        $pracownik = \Context::getContext()->employee ?? null;

        if ($pracownik instanceof \Employee && !$pracownik->isSuperAdmin()) {
            return array_map('intval', $pracownik->getAssociatedShops());
        }

        return array_map(
            static fn (array $sklep): int => (int) $sklep['id_shop'],
            Shop::getShops(false, null, false) ?: []
        );
    }

    /** Eksport wyniku bieżących filtrów do pliku CSV. */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function exportAction(Request $request): StreamedResponse
    {
        $sklepy = array_map('intval', Shop::getContextListShopID());
        $filtry = $this->filtry($request);
        $rejestr = $this->register;

        $odpowiedz = new StreamedResponse(static function () use ($rejestr, $sklepy, $filtry): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'date', 'shop', 'email', 'visitor_key', 'code', 'version',
                'text_hash', 'state', 'source', 'origin', 'ip',
            ], ';');

            foreach ($rejestr->exportRows($sklepy, $filtry) as $wiersz) {
                fputcsv($out, [
                    $wiersz['date_add'],
                    $wiersz['shop_name'] ?? '',
                    $wiersz['email'],
                    $wiersz['visitor_key'],
                    $wiersz['code'],
                    $wiersz['version'],
                    $wiersz['text_hash'],
                    $wiersz['granted'] ? 'granted' : 'withdrawn',
                    $wiersz['source'],
                    $wiersz['origin'],
                    $wiersz['ip'],
                ], ';');
            }

            fclose($out);
        });

        $odpowiedz->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $odpowiedz->headers->set(
            'Content-Disposition',
            'attachment; filename="consent-log-' . date('Y-m-d') . '.csv"'
        );

        return $odpowiedz;
    }

    /** @return array<string,string> */
    private function filtry(Request $request): array
    {
        $out = [];

        foreach (['email', 'visitor_key', 'code', 'source', 'granted', 'origin', 'shop', 'from', 'to'] as $klucz) {
            $out[$klucz] = trim((string) $request->query->get($klucz, ''));
        }

        return $out;
    }

    /**
     * @param array<int,int> $idShops
     *
     * @return array<int,array{id_shop:int,name:string}>
     */
    private function sklepy(array $idShops): array
    {
        $out = [];

        foreach ($idShops as $idShop) {
            $out[] = ['id_shop' => (int) $idShop, 'name' => (string) (new Shop((int) $idShop))->name];
        }

        return $out;
    }
}
