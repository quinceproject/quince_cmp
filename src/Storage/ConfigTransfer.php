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
 * Przenoszenie konfiguracji między sklepami.
 *
 * Wywożone jest to, co sprzedawca ustawił: kategorie zgód, tabela ciasteczek,
 * usługi i wzorce blokowania, mapowanie Consent Mode, teksty banera i ustawienia.
 *
 * Świadomie nie wywozimy trzech rzeczy:
 *
 * - **dziennika zgód** — to dowód, że konkretnej osobie coś pokazano. Wgrany
 *   do innego sklepu przestaje nim być, bo tam nikomu niczego nie pokazano;
 * - **wydanych wersji klauzul** — niosą skrót treści i daty obowiązywania,
 *   czyli też twierdzą coś o przeszłości. Wersje wydaje się na miejscu;
 * - **kluczy podpisu wymiany** — to sekrety. Każdy sklep ma własne.
 */
final class ConfigTransfer
{
    /** Wersja formatu. Podbijana, gdy zmienia się kształt pliku. */
    private const FORMAT = 1;

    /** Ustawienia pomijane przy wywozie: sekrety i stan lokalny. */
    private const POMIJANE = [
        Options::SYNC_KEY_OUT,
        Options::SYNC_KEY_IN,
        Options::SCAN_TOKEN,
        Options::SCAN_STARTED,
        Options::UPDATE_CACHE,
        Options::SYNC_ALERT_LAST,
        Options::CONSENT_EPOCH,
    ];

    /**
     * @return array<string,mixed>
     */
    public function export(int $idShop): array
    {
        return [
            'format' => self::FORMAT,
            'module' => '0.2.0',
            'exported' => date('c'),
            'shop' => (string) (new \Shop($idShop))->name,
            'types' => $this->typy($idShop),
            'cookies' => $this->ciasteczka($idShop),
            'scripts' => $this->skrypty($idShop),
            'options' => $this->ustawienia($idShop),
        ];
    }

    /**
     * Co zmieni wgranie pliku, bez wgrywania go.
     *
     * Import nadpisuje konfigurację; pokazanie skutku przed decyzją jest tańsze
     * niż odtwarzanie ustawień, których nikt nie zapisał.
     *
     * @param array<string,mixed> $dane
     *
     * @return array{valid:bool,error:string,summary:array<string,array{incoming:int,current:int}>}
     */
    public function preview(array $dane, int $idShop): array
    {
        if ((int) ($dane['format'] ?? 0) !== self::FORMAT) {
            return ['valid' => false, 'error' => 'This file comes from a different version of the module.', 'summary' => []];
        }

        foreach (['types', 'cookies', 'scripts', 'options'] as $klucz) {
            if (!isset($dane[$klucz]) || !is_array($dane[$klucz])) {
                return ['valid' => false, 'error' => 'The file is missing the "' . $klucz . '" section.', 'summary' => []];
            }
        }

        return [
            'valid' => true,
            'error' => '',
            'summary' => [
                'types' => ['incoming' => count($dane['types']), 'current' => count($this->typy($idShop))],
                'cookies' => ['incoming' => count($dane['cookies']), 'current' => count($this->ciasteczka($idShop))],
                'scripts' => ['incoming' => count($dane['scripts']), 'current' => count($this->skrypty($idShop))],
                'options' => ['incoming' => count($dane['options']), 'current' => count($this->ustawienia($idShop))],
            ],
            'services' => $this->wchodzaceUslugi($dane['scripts']),
        ];
    }

    /**
     * Usługi, które plik wprowadzi na stronę sklepu.
     *
     * Sama liczba nie wystarcza. Wpis usługi niesie kod, który trafia do stopki
     * sklepu, a plik konfiguracyjny krąży między ludźmi — „wgraj sobie nasz
     * standard". Kto go wgrywa, ma zobaczyć, co dokłada, zanim to zrobi.
     *
     * @param array<int,array<string,mixed>> $skrypty
     *
     * @return array<int,array{name:string,category:string,hosts:string,inline:bool}>
     */
    private function wchodzaceUslugi(array $skrypty): array
    {
        $out = [];

        foreach ($skrypty as $skrypt) {
            $kod = (string) ($skrypt['embedding'] ?? '');

            $out[] = [
                'name' => (string) ($skrypt['name'] ?? ''),
                'category' => (string) ($skrypt['category'] ?? ''),
                'hosts' => implode(', ', self::hosty($kod)),
                // Kod bez adresu zewnętrznego wykonuje się na miejscu i nie da się
                // go ocenić po nazwie domeny — to trzeba powiedzieć wprost.
                'inline' => self::hosty($kod) === [] && trim($kod) !== '',
            ];
        }

        return $out;
    }

