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
 * Połączenie anonimowego odwiedzającego z osobą.
 *
 * Zgoda kliknięta w banerze przez gościa zapisuje się z kluczem przeglądarki
 * i bez adresu — bo adresu wtedy nie ma. Ta sama osoba zakłada potem konto albo
 * składa zamówienie i od tej chwili ma adres. Bez zapisanego połączenia oba
 * ślady zostają osobno i na pytanie „co ta osoba wybrała" nie ma pełnej
 * odpowiedzi: brakuje wszystkiego sprzed rejestracji.
 *
 * Łączymy je tutaj, w osobnej tabeli. Dziennik zostaje nietknięty — jest
 * dowodem i nie wolno go przepisywać wstecz. Zapis „ten klucz należał do tego
 * adresu" jest osobnym faktem, z własną datą, i tak samo daje się podważyć.
 */
final class VisitorLink
{
    /**
     * Zapisuje, że klucz przeglądarki należy do tej osoby.
     *
     * Wielokrotne wywołanie nie tworzy duplikatu: klucz jest kluczem głównym,
     * a powtórzenie odświeża jedynie identyfikator klienta, bo gość może
     * najpierw zamówić bez konta, a założyć je później.
     */
    public static function stitch(int $idShop, string $visitorKey, string $email, int $idCustomer = 0): bool
    {
        if (!self::kluczPoprawny($visitorKey) || $email === '' || !\Validate::isEmail($email)) {
            return false;
        }

        $teraz = date('Y-m-d H:i:s');

        return (bool) Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . Schema::TABLE_VISITOR . '`
                (`visitor_key`, `id_shop`, `email`, `id_customer`, `date_add`, `date_stitched`)
             VALUES ("' . pSQL($visitorKey) . '", ' . $idShop . ', "' . pSQL($email) . '", ' . $idCustomer . ',
                     "' . $teraz . '", "' . $teraz . '")
             ON DUPLICATE KEY UPDATE
                `id_customer` = IF(VALUES(`id_customer`) > 0, VALUES(`id_customer`), `id_customer`),
                `date_stitched` = "' . $teraz . '"'
        );
    }

    /**
     * Adres przypisany do klucza przeglądarki, jeśli już go znamy.
     *
     * Używane przy zapisie zgody: gość, który raz się przedstawił, nie musi
     * robić tego ponownie przy każdej kolejnej zmianie ustawień.
     */
    public static function emailFor(string $visitorKey, int $idShop): string
    {
        if (!self::kluczPoprawny($visitorKey)) {
            return '';
        }

        return (string) Db::getInstance()->getValue(
            'SELECT `email` FROM `' . _DB_PREFIX_ . Schema::TABLE_VISITOR . '`
              WHERE `visitor_key` = "' . pSQL($visitorKey) . '" AND `id_shop` = ' . $idShop
        );
    }

    /** Ile kluczy przeglądarki zna ta osoba. Do odczytu na karcie profilu. */
    public static function countFor(string $email): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_VISITOR . '`
              WHERE `email` = "' . pSQL($email) . '"'
        );
    }

    private static function kluczPoprawny(string $klucz): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $klucz) === 1;
    }
}
