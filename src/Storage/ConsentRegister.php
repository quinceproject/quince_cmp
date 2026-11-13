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
 * Odczyt dziennika zgód na potrzeby panelu.
 *
 * Rozdzielone od `ConsentLog`, który wyłącznie dopisuje zdarzenia. Zakres wyniku
 * ogranicza lista sklepów przekazana przez wywołującego; pochodzi ona z kontekstu
 * sklepu w panelu.
 */
final class ConsentRegister
{
    /** Ile zdarzeń na stronę. */
    public const NA_STRONE = 50;

    /** @var array<int,string> nazwiska odczytane w tym przebiegu */
    private array $pracownicy = [];

    /**
     * Strumień zdarzeń od najnowszego, a z `afterId` w filtrach rosnąco
     * po kluczu od wskazanego miejsca. Drugi tryb jest kursorem
     * do odpytywania przyrostowego (Admin API): `strona`/`offset` nie mają
     * tam zastosowania, bo kursor sam pamięta, gdzie się skończyło.
     *
     * @param array<int,int> $idShops
     * @param array{email?:string,code?:string,granted?:string,source?:string,from?:string,to?:string,afterId?:string} $filtry
     *
     * @return array<int,array<string,mixed>>
     */
    public function stream(array $idShops, array $filtry, int $strona = 1): array
    {
        $warunek = $this->warunek($idShops, $filtry);
        $kursor = trim((string) ($filtry['afterId'] ?? '')) !== '';
        $offset = $kursor ? 0 : max(0, ($strona - 1) * self::NA_STRONE);
        $kolejnosc = $kursor ? 'l.`id_cmp_log` ASC' : 'l.`date_add` DESC, l.`id_cmp_log` DESC';

        $wiersze = Db::getInstance()->executeS(
            'SELECT l.*, s.`name` AS `shop_name`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
             LEFT JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = l.`id_shop`
             WHERE ' . $warunek . '
             ORDER BY ' . $kolejnosc . '
             LIMIT ' . self::NA_STRONE . ' OFFSET ' . $offset
        ) ?: [];

        $stitched = $this->doszycia(array_column($wiersze, 'visitor_key'));

        $out = [];

        foreach ($wiersze as $wiersz) {
            $klucz = (string) $wiersz['visitor_key'];

            $out[] = [
                'id_cmp_log' => (int) $wiersz['id_cmp_log'],
                'date_add' => (string) $wiersz['date_add'],
                // Ekran pokazuje nazwę sklepu, ale API musi oddać identyfikator:
                // nazwę da się zmienić, identyfikator jest tym, po czym system
                // zewnętrzny rozpozna, którego sklepu dotyczy zdarzenie.
                'id_shop' => (int) $wiersz['id_shop'],
                'shop_name' => (string) ($wiersz['shop_name'] ?? ''),
                'email' => (string) $wiersz['email'],
                'visitor_key' => $klucz,
                'code' => (string) $wiersz['code'],
                'version' => (string) $wiersz['version'],
                'text_hash' => (string) $wiersz['text_hash'],
                'granted' => (bool) $wiersz['granted'],
                'source' => (string) $wiersz['source'],
                'origin' => (string) $wiersz['origin'],
                'external_id' => (string) ($wiersz['external_id'] ?? ''),
                'ip' => (string) $wiersz['ip'],
                'stitched' => $stitched[$klucz]['date'] ?? null,
                'stitched_email' => $stitched[$klucz]['email'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * @param array<int,int> $idShops
     * @param array<string,string> $filtry
     */
    public function count(array $idShops, array $filtry): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
             WHERE ' . $this->warunek($idShops, $filtry)
        );
    }

    /**
     * Kody występujące w dzienniku, do listy filtra.
     *
     * Źródłem jest dziennik, nie konfiguracja: kod usuniętego rodzaju zgody nadal
     * występuje w historii i musi być wyszukiwalny.
     *
     * @param array<int,int> $idShops
     *
     * @return array<int,string>
     */
    public function uzyteKody(array $idShops): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT `code` FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `id_shop` IN (' . $this->sklepy($idShops) . ')
             ORDER BY `code` ASC'
        ) ?: [];

        return array_map(static fn (array $w): string => (string) $w['code'], $wiersze);
    }

