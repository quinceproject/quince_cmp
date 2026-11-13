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
 * Definicje tabel modułu.
 *
 * Dane trzymane są we własnych tabelach, a nie w dodatkowych polach encji
 * PrestaShopa: mechanizm z 9.2 obsługuje jedną wartość na encję, podczas gdy
 * zgody tworzą wiele wierszy w czasie, często dla podmiotu bez encji klienta
 * (gość, adres bez konta, przeglądarka przed powiązaniem z adresem).
 *
 * `_log` działa w trybie append-only i przechowuje `code`, `version` oraz
 * `text_hash` jako wartości, nie odwołania, więc zdarzenie pozostaje kompletne po
 * usunięciu rodzaju zgody.
 *
 * Wielosklepowość: tabele konfiguracyjne mają `id_shop`, ponieważ rodzaje zgód,
 * wersje klauzul, ciasteczka i skrypty należą do pojedynczego sklepu. Dziennik
 * jest jedną tabelą z `id_shop` w roli etykiety; zakres pojęcia „ta sama osoba"
 * wyznacza `share_customer` na grupie sklepów (patrz `Identity\IdentityScope`).
 *
 * `_version` nie ma `id_shop`: wersja należy do rodzaju zgody, a ten do sklepu.
 */
final class Schema
{
    /** Rodzaje zgód: kod, rodzaj, czy wymagana, kolejność. */
    public const TABLE_TYPE = 'quince_cmp_type';
    public const TABLE_TYPE_LANG = 'quince_cmp_type_lang';

    /** Wersje treści. Nowa wersja to nowy wiersz, stare zostają jako dowód. */
    public const TABLE_VERSION = 'quince_cmp_version';
    public const TABLE_VERSION_LANG = 'quince_cmp_version_lang';

    /** Doszywanie zgody ciasteczkowej do adresu, który pojawił się później. */
    public const TABLE_VISITOR = 'quince_cmp_visitor';

    /** Dziennik zdarzeń. Nic się w nim nie nadpisuje. */
    public const TABLE_LOG = 'quince_cmp_log';

    /** Tabela ciasteczek pokazywana w drugiej warstwie banera. */
    public const TABLE_COOKIE = 'quince_cmp_cookie';
    public const TABLE_COOKIE_LANG = 'quince_cmp_cookie_lang';

    /** Skrypty i usługi wstrzymywane do czasu zgody. */
    public const TABLE_SCRIPT = 'quince_cmp_script';

    /** Wymiana z systemem zewnętrznym, w obie strony. */
    public const TABLE_SYNC = 'quince_cmp_sync';

    /** Znaleziska skanera: co sklep faktycznie zapisuje w przeglądarce. */
    public const TABLE_SCAN = 'quince_cmp_scan';

    /** Skąd wzięło się znalezisko. Wartości kolumny `kind` w `_scan`. */
    public const FOUND_COOKIE = 'cookie';
    public const FOUND_LOCAL = 'localStorage';
    public const FOUND_SESSION = 'sessionStorage';
    public const FOUND_REQUEST = 'request';

    /** Co z nim zrobiono. Wartości kolumny `status` w `_scan`. */
    public const FOUND_NEW = 'new';
    public const FOUND_ADOPTED = 'adopted';
    public const FOUND_IGNORED = 'ignored';

    /** Rodzaje zgód. Wartości kolumny `kind`. */
    public const KIND_COOKIE = 'cookie';
    public const KIND_FORM = 'form';
    public const KIND_DOCUMENT = 'document';

    /** Skąd przyszło zdarzenie. Wartości kolumny `origin`. */
    public const ORIGIN_SHOP = 'shop';
    public const ORIGIN_EXTERNAL = 'external';