    /**
     * Domeny, z których usługa pobiera kod.
     *
     * @return array<int,string>
     */
    private static function hosty(string $kod): array
    {
        preg_match_all('#https?://([a-z0-9.-]+)#i', $kod, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Wgrywa konfigurację, zastępując obecną.
     *
     * Zastępujemy, nie dokładamy: plik opisuje stan docelowy, a scalanie dwóch
     * konfiguracji zgód dawałoby kategorie, których nikt nie zamawiał.
     * Dziennik i wersje zostają nietknięte.
     *
     * @param array<string,mixed> $dane
     */
    public function import(array $dane, int $idShop): bool
    {
        $db = Db::getInstance();
        $teraz = date('Y-m-d H:i:s');

        // Typy zgód mają wersje i zdarzenia w dzienniku, więc nie kasujemy ich
        // hurtem — uzgadniamy po kodzie. Kod, którego w pliku nie ma, zostaje
        // wyłączony zamiast usunięty: jego zgody dalej są w dzienniku.
        $przychodzace = [];

        foreach ($dane['types'] as $typ) {
            $kod = (string) ($typ['code'] ?? '');

            if ($kod === '') {
                continue;
            }

            $przychodzace[] = $kod;
            $this->zapiszTyp($typ, $idShop, $teraz);
        }

        if ($przychodzace !== []) {
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
                    SET `active` = 0, `date_upd` = "' . $teraz . '"
                  WHERE `id_shop` = ' . $idShop . '
                    AND `code` NOT IN ("' . implode('","', array_map('pSQL', $przychodzace)) . '")'
            );
        }

        // Ciasteczka i skrypty są czystym opisem — te da się wymienić w całości.
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '` WHERE `id_shop` = ' . $idShop);
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '` WHERE `id_shop` = ' . $idShop);

        foreach ($dane['cookies'] as $ciasteczko) {
            $this->zapiszCiasteczko($ciasteczko, $idShop, $teraz);
        }

        foreach ($dane['scripts'] as $skrypt) {
            $this->zapiszSkrypt($skrypt, $idShop);
        }

