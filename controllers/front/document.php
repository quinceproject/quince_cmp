<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Cmp\Storage\PdfStore;
use Quince\Cmp\Storage\Schema;

/**
 * Wydawanie dokumentu dołączonego do wersji klauzuli.
 *
 * Plik leży poza katalogiem dostępnym z sieci, więc wychodzi tędy. Dostęp jest
 * otwarty celowo: to jest treść, na którą ktoś się zgodził, i musi być możliwa
 * do przeczytania także przez osobę, która nie ma konta w sklepie.
 *
 * Otwarte nie znaczy odgadywalne: adres wskazuje wersję po identyfikatorze,
 * a wydajemy wyłącznie wersje należące do bieżącego sklepu.
 */
class Quince_CmpDocumentModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    public function postProcess(): void
    {
        $idVersion = (int) Tools::getValue('version', 0);

        if ($idVersion <= 0) {
            $this->brak();
        }

        $nazwa = (string) Db::getInstance()->getValue(
            'SELECT v.`pdf`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_VERSION . '` v
               INNER JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t ON t.`id_cmp_type` = v.`id_cmp_type`
              WHERE v.`id_cmp_version` = ' . $idVersion . '
                AND t.`id_shop` = ' . (int) $this->context->shop->id
        );

        $sciezka = $nazwa !== '' ? PdfStore::path($nazwa) : null;

        if ($sciezka === null) {
            $this->brak();
        }

        // `inline`, nie `attachment`: dokument ma się otworzyć, a nie pobrać.
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($sciezka));
        header('Content-Disposition: inline; filename="consent-' . $idVersion . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        readfile($sciezka);
        exit;
    }

    private function brak(): void
    {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
}