    /**
     * Zdarzenie wpisane przez obsługę, nie przez osobę.
     *
     * Rozdzielone od `shop`, bo dowód mówi wtedy co innego: nie „ta osoba
     * kliknęła", tylko „pracownik odnotował żądanie, które przyszło poza
     * sklepem". Kto pyta o podstawę, ma prawo widzieć tę różnicę.
     */
    public const ORIGIN_STAFF = 'staff';

    /** Kierunek wymiany. Wartości kolumny `direction` w `_sync`. */
    public const DIRECTION_OUT = 'out';
    public const DIRECTION_IN = 'in';

    /** Stan pozycji w kolejce wymiany. Wartości kolumny `status` w `_sync`. */
    public const SYNC_PENDING = 'pending';
    public const SYNC_SENT = 'sent';
    public const SYNC_FAILED = 'failed';

    /** Sposób osadzenia usługi w sklepie. Wartości kolumny `embedding` w `_script`. */
    public const EMBED_INLINE = 'inline';
    public const EMBED_EXTERNAL = 'external';
    public const EMBED_IFRAME = 'iframe';
    public const EMBED_GTM = 'gtm';

    /** Sposób wstrzymania usługi do czasu zgody. Wartości kolumny `technique`. */
    public const BLOCK_TYPE_SWAP = 'type_swap';
    public const BLOCK_PLACEHOLDER = 'placeholder';
    public const BLOCK_DEFERRED = 'deferred';

    /** Jednostki czasu życia ciasteczka. Wartości kolumny `lifetime_unit`. */
    public const LIFETIME_UNITS = ['session', 'minutes', 'hours', 'days', 'months', 'years'];

    /**
     * Kolumny dołożone po pierwszym wydaniu.
     *
     * `CREATE TABLE IF NOT EXISTS` nie rusza tabeli, która już stoi, więc sklep
     * zaktualizowany z wcześniejszej wersji zostałby bez nowych kolumn. Lista
     * jest uzgadniana z tabelami przy instalacji i przy aktualizacji.
     *
     * @var array<string,array<string,string>> tabela => kolumna => definicja
     */
    private const DOLOZONE = [
        self::TABLE_LOG => [
            'id_employee' => 'INT(11) UNSIGNED NOT NULL DEFAULT 0',
            'note' => 'VARCHAR(255) NOT NULL DEFAULT ""',
        ],
        self::TABLE_TYPE => [
            'cm_functionality_storage' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'cm_personalization_storage' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            'cm_security_storage' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            /* Czy ta zgoda ma stać w checkoucie, niezależnie od `kind`. Osobna
               kolumna, a nie wyliczanie z `kind === document`: dwie zgody
               dokumentowe mogą służyć różnym celom (np. jedna do checkoutu,
               druga tylko do umowy B2B), a sprzedawca musi mieć to rozróżnienie. */
            'show_in_checkout' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
            /* Kolumna `ps_customer`, którą ta zgoda odzwierciedla. Pusta, gdy
               żadnej nie ma. */
            'core_field' => 'VARCHAR(64) NOT NULL DEFAULT ""',
        ],
    ];

    public function installTables(): bool
    {
        foreach ($this->definicje() as $zapytanie) {
            if (!Db::getInstance()->execute($zapytanie)) {
                return false;
            }
        }

        return $this->uzupelnijKolumny();
    }

