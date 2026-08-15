<?php
// app/Jobs/SynchroniserContactGoogle.php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Famille;
use App\Services\GoogleContactsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job asynchrone : synchronisation directe du contact Google via People API
 * (remplace le webhook Make.com EnvoyerWebhookContact — décision du
 * 17/07/2026, cf. GoogleContactsService). Déclenché quand etat_dossier
 * PASSE à 'Validé', 'Rejeté' ou 'Archivé' — voir
 * FamillesController::update() (élargi le 14/08/2026, ne couvrait
 * initialement que 'Validé').
 *
 * Contrairement à l'ancien EnvoyerWebhookContact, l'action (création vs mise
 * à jour) n'est plus décidée par l'appelant : elle est déduite ici de la
 * présence de familles.google_resource_name, ce qui couvre naturellement le
 * cas d'un dossier modifié puis re-basculé dans l'un de ces trois états
 * (repasse par 'En cours' à l'édition, donc une seule et même transition
 * déclenche ce job à chaque fois — pas besoin d'un second point de
 * déclenchement dans le contrôleur).
 *
 * Pattern conservé de l'ancien job / de ResoudreAdresseFamille : 3
 * tentatives, backoff 60s, log + audit_logs en cas de succès, $this->fail()
 * en cas d'échec pour déclencher le retry.
 *
 * Auto-guérison (14/08/2026) : si le contact référencé par
 * google_resource_name a disparu côté Google (404), on détache et on
 * recrée immédiatement au lieu de retenter en vain 3 fois contre une
 * erreur permanente — voir le bloc catch ciblé ci-dessous.
 *
 * Si l'intégration Google Contacts n'est pas encore autorisée (aucun
 * refresh token enregistré — cf. GoogleContactsService::isConfigured()),
 * le job se contente de logger et de sortir : ce n'est pas une panne
 * transitoire à retenter, mais une configuration manquante côté admin
 * (flux OAuth à effectuer via /admin/google-contacts/authorize).
 */
class SynchroniserContactGoogle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly int $idFamille
    ) {
    }

    public function handle(GoogleContactsService $googleContacts): void
    {
        $famille = Famille::find($this->idFamille);

        if (!$famille) {
            Log::warning('[SynchroniserContactGoogle] Famille introuvable', ['id' => $this->idFamille]);
            return;
        }

        if (!$googleContacts->isConfigured()) {
            Log::warning('[SynchroniserContactGoogle] Google Contacts non configuré/autorisé — synchronisation ignorée', [
                'id_famille' => $famille->id,
            ]);
            return;
        }

        $action = $famille->google_resource_name ? 'update' : 'create';

        try {
            if ($action === 'update') {
                try {
                    $googleContacts->updateContact($famille);
                } catch (\Google\Service\Exception $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }

                    // Auto-guérison — même logique que ReverseSyncService::
                    // scanner() : le resourceName stocké ne pointe plus vers
                    // rien côté Google (contact supprimé manuellement, ou
                    // provenant d'une ancienne autorisation OAuth/ancien
                    // pipeline Make.com antérieur au 17/07/2026). Plutôt que
                    // d'échouer et de retenter 3 fois contre la même erreur
                    // permanente, on détache et on recrée immédiatement.
                    Log::warning('[SynchroniserContactGoogle] google_resource_name périmé (404) — recréation', [
                        'id_famille' => $famille->id,
                        'ancien_resource_name' => $famille->google_resource_name,
                    ]);

                    $resourceName = $googleContacts->createContact($famille);
                    $famille->forceFill(['google_resource_name' => $resourceName])->saveQuietly();
                    $action = 'create (après 404 sur update)';
                }
            } else {
                $resourceName = $googleContacts->createContact($famille);
                $famille->forceFill(['google_resource_name' => $resourceName])->saveQuietly();
            }
        } catch (\Throwable $e) {
            Log::error('[SynchroniserContactGoogle] Échec synchronisation People API', [
                'id_famille' => $famille->id,
                'action' => $action,
                'erreur' => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        Log::info('[SynchroniserContactGoogle] Synchronisé avec succès', [
            'id_famille' => $famille->id,
            'action' => $action,
        ]);

        audit('webhook', 'familles_contact', $famille->id, null, [
            'succes' => true,
            'action' => $action,
            'google_resource_name' => $famille->google_resource_name,
        ]);
    }
}
