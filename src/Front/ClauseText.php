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

use CMS;
use Context;

/**
 * Treść klauzuli z odnośnikami w składni markdownowej.
 *
 *     Akceptuję [regulamin](cms:3) i [politykę prywatności](https://…)
 *
 * Treść przechowywana jest jako zwykły tekst, nie HTML: skrót treści stanowi
 * dowód, a edytory HTML normalizują znaczniki przy zapisie, co zmieniałoby skrót
 * bez zmiany widocznej treści. Renderowane są wyłącznie odnośniki; pozostałe
 * znaki są eskejpowane.
 *
 * Cel `cms:ID` rozwijany jest do adresu strony w bieżącym języku i pozostaje
 * poprawny po zmianie ścieżki strony.
 */
final class ClauseText
{
    /** `[widoczny tekst](cel)`, gdzie cel to `cms:ID` albo zwykły adres. */
    private const WZOR = '/\[([^\]]+)\]\(([^)]+)\)/u';

    /**
     * Rozdziela treść na tekst z markerami `[…]` oraz listę adresów, w formacie
     * przyjmowanym przez `TermsAndConditions`.
     *
     * @return array{text:string,links:array<int,string>}
     */
    public static function split(string $raw, ?int $idLang = null): array
    {
        $linki = [];

        $tekst = (string) preg_replace_callback(
            self::WZOR,
            static function (array $trafienie) use (&$linki, $idLang): string {
                $linki[] = self::adres($trafienie[2], $idLang);

                return '[' . $trafienie[1] . ']';
            },
            $raw
        );

        return ['text' => $tekst, 'links' => $linki];
    }

    /** Treść jako HTML. Renderowane są wyłącznie odnośniki, reszta jest eskejpowana. */
    public static function toHtml(string $raw, ?int $idLang = null): string
    {
        $czesci = preg_split(self::WZOR, $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';

        for ($i = 0; $i < count($czesci); ++$i) {
            // Wzorzec ma dwie grupy przechwytujące: napis i cel odnośnika.
            if ($i % 3 === 0) {
                $out .= htmlspecialchars($czesci[$i], ENT_QUOTES, 'UTF-8');

                continue;
            }

            if ($i % 3 === 1) {
                $napis = htmlspecialchars($czesci[$i], ENT_QUOTES, 'UTF-8');
                $cel = htmlspecialchars(self::adres($czesci[$i + 1] ?? '', $idLang), ENT_QUOTES, 'UTF-8');
                $out .= '<a href="' . $cel . '" target="_blank" rel="noopener">' . $napis . '</a>';
            }
        }

        return $out;
    }

    /** Sam tekst, bez odnośników, do miejsc, w których HTML nie wejdzie. */
    public static function toPlain(string $raw): string
    {
        return (string) preg_replace(self::WZOR, '$1', $raw);
    }

    /**
     * Rozwija cel odnośnika. `cms:ID` zamieniane jest na adres strony sklepu;
     * schematy wykonywalne (`javascript:`, `data:`, `vbscript:`) są odrzucane.
     */
    private static function adres(string $cel, ?int $idLang = null): string
    {
        $cel = trim($cel);

        if (str_starts_with($cel, 'cms:')) {
            $idCms = (int) substr($cel, 4);
            $kontekst = Context::getContext();
            $idLang ??= (int) $kontekst->language->id;
            $strona = new CMS($idCms, $idLang);

            return $strona->id
                ? (string) $kontekst->link->getCMSLink($strona, $strona->link_rewrite, null, $idLang)
                : '#';
        }

        if (preg_match('/^\s*(javascript|data|vbscript):/i', $cel)) {
            return '#';
        }

        return $cel;
    }
}
