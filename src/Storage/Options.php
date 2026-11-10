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

use Configuration;
use Shop;

/**
 * Ustawienia modułu przechowywane w `ps_configuration`.
 *
 * Obejmuje wyłącznie wartości pojedyncze w skali sklepu; dane wielowierszowe mają
 * własne tabele (`Schema`). Klucze zadeklarowane są jako stałe, ponieważ
 * `ps_configuration` nie waliduje nazw.
 *
 * Odczyt następuje zawsze dla wskazanego sklepu. W kontekście „wszystkie sklepy"
 * `Configuration::get()` bez identyfikatora zwraca wartość globalną, co nie
 * odpowiada na pytanie o konkretną witrynę.
 */
final class Options
{
    /** Czy baner w ogóle się pokazuje. */
    public const BANNER_ON = 'QUINCE_CMP_BANNER_ON';

    /** Czy wstrzymujemy skrypty do czasu zgody. Bez tego baner jest ozdobą. */
    public const BLOCKING_ON = 'QUINCE_CMP_BLOCKING_ON';

    /** Strona CMS z polityką prywatności. */
    public const POLICY_CMS = 'QUINCE_CMP_POLICY_CMS';

    /** Ile miesięcy trzymamy zdarzenia. Patrz SPEC §10.3 — decyzja otwarta. */
    public const RETENTION_MONTHS = 'QUINCE_CMP_RETENTION_MONTHS';

    /**
     * Czy zmiana konfiguracji unieważnia dawne zgody.
     *
     * Domyślnie tak: zgoda dotyczy konkretnego zestawu kategorii, ciasteczek
     * i treści klauzuli. Po zmianie zestawu dawna zgoda opisuje coś węższego
     * niż to, co odwiedzającego teraz spotyka.
     */
    public const REASK_ON_CHANGE = 'QUINCE_CMP_REASK_ON_CHANGE';

    /**
     * Licznik ręcznych unieważnień.
     *
     * Nie każdą zmianę widać w danych — nowy cel przetwarzania w istniejącej
     * kategorii nie zostawia śladu w tabelach. Podbicie licznika każe zapytać
     * wszystkich od nowa.
     */
    public const CONSENT_EPOCH = 'QUINCE_CMP_CONSENT_EPOCH';

    /** Po ilu miesiącach pytamy o zgodę ponownie. SPEC §10.4 — decyzja otwarta. */
    public const LIFETIME_MONTHS = 'QUINCE_CMP_LIFETIME_MONTHS';

    /** Po ilu miesiącach kasujemy zgodę kogoś, kto nigdy nie podał adresu. */
    public const ANON_MONTHS = 'QUINCE_CMP_ANON_MONTHS';

    /** Co jest wstrzymywane, zanim odwiedzający cokolwiek wybierze. */
    public const BEFORE_CONSENT = 'QUINCE_CMP_BEFORE_CONSENT';
    public const BEFORE_ALL_BUT_NECESSARY = 'all_but_necessary';
    public const BEFORE_MARKETING_ONLY = 'marketing_only';
    public const BEFORE_CONSENT_CHOICES = [self::BEFORE_ALL_BUT_NECESSARY, self::BEFORE_MARKETING_ONLY];

    /** Zapamiętana odpowiedź GitHuba. Ważna dobę. */
    public const UPDATE_CACHE = 'QUINCE_CMP_UPDATE_CACHE';


    /** Bieżąca sesja skanowania: token i chwila uruchomienia. */
    public const SCAN_TOKEN = 'QUINCE_CMP_SCAN_TOKEN';
    public const SCAN_STARTED = 'QUINCE_CMP_SCAN_STARTED';

    /** Czy honorujemy sygnał GPC z przeglądarki bez pytania. */
    public const RESPECT_GPC = 'QUINCE_CMP_RESPECT_GPC';

    /** Tryb testowy: baner widzą tylko wskazane adresy IP. */
    public const TEST_MODE = 'QUINCE_CMP_TEST_MODE';
    public const TEST_IPS = 'QUINCE_CMP_TEST_IPS';

    /** Baner — pierwsza warstwa. Wartości językowe. */
    public const B1_TITLE = 'QUINCE_CMP_B1_TITLE';
    public const B1_BODY = 'QUINCE_CMP_B1_BODY';
    public const B1_ACCEPT = 'QUINCE_CMP_B1_ACCEPT';
    public const B1_REJECT = 'QUINCE_CMP_B1_REJECT';
    public const B1_PREFS = 'QUINCE_CMP_B1_PREFS';

    /** Baner — druga warstwa. Wartości językowe. */
    public const B2_TITLE = 'QUINCE_CMP_B2_TITLE';
    public const B2_BODY = 'QUINCE_CMP_B2_BODY';
    public const B2_SAVE = 'QUINCE_CMP_B2_SAVE';

