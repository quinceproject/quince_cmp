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
 * Tabela ciasteczek prezentowana w drugiej warstwie banera.
 *
 * Wpis pochodzi albo od sprzedawcy (`manual`), albo ze skanera (`scan`).
 * Skaner nie modyfikuje wpisów ręcznych: kategoria i opis wprowadzone ręcznie
 * pozostają po kolejnym skanowaniu.
 */
final class Cookies
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SCAN = 'scan';

    /**
     * Kategorie pochodzą z rodzajów zgód o rodzaju `cookie` zdefiniowanych
     * w danym sklepie, a nie ze stałej listy. Sprzedawca może kategorie dodać
     * lub usunąć.
     *
     * @return array<string,string> kod => nazwa
     */
    public function categories(int $idShop, int $idLang): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`code`, tl.`name`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
             LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE_LANG . '` tl
                    ON tl.`id_cmp_type` = t.`id_cmp_type` AND tl.`id_lang` = ' . $idLang . '
             WHERE t.`id_shop` = ' . $idShop . ' AND t.`kind` = "' . Schema::KIND_COOKIE . '"
             ORDER BY t.`position` ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['code']] = (string) ($wiersz['name'] ?? '') ?: (string) $wiersz['code'];
        }

        return $out;
    }

    /**
     * @return array<int,array{
     *     id_cmp_cookie:int, name:string, provider:string, category:string,
     *     lifetime_value:int, lifetime_unit:string, source:string, position:int,
     *     active:bool, description:string
     * }>
     */
    public function all(int $idShop, int $idLang): array
    {
        $zapytanie = new DbQuery();
        $zapytanie->select('c.*, cl.description');
        $zapytanie->from(Schema::TABLE_COOKIE, 'c');
        $zapytanie->leftJoin(
            Schema::TABLE_COOKIE_LANG,
            'cl',
            'cl.id_cmp_cookie = c.id_cmp_cookie AND cl.id_lang = ' . $idLang
        );
        $zapytanie->where('c.id_shop = ' . $idShop);
        $zapytanie->orderBy('c.position ASC, c.id_cmp_cookie ASC');

        $out = [];

        foreach (Db::getInstance()->executeS($zapytanie) ?: [] as $wiersz) {
            $out[] = [
                'id_cmp_cookie' => (int) $wiersz['id_cmp_cookie'],
                'name' => (string) $wiersz['name'],
                'provider' => (string) $wiersz['provider'],
                'category' => (string) $wiersz['category'],
                'lifetime_value' => (int) $wiersz['lifetime_value'],
                'lifetime_unit' => (string) $wiersz['lifetime_unit'],
                'source' => (string) $wiersz['source'],
                'position' => (int) $wiersz['position'],
                'active' => (bool) $wiersz['active'],
                'description' => (string) ($wiersz['description'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *     id_cmp_cookie:int, name:string, provider:string, category:string,
     *     lifetime_value:int, lifetime_unit:string, source:string,
     *     descriptions:array<int,string>
     * }|null
     */
    public function find(int $idCookie, int $idShop): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '`
             WHERE `id_cmp_cookie` = ' . $idCookie . ' AND `id_shop` = ' . $idShop
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return null;
        }

        $opisy = [];

        foreach (Db::getInstance()->executeS(
            'SELECT `id_lang`, `description` FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE_LANG . '`
             WHERE `id_cmp_cookie` = ' . $idCookie
        ) ?: [] as $tlumaczenie) {
            $opisy[(int) $tlumaczenie['id_lang']] = (string) $tlumaczenie['description'];
        }

        return [
            'id_cmp_cookie' => (int) $wiersz['id_cmp_cookie'],
            'name' => (string) $wiersz['name'],
            'provider' => (string) $wiersz['provider'],
            'category' => (string) $wiersz['category'],
            'lifetime_value' => (int) $wiersz['lifetime_value'],
            'lifetime_unit' => (string) $wiersz['lifetime_unit'],
            'source' => (string) $wiersz['source'],
            'descriptions' => $opisy,
        ];
    }

    /**
     * @param array<int,string> $opisy klucz: id_lang
     */
    public function save(
        int $idCookie,
        int $idShop,
        string $name,
        string $provider,
        string $category,
        int $lifetimeValue,
        string $lifetimeUnit,
        array $opisy,
        string $source = self::SOURCE_MANUAL
    ): bool {
        $dane = [
            'name' => pSQL($name),
            'provider' => pSQL($provider),
            'category' => pSQL($category),
            'lifetime_value' => max(0, $lifetimeValue),
            'lifetime_unit' => in_array($lifetimeUnit, Schema::LIFETIME_UNITS, true) ? $lifetimeUnit : 'session',
        ];

        if ($idCookie > 0) {
            $ok = Db::getInstance()->update(
                Schema::TABLE_COOKIE,
                $dane,
                '`id_cmp_cookie` = ' . $idCookie . ' AND `id_shop` = ' . $idShop
            );
        } else {
            if ($this->nazwaZajeta($name, $idShop)) {
                return false;
            }

            $ok = Db::getInstance()->insert(Schema::TABLE_COOKIE, $dane + [
                'id_shop' => $idShop,
                'source' => pSQL($source),
                'position' => $this->nastepnaPozycja($idShop),
                'active' => 1,
                'date_add' => pSQL(date('Y-m-d H:i:s')),
            ]);

            $idCookie = (int) Db::getInstance()->Insert_ID();
        }

        if (!$ok) {
            return false;
        }

        foreach ($opisy as $idLang => $opis) {
            $idLang = (int) $idLang;

            $istnieje = (bool) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE_LANG . '`
                 WHERE `id_cmp_cookie` = ' . $idCookie . ' AND `id_lang` = ' . $idLang
            );

            $ok = $istnieje
                ? Db::getInstance()->update(
                    Schema::TABLE_COOKIE_LANG,
                    ['description' => pSQL($opis)],
                    '`id_cmp_cookie` = ' . $idCookie . ' AND `id_lang` = ' . $idLang
                )
                : Db::getInstance()->insert(Schema::TABLE_COOKIE_LANG, [
                    'id_cmp_cookie' => $idCookie,
                    'id_lang' => $idLang,
                    'description' => pSQL($opis),
                ]);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function delete(int $idCookie, int $idShop): bool
    {
        $nalezy = (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '`
             WHERE `id_cmp_cookie` = ' . $idCookie . ' AND `id_shop` = ' . $idShop
        );

        if (!$nalezy) {
            return false;
        }

        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE_LANG . '` WHERE `id_cmp_cookie` = ' . $idCookie
        );

        return (bool) Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '` WHERE `id_cmp_cookie` = ' . $idCookie
        );
    }

    public function nazwaZajeta(string $name, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '`
             WHERE `name` = "' . pSQL($name) . '" AND `id_shop` = ' . $idShop
        );
    }

    private function nastepnaPozycja(int $idShop): int
    {
        return 1 + (int) Db::getInstance()->getValue(
            'SELECT MAX(`position`) FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '` WHERE `id_shop` = ' . $idShop
        );
    }
}
