<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Report;

use Db;
use Quince\Cmp\Storage\ConsentVersions;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Schema;
use Shop;

/**
 * Dane ekranu przeglądu: stan wdrożenia, diagnostyka, statystyka decyzji
 * i ostatnia synchronizacja.
 *
 * Stan wdrożenia raportowany jest osobno dla każdego sklepu w bieżącym
 * kontekście, ponieważ konfiguracja należy do sklepu. Rozróżniane są trzy stany:
 * poprawny, wymagający uwagi oraz nieskonfigurowany.
 */
final class Deployment
{
    /** Cztery sygnały Consent Mode v2. Implementacja wysyłająca dwa jest niepełna. */
    public const SIGNALS = [
        'ad_storage' => 'cm_ad_storage',
        'analytics_storage' => 'cm_analytics_storage',
        'ad_user_data' => 'cm_ad_user_data',
        'ad_personalization' => 'cm_ad_personalization',
    ];

    public const STATE_OK = 'ok';
    public const STATE_WARN = 'warn';
    public const STATE_OFF = 'off';

    /**
     * Sklepy bieżącego kontekstu wraz ze stanem każdego sprawdzenia.
     *
     * @return array<int,array{id_shop:int,name:string,cells:array<int,array{key:string,label:string,value:string,state:string}>}>
     */
    public function rows(): array
    {
        $out = [];

        foreach ($this->sklepy() as $idShop => $nazwa) {
            $out[] = [
                'id_shop' => $idShop,
                'name' => $nazwa,
                'cells' => [
                    $this->banner($idShop),
                    $this->blocking($idShop),
                    $this->consentMode($idShop),
                    $this->policy($idShop),
                ],
            ];
        }

        return $out;
    }

    /**
     * Nazwy kolumn — ekran bierze je stąd, żeby nagłówek nie rozjechał się z treścią.
     *
     * @return array<int,string>
     */
    public function columns(): array
    {
        return ['Banner', 'Script blocking', 'Consent Mode', 'Privacy policy'];
    }

    /**
     * @return array<int,array{level:string,text:string,action:?string,route:?string,routeParams?:array<string,int|string>}>
     */
    public function diagnostics(): array
    {
        $out = [];

        foreach ($this->sklepy() as $idShop => $nazwa) {
            $pokryte = $this->policzPokryteSygnaly($idShop);

            if ($this->policzAktywneSkrypty($idShop) > 0 && $pokryte < count(self::SIGNALS)) {
                $out[] = [
                    'level' => 'warn',
                    'text' => 'Consent Mode on %shop% covers %covered% of %all% signals. Google treats a missing signal as no consent at all.',
                    'params' => [
                        '%shop%' => $nazwa,
                        '%covered%' => $pokryte,
                        '%all%' => count(self::SIGNALS),
                    ],
                    'action' => 'Fix',
                    'route' => 'quince_cmp_scripts',
                    'routeParams' => [],
                ];
            }

            foreach ($this->rozjechaneDokumenty($idShop, $nazwa) as $znalezisko) {
                $out[] = $znalezisko;
            }

            foreach ($this->pozostaleUsterki($idShop, $nazwa) as $znalezisko) {
                $out[] = $znalezisko;
            }
        }

        return $out;
    }

    /**
     * Ustawienia, które w zestawieniu nie trzymają się kupy.
     *
     * Każde z osobna wygląda niewinnie; razem znaczą, że sklep zbiera zgody,
     * których nie ma jak wyegzekwować, albo egzekwuje coś, o co nie pyta.
     *
     * @return array<int,array{level:string,text:string,action:?string,route:?string,routeParams?:array<string,int|string>}>
     */
    private function pozostaleUsterki(int $idShop, string $nazwa): array
    {
        $out = [];

        $banerWlaczony = Options::bool(Options::BANNER_ON, false, $idShop);
        $blokowanie = Options::bool(Options::BLOCKING_ON, true, $idShop);
        $skrypty = $this->policzAktywneSkrypty($idShop);

        if ($banerWlaczony && !$blokowanie) {
            $out[] = $this->usterka(
                'danger',
                '%shop% asks for consent but blocks nothing. Every script runs before the visitor answers, so the banner is decoration.',
                'Settings',
                'quince_cmp_settings',
                ['%shop%' => $nazwa]
            );
        }

        if (!$banerWlaczony && $skrypty > 0) {
            $out[] = $this->usterka(
                'warn',
                '%shop% holds %count% services back but never asks. Nobody can release them.',
                'Banner',
                'quince_cmp_banner',
                ['%shop%' => $nazwa, '%count%' => $skrypty]
            );
        }

        if ($banerWlaczony && !$this->brakBanera($idShop)) {
            $out[] = $this->usterka(
                'warn',
                'The banner on %shop% has no privacy policy page set. The visitor cannot read what they are agreeing to.',
                'Settings',
                'quince_cmp_settings',
                ['%shop%' => $nazwa]
            );
        }

        if (Options::bool(Options::TEST_MODE, false, $idShop)) {
            $out[] = $this->usterka(
                'warn',
                'Test mode is on for %shop%: the banner is shown only to the listed IP addresses. Everyone else sees nothing.',
                'Settings',
                'quince_cmp_settings',
                ['%shop%' => $nazwa]
            );
        }

        // Kategoria bez ani jednego ciasteczka jest pytaniem o zgodę na nic.
        foreach ($this->pusteKategorie($idShop) as $kategoria) {
            $out[] = $this->usterka(
                'warn',
                'Category "%category%" on %shop% lists no cookies. The preference window shows an empty entry.',
                'Cookies',
                'quince_cmp_cookies',
                ['%category%' => $kategoria, '%shop%' => $nazwa]
            );
        }

        // Usługa bez kategorii nie ma czyjej zgody czekać — nic jej nie zwolni.
        $bezKategorii = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '`
              WHERE `id_shop` = ' . $idShop . ' AND `active` = 1 AND (`category` = "" OR `category` IS NULL)'
        );

