<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Storage;

/**
 * Dokumenty PDF dołączone do wersji klauzuli.
 *
 * Alternatywa dla strony CMS, nie jej zamiennik. Strona CMS jest wygodniejsza
 * w utrzymaniu, ale zmienia się w miejscu, a dowód ma pokazywać treść z chwili
 * udzielenia zgody. PDF jest zamrożony: raz wgrany, nie zmieni się razem
 * z resztą sklepu.
 *
 * Pliki leżą w katalogu pobrań PrestaShopa, nie w katalogu modułu: tamten jest
 * już zabezpieczony przed odczytem wprost i przeżyje aktualizację modułu.
 * Wydaje je kontroler, więc `.htaccess` nie jest jedyną linią obrony
 * (na nginksie i tak by nie zadziałał).
 */
final class PdfStore
{
    /** Podkatalog w katalogu pobrań. */
    private const KATALOG = 'quince_cmp';

    /** Górna granica. Regulamin sklepu mieści się w kilku megabajtach. */
    private const MAX_BAJTOW = 10485760;

    /**
     * Przyjmuje wgrany plik i zwraca nazwę pod jaką go zapisano.
     *
     * @return array{file:string,error:string}
     */
    public static function store(string $sciezkaTymczasowa, string $nazwaOryginalna): array
    {
        if (!is_readable($sciezkaTymczasowa)) {
            return ['file' => '', 'error' => 'The upload did not arrive.'];
        }

        $rozmiar = (int) filesize($sciezkaTymczasowa);

        if ($rozmiar <= 0 || $rozmiar > self::MAX_BAJTOW) {
            return ['file' => '', 'error' => 'The file is empty or larger than 10 MB.'];
        }

        // Rozszerzenie i typ podany przez przeglądarkę mówią to, co chce nadawca.
        // Pierwsze bajty mówią, czym plik jest naprawdę.
        $uchwyt = fopen($sciezkaTymczasowa, 'rb');

        if ($uchwyt === false) {
            return ['file' => '', 'error' => 'The file could not be opened.'];
        }

        $poczatek = (string) fread($uchwyt, 5);
        fclose($uchwyt);

        if ($poczatek !== '%PDF-') {
            return ['file' => '', 'error' => 'This is not a PDF file.'];
        }

        if (!self::przygotujKatalog()) {
            return ['file' => '', 'error' => 'The storage directory could not be created.'];
        }

        // Nazwa nadana przez nas, nie wzięta z formularza. Przeglądarka przysyła
        // nazwę oryginalną, a ta bywa ścieżką w rodzaju `../../config/settings.inc.php`.
        $nazwa = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.pdf';

        if (!@copy($sciezkaTymczasowa, self::katalog() . $nazwa)) {
            return ['file' => '', 'error' => 'The file could not be saved.'];
        }

        return ['file' => $nazwa, 'error' => ''];
    }

    /** Pełna ścieżka pliku albo `null`, gdy go nie ma. */
    public static function path(string $nazwa): ?string
    {
        // Nazwa pochodzi z bazy, ale i tak sprawdzamy jej kształt: wpis w bazie
        // zmieniony inną drogą nie może wyprowadzić odczytu poza katalog.
        if (preg_match('/^[0-9]{8}-[a-f0-9]{16}\.pdf$/', $nazwa) !== 1) {
            return null;
        }

        $sciezka = self::katalog() . $nazwa;

        return is_readable($sciezka) ? $sciezka : null;
    }

    public static function delete(string $nazwa): bool
    {
        $sciezka = self::path($nazwa);

        return $sciezka !== null && @unlink($sciezka);
    }

    private static function katalog(): string
    {
        return _PS_DOWNLOAD_DIR_ . self::KATALOG . DIRECTORY_SEPARATOR;
    }

    private static function przygotujKatalog(): bool
    {
        $katalog = self::katalog();

        if (!is_dir($katalog) && !@mkdir($katalog, 0755, true) && !is_dir($katalog)) {
            return false;
        }

        // Katalog pobrań ma własny `.htaccess`, ale podkatalog dostaje swój:
        // konfiguracja serwera bywa przenoszona, a plik zostaje przy danych.
        $blokada = $katalog . '.htaccess';

        if (!file_exists($blokada)) {
            @file_put_contents($blokada, "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
        }

        return true;
    }
}
