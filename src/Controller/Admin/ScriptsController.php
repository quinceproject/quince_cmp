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
use Quince\Cmp\Storage\Scripts;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usługi wstrzymywane do czasu zgody i mapowanie kategorii na sygnały Consent Mode. */
final class ScriptsController extends CmpController
{
    public function __construct(
        private readonly Scripts $scripts,
        private readonly Cookies $cookies
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        $idShop = Options::biezacySklep();
        $idLang = (int) \Context::getContext()->language->id;

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/scripts.html.twig', [
            'scripts' => $this->scripts->all($idShop),
            'matrix' => $this->scripts->matrix($idShop, $idLang),
            'signals' => array_keys(Scripts::SIGNALS),
            'uncovered' => $this->scripts->sygnalyBezPokrycia($idShop, $idLang),
            'signalsRequired' => Scripts::SIGNALS_REQUIRED,
            'signalGranted' => Scripts::SIGNAL_DEFAULT_GRANTED,
            'cmScope' => Options::text(Options::CM_SCOPE, 'everywhere', $idShop),
            'cmScopes' => Options::CM_SCOPES,
            'cmRegions' => Options::text(Options::CM_REGIONS, Options::CM_REGIONS_EEA, $idShop),
            'cmRegionsEea' => Options::CM_REGIONS_EEA,
            'cmWait' => Options::int(Options::CM_WAIT, 500, $idShop),
            'cmWaitChoices' => Options::CM_WAIT_CHOICES,
            'cmRedact' => Options::bool(Options::CM_REDACT, true, $idShop),
            'cmPassthrough' => Options::bool(Options::CM_PASSTHROUGH, true, $idShop),
            'embeddings' => Scripts::embeddings(),
            'techniques' => Scripts::techniques(),
            'categories' => $this->cookies->categories($idShop, $idLang),
            'shopName' => (string) (new Shop($idShop))->name,
            'shopScoped' => Shop::getContextShopID() === null,
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Scripts & blocking',
            'quinceRoute' => 'quince_cmp_scripts',
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function editAction(int $idScript): Response
    {
        $idShop = Options::biezacySklep();
        $idLang = (int) \Context::getContext()->language->id;
        $skrypt = $idScript > 0 ? $this->scripts->find($idScript, $idShop) : null;

        if ($idScript > 0 && $skrypt === null) {
            return $this->redirectToRoute('quince_cmp_scripts');
        }

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/script_edit.html.twig', [
            'script' => $skrypt,
            'embeddings' => Scripts::embeddings(),
            'techniques' => Scripts::techniques(),
            'categories' => $this->cookies->categories($idShop, $idLang),
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Service',
            'quinceRoute' => 'quince_cmp_scripts',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_script_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_scripts');
        }

        $idShop = Options::biezacySklep();
        $idScript = (int) $request->request->get('id_cmp_script', 0);
        $nazwa = trim((string) $request->request->get('name', ''));

        if ($nazwa === '') {
            $this->addFlash('error', $this->trans('The service name cannot be empty.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_script_edit', ['idScript' => $idScript]);
        }

        $this->scripts->save(
            $idScript,
            $idShop,
            $nazwa,
            trim((string) $request->request->get('embedding', '')),
            trim((string) $request->request->get('technique', '')),
            trim((string) $request->request->get('category', '')),
            trim((string) $request->request->get('pattern', ''))
        );

        $this->addFlash('success', $this->trans('Service saved.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_scripts');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function toggleAction(int $idScript, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_script_toggle', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_scripts');
        }

        $idShop = Options::biezacySklep();
        $this->scripts->toggleActive($idScript, $idShop);

        $skrypt = $this->scripts->find($idScript, $idShop);
        $wlaczony = $skrypt !== null && (bool) $skrypt['active'];

        $this->addFlash('success', $wlaczony
            ? $this->trans('Service switched on. It is held back until the visitor agrees.', [], 'Modules.Quincecmp.Admin')
            : $this->trans('Service switched off. Nothing holds it back. Check that it is gone from the theme.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_scripts');
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteAction(int $idScript, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_script_delete', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_scripts');
        }

        $this->scripts->delete($idScript, Options::biezacySklep());

        $this->addFlash('success', $this->trans(
            'Service removed from the list. Nothing holds it back now. Check that it is gone from the theme as well.', [],
            'Modules.Quincecmp.Admin'
        ));

        return $this->redirectToRoute('quince_cmp_scripts');
    }

    /**
     * Porządkuje listę krajów: wielkie litery, po dwa znaki, bez powtórzeń.
     *
     * Google odrzuca całą deklarację, gdy trafi na kod, którego nie rozumie.
     * Literówka w jednym kraju kasowałaby stan domyślny dla wszystkich.
     */
    private function kodyKrajow(string $surowe): string
    {
        $out = [];

        foreach (preg_split('/[\s,;]+/', strtoupper($surowe)) ?: [] as $kod) {
            if (preg_match('/^[A-Z]{2}$/', $kod) === 1 && !in_array($kod, $out, true)) {
                $out[] = $kod;
            }
        }

        return implode(',', $out);
    }

    /** Treść zastępnika zablokowanej ramki. */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function placeholderAction(): Response
    {
        $idShop = Options::biezacySklep();
        $idLang = (int) \Context::getContext()->language->id;

        $pola = [
            'title' => Options::PH_TITLE,
            'body' => Options::PH_BODY,
            'allow' => Options::PH_ALLOW,
            'settings' => Options::PH_SETTINGS,
        ];

        $wartosci = [];
        $podglad = [];

        foreach ($pola as $nazwa => $klucz) {
            foreach ($this->languages() as $jezyk) {
                $wartosci[$nazwa][$jezyk['id_lang']] = Options::lang($klucz, $jezyk['id_lang'], '', $idShop);
            }

            $podglad[$nazwa] = Options::lang($klucz, $idLang, '', $idShop);
        }

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/placeholder_edit.html.twig', [
            'title' => $wartosci['title'],
            'body' => $wartosci['body'],
            'allow' => $wartosci['allow'],
            'settings' => $wartosci['settings'],
            'preview' => $podglad,
            'languages' => $this->languages(),
            'engineDetected' => Engine::dostepny(),
            'layoutTitle' => 'Blocked-content placeholder',
            'quinceRoute' => 'quince_cmp_scripts',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function placeholderSaveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_placeholder_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_placeholder');
        }

        $idShop = Options::biezacySklep();

        $pola = [
            'ph_title' => Options::PH_TITLE,
            'ph_body' => Options::PH_BODY,
            'ph_allow' => Options::PH_ALLOW,
            'ph_settings' => Options::PH_SETTINGS,
        ];

        foreach ($pola as $prefiks => $klucz) {
            $wartosci = [];

            foreach ($this->languages() as $jezyk) {
                $wartosci[$jezyk['id_lang']] = trim((string) $request->request->get($prefiks . '_' . $jezyk['id_lang'], ''));
            }

            Options::saveLang($klucz, $wartosci, $idShop);
        }

        $this->addFlash('success', $this->trans('Placeholder wording saved.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_placeholder');
    }

    /** Zapis macierzy kategoria × sygnał Consent Mode. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function matrixAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_scripts_matrix', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_scripts');
        }

        $idShop = Options::biezacySklep();
        $idLang = (int) \Context::getContext()->language->id;

        $mapowanie = [];

        foreach ($this->scripts->matrix($idShop, $idLang) as $kategoria) {
            foreach (array_keys(Scripts::SIGNALS) as $sygnal) {
                $mapowanie[$kategoria['code']][$sygnal] =
                    $request->request->getBoolean('cm_' . $kategoria['code'] . '_' . $sygnal);
            }
        }

        $this->scripts->saveMatrix($idShop, $mapowanie);

        $zasieg = (string) $request->request->get('cm_scope', 'everywhere');
        $czekanie = (int) $request->request->get('cm_wait', 500);
        $regiony = $this->kodyKrajow((string) $request->request->get('cm_regions', ''));

        Options::save(Options::CM_SCOPE, in_array($zasieg, Options::CM_SCOPES, true) ? $zasieg : 'everywhere', $idShop);
        Options::save(Options::CM_REGIONS, $regiony !== '' ? $regiony : Options::CM_REGIONS_EEA, $idShop);
        Options::save(Options::CM_WAIT, in_array($czekanie, Options::CM_WAIT_CHOICES, true) ? $czekanie : 500, $idShop);
        Options::save(Options::CM_REDACT, (int) $request->request->getBoolean('cm_redact'), $idShop);
        Options::save(Options::CM_PASSTHROUGH, (int) $request->request->getBoolean('cm_passthrough'), $idShop);

        $this->addFlash('success', $this->trans('Consent Mode saved.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_scripts');
    }
}
