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
use Quince\Cmp\Sync\SyncConfig;
use Quince\Cmp\Sync\Transport;

/**
 * Zapis zdarzeń do dziennika zgód.
 *
 * Dziennik działa w trybie append-only; stan bieżący wylicza się z ostatniego
 * zdarzenia dla danego kodu. Wiersz przechowuje `code`, `version` i `text_hash`
 * jako wartości, nie odwołania, dzięki czemu pozostaje kompletny po usunięciu
 * rodzaju zgody lub zmianie treści klauzuli.
 */
final class ConsentLog
{
    public const SOURCE_CHECKOUT = 'checkout';
    public const SOURCE_REGISTRATION = 'registration';
    public const SOURCE_ACCOUNT = 'account';
    public const SOURCE_NEWSLETTER = 'newsletter';
    public const SOURCE_BANNER = 'banner';

    /**
     * Dopisuje zdarzenie do dziennika.
     *
     * Numer wersji i skrót treści pobierane są z wersji obowiązującej w chwili
     * zapisu; wywołujący ich nie podaje. Brak opublikowanej wersji nie blokuje
     * zapisu — zdarzenie trafia do dziennika z pustym numerem wersji.
     *
     * @param array{
     *     email?:string, id_customer?:int, visitor_key?:string, source?:string,
     *     form_id?:string, ip?:string, user_agent?:string, origin?:string, external_id?:string
     * } $kontekst
     */
    public function record(string $code, bool $granted, int $idShop, int $idLang, array $kontekst = []): bool
    {
        $wersja = $this->obowiazujacaWersja($code, $idShop, $idLang);
        $teraz = date('Y-m-d H:i:s');

        $zapisane = (bool) Db::getInstance()->insert(Schema::TABLE_LOG, [
            'id_shop' => $idShop,
            'visitor_key' => pSQL((string) ($kontekst['visitor_key'] ?? '')),
            'email' => pSQL((string) ($kontekst['email'] ?? '')),
            'id_customer' => (int) ($kontekst['id_customer'] ?? 0),
            'code' => pSQL($code),
            'version' => pSQL($wersja['version']),
            'text_hash' => pSQL($wersja['text_hash']),
            'id_lang' => $idLang,
            'granted' => (int) $granted,
            'source' => pSQL((string) ($kontekst['source'] ?? '')),
            'form_id' => pSQL((string) ($kontekst['form_id'] ?? '')),
            'ip' => pSQL((string) ($kontekst['ip'] ?? self::adresIp())),
            'user_agent' => pSQL(substr((string) ($kontekst['user_agent'] ?? self::przegladarka()), 0, 255)),
            'origin' => pSQL((string) ($kontekst['origin'] ?? Schema::ORIGIN_SHOP)),
            'external_id' => pSQL((string) ($kontekst['external_id'] ?? '')),
            'id_employee' => (int) ($kontekst['id_employee'] ?? 0),
            'note' => pSQL(substr((string) ($kontekst['note'] ?? ''), 0, 255)),
            'date_add' => pSQL($teraz),
        ]);

        if ($zapisane) {
            $this->doWysylki((int) Db::getInstance()->Insert_ID(), $code, $granted, $idShop, $teraz, $wersja, $kontekst);
            $this->pushToCore($code, $granted, $idShop, (int) ($kontekst['id_customer'] ?? 0));
        }

        return $zapisane;
    }

    /**
     * Przenosi stan zgody do odpowiadającej kolumny `ps_customer`, aby moduły
     * czytające natywne pole (np. wysyłka newslettera) widziały aktualną
     * wartość, nie tę sprzed ostatniej zmiany w dzienniku.
     *
     * Dotyczy też zdarzeń przyjętych z zewnątrz (`origin: external`) —
     * ta sama zasada co przy zapisie przez kartę klienta: pole ma pokazywać
     * to, co jest prawdą, bez względu na to, skąd przyszła zmiana.
     */
    private function pushToCore(string $code, bool $granted, int $idShop, int $idCustomer): void
    {
        if ($idCustomer <= 0) {
            return;
        }

        $pole = array_search($code, (new ConsentTypes())->coreFieldMap($idShop), true);

        if ($pole === false) {
            return;
        }

        Db::getInstance()->update('customer', [$pole => (int) $granted], '`id_customer` = ' . $idCustomer);
    }

    /**
     * Dokłada świeże zdarzenie do skrzynki nadawczej.
     *
     * Zdarzenia przyjęte z zewnątrz nie wracają do nadawcy — inaczej każda
     * przyjęta zmiana odbijałaby się z powrotem i wracała jako kolejna.
     *
     * @param array{version:string,text_hash:string} $wersja
     * @param array<string,mixed> $kontekst
     */
    private function doWysylki(int $idLog, string $code, bool $granted, int $idShop, string $kiedy, array $wersja, array $kontekst): void
    {
        if ($idLog <= 0 || ($kontekst['origin'] ?? Schema::ORIGIN_SHOP) === Schema::ORIGIN_EXTERNAL) {
            return;
        }

        if (!SyncConfig::bool(Options::SYNC_ON, false, $idShop) || !self::wysylany($code, $idShop)) {
            return;
        }

        $sklep = new \Shop($idShop);

        (new SyncQueue())->enqueue($idShop, $idLog, Transport::payload([
            'code' => $code,
            'granted' => $granted,
            'email' => (string) ($kontekst['email'] ?? ''),
            'visitor_key' => (string) ($kontekst['visitor_key'] ?? ''),
            'version' => $wersja['version'],
            'text_hash' => $wersja['text_hash'],
            'source' => (string) ($kontekst['source'] ?? ''),
            'date_add' => $kiedy,
        ], (string) $sklep->name));
    }

