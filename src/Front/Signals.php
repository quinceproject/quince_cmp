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

use Db;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Schema;
use Quince\Cmp\Storage\Scripts;

/**
 * Sygnały Consent Mode wysyłane do tagów Google.
 *
 * Tagi Google nie są wstrzymywane — ładują się zawsze i pytają o zgodę tym
 * kanałem. Zablokowanie ich skryptu wygląda na ostrożność, a w praktyce kasuje
 * pingi bez ciasteczek i modelowanie konwersji: Google nie dostaje ani zgody,
 * ani odmowy, tylko ciszę, i traktuje to jak brak Consent Mode w ogóle.
 * Wstrzymujemy wszystko poza nimi.
 *
 * Kolejność ma znaczenie i jest nienegocjowalna: `ads_data_redaction`
 * i `url_passthrough`, potem `consent default`, dopiero potem jakikolwiek tag.
 * `consent update` idzie po decyzji odwiedzającego.
 */
final class Signals
{
    /** Stan sygnału przed decyzją, gdy żadna kategoria go nie podnosi. */
    private const DENIED = 'denied';
    private const GRANTED = 'granted';

    /**
     * Skrypt dla sekcji `<head>`, przed czymkolwiek innym.
     *
     * Zwraca pusty ciąg, gdy w sklepie nie ma czego mapować — pusta deklaracja
     * jest gorsza od żadnej, bo Google widzi wtedy odmowę wszystkiego na zawsze.
     */
    public function headScript(int $idShop, int $idLang): string
    {
        $mapowanie = $this->mapowanie($idShop, $idLang);

        if ($mapowanie === []) {
            return '';
        }

        $czekanie = Options::int(Options::CM_WAIT, 500, $idShop);
        $domyslne = $this->stanDomyslny($mapowanie);
        $domyslne['wait_for_update'] = $czekanie;

        $linie = ['window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'];

        if (Options::bool(Options::CM_REDACT, true, $idShop)) {
            $linie[] = 'gtag("set","ads_data_redaction",true);';
        }

        if (Options::bool(Options::CM_PASSTHROUGH, true, $idShop)) {
            $linie[] = 'gtag("set","url_passthrough",true);';
        }

        // Deklaracja regionalna musi poprzedzać ogólną: gtag stosuje pierwszą
        // pasującą, więc odwrotna kolejność sprawia, że region nigdy nie zadziała.
        if (Options::text(Options::CM_SCOPE, 'everywhere', $idShop) === 'regions') {
            $regiony = $this->regiony($idShop);

            if ($regiony !== []) {
                $linie[] = 'gtag("consent","default",' . $this->json($domyslne + ['region' => $regiony]) . ');';
                $linie[] = 'gtag("consent","default",' . $this->json($this->stanLuzny($mapowanie, $czekanie)) . ');';
            } else {
                $linie[] = 'gtag("consent","default",' . $this->json($domyslne) . ');';
            }
        } else {
            $linie[] = 'gtag("consent","default",' . $this->json($domyslne) . ');';
        }

        // Powracający z ważnym wyborem nie może czekać na skrypt przeglądarki —
        // aktualizacja idzie od razu, w tym samym znaczniku co deklaracja.
        $przyznane = Visitor::granted($idShop);

        if ($przyznane !== []) {
            $linie[] = 'gtag("consent","update",' . $this->json($this->stanPoWyborze($mapowanie, $przyznane)) . ');';
        }

        return '<script>' . implode('', $linie) . '</script>';
    }

    /**
     * Stan sygnałów dla wskazanych kategorii. Używane też przez warstwę JS.
     *
     * @param array<int,string> $kody
     *
     * @return array<string,string>
     */
    public function forCodes(int $idShop, int $idLang, array $kody): array
    {
        return $this->stanPoWyborze($this->mapowanie($idShop, $idLang), $kody);
    }

    /**
     * Które kategorie podnoszą który sygnał.
     *
     * @return array<string,array<int,string>> kod kategorii => sygnały
     */
    private function mapowanie(int $idShop, int $idLang): array
    {
        $kolumny = implode(', ', array_map(static fn (string $k): string => '`' . $k . '`', Scripts::SIGNALS));

        $wiersze = Db::getInstance()->executeS(
            'SELECT `code`, ' . $kolumny . '
               FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
              WHERE `id_shop` = ' . $idShop . '
                AND `kind` = "' . Schema::KIND_COOKIE . '"
                AND `active` = 1'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $sygnaly = [];

            foreach (Scripts::SIGNALS as $sygnal => $kolumna) {
                if ((bool) $wiersz[$kolumna]) {
                    $sygnaly[] = $sygnal;
                }
            }

            $out[(string) $wiersz['code']] = $sygnaly;
        }

        return $out;
    }

    /**
     * Stan przed decyzją: wszystko odmówione poza `security_storage`.
     *
     * @param array<string,array<int,string>> $mapowanie
     *
     * @return array<string,string>
     */
    private function stanDomyslny(array $mapowanie): array
    {
        $out = [];

        foreach (array_keys(Scripts::SIGNALS) as $sygnal) {
            $out[$sygnal] = $sygnal === Scripts::SIGNAL_DEFAULT_GRANTED ? self::GRANTED : self::DENIED;
        }

        return $out;
    }

    /**
     * Stan poza regionami, w których wymagana jest zgoda.
     *
     * Sygnały pozostają przyznane z góry — tam, gdzie prawo nie każe pytać,
     * odmowa nie chroni nikogo, a kosztuje pomiar.
     *
     * @param array<string,array<int,string>> $mapowanie
     *
     * @return array<string,mixed>
     */
    private function stanLuzny(array $mapowanie, int $czekanie): array
    {
        $out = [];

        foreach (array_keys(Scripts::SIGNALS) as $sygnal) {
            $out[$sygnal] = self::GRANTED;
        }

        $out['wait_for_update'] = $czekanie;

        return $out;
    }

    /**
     * Stan wynikający z wyboru odwiedzającego.
     *
     * @param array<string,array<int,string>> $mapowanie
     * @param array<int,string> $kody
     *
     * @return array<string,string>
     */
    private function stanPoWyborze(array $mapowanie, array $kody): array
    {
        $out = [];

        foreach (array_keys(Scripts::SIGNALS) as $sygnal) {
            $out[$sygnal] = $sygnal === Scripts::SIGNAL_DEFAULT_GRANTED ? self::GRANTED : self::DENIED;
        }

        foreach ($kody as $kod) {
            foreach ($mapowanie[$kod] ?? [] as $sygnal) {
                $out[$sygnal] = self::GRANTED;
            }
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function regiony(int $idShop): array
    {
        $lista = Options::text(Options::CM_REGIONS, Options::CM_REGIONS_EEA, $idShop);

        return array_values(array_filter(explode(',', $lista)));
    }

    /** @param array<string,mixed> $dane */
    private function json(array $dane): string
    {
        return (string) json_encode(
            $dane,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }
}
