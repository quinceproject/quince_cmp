<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\ApiPlatform\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Quince\Cmp\ApiPlatform\Resources\ConsentState;
use Quince\Cmp\Storage\ConsentRegister;

/**
 * Skąd Admin API bierze stan zgód jednej osoby.
 *
 * Czyta ten sam rejestr, co ekran profilu zgody — nie ma drugiego źródła prawdy
 * i nie ma zapytania pisanego osobno pod API. Gdyby były dwa, prędzej czy później
 * odpowiadałyby co innego, a różnicy nikt by nie zauważył.
 *
 * **Zwracamy podzbiór.** Adres IP, nagłówek przeglądarki i klucz odwiedzającego
 * zostają w sklepie: system zewnętrzny pyta, na co osoba się zgodziła, a nie
 * z jakiego łącza wtedy korzystała. Wychodzi to, co jest odpowiedzią, plus numer
 * wersji i skrót treści — czyli dowód, do czego ta odpowiedź się odnosiła.
 *
 * @implements ProviderInterface<ConsentState>
 */
final class ConsentStateProvider implements ProviderInterface
{
    public function __construct(private readonly ConsentRegister $rejestr)
    {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?ConsentState
    {
        $email = trim((string) ($uriVariables['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $stan = new ConsentState();
        $stan->email = $email;

        // Cała grupa sklepów, bo zgoda jest sprawą osoby, a osoba nie należy
        // do sklepu. Rozbicie po sklepach zostaje w odpowiedzi.
        $macierz = $this->rejestr->matrix($email, \Shop::getShops(false, null, true));

        foreach ($macierz['cells'] as $kod => $poSklepach) {
            foreach ($poSklepach as $idShop => $komorka) {
                $stan->consents[(string) $kod][(int) $idShop] = [
                    'granted' => (bool) $komorka['granted'],
                    'version' => (string) $komorka['version'],
                    'textHash' => (string) $komorka['text_hash'],
                    'recordedAt' => self::iso((string) $komorka['date']),
                    'source' => (string) $komorka['source'],
                    'origin' => (string) $komorka['origin'],
                    // Zgoda na treść, która od tamtej pory się zmieniła. Dla systemu
                    // zewnętrznego to sygnał, że tę osobę trzeba zapytać ponownie.
                    'outdated' => (bool) $komorka['outdated'],
                ];
            }
        }

        return $stan;
    }

    /** Czas sklepu z offsetem, zamiast gołej daty MySQL. */
    private static function iso(string $mysql): string
    {
        return (new \DateTime($mysql, new \DateTimeZone(date_default_timezone_get())))->format('c');
    }
}
