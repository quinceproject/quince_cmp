<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Cmp\Controller\Admin;

use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Quince\Cmp\Ui\PanelNavigation;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wspólna podstawa kontrolerów panelu.
 *
 * Nie dziedziczy po `Quince\Engine\Controller\Admin\QuinceController`, ponieważ
 * moduł musi działać bez zainstalowanego silnika.
 */
abstract class CmpController extends PrestaShopAdminController
{
    /**
     * Renderuje ekran panelu wraz z nawigacją.
     *
     * `layoutTitle` tłumaczony jest tutaj, ponieważ PrestaShop wstawia go do
     * nagłówka strony bez tłumaczenia.
     *
     * @param array<string,mixed> $parameters
     */
    protected function renderPanel(string $template, array $parameters): Response
    {
        if (isset($parameters['layoutTitle'])) {
            $parameters['layoutTitle'] = $this->trans(
                (string) $parameters['layoutTitle'], [],
                'Modules.Quincecmp.Admin'
            );
        }

        // `engineDetected` trafia tu, a nie do każdego kontrolera z osobna:
        // znacznik na dole paska nawigacji jest częścią powłoki i nie może
        // zależeć od tego, czy autor ekranu pamiętał o zmiennej.
        return $this->render($template, $parameters + [
            'nav' => (new PanelNavigation())->items(),
            'qcVersion' => $this->moduleVersion(),
            'engineDetected' => \Quince\Cmp\Integration\Engine::dostepny(),
        ]);
    }

    /**
     * Języki sklepu do pól wielojęzycznych.
     *
     * @return array<int,array{id_lang:int,name:string,iso_code:string,default:bool}>
     */
    protected function languages(): array
    {
        $domyslny = (int) \Configuration::get('PS_LANG_DEFAULT');
        $out = [];

        foreach (\Language::getLanguages(true) as $jezyk) {
            $out[] = [
                'id_lang' => (int) $jezyk['id_lang'],
                'name' => (string) $jezyk['name'],
                'iso_code' => (string) $jezyk['iso_code'],
                // Pola wielojęzyczne otwierają się na języku sklepu, nie na
                // pierwszym z listy.
                'default' => (int) $jezyk['id_lang'] === $domyslny,
            ];
        }

        return $out;
    }

    /**
     * Znacznik cache przy plikach CSS i JS.
     *
     * Sama wersja modułu nie wystarcza: sklep z wyłączonym trybem debugowania
     * trzyma arkusz w przeglądarce do następnej zmiany numeru wersji, więc każda
     * poprawka stylu jest niewidoczna aż do wydania paczki. Doklejamy czas zmiany
     * najświeższego z plików: w gotowym sklepie to wartość stała, a przy pracy
     * nad modułem zmienia się razem z plikiem.
     */
    private function moduleVersion(): string
    {
        $modul = \Module::getInstanceByName('quince_cmp');
        $wersja = $modul instanceof \Module ? (string) $modul->version : '0.1.0';

        return $wersja . '.' . $this->czasZasobow();
    }

    /** Czas zmiany najświeższego z arkuszy i skryptów modułu. */
    private function czasZasobow(): int
    {
        $katalogi = [
            _PS_MODULE_DIR_ . 'quince_cmp/views/css',
            _PS_MODULE_DIR_ . 'quince_cmp/views/css/tokens',
            _PS_MODULE_DIR_ . 'quince_cmp/views/js',
        ];

        $najnowszy = 0;

        foreach ($katalogi as $katalog) {
            foreach (glob($katalog . '/*.{css,js}', GLOB_BRACE) ?: [] as $plik) {
                $najnowszy = max($najnowszy, (int) filemtime($plik));
            }
        }

        return $najnowszy;
    }
}
