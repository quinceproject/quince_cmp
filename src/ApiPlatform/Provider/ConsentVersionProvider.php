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
use Quince\Cmp\ApiPlatform\Resources\ConsentVersion;
use Quince\Cmp\Storage\ConsentTypes;
use Quince\Cmp\Storage\ConsentVersions;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Historia wersji jednej klauzuli, czytana przez Admin API.
 *
 * `ConsentVersions::forType()` pracuje po `id_cmp_type`, nie po kodzie — ten
 * dostawca rozwiązuje kod na typ przez `ConsentTypes`, tak jak robi to panel,
 * zamiast pisać drugie zapytanie pod to samo dopasowanie.
 *
 * @implements ProviderInterface<ConsentVersion>
 */
final class ConsentVersionProvider implements ProviderInterface
{
    public function __construct(
        private readonly ConsentVersions $wersje,
        private readonly ConsentTypes $typy,
        private readonly RequestStack $zadania
    ) {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     *
     * @return array<int,ConsentVersion>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $zadanie = $this->zadania->getCurrentRequest();
        $pytanie = $zadanie !== null ? $zadanie->query->all() : [];

        $idShop = isset($pytanie['shopId']) && $pytanie['shopId'] !== '' ? (int) $pytanie['shopId'] : null;
        $kod = trim((string) ($pytanie['code'] ?? ''));

        if ($idShop === null || $kod === '') {
            return [];
        }

        $idTypu = $this->rozwiazKod($kod, $idShop);

        if ($idTypu === null) {
            return [];
        }

        return array_map(
            fn (array $wiersz): ConsentVersion => $this->zasob($wiersz),
            $this->wersje->forType($idTypu, $kod, $idShop)
        );
    }

    private function rozwiazKod(string $kod, int $idShop): ?int
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        foreach ($this->typy->all($idShop, $idLang) as $typ) {
            if ($typ['code'] === $kod) {
                return (int) $typ['id_cmp_type'];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $wiersz z {@see ConsentVersions::forType()} */
    private function zasob(array $wiersz): ConsentVersion
    {
        $wersja = new ConsentVersion();
        $wersja->id = (int) $wiersz['id_cmp_version'];
        $wersja->version = (string) $wiersz['version'];
        $wersja->dateFrom = self::iso((string) $wiersz['date_from']);
        $wersja->dateTo = $wiersz['date_to'] !== null ? self::iso((string) $wiersz['date_to']) : null;
        $wersja->inForce = (bool) $wiersz['in_force'];
        $wersja->pdf = (string) $wiersz['pdf'];

        $wersja->texts = array_map(static fn (array $h): array => [
            'iso' => (string) $h['iso'],
            'shortLabel' => (string) $h['short_label'],
            'fullClause' => (string) $h['full_clause'],
            'textHash' => (string) $h['hash'],
        ], $wiersz['hashes']);

        return $wersja;
    }

    private static function iso(string $mysql): string
    {
        return (new \DateTime($mysql, new \DateTimeZone(date_default_timezone_get())))->format('c');
    }
}
