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
use Quince\Cmp\Storage\ConsentTypes;
use Quince\Cmp\Storage\Options;
use Quince\Cmp\Storage\Schema;
use Quince\Cmp\Storage\SyncQueue;
use Quince\Cmp\Sync\Endpoint;
use Quince\Cmp\Sync\SyncConfig;
use Quince\Cmp\Sync\Transport;
use Shop;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Dwukierunkowa wymiana zdarzeń zgody z systemem zewnętrznym. */
final class SyncController extends CmpController
{
    public function __construct(
        private readonly SyncQueue $queue,
        private readonly Transport $transport,
        private readonly ConsentTypes $types
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $idShop = Options::biezacySklep();
        $filtr = (string) $request->query->get('status', '');
        $stan = in_array($filtr, [Schema::SYNC_PENDING, Schema::SYNC_SENT, Schema::SYNC_FAILED], true) ? $filtr : null;
        $ostatni = $this->queue->lastRun($idShop);

        return $this->renderPanel('@Modules/quince_cmp/views/templates/admin/sync.html.twig', [
            'syncOn' => SyncConfig::bool(Options::SYNC_ON, false, $idShop),
            'endpoint' => SyncConfig::text(Options::SYNC_ENDPOINT, '', $idShop),
            'allowLocal' => SyncConfig::bool(Options::SYNC_ALLOW_LOCAL, false, $idShop),
            'keyOut' => SyncConfig::text(Options::SYNC_KEY_OUT, '', $idShop),
            'keyIn' => SyncConfig::text(Options::SYNC_KEY_IN, '', $idShop),
            'every' => SyncConfig::int(Options::SYNC_EVERY, 15, $idShop),
            'intervals' => Options::SYNC_INTERVALS,
            'keepDays' => SyncConfig::int(Options::SYNC_KEEP_DAYS, 30, $idShop),
            'keepChoices' => Options::SYNC_KEEP_CHOICES,
            'alertOn' => SyncConfig::bool(Options::SYNC_ALERT_ON, true, $idShop),
            'alertTo' => (string) \Configuration::get('PS_SHOP_EMAIL', null, null, $idShop),
            'scope' => SyncConfig::scope($idShop),
            'groupName' => SyncConfig::nazwaGrupy($idShop),
            'groupShops' => SyncConfig::rozmiarGrupy($idShop),
            'backlog' => $this->queue->backlogCount(SyncConfig::sklepy($idShop), $this->wybraneKody($idShop)),
            'codes' => $this->kody($idShop),
            'outbox' => $this->queue->outbox($idShop, $stan),
            'counts' => $this->queue->counts($idShop),
            'lastRun' => $ostatni,
            'incoming' => $this->queue->incoming($idShop, $ostatni['date']),
            'inboxUrl' => $this->adres($idShop, 'inbox'),
            'cronUrl' => $this->adres($idShop, 'cron') . '?token=' . $this->tokenCrona($idShop),
            'status' => $stan ?? '',
            'contract' => [
                'payload' => Transport::przykladowyLadunek((string) (new Shop($idShop))->name),
                'signatureHeader' => Transport::HEADER_SIGNATURE,
                'timestampHeader' => Transport::HEADER_TIMESTAMP,
                'tolerance' => 300,
            ],
            'shopName' => (string) (new Shop($idShop))->name,
            'shopScoped' => Shop::getContextShopID() === null,
            'layoutTitle' => 'Sync',
            'quinceRoute' => 'quince_cmp_sync',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_sync_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_sync');
        }

        $idShop = Options::biezacySklep();

        $adres = trim((string) $request->request->get('endpoint', ''));
        $co = (int) $request->request->get('every', 15);
        $wybrane = (array) $request->request->all('codes');

        $wolnoLokalnie = $request->request->getBoolean('allow_local');

        if ($adres !== '') {
            $ocena = Endpoint::sprawdz($adres, $wolnoLokalnie);

            if (!$ocena['ok']) {
                $this->addFlash('error', $ocena['error']);

                return $this->redirectToRoute('quince_cmp_sync');
            }
        }

        // Zasięg najpierw: reszta wartości ma trafić tam, gdzie wskazuje nowy wybór.
        SyncConfig::setScope((string) $request->request->get('scope', SyncConfig::SCOPE_SHOP), $idShop);

        $trzymaj = (int) $request->request->get('keep_days', 30);

        SyncConfig::save(Options::SYNC_ON, (int) $request->request->getBoolean('sync_on'), $idShop);
        SyncConfig::save(Options::SYNC_ENDPOINT, $adres, $idShop);
        SyncConfig::save(Options::SYNC_EVERY, in_array($co, Options::SYNC_INTERVALS, true) ? $co : 15, $idShop);
        SyncConfig::save(Options::SYNC_ALLOW_LOCAL, (int) $wolnoLokalnie, $idShop);
        SyncConfig::save(Options::SYNC_CODES, implode(',', array_map('strval', $wybrane)), $idShop);
        SyncConfig::save(Options::SYNC_KEEP_DAYS, in_array($trzymaj, Options::SYNC_KEEP_CHOICES, true) ? $trzymaj : 30, $idShop);
        SyncConfig::save(Options::SYNC_ALERT_ON, (int) $request->request->getBoolean('alert_on'), $idShop);

        $this->addFlash('success', $this->trans('Sync settings saved.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_sync');
    }

    /**
     * Nadaje nowy klucz podpisu.
     *
     * Stary przestaje działać natychmiast.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function rotateAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_sync_rotate', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_sync');
        }

        $idShop = Options::biezacySklep();
        $ktory = (string) $request->request->get('which', 'out');
        $klucz = $ktory === 'in' ? Options::SYNC_KEY_IN : Options::SYNC_KEY_OUT;

        SyncConfig::save($klucz, $this->nowyKlucz($ktory), $idShop);

        $this->addFlash('success', $this->trans('New key generated. Update it in the external system.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_sync');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function retryAction(Request $request, int $idSync): RedirectResponse
    {
        $this->queue->retry(Options::biezacySklep(), $idSync);

        $this->addFlash('success', $this->trans('Queued for another attempt.', [], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_sync', ['status' => $request->query->get('status', '')]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function retryAllAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_sync_retry_all', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_sync');
        }

        $ile = $this->queue->retryAllFailed(Options::biezacySklep());

        $this->addFlash('success', $this->trans('%count% events queued for another attempt.', ['%count%' => $ile], 'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_sync');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function runAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_sync_run', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_sync');
        }

        $wynik = $this->transport->run(Options::biezacySklep());

        if ($wynik['skipped']) {
            $this->addFlash('warning', $this->trans('Sync is off, or the endpoint and signing key are not set.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_sync');
        }

        $this->addFlash('success', $this->trans(
            'Sent %sent%, failed %failed%.',
            ['%sent%' => $wynik['sent'], '%failed%' => $wynik['failed']],
            'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_sync');
    }

    /**
     * Dokłada do kolejki zgody sprzed włączenia wysyłki.
     *
     * Jednorazowe. Kolejka pilnuje, żeby to samo zdarzenie nie trafiło do niej
     * dwa razy, więc powtórne kliknięcie nie duplikuje niczego.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function backlogAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_cmp_sync_backlog', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', [], 'Modules.Quincecmp.Admin'));

            return $this->redirectToRoute('quince_cmp_sync');
        }

        $idShop = Options::biezacySklep();
        $sklepy = SyncConfig::sklepy($idShop);
        $nazwy = [];

        foreach ($sklepy as $id) {
            $nazwy[$id] = (string) (new Shop($id))->name;
        }

        $ile = $this->queue->enqueueBacklog(
            $sklepy,
            $this->wybraneKody($idShop),
            static fn (array $wiersz): string => Transport::payload($wiersz, $nazwy[(int) $wiersz['id_shop']] ?? '')
        );

        $this->addFlash('success', $this->trans(
            '%count% past events queued. They go out with the next run.',
            ['%count%' => $ile],
            'Modules.Quincecmp.Admin'));

        return $this->redirectToRoute('quince_cmp_sync');
    }

    /**
     * Kody zaznaczone do wysyłki. Pusta lista w konfiguracji oznacza wszystkie,
     * więc zwracamy wtedy pustą tablicę i zapytanie nie nakłada warunku.
     *
     * @return array<int,string>
     */
    private function wybraneKody(int $idShop): array
    {
        $lista = SyncConfig::text(Options::SYNC_CODES, '', $idShop);

        return $lista === '' ? [] : array_values(array_filter(explode(',', $lista)));
    }

    /**
     * Kody zgód używane w tym sklepie wraz z informacją, czy jadą na zewnątrz.
     *
     * Pusta lista w konfiguracji znaczy, że jadą wszystkie.
     *
     * @return array<int,array{code:string,name:string,on:bool}>
     */
    private function kody(int $idShop): array
    {
        $zapisane = SyncConfig::text(Options::SYNC_CODES, '', $idShop);
        $wybrane = $zapisane === '' ? null : array_filter(explode(',', $zapisane));
        $idLang = (int) \Context::getContext()->language->id;
        $out = [];

        foreach ($this->types->all($idShop, $idLang) as $typ) {
            $out[] = [
                'code' => $typ['code'],
                'name' => $typ['name'],
                'on' => $wybrane === null || in_array($typ['code'], $wybrane, true),
            ];
        }

        return $out;
    }

    /** Adres kontrolera modułu na sklepie o wskazanym identyfikatorze. */
    private function adres(int $idShop, string $kontroler): string
    {
        $sklep = new Shop($idShop);
        $domena = $sklep->domain_ssl ?: $sklep->domain;

        return 'https://' . $domena . $sklep->getBaseURI() . 'module/quince_cmp/' . $kontroler;
    }

    /**
     * Token przebiegu crona.
     *
     * Liczony tą samą metodą, którą sprawdza kontroler frontu. Inaczej adres
     * pokazany w panelu przestałby działać po zmianie jednej z dwóch stron.
     */
    private function tokenCrona(int $idShop): string
    {
        require_once _PS_MODULE_DIR_ . 'quince_cmp/controllers/front/cron.php';

        return \Quince_CmpCronModuleFrontController::token($idShop);
    }

    private function nowyKlucz(string $ktory): string
    {
        return ($ktory === 'in' ? 'in_' : 'out_') . bin2hex(random_bytes(24));
    }
}
