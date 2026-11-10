<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Integration;

use Quince\Cmp\Storage\Options;

/**
 * Spis wydań Quince — jeden plik JSON dla wszystkich modułów.
 *
 * Adres jest wpisany w moduł, nie w ustawienia. Pole, w które sprzedawca wpisuje
 * dowolny adres, byłoby furtką: kto ma dostęp do panelu, wskazuje własny plik
 * i moduł instaluje stamtąd kod.
 *
 * Dlaczego plik, a nie zapytanie do GitHub API. API bez tokenu dopuszcza
 * sześćdziesiąt żądań na godzinę **z jednego adresu IP**. Na hostingu
 * współdzielonym setki sklepów wychodzą jednym adresem, więc limit rozchodzi się
 * w kilka minut i od tego momentu każdy sprzedawca na tym serwerze widzi odmowę,
 * choć jego sklep zapytał raz na dobę. Plik statyczny nie ma limitu, a przy tym
 * mieści więcej niż „istnieje tag vX": wymagania wersji, wycofanie błędnego
 * wydania, odwzorowanie nazwy modułu na repozytorium.
 *
 * Oczekiwany kształt:
 *
 * ```json
 * {
 *   "modules": {
 *     "quince_cmp": {
 *       "repository": "quinceproject/quince_cmp",
 *       "version": "1.0.0",
 *       "package":  "https://github.com/.../quince_cmp.zip",
 *       "sha256":   "e3b0c44298fc1c149afbf4c8996fb924...",
 *       "notes":    "https://github.com/.../releases/tag/v1.0.0",
 *       "requires": { "php": "8.1", "prestashop": "9.2" }
 *     }
 *   }
 * }
 * ```
 *
 * Nieznane pola pomijamy — plik obsługuje też silnik i motyw, a każdy z nich
 * czyta z niego co innego.
 */
final class Manifest
{
    /**
     * Adres spisu.
     *
     * GitHub Pages organizacji: ten sam wystawca co repozytoria, bez własnej
     * domeny i bez limitu żądań. Wymaga repozytorium o nazwie `quinceproject.github.io`,
     * do którego workflow wydania dopisuje ten plik.
     */
    public const ADRES = 'https://quinceproject.github.io/manifest.json';

    /** Jak długo odpowiedź jest aktualna. */
    private const WAZNY_GODZIN = 24;

    private const TIMEOUT = 6;

    /**
     * Wpis dla jednego modułu.
     *
     * @return array{found:bool,version:string,package:string,sha256:string,notes:string,repository:string,checked:?string,error:string}
     */
    public static function wpis(string $modul, int $idShop, bool $wymus = false): array
    {
        $pusty = [
            'found' => false,
            'version' => '',
            'package' => '',
            'sha256' => '',
            'notes' => '',
            'repository' => '',
            'checked' => null,
            'error' => '',
        ];

        if (self::ADRES === '') {
            return $pusty;
        }

        $spis = self::spis($idShop, $wymus);

        if ($spis['error'] !== '') {
            return array_merge($pusty, ['error' => $spis['error'], 'checked' => $spis['checked']]);
        }

        $wpis = $spis['modules'][$modul] ?? null;

        if (!is_array($wpis)) {
            return array_merge($pusty, ['checked' => $spis['checked']]);
        }

        return [
            'found' => true,
            'version' => ltrim((string) ($wpis['version'] ?? ''), 'vV'),
            'package' => (string) ($wpis['package'] ?? ''),
            'sha256' => strtolower(trim((string) ($wpis['sha256'] ?? ''))),
            'notes' => (string) ($wpis['notes'] ?? ''),
            'repository' => (string) ($wpis['repository'] ?? ''),
            'checked' => $spis['checked'],
            'error' => '',
        ];
    }

    /**
     * Cały spis, z pamięci albo z sieci.
     *
     * @return array{modules:array<string,mixed>,checked:?string,error:string}
     */
    private static function spis(int $idShop, bool $wymus): array
    {
        $zapisane = self::zPamieci($idShop);

        if (!$wymus && $zapisane !== null) {
            return [
                'modules' => (array) ($zapisane['modules'] ?? []),
                'checked' => (string) ($zapisane['checked'] ?? ''),
                'error' => '',
            ];
        }

        $odpowiedz = self::pobierz(self::ADRES);

        if ($odpowiedz['error'] !== '') {
            return [
                'modules' => (array) ($zapisane['modules'] ?? []),
                'checked' => isset($zapisane['checked']) ? (string) $zapisane['checked'] : null,
                'error' => $odpowiedz['error'],
            ];
        }

        $dane = json_decode($odpowiedz['body'], true);

        if (!is_array($dane) || !isset($dane['modules']) || !is_array($dane['modules'])) {
            return ['modules' => [], 'checked' => null, 'error' => 'The release index is not in the expected format.'];
        }

        $wynik = ['modules' => $dane['modules'], 'checked' => date('Y-m-d H:i:s')];
        Options::save(Options::UPDATE_CACHE, (string) json_encode($wynik), $idShop);

        return $wynik + ['error' => ''];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function zPamieci(int $idShop): ?array
    {
        $surowe = Options::text(Options::UPDATE_CACHE, '', $idShop);

        if ($surowe === '') {
            return null;
        }

        $dane = json_decode($surowe, true);

        if (!is_array($dane) || !isset($dane['checked'])) {
            return null;
        }

        return strtotime((string) $dane['checked']) > strtotime('-' . self::WAZNY_GODZIN . ' hours') ? $dane : null;
    }

    /**
     * @return array{body:string,error:string}
     */
    private static function pobierz(string $adres): array
    {
        if (!function_exists('curl_init')) {
            return ['body' => '', 'error' => 'The cURL extension is not available on this server.'];
        }

        $uchwyt = curl_init($adres);

        curl_setopt_array($uchwyt, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['User-Agent: quince_cmp'],
        ]);

        $tresc = curl_exec($uchwyt);
        $kod = (int) curl_getinfo($uchwyt, CURLINFO_RESPONSE_CODE);
        $blad = curl_error($uchwyt);
        curl_close($uchwyt);

        if ($tresc === false) {
            return ['body' => '', 'error' => $blad !== '' ? $blad : 'Could not reach the release index.'];
        }

        // Spis powstaje przy pierwszym wydaniu. Zanim to nastąpi, jego brak jest
        // stanem normalnym, a nie awarią, i tak trzeba go pokazać sprzedawcy.
        if ($kod === 404) {
            return ['body' => '', 'error' => 'not-published'];
        }

        if ($kod < 200 || $kod >= 300) {
            return ['body' => '', 'error' => 'The release index answered ' . $kod . '.'];
        }

        return ['body' => (string) $tresc, 'error' => ''];
    }
}
