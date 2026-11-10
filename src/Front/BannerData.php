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
use Db;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Schema;
use Quince\Cmp\Ui\Contrast;
use Tools;

/**
 * Dane potrzebne do złożenia banera po stronie przeglądarki.
 *
 * Baner rysuje się w przeglądarce, a nie w szablonie sklepu, z dwóch powodów.
 * Pierwszy: szablonów jest tyle, ile motywów, i każdy nadpisałby wygląd po
 * swojemu. Drugi: strona sklepu bywa serwowana z pamięci podręcznej, a baner
 * musi znać stan zgody tego konkretnego odwiedzającego.
 */
final class BannerData
{
    /**
     * @return array<string,mixed>
     */
    public function config(int $idShop, int $idLang): array
    {
        return [
            'show' => Visitor::shouldAsk($idShop),
            'placement' => Options::text(Options::B_PLACEMENT, 'bar', $idShop),
            'colours' => $this->kolory($idShop),
            'texts' => $this->teksty($idShop, $idLang),
            'fab' => $this->plywajacy($idShop, $idLang),
            'categories' => $this->kategorie($idShop, $idLang),
            'policyUrl' => $this->politykaUrl($idShop, $idLang),

            // Na stronie polityki prywatności baner nie zasłania treści.
            // Kto wszedł tam wprost, musi móc przeczytać, na co się zgadza,
            // zanim go o to spytamy.
            'onPolicyPage' => $this->naStroniePolityki($idShop),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function kolory(int $idShop): array
    {
        $akcent = Options::text(Options::B_COLOUR_ACCENT, '#f49000', $idShop);
        $tlo = Options::text(Options::B_COLOUR_SURFACE, '#ffffff', $idShop);
        $tekst = Options::text(Options::B_COLOUR_TEXT, '#1b1d20', $idShop);

        return [
            'accent' => $akcent,
            'surface' => $tlo,
            'text' => $tekst,

            // Napis na przycisku głównym liczony, nie wpisany: sprzedawca
            // wybiera dowolny akcent, a przycisk ma zostać czytelny.
            'onAccent' => Contrast::onColour($akcent),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function teksty(int $idShop, int $idLang): array
    {
        $mapa = [
            'title' => [Options::B1_TITLE, 'We value your privacy'],
            'body' => [Options::B1_BODY, 'We use cookies to run this shop and, with your consent, for analytics and marketing.'],
            'accept' => [Options::B1_ACCEPT, 'Accept all'],
            'reject' => [Options::B1_REJECT, 'Reject all'],
            'prefs' => [Options::B1_PREFS, 'Preferences'],
            'prefsTitle' => [Options::B2_TITLE, 'Consent preferences'],
            'prefsBody' => [Options::B2_BODY, 'Choose which cookies you allow. Necessary cookies keep the shop working and cannot be switched off.'],
            'save' => [Options::B2_SAVE, 'Save my choices'],
        ];

        $out = [];

        foreach ($mapa as $klucz => [$opcja, $domyslnie]) {
            $out[$klucz] = Options::lang($opcja, $idLang, $domyslnie, $idShop);
        }

        $out['policy'] = 'Privacy policy';
        $out['always'] = 'Always active';
        $out['close'] = 'Close';

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function plywajacy(int $idShop, int $idLang): array
    {
        return [
            'on' => Options::bool(Options::FAB_ON, true, $idShop),
            'position' => Options::text(Options::FAB_POSITION, 'left', $idShop),
            'offsetDesktop' => Options::int(Options::FAB_OFFSET_DESKTOP, Options::FAB_OFFSET_DEFAULT, $idShop),
            'offsetMobile' => Options::int(Options::FAB_OFFSET_MOBILE, Options::FAB_OFFSET_DEFAULT, $idShop),
            'mode' => Options::text(Options::FAB_MODE, 'icon_label', $idShop),
            'icon' => Options::text(Options::FAB_ICON, 'cookie', $idShop),
            'svg' => Options::text(Options::FAB_SVG, '', $idShop),
            'label' => Options::lang(Options::FAB_LABEL, $idLang, 'Cookie settings', $idShop),
        ];
    }

    /**
     * Kategorie z ciasteczkami, które do nich należą.
     *
     * Tabela ciasteczek jest częścią zgody, nie ozdobą okna: odwiedzający ma
     * prawo wiedzieć, co konkretnie zostanie zapisane i na jak długo.
     *
     * @return array<int,array<string,mixed>>
     */
    private function kategorie(int $idShop, int $idLang): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT t.`code`, t.`required`, tl.`name`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_TYPE . '` t
               LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_TYPE_LANG . '` tl
                      ON tl.`id_cmp_type` = t.`id_cmp_type` AND tl.`id_lang` = ' . $idLang . '
              WHERE t.`id_shop` = ' . $idShop . '
                AND t.`kind` = "' . Schema::KIND_COOKIE . '"
                AND t.`active` = 1
              ORDER BY t.`required` DESC, t.`position` ASC'
        ) ?: [];

        $ciasteczka = $this->ciasteczka($idShop, $idLang);
        $out = [];

        foreach ($wiersze as $wiersz) {
            $kod = (string) $wiersz['code'];

            $out[] = [
                'code' => $kod,
                'name' => (string) ($wiersz['name'] ?? '') ?: $kod,
                'required' => (bool) $wiersz['required'],
                'cookies' => $ciasteczka[$kod] ?? [],
            ];
        }

        return $out;
    }

    /**
     * @return array<string,array<int,array<string,string>>>
     */
    private function ciasteczka(int $idShop, int $idLang): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT c.`name`, c.`provider`, c.`category`, c.`lifetime_value`, c.`lifetime_unit`, cl.`description`
               FROM `' . _DB_PREFIX_ . Schema::TABLE_COOKIE . '` c
               LEFT JOIN `' . _DB_PREFIX_ . Schema::TABLE_COOKIE_LANG . '` cl
                      ON cl.`id_cmp_cookie` = c.`id_cmp_cookie` AND cl.`id_lang` = ' . $idLang . '
              WHERE c.`id_shop` = ' . $idShop . ' AND c.`active` = 1
              ORDER BY c.`position` ASC'
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['category']][] = [
                'name' => (string) $wiersz['name'],
                'provider' => (string) $wiersz['provider'],
                'lifetime' => $this->okres((int) $wiersz['lifetime_value'], (string) $wiersz['lifetime_unit']),
                'description' => (string) ($wiersz['description'] ?? ''),
            ];
        }

        return $out;
    }