    /**
     * Dokłada brakujące kolumny do tabel, które już istnieją.
     *
     * Bezpieczne do wielokrotnego uruchomienia: pomija to, co zastanie.
     */
    public function uzupelnijKolumny(): bool
    {
        $db = Db::getInstance();

        foreach (self::DOLOZONE as $tabela => $kolumny) {
            $istniejace = $this->kolumny($tabela);

            if ($istniejace === []) {
                continue;                       // tabeli nie ma, założy ją `definicje()`
            }

            foreach ($kolumny as $nazwa => $definicja) {
                if (in_array($nazwa, $istniejace, true)) {
                    continue;
                }

                if (!$db->execute('ALTER TABLE `' . _DB_PREFIX_ . $tabela . '` ADD COLUMN `' . $nazwa . '` ' . $definicja)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<int,string>
     */
    private function kolumny(string $tabela): array
    {
        $wiersze = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $tabela . '`', true, false);

        if (!is_array($wiersze)) {
            return [];
        }

        return array_map(static fn (array $w): string => (string) $w['Field'], $wiersze);
    }

    /**
     * Usuwa tabele w kolejności odwrotnej do zakładania.
     *
     * Klucze obce nie są definiowane, zgodnie z konwencją PrestaShopa.
     */
    public function dropTables(): bool
    {
        $tabele = [
            self::TABLE_SCAN,
            self::TABLE_SYNC,
            self::TABLE_SCRIPT,
            self::TABLE_COOKIE_LANG,
            self::TABLE_COOKIE,
            self::TABLE_LOG,
            self::TABLE_VISITOR,
            self::TABLE_VERSION_LANG,
            self::TABLE_VERSION,
            self::TABLE_TYPE_LANG,
            self::TABLE_TYPE,
        ];

        foreach ($tabele as $tabela) {
            if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $tabela . '`')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Definicje tabel w kolejności zakładania.
     *
     * @return array<int,string>
     */
    private function definicje(): array
    {
        $silnik = _MYSQL_ENGINE_;
        $prefix = _DB_PREFIX_;

        return [
            // --- rodzaje zgód ---------------------------------------------------

            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_TYPE . '` (
                `id_cmp_type` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `code` VARCHAR(64) NOT NULL,
                `kind` VARCHAR(16) NOT NULL DEFAULT "' . self::KIND_FORM . '",
                /* Strona CMS z treścią dokumentu. Moduł wskazuje stronę, nie
                   przechowuje jej treści. Zero dla zgód niedokumentowych. */
                `id_cms` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                /* Sygnały Consent Mode ustawiane przez tę kategorię. Mapowanie
                   należy do kategorii, nie do pojedynczej usługi: dwie usługi
                   w jednej kategorii nie mogą sterować różnymi sygnałami.
                   Osobne kolumny zamiast maski bitowej upraszczają diagnostykę.

                   Cztery pierwsze są wymagane przez Consent Mode v2. Trzy dalsze
                   są opcjonalne, ale czytają je także narzędzia spoza rodziny
                   Google, więc sprzedawca musi mieć jak nimi sterować. */
                `cm_ad_storage` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `cm_analytics_storage` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `cm_ad_user_data` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `cm_ad_personalization` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `cm_functionality_storage` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `cm_personalization_storage` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `cm_security_storage` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_cmp_type`),
                UNIQUE KEY `uq_shop_code` (`id_shop`, `code`),
                KEY `idx_shop_kind` (`id_shop`, `kind`, `active`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // Nazwa robocza dla panelu. Treść wyświetlana klientowi należy do wersji.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_TYPE_LANG . '` (
                `id_cmp_type` INT(11) UNSIGNED NOT NULL,
                `id_lang` INT(11) UNSIGNED NOT NULL,
                `name` VARCHAR(255) NOT NULL DEFAULT "",
                PRIMARY KEY (`id_cmp_type`, `id_lang`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // --- wersje treści --------------------------------------------------

            // `version` jest napisem, ponieważ numeracja może pochodzić z zewnętrznego
            // rejestru dokumentów.
            //
            // `date_to` jest wyliczalne z następnej wersji, ale przechowywane wprost:
            // zapytanie o stan na dany dzień sprowadza się wtedy do warunku na dacie.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_VERSION . '` (
                `id_cmp_version` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cmp_type` INT(11) UNSIGNED NOT NULL,
                `version` VARCHAR(32) NOT NULL,
                `date_from` DATETIME NOT NULL,
                `date_to` DATETIME NULL DEFAULT NULL,
                `pdf` VARCHAR(255) NOT NULL DEFAULT "",
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cmp_version`),
                UNIQUE KEY `uq_type_version` (`id_cmp_type`, `version`),
                KEY `idx_type_from` (`id_cmp_type`, `date_from`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // `text_hash` należy do tabeli językowej, ponieważ klauzula w każdym
            // języku stanowi odrębny dowód. CHAR(64) odpowiada SHA-256 w zapisie
            // szesnastkowym.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_VERSION_LANG . '` (
                `id_cmp_version` INT(11) UNSIGNED NOT NULL,
                `id_lang` INT(11) UNSIGNED NOT NULL,
                `short_label` VARCHAR(512) NOT NULL DEFAULT "",
                `full_clause` TEXT NULL,
                `text_hash` CHAR(64) NOT NULL DEFAULT "",
                PRIMARY KEY (`id_cmp_version`, `id_lang`),
                KEY `idx_hash` (`text_hash`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // --- doszywanie -----------------------------------------------------

            // Zgoda ciasteczkowa zapada przed poznaniem adresu, więc identyfikuje ją
            // klucz przeglądarki. Jeden adres może mieć wiele kluczy, stąd klucz
            // główny na `visitor_key`.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_VISITOR . '` (
                `visitor_key` CHAR(32) NOT NULL,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `email` VARCHAR(255) NOT NULL DEFAULT "",
                `id_customer` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_stitched` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`visitor_key`),
                KEY `idx_email` (`email`),
                KEY `idx_customer` (`id_customer`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // --- dziennik zdarzeń -----------------------------------------------

            // Odnormalizowany: `code`, `version` i `text_hash` przechowywane są jako
            // wartości z chwili zapisu. Indeksy odpowiadają sposobom przeszukiwania
            // rejestru: po adresie, kluczu przeglądarki, kodzie z datą oraz
            // identyfikatorze zewnętrznym.
            //
            // `id_employee` i `note` wypełnia wyłącznie wpis obsługi: podpis osoby,
            // która go wprowadziła, i podstawa, na jakiej to zrobiła.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_LOG . '` (
                `id_cmp_log` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `visitor_key` CHAR(32) NOT NULL DEFAULT "",
                `email` VARCHAR(255) NOT NULL DEFAULT "",
                `id_customer` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `code` VARCHAR(64) NOT NULL,
                `version` VARCHAR(32) NOT NULL DEFAULT "",
                `text_hash` CHAR(64) NOT NULL DEFAULT "",
                `id_lang` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `granted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `source` VARCHAR(64) NOT NULL DEFAULT "",
                `form_id` VARCHAR(64) NOT NULL DEFAULT "",
                `ip` VARCHAR(45) NOT NULL DEFAULT "",
                `user_agent` VARCHAR(255) NOT NULL DEFAULT "",
                `origin` VARCHAR(16) NOT NULL DEFAULT "' . self::ORIGIN_SHOP . '",
                `id_employee` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `note` VARCHAR(255) NOT NULL DEFAULT "",
                `external_id` VARCHAR(64) NOT NULL DEFAULT "",
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cmp_log`),
                KEY `idx_email_code_date` (`email`, `code`, `date_add`),
                KEY `idx_visitor` (`visitor_key`, `date_add`),
                KEY `idx_code_date` (`code`, `date_add`),
                KEY `idx_customer` (`id_customer`),
                KEY `idx_external` (`origin`, `external_id`),
                KEY `idx_date` (`date_add`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // --- ciasteczka -----------------------------------------------------

            // `name` dopuszcza wzorzec z gwiazdką (`_ga_*`), ponieważ część nazw
            // zawiera identyfikator nadawany przez dostawcę.
            //
            // `source` odróżnia wpisy ręczne od pochodzących ze skanera; skaner nie
            // modyfikuje wpisów ręcznych.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_COOKIE . '` (
                `id_cmp_cookie` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `name` VARCHAR(128) NOT NULL,
                `provider` VARCHAR(128) NOT NULL DEFAULT "",
                `category` VARCHAR(32) NOT NULL DEFAULT "",
                /* Czas życia rozbity na liczbę i jednostkę, aby dało się go
                   przetłumaczyć dla klienta, porównać i posortować. Dla jednostki
                   `session` liczba jest nieistotna. */
                `lifetime_value` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `lifetime_unit` VARCHAR(16) NOT NULL DEFAULT "session",
                `source` VARCHAR(16) NOT NULL DEFAULT "manual",
                `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cmp_cookie`),
                UNIQUE KEY `uq_shop_name` (`id_shop`, `name`),
                KEY `idx_shop_category` (`id_shop`, `category`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_COOKIE_LANG . '` (
                `id_cmp_cookie` INT(11) UNSIGNED NOT NULL,
                `id_lang` INT(11) UNSIGNED NOT NULL,
                `description` VARCHAR(512) NOT NULL DEFAULT "",
                PRIMARY KEY (`id_cmp_cookie`, `id_lang`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // --- skrypty i blokowanie -------------------------------------------

            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_SCRIPT . '` (
                `id_cmp_script` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `name` VARCHAR(128) NOT NULL,
                `embedding` VARCHAR(32) NOT NULL DEFAULT "",
                `technique` VARCHAR(32) NOT NULL DEFAULT "",
                `category` VARCHAR(32) NOT NULL DEFAULT "",
                `pattern` VARCHAR(255) NOT NULL DEFAULT "",
                `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (`id_cmp_script`),
                KEY `idx_shop_category` (`id_shop`, `category`, `active`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            // --- synchronizacja --------------------------------------------------

            // Kolejka wymiany w obie strony. `id_cmp_log` wskazuje zdarzenie, którego
            // dotyczy wysyłka; przy ruchu przychodzącym pozostaje puste do czasu
            // zapisania zdarzenia. `attempts` i `next_try` obsługują ponawianie.
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_SYNC . '` (
                `id_cmp_sync` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `direction` VARCHAR(8) NOT NULL DEFAULT "' . self::DIRECTION_OUT . '",
                `id_cmp_log` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                `external_id` VARCHAR(64) NOT NULL DEFAULT "",
                `payload` TEXT NULL,
                `response` TEXT NULL,
                `http_code` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                `error` VARCHAR(255) NOT NULL DEFAULT "",
                `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT "' . self::SYNC_PENDING . '",
                `next_try` DATETIME NULL DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_cmp_sync`),
                KEY `idx_queue` (`id_shop`, `direction`, `status`, `next_try`),
                KEY `idx_log` (`id_cmp_log`),
                KEY `idx_external` (`external_id`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',

            /* Znaleziska skanera.

               Osobna tabela, a nie dopisywanie wprost do `_cookie`: skaner
               melduje, co zastał, a nie co ma stać w tabeli ciasteczek. Decyzja
               należy do sprzedawcy: on zna dostawcę i przeznaczenie, a skaner
               widzi tylko nazwę.

               `name` razem z `kind` i `id_shop` jest kluczem naturalnym, więc
               ten sam wpis nie mnoży się przy kolejnych przebiegach. */
            'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE_SCAN . '` (
                `id_cmp_scan` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `kind` VARCHAR(16) NOT NULL DEFAULT "' . self::FOUND_COOKIE . '",
                `name` VARCHAR(190) NOT NULL,
                `domain` VARCHAR(190) NOT NULL DEFAULT "",
                `page` VARCHAR(255) NOT NULL DEFAULT "",
                `status` VARCHAR(16) NOT NULL DEFAULT "' . self::FOUND_NEW . '",
                `seen` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_cmp_scan`),
                UNIQUE KEY `uniq_found` (`id_shop`, `kind`, `name`),
                KEY `idx_status` (`id_shop`, `status`)
            ) ENGINE=' . $silnik . ' DEFAULT CHARSET=utf8mb4',
        ];
    }
}
