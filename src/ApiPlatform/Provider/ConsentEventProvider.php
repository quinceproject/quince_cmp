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
use Quince\Cmp\ApiPlatform\Resources\ConsentEvent;
use Quince\Cmp\Storage\ConsentRegister;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Dziennik zgód czytany przez Admin API.
 *
 * Te same zapytania, te same filtry i ta sama stronicowość co ekran dziennika —
 * bo to ten sam dziennik. Osobne zapytanie pisane pod API znaczyłoby, że sklep
 * ma dwie wersje własnej historii.
 *
 * **Adresu IP i nagłówka przeglądarki nie oddajemy.** Są w dzienniku po to, żeby
 * sklep mógł udowodnić okoliczności zgody, gdy ktoś ją zakwestionuje — a nie po
 * to, żeby wyjeżdżały z niego przy każdym odpytaniu. Kto ich potrzebuje, ma
 * ekran dziennika i eksport CSV, oba pod kontrolą uprawnień pracownika.
 *
 * @implements ProviderInterface<ConsentEvent>
 */
final class ConsentEventProvider implements ProviderInterface
{
    /** Ile zdarzeń oddajemy na stronę. Tyle samo, co widzi obsługa na ekranie. */
    private const NA_STRONE = ConsentRegister::NA_STRONE;

    public function __construct(
        private readonly ConsentRegister $rejestr,
        private readonly RequestStack $zadania
    ) {
    }

    /**
     * @param array<string,mixed> $uriVariables
     * @param array<string,mixed> $context
     *
     * @return array<int,ConsentEvent>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|ConsentEvent|null
    {
        // Operacja pojedyncza podaje identyfikator w adresie; zbiorcza nie.
        if (isset($uriVariables['eventId'])) {
            return $this->jedno((int) $uriVariables['eventId']);
        }

        $zadanie = $this->zadania->getCurrentRequest();
        $pytanie = $zadanie !== null ? $zadanie->query->all() : [];

        // Filtry przepuszczamy przez białą listę. Rejestr składa z nich warunek
        // SQL, więc przekazanie tam czegokolwiek z żądania byłoby otwarciem
        // zapytania na wartości, których nikt nie przewidział.
        $filtry = [];

        foreach (['email', 'code', 'source', 'origin', 'from', 'to', 'granted', 'shop', 'afterId'] as $nazwa) {
            $wartosc = trim((string) ($pytanie[$nazwa] ?? ''));

            if ($wartosc !== '') {
                $filtry[$nazwa] = $wartosc;
            }
        }

        $strona = max(1, (int) ($pytanie['page'] ?? 1));
        $out = [];

        foreach ($this->rejestr->stream($this->sklepy(), $filtry, $strona) as $wiersz) {
            $out[] = $this->zZapisu($wiersz);
        }

        return $out;
    }

    /** Jedno zdarzenie po identyfikatorze, albo `null`, gdy go nie ma. */
    private function jedno(int $idZdarzenia): ?ConsentEvent
    {
        $wiersz = $this->rejestr->event($idZdarzenia, $this->sklepy());

        return $wiersz === null ? null : $this->zZapisu($wiersz);
    }

    /**
     * Wiersz dziennika na zasób API. Jedno miejsce dla listy i dla pojedynczego
     * zdarzenia — inaczej te same dane wychodziłyby z API w dwóch kształtach.
     *
     * @param array<string,mixed> $wiersz
     */
    private function zZapisu(array $wiersz): ConsentEvent
    {
        $zdarzenie = new ConsentEvent();
        $zdarzenie->eventId = (int) $wiersz['id_cmp_log'];
        $zdarzenie->code = (string) $wiersz['code'];
        $zdarzenie->granted = (bool) $wiersz['granted'];
        $zdarzenie->email = (string) $wiersz['email'];
        $zdarzenie->version = (string) $wiersz['version'];
        $zdarzenie->textHash = (string) $wiersz['text_hash'];
        $zdarzenie->recordedAt = self::iso((string) $wiersz['date_add']);
        $zdarzenie->source = (string) $wiersz['source'];
        $zdarzenie->origin = (string) $wiersz['origin'];
        $zdarzenie->shopId = (int) ($wiersz['id_shop'] ?? 0);
        $zdarzenie->externalId = (string) ($wiersz['external_id'] ?? '');

        return $zdarzenie;
    }

    /**
     * @return array<int,int>
     */
    private function sklepy(): array
    {
        return array_map('intval', \Shop::getShops(false, null, true));
    }

    /** Czas sklepu z offsetem, zamiast gołej daty MySQL. */
    private static function iso(string $mysql): string
    {
        return (new \DateTime($mysql, new \DateTimeZone(date_default_timezone_get())))->format('c');
    }
}
