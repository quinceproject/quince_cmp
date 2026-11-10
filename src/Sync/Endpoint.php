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

/**
 * Sprawdzenie, czy pod wskazany adres wolno wysłać żądanie.
 *
 * Adres odbiorcy wpisuje sprzedawca, a moduł wysyła tam żądanie z serwera sklepu.
 * Bez tego sprawdzenia pole w panelu jest narzędziem do odpytywania sieci, w której
 * stoi sklep: `https://127.0.0.1:8080/`, `https://10.0.0.5/` albo
 * `https://169.254.169.254/` — usługa metadanych u dostawców chmurowych, zwracająca
 * poświadczenia instancji. Odpowiedź wraca do panelu, więc byłby to pełny odczyt,
 * nie ślepe żądanie.
 *
 * W układzie, w którym sklep stoi na cudzym serwerze — hosting współdzielony, agencja
 * prowadząca sklep klienta — osoba z dostępem do panelu nie jest właścicielem maszyny.
 *
 * Sprawdzamy adres, pod który połączenie faktycznie pójdzie, a nie sam napis: domena
 * wskazująca na `127.0.0.1` wygląda niewinnie. Wynik rozwiązania nazwy zwracamy
 * wywołującemu, żeby przypiął go do połączenia — inaczej między sprawdzeniem
 * a wysyłką DNS może odpowiedzieć czym innym.
 */
final class Endpoint
{
    /**
     * @return array{ok:bool,error:string,host:string,port:int,ip:string}
     */
    public static function sprawdz(string $adres, bool $wolnoLokalnie = false): array
    {
        $zle = static fn (string $powod): array => [
            'ok' => false, 'error' => $powod, 'host' => '', 'port' => 0, 'ip' => '',
        ];

        if (filter_var($adres, FILTER_VALIDATE_URL) === false || !str_starts_with($adres, 'https://')) {
            return $zle('The endpoint must be an absolute https:// address.');
        }

        $czesci = parse_url($adres);
        $host = (string) ($czesci['host'] ?? '');

        if ($host === '') {
            return $zle('The endpoint has no host name.');
        }

        $port = (int) ($czesci['port'] ?? 443);
        $adresy = self::rozwiaz($host);

        if ($adresy === []) {
            return $zle('The host name does not resolve to any address.');
        }

        if (!$wolnoLokalnie) {
            foreach ($adresy as $ip) {
                if (!self::publiczny($ip)) {
                    return $zle('The endpoint resolves to ' . $ip . ', which is inside this server\'s own network. Tick the option below if that is what you mean.');
                }
            }
        }

        return ['ok' => true, 'error' => '', 'host' => $host, 'port' => $port, 'ip' => $adresy[0]];
    }

    /**
     * Adresy, pod które rozwiązuje się nazwa. Literał adresu zwracamy bez pytania DNS-u.
     *
     * @return array<int,string>
     */
    private static function rozwiaz(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Nawias kwadratowy wokół adresu IPv6 w URL-u.
        $goly = trim($host, '[]');

        if (filter_var($goly, FILTER_VALIDATE_IP) !== false) {
            return [$goly];
        }

        $out = [];

        foreach ((array) @gethostbynamel($host) as $ip) {
            $out[] = (string) $ip;
        }

        foreach ((array) @dns_get_record($host, DNS_AAAA) as $wpis) {
            if (isset($wpis['ipv6'])) {
                $out[] = (string) $wpis['ipv6'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Czy adres leży poza sieciami, do których serwer nie powinien sięgać na cudze polecenie.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE` odcina `10/8`, `172.16/12`, `192.168/16` i `fc00::/7`,
     * `FILTER_FLAG_NO_RES_RANGE` — pętlę zwrotną, `169.254/16` z usługą metadanych,
     * `0.0.0.0/8` oraz odpowiedniki w IPv6. Obie flagi razem pokrywają to, co trzeba,
     * bez wypisywania zakresów ręcznie.
     */
    private static function publiczny(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
