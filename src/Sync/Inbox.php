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

use Db;
use Quince\Cmp\Storage\ConsentLog;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Schema;

/**
 * Przyjmowanie zmian zgody z systemu zewnętrznego.
 *
 * Konflikt rozstrzyga czas zdarzenia, nie czas doręczenia (SPEC §4.1). Zapis
 * starszy od naszego ostatniego zdarzenia dla tego samego kodu i tej samej osoby
 * zostaje odnotowany jako odrzucony — nie znika po cichu, bo pytanie „dlaczego
 * u nich jest inaczej" musi mieć odpowiedź w panelu.
 */
final class Inbox
{
    /** Po ilu sekundach żądanie uznajemy za przeterminowane. */
    private const TOLERANCJA = 300;

    /**
     * @param array<string,string> $naglowki
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(int $idShop, string $cialo, array $naglowki): array
    {
        $klucz = SyncConfig::text(Options::SYNC_KEY_IN, '', $idShop);

        if ($klucz === '') {
            return $this->odpowiedz(503, ['error' => 'Incoming sync is not configured.']);
        }

        $blad = $this->sprawdzPodpis($cialo, $naglowki, $klucz);

        if ($blad !== null) {
            return $this->odpowiedz(401, ['error' => $blad]);
        }

        $dane = json_decode($cialo, true);

        if (!is_array($dane)) {
            return $this->odpowiedz(400, ['error' => 'Body is not valid JSON.']);
        }

        if (($dane['ping'] ?? false) === true) {
            return $this->odpowiedz(200, ['ok' => true]);
        }

        $brakujace = $this->brakujacePola($dane);

        if ($brakujace !== []) {
            return $this->odpowiedz(422, ['error' => 'Missing fields: ' . implode(', ', $brakujace)]);
        }

        return $this->przyjmij($idShop, $dane, $cialo);
    }

    /**
     * Przyjęcie zdarzenia od nadawcy, którego **tożsamość potwierdziła już inna
     * warstwa** — dziś Admin API PrestaShopa, po OAuth2.
     *
     * Pomija klucz i podpis, bo tam uwierzytelnia platforma, ale przechodzi przez
     * to samo sprawdzanie pól i to samo rozstrzyganie konfliktów co webhook.
     * Gdyby każde wejście miało własną ścieżkę zapisu, prędzej czy później jedno
     * z nich zapisywałoby coś, czego drugie nie dopuszcza — a dziennik zgód jest
     * dowodem i musi znaczyć to samo niezależnie od tego, którymi drzwiami wszedł.
     *
     * @param array<string,mixed> $dane
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    public function accept(int $idShop, array $dane): array
    {
        $brakujace = $this->brakujacePola($dane);

        if ($brakujace !== []) {
            return $this->odpowiedz(422, ['error' => 'Missing fields: ' . implode(', ', $brakujace)]);
        }

        return $this->przyjmij($idShop, $dane, (string) json_encode($dane));
    }

    /**
     * @param array<string,mixed> $dane
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    private function przyjmij(int $idShop, array $dane, string $cialo): array
    {
        $kod = (string) $dane['code'];
        $email = (string) ($dane['email'] ?? '');
        $przyznana = (bool) $dane['granted'];
        $kiedy = date('Y-m-d H:i:s', strtotime((string) $dane['occurred_at']));
        $nasze = $this->ostatnieNasze($idShop, $kod, $email);

        if ($nasze !== null && strtotime($nasze['date_add']) > strtotime($kiedy)) {
            $this->zapiszKonflikt($idShop, $dane, $kiedy, $nasze);

            return $this->odpowiedz(409, [
                'error' => 'A newer event exists in the shop.',
                'shop_event_at' => $nasze['date_add'],
            ]);
        }

        $zapisane = (new ConsentLog())->record($kod, $przyznana, $idShop, $this->jezyk($idShop), [
            'email' => $email,
            'id_customer' => $this->klient($idShop, $email),
            'visitor_key' => (string) ($dane['visitor_key'] ?? ''),
            'source' => (string) ($dane['source'] ?? 'external'),
            'origin' => Schema::ORIGIN_EXTERNAL,
            // Webhook (ciało JSON nadawcy) niesie to pod `id`; Admin API
            // (ConsentEventProcessor) pod `external_id`, nazwą zgodną z kolumną
            // w dzienniku — jeden klucz obsługuje oba wejścia.
            'external_id' => (string) ($dane['external_id'] ?? $dane['id'] ?? ''),
            'ip' => '',
            'user_agent' => '',
        ]);

        Db::getInstance()->insert(Schema::TABLE_SYNC, [
            'id_shop' => $idShop,
            'direction' => Schema::DIRECTION_IN,
            'external_id' => pSQL((string) ($dane['id'] ?? '')),
            'payload' => pSQL($cialo),
            'attempts' => 1,
            'status' => Schema::SYNC_SENT,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        // Identyfikator zapisanego zdarzenia oddajemy wołającemu. Webhook go nie
        // czyta, ale Admin API musi zbudować z niego adres utworzonego zasobu —
        // bez tego odpowiedź na `POST` wywraca się mimo poprawnego zapisu.
        return $this->odpowiedz(200, ['ok' => true, 'event_id' => $zapisane ? (int) $this->ostatniZapis($idShop, $kod, $email) : 0]);
    }

    /**
     * Identyfikator zdarzenia dopisanego przed chwilą.
     *
     * Czytamy go zapytaniem, a nie z `Insert_ID()`, bo `ConsentLog::record()`
     * wykonuje po wstawieniu jeszcze inne zapytania — a wtedy `Insert_ID()` mówi
     * o ostatnim z nich, nie o naszym.
     */
    private function ostatniZapis(int $idShop, string $kod, string $email): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_cmp_log` FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
              WHERE `id_shop` = ' . $idShop . '
                AND `code` = "' . pSQL($kod) . '"
                AND `email` = "' . pSQL($email) . '"
              ORDER BY `id_cmp_log` DESC'
        );
    }

    /**
     * @param array<string,mixed> $dane
     * @param array{date_add:string,granted:bool} $nasze
     */
    private function zapiszKonflikt(int $idShop, array $dane, string $kiedy, array $nasze): void
    {
        $opis = sprintf(
            'External event %s (%s) lost to shop event %s (%s) — newer event wins.',
            substr($kiedy, 0, 16),
            ((bool) $dane['granted']) ? 'granted' : 'withdrawn',
            substr($nasze['date_add'], 0, 16),
            $nasze['granted'] ? 'granted' : 'withdrawn'
        );

        Db::getInstance()->insert(Schema::TABLE_SYNC, [
            'id_shop' => $idShop,
            'direction' => Schema::DIRECTION_IN,
            'external_id' => pSQL((string) ($dane['id'] ?? '')),
            'payload' => pSQL((string) json_encode([
                'code' => (string) $dane['code'],
                'email' => (string) ($dane['email'] ?? ''),
                'external' => ['at' => $kiedy, 'granted' => (bool) $dane['granted']],
                'shop' => ['at' => $nasze['date_add'], 'granted' => $nasze['granted']],
            ])),
            'error' => pSQL($opis),
            'attempts' => 1,
            'status' => Schema::SYNC_FAILED,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Ostatnie nasze zdarzenie dla tego kodu i tej osoby.
     *
     * @return array{date_add:string,granted:bool}|null
     */
    private function ostatnieNasze(int $idShop, string $kod, string $email): ?array
    {
        if ($email === '') {
            return null;
        }

        $wiersz = Db::getInstance()->getRow(
            'SELECT `date_add`, `granted` FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `id_shop` = ' . $idShop . '
               AND `code` = "' . pSQL($kod) . '"
               AND `email` = "' . pSQL($email) . '"
               AND `origin` = "' . Schema::ORIGIN_SHOP . '"
             ORDER BY `date_add` DESC, `id_cmp_log` DESC'
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return null;
        }

        return ['date_add' => (string) $wiersz['date_add'], 'granted' => (bool) $wiersz['granted']];
    }

    /**
     * Podpis i znacznik czasu.
     *
     * Porównanie stałoczasowe — zwykłe `===` na skrótach pozwala odgadywać
     * podpis po czasie odpowiedzi.
     *
     * @param array<string,string> $naglowki
     */
    private function sprawdzPodpis(string $cialo, array $naglowki, string $klucz): ?string
    {
        $podpis = $naglowki[strtolower(Transport::HEADER_SIGNATURE)] ?? '';
        $znacznik = (int) ($naglowki[strtolower(Transport::HEADER_TIMESTAMP)] ?? 0);

        if ($podpis === '' || $znacznik === 0) {
            return 'Missing signature headers.';
        }

        if (abs(time() - $znacznik) > self::TOLERANCJA) {
            return 'Request timestamp is out of tolerance.';
        }

        return hash_equals(Transport::sign($cialo, $klucz, $znacznik), $podpis) ? null : 'Bad signature.';
    }

    /**
     * @param array<string,mixed> $dane
     *
     * @return array<int,string>
     */
    private function brakujacePola(array $dane): array
    {
        $out = [];

        foreach (['code', 'granted', 'occurred_at'] as $pole) {
            if (!array_key_exists($pole, $dane)) {
                $out[] = $pole;
            }
        }

        if (isset($dane['occurred_at']) && strtotime((string) $dane['occurred_at']) === false) {
            $out[] = 'occurred_at (unreadable date)';
        }

        return $out;
    }

    private function klient(int $idShop, string $email): int
    {
        if ($email === '') {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `email` = "' . pSQL($email) . '" AND `id_shop` = ' . $idShop . ' AND `deleted` = 0'
        );
    }

    private function jezyk(int $idShop): int
    {
        return (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
    }

    /**
     * @param array<string,mixed> $cialo
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    private function odpowiedz(int $status, array $cialo): array
    {
        return ['status' => $status, 'body' => $cialo];
    }
}
