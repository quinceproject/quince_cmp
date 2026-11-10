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
 * Skrzynka nadawcza i dziennik wymiany z systemem zewnętrznym.
 *
 * Kolejka jest pochodną rejestru: każda pozycja wskazuje zdarzenie z `_log`,
 * które ma pojechać na zewnątrz. Rejestr pozostaje tylko do dopisywania, więc
 * nieudana wysyłka nie ma jak zepsuć dowodu — zmienia się wyłącznie stan pozycji
 * w kolejce.
 */
final class SyncQueue
{
    /** Ile razy próbujemy, zanim pozycja zostaje z błędem do decyzji człowieka. */
    public const MAX_ATTEMPTS = 5;

    /** Odstępy między próbami w minutach, indeksowane liczbą dotychczasowych prób. */
    private const BACKOFF = [1, 5, 15, 60, 240];

    /**
     * Dopisuje zdarzenie do wysyłki.
     *
     * Nie sprawdza, czy kod podlega wysyłce — to decyzja warstwy wywołującej,
     * która zna ustawienia sklepu.
     */
    public function enqueue(int $idShop, int $idLog, string $payload): bool
    {
        return Db::getInstance()->insert(Schema::TABLE_SYNC, [
            'id_shop' => $idShop,
            'direction' => Schema::DIRECTION_OUT,
            'id_cmp_log' => $idLog,
            'payload' => pSQL($payload),
            'attempts' => 0,
            'status' => Schema::SYNC_PENDING,
            'next_try' => date('Y-m-d H:i:s'),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Pozycje skrzynki nadawczej, najnowsze pierwsze.
     *
     * @return array<int,array<string,mixed>>
     */
    public function outbox(int $idShop, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $warunek = $status !== null ? ' AND s.`status` = "' . pSQL($status) . '"' : '';

        $wiersze = Db::getInstance()->executeS(
            'SELECT s.*, l.`code`, l.`granted`, l.`email`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '` s
               LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l ON l.`id_cmp_log` = s.`id_cmp_log`
              WHERE s.`id_shop` = ' . $idShop . ' AND s.`direction` = "' . Schema::DIRECTION_OUT . '"' . $warunek . '
              ORDER BY s.`id_cmp_sync` DESC
              LIMIT ' . $offset . ', ' . $limit
        ) ?: [];

        return array_map([$this, 'wiersz'], $wiersze);
    }

    /**
     * Liczba pozycji w każdym ze stanów.
     *
     * @return array{pending:int,sent:int,failed:int}
     */
    public function counts(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS `ile`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
              WHERE `id_shop` = ' . $idShop . ' AND `direction` = "' . Schema::DIRECTION_OUT . '"
              GROUP BY `status`'
        ) ?: [];

        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['status']] = (int) $wiersz['ile'];
        }

        return $out;
    }

    /**
     * Ile zdarzeń z dziennika nigdy nie trafiło do kolejki.
     *
     * @param array<int,int> $idShops
     * @param array<int,string> $kody pusta lista oznacza wszystkie
     */
    public function backlogCount(array $idShops, array $kody): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
              WHERE ' . $this->zakres($idShops, $kody) . '
                AND NOT EXISTS (
                    SELECT 1 FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '` s
                     WHERE s.`id_cmp_log` = l.`id_cmp_log` AND s.`direction` = "' . Schema::DIRECTION_OUT . '"
                )'
        );
    }

    /**
     * Dokłada do kolejki zdarzenia sprzed włączenia wysyłki.
     *
     * Kolejka napełnia się od chwili włączenia, więc bez tego historia zostaje
     * w dzienniku i nigdy nie dojedzie. Zdarzenia przyjęte z zewnątrz są
     * pomijane — wróciłyby do nadawcy.
     *
     * @param array<int,int> $idShops
     * @param array<int,string> $kody
     * @param callable(array<string,mixed>):string $ladunek
     */
    public function enqueueBacklog(array $idShops, array $kody, callable $ladunek, int $limit = 5000): int
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT l.* FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
              WHERE ' . $this->zakres($idShops, $kody) . '
                AND NOT EXISTS (
                    SELECT 1 FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '` s
                     WHERE s.`id_cmp_log` = l.`id_cmp_log` AND s.`direction` = "' . Schema::DIRECTION_OUT . '"
                )
              ORDER BY l.`id_cmp_log` ASC
              LIMIT ' . $limit
        ) ?: [];

        $ile = 0;

        foreach ($wiersze as $wiersz) {
            if ($this->enqueue((int) $wiersz['id_shop'], (int) $wiersz['id_cmp_log'], $ladunek($wiersz))) {
                ++$ile;
            }
        }

        return $ile;
    }

