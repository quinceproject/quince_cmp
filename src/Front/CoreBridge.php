<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Front;

use Configuration;
use Context;
use Customer;
use Order;
use PrestaShop\PrestaShop\Core\Checkout\TermsAndConditions;
use Quince\Cmp\Storage\ConsentLog;
use Quince\Cmp\Storage\ConsentTypes;
use Quince\Cmp\Storage\VisitorLink;
use Quince\Cmp\Storage\ConsentVersions;
use Quince\Cmp\Storage\Schema;

/**
 * Integracja ze zgodami wbudowanymi w PrestaShopa.
 *
 * Moduł nie podmienia natywnych pól wyboru ani nie duplikuje kolumn stanu.
 * PrestaShop przechowuje stan bieżący, moduł historię zdarzeń wraz z datą,
 * adresem IP, źródłem i treścią, na którą wyrażono zgodę.
 *
 * Zakres uzupełnienia: akceptacja warunków w kasie nie jest w ogóle zapisywana
 * przez PrestaShopa, `ps_customer.newsletter` przechowuje wyłącznie stan bieżący,
 * a `ps_customer.optin` nie zawiera daty ani adresu IP.
 *
 * `PS_CONDITIONS`, `ps_dataprivacy` i `psgdpr` zostają aktywne i to one pokazują
 * checkbox klientowi. Moduł nie dokłada obok własnego dla `terms_shop`/
 * `privacy_policy`. `terms_shop` zapisuje się po skutecznej walidacji (checkbox
 * był wymagany, więc dotarcie tutaj już jest dowodem). `privacy_policy` zapisuje
 * się zawsze, bezwarunkowo: przetwarzanie danych do realizacji zamówienia albo
 * konta nie jest wyborem do odmówienia, więc nie zależy od żadnego checkboxa.
 * `ps_dataprivacy` i `psgdpr` nie zapisują nigdzie faktu zaznaczenia swojego
 * checkboxa (żadna z ich tabel tego nie trzyma), więc bez tego zapisu ten dowód
 * ginie bezpowrotnie.
 */
final class CoreBridge
{
    /**
     * Kody zgód, które mają już działający natywny odpowiednik: `terms_shop`
     * przez `PS_CONDITIONS`, `privacy_policy` przez `ps_dataprivacy`/`psgdpr`.
     *
     * Dla tych dwóch kodów moduł nigdy nie pokazuje własnego checkboxa
     * w checkoucie (patrz `ConsentTypesController::saveAction()`), żeby moduł
     * i rdzeń nie pytały naraz o to samo.
     */
    public const NATYWNE = ['terms_shop', 'privacy_policy'];

    public function __construct(
        private readonly ConsentLog $log,
        private readonly ConsentVersions $versions,
        private readonly ConsentTypes $types
    ) {
    }

    /**
     * Zgody dokumentowe zwracane do `ConditionsToApproveFinder`.
     *
     * Pozycje dołączane są do listy natywnej, nie zastępują jej.
     *
     * @return array<int,TermsAndConditions>
     */
    public function checkoutConditions(int $idShop, int $idLang): array
    {
        $out = [];

        foreach ($this->log->aktywneKodyDoCheckoutu($idShop) as $kod) {
            $tresc = $this->versions->obowiazujacaTresc($kod, $idShop, $idLang);

            // Rodzaj zgody bez opublikowanej wersji jest pomijany.
            if ($tresc === null || $tresc['short_label'] === '') {
                continue;
            }

            $rozbite = ClauseText::split($tresc['short_label'], $idLang);

            $warunek = new TermsAndConditions();
            $warunek->setIdentifier('quince_cmp_' . $kod);

            // `setText()` przyjmuje adresy jako kolejne argumenty pozycyjne.
            $warunek->setText($rozbite['text'], ...$rozbite['links']);

            $out[] = $warunek;
        }

        return $out;
    }

    /**
     * Zapisuje akceptację zgód dokumentowych powiązaną ze złożonym zamówieniem.
     */
    public function recordOrder(Order $order): void
    {
        $idShop = (int) $order->id_shop;

        $idLang = (int) $order->id_lang;
        $klient = new Customer((int) $order->id_customer);

        $this->polaczZOsoba($idShop, (string) $klient->email, (int) $order->id_customer);

        $kontekst = [
            'email' => (string) $klient->email,
            'id_customer' => (int) $order->id_customer,
            'source' => ConsentLog::SOURCE_CHECKOUT,
            'form_id' => 'order:' . (int) $order->id,
        ];

        $aktywne = $this->log->aktywneKodyDoCheckoutu($idShop);

        foreach ($aktywne as $kod) {
            $this->log->record($kod, true, $idShop, $idLang, $kontekst);
        }

        $this->zlapNatywneZgody($aktywne, $idShop, $idLang, $kontekst);
    }

