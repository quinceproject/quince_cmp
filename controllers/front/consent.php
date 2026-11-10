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

use Quince\Cmp\Front\Signals;
use Quince\Cmp\Front\Visitor;
use Quince\Cmp\Storage\ConsentLog;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Schema;
use Quince\Cmp\Storage\VisitorLink;

/**
 * Zapis wyboru odwiedzającego.
 *
 * Ciasteczko po stronie przeglądarki wystarcza, żeby wiedzieć, co uruchomić.
 * Nie wystarcza jako dowód: przeglądarkę czyści się jednym kliknięciem, a treść
 * ciasteczka można podmienić. Dowód powstaje tutaj — w dzienniku, ze skrótem
 * treści, która była wtedy wyświetlona.
 *
 * Zapisujemy zdarzenie dla **każdej** kategorii, także odmowy. Rejestr odmów
 * jest tak samo potrzebny jak rejestr zgód: bez niego nie da się wykazać, że
 * ktoś świadomie czegoś odmówił.
 */
class Quince_CmpConsentModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    public function postProcess(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->wyslij(405, ['error' => 'Use POST.']);
        }

        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;

        $wybrane = $this->kody((string) Tools::getValue('codes', ''));
        $wszystkie = $this->kategorie($idShop);

        if ($wszystkie === []) {
            $this->wyslij(409, ['error' => 'No cookie categories defined.']);
        }

        $klucz = $this->klucz();
        $idKlienta = (int) ($this->context->customer->id ?? 0);
        $email = $this->adres();

        if ($email !== '') {
            // Klient jest zalogowany: od tej chwili jego wcześniejsze wybory
            // z tej przeglądarki dają się odnaleźć pod adresem.
            VisitorLink::stitch($idShop, $klucz, $email, $idKlienta);
        } else {
            // Gość, który kiedyś się przedstawił, nie musi robić tego ponownie.
            $email = VisitorLink::emailFor($klucz, $idShop);
        }

        // Kategorie wymagane wchodzą do wyboru niezależnie od tego, co przyszło
        // z przeglądarki — bez nich sklep nie działa i nikt ich nie odznacza.
        $przyznane = array_values(array_unique(array_merge(
            array_keys(array_filter($wszystkie, static fn (bool $wymagana): bool => $wymagana)),
            array_values(array_intersect($wybrane, array_keys($wszystkie)))
        )));

        $dziennik = new ConsentLog();

        foreach ($wszystkie as $kod => $wymagana) {
            $zgoda = in_array($kod, $przyznane, true);

            if ($dziennik->bezZmiany($kod, $zgoda, $idShop, $email, $idKlienta)) {
                continue;
            }

            $dziennik->record($kod, $zgoda, $idShop, $idLang, [
                'email' => $email,
                'id_customer' => $idKlienta,
                'visitor_key' => $klucz,
                'source' => ConsentLog::SOURCE_BANNER,
            ]);
        }

        $this->zapiszCiasteczko($przyznane, $idShop);

        $this->wyslij(200, [
            'ok' => true,
            'codes' => $przyznane,
            'signals' => (new Signals())->forCodes($idShop, $idLang, $przyznane),
        ]);
    }

    /**
     * Kategorie ciasteczkowe sklepu: kod => czy wymagana.
     *
     * @return array<string,bool>
     */
    private function kategorie(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `code`, `required` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
              WHERE `id_shop` = ' . $idShop . ' AND `kind` = "' . Schema::KIND_COOKIE . '" AND `active` = 1'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['code']] = (bool) $wiersz['required'];
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function kody(string $surowe): array
    {
        $out = [];

        foreach (explode(',', $surowe) as $kod) {
            $kod = trim($kod);

            if (preg_match('/^[a-z0-9_]{1,64}$/', $kod) === 1) {
                $out[] = $kod;
            }
        }

        return $out;
    }

    /**
     * Klucz przeglądarki. Zakładany dopiero teraz, przy pierwszym zapisie zgody.
     *
     * Ciasteczko zakładane każdemu przy wejściu samo wymagałoby zgody, więc
     * czeka do chwili, w której odwiedzający czegoś od nas chce.
     */
    private function klucz(): string
    {
        $klucz = Visitor::key();

        if ($klucz === '') {
            $klucz = bin2hex(random_bytes(16));
            $this->ciasteczko(Visitor::COOKIE_KEY, $klucz, 24);
        }

        return $klucz;
    }

    private function adres(): string
    {
        $email = (string) ($this->context->customer->email ?? '');

        return Validate::isEmail($email) ? $email : '';
    }

    /**
     * @param array<int,string> $kody
     */
    private function zapiszCiasteczko(array $kody, int $idShop): void
    {
        $this->ciasteczko(
            Visitor::COOKIE,
            (string) json_encode([
                'v' => 1,
                'c' => $kody,
                't' => time(),
                'r' => Visitor::revision($idShop),
            ]),
            Options::int(Options::LIFETIME_MONTHS, 12, $idShop)
        );
    }

    private function ciasteczko(string $nazwa, string $wartosc, int $miesiace): void
    {
        setcookie($nazwa, $wartosc, [
            'expires' => strtotime('+' . $miesiace . ' months'),
            'path' => '/',
            'secure' => Tools::usingSecureMode(),
            'httponly' => false,   // warstwa JS musi to odczytać przy starcie strony
            'samesite' => 'Lax',
        ]);
    }

    /** @param array<string,mixed> $cialo */
    private function wyslij(int $status, array $cialo): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        echo json_encode($cialo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
