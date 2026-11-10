<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Scan;

use Db;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Schema;

/**
 * Skaner: co sklep faktycznie zapisuje w przeglądarce.
 *
 * Nie da się tego ustalić z serwera. Nagłówek `Set-Cookie` pokazuje wyłącznie
 * to, co zapisał sam PrestaShop; wszystko, co dokłada JavaScript — a to jest
 * większość ciasteczek pomiarowych i reklamowych — powstaje dopiero
 * w przeglądarce i nigdy nie przechodzi przez PHP. Dlatego zbiera je skrypt
 * uruchomiony na sklepie, a serwer przyjmuje meldunek.
 *
 * Skanowanie działa w trybie, w którym **wszystkie kategorie są chwilowo
 * zwolnione** — inaczej wstrzymane usługi nigdy by się nie uruchomiły i skaner
 * meldowałby, że sklep nie zapisuje niczego. Tryb obowiązuje wyłącznie
 * przeglądarkę, która ma ważny token, i nie zapisuje żadnej zgody w dzienniku.
 */
final class Scanner
{
    /** Nazwa ciasteczka trybu skanowania. */
    public const COOKIE = 'quince_cmp_scan';

    /** Parametr adresu, którym panel uruchamia skanowanie. */
    public const PARAM = 'qc_scan';

    /** Jak długo token jest ważny. Skan to jedna sesja przeglądania sklepu. */
    private const WAZNY_MINUT = 30;

    /**
     * Nazwy, których nie melduje — własne ciasteczka modułu i sesja sklepu.
     *
     * Meldowanie ich zaśmiecałoby listę rzeczami, których sprzedawca i tak
     * nie opisze, bo nie należą do żadnej usługi zewnętrznej.
     */
    private const POMIJANE = [
        'quince_cmp',
        'quince_cmp_k',
        'quince_cmp_scan',
        'PrestaShop-',
        'PHPSESSID',
    ];

    /** Zakłada nową sesję skanowania i zwraca token. */
    public function start(int $idShop): string
    {
        $token = bin2hex(random_bytes(16));

        Options::save(Options::SCAN_TOKEN, $token, $idShop);
        Options::save(Options::SCAN_STARTED, date('Y-m-d H:i:s'), $idShop);

        return $token;
    }

    /** Czy podany token otwiera tryb skanowania w tym sklepie. */
    public function tokenWazny(string $token, int $idShop): bool
    {
        $zapisany = Options::text(Options::SCAN_TOKEN, '', $idShop);
        $start = Options::text(Options::SCAN_STARTED, '', $idShop);

        if ($zapisany === '' || $token === '' || !hash_equals($zapisany, $token)) {
            return false;
        }

        return $start !== '' && strtotime($start) > strtotime('-' . self::WAZNY_MINUT . ' minutes');
    }

    /** Czy bieżące żądanie idzie w trybie skanowania. */
    public function wTrybie(int $idShop): bool
    {
        $zParametru = (string) ($_GET[self::PARAM] ?? '');
        $zCiasteczka = (string) ($_COOKIE[self::COOKIE] ?? '');

        return $this->tokenWazny($zParametru, $idShop) || $this->tokenWazny($zCiasteczka, $idShop);
    }