        foreach ($dane['options'] as $klucz => $wartosc) {
            if (!str_starts_with((string) $klucz, 'QUINCE_CMP_') || in_array($klucz, self::POMIJANE, true)) {
                continue;
            }

            Options::save((string) $klucz, $wartosc, $idShop);
        }

        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function typy(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `id_cmp_type`, `code`, `kind`, `id_cms`, `required`, `position`, `active`,
                    `cm_ad_storage`, `cm_analytics_storage`, `cm_ad_user_data`, `cm_ad_personalization`,
                    `cm_functionality_storage`, `cm_personalization_storage`, `cm_security_storage`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
              WHERE `id_shop` = ' . $idShop . ' ORDER BY `position` ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $id = (int) $wiersz['id_cmp_type'];
            unset($wiersz['id_cmp_type']);

            $wiersz['names'] = $this->nazwy(Schema::TABLE_TYPE_LANG, 'id_cmp_type', $id, 'name');
            $out[] = $wiersz;
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function ciasteczka(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT `id_cmp_cookie`, `name`, `provider`, `category`, `lifetime_value`, `lifetime_unit`,
                    `source`, `position`, `active`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '`
              WHERE `id_shop` = ' . $idShop . ' ORDER BY `position` ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $id = (int) $wiersz['id_cmp_cookie'];
            unset($wiersz['id_cmp_cookie']);

            $wiersz['descriptions'] = $this->nazwy(Schema::TABLE_COOKIE_LANG, 'id_cmp_cookie', $id, 'description');
            $out[] = $wiersz;
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function skrypty(int $idShop): array
    {
        return Db::getInstance()->executeS(
            'SELECT `name`, `embedding`, `technique`, `category`, `pattern`, `position`, `active`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_SCRIPT . '`
              WHERE `id_shop` = ' . $idShop . ' ORDER BY `position` ASC'
        ) ?: [];
    }

    /**
     * @return array<string,string>
     */
    private function ustawienia(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE "QUINCE_CMP_%"'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $klucz = (string) $wiersz['name'];

            if (in_array($klucz, self::POMIJANE, true)) {
                continue;
            }

            $wartosc = \Configuration::get($klucz, null, null, $idShop);

            if ($wartosc !== false) {
                $out[$klucz] = (string) $wartosc;
            }
        }

        return $out;
    }

    /**
     * @return array<string,string> kod języka => wartość
     */
    private function nazwy(string $tabela, string $kolumnaId, int $id, string $pole): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT l.`iso_code`, t.`' . $pole . '`
               FROM `' . _DB_PREFIX_ . $tabela . '` t
               INNER JOIN `' . _DB_PREFIX_ . 'lang` l ON l.`id_lang` = t.`id_lang`
              WHERE t.`' . $kolumnaId . '` = ' . $id
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['iso_code']] = (string) $wiersz[$pole];
        }

        return $out;
    }

    /**
     * Zapisuje typ zgody, dopasowując go po kodzie.
     *
     * @param array<string,mixed> $typ
     */
    private function zapiszTyp(array $typ, int $idShop, string $teraz): void
    {
        $db = Db::getInstance();
        $kod = pSQL((string) $typ['code']);

        $dane = [
            'kind' => pSQL((string) ($typ['kind'] ?? Schema::KIND_COOKIE)),
            'id_cms' => (int) ($typ['id_cms'] ?? 0),
            'required' => (int) ($typ['required'] ?? 0),
            'position' => (int) ($typ['position'] ?? 0),
            'active' => (int) ($typ['active'] ?? 1),
            'date_upd' => $teraz,
        ];

        foreach (array_values(Scripts::SIGNALS) as $kolumna) {
            $dane[$kolumna] = (int) ($typ[$kolumna] ?? 0);
        }

        $id = (int) $db->getValue(
            'SELECT `id_cmp_type` FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '`
              WHERE `id_shop` = ' . $idShop . ' AND `code` = "' . $kod . '"'
        );

        if ($id > 0) {
            $db->update(Schema::TABLE_TYPE, $dane, '`id_cmp_type` = ' . $id);
        } else {
            $db->insert(Schema::TABLE_TYPE, $dane + ['id_shop' => $idShop, 'code' => $kod, 'date_add' => $teraz]);
            $id = (int) $db->Insert_ID();
        }

        $this->zapiszJezyki(Schema::TABLE_TYPE_LANG, 'id_cmp_type', $id, 'name', (array) ($typ['names'] ?? []));
    }

    /**
     * @param array<string,mixed> $ciasteczko
     */
    private function zapiszCiasteczko(array $ciasteczko, int $idShop, string $teraz): void
    {
        $db = Db::getInstance();

        $db->insert(Schema::TABLE_COOKIE, [
            'id_shop' => $idShop,
            'name' => pSQL((string) ($ciasteczko['name'] ?? '')),
            'provider' => pSQL((string) ($ciasteczko['provider'] ?? '')),
            'category' => pSQL((string) ($ciasteczko['category'] ?? '')),
            'lifetime_value' => (int) ($ciasteczko['lifetime_value'] ?? 0),
            'lifetime_unit' => pSQL((string) ($ciasteczko['lifetime_unit'] ?? 'session')),
            'source' => pSQL((string) ($ciasteczko['source'] ?? '')),
            'position' => (int) ($ciasteczko['position'] ?? 0),
            'active' => (int) ($ciasteczko['active'] ?? 1),
            'date_add' => $teraz,
        ]);

        $this->zapiszJezyki(
            Schema::TABLE_COOKIE_LANG,
            'id_cmp_cookie',
            (int) $db->Insert_ID(),
            'description',
            (array) ($ciasteczko['descriptions'] ?? [])
        );
    }

    /**
     * @param array<string,mixed> $skrypt
     */
    private function zapiszSkrypt(array $skrypt, int $idShop): void
    {
        Db::getInstance()->insert(Schema::TABLE_SCRIPT, [
            'id_shop' => $idShop,
            'name' => pSQL((string) ($skrypt['name'] ?? '')),
            'embedding' => pSQL((string) ($skrypt['embedding'] ?? '')),
            'technique' => pSQL((string) ($skrypt['technique'] ?? '')),
            'category' => pSQL((string) ($skrypt['category'] ?? '')),
            'pattern' => pSQL((string) ($skrypt['pattern'] ?? '')),
            'position' => (int) ($skrypt['position'] ?? 0),
            'active' => (int) ($skrypt['active'] ?? 1),
        ]);
    }

    /**
     * Zapisuje wartości językowe, dopasowując języki po kodzie ISO.
     *
     * Sklep docelowy ma zwykle inne identyfikatory języków niż źródłowy, więc
     * po numerach nic by się nie zgadzało. Język, którego docelowy nie ma,
     * jest pomijany — nie zakładamy go za sprzedawcę.
     *
     * @param array<string,string> $wartosci
     */
    private function zapiszJezyki(string $tabela, string $kolumnaId, int $id, string $pole, array $wartosci): void
    {
        if ($id <= 0) {
            return;
        }

        $db = Db::getInstance();
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . $tabela . '` WHERE `' . $kolumnaId . '` = ' . $id);

        foreach ($wartosci as $iso => $wartosc) {
            $idLang = (int) \Language::getIdByIso((string) $iso);

            if ($idLang <= 0) {
                continue;
            }

            $db->insert($tabela, [
                $kolumnaId => $id,
                'id_lang' => $idLang,
                $pole => pSQL((string) $wartosc),
            ]);
        }
    }
}
