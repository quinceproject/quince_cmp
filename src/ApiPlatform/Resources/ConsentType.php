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
use Quince\Cmp\ApiPlatform\Provider\ConsentTypeProvider;

/**
 * Rodzaj zgody tego sklepu: `newsletter`, `terms_shop`, `cookies_marketing`
 * i tak dalej.
 *
 * Potrzebny systemowi zewnętrznemu do mapowania WŁASNYCH rodzajów zgody na
 * kody tego sklepu — bez tego mapowanie trzeba by trzymać twardo zakodowane
 * po drugiej stronie, a każda zmiana w panelu (nowy kod, zmiana nazwy)
 * wymagałaby ręcznej synchronizacji.
 *
 * Rodzaj `cookie` jest tu widoczny tak samo jak każdy inny — filtrowanie go
 * poza zainteresowaniem systemu zewnętrznego jest decyzją WYWOŁUJĄCEGO
 * (parametr `kind`), nie czymś, co ten zasób narzuca.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/quince-cmp/consent-types',
            provider: ConsentTypeProvider::class,
            extraProperties: ['scopes' => ['quince_cmp_consent_read']],
            openapiContext: [
                'summary' => 'Consent types this shop collects',
                'description' => 'Requires `shopId`. Filter with `kind` (`cookie`, `form`, `document`) '
                    . 'to leave out a category, e.g. `cookie`, which a system outside the browser '
                    . 'has no use for.',
            ],
        ),
        new Get(
            uriTemplate: '/quince-cmp/consent-types/{code}',
            provider: ConsentTypeProvider::class,
            extraProperties: ['scopes' => ['quince_cmp_consent_read']],
            openapiContext: [
                'summary' => 'One consent type by code',
                'description' => 'Requires `shopId`.',
            ],
        ),
    ],
)]
class ConsentType
{
    #[ApiProperty(identifier: true)]
    public string $code = '';

    /** `cookie`, `form` albo `document`. */
    public string $kind = '';

    public bool $required = false;

    /** Kolejność na liście w panelu. */
    public int $position = 0;

    public bool $active = false;

    /** Nazwa w języku domyślnym sklepu; kod, gdy tłumaczenia brak. */
    public string $name = '';

    /** Numer wersji obowiązującej dziś, albo `null`, gdy nic nie opublikowano. */
    public ?string $version = null;

    /** Kiedy ta wersja weszła w życie. */
    public ?string $versionFrom = null;

    public int $shopId = 0;
}
