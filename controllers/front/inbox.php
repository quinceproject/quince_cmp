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

use Quince\Cmp\Sync\Inbox;

/**
 * Punkt przyjmowania zmian zgody z systemu zewnętrznego.
 *
 * Odpowiada JSON-em i nie renderuje szablonu. Uwierzytelnienie opiera się
 * wyłącznie na podpisie ciała żądania — bez sesji i bez tokenu z adresu, bo
 * wywołujący jest serwerem, nie przeglądarką.
 */
class Quince_CmpInboxModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    public function postProcess(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->wyslij(405, ['error' => 'Use POST.']);
        }

        $wynik = (new Inbox())->handle(
            (int) $this->context->shop->id,
            (string) file_get_contents('php://input'),
            $this->naglowki()
        );

        $this->wyslij($wynik['status'], $wynik['body']);
    }

    /**
     * Nagłówki żądania w zapisie małymi literami.
     *
     * `getallheaders()` nie istnieje w każdym środowisku (FPM bywa bez niego),
     * więc czytamy z `$_SERVER`.
     *
     * @return array<string,string>
     */
    private function naglowki(): array
    {
        $out = [];

        foreach ($_SERVER as $klucz => $wartosc) {
            if (!is_string($klucz) || !str_starts_with($klucz, 'HTTP_')) {
                continue;
            }

            $nazwa = strtolower(str_replace('_', '-', substr($klucz, 5)));
            $out[$nazwa] = (string) $wartosc;
        }

        return $out;
    }

    /** @param array<string,mixed> $cialo */
    private function wyslij(int $status, array $cialo): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        echo json_encode($cialo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
