<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\ApiPlatform\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Quince\Cmp\ApiPlatform\Resources\ConsentEvent;
use Quince\Cmp\Sync\Inbox;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Zapis decyzji podjętej poza sklepem, przez Admin API.
 *
 * **Nie ma tu własnej ścieżki zapisu.** Zdarzenie idzie przez `Inbox::accept()`,
 * czyli przez to samo sprawdzanie pól i to samo rozstrzyganie konfliktów, co
 * webhook. Dziennik zgód jest dowodem — musi znaczyć to samo niezależnie od tego,
 * którymi drzwiami zdarzenie weszło.
 *
 * Różnica jest jedna i dotyczy uwierzytelnienia: webhook sprawdza podpis HMAC,
 * tutaj tożsamość potwierdziła już platforma po OAuth2. Reszta jest wspólna.
 *
 * @implements ProcessorInterface<ConsentEvent, ConsentEvent>
 */
final class ConsentEventProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Inbox $skrzynka,
        private readonly RequestStack $zadania
    ) {
    }

    /**
     * @param ConsentEvent        $data
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ConsentEvent
    {
        // `shopId` rozstrzyga sklep, do którego trafia zdarzenie — bierzemy go
        // wyłącznie z query stringu żądania (ten sam wzorzec co pozostałe
        // zasoby API tego modułu), nigdy z ciała. Ciało jest tym samym
        // obiektem `ConsentEvent`, który klient przysyła — ufanie jego
        // `shopId` pozwoliłoby zapisać dowód zgody do dziennika innego sklepu.
        $idShop = $this->idShop();

        if ($idShop === null) {
            throw new UnprocessableEntityHttpException('shopId is required.');
        }

        $odpowiedz = $this->skrzynka->accept($idShop, [
            'code' => $data->code,
            'granted' => $data->granted,
            'email' => $data->email,
            'version' => $data->version,
            'text_hash' => $data->textHash,
            // `occurred_at` — nazwa pola z kontraktu webhooka. Na zewnątrz mówimy
            // `recordedAt`, bo tak brzmi to po ludzku, ale do środka wchodzi ta
            // sama nazwa, której pilnuje `Inbox::brakujacePola()`.
            'occurred_at' => $data->recordedAt,
            'source' => $data->source ?: 'external',
            'external_id' => $data->externalId,
        ]);

        // Kody z `Inbox` przenosimy na wyjątki HTTP, zamiast oddawać własny kształt
        // błędu. Klient API Platform czyta status i standardowe ciało; własny format
        // zmuszałby go do rozpoznawania dwóch.
        if ($odpowiedz['status'] === 409) {
            throw new ConflictHttpException((string) ($odpowiedz['body']['error'] ?? 'Conflict with a newer local event.'));
        }

        if ($odpowiedz['status'] >= 400) {
            throw new UnprocessableEntityHttpException((string) ($odpowiedz['body']['error'] ?? 'The event was rejected.'));
        }

        $data->shopId = $idShop;
        $data->eventId = (int) ($odpowiedz['body']['event_id'] ?? 0);

        return $data;
    }

    private function idShop(): ?int
    {
        $zadanie = $this->zadania->getCurrentRequest();
        $wartosc = $zadanie !== null ? $zadanie->query->get('shopId') : null;

        return $wartosc !== null && $wartosc !== '' ? (int) $wartosc : null;
    }
}
