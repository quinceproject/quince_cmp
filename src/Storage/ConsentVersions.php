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

use Db;

/**
 * Wersje treści zgód.
 *
 * Każda wersja przechowuje `text_hash` (skrót treści faktycznie wyświetlonej)
 * osobno dla każdego języka, ponieważ klauzula w każdym języku stanowi odrębny
 * dowód.
 *
 * Klasa nie udostępnia metody modyfikującej treść opublikowanej wersji: zmiana
 * tekstu przy zachowanym numerze wersji unieważniłaby dowód dla zgód już
 * zebranych. Korekta wymaga wydania nowej wersji.
 */
final class ConsentVersions
{
    /**
     * Historia wersji jednego rodzaju zgody, od najnowszej.
     *
     * @return array<int,array{
     *     id_cmp_version:int, version:string, date_from:string, date_to:?string,
     *     pdf:string, in_force:bool, collected:int, hashes:array<int,array{iso:string,hash:string}>
     * }>
     */
    public function forType(int $idType, string $code, int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `id_cmp_version`, `version`, `date_from`, `date_to`, `pdf`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '`
             WHERE `id_cmp_type` = ' . $idType . '
             ORDER BY `date_from` DESC, `id_cmp_version` DESC'
        ) ?: [];

        if ($wiersze === []) {
            return [];
        }

        $skroty = $this->skroty(array_map(static fn (array $w): int => (int) $w['id_cmp_version'], $wiersze));
        $zebrane = $this->zebranePerWersja($code, $idShop);
        $obowiazujaca = $this->obowiazujacaId($idType);

        $out = [];

        foreach ($wiersze as $wiersz) {
            $id = (int) $wiersz['id_cmp_version'];

            $out[] = [
                'id_cmp_version' => $id,
                'version' => (string) $wiersz['version'],
                'date_from' => (string) $wiersz['date_from'],
                // Zerowa data z dawniejszych zapisów znaczy „nie zamknięta",
                // tak samo jak NULL. Ekran nie może pokazać roku 0000
                // jako końca obowiązywania.
                'date_to' => self::dataAlboNic($wiersz['date_to'] ?? null),
                'pdf' => (string) $wiersz['pdf'],
                'in_force' => $id === $obowiazujaca,
                'collected' => $zebrane[(string) $wiersz['version']] ?? 0,
                'hashes' => $skroty[$id] ?? [],
            ];
        }

