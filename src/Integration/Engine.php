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

use Module;

/**
 * Wykrywanie modułu `quince_engine`.
 *
 * Jedyne miejsce w module odwołujące się do silnika. Klasa umieszczona jest
 * w `src/`, a nie w klasie modułu, ponieważ ta ostatnia nie jest ładowana
 * automatycznie w kontekście Symfony.
 */
final class Engine
{
    public const MODULE = 'quince_engine';

    /** Czy silnik jest zainstalowany i włączony. */
    public static function dostepny(): bool
    {
        return Module::isInstalled(self::MODULE) && Module::isEnabled(self::MODULE);
    }
}
