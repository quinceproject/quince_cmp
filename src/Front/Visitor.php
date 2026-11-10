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

use Quince\Cmp\Scan\Scanner;
use Quince\Cmp\Storage\Options;

/**
 * Wybór odwiedzającego przechowywany po jego stronie.
 *
 * Ciasteczko niesie wyłącznie kody kategorii i znacznik czasu — nie treść zgody
 * i nie dowód. Dowód mieszka w dzienniku po stronie sklepu; to tutaj jest tylko
 * podręczną odpowiedzią na pytanie „czy pokazać baner i co wolno uruchomić",
 * którą trzeba znać przed pierwszym zapytaniem do bazy.
 *
 * Ciasteczko jest własne, nie `Context::getContext()->cookie`: tamto jest
 * ciasteczkiem sesji sklepu i samo w sobie wymaga zgody na coś więcej niż
 * działanie koszyka.
 */
final class Visitor
{
    public const COOKIE = 'quince_cmp';

    /** Klucz przeglądarki dopisywany do zdarzeń zgody osoby bez adresu. */
    public const COOKIE_KEY = 'quince_cmp_k';

    /** Wersja zapisu. Zmiana unieważnia stare ciasteczka, zamiast je odczytywać na siłę. */
    private const WERSJA = 1;

    /**
     * Odczytuje wybór z ciasteczka.
     *
     * @return array{codes:array<int,string>,at:int,version:int}|null
     */
    public static function choice(): ?array
    {
        $surowe = (string) ($_COOKIE[self::COOKIE] ?? '');

        if ($surowe === '') {
            return null;
        }

        $dane = json_decode($surowe, true);

        if (!is_array($dane) || (int) ($dane['v'] ?? 0) !== self::WERSJA) {
            return null;
        }

        $kody = [];

        foreach ((array) ($dane['c'] ?? []) as $kod) {
            if (is_string($kod) && preg_match('/^[a-z0-9_]{1,64}$/', $kod) === 1) {
                $kody[] = $kod;
            }
        }

        return [
            'codes' => $kody,
            'at' => (int) ($dane['t'] ?? 0),
            'revision' => (string) ($dane['r'] ?? ''),
            'version' => self::WERSJA,
        ];
    }

    /**
     * Czy wybór jest jeszcze ważny.
     *
     * Dwa powody wygaśnięcia. Czas: zgoda sprzed trzech lat nie jest dowodem
     * na to, że ktoś dziś zgadza się na to samo. Zmiana konfiguracji: gdy sklep
     * dołożył dostawcę albo wydał nową wersję klauzuli, dawna zgoda opisuje
     * węższy zakres niż ten, który odwiedzającego teraz spotyka.
     */
    public static function stillValid(int $idShop): bool
    {
        $wybor = self::choice();

        if ($wybor === null || $wybor['at'] <= 0) {
            return false;
        }

        $miesiace = Options::int(Options::LIFETIME_MONTHS, 12, $idShop);

        if ($wybor['at'] <= strtotime('-' . $miesiace . ' months')) {
            return false;
        }

        if (!Options::bool(Options::REASK_ON_CHANGE, true, $idShop)) {
            return true;
        }

        // Pusty odcisk to ciasteczko sprzed wprowadzenia tego mechanizmu.
        // Traktujemy je jak nieaktualne, zamiast zgadywać, na co ktoś się zgodził.
        return $wybor['revision'] !== '' && $wybor['revision'] === Revision::current($idShop);
    }

    /** Odcisk konfiguracji do zapisania razem z wyborem. */
    public static function revision(int $idShop): string
    {
        return Revision::current($idShop);
    }

    /**
     * Kody kategorii, na które odwiedzający się zgodził.
     *
     * @return array<int,string>
     */
    public static function granted(int $idShop): array
    {
        // W trybie skanowania wszystko jest chwilowo zwolnione: inaczej wstrzymane
        // usługi nigdy by się nie uruchomiły i skaner meldowałby, że sklep nie
        // zapisuje niczego. Dotyczy wyłącznie przeglądarki z ważnym tokenem
        // i nie zapisuje żadnej zgody w dzienniku.
        if ((new Scanner())->wTrybie($idShop)) {
            return self::wszystkieKategorie($idShop);
        }

        return self::stillValid($idShop) ? (self::choice()['codes'] ?? []) : [];
    }

    /**
     * Wszystkie czynne kategorie ciasteczkowe sklepu.
     *
     * @return array<int,string>
     */
    private static function wszystkieKategorie(int $idShop): array
    {
        $wiersze = \Db::getInstance()->executeS(
            'SELECT `code` FROM `' . _DB_PREFIX_ . \Quince\Cmp\Storage\Schema::TABLE_TYPE . '`
              WHERE `id_shop` = ' . $idShop . ' AND `kind` = "cookie" AND `active` = 1'
        ) ?: [];

        return array_map(static fn (array $w): string => (string) $w['code'], $wiersze);
    }

    /**
     * Klucz przeglądarki. Zakładany przy pierwszym zapisie zgody, nie wcześniej.
     *
     * Ciasteczko zakładane każdemu, kto wejdzie na stronę, samo wymagałoby zgody.
     * To zakładamy dopiero w chwili, w której odwiedzający czegoś od nas chce.
     */
    public static function key(): string
    {
        $klucz = (string) ($_COOKIE[self::COOKIE_KEY] ?? '');

        return preg_match('/^[a-f0-9]{32}$/', $klucz) === 1 ? $klucz : '';
    }

    /**
     * Czy przeglądarka prosi o nieudostępnianie danych.
     *
     * Global Privacy Control jest w kilku stanach USA sygnałem wiążącym, a nie
     * podpowiedzią. Honorujemy go bez pytania, jeśli sprzedawca tak ustawił.
     */
    public static function gpc(): bool
    {
        return ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
    }

    /**
     * Czy baner ma się w ogóle pokazać.
     *
     * Cztery powody milczenia: wyłączony, tryb testowy poza listą adresów,
     * uszanowany sygnał GPC, ważny wybór sprzed chwili.
     */
    public static function shouldAsk(int $idShop): bool
    {
        if (!Options::bool(Options::BANNER_ON, false, $idShop)) {
            return false;
        }

        if (Options::bool(Options::TEST_MODE, false, $idShop) && !self::wTrybieTestowym($idShop)) {
            return false;
        }

        if (Options::bool(Options::RESPECT_GPC, true, $idShop) && self::gpc()) {
            return false;
        }

        return !self::stillValid($idShop);
    }

    private static function wTrybieTestowym(int $idShop): bool
    {
        $lista = array_filter(array_map('trim', explode(',', Options::text(Options::TEST_IPS, '', $idShop))));

        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $lista, true);
    }
}
