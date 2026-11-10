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
use Language;

/**
 * Zestaw rodzajów zgód zakładany przy instalacji modułu.
 *
 * Obejmuje pozycje uniwersalne: cztery kategorie ciasteczek, trzy kanały
 * marketingowe oraz regulamin i politykę prywatności. Zgody branżowe dodaje
 * sprzedawca samodzielnie.
 *
 * Zakładane są wyłącznie rodzaje zgód, bez wersji — moduł nie dostarcza treści
 * klauzul, ponieważ odpowiada za nie sprzedawca. Do czasu opublikowania wersji
 * zgoda nie jest prezentowana klientowi.
 *
 * Zestaw zakładany jest osobno w każdym sklepie, również w utworzonym po
 * instalacji modułu (hook `actionObjectShopAddAfter`).
 */
final class DefaultConsentTypes
{
    /**
     * @var array<int,array{code:string,kind:string,required:bool,name:string,signals?:array<int,string>,core_field?:string}>
     */
    private const TYPES = [
        // Kategorie ciasteczek. Niezbędne są oznaczone jako wymagane.
        ['code' => 'cookies_necessary', 'kind' => Schema::KIND_COOKIE, 'required' => true, 'name' => 'Necessary cookies',
            'signals' => ['security_storage']],
        ['code' => 'cookies_analytics', 'kind' => Schema::KIND_COOKIE, 'required' => false, 'name' => 'Analytics cookies',
            'signals' => ['analytics_storage']],
        ['code' => 'cookies_marketing', 'kind' => Schema::KIND_COOKIE, 'required' => false, 'name' => 'Marketing cookies',
            'signals' => ['ad_storage', 'ad_user_data', 'ad_personalization']],
        ['code' => 'cookies_preferences', 'kind' => Schema::KIND_COOKIE, 'required' => false, 'name' => 'Preference cookies',
            'signals' => ['functionality_storage', 'personalization_storage']],

        // Kanały marketingowe rozdzielone — zgoda jest rozdzielna po celach.
        ['code' => 'newsletter', 'kind' => Schema::KIND_FORM, 'required' => false, 'name' => 'Newsletter subscription',
            'core_field' => 'newsletter'],
        ['code' => 'marketing_email', 'kind' => Schema::KIND_FORM, 'required' => false, 'name' => 'Marketing messages by e-mail',
            'core_field' => 'optin'],
        ['code' => 'marketing_sms', 'kind' => Schema::KIND_FORM, 'required' => false, 'name' => 'Marketing messages by SMS'],

        // Dokumenty. Regulamin wymagany, polityka prywatności nie.
        ['code' => 'terms_shop', 'kind' => Schema::KIND_DOCUMENT, 'required' => true, 'name' => 'Terms and conditions'],
        ['code' => 'privacy_policy', 'kind' => Schema::KIND_DOCUMENT, 'required' => false, 'name' => 'Privacy policy'],
    ];

    /**
     * Zakłada zestaw w jednym sklepie. Operacja idempotentna — istniejące kody
     * pozostają bez zmian.
     */
    public function seedForShop(int $idShop): bool
    {
        $istniejace = $this->kodyWSklepie($idShop);
        $jezyki = Language::getLanguages(false);
        $teraz = date('Y-m-d H:i:s');
        $pozycja = $this->nastepnaPozycja($idShop);

        foreach (self::TYPES as $opis) {
            if (in_array($opis['code'], $istniejace, true)) {
                continue;
            }

            $ok = Db::getInstance()->insert(Schema::TABLE_TYPE, $this->sygnaly($opis) + [
                'id_shop' => $idShop,
                'code' => pSQL($opis['code']),
                'kind' => pSQL($opis['kind']),
                'required' => (int) $opis['required'],
                'position' => $pozycja,
                'active' => 1,
                'id_cms' => $this->stronaDokumentu($opis['code'], $idShop),
                'core_field' => pSQL($opis['core_field'] ?? ''),
                'date_add' => pSQL($teraz),
                'date_upd' => pSQL($teraz),
            ]);

            if (!$ok) {
                return false;
            }

            $idTypu = (int) Db::getInstance()->Insert_ID();
            ++$pozycja;

            // Nazwa robocza dla panelu, jednakowa we wszystkich językach.
            foreach ($jezyki as $jezyk) {
                Db::getInstance()->insert(Schema::TABLE_TYPE_LANG, [
                    'id_cmp_type' => $idTypu,
                    'id_lang' => (int) $jezyk['id_lang'],
                    'name' => pSQL($opis['name']),
                ]);
            }
        }

        return true;
    }

    /** Zakłada zestaw we wszystkich sklepach instalacji, także nieaktywnych. */
    public function seedAllShops(): bool
    {
        foreach (\Shop::getShops(false) as $sklep) {
            if (!$this->seedForShop((int) $sklep['id_shop'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strona CMS dla zgody dokumentowej, o ile wskazuje ją konfiguracja sklepu.
     * Regulamin pobierany jest z `PS_CONDITIONS_CMS_ID`; polityka prywatności nie
     * ma odpowiednika w konfiguracji PrestaShopa.
     */
    private function stronaDokumentu(string $code, int $idShop): int
    {
        if ($code !== 'terms_shop') {
            return 0;
        }

        return (int) \Configuration::get('PS_CONDITIONS_CMS_ID', null, null, $idShop);
    }

    /**
     * @return array<int,string>
     */
    /**
     * Kolumny Consent Mode dla zakladanej kategorii.
     *
     * Bez tego przypisania zgoda klienta nie ma jak dotrzec do Google: modul
     * deklaruje przed decyzja odmowe wszystkich sygnalow, a po zgodzie podnosi
     * tylko te, ktore dana kategoria steruje. Kategoria niesterujaca niczym
     * sprawia, ze „Akceptuje" nie zmienia nic, a Google traktuje kanal, ktory
     * nigdy nie mowi „tak", jak wylaczony w ogole.
     *
     * Przypisanie jest **propozycja startowa**, nie regula prawa — sprzedawca
     * zmienia je na ekranie skryptow.
     *
     * @param array{signals?:array<int,string>} $opis
     *
     * @return array<string,int>
     */
    private function sygnaly(array $opis): array
    {
        $out = [];

        foreach (Scripts::SIGNALS as $sygnal => $kolumna) {
            $out[$kolumna] = (int) in_array($sygnal, $opis['signals'] ?? [], true);
        }

        return $out;
    }

    private function kodyWSklepie(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `code` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` WHERE `id_shop` = ' . $idShop
        ) ?: [];

        return array_map(static fn (array $w): string => (string) $w['code'], $wiersze);
    }

    private function nastepnaPozycja(int $idShop): int
    {
        return 1 + (int) Db::getInstance()->getValue(
            'SELECT MAX(`position`) FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` WHERE `id_shop` = ' . $idShop
        );
    }
}
