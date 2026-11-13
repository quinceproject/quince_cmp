<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Sync;

use Quince\Cmp\Storage\Options;
use Shop;

/**
 * Ustawienia wymiany z uwzględnieniem zasięgu.
 *
 * Sprzedawca prowadzący kilka witryn zwykle ma jeden system po drugiej stronie,
 * więc adres i klucz można ustawić raz dla całej grupy sklepów.
 *
 * Odczyt jest **zawsze** per sklep. Zasięg grupowy realizujemy powielaniem przy
 * zapisie, nie odczytem z poziomu grupy: `Configuration` na poziomie grupy
 * stempluje wiersz bieżącym sklepem i przy braku wpisu schodzi do wartości
 * globalnej, więc druga grupa widziałaby cudze ustawienia.
 */
final class SyncConfig
{
    public const SCOPE_SHOP = 'shop';
    public const SCOPE_GROUP = 'group';

    /** Klucze objęte zasięgiem grupowym. Reszta zostaje przy witrynie. */
    private const WSPOLNE = [
        Options::SYNC_ON,
        Options::SYNC_ENDPOINT,
        Options::SYNC_KEY_OUT,
        Options::SYNC_KEY_IN,
        Options::SYNC_CODES,
        Options::SYNC_EVERY,
        Options::SYNC_KEEP_DAYS,
        Options::SYNC_ALERT_ON,
    ];

    public static function scope(int $idShop): string
    {
        return Options::text(Options::SYNC_SCOPE, self::SCOPE_SHOP, $idShop) === self::SCOPE_GROUP
            ? self::SCOPE_GROUP
            : self::SCOPE_SHOP;
    }

    public static function text(string $klucz, string $domyslnie, int $idShop): string
    {
        return Options::text($klucz, $domyslnie, $idShop);
    }

    public static function int(string $klucz, int $domyslnie, int $idShop): int
    {
        return Options::czyUstawione($klucz, $idShop) ? Options::int($klucz, $domyslnie, $idShop) : $domyslnie;
    }

    public static function bool(string $klucz, bool $domyslnie, int $idShop): bool
    {
        return Options::czyUstawione($klucz, $idShop) ? Options::bool($klucz, $domyslnie, $idShop) : $domyslnie;
    }

    /** Zapisuje wartość w witrynie, a przy zasięgu grupowym w całej grupie. */
    public static function save(string $klucz, mixed $wartosc, int $idShop): bool
    {
        $ok = true;

        foreach (self::zapisywaneSklepy($klucz, $idShop) as $id) {
            $ok = Options::save($klucz, $wartosc, $id) && $ok;
        }

        return $ok;
    }

    /**
     * Przełącza zasięg i wyrównuje ustawienia w grupie.
     *
     * Przy wejściu w zasięg grupowy witryny rodzeństwa dostają wartości tej,
     * na której dokonano zmiany. Inaczej pokazywałyby „ta witryna osobno",
     * trzymając cudze ustawienia. Wyjście dotyczy wyłącznie tej witryny, żeby
     * dało się odłączyć jeden sklep od wspólnego odbiorcy.
     */
    public static function setScope(string $zasieg, int $idShop): void
    {
        $zasieg = $zasieg === self::SCOPE_GROUP ? self::SCOPE_GROUP : self::SCOPE_SHOP;
        Options::save(Options::SYNC_SCOPE, $zasieg, $idShop);

        if ($zasieg !== self::SCOPE_GROUP) {
            return;
        }

        foreach (self::sklepy($idShop) as $id) {
            if ($id === $idShop) {
                continue;
            }

            Options::save(Options::SYNC_SCOPE, self::SCOPE_GROUP, $id);

            foreach (self::WSPOLNE as $klucz) {
                Options::save($klucz, Options::text($klucz, '', $idShop), $id);
            }
        }
    }

    /**
     * Witryny objęte tym samym ustawieniem.
     *
     * @return array<int,int>
     */
    public static function sklepy(int $idShop): array
    {
        if (self::scope($idShop) === self::SCOPE_SHOP) {
            return [$idShop];
        }

        $out = [];

        foreach (Shop::getShops(true, self::grupa($idShop), true) as $id) {
            $out[] = (int) $id;
        }

        sort($out);

        return $out !== [] ? $out : [$idShop];
    }

    /** Nazwa grupy do opisania zasięgu na ekranie. */
    public static function nazwaGrupy(int $idShop): string
    {
        $grupa = (new Shop($idShop))->getGroup();

        return (string) ($grupa->name ?? '');
    }

    /** Ile witryn liczy grupa tej witryny, niezależnie od wybranego zasięgu. */
    public static function rozmiarGrupy(int $idShop): int
    {
        return count(Shop::getShops(true, self::grupa($idShop), true));
    }

    /**
     * Zaklada brakujace klucze podpisu.
     *
     * Sklep bez klucza nadawcy odrzuca wszystko, co przyjdzie z zewnatrz, a bez
     * klucza podpisu nie wysyla nic, i jedno, i drugie po cichu. Klucz sam niczego
     * nie wlacza, wiec zakladamy go od razu.
     */
    public static function zapewnijKlucze(int $idShop): void
    {
        foreach ([Options::SYNC_KEY_OUT => 'out_', Options::SYNC_KEY_IN => 'in_'] as $klucz => $prefiks) {
            if (Options::text($klucz, '', $idShop) === '') {
                Options::save($klucz, $prefiks . bin2hex(random_bytes(24)), $idShop);
            }
        }
    }

    public static function grupa(int $idShop): int
    {
        return (int) (new Shop($idShop))->id_shop_group;
    }

    /**
     * @return array<int,int>
     */
    private static function zapisywaneSklepy(string $klucz, int $idShop): array
    {
        return in_array($klucz, self::WSPOLNE, true) ? self::sklepy($idShop) : [$idShop];
    }
}