    /** Czy kod jest na liście wysyłanych. Pusta lista oznacza wszystkie. */
    private static function wysylany(string $code, int $idShop): bool
    {
        $lista = SyncConfig::text(Options::SYNC_CODES, '', $idShop);

        return $lista === '' || in_array($code, array_filter(explode(',', $lista)), true);
    }

    /**
     * Czy ostatnie zdarzenie dla tego kodu ma już podany stan.
     *
     * Hook `actionCustomerAccountUpdate` wywoływany jest przy każdym zapisie karty
     * klienta, niezależnie od tego, czy zgody uległy zmianie.
     */
    public function bezZmiany(string $code, bool $granted, int $idShop, string $email, int $idCustomer): bool
    {
        $warunek = $idCustomer > 0
            ? '`id_customer` = ' . $idCustomer
            : '`email` = "' . pSQL($email) . '"';

        $ostatnie = Db::getInstance()->getValue(
            'SELECT `granted` FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `code` = "' . pSQL($code) . '" AND `id_shop` = ' . $idShop . ' AND ' . $warunek . '
             ORDER BY `date_add` DESC, `id_cmp_log` DESC'
        );

        return $ostatnie !== false && (bool) $ostatnie === $granted;
    }

    /**
     * Wersja obowiązująca w chwili wywołania: najpóźniejsza spełniająca
     * `date_from <= NOW()`. Wersje o dacie przyszłej są pomijane.
     *
     * @return array{version:string,text_hash:string}
     */
    private function obowiazujacaWersja(string $code, int $idShop, int $idLang): array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT v.`version`, vl.`text_hash`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v
             INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t ON t.`id_cmp_type` = v.`id_cmp_type`
             LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '` vl
                    ON vl.`id_cmp_version` = v.`id_cmp_version` AND vl.`id_lang` = ' . $idLang . '
             WHERE t.`code` = "' . pSQL($code) . '" AND t.`id_shop` = ' . $idShop . '
               AND v.`date_from` <= NOW()
             ORDER BY v.`date_from` DESC, v.`id_cmp_version` DESC'
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return ['version' => '', 'text_hash' => ''];
        }

        return [
            'version' => (string) $wiersz['version'],
            'text_hash' => (string) ($wiersz['text_hash'] ?? ''),
        ];
    }

    /** @return array<int,string> */
    public function aktywneKody(int $idShop, string $kind): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `code` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
             WHERE `id_shop` = ' . $idShop . ' AND `kind` = "' . pSQL($kind) . '" AND `active` = 1
             ORDER BY `position` ASC'
        ) ?: [];

        return array_map(static fn (array $w): string => (string) $w['code'], $wiersze);
    }

    /**
     * Kody zgód oznaczonych do pokazania w checkoucie, niezależnie od `kind`.
     *
     * Osobna metoda od {@see aktywneKody()}: ta filtruje po przełączniku
     * sprzedawcy (`show_in_checkout`), nie po rodzaju zgody. Dwie zgody
     * dokumentowe mogą dziś służyć różnym celom i tylko jedna z nich ma
     * stanąć w checkoucie.
     *
     * @return array<int,string>
     */
    public function aktywneKodyDoCheckoutu(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `code` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
             WHERE `id_shop` = ' . $idShop . ' AND `show_in_checkout` = 1 AND `active` = 1
             ORDER BY `position` ASC'
        ) ?: [];

        return array_map(static fn (array $w): string => (string) $w['code'], $wiersze);
    }

    /**
     * Ostatni stan każdego kodu dla tej osoby — karta klienta w rdzeniu.
     *
     * Dopasowanie po `id_customer`, a dla zdarzeń sprzed założenia konta
     * (np. zapis na newsletter jako gość) także po adresie, żeby historia
     * sprzed rejestracji nie znikała z widoku.
     *
     * @return array<string,array{granted:bool,date_add:string,source:string}>
     */
    public function ostatnieDlaOsoby(int $idShop, int $idCustomer, string $email): array
    {
        $warunki = ['`id_customer` = ' . $idCustomer];

        if ($email !== '') {
            $warunki[] = '(`id_customer` = 0 AND `email` = "' . pSQL($email) . '")';
        }

        $wiersze = Db::getInstance()->executeS(
            'SELECT `code`, `granted`, `date_add`, `source`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `id_shop` = ' . $idShop . ' AND (' . implode(' OR ', $warunki) . ')
             ORDER BY `date_add` ASC, `id_cmp_log` ASC'
        ) ?: [];

        // Sortowanie rosnąco i nadpisywanie w pętli daje ostatni wiersz na kod,
        // tak samo jak `obowiazujaceWersje()` — bez `GROUP BY` i jego pułapek
        // przy włączonym `ONLY_FULL_GROUP_BY`.
        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['code']] = [
                'granted' => (bool) $wiersz['granted'],
                'date_add' => (string) $wiersz['date_add'],
                'source' => (string) $wiersz['source'],
            ];
        }

        return $out;
    }

    /** Adres klienta z uwzględnieniem nagłówków proxy, przez `Tools::getRemoteAddr()`. */
    private static function adresIp(): string
    {
        return substr((string) \Tools::getRemoteAddr(), 0, 45);
    }

    private static function przegladarka(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}
