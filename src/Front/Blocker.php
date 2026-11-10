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

/**
 * Wstrzymywanie usług do czasu zgody.
 *
 * Moduł nie przechowuje kodu usług — one siedzą w szablonie, w innych modułach
 * albo w menedżerze tagów. Rozpoznajemy je po wzorcu i przepisujemy gotowy HTML
 * strony, zanim trafi do przeglądarki: `<script>` traci wykonywalny typ,
 * `<iframe>` traci adres. Odzyskują je po zgodzie, po stronie przeglądarki.
 *
 * Przepisywanie gotowej strony jest jedynym sposobem, jaki działa niezależnie
 * od tego, kto wstawił skrypt. Blokowanie z poziomu JavaScriptu jest spóźnione:
 * skrypt zdąży się wykonać, zanim cokolwiek go zauważy.
 *
 * Tagów Google nie ruszamy. Nimi steruje Consent Mode: mają się załadować
 * i same ograniczyć zakres. Zablokowanie ich odbiera Google pingi bez
 * ciasteczek i modelowanie, czyli kosztuje dane, których nikt nie chronił.
 */
final class Blocker
{
    /** Wzorzec atrybutu `type` w znaczniku skryptu. Cudzysłowy jako kody znaków. */
    private const WZOR_TYPE = '#\stype\s*=\s*([\x22\x27])(.*?)\1#i';

    /** Wzorzec atrybutu `src` w ramce. */
    private const WZOR_SRC = '#\ssrc\s*=\s*([\x22\x27])(.*?)\1#i';

    /**
     * Adresy, których nie wstrzymujemy nigdy.
     *
     * Rozpoznawane po fragmencie adresu, bo nazwa usługi w panelu bywa dowolna.
     */
    private const GOOGLE = [
        'googletagmanager.com',
        'google-analytics.com',
        'googleadservices.com',
        'googlesyndication.com',
        'gtag/js',
    ];

    /**
     * Przepisuje stronę tak, żeby niezaakceptowane usługi się nie uruchomiły.
     *
     * @param array<int,string> $przyznane kody kategorii, na które jest zgoda
     */
    public function rewrite(string $html, int $idShop, array $przyznane): string
    {
        $reguly = $this->reguly($idShop, $przyznane);

        if ($reguly === []) {
            return $html;
        }

        return $this->ramki($this->skrypty($html, $reguly), $reguly);
    }

    /**
     * Reguły dla usług, na które nie ma zgody.
     *
     * @param array<int,string> $przyznane
     *
     * @return array<int,array{pattern:string,code:string,technique:string,embedding:string,name:string}>
     */
    private function reguly(int $idShop, array $przyznane): array
    {
        if (!Options::bool(Options::BLOCKING_ON, true, $idShop)) {
            return [];
        }

        $wiersze = Db::getInstance()->executeS(
            'SELECT `name`, `pattern`, `category`, `technique`, `embedding`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '`
              WHERE `id_shop` = ' . $idShop . ' AND `active` = 1 AND `pattern` <> ""'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $kod = (string) $wiersz['category'];

            // Bez kategorii nie wiadomo, czyja zgoda tę usługę zwalnia — a usługa,
            // której nic nigdy nie zwolni, nie działałaby w sklepie w ogóle.
            if ($kod === '' || in_array($kod, $przyznane, true)) {
                continue;
            }

            $out[] = [
                'pattern' => (string) $wiersz['pattern'],
                'code' => $kod,
                'technique' => (string) $wiersz['technique'],
                'embedding' => (string) $wiersz['embedding'],
                'name' => (string) $wiersz['name'],
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,string>> $reguly
     */
    private function skrypty(string $html, array $reguly): string
    {
        if (stripos($html, '<script') === false) {
            return $html;
        }

        // Dopasowujemy cały blok razem z treścią: większość znaczników pomiarowych
        // to skrypt wpisany w stronę, bez adresu, więc same atrybuty nie wystarczą.
        return (string) preg_replace_callback(
            '#<script\b([^>]*)>(.*?)</script>#is',
            function (array $m) use ($reguly): string {
                $atrybuty = $m[1];
                $tresc = $m[2];

                if ($this->tagGoogle($atrybuty . $tresc) || stripos($atrybuty, 'data-qc-code') !== false) {
                    return $m[0];
                }

                $regula = $this->dopasuj($atrybuty . $tresc, $reguly);

                if ($regula === null) {
                    return $m[0];
                }

                // `type` bywa już ustawiony (`module`, `application/json`) —
                // zapamiętujemy go, żeby po zgodzie wrócił ten sam.
                $poprzedni = 'text/javascript';

                $atrybuty = (string) preg_replace_callback(
                    self::WZOR_TYPE,
                    static function (array $t) use (&$poprzedni): string {
                        $poprzedni = $t[2];

                        return '';
                    },
                    $atrybuty
                );

                return '<script type="text/plain"'
                    . ' data-qc-code="' . htmlspecialchars($regula['code'], ENT_QUOTES) . '"'
                    . ' data-qc-type="' . htmlspecialchars($poprzedni, ENT_QUOTES) . '"'
                    . $atrybuty . '>' . $tresc . '</script>';
            },
            $html
        );
    }

    /**
     * @param array<int,array<string,string>> $reguly
     */
    private function ramki(string $html, array $reguly): string
    {
        if (stripos($html, '<iframe') === false) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#<iframe\b([^>]*)>#i',
            function (array $m) use ($reguly): string {
                $atrybuty = $m[1];

                if (stripos($atrybuty, 'data-qc-code') !== false) {
                    return $m[0];
                }

                $regula = $this->dopasuj($atrybuty, $reguly);

                if ($regula === null) {
                    return $m[0];
                }

                // Adres wędruje do atrybutu, którego przeglądarka nie pobiera.
                // Sam `sandbox` nie wystarcza — żądanie do usługi i tak by poszło.
                $atrybuty = (string) preg_replace(self::WZOR_SRC, ' data-qc-src=$1$2$1', $atrybuty, 1);

                return '<iframe data-qc-code="' . htmlspecialchars($regula['code'], ENT_QUOTES) . '"'
                    . ' data-qc-name="' . htmlspecialchars($regula['name'], ENT_QUOTES) . '"'
                    . $atrybuty . '>';
            },
            $html
        );
    }

    /**
     * @param array<int,array<string,string>> $reguly
     *
     * @return array<string,string>|null
     */
    private function dopasuj(string $tekst, array $reguly): ?array
    {
        foreach ($reguly as $regula) {
            if ($regula['pattern'] !== '' && stripos($tekst, $regula['pattern']) !== false) {
                return $regula;
            }
        }

        return null;
    }

    private function tagGoogle(string $tekst): bool
    {
        foreach (self::GOOGLE as $fragment) {
            if (stripos($tekst, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }
}
