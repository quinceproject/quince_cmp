<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Api;

use Db;
use Tools;

/**
 * Co ten moduł wystawia przez Admin API PrestaShopa.
 *
 * Jedno miejsce na opis powierzchni API: operacje, zakresy i adres. Ekran czyta
 * stąd, a nie z własnej listy, żeby dokumentacja w panelu nie rozjechała się
 * z tym, co moduł naprawdę wystawia.
 *
 * Same zasoby leżą w `src/ApiPlatform/Resources`, skąd rdzeń wczytuje je sam.
 */
final class ApiSurface
{
    public const SCOPE_READ = 'quince_cmp_consent_read';
    public const SCOPE_WRITE = 'quince_cmp_consent_write';

    /**
     * Operacje w kolejności, w jakiej ma je czytać człowiek: najpierw odczyt
     * jednej osoby, potem historia, na końcu zapis.
     *
     * @return array<int,array{method:string,path:string,scope:string,summary:string}>
     */
    public static function operations(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/quince-cmp/consents/{email}',
                'scope' => self::SCOPE_READ,
                'summary' => 'The current consent state of one person, per shop.',
            ],
            [
                'method' => 'GET',
                'path' => '/quince-cmp/consent-events',
                'scope' => self::SCOPE_READ,
                'summary' => 'The consent log, newest first. Filters: code, email, from, to, page.',
            ],
            [
                'method' => 'POST',
                'path' => '/quince-cmp/consent-events',
                'scope' => self::SCOPE_WRITE,
                'summary' => 'Record a decision made outside the shop. A withdrawal is granted: false.',
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function scopes(): array
    {
        return [self::SCOPE_READ, self::SCOPE_WRITE];
    }

    /**
     * Adres, pod którym stoi API tego sklepu.
     *
     * Zawsze `https`, bo `SSLMiddlewareListener` rdzenia wymusza bezpieczny
     * protokół i poza trybem deweloperskim nie ma od tego wyjątku. Podanie tu
     * `http` byłoby podpowiedzią adresu, który nigdy nie odpowie.
     */
    public static function baseUrl(): string
    {
        return 'https://' . Tools::getShopDomainSsl() . '/admin-api';
    }

    /**
     * Czy Admin API w ogóle odpowie w tym sklepie.
     *
     * Trzy warunki i każdy z nich potrafi wyłączyć całość:
     *
     * - `PS_ENABLE_ADMIN_API`: włącznik API w sklepie
     * - HTTPS: `SSLMiddlewareListener` odrzuca żądania po zwykłym HTTP i poza
     *   trybem deweloperskim nie ma od tego wyjątku
     * - **wielosklepowość**: gdy jest włączona, `AdminAPIFeatureListener`
     *   odpowiada 404 na wszystko, dopóki nie włączy się flagi
     *   `admin_api_multistore`. Ekran API w rdzeniu o tym nie wspomina,
     *   a objawia się to jak brak trasy
     *
     * @return array{ok:bool,api:bool,ssl:bool,multistore:bool,flag:bool}
     */
    public static function readiness(): array
    {
        $api = (bool) \Configuration::get('PS_ENABLE_ADMIN_API');
        $ssl = (bool) \Configuration::get('PS_SSL_ENABLED');
        $wielosklep = \Shop::isFeatureActive();
        $flaga = (bool) Db::getInstance()->getValue(
            'SELECT `state` FROM `' . _DB_PREFIX_ . 'feature_flag` WHERE `name` = "admin_api_multistore"'
        );

        return [
            'ok' => $api && $ssl && (!$wielosklep || $flaga),
            'api' => $api,
            'ssl' => $ssl,
            'multistore' => $wielosklep,
            'flag' => $flaga,
        ];
    }

    /**
     * Klienci API, którzy mają którykolwiek z naszych zakresów.
     *
     * Czytamy wprost z tabeli rdzenia, bo pytanie brzmi „kto ma dostęp do zgód",
     * a nie „jacy klienci istnieją". Resztę klientów sklepu pokazuje ekran rdzenia.
     *
     * @return array<int,array{name:string,clientId:string,enabled:bool,scopes:array<int,string>,write:bool}>
     */
    public static function clients(): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `client_id`, `client_name`, `enabled`, `scopes`
               FROM `' . _DB_PREFIX_ . 'api_client`
              ORDER BY `client_name` ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $zakresy = json_decode((string) $wiersz['scopes'], true);
            $zakresy = is_array($zakresy) ? array_map('strval', $zakresy) : [];
            $nasze = array_values(array_intersect($zakresy, self::scopes()));

            if ($nasze === []) {
                continue;
            }

            $out[] = [
                'name' => (string) $wiersz['client_name'],
                'clientId' => (string) $wiersz['client_id'],
                'enabled' => (bool) $wiersz['enabled'],
                'scopes' => $nasze,
                'write' => in_array(self::SCOPE_WRITE, $nasze, true),
            ];
        }

        return $out;
    }
}
