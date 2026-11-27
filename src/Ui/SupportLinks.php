<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Ui;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Adresy bloków wsparcia na ekranie „Support”: Ko-fi, strona projektu
 * i zgłoszenia w repozytorium modułu.
 *
 * Własna kopia adresów z `Quince\Engine\Ui\SupportLinks`: moduł ma działać
 * bez silnika. Przy zmianie adresu trzeba poprawić oba miejsca.
 */
final class SupportLinks
{
    /** Strona wsparcia projektu na Ko-fi. */
    public const KOFI = 'https://ko-fi.com/quinceproject';

    /** Strona całego projektu. */
    public const PROJECT = 'https://quinceproject.github.io/';

    /** Zgłoszenia w repozytorium tego modułu. */
    public const ISSUES = 'https://github.com/quinceproject/quince_cmp/issues';
}
