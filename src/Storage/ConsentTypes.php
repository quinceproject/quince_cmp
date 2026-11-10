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
use DbQuery;

/**
 * Rodzaje zgód: odczyt i zapis metadanych.
 *
 * Rodzaj zgody należy do pojedynczego sklepu, dlatego każda metoda przyjmuje
 * `id_shop` i zawęża zapytanie. Dane pochodne (obowiązujące wersje, liczba
 * zebranych zgód) pobierane są osobnymi zapytaniami — liczba rodzajów zgód
 * w sklepie jest niewielka.
 */
final class ConsentTypes
{
    /** Ile dni wstecz liczy kolumna „zebranych" na liście. */
    private const OKNO_DNI = 30;

    /**
     * Lista rodzajów zgód jednego sklepu, w kolejności ustawionej w panelu.
     *
     * @return array<int,array{
     *     id_cmp_type:int, code:string, kind:string, required:bool, position:int,
     *     active:bool, show_in_checkout:bool, name:string, version:?string,
     *     date_from:?string, collected:int
     * }>
     */
    public function all(int $idShop, int $idLang, string $search = '', string $kind = ''): array
    {
        $zapytanie = new DbQuery();
        $zapytanie->select('t.id_cmp_type, t.code, t.kind, t.required, t.position, t.active, t.show_in_checkout, tl.name');
        $zapytanie->from(Schema::TABLE_TYPE, 't');
        $zapytanie->leftJoin(
            Schema::TABLE_TYPE_LANG,
            'tl',
            'tl.id_cmp_type = t.id_cmp_type AND tl.id_lang = ' . $idLang
        );
        $zapytanie->where('t.id_shop = ' . $idShop);

        if ($search !== '') {
            $zapytanie->where('(t.code LIKE "%' . pSQL($search) . '%" OR tl.name LIKE "%' . pSQL($search) . '%")');
        }

        if ($kind !== '') {
            $zapytanie->where('t.kind = "' . pSQL($kind) . '"');
        }

        $zapytanie->orderBy('t.position ASC, t.id_cmp_type ASC');

        $wiersze = Db::getInstance()->executeS($zapytanie) ?: [];

        if ($wiersze === []) {
            return [];
        }

        $wersje = $this->obowiazujaceWersje($idShop);
        $zebrane = $this->zebraneOstatnio($idShop);

        $out = [];

        foreach ($wiersze as $wiersz) {
            $kod = (string) $wiersz['code'];

            $out[] = [
                'id_cmp_type' => (int) $wiersz['id_cmp_type'],
                'code' => $kod,
                'kind' => (string) $wiersz['kind'],
                'required' => (bool) $wiersz['required'],
                'position' => (int) $wiersz['position'],
                'active' => (bool) $wiersz['active'],
                'show_in_checkout' => (bool) $wiersz['show_in_checkout'],
                // Przy braku tłumaczenia nazwy używany jest kod.
                'name' => (string) ($wiersz['name'] ?? '') ?: $kod,
                'version' => $wersje[$kod]['version'] ?? null,
                'date_from' => $wersje[$kod]['date_from'] ?? null,
                'collected' => $zebrane[$kod] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Wersja obowiązująca w chwili wywołania, w podziale na kod zgody. Wersje
     * o dacie przyszłej są pomijane.
     *
     * @return array<string,array{version:string,date_from:string}>
     */
    private function obowiazujaceWersje(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`code`, v.`version`, v.`date_from`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v
             INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t ON t.`id_cmp_type` = v.`id_cmp_type`
             WHERE t.`id_shop` = ' . $idShop . ' AND v.`date_from` <= NOW()
             ORDER BY v.`date_from` ASC'
        ) ?: [];

        // Sortowanie rosnąco i nadpisywanie w pętli zastępuje `GROUP BY`, które przy
        // włączonym `ONLY_FULL_GROUP_BY` wymagałoby podzapytania.
        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['code']] = [
                'version' => (string) $wiersz['version'],
                'date_from' => (string) $wiersz['date_from'],
            ];
        }

        return $out;
    }

    /**
     * Liczba udzielonych zgód z ostatnich 30 dni, w podziale na kod. Cofnięcia
     * nie są wliczane.
     *
     * @return array<string,int>
     */
    private function zebraneOstatnio(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `code`, COUNT(*) AS `ile`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `id_shop` = ' . $idShop . '
               AND `granted` = 1
               AND `date_add` >= DATE_SUB(NOW(), INTERVAL ' . self::OKNO_DNI . ' DAY)
             GROUP BY `code`'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['code']] = (int) $wiersz['ile'];
        }