        return $out;
    }

    /**
     * Treść wersji w jednym języku.
     *
     * @return array{short_label:string,full_clause:string,text_hash:string}|null
     */
    public function content(int $idVersion, int $idLang): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT `short_label`, `full_clause`, `text_hash`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '`
             WHERE `id_cmp_version` = ' . $idVersion . ' AND `id_lang` = ' . $idLang
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return null;
        }

        return [
            'short_label' => (string) $wiersz['short_label'],
            'full_clause' => (string) ($wiersz['full_clause'] ?? ''),
            'text_hash' => (string) $wiersz['text_hash'],
        ];
    }

    /**
     * Wydaje nową wersję i zamyka poprzednią, ustawiając jej `date_to` na datę
     * wejścia w życie nowej.
     *
     * Klucz `hash_source` pozwala policzyć skrót z treści przechowywanej poza
     * modułem; przy zgodach dokumentowych jest nią strona CMS sklepu.
     * `full_clause` pozostaje wtedy pusty.
     *
     * @param array<int,array{short_label:string,full_clause:string,hash_source?:string}> $tresci klucz: id_lang
     */
    public function publish(int $idType, string $version, string $dateFrom, array $tresci, string $pdf = ''): bool
    {
        $poprzednia = $this->ostatniaId($idType);

        // `date_to` celowo pomijamy zamiast wstawiać `null`: `Db::insert()` zamienia
        // `null` na pusty napis, a MySQL robi z niego „zerową" datę `0000-00-00`.
        // Taka wartość jest prawdziwa w PHP i szablon rysuje ją jako koniec
        // obowiązywania wersji, która obowiązuje. Pominięta kolumna bierze
        // `DEFAULT NULL` ze schematu i zachowuje się tak, jak zapisano.
        $ok = Db::getInstance()->insert(Schema::TABLE_VERSION, [
            'id_cmp_type' => $idType,
            'version' => pSQL($version),
            'date_from' => pSQL($dateFrom),
            'pdf' => pSQL($pdf),
            'date_add' => pSQL(date('Y-m-d H:i:s')),
        ]);

        if (!$ok) {
            return false;
        }

        $idNowej = (int) Db::getInstance()->Insert_ID();

        foreach ($tresci as $idLang => $tresc) {
            $ok = Db::getInstance()->insert(Schema::TABLE_VERSION_LANG, [
                'id_cmp_version' => $idNowej,
                'id_lang' => (int) $idLang,
                'short_label' => pSQL($tresc['short_label']),
                'full_clause' => pSQL($tresc['full_clause'], true),
                'text_hash' => self::hash($tresc['short_label'], $tresc['hash_source'] ?? $tresc['full_clause']),
            ]);

            if (!$ok) {
                return false;
            }
        }

        if ($poprzednia !== 0) {
            Db::getInstance()->update(
                Schema::TABLE_VERSION,
                ['date_to' => pSQL($dateFrom)],
                '`id_cmp_version` = ' . $poprzednia
            );
        }

        return true;
    }

    /**
     * SHA-256 z krótkiego podpisu i pełnej klauzuli łącznie.
     *
     * Ciągi białych znaków normalizowane są do pojedynczej spacji, aby zmiana
     * łamania wierszy nie zmieniała skrótu. Wielkość liter jest znacząca.
     */
    public static function hash(string $shortLabel, string $fullClause): string
    {
        $normalizuj = static fn (string $t): string => trim((string) preg_replace('/\s+/u', ' ', $t));

        return hash('sha256', $normalizuj($shortLabel) . "\n" . $normalizuj($fullClause));
    }

    /** Kolejny numer w schemacie `v1`, `v2`, `v3`. Numeracja podana ręcznie ma pierwszeństwo. */
    public function nastepnyNumer(int $idType): string
    {
        $ile = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` WHERE `id_cmp_type` = ' . $idType
        );

        return 'v' . ($ile + 1);
    }

    /**
     * Treść obowiązująca w chwili wywołania. Wersje o dacie przyszłej są pomijane.
     *
     * @return array{version:string,short_label:string,full_clause:string,text_hash:string}|null
     */
    public function obowiazujacaTresc(string $code, int $idShop, int $idLang): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT v.`version`, vl.`short_label`, vl.`full_clause`, vl.`text_hash`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v
             INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t ON t.`id_cmp_type` = v.`id_cmp_type`
             LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '` vl
                    ON vl.`id_cmp_version` = v.`id_cmp_version` AND vl.`id_lang` = ' . $idLang . '
             WHERE t.`code` = "' . pSQL($code) . '" AND t.`id_shop` = ' . $idShop . '
               AND t.`active` = 1 AND v.`date_from` <= NOW()
             ORDER BY v.`date_from` DESC, v.`id_cmp_version` DESC'
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return null;
        }

        return [
            'version' => (string) $wiersz['version'],
            'short_label' => (string) ($wiersz['short_label'] ?? ''),
            'full_clause' => (string) ($wiersz['full_clause'] ?? ''),
            'text_hash' => (string) ($wiersz['text_hash'] ?? ''),
        ];
    }

    /** Data albo `null`, gdy jest pusta lub „zerowa" w rozumieniu MySQL-a. */
    private static function dataAlboNic(mixed $wartosc): ?string
    {
        $wartosc = (string) ($wartosc ?? '');

        return $wartosc === '' || str_starts_with($wartosc, '0000-00-00') ? null : $wartosc;
    }

    public function numerZajety(int $idType, string $version): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '`
             WHERE `id_cmp_type` = ' . $idType . ' AND `version` = "' . pSQL($version) . '"'
        );
    }

    /** Identyfikator wersji obowiązującej w chwili wywołania. */
    private function obowiazujacaId(int $idType): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_cmp_version` FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '`
             WHERE `id_cmp_type` = ' . $idType . ' AND `date_from` <= NOW()
             ORDER BY `date_from` DESC, `id_cmp_version` DESC'
        );
    }

    /** Ostatnia wersja w ogóle, także ta z datą w przyszłości. */
    private function ostatniaId(int $idType): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_cmp_version` FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '`
             WHERE `id_cmp_type` = ' . $idType . '
             ORDER BY `date_from` DESC, `id_cmp_version` DESC'
        );
    }

    /**
     * @param array<int,int> $idVersions
     *
     * @return array<int,array<int,array{iso:string,hash:string}>>
     */
    private function skroty(array $idVersions): array
    {
        if ($idVersions === []) {
            return [];
        }

        $wiersze = Db::getInstance()->executeS(
            'SELECT vl.`id_cmp_version`, vl.`text_hash`, vl.`short_label`, vl.`full_clause`, l.`iso_code`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '` vl
             INNER JOIN `' . _DB_PREFIX_ . 'lang` l ON l.`id_lang` = vl.`id_lang`
             WHERE vl.`id_cmp_version` IN (' . implode(',', array_map('intval', $idVersions)) . ')
             ORDER BY l.`iso_code` ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            // Skrót dowodzi treści, ale administrator musi móc tę treść też
            // przeczytać, więc etykieta i klauzula idą obok hasza z tego samego
            // wiersza.
            $out[(int) $wiersz['id_cmp_version']][] = [
                'iso' => (string) $wiersz['iso_code'],
                'hash' => (string) $wiersz['text_hash'],
                'short_label' => (string) $wiersz['short_label'],
                'full_clause' => (string) ($wiersz['full_clause'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Liczba udzielonych zgód w podziale na numer wersji. Zliczanie po kolumnie
     * `version` w dzienniku, ponieważ dziennik nie odwołuje się do tabeli wersji.
     *
     * @return array<string,int>
     */
    private function zebranePerWersja(string $code, int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `version`, COUNT(*) AS `ile`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `code` = "' . pSQL($code) . '" AND `id_shop` = ' . $idShop . ' AND `granted` = 1
             GROUP BY `version`'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['version']] = (int) $wiersz['ile'];
        }

        return $out;
    }
}
