<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\ApiPlatform\Resources;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Quince\Cmp\ApiPlatform\Processor\ConsentEventProcessor;
use Quince\Cmp\ApiPlatform\Provider\ConsentEventProvider;

/**
 * Zdarzenie zgody: jedna odpowiedź jednej osoby, w jednej chwili.
 *
 * Dziennik jest **dopisywany, nigdy nadpisywany**: nie ma tu operacji zmiany
 * ani usunięcia. Wycofanie zgody to nowe zdarzenie z `granted: false`, a nie
 * skasowanie poprzedniego.
 *
 * Dwa zakresy: odczyt oddaje historię decyzji klientów, zapis pozwala odnotować
 * decyzję podjętą poza sklepem. System, który tylko czyta, nie może pisać.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/quince-cmp/consent-events',
            provider: ConsentEventProvider::class,
            extraProperties: ['scopes' => ['quince_cmp_consent_read']],
            openapiContext: [
                'summary' => 'Consent events, newest first',
                'description' => 'The append-only log. Filter with `code`, `email`, `from` and `to`; '
                    . 'page with `page`. For incremental polling use `afterId` instead: it switches '
                    . 'ordering to ascending by id and ignores `page`, since the cursor already knows '
                    . 'where the last request stopped. One page holds at most 100 events.',
            ],
        ),
        // Odczyt pojedynczego zdarzenia. Potrzebny nie tylko sam dla siebie:
        // bez niego API Platform nie umie zbudować adresu zasobu utworzonego
        // przez `POST` i odpowiedź wywraca się mimo poprawnego zapisu.
        new Get(
            uriTemplate: '/quince-cmp/consent-events/{eventId}',
            provider: ConsentEventProvider::class,
            extraProperties: ['scopes' => ['quince_cmp_consent_read']],
            openapiContext: ['summary' => 'One consent event by id'],
        ),
        new Post(
            uriTemplate: '/quince-cmp/consent-events',
            processor: ConsentEventProcessor::class,
            extraProperties: ['scopes' => ['quince_cmp_consent_write']],
            openapiContext: [
                'summary' => 'Record a decision made outside the shop',
                'description' => 'Requires `shopId` as a query parameter; the body\'s own `shopId` is '
                    . 'ignored, the same convention as every other write in this API. Otherwise the same '
                    . 'shape the shop sends out. State is derived from the newest event, so a withdrawal is '
                    . 'this call with `granted: false`. A conflict with a newer local event answers 409 and '
                    . 'is written down with both sides.',
            ],
        ),
    ],
)]
class ConsentEvent
{
    #[ApiProperty(identifier: true)]
    public ?int $eventId = null;

    /** Kod rodzaju zgody, np. `cookies_marketing`. */
    public string $code = '';

    /** Czy zgoda została udzielona. Wycofanie to `false`, nie brak zdarzenia. */
    public bool $granted = false;

    public string $email = '';

    /** Numer wersji klauzuli obowiązującej w chwili odpowiedzi. */
    public string $version = '';

    /** Skrót treści, którą wtedy pokazano. Bez niego zdarzenie dowodzi kliknięcia, nie zgody. */
    public string $textHash = '';

    /** Kiedy decyzja zapadła po tamtej stronie, nie kiedy do nas dotarła. */
    public string $recordedAt = '';

    /** Skąd: baner, kasa, rejestracja, konto, newsletter albo system zewnętrzny. */
    public string $source = '';

    /** Skąd przyszło zdarzenie: `shop` (ze sklepu) albo `external` (z zewnątrz, np. przez ten kanał). */
    public string $origin = '';

    public int $shopId = 0;

    /**
     * Identyfikator zdarzenia po stronie systemu zewnętrznego, jeśli go podał.
     * Pozwala odbiorcy rozpoznać własne zdarzenie i nie zaimportować go
     * ponownie przy kolejnym odpytaniu (echo).
     */
    public string $externalId = '';
}