    /** Czas życia w formie do odczytania, nie w sekundach. */
    private function okres(int $wartosc, string $jednostka): string
    {
        if ($wartosc <= 0) {
            return 'session';
        }

        return $wartosc . ' ' . rtrim($jednostka, 's') . ($wartosc === 1 ? '' : 's');
    }

    private function politykaUrl(int $idShop, int $idLang): string
    {
        $idCms = Options::int(Options::POLICY_CMS, 0, $idShop);

        return $idCms > 0 ? (string) \Context::getContext()->link->getCMSLink($idCms, null, null, $idLang) : '';
    }

    /**
     * Czy właśnie oglądana strona jest wskazaną polityką prywatności.
     *
     * Sprawdzane po stronie serwera, bo decyduje o tym, czy baner w ogóle
     * zasłoni stronę — a to musi być pewne, zanim cokolwiek się wyrenderuje.
     */
    private function naStroniePolityki(int $idShop): bool
    {
        $idCms = Options::int(Options::POLICY_CMS, 0, $idShop);

        if ($idCms <= 0) {
            return false;
        }

        $kontroler = \Context::getContext()->controller;

        if (!isset($kontroler->php_self) || $kontroler->php_self !== 'cms') {
            return false;
        }

        $biezaca = isset($kontroler->cms) && \Validate::isLoadedObject($kontroler->cms)
            ? (int) $kontroler->cms->id
            : (int) Tools::getValue('id_cms');

        return $biezaca === $idCms;
    }
}
