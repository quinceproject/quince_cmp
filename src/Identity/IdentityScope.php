<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Identity;

use Db;
use Shop;

/**
 * Zasięg tożsamości: zbiór sklepów, w których ten sam adres e-mail oznacza tę
 * samą osobę.
 *
 * Zakres wyznacza `share_customer` na grupie sklepów. Grupa dzieląca klientów
 * daje jeden profil obejmujący wszystkie jej sklepy; grupa bez dzielenia daje
 * profil odrębny dla każdego sklepu. Wyszukanie adresu może więc zwrócić kilka
 * profili.
 *
 * Zasięg wyznaczany jest na podstawie sklepu, w którym zapisano zdarzenie, a nie
 * `id_customer`, bo zgody zbierane są również przed założeniem konta.
 */
final class IdentityScope
{
    /** @return array<int,int> identyfikatory sklepów, zawsze z podanym włącznie */
    public static function shopsFor(int $idShop): array
    {
        $idGrupy = self::grupaSklepu($idShop);

        if ($idGrupy === 0 || !self::grupaDzieliKlientow($idGrupy)) {
            return [$idShop];
        }

        $sklepy = array_map(
            static fn (array $s): int => (int) $s['id_shop'],
            Shop::getShops(false, $idGrupy, false) ?: []
        );

        // Pusta grupa (np. wszystkie sklepy nieaktywne) sprowadza zasięg do sklepu
        // podanego w argumencie.
        return $sklepy !== [] ? $sklepy : [$idShop];
    }

    /**
     * Dzieli listę sklepów na rozłączne zasięgi tożsamości.
     *
     * @param array<int,int> $idShops
     *
     * @return array<int,array{key:string,label:string,shops:array<int,int>}>
     */
    public static function split(array $idShops): array
    {
        $zasiegi = [];

        foreach ($idShops as $idShop) {
            $idShop = (int) $idShop;
            $wZasiegu = self::shopsFor($idShop);

            // Kluczem zasięgu jest najmniejszy identyfikator sklepu, niezależny od
            // kolejności przechodzenia listy.
            sort($wZasiegu);
            $klucz = (string) $wZasiegu[0];

            if (isset($zasiegi[$klucz])) {
                continue;
            }

            $zasiegi[$klucz] = [
                'key' => $klucz,
                'label' => self::label($idShop),
                // Zawężenie do sklepów obecnych w kontekście panelu.
                'shops' => array_values(array_intersect($wZasiegu, array_map('intval', $idShops))),
            ];
        }

        return array_values($zasiegi);
    }

    /**
     * Nazwa zasięgu: nazwa grupy przy dzielonych klientach, w przeciwnym razie
     * nazwa sklepu.
     */
    public static function label(int $idShop): string
    {
        $idGrupy = self::grupaSklepu($idShop);

        if ($idGrupy !== 0 && self::grupaDzieliKlientow($idGrupy)) {
            return (string) Db::getInstance()->getValue(
                'SELECT `name` FROM `' . _DB_PREFIX_ . 'shop_group` WHERE `id_shop_group` = ' . $idGrupy
            );
        }

        return (string) (new Shop($idShop))->name;
    }

    private static function grupaSklepu(int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_shop_group` FROM `' . _DB_PREFIX_ . 'shop` WHERE `id_shop` = ' . $idShop
        );
    }

    private static function grupaDzieliKlientow(int $idGrupy): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT `share_customer` FROM `' . _DB_PREFIX_ . 'shop_group` WHERE `id_shop_group` = ' . $idGrupy
        );
    }
}
