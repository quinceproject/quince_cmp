<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Integration;

use Hook;

/**
 * Czy jest nowsze wydanie modułu.
 *
 * Dwa źródła, w tej kolejności:
 *
 * 1. **Silnik**, jeśli jest w sklepie. Prowadzi spis wszystkich modułów Quince
 *    i pyta o niego raz, zamiast pozwolić każdemu modułowi pytać osobno.
 * 2. **Spis wydań**, gdy silnika nie ma. Moduł musi działać sam.
 *
 * Rozmowa z silnikiem idzie hookiem, a nie wywołaniem jego klasy. Moduł nie może
 * zależeć od kodu, którego w sklepie może nie być, a hook bez odbiorcy po prostu
 * nic nie zwraca i wtedy schodzimy do spisu.
 *
 * Moduł nigdy nie aktualizuje się sam. Tu ustalamy wyłącznie, co pokazać;
 * pliki podmienia {@see Updater}, po kliknięciu.
 */
final class Release
{
    /** Hook, którym silnik może odpowiedzieć zamiast modułu. */
    public const HOOK = 'actionQuinceReleaseIndex';

    private const MODUL = 'quince_cmp';

    /**
     * Stan aktualizacji.
     *
     * `configured` mówi, czy w ogóle jest się o co pytać. Fałsz oznacza, że
     * wydania nie są jeszcze publikowane, a ekran nie pokazuje wtedy przycisku
     * aktualizacji.
     *
     * @return array{configured:bool,source:string,checked:?string,current:string,latest:string,newer:bool,url:string,notes:string,zip:string,sha256:string,error:string}
     */
    public static function status(string $wersjaModulu, int $idShop, bool $wymus = false): array
    {
        $pusty = [
            'configured' => false,
            'source' => '',
            'checked' => null,
            'current' => $wersjaModulu,
            'latest' => '',
            'newer' => false,
            'url' => '',
            'notes' => '',
            'zip' => '',
            'sha256' => '',
            'error' => '',
        ];

        $wpis = self::odSilnika($idShop, $wymus) ?? Manifest::wpis(self::MODUL, $idShop, $wymus);

        if (($wpis['error'] ?? '') === 'not-published') {
            return $pusty;
        }

        if (($wpis['error'] ?? '') !== '') {
            return array_merge($pusty, [
                'configured' => true,
                'source' => (string) ($wpis['source'] ?? 'index'),
                'checked' => $wpis['checked'] ?? null,
                'error' => (string) $wpis['error'],
            ]);
        }

        if (empty($wpis['found'])) {
            return $pusty;
        }

        $najnowsza = (string) $wpis['version'];
        $repo = (string) $wpis['repository'];

        return [
            'configured' => true,
            'source' => (string) ($wpis['source'] ?? 'index'),
            'checked' => $wpis['checked'] ?? null,
            'current' => $wersjaModulu,
            'latest' => $najnowsza,
            'newer' => $najnowsza !== '' && version_compare($najnowsza, $wersjaModulu, '>'),
            'url' => $repo !== '' ? 'https://github.com/' . $repo : '',
            'notes' => (string) $wpis['notes'],
            'zip' => (string) $wpis['package'],
            'sha256' => (string) ($wpis['sha256'] ?? ''),
            'error' => '',
        ];
    }

    /**
     * Odpowiedź silnika albo `null`, gdy silnika nie ma lub nie odpowiedział.
     *
     * `Hook::exec` z `$array_return` zwraca wynik każdego modułu z osobna, więc
     * bierzemy pierwszy, który wygląda jak wpis o wydaniu. Cokolwiek innego
     * traktujemy jak brak odpowiedzi i schodzimy do spisu wydań.
     *
     * @return array<string,mixed>|null
     */
    private static function odSilnika(int $idShop, bool $wymus): ?array
    {
        if (!Engine::dostepny() || !class_exists(Hook::class)) {
            return null;
        }

        $odpowiedzi = Hook::exec(
            self::HOOK,
            ['module' => self::MODUL, 'id_shop' => $idShop, 'force' => $wymus],
            null,
            true
        );

        if (!is_array($odpowiedzi)) {
            return null;
        }

        foreach ($odpowiedzi as $odpowiedz) {
            if (is_array($odpowiedz) && (isset($odpowiedz['found']) || isset($odpowiedz['error']))) {
                return $odpowiedz + ['source' => 'engine'];
            }
        }

        return null;
    }
}
