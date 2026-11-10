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
 * Ciasteczka, które ustawia sam PrestaShop.
 *
 * Zestaw startowy tabeli ciasteczek. Wchodzą tu **wyłącznie te, które istnieją
 * w każdym sklepie na tym silniku** — nie „popularne ciasteczka z internetu".
 * Tabela ciasteczek jest oświadczeniem wobec klienta: mówi, co ten sklep u niego
 * zapisuje. Wpisanie tam Google Analytics w sklepie, który go nie ma, jest
 * nieprawdą wobec klienta, a nie ułatwieniem dla sprzedawcy. Resztę znajduje
 * skaner, bo tylko on widzi, co naprawdę się dzieje na stronie.
 *
 * Nazwy sprawdzone na PrestaShop 9.2: `PHPSESSID` z konfiguracji PHP oraz
 * ciasteczko sklepu z `classes/Cookie.php`, gdzie nazwa powstaje jako
 * `'PrestaShop-' . md5(wersja . nazwa . domena)`. Skrót jest inny w każdej
 * instalacji, więc w tabeli idzie jako wzorzec z gwiazdką — dokładnie po to
 * ten wzorzec w module jest.
 *
 * Obu tych ciasteczek nie da się odrzucić: bez nich nie ma koszyka ani sesji.
 * Dlatego trafiają do kategorii wymaganej.
 */
final class DefaultCookies
{
    /**
     * @var array<int,array{name:string,provider:string,category:string,value:int,unit:string,pl:string,en:string}>
     */
    private const COOKIES = [
        [
            'name' => 'PHPSESSID',
            'provider' => 'PrestaShop',
            'category' => 'cookies_necessary',
            'value' => 0,
            'unit' => 'session',
            'en' => 'Keeps the PHP session that ties one visit together. Without it the shop cannot tell one page view from the next.',
            'pl' => 'Utrzymuje sesję PHP, która spina jedną wizytę w całość. Bez niego sklep nie odróżnia kolejnych odsłon od siebie.',
        ],
        [
            'name' => 'PrestaShop-*',
            'provider' => 'PrestaShop',
            'category' => 'cookies_necessary',
            'value' => 20,
            'unit' => 'days',
            'en' => 'Carries the cart, the chosen language and currency between pages, and keeps a signed-in customer signed in. The name ends with a hash of the shop address, so it differs from shop to shop.',
            'pl' => 'Przenosi koszyk, wybrany język i walutę między stronami, a zalogowanego klienta trzyma zalogowanym. Nazwa kończy się skrótem adresu sklepu, więc w każdym sklepie brzmi inaczej.',
        ],
    ];

    /**
     * Zakłada zestaw w każdym sklepie. Pomija wpisy, które już są — ponowna
     * instalacja nie kasuje opisów poprawionych ręcznie.
     */
    public function seedAllShops(): bool
    {
        foreach (\Shop::getShops(false, null, true) as $idShop) {
            if (!$this->seedForShop((int) $idShop)) {
                return false;
            }
        }

        return true;
    }

    public function seedForShop(int $idShop): bool
    {
        $magazyn = new Cookies();
        $jezyki = Language::getLanguages(false);

        foreach (self::COOKIES as $opis) {
            if ($this->istnieje($opis['name'], $idShop)) {
                continue;
            }

            $opisy = [];

            foreach ($jezyki as $jezyk) {
                $kod = strtolower(substr((string) $jezyk['iso_code'], 0, 2));
                $opisy[(int) $jezyk['id_lang']] = $kod === 'pl' ? $opis['pl'] : $opis['en'];
            }

            $ok = $magazyn->save(
                0,
                $idShop,
                $opis['name'],
                $opis['provider'],
                $opis['category'],
                $opis['value'],
                $opis['unit'],
                $opisy,
                Cookies::SOURCE_MANUAL
            );

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function istnieje(string $nazwa, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '`
              WHERE `id_shop` = ' . $idShop . ' AND `name` = "' . pSQL($nazwa) . '"'
        );
    }
}