    /** Umiejscowienie pierwszej warstwy. */
    public const B_PLACEMENT = 'QUINCE_CMP_B_PLACEMENT';
    public const PLACEMENTS = ['bar', 'modal', 'corner'];

    /** Źródło kolorów banera: tokeny silnika albo własne. */
    public const B_COLOURS = 'QUINCE_CMP_B_COLOURS';
    public const B_COLOUR_ACCENT = 'QUINCE_CMP_B_COLOUR_ACCENT';
    public const B_COLOUR_SURFACE = 'QUINCE_CMP_B_COLOUR_SURFACE';
    public const B_COLOUR_TEXT = 'QUINCE_CMP_B_COLOUR_TEXT';

    /** Pływający przycisk powrotu do ustawień. */
    public const FAB_ON = 'QUINCE_CMP_FAB_ON';
    public const FAB_POSITION = 'QUINCE_CMP_FAB_POSITION';
    public const FAB_LABEL = 'QUINCE_CMP_FAB_LABEL';
    public const FAB_POSITIONS = ['left', 'right'];

    /**
     * Odległość przycisku od dolnej krawędzi, w pikselach — osobno dla telefonu.
     *
     * W tym samym rogu stoi zwykle czat, przycisk „do góry" albo przyklejony pasek
     * koszyka. Na telefonie takie paski są dużo częstsze, więc wartość dobrana pod
     * komputer potrafiłaby schować przycisk pod nimi.
     */
    public const FAB_OFFSET_DESKTOP = 'QUINCE_CMP_FAB_OFFSET_DESKTOP';
    public const FAB_OFFSET_MOBILE = 'QUINCE_CMP_FAB_OFFSET_MOBILE';
    public const FAB_OFFSET_DEFAULT = 18;
    public const FAB_OFFSET_MAX = 400;

    /** Czy przycisk niesie napis, czy zostaje przy samej ikonie. */
    public const FAB_MODE = 'QUINCE_CMP_FAB_MODE';
    public const FAB_MODES = ['icon_label', 'icon'];

    /** Znak na przycisku. Nazwy z zestawu `_macros/icons.html.twig`. */
    public const FAB_ICON = 'QUINCE_CMP_FAB_ICON';
    public const FAB_ICONS = ['cookie', 'shield', 'settings', 'eye', 'info', 'user'];

    /**
     * Własny znak wgrany przez sprzedawcę, już oczyszczony.
     *
     * Trzymany jako treść, nie jako plik: wchodzi w stronę wprost, bez
     * dodatkowego żądania, i dziedziczy kolor przycisku.
     */
    public const FAB_SVG = 'QUINCE_CMP_FAB_SVG';

    /** Treść zastępnika zablokowanej ramki. Wartości językowe. */
    public const PH_TITLE = 'QUINCE_CMP_PH_TITLE';
    public const PH_BODY = 'QUINCE_CMP_PH_BODY';
    public const PH_ALLOW = 'QUINCE_CMP_PH_ALLOW';
    public const PH_SETTINGS = 'QUINCE_CMP_PH_SETTINGS';

    /**
     * Zasięg stanu domyślnego Consent Mode.
     *
     * `everywhere` — odmowa dla całego świata; ustawienie bezpieczne i domyślne.
     * `regions`    — odmowa tylko w wskazanych krajach, poza nimi zgoda z góry.
     *                Tak działa większość sklepów sprzedających poza EOG: prawo
     *                wymaga pytania tam, gdzie obowiązuje, a nie wszędzie.
     */
    public const CM_SCOPE = 'QUINCE_CMP_CM_SCOPE';
    public const CM_SCOPES = ['everywhere', 'regions'];

    /** Kraje objęte odmową z góry. Kody ISO 3166-1 alfa-2, po przecinku. */
    public const CM_REGIONS = 'QUINCE_CMP_CM_REGIONS';

    /** Europejski Obszar Gospodarczy plus Wielka Brytania i Szwajcaria. */
    public const CM_REGIONS_EEA = 'AT,BE,BG,HR,CY,CZ,DK,EE,FI,FR,DE,GR,HU,IS,IE,IT,LV,LI,LT,LU,MT,NL,NO,PL,PT,RO,SK,SI,ES,SE,GB,CH';

    /**
     * Ile milisekund tagi Google mają czekać na rozstrzygnięcie zgody.
     *
     * Bez tego tag zdąży wystrzelić ze stanem domyślnym, zanim baner zdąży się
     * pokazać, i zgoda udzielona sekundę później nie odzyska tej odsłony.
     */
    public const CM_WAIT = 'QUINCE_CMP_CM_WAIT';
    public const CM_WAIT_CHOICES = [0, 500, 1000, 2000];

    /** Czy przed zgodą wycinać identyfikatory reklamowe z żądań do Google. */
    public const CM_REDACT = 'QUINCE_CMP_CM_REDACT';

