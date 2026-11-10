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
use Quince\Cmp\ApiPlatform\Provider\ConsentStateProvider;

/**
 * Stan zgód jednej osoby, czytany przez Admin API PrestaShopa.
 *
 * **Dlaczego przez API rdzenia, a nie własnym kluczem.** PrestaShop 9 ma
 * uwierzytelnianie OAuth2, ekran klientów API, zakresy uprawnień, wymianę
 * sekretu i dokumentację OpenAPI generowaną z tych klas. Własny mechanizm
 * kluczy w module powtarzałby to gorzej i rozpraszał integracje sklepu po
 * kilku ekranach.
 *
 * Rdzeń wczytuje ten katalog sam — `PrestaShopExtension` szuka zasobów w
 * `<moduł>/src/ApiPlatform/Resources`. Zakres z `scopes:` wędruje do
 * `extraProperties` i pojawia się na liście uprawnień przy tworzeniu klienta
 * API, a przy żądaniu sprawdza go `ScopeCheckerListener`.
 *
 * **Adres identyfikuje osobę adresem e-mail**, bo tak identyfikuje ją zgoda:
 * jedna osoba w całej grupie sklepów, a zgoda udzielona jednemu sklepowi nie
 * jest zgodą udzieloną drugiemu — stąd stan rozbity po sklepach.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/quince-cmp/consents/{email}',
            provider: ConsentStateProvider::class,
            extraProperties: ['scopes' => ['quince_cmp_consent_read']],
            openapiContext: [
                'summary' => 'Current consent state of one person',
                'description' => 'The last recorded answer for every consent type, per shop. '
                    . 'Nothing here is derived from a cookie: the state comes from the consent log, '
                    . 'which is append-only.',
            ],
        ),
    ],
)]
class ConsentState
{
    /** Adres e-mail osoby. Zgoda bez adresu jest przypięta do przeglądarki i tą drogą nie wychodzi. */
    #[ApiProperty(identifier: true)]
    public string $email;

    /**
     * Stan po kodach zgody.
     *
     * @var array<string,array<int,array{granted:bool,version:string,recordedAt:string,source:string,current:bool}>>
     */
    public array $consents = [];
}