    /**
     * @param array<int,int> $idShops
     *
     * @return array<int,string>
     */
    public function uzyteZrodla(array $idShops): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT `source` FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `id_shop` IN (' . $this->sklepy($idShops) . ') AND `source` <> ""
             ORDER BY `source` ASC'
        ) ?: [];

        return array_map(static fn (array $w): string => (string) $w['source'], $wiersze);
    }

    /**
     * Zdarzenia pasujące do filtrów, porcjami po 500 wierszy.
     *
     * Generator zamiast tablicy: eksport obejmuje cały dziennik, którego rozmiar
     * nie jest ograniczony.
     *
     * @param array<int,int> $idShops
     * @param array<string,string> $filtry
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function exportRows(array $idShops, array $filtry): \Generator
    {
        $warunek = $this->warunek($idShops, $filtry);
        $strona = 0;

        do {
            $wiersze = Db::getInstance()->executeS(
                'SELECT l.*, s.`name` AS `shop_name`
                 FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
                 LEFT JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = l.`id_shop`
                 WHERE ' . $warunek . '
                 ORDER BY l.`id_cmp_log` ASC
                 LIMIT 500 OFFSET ' . ($strona * 500)
            ) ?: [];

            foreach ($wiersze as $wiersz) {
                yield $wiersz;
            }

            ++$strona;
        } while (count($wiersze) === 500);
    }

    /**
     * Zdarzenie wraz z treścią, która była wtedy wyświetlona.
     *
     * @param array<int,int> $idShops
     *
     * @return array<string,mixed>|null
     */
    public function event(int $idLog, array $idShops): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT l.*, s.`name` AS `shop_name`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
             LEFT JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = l.`id_shop`
             WHERE l.`id_cmp_log` = ' . $idLog . ' AND l.`id_shop` IN (' . $this->sklepy($idShops) . ')'
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return null;
        }

        // Wyszukiwanie po skrócie, nie po numerze wersji: numer nie jest unikalny
        // w skali instalacji, skrót jest.
        $tresc = Db::getInstance()->getRow(
            'SELECT `short_label`, `full_clause`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '`
             WHERE `text_hash` = "' . pSQL((string) $wiersz['text_hash']) . '"'
        );

        $wiersz['short_label'] = is_array($tresc) ? (string) ($tresc['short_label'] ?? '') : '';
        $wiersz['full_clause'] = is_array($tresc) ? (string) ($tresc['full_clause'] ?? '') : '';

        return $wiersz;
    }

    /**
     * @param array<int,int> $idShops sklepy jednego zasięgu tożsamości
     *
     * @return array{codes:array<int,string>,cells:array<string,array<int,array<string,mixed>>>}
     */
    public function matrix(string $email, array $idShops): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT l.`id_cmp_log`, l.`code`, l.`id_shop`, l.`granted`, l.`version`, l.`date_add`,
                    l.`source`, l.`ip`, l.`user_agent`, l.`text_hash`, l.`visitor_key`, l.`email`, l.`origin`,
                    l.`id_employee`, l.`note`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
             INNER JOIN (
                SELECT `code`, `id_shop`, MAX(`id_cmp_log`) AS `ostatnie`
                FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
                WHERE ' . self::tejOsoby($email, '') . ' AND `id_shop` IN (' . $this->sklepy($idShops) . ')
                GROUP BY `code`, `id_shop`
             ) o ON o.`ostatnie` = l.`id_cmp_log`
             ORDER BY l.`code` ASC'
        ) ?: [];

        $tresci = $this->tresciPoSkrocie(array_column($wiersze, 'text_hash'));
        $biezace = $this->wersjeBiezace($idShops);

        $kody = [];
        $komorki = [];

        foreach ($wiersze as $wiersz) {
            $kod = (string) $wiersz['code'];
            $idShop = (int) $wiersz['id_shop'];
            $skrot = (string) $wiersz['text_hash'];
            $wersja = (string) $wiersz['version'];
            $obowiazujaca = (string) ($biezace[$idShop][$kod] ?? '');

            $kody[$kod] = true;

            $komorki[$kod][$idShop] = [
                'id_cmp_log' => (int) $wiersz['id_cmp_log'],
                'granted' => (bool) $wiersz['granted'],
                'version' => $wersja,
                'date' => (string) $wiersz['date_add'],
                'source' => (string) $wiersz['source'],
                'origin' => (string) $wiersz['origin'],
                'ip' => (string) $wiersz['ip'],
                'user_agent' => (string) $wiersz['user_agent'],
                'text_hash' => $skrot,
                'visitor_key' => (string) $wiersz['visitor_key'],
                'anonymous' => (string) $wiersz['email'] === '',
                'short_label' => $tresci[$skrot]['short_label'] ?? '',
                'full_clause' => $tresci[$skrot]['full_clause'] ?? '',
                'note' => (string) $wiersz['note'],
                'staff' => $this->pracownik((int) $wiersz['id_employee']),
                'current_version' => $obowiazujaca,
                // Zgoda na treść, która od tamtej pory się zmieniła. To jedyny
                // powód, dla którego trzeba kogoś zapytać ponownie, więc widok
                // ma to mówić wprost, a nie kazać porównywać dwa numery.
                'outdated' => $wersja !== '' && $obowiazujaca !== '' && $wersja !== $obowiazujaca,
            ];
        }

        return ['codes' => array_keys($kody), 'cells' => $komorki];
    }

    /**
     * Nazwy rodzajów zgód, po kodzie.
     *
     * Kod jest identyfikatorem, nie napisem dla człowieka. W widoku ma stać
     * obok nazwy, nie zamiast niej.
     *
     * @param array<int,int> $idShops
     *
     * @return array<string,array{name:string,kind:string,required:bool}>
     */
    public function nazwy(array $idShops, int $idLang): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`code`, t.`kind`, t.`required`, tl.`name`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
             LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE_LANG . '` tl
                    ON tl.`id_cmp_type` = t.`id_cmp_type` AND tl.`id_lang` = ' . $idLang . '
             WHERE t.`id_shop` IN (' . $this->sklepy($idShops) . ')'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $kod = (string) $wiersz['code'];

            if (!isset($out[$kod]) || $out[$kod]['name'] === '') {
                $out[$kod] = [
                    'name' => (string) ($wiersz['name'] ?? ''),
                    'kind' => (string) $wiersz['kind'],
                    'required' => (bool) $wiersz['required'],
                ];
            }
        }

        return $out;
    }

    /**
     * Imię i nazwisko pracownika, który wpisał zdarzenie.
     *
     * Pobieramy je przy odczycie, a nie zapisujemy w dzienniku, bo zmiana
     * nazwiska w koncie ma zmienić je wszędzie. Identyfikator zostaje w wierszu
     * i to on jest dowodem, a nazwisko jest tylko jego czytelną postacią.
     * Skasowane konto zostawia sam numer.
     *
     * @return array{id:int,name:string}
     */
    private function pracownik(int $idEmployee): array
    {
        if ($idEmployee <= 0) {
            return ['id' => 0, 'name' => ''];
        }

        if (!isset($this->pracownicy[$idEmployee])) {
            $wiersz = Db::getInstance()->getRow(
                'SELECT `firstname`, `lastname` FROM `' . _DB_PREFIX_ . 'employee`
                  WHERE `id_employee` = ' . $idEmployee
            );

            $this->pracownicy[$idEmployee] = is_array($wiersz) && $wiersz !== []
                ? trim($wiersz['firstname'] . ' ' . $wiersz['lastname'])
                : '';
        }

        return ['id' => $idEmployee, 'name' => $this->pracownicy[$idEmployee]];
    }

    /**
     * Wersja obowiązująca dziś, dla każdego sklepu i kodu.
     *
     * @param array<int,int> $idShops
     *
     * @return array<int,array<string,string>>
     */
    private function wersjeBiezace(array $idShops): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`id_shop`, t.`code`, v.`version`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
             INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v ON v.`id_cmp_type` = t.`id_cmp_type`
             WHERE t.`id_shop` IN (' . $this->sklepy($idShops) . ') AND v.`date_from` <= NOW()
             ORDER BY v.`date_from` ASC, v.`id_cmp_version` ASC'
        ) ?: [];

        $out = [];

        // Kolejność rosnąca i nadpisywanie zostawiają w tablicy wersję najnowszą.
        foreach ($wiersze as $wiersz) {
            $out[(int) $wiersz['id_shop']][(string) $wiersz['code']] = (string) $wiersz['version'];
        }

        return $out;
    }

    /**
     * Treść klauzul po skrócie.
     *
     * Skrót, nie numer wersji: numer nie jest unikalny w skali instalacji,
     * skrót jest. Ten sam skrót w kilku sklepach oznacza tę samą treść.
     *
     * @param array<int,string> $skroty
     *
     * @return array<string,array{short_label:string,full_clause:string}>
     */
    private function tresciPoSkrocie(array $skroty): array
    {
        $czyste = array_filter(array_unique(array_map('strval', $skroty)));

        if ($czyste === []) {
            return [];
        }

        $wpisane = implode(',', array_map(static fn (string $h): string => '"' . pSQL($h) . '"', $czyste));

        $wiersze = Db::getInstance()->executeS(
            'SELECT `text_hash`, `short_label`, `full_clause`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '`
             WHERE `text_hash` IN (' . $wpisane . ')'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['text_hash']] = [
                'short_label' => (string) $wiersz['short_label'],
                'full_clause' => (string) $wiersz['full_clause'],
            ];
        }

        return $out;
    }

    /**
     * @param array<int,int> $idShops
     *
     * @return array<int,array<string,mixed>>
     */
    public function timeline(string $email, array $idShops, int $limit = 100): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT l.*, s.`name` AS `shop_name`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
             LEFT JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = l.`id_shop`
             WHERE ' . self::tejOsoby($email) . ' AND l.`id_shop` IN (' . $this->sklepy($idShops) . ')
             ORDER BY l.`date_add` DESC, l.`id_cmp_log` DESC
             LIMIT ' . $limit
        ) ?: [];

        // Treść dołączamy tutaj, a nie osobnym odczytem przy rozwinięciu wiersza:
        // szczegół ma się otwierać bez sięgania do serwera.
        $tresci = $this->tresciPoSkrocie(array_column($wiersze, 'text_hash'));

        foreach ($wiersze as $i => $wiersz) {
            $skrot = (string) $wiersz['text_hash'];
            $wiersze[$i]['short_label'] = $tresci[$skrot]['short_label'] ?? '';
            $wiersze[$i]['full_clause'] = $tresci[$skrot]['full_clause'] ?? '';
            $wiersze[$i]['staff'] = $this->pracownik((int) ($wiersz['id_employee'] ?? 0));
        }

        return $wiersze;
    }

    /** @return array<int,array{visitor_key:string,date_stitched:?string}> */
    public function visitorKeys(string $email): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `visitor_key`, `date_stitched` FROM `' . _DB_PREFIX_ . Schema::TABLE_VISITOR . '`
             WHERE `email` = "' . pSQL($email) . '"
             ORDER BY `date_add` ASC'
        ) ?: [];

        return array_map(static fn (array $w): array => [
            'visitor_key' => (string) $w['visitor_key'],
            'date_stitched' => $w['date_stitched'] ?: null,
        ], $wiersze);
    }

    /**
     * @param array<int,int> $idShops
     * @param array<string,string> $filtry
     */
    private function warunek(array $idShops, array $filtry): string
    {
        $czesci = ['l.`id_shop` IN (' . $this->sklepy($idShops) . ')'];

        if (($filtry['email'] ?? '') !== '') {
            $czesci[] = self::tejOsoby($filtry['email'], 'l.', true);
        }

        if (($filtry['visitor_key'] ?? '') !== '') {
            $czesci[] = 'l.`visitor_key` LIKE "%' . pSQL($filtry['visitor_key']) . '%"';
        }

        if (($filtry['code'] ?? '') !== '') {
            $czesci[] = 'l.`code` = "' . pSQL($filtry['code']) . '"';
        }

        if (($filtry['origin'] ?? '') !== '') {
            $czesci[] = 'l.`origin` = "' . pSQL($filtry['origin']) . '"';
        }

        // Zawężenie do jednego sklepu **wewnątrz** kontekstu, nie zamiast niego.
        // Obsługa nie może filtrem wyjść poza sklepy, do których ma dostęp.
        if (($filtry['shop'] ?? '') !== '' && in_array((int) $filtry['shop'], array_map('intval', $idShops), true)) {
            $czesci[] = 'l.`id_shop` = ' . (int) $filtry['shop'];
        }

        if (($filtry['source'] ?? '') !== '') {
            $czesci[] = 'l.`source` = "' . pSQL($filtry['source']) . '"';
        }

        if (($filtry['granted'] ?? '') !== '') {
            $czesci[] = 'l.`granted` = ' . ((int) ($filtry['granted'] === 'granted'));
        }

        if (($filtry['from'] ?? '') !== '') {
            $czesci[] = 'l.`date_add` >= "' . pSQL($filtry['from']) . ' 00:00:00"';
        }

        if (($filtry['to'] ?? '') !== '') {
            $czesci[] = 'l.`date_add` <= "' . pSQL($filtry['to']) . ' 23:59:59"';
        }

        if (($filtry['afterId'] ?? '') !== '') {
            $czesci[] = 'l.`id_cmp_log` > ' . (int) $filtry['afterId'];
        }

        return implode(' AND ', $czesci);
    }

    /**
     * Warunek „to zdarzenie należy do tej osoby".
     *
     * Sam adres nie wystarczy. Zgoda kliknięta w banerze przez gościa zapisuje
     * się bez adresu, bo adresu wtedy nie ma. Dopiero późniejszy zakup albo
     * rejestracja wiąże klucz przeglądarki z osobą. Filtrowanie po samym adresie
     * gubiłoby wszystko sprzed tego momentu, czyli zwykle cały wybór ciasteczek.
     *
     * Klucze bierzemy podzapytaniem, a nie osobnym odczytem: warunek trafia do
     * kilku zapytań i lista kluczy musi być tam ta sama, także gdy w międzyczasie
     * dopisze się nowe połączenie.
     *
     * @param string $alias   przedrostek kolumn, pusty w podzapytaniu bez aliasu
     * @param bool   $czesciowo dopasowanie fragmentu adresu, dla pola wyszukiwania
     */
    private static function tejOsoby(string $email, string $alias = 'l.', bool $czesciowo = false): string
    {
        // Pusty adres pasowałby do każdego zdarzenia gościa, bo te mają puste
        // pole adresu. Warunek nie do spełnienia jest tu jedyną bezpieczną
        // odpowiedzią: profil nikogo nie może pokazywać wszystkich.
        if ($email === '') {
            return '0';
        }

        $porownanie = $czesciowo
            ? 'LIKE "%' . pSQL($email) . '%"'
            : '= "' . pSQL($email) . '"';

        return '(' . $alias . '`email` ' . $porownanie
            . ' OR ' . $alias . '`visitor_key` IN (
                    SELECT `visitor_key` FROM `' . _DB_PREFIX_ . Schema::TABLE_VISITOR . '`
                     WHERE `email` ' . $porownanie . '))';
    }

    /**
     * Lista identyfikatorów do klauzuli `IN`. Dla pustej listy zwraca `0`,
     * ponieważ `IN ()` jest błędem składni MySQL.
     *
     * @param array<int,int> $idShops
     */
    private function sklepy(array $idShops): string
    {
        $lista = array_map('intval', $idShops);

        return $lista === [] ? '0' : implode(',', $lista);
    }

    /**
     * Klucze przeglądarek, o których wiemy, do kogo należą.
     *
     * Adres wraca razem z datą, bo wiersz bez adresu w dzienniku i tak prowadzi
     * do konkretnej osoby, jeśli połączenie zostało zapisane później.
     *
     * @param array<int,string> $klucze
     *
     * @return array<string,array{date:string,email:string}>
     */
    private function doszycia(array $klucze): array
    {
        $klucze = array_filter(array_unique($klucze));

        if ($klucze === []) {
            return [];
        }

        $wpisane = implode(',', array_map(static fn (string $k): string => '"' . pSQL($k) . '"', $klucze));

        $wiersze = Db::getInstance()->executeS(
            'SELECT `visitor_key`, `date_stitched`, `email` FROM `' . _DB_PREFIX_ . Schema::TABLE_VISITOR . '`
             WHERE `visitor_key` IN (' . $wpisane . ') AND `date_stitched` IS NOT NULL'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['visitor_key']] = [
                'date' => (string) $wiersz['date_stitched'],
                'email' => (string) $wiersz['email'],
            ];
        }

        return $out;
    }
}
