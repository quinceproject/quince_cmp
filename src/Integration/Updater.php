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

use ZipArchive;

/**
 * Podmiana plików modułu na nowsze wydanie.
 *
 * Uruchamiana wyłącznie z ręki sprzedawcy, po kliknięciu. Moduł nie aktualizuje
 * się sam — sprawdza, czy jest nowsza wersja, i czeka.
 *
 * Operacja jest z natury niebezpieczna: moduł nadpisuje pliki, z których w tej
 * właśnie chwili działa. Stąd kolejność, w której katalog na dysku nigdy nie
 * zostaje w stanie pośrednim dłużej, niż to konieczne:
 *
 * 1. pobranie, sprawdzenie skrótu, rozpakowanie do katalogu tymczasowego
 * 2. sprawdzenie, czy to w ogóle ten moduł i czy wersja jest nowsza
 * 3. kopia zapasowa działającego katalogu
 * 4. podmiana
 * 5. przy jakimkolwiek błędzie w kroku 4 — powrót z kopii
 *
 * Po podmianie żądanie nie może już dotknąć żadnej klasy modułu, bo pliki pod
 * nią się zmieniły. Kontroler robi wyłącznie przekierowanie, a skrypty z
 * `upgrade/` idą w kolejnym żądaniu.
 */
final class Updater
{
    /** Wydanie modułu PrestaShopa waży pojedyncze megabajty; sto to już nie jest nasz plik. */
    private const MAX_BAJTOW = 104857600;

    private const TIMEOUT = 120;

    private const MODUL = 'quince_cmp';

    /**
     * Czego brakuje, żeby aktualizacja mogła się udać.
     *
     * Sprawdzamy przed pokazaniem przycisku, nie po kliknięciu. Sprzedawca ma
     * wiedzieć, że jego serwer tego nie zrobi, zanim zacznie.
     *
     * @return string[]
     */
    public static function przeszkody(): array
    {
        $lista = [];

        if (!class_exists(ZipArchive::class)) {
            $lista[] = 'The zip extension is not available on this server.';
        }

        if (!function_exists('curl_init')) {
            $lista[] = 'The cURL extension is not available on this server.';
        }

        if (!is_writable(_PS_MODULE_DIR_ . self::MODUL)) {
            $lista[] = 'The module directory is not writable, so its files cannot be replaced.';
        }

        return $lista;
    }

    /**
     * @return array{ok:bool,error:string,version:string}
     */
    public function run(string $adresZip, string $oczekiwana, string $skrot = ''): array
    {
        $przeszkody = self::przeszkody();

        if ($przeszkody !== []) {
            return $this->blad(implode(' ', $przeszkody));
        }

        if ($adresZip === '') {
            return $this->blad('The release has no package attached.');
        }

        $roboczy = $this->katalogRoboczy();

        if ($roboczy === '') {
            return $this->blad('Could not create a temporary directory for the download.');
        }

        try {
            return $this->przebieg($roboczy, $adresZip, $oczekiwana, $skrot);
        } finally {
            $this->usun($roboczy);
        }
    }

    /**
     * @return array{ok:bool,error:string,version:string}
     */
    private function przebieg(string $roboczy, string $adresZip, string $oczekiwana, string $skrot): array
    {
        $paczka = $roboczy . '/release.zip';
        $blad = $this->pobierz($adresZip, $paczka);

        if ($blad !== '') {
            return $this->blad($blad);
        }

        // Sprawdzenie przed rozpakowaniem, nie po. Skrót pochodzi ze spisu wydań,
        // a paczka z serwera plików — zgodność obu znaczy, że dostaliśmy to, co
        // wydaliśmy. Bez tego całe zaufanie leży na samym TLS-ie, a przejęcie
        // wydania albo podmiana w tranzycie dają wykonanie dowolnego kodu.
        if ($skrot !== '') {
            $policzony = hash_file('sha256', $paczka);

            if (!hash_equals($skrot, (string) $policzony)) {
                return $this->blad('The downloaded package does not match the checksum published for this release.');
            }
        }

        $rozpakowane = $roboczy . '/new';
        $blad = $this->rozpakuj($paczka, $rozpakowane);

        if ($blad !== '') {
            return $this->blad($blad);
        }

        $zrodlo = $this->korzenModulu($rozpakowane);

        if ($zrodlo === '') {
            return $this->blad('The package does not contain this module.');
        }

        $wersja = $this->wersjaWKatalogu($zrodlo);

        if ($wersja === '') {
            return $this->blad('Could not read the version out of the package.');
        }

        if ($oczekiwana !== '' && version_compare($wersja, $oczekiwana, '<')) {
            return $this->blad('The package holds version ' . $wersja . ', which is older than the release that was announced.');
        }

        return $this->podmien($zrodlo, $roboczy . '/backup', $wersja);
    }

    /**
     * Kopia zapasowa, podmiana, a przy niepowodzeniu powrót.
     *
     * @return array{ok:bool,error:string,version:string}
     */
    private function podmien(string $zrodlo, string $kopia, string $wersja): array
    {
        $zywy = _PS_MODULE_DIR_ . self::MODUL;

        if (!$this->kopiuj($zywy, $kopia)) {
            return $this->blad('Could not back up the current files, so nothing was replaced.');
        }

        if (!$this->usun($zywy) || !mkdir($zywy, 0755, true)) {
            $this->przywroc($kopia, $zywy);

            return $this->blad('Could not clear the module directory. The previous files are back in place.');
        }

        if (!$this->kopiuj($zrodlo, $zywy)) {
            $this->przywroc($kopia, $zywy);

            return $this->blad('Copying the new files failed. The previous version was restored.');
        }

        return ['ok' => true, 'error' => '', 'version' => $wersja];
    }