    /**
     * Sprząta kolejkę i dziennik po terminie retencji.
     *
     * @param array<int,int> $idShops
     */
    public function purge(array $idShops, int $dni): int
    {
        if ($dni <= 0) {
            return 0;
        }

        $db = Db::getInstance();
        $sklepy = implode(',', array_map('intval', $idShops));
        $granica = pSQL(date('Y-m-d H:i:s', strtotime('-' . $dni . ' days')));

        // Wychodzące: kasujemy tylko doręczone. Pozycja, która się nie udała,
        // czeka na decyzję człowieka i jej zniknięcie byłoby ukryciem problemu.
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
              WHERE `id_shop` IN (' . $sklepy . ')
                AND `direction` = "' . Schema::DIRECTION_OUT . '"
                AND `status` = "' . Schema::SYNC_SENT . '"
                AND `date_upd` < "' . $granica . '"'
        );

        $ile = (int) $db->Affected_Rows();

        // Przychodzące: cały dziennik, razem z zapisami konfliktów. To relacja
        // z tego, co się wydarzyło, a nie kolejka — po terminie retencji znika
        // tak samo jak reszta. Bez tego konflikty rosłyby w nieskończoność
        // i po roku nie dałoby się odróżnić świeżego od dawno zamkniętego.
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
              WHERE `id_shop` IN (' . $sklepy . ')
                AND `direction` = "' . Schema::DIRECTION_IN . '"
                AND `date_add` < "' . $granica . '"'
        );

        return $ile + (int) $db->Affected_Rows();
    }

    /**
     * @param array<int,int> $idShops
     * @param array<int,string> $kody
     */
    private function zakres(array $idShops, array $kody): string
    {
        $warunek = 'l.`id_shop` IN (' . implode(',', array_map('intval', $idShops)) . ')'
            . ' AND l.`origin` = "' . Schema::ORIGIN_SHOP . '"';

        if ($kody !== []) {
            $warunek .= ' AND l.`code` IN ("' . implode('","', array_map('pSQL', $kody)) . '")';
        }

        return $warunek;
    }

    /**
     * Pozycje gotowe do wysłania w tym przebiegu.
     *
     * @return array<int,array<string,mixed>>
     */
    public function due(int $idShop, int $limit = 100): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT *
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
              WHERE `id_shop` = ' . $idShop . '
                AND `direction` = "' . Schema::DIRECTION_OUT . '"
                AND `status` = "' . Schema::SYNC_PENDING . '"
                AND `next_try` <= "' . date('Y-m-d H:i:s') . '"
              ORDER BY `id_cmp_sync` ASC
              LIMIT ' . $limit
        ) ?: [];

        return $wiersze;
    }

    public function markSent(int $idSync, int $httpCode, string $response, ?string $externalId = null): bool
    {
        $dane = [
            'status' => Schema::SYNC_SENT,
            'http_code' => $httpCode,
            'response' => pSQL(mb_substr($response, 0, 4000)),
            'error' => '',
            'attempts' => ['type' => 'sql', 'value' => '`attempts` + 1'],
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($externalId !== null && $externalId !== '') {
            $dane['external_id'] = pSQL($externalId);
        }

        return Db::getInstance()->update(Schema::TABLE_SYNC, $dane, '`id_cmp_sync` = ' . $idSync);
    }

    /**
     * Odnotowuje nieudaną próbę i wyznacza termin następnej.
     *
     * Po wyczerpaniu prób pozycja zostaje w stanie `failed` — nie znika i nie
     * ponawia się w nieskończoność, bo powtarzanie zapytania do adresu, który
     * odpowiada błędem, nie jest naprawą.
     */
    public function markFailed(int $idSync, int $attempts, int $httpCode, string $blad, string $response = ''): bool
    {
        $proby = $attempts + 1;
        $wyczerpane = $proby >= self::MAX_ATTEMPTS;
        $odstep = self::BACKOFF[min($attempts, count(self::BACKOFF) - 1)];

        return Db::getInstance()->update(Schema::TABLE_SYNC, [
            'status' => $wyczerpane ? Schema::SYNC_FAILED : Schema::SYNC_PENDING,
            'http_code' => $httpCode,
            'response' => pSQL(mb_substr($response, 0, 4000)),
            'error' => pSQL(mb_substr($blad, 0, 255)),
            'attempts' => $proby,
            'next_try' => date('Y-m-d H:i:s', strtotime('+' . $odstep . ' minutes')),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_cmp_sync` = ' . $idSync);
    }

    /** Przywraca pozycję do kolejki bez zerowania licznika prób. */
    public function retry(int $idShop, int $idSync): bool
    {
        return Db::getInstance()->update(Schema::TABLE_SYNC, [
            'status' => Schema::SYNC_PENDING,
            'next_try' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_cmp_sync` = ' . $idSync . ' AND `id_shop` = ' . $idShop);
    }

    /** Przywraca do kolejki wszystko, co odpadło po wyczerpaniu prób. */
    public function retryAllFailed(int $idShop): int
    {
        $db = Db::getInstance();

        $db->update(Schema::TABLE_SYNC, [
            'status' => Schema::SYNC_PENDING,
            'attempts' => 0,
            'next_try' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_shop` = ' . $idShop . ' AND `status` = "' . Schema::SYNC_FAILED . '"');

        return (int) $db->Affected_Rows();
    }

    /**
     * Podsumowanie ostatniego przebiegu wysyłki.
     *
     * @return array{date:?string,sent:int,failed:int}
     */
    public function lastRun(int $idShop): array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT MAX(`date_upd`) AS `kiedy`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
              WHERE `id_shop` = ' . $idShop . ' AND `direction` = "' . Schema::DIRECTION_OUT . '"
                AND `status` <> "' . Schema::SYNC_PENDING . '"'
        );

        $kiedy = $wiersz['kiedy'] ?? null;

        if ($kiedy === null || str_starts_with((string) $kiedy, '0000')) {
            return ['date' => null, 'sent' => 0, 'failed' => 0];
        }

        $od = date('Y-m-d H:i:s', strtotime((string) $kiedy) - 3600);

        $podsumowanie = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS `ile`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
              WHERE `id_shop` = ' . $idShop . ' AND `direction` = "' . Schema::DIRECTION_OUT . '"
                AND `date_upd` >= "' . pSQL($od) . '"
              GROUP BY `status`'
        ) ?: [];

        $out = ['date' => (string) $kiedy, 'sent' => 0, 'failed' => 0];

        foreach ($podsumowanie as $wiersz) {
            if ((string) $wiersz['status'] === Schema::SYNC_SENT) {
                $out['sent'] = (int) $wiersz['ile'];
            }

            if ((string) $wiersz['status'] === Schema::SYNC_FAILED) {
                $out['failed'] = (int) $wiersz['ile'];
            }
        }

        return $out;
    }

    /**
     * Zdarzenia przyjęte z zewnątrz od podanej chwili wraz z rozstrzygnięciami.
     *
     * Ekran liczy je od ostatniego przebiegu wysyłki, bo to jest odcinek, o który
     * pyta sprzedawca: „co się wydarzyło, odkąd ostatnio patrzyłem".
     *
     * Konflikt rozstrzyga czas zdarzenia, nie czas przyjęcia (SPEC §4.1), więc
     * zapis z zewnątrz starszy od naszego trafia do dziennika jako odrzucony.
     *
     * @return array{accepted:int,rejected:int,conflicts:array<int,array<string,mixed>>}
     */
    public function incoming(int $idShop, ?string $od = null): array
    {
        $od = $od ?? date('Y-m-d H:i:s', strtotime('-1 day'));

        $liczby = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS `ile`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
              WHERE `id_shop` = ' . $idShop . ' AND `direction` = "' . Schema::DIRECTION_IN . '"
                AND `date_add` >= "' . pSQL($od) . '"
              GROUP BY `status`'
        ) ?: [];

        $out = ['accepted' => 0, 'rejected' => 0, 'conflicts' => []];

        foreach ($liczby as $wiersz) {
            if ((string) $wiersz['status'] === Schema::SYNC_SENT) {
                $out['accepted'] = (int) $wiersz['ile'];
            }

            if ((string) $wiersz['status'] === Schema::SYNC_FAILED) {
                $out['rejected'] = (int) $wiersz['ile'];
            }
        }

        $konflikty = Db::getInstance()->executeS(
            'SELECT `payload`, `error`, `date_add`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
              WHERE `id_shop` = ' . $idShop . ' AND `direction` = "' . Schema::DIRECTION_IN . '"
                AND `status` = "' . Schema::SYNC_FAILED . '"
                AND `date_add` >= "' . pSQL($od) . '"
              ORDER BY `id_cmp_sync` DESC
              LIMIT 5'
        ) ?: [];

        foreach ($konflikty as $wiersz) {
            $dane = json_decode((string) $wiersz['payload'], true);

            $out['conflicts'][] = [
                'date' => (string) $wiersz['date_add'],
                'reason' => (string) $wiersz['error'],
                'code' => is_array($dane) ? (string) ($dane['code'] ?? '') : '',
                'email' => is_array($dane) ? (string) ($dane['email'] ?? '') : '',
                'external' => is_array($dane) ? $dane['external'] ?? null : null,
                'shop' => is_array($dane) ? $dane['shop'] ?? null : null,
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $wiersz */
    private function wiersz(array $wiersz): array
    {
        return [
            'id' => (int) $wiersz['id_cmp_sync'],
            'date' => (string) $wiersz['date_add'],
            'code' => (string) ($wiersz['code'] ?? ''),
            'granted' => $wiersz['granted'] === null ? null : (bool) $wiersz['granted'],
            'email' => (string) ($wiersz['email'] ?? ''),
            'attempts' => (int) $wiersz['attempts'],
            'maxAttempts' => self::MAX_ATTEMPTS,
            'response' => $this->odpowiedz($wiersz),
            'status' => (string) $wiersz['status'],
        ];
    }

    /** Skrót odpowiedzi odbiorcy do jednej komórki tabeli. */
    private function odpowiedz(array $wiersz): string
    {
        $blad = trim((string) ($wiersz['error'] ?? ''));

        if ($blad !== '') {
            return $blad;
        }

        $kod = (int) ($wiersz['http_code'] ?? 0);

        return $kod > 0 ? (string) $kod : '';
    }
}
