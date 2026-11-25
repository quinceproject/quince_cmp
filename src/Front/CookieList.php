<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Tabela ciasteczek w treści strony CMS, w miejscu znacznika `[quince_cookies]`.
 *
 * Dane te same co w drugiej warstwie banera, więc polityka prywatności nie
 * rozjeżdża się z tym, o co baner pyta.
 */
final class CookieList
{
    public const TAG = '[quince_cookies]';

    /**
     * Edytor stron opakowuje samotny znacznik w akapit. Zamieniamy cały akapit,
     * bo tabela w `<p>` to niepoprawny HTML i przeglądarka rozbija go po swojemu.
     */
    private const WZORZEC = '~<p[^>]*>(?:\s|&nbsp;)*\[quince_cookies\](?:\s|&nbsp;)*</p>|\[quince_cookies\]~i';

    public function replace(string $tresc, int $idShop, int $idLang): string
    {
        if (stripos($tresc, self::TAG) === false) {
            return $tresc;
        }

        $html = $this->render($idShop, $idLang);

        return (string) preg_replace_callback(self::WZORZEC, static fn (): string => $html, $tresc);
    }

    public function render(int $idShop, int $idLang): string
    {
        $t = static fn (string $tekst): string => self::e(BannerData::tlumacz($tekst, $idLang));
        $out = '';

        foreach ((new BannerData())->cookieCategories($idShop, $idLang) as $kategoria) {
            if ($kategoria['cookies'] === []) {
                continue;
            }

            $out .= '<h3>' . self::e($kategoria['name']) . '</h3>'
                . '<table class="table">'
                . '<thead><tr>'
                . '<th>' . $t('Name') . '</th>'
                . '<th>' . $t('Provider') . '</th>'
                . '<th>' . $t('Lifetime') . '</th>'
                . '<th>' . $t('Purpose') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($kategoria['cookies'] as $c) {
                $out .= '<tr>'
                    . '<td>' . self::e($c['name']) . '</td>'
                    . '<td>' . self::e($c['provider']) . '</td>'
                    . '<td>' . self::e($c['lifetime']) . '</td>'
                    . '<td>' . self::e($c['description']) . '</td>'
                    . '</tr>';
            }

            $out .= '</tbody></table>';
        }

        return $out === '' ? '' : '<div class="quince-cookie-list">' . $out . '</div>';
    }

    private static function e(string $tekst): string
    {
        return htmlspecialchars($tekst, ENT_QUOTES, 'UTF-8');
    }
}
