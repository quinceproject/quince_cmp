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
use ApiPlatform\Metadata\GetCollection;
use Quince\Cmp\ApiPlatform\Provider\ConsentVersionProvider;

/**
 * Jedna wersja treści klauzuli jednego rodzaju zgody.
 *
 * Zdarzenie zgody niesie `textHash` — skrót treści pokazanej w chwili
 * odpowiedzi — nie samą treść: dowód zostaje w sklepie. System zewnętrzny,
 * który chce przechować treść obok zgody (nie tylko sam skrót), czyta ją
 * stąd, dopasowując po `textHash` do pozycji w `texts`.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/quince-cmp/consent-versions',
            provider: ConsentVersionProvider::class,
            extraProperties: ['scopes' => ['quince_cmp_consent_read']],
            openapiContext: [
                'summary' => 'Version history of one consent type, newest first',
                'description' => 'Requires `code` and `shopId`. A closed-text document only; the one '
                    . 'type it belongs to is NOT repeated per row, since the caller already asked for it '
                    . 'through `code`.',
            ],
        ),
    ],
)]
class ConsentVersion
{
    #[ApiProperty(identifier: true)]
    public int $id = 0;

    public string $version = '';

    public string $dateFrom = '';

    /** `null` oznacza wersję wciąż obowiązującą. */
    public ?string $dateTo = null;

    public bool $inForce = false;

    /** Adres pliku PDF tej wersji, jeśli sprzedawca go wgrał. Pusty, gdy nie ma. */
    public string $pdf = '';

    /**
     * Treść per język.
     *
     * @var array<int,array{iso:string,shortLabel:string,fullClause:string,textHash:string}>
     */
    public array $texts = [];
}
