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
 * Kasowanie zdarzeń starszych niż okres z ustawień.
 *
 * Dziennik jest dowodem, więc nic w nim nie nadpisujemy i niczego nie zmieniamy.
 * Retencja robi jedyną rzecz, jaką wolno: usuwa całe zdarzenia, które
 * przekroczyły termin. To nie jest sprzątanie dla porządku — trzymanie danych
 * osobowych bez terminu jest samo w sobie naruszeniem.
 *
 * Dwa terminy, bo dwie różne rzeczy. Zdarzenie z adresem to ślad po osobie,
 * którą sklep zna; zdarzenie samego gościa wisi na kluczu przeglądarki i traci
 * sens, gdy tej przeglądarki już nie ma. Krótszy termin dla gościa nie odbiera
 * nikomu dowodu, bo dowód i tak nie miałby do kogo się odnieść.
 */
final class Retention
{
    /**
     * Ile wierszy kasujemy w jednym przebiegu.
     *
     * Kasowanie miliona wierszy jednym poleceniem blokuje tabelę na czas,
     * w którym sklep nie zapisze żadnej nowej zgody. Kolejny przebieg dokończy.
     */
    private const PORCJA = 5000;

    /**
     * @return array{events:int,anonymous:int,links:int}
     */
    public function run(int $idShop): array
    {
        $miesiace = Options::int(Options::RETENTION_MONTHS, 36, $idShop);
        $anonimowe = Options::int(Options::ANON_MONTHS, 6, $idShop);

        return [
            'events' => $this->zdarzenia($idShop, $miesiace),
            'anonymous' => $anonimowe < $miesiace ? $this->anonimowe($idShop, $anonimowe) : 0,
            'links' => $this->polaczenia($idShop, $miesiace),
        ];
    }

    /** Ile zdarzeń czeka na skasowanie. Do pokazania w ustawieniach. */
    public function pending(int $idShop): int
    {
        $miesiace = Options::int(Options::RETENTION_MONTHS, 36, $idShop);

        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
              WHERE `id_shop` = ' . $idShop . ' AND `date_add` < "' . $this->granica($miesiace) . '"'
        );
    }

    private function zdarzenia(int $idShop, int $miesiace): int
    {
        return $this->kasuj(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
              WHERE `id_shop` = ' . $idShop . '
                AND `date_add` < "' . $this->granica($miesiace) . '"
              LIMIT ' . self::PORCJA
        );
    }

    /**
     * Zdarzenia bez adresu i bez konta, po krótszym terminie.
     *
     * Warunek celowo obejmuje oba pola: gość, którego później połączono
     * z osobą, ma w dzienniku zdarzenia z adresem i te podlegają terminowi
     * podstawowemu.
     */
    private function anonimowe(int $idShop, int $miesiace): int
    {
        return $this->kasuj(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_LOG . '`
              WHERE `id_shop` = ' . $idShop . '
                AND `email` = "" AND `id_customer` = 0
                AND `date_add` < "' . $this->granica($miesiace) . '"
              LIMIT ' . self::PORCJA
        );
    }

    /**
     * Połączenia klucza przeglądarki z osobą, po terminie podstawowym.
     *
     * Zapis „ten klucz należał do tego adresu" jest daną osobową tak samo jak
     * zdarzenie, więc nie może przeżyć zdarzeń, które opisywał.
     */
    private function polaczenia(int $idShop, int $miesiace): int
    {
        return $this->kasuj(
            'DELETE FROM `' . _DB_PREFIX_ . Schema::TABLE_VISITOR . '`
              WHERE `id_shop` = ' . $idShop . '
                AND `date_add` < "' . $this->granica($miesiace) . '"
              LIMIT ' . self::PORCJA
        );
    }

    private function kasuj(string $zapytanie): int
    {
        $db = Db::getInstance();
        $db->execute($zapytanie);

        return (int) $db->Affected_Rows();
    }

    private function granica(int $miesiace): string
    {
        return pSQL(date('Y-m-d H:i:s', strtotime('-' . max(1, $miesiace) . ' months')));
    }
}
