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
use Configuration;
use Quince\Cmp\ApiPlatform\Resources\ConsentType;
use Quince\Cmp\Storage\ConsentTypes;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lista rodzajów zgód czytana przez Admin API.
 *
 * Woła dokładnie to samo `ConsentTypes::all()`, co ekran „Rodzaje zgód".
 *
 * @implements ProviderInterface<ConsentType>
 */
final class ConsentTypeProvider implements ProviderInterface
{
    public function __construct(
        private readonly ConsentTypes $typy,
        private readonly RequestStack $zadania
    ) {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     *
     * @return array<int,ConsentType>|ConsentType|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|ConsentType|null
    {
        $idShop = $this->idShop();

        if ($idShop === null) {
            return isset($uriVariables['code']) ? null : [];
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        if (isset($uriVariables['code'])) {
            $kod = (string) $uriVariables['code'];

            foreach ($this->typy->all($idShop, $idLang) as $wiersz) {
                if ($wiersz['code'] === $kod) {
                    return $this->zasob($wiersz, $idShop);
                }
            }

            return null;
        }

        $zadanie = $this->zadania->getCurrentRequest();
        $kind = $zadanie !== null ? trim((string) $zadanie->query->get('kind', '')) : '';

        return array_map(
            fn (array $wiersz): ConsentType => $this->zasob($wiersz, $idShop),
            $this->typy->all($idShop, $idLang, '', $kind)
        );
    }

    /** @param array<string,mixed> $wiersz */
    private function zasob(array $wiersz, int $idShop): ConsentType
    {
        $typ = new ConsentType();
        $typ->code = (string) $wiersz['code'];
        $typ->kind = (string) $wiersz['kind'];
        $typ->required = (bool) $wiersz['required'];
        $typ->position = (int) $wiersz['position'];
        $typ->active = (bool) $wiersz['active'];
        $typ->name = (string) $wiersz['name'];
        $typ->version = $wiersz['version'] !== null ? (string) $wiersz['version'] : null;
        $typ->versionFrom = $wiersz['date_from'] !== null
            ? (new \DateTime((string) $wiersz['date_from'], new \DateTimeZone(date_default_timezone_get())))->format('c')
            : null;
        $typ->shopId = $idShop;

        return $typ;
    }

    private function idShop(): ?int
    {
        $zadanie = $this->zadania->getCurrentRequest();
        $wartosc = $zadanie !== null ? $zadanie->query->get('shopId') : null;

        return $wartosc !== null && $wartosc !== '' ? (int) $wartosc : null;
    }
}
