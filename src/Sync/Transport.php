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
use Quince\Cmp\Storage\Schema;
use Quince\Cmp\Storage\SyncQueue;

/**
 * Wysyłka zdarzeń zgody do systemu zewnętrznego.
 *
 * Ładunek jest podpisany kluczem współdzielonym (HMAC-SHA256) i niesie znacznik
 * czasu, żeby odbiorca mógł odrzucić powtórzone żądanie. Podpis liczony jest
 * z surowego ciała żądania — nie z posortowanych pól — bo tylko wtedy obie
 * strony podpisują dokładnie to samo.
 */
final class Transport
{
    /** Po ilu sekundach uznajemy odbiorcę za niedostępnego. */
    private const TIMEOUT = 10;

    /** Nagłówki, którymi odbiorca weryfikuje żądanie. */
    public const HEADER_SIGNATURE = 'X-Quince-Signature';
    public const HEADER_TIMESTAMP = 'X-Quince-Timestamp';

    public function __construct(private readonly SyncQueue $queue)
    {
    }

    /**
     * Wysyła wszystko, co czeka w kolejce dla wskazanego sklepu.
     *
     * @return array{sent:int,failed:int,gaveUp:int,purged:int,skipped:bool}
     */
    public function run(int $idShop, int $limit = 100): array
    {
        $adres = SyncConfig::text(Options::SYNC_ENDPOINT, '', $idShop);
        $klucz = SyncConfig::text(Options::SYNC_KEY_OUT, '', $idShop);

        $wolnoLokalnie = SyncConfig::bool(Options::SYNC_ALLOW_LOCAL, false, $idShop);

        if (!SyncConfig::bool(Options::SYNC_ON, false, $idShop) || $adres === '' || $klucz === '') {
            return ['sent' => 0, 'failed' => 0, 'gaveUp' => 0, 'purged' => 0, 'skipped' => true];
        }

        $wynik = ['sent' => 0, 'failed' => 0, 'gaveUp' => 0, 'purged' => 0, 'skipped' => false];
        $ostatniBlad = '';

        foreach ($this->queue->due($idShop, $limit) as $pozycja) {
            $odpowiedz = $this->post($adres, $klucz, (string) $pozycja['payload'], $wolnoLokalnie);
            $id = (int) $pozycja['id_cmp_sync'];

            if ($odpowiedz['ok']) {
                $this->queue->markSent($id, $odpowiedz['code'], $odpowiedz['body'], $odpowiedz['externalId']);
                ++$wynik['sent'];

                continue;
            }

            $proby = (int) $pozycja['attempts'];
            $this->queue->markFailed($id, $proby, $odpowiedz['code'], $odpowiedz['error'], $odpowiedz['body']);
            ++$wynik['failed'];
            $ostatniBlad = $odpowiedz['error'];

            if ($proby + 1 >= SyncQueue::MAX_ATTEMPTS) {
                ++$wynik['gaveUp'];
            }
        }

        $wynik['purged'] = $this->queue->purge(
            [$idShop],
            SyncConfig::int(Options::SYNC_KEEP_DAYS, 30, $idShop)
        );

        if ($wynik['gaveUp'] > 0) {
            Alert::sendFailure($idShop, $wynik['gaveUp'], $ostatniBlad);
        }

        return $wynik;
    }

    /**
     * Sprawdza połączenie bez ruszania kolejki.
     *
     * @return array{ok:bool,code:int,error:string}
     */
    public function ping(int $idShop): array
    {
        $adres = SyncConfig::text(Options::SYNC_ENDPOINT, '', $idShop);
        $klucz = SyncConfig::text(Options::SYNC_KEY_OUT, '', $idShop);

        if ($adres === '' || $klucz === '') {
            return ['ok' => false, 'code' => 0, 'error' => 'No endpoint or signing key configured.'];
        }

        $odpowiedz = $this->post(
            $adres,
            $klucz,
            json_encode(['ping' => true], JSON_THROW_ON_ERROR),
            SyncConfig::bool(Options::SYNC_ALLOW_LOCAL, false, $idShop)
        );

        return ['ok' => $odpowiedz['ok'], 'code' => $odpowiedz['code'], 'error' => $odpowiedz['error']];
    }