        return $out;
    }

    /**
     * Liczba wszystkich wersji, łącznie z nieobowiązującymi.
     *
     * @return array<int,int> id_cmp_type => liczba wersji
     */
    public function liczbaWersji(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT v.`id_cmp_type`, COUNT(*) AS `ile`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v
             INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t ON t.`id_cmp_type` = v.`id_cmp_type`
             WHERE t.`id_shop` = ' . $idShop . '
             GROUP BY v.`id_cmp_type`'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(int) $wiersz['id_cmp_type']] = (int) $wiersz['ile'];
        }

        return $out;
    }

    /**
     * Rodzaj zgody wraz z nazwami we wszystkich językach. Filtr po sklepie jest
     * obowiązkowy również przy znanym identyfikatorze.
     *
     * @return array{
     *     id_cmp_type:int, code:string, kind:string, required:bool, position:int,
     *     active:bool, show_in_checkout:bool, core_field:string, names:array<int,string>
     * }|null
     */
    public function find(int $idType, int $idShop): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT `id_cmp_type`, `code`, `kind`, `required`, `position`, `active`, `id_cms`,
                    `show_in_checkout`, `core_field`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
             WHERE `id_cmp_type` = ' . $idType . ' AND `id_shop` = ' . $idShop
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return null;
        }

        $nazwy = [];

        foreach (Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE_LANG . '`
             WHERE `id_cmp_type` = ' . $idType
        ) ?: [] as $tlumaczenie) {
            $nazwy[(int) $tlumaczenie['id_lang']] = (string) $tlumaczenie['name'];
        }

        return [
            'id_cmp_type' => (int) $wiersz['id_cmp_type'],
            'code' => (string) $wiersz['code'],
            'kind' => (string) $wiersz['kind'],
            'required' => (bool) $wiersz['required'],
            'position' => (int) $wiersz['position'],
            'active' => (bool) $wiersz['active'],
            'id_cms' => (int) $wiersz['id_cms'],
            'show_in_checkout' => (bool) $wiersz['show_in_checkout'],
            'core_field' => (string) $wiersz['core_field'],
            'names' => $nazwy,
        ];
    }

    /** Strona CMS spięta z danym kodem zgody w tym sklepie, albo `0`. */
    public function idCmsDla(string $code, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_cms` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
             WHERE `code` = "' . pSQL($code) . '" AND `id_shop` = ' . $idShop
        );
    }

    /**
     * Odwzorowanie kolumn `ps_customer` na kody zgód w danym sklepie.
     *
     * Zastępuje sztywną stałą `CoreBridge::MAPOWANIE` — sprzedawca ustawia
     * to teraz na karcie rodzaju zgody, w zakładce „Where it appears".
     *
     * @return array<string,string> core_field => code
     */
    public function coreFieldMap(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `code`, `core_field` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
             WHERE `id_shop` = ' . $idShop . ' AND `core_field` != ""'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['core_field']] = (string) $wiersz['code'];
        }

        return $out;
    }

    /**
     * Rodzaj zgody, który już mapuje daną kolumnę `ps_customer` w tym sklepie —
     * albo `null`, gdy kolumna jest wolna. Dwie zgody nie mogą dzielić jednej
     * kolumny: `recordCustomer()`/`pushToCore()` zakładają jedno odwzorowanie
     * na kolumnę.
     *
     * @return array{id_cmp_type:int,name:string}|null
     */
    public function coreFieldOwner(string $coreField, int $idShop, int $excludeIdType, int $idLang): ?array
    {
        if ($coreField === '') {
            return null;
        }

        $wiersz = Db::getInstance()->getRow(
            'SELECT t.`id_cmp_type`, tl.`name`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
             LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE_LANG . '` tl
                    ON tl.`id_cmp_type` = t.`id_cmp_type` AND tl.`id_lang` = ' . $idLang . '
             WHERE t.`id_shop` = ' . $idShop . '
               AND t.`core_field` = "' . pSQL($coreField) . '"
               AND t.`id_cmp_type` != ' . $excludeIdType
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return null;
        }

        return [
            'id_cmp_type' => (int) $wiersz['id_cmp_type'],
            'name' => (string) ($wiersz['name'] ?? ''),
        ];
    }

    /** Czy kod jest już zajęty w tym sklepie — do walidacji przed założeniem nowego rodzaju. */
    public function kodZajety(string $code, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
             WHERE `code` = "' . pSQL($code) . '" AND `id_shop` = ' . $idShop
        );
    }

    /**
     * Zakłada nowy rodzaj zgody — bez treści ani wersji, tak jak zasiewane
     * przy instalacji (patrz `DefaultConsentTypes`). Wersję sprzedawca
     * wydaje osobno, na karcie rodzaju, po wpisaniu treści.
     *
     * @param array<int,string> $nazwy klucz: id_lang
     *
     * @return int nowe `id_cmp_type`, albo 0 przy błędzie (np. zajęty kod)
     */
    public function create(int $idShop, string $code, string $kind, array $nazwy, bool $required): int
    {
        if ($code === '' || $this->kodZajety($code, $idShop)) {
            return 0;
        }

        $teraz = date('Y-m-d H:i:s');
        $pozycja = 1 + (int) Db::getInstance()->getValue(
            'SELECT MAX(`position`) FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` WHERE `id_shop` = ' . $idShop
        );

        $ok = Db::getInstance()->insert(Schema::TABLE_TYPE, [
            'id_shop' => $idShop,
            'code' => pSQL($code),
            'kind' => pSQL($kind),
            'required' => (int) $required,
            'position' => $pozycja,
            'active' => 1,
            'date_add' => pSQL($teraz),
            'date_upd' => pSQL($teraz),
        ]);

        if (!$ok) {
            return 0;
        }

        $idTypu = (int) Db::getInstance()->Insert_ID();

        foreach ($nazwy as $idLang => $nazwa) {
            Db::getInstance()->insert(Schema::TABLE_TYPE_LANG, [
                'id_cmp_type' => $idTypu,
                'id_lang' => (int) $idLang,
                'name' => pSQL($nazwa),
            ]);
        }

        return $idTypu;
    }

    /**
     * Zapisuje nazwy i przełączniki rodzaju zgody.
     *
     * `code` i `kind` nie podlegają zmianie: kod występuje w każdym zdarzeniu
     * dziennika, a rodzaj determinuje sposób zbierania zgody na froncie.
     *
     * @param array<int,string> $nazwy klucz: id_lang
     */
    public function saveMeta(
        int $idType,
        int $idShop,
        array $nazwy,
        bool $required,
        bool $active,
        int $idCms = 0,
        bool $showInCheckout = false,
        string $coreField = ''
    ): bool {
        $ok = Db::getInstance()->update(
            Schema::TABLE_TYPE,
            [
                'required' => (int) $required,
                'active' => (int) $active,
                'id_cms' => $idCms,
                'show_in_checkout' => (int) $showInCheckout,
                'core_field' => pSQL($coreField),
                'date_upd' => pSQL(date('Y-m-d H:i:s')),
            ],
            '`id_cmp_type` = ' . $idType . ' AND `id_shop` = ' . $idShop
        );

        if (!$ok) {
            return false;
        }

        foreach ($nazwy as $idLang => $nazwa) {
            $idLang = (int) $idLang;

            $istnieje = (bool) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE_LANG . '`
                 WHERE `id_cmp_type` = ' . $idType . ' AND `id_lang` = ' . $idLang
            );

            $ok = $istnieje
                ? Db::getInstance()->update(
                    Schema::TABLE_TYPE_LANG,
                    ['name' => pSQL($nazwa)],
                    '`id_cmp_type` = ' . $idType . ' AND `id_lang` = ' . $idLang
                )
                : Db::getInstance()->insert(Schema::TABLE_TYPE_LANG, [
                    'id_cmp_type' => $idType,
                    'id_lang' => $idLang,
                    'name' => pSQL($nazwa),
                ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zapisuje nową kolejność rodzajów zgód z przeciągnięcia na liście.
     *
     * `$orderedIds` to id w kolejności, w jakiej stoją po przeciągnięciu. Lista
     * schodzi z ekranu, więc przy filtrze zakładki (Ciasteczko/Formularz/
     * Dokument) obejmuje tylko widoczny podzbiór — stąd nowe pozycje to nie
     * 0..N od zera, tylko te same wartości `position`, które ten podzbiór już
     * zajmował, przełożone na nową kolejność. Rodzaje spoza listy (spoza
     * filtra albo spoza sklepu) zostają przy swoich pozycjach bez zmian.
     *
     * @param array<int,int> $orderedIds
     */
    public function reorder(array $orderedIds, int $idShop): bool
    {
        $orderedIds = array_values(array_map('intval', $orderedIds));

        if ($orderedIds === []) {
            return true;
        }

        $db = Db::getInstance();

        $wiersze = $db->executeS(
            'SELECT `id_cmp_type`, `position` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
             WHERE `id_shop` = ' . $idShop . ' AND `id_cmp_type` IN (' . implode(',', $orderedIds) . ')'
        ) ?: [];

        $wSklepie = [];
        $pozycje = [];

        foreach ($wiersze as $wiersz) {
            $wSklepie[(int) $wiersz['id_cmp_type']] = true;
            $pozycje[] = (int) $wiersz['position'];
        }

        sort($pozycje);

        $i = 0;

        foreach ($orderedIds as $idType) {
            // Id spoza tego sklepu jest pomijane, żeby jeden sprzedawca nie mógł
            // przestawić kolejności u innego, wysyłając spreparowany formularz.
            if (!isset($wSklepie[$idType])) {
                continue;
            }

            $ok = $db->update(
                Schema::TABLE_TYPE,
                ['position' => $pozycje[$i]],
                '`id_cmp_type` = ' . $idType . ' AND `id_shop` = ' . $idShop
            );

            ++$i;

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** Przełącza aktywność rodzaju zgody. Zgody wymagane pozostają bez zmian. */
    public function toggleActive(int $idType, int $idShop): bool
    {
        return (bool) Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
             SET `active` = 1 - `active`, `date_upd` = "' . pSQL(date('Y-m-d H:i:s')) . '"
             WHERE `id_cmp_type` = ' . $idType . ' AND `id_shop` = ' . $idShop . ' AND `required` = 0'
        );
    }
}
