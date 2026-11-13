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
 * Odcisk konfiguracji zgód.
 *
 * Zgoda dotyczy konkretnego zestawu: tych kategorii, tych ciasteczek, tej
 * treści klauzuli. Gdy sklep dokłada nowego dostawcę albo wydaje nową wersję
 * regulaminu, dawna zgoda przestaje opisywać stan faktyczny: klient zgodził
 * się na coś węższego niż to, co go teraz spotyka.
 *
 * Odcisk zapisujemy w ciasteczku razem z wyborem. Inny odcisk przy kolejnej
 * wizycie znaczy, że pytanie zmieniło treść, więc trzeba je zadać ponownie.
 *
 * Nie wchodzą tu opisy ciasteczek ani teksty banera: poprawiona literówka
 * nie zmienia zakresu zgody, a wliczona kazałaby pytać wszystkich od nowa.
 */
final class Revision
{
    /** Długość odcisku w ciasteczku. Osiem znaków wystarcza do porównania. */
    private const DLUGOSC = 8;

    /**
     * Odcisk bieżącej konfiguracji sklepu.
     *
     * Wchodzą: kody czynnych kategorii, nazwy i dostawcy ich ciasteczek oraz
     * skrót treści obowiązującej wersji każdej klauzuli. Do tego licznik
     * ręcznych unieważnień, bo nie każdą zmianę widać w danych: nowy cel
     * przetwarzania w tej samej kategorii nie zostawia śladu w tabelach.
     */
    public static function current(int $idShop): string
    {
        $czesci = [
            'r' . Options::int(Options::CONSENT_EPOCH, 0, $idShop),
        ];

        foreach (self::kategorie($idShop) as $kategoria) {
            $czesci[] = $kategoria;
        }

        foreach (self::ciasteczka($idShop) as $ciasteczko) {
            $czesci[] = $ciasteczko;
        }

        foreach (self::wersje($idShop) as $wersja) {
            $czesci[] = $wersja;
        }

        return substr(hash('sha256', implode('|', $czesci)), 0, self::DLUGOSC);
    }

    /**
     * Unieważnia wszystkie zgody w sklepie.
     *
     * Nie kasuje niczego z dziennika, tam zostaje dowód, że ktoś kiedyś się
     * zgodził. Zmienia wyłącznie odcisk, więc przy najbliższej wizycie każdy
     * dostanie pytanie jeszcze raz.
     */
    public static function bump(int $idShop): void
    {
        Options::save(Options::CONSENT_EPOCH, Options::int(Options::CONSENT_EPOCH, 0, $idShop) + 1, $idShop);
    }

    /**
     * @return array<int,string>
     */
    private static function kategorie(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `code`, `required` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
              WHERE `id_shop` = ' . $idShop . ' AND `kind` = "' . Schema::KIND_COOKIE . '" AND `active` = 1
              ORDER BY `code` ASC'
        ) ?: [];

        return array_map(
            static fn (array $w): string => 'k:' . $w['code'] . ':' . (int) $w['required'],
            $wiersze
        );
    }

    /**
     * @return array<int,string>
     */
    private static function ciasteczka(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `name`, `provider`, `category` FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '`
              WHERE `id_shop` = ' . $idShop . ' AND `active` = 1
              ORDER BY `category` ASC, `name` ASC'
        ) ?: [];

        return array_map(
            static fn (array $w): string => 'c:' . $w['category'] . ':' . $w['name'] . ':' . $w['provider'],
            $wiersze
        );
    }

    /**
     * Skróty treści obowiązujących wersji.
     *
     * Bierzemy tylko wersje już obowiązujące: wydana z datą przyszłą nie zmienia
     * jeszcze niczego, co widzi odwiedzający, więc nie ma powodu pytać wcześniej.
     *
     * @return array<int,string>
     */
    private static function wersje(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`code`, vl.`text_hash`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
               INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v ON v.`id_cmp_type` = t.`id_cmp_type`
               INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '` vl ON vl.`id_cmp_version` = v.`id_cmp_version`
              WHERE t.`id_shop` = ' . $idShop . '
                AND t.`active` = 1
                AND v.`date_from` <= NOW()
                AND (v.`date_to` IS NULL OR v.`date_to` >= NOW())
              ORDER BY t.`code` ASC, vl.`id_lang` ASC'
        ) ?: [];

        return array_map(
            static fn (array $w): string => 'v:' . $w['code'] . ':' . $w['text_hash'],
            $wiersze
        );
    }
}