    /**
     * Ładunek pojedynczego zdarzenia.
     *
     * Niesie skrót treści, a nie samą treść: odbiorca ma umieć odtworzyć, na co
     * ktoś się zgodził, ale rejestr treści zostaje po naszej stronie.
     *
     * @param array<string,mixed> $zdarzenie
     */
    public static function payload(array $zdarzenie, string $sklep): string
    {
        return json_encode([
            'shop' => $sklep,
            'code' => (string) $zdarzenie['code'],
            'granted' => (bool) $zdarzenie['granted'],
            'email' => (string) ($zdarzenie['email'] ?? ''),
            'visitor_key' => (string) ($zdarzenie['visitor_key'] ?? ''),
            'version' => (string) ($zdarzenie['version'] ?? ''),
            'text_hash' => (string) ($zdarzenie['text_hash'] ?? ''),
            'source' => (string) ($zdarzenie['source'] ?? ''),
            'origin' => Schema::ORIGIN_SHOP,
            'occurred_at' => (string) $zdarzenie['date_add'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Przykładowy ładunek do opisu kontraktu w panelu.
     *
     * Budowany tą samą metodą co ładunek prawdziwy, żeby dokumentacja na ekranie
     * nie rozjechała się z tym, co moduł faktycznie wysyła.
     */
    public static function przykladowyLadunek(string $sklep): string
    {
        $json = self::payload([
            'code' => 'cookies_marketing',
            'granted' => true,
            'email' => 'anna.kowalska@example.com',
            'visitor_key' => '9f1c4b7e2a8d40559e3b6c1af2d70e84',
            'version' => '3',
            'text_hash' => 'b1946ac92492d2347c6235b4d2611184',
            'source' => 'banner',
            'date_add' => '2026-08-27 11:40:07',
        ], $sklep);

        return (string) json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Podpis ciała żądania. Odbiorca liczy go tak samo i porównuje. */
    public static function sign(string $cialo, string $klucz, int $znacznik): string
    {
        return hash_hmac('sha256', $znacznik . '.' . $cialo, $klucz);
    }

    /**
     * @return array{ok:bool,code:int,body:string,error:string,externalId:?string}
     */
    private function post(string $adres, string $klucz, string $cialo, bool $wolnoLokalnie = false): array
    {
        $znacznik = time();

        // Sprawdzenie powtarzamy przy każdej wysyłce, nie tylko przy zapisie ustawień:
        // nazwa, która wtedy wskazywała na adres publiczny, dziś może wskazywać
        // na pętlę zwrotną.
        $ocena = Endpoint::sprawdz($adres, $wolnoLokalnie);

        if (!$ocena['ok']) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $ocena['error'], 'externalId' => null];
        }

        $uchwyt = curl_init($adres);

        curl_setopt_array($uchwyt, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cialo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            // Połączenie przypięte do sprawdzonego adresu. Bez tego DNS mógłby między
            // sprawdzeniem a połączeniem odpowiedzieć czym innym.
            CURLOPT_RESOLVE => [$ocena['host'] . ':' . $ocena['port'] . ':' . $ocena['ip']],
            // Przekierowanie omija sprawdzenie, więc za nim nie idziemy.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                self::HEADER_TIMESTAMP . ': ' . $znacznik,
                self::HEADER_SIGNATURE . ': ' . self::sign($cialo, $klucz, $znacznik),
            ],
        ]);

        $tresc = curl_exec($uchwyt);
        $kod = (int) curl_getinfo($uchwyt, CURLINFO_RESPONSE_CODE);
        $bladSieci = curl_error($uchwyt);

        curl_close($uchwyt);

        if ($tresc === false) {
            return ['ok' => false, 'code' => $kod, 'body' => '', 'error' => $bladSieci ?: 'connection failed', 'externalId' => null];
        }

        $tresc = (string) $tresc;
        $ok = $kod >= 200 && $kod < 300;
        $dane = json_decode($tresc, true);

        return [
            'ok' => $ok,
            'code' => $kod,
            'body' => $tresc,
            'error' => $ok ? '' : $this->opisBledu($kod, $dane),
            'externalId' => is_array($dane) ? ($dane['id'] ?? null) : null,
        ];
    }

    private function opisBledu(int $kod, mixed $dane): string
    {
        if (is_array($dane) && isset($dane['error']) && is_string($dane['error'])) {
            return $dane['error'];
        }

        return $kod > 0 ? $kod . ' ' . $this->nazwaKodu($kod) : 'timeout';
    }

    private function nazwaKodu(int $kod): string
    {
        return match (true) {
            $kod === 401 || $kod === 403 => 'Unauthorized',
            $kod === 404 => 'Not Found',
            $kod === 409 => 'Conflict',
            $kod === 422 => 'Unprocessable',
            $kod === 429 => 'Too Many Requests',
            $kod >= 500 => 'Server error',
            default => 'HTTP error',
        };
    }
}