    /**
     * Dopisuje do dziennika zgody, które w checkoucie pyta `PS_CONDITIONS` albo
     * `ps_dataprivacy`/`psgdpr` zamiast własnego checkboxa modułu.
     *
     * Warunek `!in_array($kod, $aktywne, true)` chroni przed podwójnym wpisem,
     * gdy własny checkbox modułu stoi obok natywnego (`show_in_checkout`
     * włączone).
     *
     * `privacy_policy` zapisuje się zawsze, bezwarunkowo: przetwarzanie danych
     * do realizacji zamówienia nie jest opcją do odmówienia, więc nie zależy
     * od żadnego checkboxa. Samo złożenie zamówienia już je oznacza.
     *
     * @param array<int,string> $aktywne kody z `aktywneKodyDoCheckoutu()`
     * @param array<string,mixed> $kontekst
     */
    private function zlapNatywneZgody(array $aktywne, int $idShop, int $idLang, array $kontekst): void
    {
        if (!in_array('terms_shop', $aktywne, true)
            && (int) Configuration::get('PS_CONDITIONS', null, null, $idShop) === 1
        ) {
            $this->log->record('terms_shop', true, $idShop, $idLang, ['form_id' => 'native:ps_conditions'] + $kontekst);
        }

        if (!in_array('privacy_policy', $aktywne, true)) {
            $this->log->record('privacy_policy', true, $idShop, $idLang, ['form_id' => 'native:dataprivacy'] + $kontekst);
        }
    }

    /**
     * Zapisuje stan zgód z karty klienta. Zdarzenia niezmieniające stanu są
     * pomijane, ponieważ hook wywoływany jest przy każdym zapisie karty.
     *
     * Nieodznaczony checkbox nie zawsze znaczy „odmowa": formularz
     * rejestracji nie jest bannerem zgód, więc puste pole przy rejestracji
     * znaczy „nie podjęto decyzji". Jedyne miejsce, gdzie odznaczenie liczy
     * się jako świadome wycofanie, to edycja karty klienta (`source` =
     * `ACCOUNT`), i tylko wtedy, gdy zgoda realnie BYŁA wcześniej udzielona:
     * ta sama osoba, ten sam rodzaj zgody, choćby jako gość pod tym
     * e-mailem (stąd `ostatnieDlaOsoby()`, nie wyłącznie po `id_customer`).
     * Odznaczenie pola, którego nikt nigdy nie zaznaczył, niczego nie wycofuje.
     */
    public function recordCustomer(Customer $klient, string $source): void
    {
        $idShop = (int) ($klient->id_shop ?: Context::getContext()->shop->id);
        $idLang = (int) Context::getContext()->language->id;
        $idCustomer = (int) $klient->id;
        $email = (string) $klient->email;

        $this->polaczZOsoba($idShop, $email, $idCustomer);

        $znane = $this->log->ostatnieDlaOsoby($idShop, $idCustomer, $email);

        foreach ($this->types->coreFieldMap($idShop) as $poleCore => $kod) {
            $zgoda = (bool) $klient->{$poleCore};

            if (!$zgoda) {
                $bylaUdzielona = (bool) ($znane[$kod]['granted'] ?? false);

                if ($source !== ConsentLog::SOURCE_ACCOUNT || !$bylaUdzielona) {
                    continue;
                }
            }

            if ($this->log->bezZmiany($kod, $zgoda, $idShop, $email, $idCustomer)) {
                continue;
            }

            $this->log->record($kod, $zgoda, $idShop, $idLang, [
                'email' => $email,
                'id_customer' => $idCustomer,
                'source' => $source,
                'form_id' => 'customer:' . $poleCore,
            ]);
        }

        // Bezwarunkowo, tak jak w `zlapNatywneZgody()`. To nie jest wybór,
        // który formularz mógłby odrzucić, więc złożenie formularza już
        // je oznacza. `bezZmiany()` i tak chroni przed wpisem przy każdej
        // kolejnej, niezwiązanej edycji karty klienta.
        if (!$this->log->bezZmiany('privacy_policy', true, $idShop, $email, $idCustomer)) {
            $this->log->record('privacy_policy', true, $idShop, $idLang, [
                'email' => $email,
                'id_customer' => $idCustomer,
                'source' => $source,
                'form_id' => 'native:dataprivacy',
            ]);
        }
    }

    /** Zapisuje zapis do newslettera dokonany bez konta; identyfikacją jest adres. */
    public function recordGuestNewsletter(string $email, int $idShop, int $idLang): void
    {
        if ($email === '') {
            return;
        }

        $this->polaczZOsoba($idShop, $email);

        $this->log->record('newsletter', true, $idShop, $idLang, [
            'email' => $email,
            'source' => ConsentLog::SOURCE_NEWSLETTER,
            'form_id' => 'emailsubscription',
        ]);
    }

    /**
     * Łączy klucz przeglądarki z osobą, gdy tylko adres staje się znany.
     *
     * Wywoływane przy każdym zdarzeniu niosącym adres: rejestracji, zapisie
     * karty klienta, zamówieniu i zapisie na newsletter. Wcześniejsze zgody
     * tego samego gościa dopiero wtedy dają się odnaleźć pod jego adresem.
     */
    private function polaczZOsoba(int $idShop, string $email, int $idCustomer = 0): void
    {
        $klucz = Visitor::key();

        if ($klucz === '' || $email === '') {
            return;
        }

        VisitorLink::stitch($idShop, $klucz, $email, $idCustomer);
    }
}
