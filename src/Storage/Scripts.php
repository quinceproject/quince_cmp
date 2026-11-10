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
 * Usługi wstrzymywane do czasu zgody oraz mapowanie kategorii na sygnały
 * Consent Mode.
 *
 * Mapowanie sygnałów przechowywane jest przy rodzaju zgody, nie przy usłudze:
 * sygnał ustawia kategoria, a usługi tylko do niej należą.
 */
final class Scripts
{
    /** Sygnał Consent Mode => kolumna w tabeli rodzajów zgód. */
    public const SIGNALS = [
        'ad_storage' => 'cm_ad_storage',
        'analytics_storage' => 'cm_analytics_storage',
        'ad_user_data' => 'cm_ad_user_data',
        'ad_personalization' => 'cm_ad_personalization',
        'functionality_storage' => 'cm_functionality_storage',
        'personalization_storage' => 'cm_personalization_storage',
        'security_storage' => 'cm_security_storage',
    ];

    /**
     * Sygnały wymagane przez Consent Mode v2.
     *
     * Brak pokrycia któregoś z nich Google traktuje jak brak zgody w ogóle.
     * Pozostałe trzy są opcjonalne: pusta kolumna nie unieważnia konfiguracji,
     * więc ostrzeżenie ich nie dotyczy.
     */
    public const SIGNALS_REQUIRED = [
        'ad_storage',
        'analytics_storage',
        'ad_user_data',
        'ad_personalization',
    ];

    /**
     * Sygnał, który zwykle wolno ustawić na `granted` już przed pytaniem.
     *
     * `security_storage` obsługuje wykrywanie nadużyć i uwierzytelnianie —
     * odmowa przed zgodą psuje mechanizmy bezpieczeństwa, a nie chroni prywatności.
     */
    public const SIGNAL_DEFAULT_GRANTED = 'security_storage';

    /** @return array<string,string> */
    public static function embeddings(): array
    {
        return [
            Schema::EMBED_INLINE => 'Inline script',
            Schema::EMBED_EXTERNAL => 'External src',
            Schema::EMBED_IFRAME => 'Iframe',
            Schema::EMBED_GTM => 'GTM tag',
        ];
    }

    /** @return array<string,string> */
    public static function techniques(): array
    {
        return [
            Schema::BLOCK_TYPE_SWAP => 'Swap type to text/plain',
            Schema::BLOCK_PLACEHOLDER => 'Replace with placeholder',
            Schema::BLOCK_DEFERRED => 'Defer the call',
        ];
    }

    /**
     * @return array<int,array{
     *     id_cmp_script:int, name:string, embedding:string, technique:string,
     *     category:string, pattern:string, position:int, active:bool
     * }>
     */
    public function all(int $idShop): array
    {
        $out = [];

        foreach (Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '`
             WHERE `id_shop` = ' . $idShop . '
             ORDER BY `position` ASC, `id_cmp_script` ASC'
        ) ?: [] as $wiersz) {
            $out[] = [
                'id_cmp_script' => (int) $wiersz['id_cmp_script'],
                'name' => (string) $wiersz['name'],
                'embedding' => (string) $wiersz['embedding'],
                'technique' => (string) $wiersz['technique'],
                'category' => (string) $wiersz['category'],
                'pattern' => (string) $wiersz['pattern'],
                'position' => (int) $wiersz['position'],
                'active' => (bool) $wiersz['active'],
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(int $idScript, int $idShop): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '`
             WHERE `id_cmp_script` = ' . $idScript . ' AND `id_shop` = ' . $idShop
        );

        return is_array($wiersz) && $wiersz !== [] ? $wiersz : null;
    }

