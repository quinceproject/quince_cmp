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

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Cmp\Integration\Engine;
use Quince\Cmp\Storage\Cookies;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Ui\Contrast;
use Quince\Cmp\Ui\SvgUpload;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Treść i wygląd obu warstw banera oraz pływającego przycisku. */
final class BannerController extends CmpController
{
    /** Pole formularza => klucz konfiguracji. Wartości językowe. */
    private const POLA_JEZYKOWE = [
        'b1_title' => Options::B1_TITLE,
        'b1_body' => Options::B1_BODY,
        'b1_accept' => Options::B1_ACCEPT,
        'b1_reject' => Options::B1_REJECT,
        'b1_prefs' => Options::B1_PREFS,
        'b2_title' => Options::B2_TITLE,
        'b2_body' => Options::B2_BODY,
        'b2_save' => Options::B2_SAVE,
        'fab_label' => Options::FAB_LABEL,
    ];

    /** Wartości domyślne, gdy sprzedawca niczego nie wpisał. */
    private const DOMYSLNE = [
        'b1_title' => 'We value your privacy',
        'b1_body' => 'We use cookies to run this shop and, with your consent, for analytics and marketing.',
        'b1_accept' => 'Accept all',
        'b1_reject' => 'Reject all',
        'b1_prefs' => 'Preferences',
        'b2_title' => 'Consent preferences',
        'b2_body' => 'Choose which cookies you allow. Necessary cookies keep the shop working and cannot be switched off.',
        'b2_save' => 'Save my choices',
        'fab_label' => 'Cookie settings',
    ];

    public function __construct(private readonly Cookies $cookies)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $idShop = Options::biezacySklep();
        $idLang = (int) \Context::getContext()->language->id;

        $wartosci = [];
        $podglad = [];

