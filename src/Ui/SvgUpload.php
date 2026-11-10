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

/**
 * Przyjmowanie własnego znaku na pływający przycisk.
 *
 * SVG jest dokumentem, nie obrazkiem: potrafi nieść skrypt, odwołanie do
 * zasobu zewnętrznego i zdarzenie. Plik wgrany przez sprzedawcę trafia na
 * każdą stronę sklepu, więc nie wystarczy sprawdzić rozszerzenia — czyścimy
 * treść i przepuszczamy wyłącznie to, co potrzebne do narysowania kształtu.
 *
 * Wynik zapisujemy jako treść, nie jako plik: znak wchodzi w stronę wprost,
 * bez dodatkowego żądania, i dziedziczy kolor przycisku.
 */
final class SvgUpload
{
    /** Górna granica rozmiaru. Ikona mieści się w kilku kilobajtach. */
    private const MAX_BAJTOW = 32768;

    /** Znaczniki, które zostają. Reszta wylatuje razem z zawartością. */
    private const DOZWOLONE = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'rect', 'defs', 'title', 'desc', 'use', 'symbol',
    ];

    /** Atrybuty, które zostają. */
    private const ATRYBUTY = [
        'viewbox', 'xmlns', 'd', 'fill', 'stroke', 'stroke-width', 'stroke-linecap',
        'stroke-linejoin', 'stroke-dasharray', 'fill-rule', 'clip-rule', 'opacity',
        'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'points',
        'width', 'height', 'transform', 'id',
    ];

    /**
     * Czyści wgrany plik i zwraca bezpieczną treść albo powód odmowy.
     *
     * @return array{svg:string,error:string}
     */
    public static function clean(string $tresc): array
    {
        $tresc = trim($tresc);

        if ($tresc === '') {
            return ['svg' => '', 'error' => ''];
        }

        if (strlen($tresc) > self::MAX_BAJTOW) {
            return ['svg' => '', 'error' => 'The file is larger than 32 kB. An icon needs a fraction of that.'];
        }

        if (stripos($tresc, '<svg') === false) {
            return ['svg' => '', 'error' => 'This is not an SVG file.'];
        }

        // Deklaracja typu dokumentu w ikonie nie ma czego opisywać, a jest drogą
        // do plików serwera przez encję zewnętrzną. Odrzucamy plik, zamiast
        // próbować ją unieszkodliwić.
        if (preg_match('/<!DOCTYPE/i', $tresc) === 1) {
            return ['svg' => '', 'error' => 'The file declares a document type. An icon has no use for one.'];
        }

        // Bez `LIBXML_NOENT`: ta flaga nie wyłącza encji, tylko każe je rozwinąć —
        // czyli wstawić do dokumentu treść, na którą wskazują.
        $poprzedni = libxml_use_internal_errors(true);
        $dokument = new \DOMDocument();
        $wczytany = $dokument->loadXML($tresc, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzedni);

        if (!$wczytany || $dokument->documentElement === null) {
            return ['svg' => '', 'error' => 'The file could not be read as XML.'];
        }

        if (strtolower($dokument->documentElement->nodeName) !== 'svg') {
            return ['svg' => '', 'error' => 'The root element is not <svg>.'];
        }

        self::wyczysc($dokument->documentElement);

        $korzen = $dokument->documentElement;

        // Rozmiar bierze się z przycisku, nie z pliku — inaczej wgrany plik
        // 512 px rozsadziłby przycisk.
        $korzen->removeAttribute('width');
        $korzen->removeAttribute('height');
        $korzen->setAttribute('aria-hidden', 'true');

        if (!$korzen->hasAttribute('viewBox')) {
            return ['svg' => '', 'error' => 'The file has no viewBox, so it cannot be scaled to the button.'];
        }

        $wynik = $dokument->saveXML($korzen);

        if (!is_string($wynik) || $wynik === '') {
            return ['svg' => '', 'error' => 'Nothing was left after cleaning the file.'];
        }

        return ['svg' => $wynik, 'error' => ''];
    }

    /**
     * Usuwa wszystko spoza listy: obce znaczniki, obce atrybuty, atrybuty
     * zdarzeń oraz adresy inne niż odwołanie wewnętrzne.
     */
    private static function wyczysc(\DOMElement $wezel): void
    {
        foreach (iterator_to_array($wezel->childNodes) as $dziecko) {
            if ($dziecko instanceof \DOMElement) {
                if (!in_array(strtolower($dziecko->nodeName), self::DOZWOLONE, true)) {
                    $wezel->removeChild($dziecko);

                    continue;
                }

                self::wyczysc($dziecko);

                continue;
            }

            // Komentarze i instrukcje przetwarzania nie rysują niczego.
            if ($dziecko instanceof \DOMComment || $dziecko instanceof \DOMProcessingInstruction) {
                $wezel->removeChild($dziecko);
            }
        }

        foreach (iterator_to_array($wezel->attributes ?? []) as $atrybut) {
            $nazwa = strtolower($atrybut->nodeName);

            if (!in_array($nazwa, self::ATRYBUTY, true)) {
                $wezel->removeAttribute($atrybut->nodeName);

                continue;
            }

            // `use` potrafi wskazać dokument zewnętrzny; zostawiamy wyłącznie
            // odwołanie w obrębie tego samego pliku.
            if ($nazwa === 'href' || $nazwa === 'xlink:href') {
                if (!str_starts_with(trim($atrybut->nodeValue ?? ''), '#')) {
                    $wezel->removeAttribute($atrybut->nodeName);
                }
            }

            if (stripos((string) $atrybut->nodeValue, 'javascript:') !== false
                || stripos((string) $atrybut->nodeValue, 'url(') !== false) {
                $wezel->removeAttribute($atrybut->nodeName);
            }
        }
    }
}