    /** Czy przenosić identyfikator kliknięcia w adresie, gdy nie wolno go zapisać. */
    public const CM_PASSTHROUGH = 'QUINCE_CMP_CM_PASSTHROUGH';

    /** Wymiana z systemem zewnętrznym. Adres odbiorcy i klucze podpisu. */
    public const SYNC_ON = 'QUINCE_CMP_SYNC_ON';
    public const SYNC_ENDPOINT = 'QUINCE_CMP_SYNC_ENDPOINT';
    public const SYNC_KEY_OUT = 'QUINCE_CMP_SYNC_KEY_OUT';
    public const SYNC_KEY_IN = 'QUINCE_CMP_SYNC_KEY_IN';

    /** Kody zgód wysyłane na zewnątrz. Lista rozdzielona przecinkami. */
    /**
     * Zgoda na wysyłkę pod adres w sieci lokalnej serwera.
     *
     * Domyślnie wyłączone. Odbiorca stojący w tej samej sieci co sklep to sytuacja
     * prawdziwa, ale rzadka — a bez tego przełącznika pole adresu jest narzędziem
     * do odpytywania cudzej infrastruktury. Świadome kliknięcie zamienia przeoczenie
     * w decyzję.
     */
    public const SYNC_ALLOW_LOCAL = 'QUINCE_CMP_SYNC_ALLOW_LOCAL';

    public const SYNC_CODES = 'QUINCE_CMP_SYNC_CODES';

    /** Co ile minut rusza wysyłka. */
    public const SYNC_EVERY = 'QUINCE_CMP_SYNC_EVERY';
    public const SYNC_INTERVALS = [5, 15, 30, 60, 360, 1440];

    /** Po ilu dniach kasujemy pozycje wysłane. Nieudane zostają do decyzji. */
    public const SYNC_KEEP_DAYS = 'QUINCE_CMP_SYNC_KEEP_DAYS';
    public const SYNC_KEEP_CHOICES = [7, 30, 90, 365];

    /** Powiadomienie mailem, gdy wysyłka przestaje dochodzić. */
    public const SYNC_ALERT_ON = 'QUINCE_CMP_SYNC_ALERT_ON';
    public const SYNC_ALERT_LAST = 'QUINCE_CMP_SYNC_ALERT_LAST';

    /** Czy ustawienia wymiany są wspólne dla grupy sklepów. */
    public const SYNC_SCOPE = 'QUINCE_CMP_SYNC_SCOPE';

    /**
     * Sklep bieżącego kontekstu. W kontekście „wszystkie sklepy" zwraca sklep
     * domyślny instalacji.
     */
    public static function biezacySklep(): int
    {
        return (int) (Shop::getContextShopID() ?: Configuration::get('PS_SHOP_DEFAULT'));
    }

    public static function bool(string $klucz, bool $domyslnie = false, ?int $idShop = null): bool
    {
        $wartosc = Configuration::get($klucz, null, null, $idShop ?? self::biezacySklep());

        // `Configuration::get()` zwraca `false` zarówno dla klucza nieustawionego,
        // jak i ustawionego na zero; rozróżnienie zapewnia `czyUstawione()`.
        return $wartosc === false ? $domyslnie : (bool) $wartosc;
    }

    public static function czyUstawione(string $klucz, ?int $idShop = null): bool
    {
        return Configuration::hasKey($klucz, null, null, $idShop ?? self::biezacySklep());
    }

    public static function int(string $klucz, int $domyslnie = 0, ?int $idShop = null): int
    {
        $wartosc = Configuration::get($klucz, null, null, $idShop ?? self::biezacySklep());

        return $wartosc === false || $wartosc === '' ? $domyslnie : (int) $wartosc;
    }

    public static function text(string $klucz, string $domyslnie = '', ?int $idShop = null): string
    {
        $wartosc = Configuration::get($klucz, null, null, $idShop ?? self::biezacySklep());

        return $wartosc === false ? $domyslnie : (string) $wartosc;
    }

    /** Wartość językowa. */
    public static function lang(string $klucz, int $idLang, string $domyslnie = '', ?int $idShop = null): string
    {
        $wartosc = Configuration::get($klucz, $idLang, null, $idShop ?? self::biezacySklep());

        return $wartosc === false || $wartosc === '' ? $domyslnie : (string) $wartosc;
    }

    /**
     * @param array<int,string> $wartosci klucz: id_lang
     */
    public static function saveLang(string $klucz, array $wartosci, ?int $idShop = null): bool
    {
        return (bool) Configuration::updateValue(
            $klucz,
            $wartosci,
            true,
            null,
            $idShop ?? self::biezacySklep()
        );
    }

    public static function save(string $klucz, mixed $wartosc, ?int $idShop = null): bool
    {
        return (bool) Configuration::updateValue($klucz, $wartosc, false, null, $idShop ?? self::biezacySklep());
    }
}