    public function save(
        int $idScript,
        int $idShop,
        string $name,
        string $embedding,
        string $technique,
        string $category,
        string $pattern
    ): bool {
        $dane = [
            'name' => pSQL($name),
            'embedding' => pSQL(array_key_exists($embedding, self::embeddings()) ? $embedding : Schema::EMBED_EXTERNAL),
            'technique' => pSQL(array_key_exists($technique, self::techniques()) ? $technique : Schema::BLOCK_TYPE_SWAP),
            'category' => pSQL($category),
            'pattern' => pSQL($pattern),
        ];

        if ($idScript > 0) {
            return (bool) Db::getInstance()->update(
                Schema::TABLE_SCRIPT,
                $dane,
                '`id_cmp_script` = ' . $idScript . ' AND `id_shop` = ' . $idShop
            );
        }

        return (bool) Db::getInstance()->insert(Schema::TABLE_SCRIPT, $dane + [
            'id_shop' => $idShop,
            'position' => $this->nastepnaPozycja($idShop),
            'active' => 1,
        ]);
    }

    public function toggleActive(int $idScript, int $idShop): bool
    {
        return (bool) Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '`
             SET `active` = 1 - `active`
             WHERE `id_cmp_script` = ' . $idScript . ' AND `id_shop` = ' . $idShop
        );
    }

    public function delete(int $idScript, int $idShop): bool
    {
        return (bool) Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '`
             WHERE `id_cmp_script` = ' . $idScript . ' AND `id_shop` = ' . $idShop
        );
    }

    /**
     * Kategorie ciasteczkowe wraz z ustawionymi sygnałami Consent Mode.
     *
     * Kategoria oznaczona jako wymagana jest pomijana — zgoda na nią nie zapada,
     * więc nie może warunkować żadnego sygnału.
     *
     * @return array<int,array{code:string,name:string,signals:array<string,bool>}>
     */
    public function matrix(int $idShop, int $idLang): array
    {
        $kolumny = implode(', ', array_map(static fn (string $k): string => '`' . $k . '`', self::SIGNALS));

        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`code`, tl.`name`, ' . $kolumny . '
             FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
             LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE_LANG . '` tl
                    ON tl.`id_cmp_type` = t.`id_cmp_type` AND tl.`id_lang` = ' . $idLang . '
             WHERE t.`id_shop` = ' . $idShop . ' AND t.`kind` = "' . Schema::KIND_COOKIE . '"
               AND t.`required` = 0
             ORDER BY t.`position` ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $sygnaly = [];

            foreach (self::SIGNALS as $sygnal => $kolumna) {
                $sygnaly[$sygnal] = (bool) $wiersz[$kolumna];
            }

            $out[] = [
                'code' => (string) $wiersz['code'],
                'name' => (string) ($wiersz['name'] ?? '') ?: (string) $wiersz['code'],
                'signals' => $sygnaly,
            ];
        }

        return $out;
    }

    /**
     * @param array<string,array<string,bool>> $mapowanie kod kategorii => sygnał => czy ustawia
     */
    public function saveMatrix(int $idShop, array $mapowanie): bool
    {
        foreach ($mapowanie as $kod => $sygnaly) {
            $dane = [];

            foreach (self::SIGNALS as $sygnal => $kolumna) {
                $dane[$kolumna] = (int) ($sygnaly[$sygnal] ?? false);
            }

            $ok = Db::getInstance()->update(
                Schema::TABLE_TYPE,
                $dane,
                '`code` = "' . pSQL((string) $kod) . '" AND `id_shop` = ' . $idShop
            );

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sygnały, których nie ustawia żadna aktywna kategoria.
     *
     * @return array<int,string>
     */
    public function sygnalyBezPokrycia(int $idShop, int $idLang): array
    {
        $pokryte = [];

        foreach ($this->matrix($idShop, $idLang) as $kategoria) {
            foreach ($kategoria['signals'] as $sygnal => $ustawia) {
                if ($ustawia) {
                    $pokryte[$sygnal] = true;
                }
            }
        }

        // Tylko wymagane: brak pokrycia sygnału opcjonalnego niczego nie unieważnia.
        return array_values(array_diff(self::SIGNALS_REQUIRED, array_keys($pokryte)));
    }

    private function nastepnaPozycja(int $idShop): int
    {
        return 1 + (int) Db::getInstance()->getValue(
            'SELECT MAX(`position`) FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '` WHERE `id_shop` = ' . $idShop
        );
    }
}