    private function przywroc(string $kopia, string $zywy): void
    {
        $this->usun($zywy);
        $this->kopiuj($kopia, $zywy);
    }

    /**
     * Pobranie do pliku, a nie do pamięci.
     *
     * `CURLOPT_FOLLOWLOCATION`, bo adres pliku wydania na GitHubie jest
     * przekierowaniem na serwer plików.
     */
    private function pobierz(string $adres, string $doPliku): string
    {
        $uchwyt = curl_init($adres);
        $plik = fopen($doPliku, 'wb');

        if ($plik === false) {
            return 'Could not write the download to disk.';
        }

        curl_setopt_array($uchwyt, [
            CURLOPT_FILE => $plik,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . self::MODUL],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($u, $spodziewane, $pobrane): int => $pobrane > self::MAX_BAJTOW ? 1 : 0,
        ]);

        $udane = curl_exec($uchwyt);
        $kod = (int) curl_getinfo($uchwyt, CURLINFO_RESPONSE_CODE);
        $komunikat = curl_error($uchwyt);
        curl_close($uchwyt);
        fclose($plik);

        if ($udane === false) {
            return $komunikat !== '' ? 'Download failed: ' . $komunikat : 'Download failed.';
        }

        if ($kod < 200 || $kod >= 300) {
            return 'The server answered ' . $kod . ' when asked for the package.';
        }

        return filesize($doPliku) > 0 ? '' : 'The downloaded package is empty.';
    }

    /**
     * Rozpakowanie z odrzuceniem wpisów wychodzących poza katalog docelowy.
     *
     * Nazwa wpisu w archiwum jest danymi z zewnątrz. Wpis `../../config/settings.inc.php`
     * nadpisałby plik poza modułem, więc archiwum z takim wpisem odrzucamy w całości
     * zamiast pomijać pojedynczy plik — skoro paczka jest spreparowana, reszta jej
     * zawartości też nie zasługuje na zaufanie.
     */
    private function rozpakuj(string $paczka, string $dokad): string
    {
        $zip = new ZipArchive();

        if ($zip->open($paczka) !== true) {
            return 'The downloaded file is not a valid zip archive.';
        }

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nazwa = (string) $zip->getNameIndex($i);

            if ($nazwa === '' || str_starts_with($nazwa, '/') || str_contains($nazwa, '..') || preg_match('#^[a-z]:#i', $nazwa) === 1) {
                $zip->close();

                return 'The package contains a file path that points outside the module directory.';
            }
        }

        if (!mkdir($dokad, 0755, true)) {
            $zip->close();

            return 'Could not create a directory for the unpacked files.';
        }

        $ok = $zip->extractTo($dokad);
        $zip->close();

        return $ok ? '' : 'Unpacking the package failed.';
    }

    /**
     * Katalog, w którym leży główny plik modułu.
     *
     * Zip zbudowany przez nasz workflow ma go w podkatalogu o nazwie modułu,
     * a archiwum pobrane wprost z gałęzi — w katalogu z nazwą repozytorium
     * i skrótem commita. Szukamy więc na dwóch poziomach zamiast zakładać jeden.
     */
    private function korzenModulu(string $katalog): string
    {
        if (is_file($katalog . '/' . self::MODUL . '.php')) {
            return $katalog;
        }

        foreach ((array) glob($katalog . '/*', GLOB_ONLYDIR) as $podkatalog) {
            if (is_file($podkatalog . '/' . self::MODUL . '.php')) {
                return (string) $podkatalog;
            }
        }

        return '';
    }

    private function wersjaWKatalogu(string $katalog): string
    {
        $tresc = (string) file_get_contents($katalog . '/' . self::MODUL . '.php');

        if (!str_contains($tresc, 'extends Module')) {
            return '';
        }

        return preg_match('#\$this->version\s*=\s*[\x22\x27]([\w.-]+)[\x22\x27]#', $tresc, $m) === 1 ? $m[1] : '';
    }

    private function katalogRoboczy(): string
    {
        $sciezka = rtrim(sys_get_temp_dir(), '/\\') . '/' . self::MODUL . '-' . bin2hex(random_bytes(8));

        return mkdir($sciezka, 0700, true) ? $sciezka : '';
    }

    private function kopiuj(string $zrodlo, string $cel): bool
    {
        if (!is_dir($cel) && !mkdir($cel, 0755, true)) {
            return false;
        }

        foreach ((array) scandir($zrodlo) as $wpis) {
            if ($wpis === '.' || $wpis === '..') {
                continue;
            }

            $z = $zrodlo . '/' . $wpis;
            $do = $cel . '/' . $wpis;

            $udane = is_dir($z) ? $this->kopiuj($z, $do) : copy($z, $do);

            if (!$udane) {
                return false;
            }
        }

        return true;
    }

    private function usun(string $sciezka): bool
    {
        if (is_file($sciezka) || is_link($sciezka)) {
            return unlink($sciezka);
        }

        if (!is_dir($sciezka)) {
            return true;
        }

        foreach ((array) scandir($sciezka) as $wpis) {
            if ($wpis !== '.' && $wpis !== '..' && !$this->usun($sciezka . '/' . $wpis)) {
                return false;
            }
        }

        return rmdir($sciezka);
    }

    /**
     * @return array{ok:bool,error:string,version:string}
     */
    private function blad(string $komunikat): array
    {
        return ['ok' => false, 'error' => $komunikat, 'version' => ''];
    }
}