        foreach (self::POLA_JEZYKOWE as $pole => $klucz) {
            foreach ($this->languages() as $jezyk) {
                $wartosci[$pole][$jezyk['id_lang']] = Options::lang($klucz, $jezyk['id_lang'], '', $idShop);
            }

            $podglad[$pole] = Options::lang($klucz, $idLang, self::DOMYSLNE[$pole], $idShop);
        }

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/banner.html.twig', [
            'languages' => $this->languages(),
            'values' => $wartosci,
            'preview' => $podglad,
            'defaults' => self::DOMYSLNE,
            'bannerOn' => Options::bool(Options::BANNER_ON, false, $idShop),
            'placement' => Options::text(Options::B_PLACEMENT, 'bar', $idShop),
            'placements' => Options::PLACEMENTS,
            'colours' => Options::text(Options::B_COLOURS, 'engine', $idShop),
            'colourAccent' => Options::text(Options::B_COLOUR_ACCENT, '#f49000', $idShop),
            'colourSurface' => Options::text(Options::B_COLOUR_SURFACE, '#ffffff', $idShop),
            'colourText' => Options::text(Options::B_COLOUR_TEXT, '#1b1d20', $idShop),
            'fabOn' => Options::bool(Options::FAB_ON, true, $idShop),
            'fabPosition' => Options::text(Options::FAB_POSITION, 'left', $idShop),
            'fabPositions' => Options::FAB_POSITIONS,
            'fabOffsetDesktop' => Options::int(Options::FAB_OFFSET_DESKTOP, Options::FAB_OFFSET_DEFAULT, $idShop),
            'fabOffsetMobile' => Options::int(Options::FAB_OFFSET_MOBILE, Options::FAB_OFFSET_DEFAULT, $idShop),
            'fabOffsetMax' => Options::FAB_OFFSET_MAX,
            'fabMode' => Options::text(Options::FAB_MODE, 'icon_label', $idShop),
            'fabModes' => Options::FAB_MODES,
            'fabIcon' => Options::text(Options::FAB_ICON, 'cookie', $idShop),
            'fabIcons' => Options::FAB_ICONS,
            'fabSvg' => Options::text(Options::FAB_SVG, '', $idShop),
            'onAccent' => Contrast::onColour(Options::text(Options::B_COLOUR_ACCENT, '#f49000', $idShop)),
            'contrast' => $this->kontrasty($idShop),
            'categories' => $this->cookies->categories($idShop, $idLang),
            'layer' => (string) $request->query->get('layer', 'one'),
            'device' => (string) $request->query->get('device', 'desktop'),
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Banner & preferences',
            'quinceRoute' => 'quince_cmp_banner',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_banner_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_banner');
        }

        $idShop = Options::biezacySklep();

        foreach (self::POLA_JEZYKOWE as $pole => $klucz) {
            $wartosci = [];

            foreach ($this->languages() as $jezyk) {
                $wartosci[$jezyk['id_lang']] = trim((string) $request->request->get($pole . '_' . $jezyk['id_lang'], ''));
            }

            Options::saveLang($klucz, $wartosci, $idShop);
        }

        $umiejscowienie = (string) $request->request->get('placement', 'bar');
        $kolory = (string) $request->request->get('colours', 'engine');
        $pozycjaFab = (string) $request->request->get('fab_position', 'left');

        Options::save(Options::BANNER_ON, (int) $request->request->getBoolean('banner_on'), $idShop);
        Options::save(Options::B_PLACEMENT, in_array($umiejscowienie, Options::PLACEMENTS, true) ? $umiejscowienie : 'bar', $idShop);
        Options::save(Options::B_COLOURS, $kolory === 'own' ? 'own' : 'engine', $idShop);
        Options::save(Options::B_COLOUR_ACCENT, $this->kolor($request->request->get('colour_accent'), '#f49000'), $idShop);
        Options::save(Options::B_COLOUR_SURFACE, $this->kolor($request->request->get('colour_surface'), '#ffffff'), $idShop);
        Options::save(Options::B_COLOUR_TEXT, $this->kolor($request->request->get('colour_text'), '#1b1d20'), $idShop);
        Options::save(Options::FAB_ON, (int) $request->request->getBoolean('fab_on'), $idShop);
        Options::save(Options::FAB_POSITION, in_array($pozycjaFab, Options::FAB_POSITIONS, true) ? $pozycjaFab : 'left', $idShop);
        Options::save(Options::FAB_OFFSET_DESKTOP, $this->odstep($request->request->get('fab_offset_desktop')), $idShop);
        Options::save(Options::FAB_OFFSET_MOBILE, $this->odstep($request->request->get('fab_offset_mobile')), $idShop);

        $trybFab = (string) $request->request->get('fab_mode', 'icon_label');
        $ikonaFab = (string) $request->request->get('fab_icon', 'cookie');

        Options::save(Options::FAB_MODE, in_array($trybFab, Options::FAB_MODES, true) ? $trybFab : 'icon_label', $idShop);
        Options::save(Options::FAB_ICON, in_array($ikonaFab, Options::FAB_ICONS, true) ? $ikonaFab : 'cookie', $idShop);

        $this->zapiszWlasnyZnak($request, $idShop);

        $this->addFlash('success', $this->trans('Banner saved.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_banner');
    }

    /**
     * Kontrast napisów na przyciskach banera, liczony z kolorów faktycznie
     * ustawionych. Ekran pilnujący czytelności nie podaje wartości niepoliczonych.
     *
     * @return array<int,array{label:string,ratio:float,pass:bool}>
     */
    private function kontrasty(int $idShop): array
    {
        $akcent = Options::text(Options::B_COLOUR_ACCENT, '#f49000', $idShop);
        $tlo = Options::text(Options::B_COLOUR_SURFACE, '#ffffff', $idShop);
        $tekst = Options::text(Options::B_COLOUR_TEXT, '#1b1d20', $idShop);

        $pary = [
            'Accept all' => [Contrast::onColour($akcent), $akcent, Contrast::AA_TEXT],
            'Reject all' => [$tekst, $tlo, Contrast::AA_TEXT],
            'Preferences' => [$tekst, $tlo, Contrast::AA_TEXT],
            'Focus ring' => [$akcent, $tlo, Contrast::AA_LARGE],
        ];

        $out = [];

        foreach ($pary as $etykieta => [$a, $b, $prog]) {
            $stosunek = Contrast::ratio($a, $b);

            $out[] = [
                'label' => $etykieta,
                'ratio' => $stosunek,
                'pass' => Contrast::passes($stosunek, $prog),
            ];
        }

        return $out;
    }

    /**
     * Przyjmuje albo usuwa własny znak na przycisku.
     *
     * Plik nie ląduje na dysku: po oczyszczeniu zapisujemy samą treść. Dzięki
     * temu nie ma czego podać pod adresem ani czego zapomnieć przy odinstalowaniu.
     */
    private function zapiszWlasnyZnak(Request $request, int $idShop): void
    {
        if ($request->request->getBoolean('fab_svg_remove')) {
            Options::save(Options::FAB_SVG, '', $idShop);

            return;
        }

        $plik = $request->files->get('fab_svg');

        if ($plik === null || !$plik->isValid()) {
            return;
        }

        $wynik = SvgUpload::clean((string) file_get_contents($plik->getPathname()));

        if ($wynik['error'] !== '') {
            $this->addFlash('error', $this->trans($wynik['error'], [], 'Modules.Quincecmp.Admin'));

            return;
        }

        Options::save(Options::FAB_SVG, $wynik['svg'], $idShop);
        $this->addFlash('success', $this->trans('Custom icon accepted.', [], 'Modules.Quincecmp.Admin'));
    }

    /** Kolor w zapisie szesnastkowym albo wartość domyślna. */
    private function kolor(mixed $wartosc, string $domyslny): string
    {
        $wartosc = trim((string) $wartosc);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $wartosc) === 1 ? strtolower($wartosc) : $domyslny;
    }

    /**
     * Odległość od krawędzi w dozwolonym zakresie.
     *
     * Pusta albo nieliczbowa wartość wraca do domyślnej, a nie do zera: zero
     * przykleiłoby przycisk do samej krawędzi, czego nikt nie wpisał świadomie.
     */
    private function odstep(mixed $wartosc): int
    {
        if (!is_numeric($wartosc)) {
            return Options::FAB_OFFSET_DEFAULT;
        }

        return max(0, min(Options::FAB_OFFSET_MAX, (int) $wartosc));
    }
}
