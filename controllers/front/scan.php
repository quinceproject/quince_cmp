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

use Quince\Cmp\Scan\Scanner;

/**
 * Meldunek zbieracza skanera.
 *
 * Przyjmuje wyłącznie żądania z przeglądarki, która ma ważny token skanowania.
 * Bez tego dowolny odwiedzający mógłby zapełnić tabelę znalezisk czymkolwiek.
 */
class Quince_CmpScanModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    public function postProcess(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->wyslij(405, ['error' => 'Use POST.']);
        }

        $idShop = (int) $this->context->shop->id;
        $skaner = new Scanner();

        if (!$skaner->wTrybie($idShop)) {
            $this->wyslij(403, ['error' => 'No scan in progress.']);
        }

        $dane = json_decode((string) Tools::getValue('findings', '[]'), true);

        if (!is_array($dane)) {
            $this->wyslij(400, ['error' => 'Findings must be a JSON array.']);
        }

        // Górna granica na jeden meldunek: strona z setką zasobów nie może
        // jednym żądaniem zapchać tabeli.
        $ile = $skaner->record($idShop, array_slice($dane, 0, 200), (string) Tools::getValue('page', '/'));

        $this->wyslij(200, ['ok' => true, 'stored' => $ile]);
    }

    /** @param array<string,mixed> $cialo */
    private function wyslij(int $status, array $cialo): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        echo json_encode($cialo);
        exit;
    }
}