        if ($bezKategorii > 0) {
            $out[] = $this->usterka(
                'danger',
                '%count% services on %shop% have no category. Nothing will ever release them.',
                'Scripts',
                'quince_cmp_scripts',
                ['%count%' => $bezKategorii, '%shop%' => $nazwa]
            );
        }

        // Zgody zebrane bez wydanej wersji nie mają dowodu treści.
        $bezWersji = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
              WHERE `id_shop` = ' . $idShop . ' AND `text_hash` = ""'
        );

        if ($bezWersji > 0) {
            $out[] = $this->usterka(
                'warn',
                '%count% consents on %shop% were recorded with no published wording. They prove a click, not what was agreed to.',
                'Consent types',
                'quince_cmp_types',
                ['%count%' => $bezWersji, '%shop%' => $nazwa]
            );
        }

        return $out;
    }

    /** Czy baner ma wskazaną stronę polityki prywatności. */
    private function brakBanera(int $idShop): bool
    {
        return Options::int(Options::POLICY_CMS, 0, $idShop) > 0;
    }

    /**
     * Kategorie ciasteczkowe, do których nie przypisano ani jednego ciasteczka.
     *
     * @return array<int,string>
     */
    private function pusteKategorie(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`code`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
              WHERE t.`id_shop` = ' . $idShop . '
                AND t.`kind` = "' . Schema::KIND_COOKIE . '"
                AND t.`active` = 1
                AND NOT EXISTS (
                    SELECT 1 FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '` c
                     WHERE c.`id_shop` = t.`id_shop` AND c.`category` = t.`code` AND c.`active` = 1
                )'
        ) ?: [];

        return array_map(static fn (array $w): string => (string) $w['code'], $wiersze);
    }

    /**
     * @return array{level:string,text:string,action:?string,route:?string,routeParams?:array<string,int|string>}
     */
    /**
     * Jedno znalezisko.
     *
     * `$tekst` jest **kluczem tlumaczenia**, nie gotowym zdaniem: wartosci wchodza
     * osobno, w `$podstawienia`. Komunikat sklejony tutaj docieralby do szablonu
     * juz z liczbami w srodku i zaden katalog by go nie rozpoznal.
     *
     * @param array<string,string|int> $podstawienia
     *
     * @return array{level:string,text:string,params:array<string,string|int>,action:?string,route:?string,routeParams:array<string,int|string>}
     */
    private function usterka(string $poziom, string $tekst, string $gdzie, string $trasa, array $podstawienia = []): array
    {
        return [
            'level' => $poziom,
            'text' => $tekst,
            'params' => $podstawienia,
            'action' => $gdzie,
            'route' => $trasa,
            'routeParams' => [],
        ];
    }

    /**
     * Zgody dokumentowe, których strona CMS zmieniła się po wydaniu wersji.
     *
     * @return array<int,array{level:string,text:string,action:?string,route:?string,routeParams?:array<string,int|string>}>
     */
    private function rozjechaneDokumenty(int $idShop, string $nazwaSklepu): array
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`id_cmp_type`, t.`code`, t.`id_cms`, v.`version`, vl.`text_hash`,
                    (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '` l
                      WHERE l.`code` = t.`code` AND l.`id_shop` = t.`id_shop`
                        AND l.`version` = v.`version` AND l.`granted` = 1) AS `zebrane`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
             INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v ON v.`id_cmp_type` = t.`id_cmp_type`
             INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '` vl
                     ON vl.`id_cmp_version` = v.`id_cmp_version` AND vl.`id_lang` = ' . $idLang . '
             WHERE t.`id_shop` = ' . $idShop . ' AND t.`kind` = "' . Schema::KIND_DOCUMENT . '"
               AND t.`id_cms` > 0 AND v.`date_from` <= NOW() AND v.`date_to` IS NULL'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $strona = new \CMS((int) $wiersz['id_cms'], $idLang);

            if (!$strona->id) {
                continue;
            }

            $zapisany = (string) $wiersz['text_hash'];

            // Skrót obejmuje krótki podpis i treść łącznie, więc porównanie wymaga
            // policzenia go w ten sam sposób.
            $krotki = (string) Db::getInstance()->getValue(
                'SELECT `short_label` FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION_LANG . '`
                 WHERE `text_hash` = "' . pSQL($zapisany) . '"'
            );

            $teraz = (string) $strona->meta_title . "\n" . (string) $strona->content;

            if (ConsentVersions::hash($krotki, $teraz) === $zapisany) {
                continue;
            }

            $out[] = [
                'level' => 'danger',
                'text' => '%code% on %shop% was edited after version %version% was published: %count% consents reference text that no longer matches.',
                'params' => [
                    '%code%' => $wiersz['code'],
                    '%shop%' => $nazwaSklepu,
                    '%version%' => $wiersz['version'],
                    '%count%' => (int) $wiersz['zebrane'],
                ],
                'action' => 'Publish a new version',
                'route' => 'quince_cmp_type_edit',
                'routeParams' => ['idType' => (int) $wiersz['id_cmp_type']],
            ];
        }

        return $out;
    }

    /**
     * Statystyka decyzji z banera z ostatnich 30 dni. Za jedną decyzję uznaje się
     * komplet zdarzeń zapisanych w tej samej sekundzie dla tego samego gościa.
     *
     * @return array{accept:int,reject:int,custom:int,total:int,withdrawals:int}
     */
    public function decisions(): array
    {
        $sklepy = implode(',', array_keys($this->sklepy())) ?: '0';

        $grupy = Db::getInstance()->executeS(
            'SELECT SUM(`granted`) AS `tak`, COUNT(*) AS `ile`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `id_shop` IN (' . $sklepy . ') AND `source` = "banner"
               AND `date_add` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY `visitor_key`, `date_add`'
        ) ?: [];

        $accept = $reject = $custom = 0;

        foreach ($grupy as $grupa) {
            $tak = (int) $grupa['tak'];
            $ile = (int) $grupa['ile'];

            if ($tak === $ile) {
                ++$accept;
            } elseif ($tak === 0) {
                ++$reject;
            } else {
                ++$custom;
            }
        }

        $cofniecia = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
             WHERE `id_shop` IN (' . $sklepy . ') AND `granted` = 0
               AND `date_add` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );

        return [
            'accept' => $accept,
            'reject' => $reject,
            'custom' => $custom,
            'total' => $accept + $reject + $custom,
            'withdrawals' => $cofniecia,
        ];
    }

    /**
     * @return array{date:string,sent:int,accepted:int,failed:int}|null
     */
    public function lastSync(): ?array
    {
        $sklepy = implode(',', array_keys($this->sklepy())) ?: '0';

        $ostatnia = Db::getInstance()->getValue(
            'SELECT MAX(`date_upd`) FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
             WHERE `id_shop` IN (' . $sklepy . ')'
        );

        if (!$ostatnia) {
            return null;
        }

        $wiersz = Db::getInstance()->getRow(
            'SELECT
                SUM(`direction` = "' . Schema::DIRECTION_OUT . '" AND `status` = "sent") AS `wyslane`,
                SUM(`direction` = "' . Schema::DIRECTION_IN . '") AS `przyjete`,
                SUM(`status` = "failed") AS `nieudane`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_SYNC . '`
             WHERE `id_shop` IN (' . $sklepy . ')'
        );

        return [
            'date' => (string) $ostatnia,
            'sent' => (int) ($wiersz['wyslane'] ?? 0),
            'accepted' => (int) ($wiersz['przyjete'] ?? 0),
            'failed' => (int) ($wiersz['nieudane'] ?? 0),
        ];
    }

    /**
     * @return array<int,string> id_shop => nazwa
     */
    private function sklepy(): array
    {
        $lista = Shop::getContextListShopID();
        $out = [];

        foreach ($lista as $idShop) {
            $sklep = new Shop((int) $idShop);
            $out[(int) $idShop] = (string) $sklep->name;
        }

        return $out;
    }

    /**
     * @return array{key:string,label:string,value:string,state:string}
     */
    private function banner(int $idShop): array
    {
        if (!Options::czyUstawione(Options::BANNER_ON, $idShop)) {
            return ['key' => 'banner', 'label' => 'Banner', 'value' => 'Not set up yet', 'state' => self::STATE_OFF];
        }

        $wlaczony = Options::bool(Options::BANNER_ON, false, $idShop);

        return [
            'key' => 'banner',
            'label' => 'Banner',
            'value' => $wlaczony ? 'On' : 'Off',
            'state' => $wlaczony ? self::STATE_OK : self::STATE_WARN,
        ];
    }

    /**
     * Stan blokowania skryptów. Liczone są usługi objęte wstrzymaniem, nie sam
     * stan przełącznika.
     *
     * @return array{key:string,label:string,value:string,state:string}
     */
    private function blocking(int $idShop): array
    {
        $ile = $this->policzAktywneSkrypty($idShop);

        if ($ile === 0) {
            return [
                'key' => 'blocking',
                'label' => 'Script blocking',
                'value' => 'No services yet',
                'state' => self::STATE_OFF,
            ];
        }

        $wlaczone = Options::bool(Options::BLOCKING_ON, false, $idShop);

        return [
            'key' => 'blocking',
            'label' => 'Script blocking',
            'value' => $wlaczone ? 'On (%count% services)' : 'Off (%count% waiting)',
            'params' => ['%count%' => $ile],
            'state' => $wlaczone ? self::STATE_OK : self::STATE_WARN,
        ];
    }

    /**
     * Pokrycie sygnałów Consent Mode przez kategorie sklepu. Niepełne pokrycie
     * traktowane jest jako ostrzeżenie — brakujący sygnał Google interpretuje
     * jako brak zgody.
     *
     * @return array{key:string,label:string,value:string,state:string}
     */
    private function consentMode(int $idShop): array
    {
        $pokryte = $this->policzPokryteSygnaly($idShop);
        $wszystkie = count(self::SIGNALS);

        return [
            'key' => 'consent_mode',
            'label' => 'Consent Mode',
            'value' => '%covered% of %all% signals',
            'params' => ['%covered%' => $pokryte, '%all%' => $wszystkie],
            'state' => match (true) {
                $pokryte === $wszystkie => self::STATE_OK,
                $pokryte === 0 => self::STATE_OFF,
                default => self::STATE_WARN,
            },
        ];
    }

    /**
     * @return array{key:string,label:string,value:string,state:string}
     */
    private function policy(int $idShop): array
    {
        $wersja = $this->aktualnaWersja('privacy_policy', $idShop);

        if ($wersja === null) {
            return [
                'key' => 'policy',
                'label' => 'Privacy policy',
                'value' => 'Not published',
                'state' => self::STATE_OFF,
            ];
        }

        return [
            'key' => 'policy',
            'label' => 'Privacy policy',
            'value' => '%version% · %date%',
            'params' => ['%version%' => $wersja['version'], '%date%' => substr($wersja['date_from'], 0, 10)],
            'state' => self::STATE_OK,
        ];
    }

    private function policzAktywneSkrypty(int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '`
             WHERE `active` = 1 AND `id_shop` = ' . $idShop
        );
    }

    /** Liczba sygnałów ustawianych przez co najmniej jedną aktywną usługę. */
    private function policzPokryteSygnaly(int $idShop): int
    {
        $sumy = [];

        foreach (self::SIGNALS as $sygnal => $kolumna) {
            $sumy[] = 'SUM(`' . $kolumna . '`) AS `' . $sygnal . '`';
        }

        $wiersz = Db::getInstance()->getRow(
            'SELECT ' . implode(', ', $sumy)
            . ' FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
               WHERE `active` = 1 AND `kind` = "' . Schema::KIND_COOKIE . '" AND `id_shop` = ' . $idShop
        );

        if (!is_array($wiersz)) {
            return 0;
        }

        $pokryte = 0;

        foreach (array_keys(self::SIGNALS) as $sygnal) {
            if ((int) ($wiersz[$sygnal] ?? 0) > 0) {
                ++$pokryte;
            }
        }

        return $pokryte;
    }

    /**
     * Wersja obowiązująca w chwili wywołania. Filtr po sklepie stosowany jest na
     * tabeli rodzajów zgód, ponieważ tabela wersji nie ma `id_shop`.
     *
     * @return array{version:string,date_from:string}|null
     */
    private function aktualnaWersja(string $code, int $idShop): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT v.`version`, v.`date_from`
             FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v
             INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t ON t.`id_cmp_type` = v.`id_cmp_type`
             WHERE t.`code` = "' . pSQL($code) . '" AND t.`id_shop` = ' . $idShop . '
               AND v.`date_from` <= NOW()
             ORDER BY v.`date_from` DESC'
            // Bez `LIMIT 1` — `Db::getRow()` dokłada je samo, a dwa pod rząd
            // to błąd składni MySQL-a.
        );

        if (!is_array($wiersz) || $wiersz === []) {
            return null;
        }

        return ['version' => (string) $wiersz['version'], 'date_from' => (string) $wiersz['date_from']];
    }
}
