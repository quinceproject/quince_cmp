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

use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Retention;
use Quince\Cmp\Storage\SyncQueue;
use Quince\Cmp\Sync\SyncConfig;
use Quince\Cmp\Sync\Transport;

/**
 * Wyzwalacz wysyłki dla harmonogramu systemowego.
 *
 * PrestaShop nie ma własnego zegara, więc przebieg uruchamia cron serwera albo
 * zewnętrzny monitor. Adres chroni token wyprowadzony z klucza podpisu — bez
 * niego każdy mógłby wywoływać przebieg dowolnie często.
 *
 * Przebieg robi dwie rzeczy: wysyła to, co czeka w kolejce, i kasuje zdarzenia,
 * które przekroczyły termin retencji. Retencja nie zależy od tego, czy wymiana
 * jest w ogóle włączona — termin przechowywania danych obowiązuje sklep
 * niezależnie od tego, czy wysyła je gdziekolwiek.
 */
class Quince_CmpCronModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    public function postProcess(): void
    {
        $idShop = (int) $this->context->shop->id;

        if (!hash_equals(self::token($idShop), (string) Tools::getValue('token'))) {
            $this->wyslij(403, ['error' => 'Bad token.']);
        }

        $wynik = (new Transport(new SyncQueue()))->run($idShop);

        // Retencja idzie tym samym przebiegiem: to jedyne miejsce, w którym
        // moduł dostaje regularny sygnał czasu. Bez tego termin z ustawień
        // byłby obietnicą, za którą nic nie stoi.
        $wynik['retention'] = (new Retention())->run($idShop);

        $this->wyslij($wynik['skipped'] && $wynik['retention']['events'] === 0 ? 503 : 200, $wynik);
    }

    /**
     * Token przebiegu.
     *
     * Wyprowadzony z klucza podpisu i identyfikatora sklepu, żeby wymiana klucza
     * unieważniała także stary adres crona.
     */
    public static function token(int $idShop): string
    {
        return substr(hash_hmac(
            'sha256',
            'cron.' . $idShop,
            SyncConfig::text(Options::SYNC_KEY_OUT, '', $idShop) ?: _COOKIE_KEY_
        ), 0, 32);
    }

    /** @param array<string,mixed> $cialo */
    private function wyslij(int $status, array $cialo): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        echo json_encode($cialo);
        exit;
    }
}