    /**
     * Zapisuje meldunek z przeglądarki.
     *
     * Ten sam wpis przy kolejnym przebiegu podbija licznik zamiast tworzyć
     * duplikat — sprzedawca ma zobaczyć listę rzeczy, nie listę zdarzeń.
     *
     * @param array<int,array{kind:string,name:string,domain?:string}> $znaleziska
     *
     * @return int ile pozycji zapisano
     */
    public function record(int $idShop, array $znaleziska, string $strona): int
    {
        $db = Db::getInstance();
        $teraz = date('Y-m-d H:i:s');
        $ile = 0;

        foreach ($znaleziska as $znalezisko) {
            $rodzaj = (string) ($znalezisko['kind'] ?? '');
            $nazwa = trim((string) ($znalezisko['name'] ?? ''));

            if ($nazwa === '' || !$this->rodzajZnany($rodzaj) || $this->pomijane($nazwa)) {
                continue;
            }

            $db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . Schema::TABLE_SCAN . '`
                    (`id_shop`, `kind`, `name`, `domain`, `page`, `status`, `seen`, `date_add`, `date_upd`)
                 VALUES (' . $idShop . ', "' . pSQL($rodzaj) . '", "' . pSQL(mb_substr($nazwa, 0, 190)) . '",
                         "' . pSQL(mb_substr((string) ($znalezisko['domain'] ?? ''), 0, 190)) . '",
                         "' . pSQL(mb_substr($strona, 0, 255)) . '",
                         "' . Schema::FOUND_NEW . '", 1, "' . $teraz . '", "' . $teraz . '")
                 ON DUPLICATE KEY UPDATE `seen` = `seen` + 1, `date_upd` = "' . $teraz . '"'
            );

            ++$ile;
        }

        return $ile;
    }

    /**
     * Znaleziska do pokazania w panelu.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findings(int $idShop, ?string $status = Schema::FOUND_NEW): array
    {
        $warunek = $status !== null ? ' AND s.`status` = "' . pSQL($status) . '"' : '';

        $wiersze = Db::getInstance()->executeS(
            'SELECT s.*, (
                        SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '` c
                         WHERE c.`id_shop` = s.`id_shop` AND c.`name` = s.`name`
                    ) AS `opisane`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SCAN . '` s
              WHERE s.`id_shop` = ' . $idShop . $warunek . '
              ORDER BY s.`kind` ASC, s.`name` ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[] = [
                'id' => (int) $wiersz['id_cmp_scan'],
                'kind' => (string) $wiersz['kind'],
                'name' => (string) $wiersz['name'],
                'domain' => (string) $wiersz['domain'],
                'page' => (string) $wiersz['page'],
                'seen' => (int) $wiersz['seen'],
                'described' => (int) $wiersz['opisane'] > 0,
                'date' => (string) $wiersz['date_upd'],
            ];
        }

        return $out;
    }

    /** Ile znalezisk czeka na decyzję. */
    public function pending(int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_SCAN . '`
              WHERE `id_shop` = ' . $idShop . ' AND `status` = "' . Schema::FOUND_NEW . '"'
        );
    }

    /** Oznacza znalezisko jako załatwione albo świadomie pominięte. */
    public function mark(int $idShop, int $id, string $status): bool
    {
        if (!in_array($status, [Schema::FOUND_ADOPTED, Schema::FOUND_IGNORED, Schema::FOUND_NEW], true)) {
            return false;
        }

        return Db::getInstance()->update(
            Schema::TABLE_SCAN,
            ['status' => pSQL($status), 'date_upd' => date('Y-m-d H:i:s')],
            '`id_cmp_scan` = ' . $id . ' AND `id_shop` = ' . $idShop
        );
    }

    /** Kasuje wszystkie znaleziska sklepu — przed nowym, czystym przebiegiem. */
    public function reset(int $idShop): bool
    {
        return Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_SCAN . '` WHERE `id_shop` = ' . $idShop
        );
    }

    /** Kiedy ostatnio coś zameldowano. */
    public function lastSeen(int $idShop): ?string
    {
        $kiedy = Db::getInstance()->getValue(
            'SELECT MAX(`date_upd`) FROM `' . _DB_PREFIX_ . Schema::TABLE_SCAN . '` WHERE `id_shop` = ' . $idShop
        );

        return is_string($kiedy) && $kiedy !== '' && !str_starts_with($kiedy, '0000') ? $kiedy : null;
    }

    private function rodzajZnany(string $rodzaj): bool
    {
        return in_array($rodzaj, [
            Schema::FOUND_COOKIE,
            Schema::FOUND_LOCAL,
            Schema::FOUND_SESSION,
            Schema::FOUND_REQUEST,
        ], true);
    }

    private function pomijane(string $nazwa): bool
    {
        foreach (self::POMIJANE as $fragment) {
            if (str_starts_with($nazwa, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
