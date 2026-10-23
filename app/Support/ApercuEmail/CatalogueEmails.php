<?php
// app/Support/ApercuEmail/CatalogueEmails.php

declare(strict_types=1);

namespace App\Support\ApercuEmail;

use Amana\Shared\Models\BenevoleProfil;
use Amana\Shared\Models\Personne;
use Amana\Shared\Notifications\EmailChangeConfirmationNotification;
use Amana\Shared\Notifications\EmailChangedNotification;
use Amana\Shared\Notifications\EmailNowLoginNotification;
use Amana\Shared\Notifications\PasswordChangedNotification;
use Amana\Shared\Notifications\ResetPasswordNotification;
use App\Models\BenevoleDemandeAttente;
use App\Models\Campagne;
use App\Models\Famille;
use App\Models\FamilleVerification;
use App\Models\IntakeDemandeAttente;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Notifications\BenevoleCandidatureValideeDejaInscritNotification;
use App\Notifications\BenevoleCandidatureValideeNotification;
use App\Notifications\BenevoleIntakeConfirmationNotification;
use App\Notifications\CampagneDisponibiliteNotification;
use App\Notifications\DemandeNouvelleTourneeNotification;
use App\Notifications\FamilleVerificationNotification;
use App\Notifications\IntakeConfirmationNotification;
use App\Notifications\InvitationFamillesDejaInscritNotification;
use App\Notifications\InvitationFamillesNotification;
use App\Notifications\LivraisonConfirmationNotification;
use App\Notifications\NouvelleCandidatureBenevoleNotification;
use App\Notifications\NouvelleDemandeFamilleNotification;
use App\Notifications\PackagingAnnuleNotification;
use App\Notifications\RetraitHqAnnuleNotification;
use App\Notifications\RetraitHqNotification;
use App\Notifications\RouteChargeeNotification;
use App\Notifications\RouteIncidentNotification;
use App\Notifications\RouteModifieeNotification;
use App\Notifications\RoutePretePourChargementNotification;
use App\Services\QrCodeService;
use Closure;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use InvalidArgumentException;

/**
 * Catalogue des emails de l'application, pour l'outil d'aperçu (23/10/2026, commande
 * `familles:apercu-email` — voir docs/emails.md).
 *
 * Une entrée = une notification + des données d'exemple construites EN MÉMOIRE (jamais
 * persistées) : l'objectif est de pouvoir rendre/envoyer chaque email sans base de données
 * remplie, pour retoucher le gabarit pas à pas.
 *
 * Deux sortes de destinataires, comme en production :
 *   - `personne` : `$personne->notify()` (compte AMANA : bénévole, admin, gestionnaire) ;
 *   - `anonyme`  : `Notification::route('mail', $email)` (famille ou demande non confirmée,
 *     qui n'ont pas de compte).
 *
 * Ajouter un email : une entrée dans entrees(), c'est tout — le test
 * ApercuEmailTest::test_chaque_email_du_catalogue_se_rend verrouille que tout se rend.
 */
final class CatalogueEmails
{
    public const LANGUES = ['fr', 'ar', 'en'];

    /**
     * @return array<string, array{
     *     classe: class-string<Notification>,
     *     vue: string|null,
     *     destinataire: 'personne'|'anonyme',
     *     langues: bool,
     *     declencheur: string,
     *     construire: Closure(string, string): Notification
     * }>
     */
    public function entrees(): array
    {
        return [
            // ── Familles (destinataire anonyme, fr/ar/en) ───────────────────────
            'intake-confirmation' => [
                'classe' => IntakeConfirmationNotification::class,
                'vue' => 'emails.intake-confirmation',
                'destinataire' => 'anonyme',
                'langues' => true,
                'declencheur' => 'Une famille envoie le formulaire public de demande (IntakeController).',
                'construire' => fn(string $langue, string $adresse) => new IntakeConfirmationNotification(
                    (new IntakeDemandeAttente())->forceFill(['id' => 1, 'langue' => $langue, 'donnees' => ['prenom' => 'Amina']]),
                    'jeton-exemple',
                ),
            ],
            'verification-famille' => [
                'classe' => FamilleVerificationNotification::class,
                'vue' => 'emails.verification-famille',
                'destinataire' => 'anonyme',
                'langues' => true,
                'declencheur' => 'Vérification annuelle des dossiers validés (familles:envoyer-verifications, FamilleVerificationService).',
                'construire' => fn(string $langue, string $adresse) => new FamilleVerificationNotification(
                    (new FamilleVerification())->forceFill(['id' => 1]),
                    $this->famille($langue, $adresse),
                    'jeton-exemple',
                ),
            ],
            'livraison-confirmation' => [
                'classe' => LivraisonConfirmationNotification::class,
                'vue' => 'emails.livraison-confirmation',
                'destinataire' => 'anonyme',
                'langues' => true,
                'declencheur' => 'Démarrage d’une campagne : demande de confirmation de disponibilité (ContactTokenService).',
                'construire' => fn(string $langue, string $adresse) => new LivraisonConfirmationNotification(
                    $this->livraison($langue, $adresse),
                    $this->famille($langue, $adresse),
                    'jeton-exemple',
                ),
            ],
            'retrait-hq' => [
                'classe' => RetraitHqNotification::class,
                'vue' => 'emails.retrait-hq',
                'destinataire' => 'anonyme',
                'langues' => true,
                'declencheur' => 'Créneau de retrait au QG planifié (RetraitHqSchedulingService → RetraitHqNotificationService). Contient le QR code.',
                'construire' => fn(string $langue, string $adresse) => new RetraitHqNotification(
                    $this->livraison($langue, $adresse, seDeplace: true),
                    $this->famille($langue, $adresse),
                    app(QrCodeService::class),
                ),
            ],
            'retrait-hq-annule' => [
                'classe' => RetraitHqAnnuleNotification::class,
                'vue' => 'emails.retrait-hq-annule',
                'destinataire' => 'anonyme',
                'langues' => true,
                'declencheur' => 'Un retrait au QG est annulé (LivraisonChangementService / RetraitHqNotificationService).',
                'construire' => fn(string $langue, string $adresse) => new RetraitHqAnnuleNotification($this->famille($langue, $adresse)),
            ],

            // ── Bénévoles (candidature publique : anonyme ; compte : personne) ──
            'benevole-confirmation' => [
                'classe' => BenevoleIntakeConfirmationNotification::class,
                'vue' => 'emails.benevole-confirmation',
                'destinataire' => 'anonyme',
                'langues' => true,
                'declencheur' => 'Un bénévole envoie le formulaire public de candidature (BenevoleIntakeController).',
                'construire' => fn(string $langue, string $adresse) => new BenevoleIntakeConfirmationNotification(
                    (new BenevoleDemandeAttente())->forceFill(['id' => 1, 'langue' => $langue, 'donnees' => ['prenom' => 'Karim']]),
                    'jeton-exemple',
                ),
            ],
            'benevole-candidature-validee' => [
                'classe' => BenevoleCandidatureValideeNotification::class,
                'vue' => 'emails.benevole-candidature-validee',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Un admin valide une candidature bénévole, nouveau compte (BenevoleCandidaturesController).',
                'construire' => fn(string $langue, string $adresse) => new BenevoleCandidatureValideeNotification('https://exemple.test/reinitialisation/jeton'),
            ],
            'benevole-candidature-validee-deja-inscrit' => [
                'classe' => BenevoleCandidatureValideeDejaInscritNotification::class,
                'vue' => 'emails.benevole-candidature-validee-deja-inscrit',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Un admin valide une candidature bénévole d’une personne qui a déjà un compte.',
                'construire' => fn(string $langue, string $adresse) => new BenevoleCandidatureValideeDejaInscritNotification('https://exemple.test/connexion'),
            ],
            'nouvelle-candidature-benevole' => [
                'classe' => NouvelleCandidatureBenevoleNotification::class,
                'vue' => 'emails.nouvelle-candidature-benevole',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Un bénévole clique le lien de confirmation reçu par email : alerte aux administrateurs (BenevoleIntakeConfirmationController).',
                'construire' => fn(string $langue, string $adresse) => new NouvelleCandidatureBenevoleNotification(
                    (new BenevoleProfil())->forceFill(['id' => 1, 'langue_preferee' => $langue]),
                    $this->personne($adresse, 'Karim', 'Haddad'),
                ),
            ],
            'campagne-disponibilite' => [
                'classe' => CampagneDisponibiliteNotification::class,
                'vue' => 'emails.campagne-disponibilite',
                'destinataire' => 'personne',
                'langues' => true,
                'declencheur' => 'Une campagne s’ouvre : demande de disponibilité aux bénévoles (BenevoleDisponibiliteService).',
                'construire' => fn(string $langue, string $adresse) => new CampagneDisponibiliteNotification(
                    $this->campagne(),
                    (new BenevoleProfil())->forceFill(['id' => 1, 'langue_preferee' => $langue]),
                ),
            ],

            // ── Administration / comptes ───────────────────────────────────────
            'nouvelle-demande-famille' => [
                'classe' => NouvelleDemandeFamilleNotification::class,
                'vue' => 'emails.nouvelle-demande-famille',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Une famille clique le lien de confirmation reçu par email : alerte au staff (IntakeConfirmationController).',
                'construire' => fn(string $langue, string $adresse) => new NouvelleDemandeFamilleNotification($this->famille($langue, $adresse)),
            ],
            'invitation-familles' => [
                'classe' => InvitationFamillesNotification::class,
                'vue' => 'emails.invitation-familles',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Un admin donne accès à AMANA Familles à un nouvel utilisateur (PersonnesController).',
                'construire' => fn(string $langue, string $adresse) => new InvitationFamillesNotification('https://exemple.test/reinitialisation/jeton'),
            ],
            'invitation-familles-deja-inscrit' => [
                'classe' => InvitationFamillesDejaInscritNotification::class,
                'vue' => 'emails.invitation-familles-deja-inscrit',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Un admin donne accès à AMANA Familles à quelqu’un qui a déjà un compte (PersonnesController).',
                'construire' => fn(string $langue, string $adresse) => new InvitationFamillesDejaInscritNotification('https://exemple.test/connexion'),
            ],

            // ── Livraison : tournées et incidents (personne) ───────────────────
            'route-chargee' => [
                'classe' => RouteChargeeNotification::class,
                'vue' => 'emails.route-chargee',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'L’équipe chargement confirme le chargement : le chauffeur peut partir (ChargementController::confirmer).',
                'construire' => fn(string $langue, string $adresse) => new RouteChargeeNotification($this->route()),
            ],
            'route-prete-a-charger' => [
                'classe' => RoutePretePourChargementNotification::class,
                'vue' => 'emails.route-prete-a-charger',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Tous les colis d’une tournée sont conditionnés (RouteChargementService::promouvoirSiPrete) — email au chauffeur seulement.',
                'construire' => fn(string $langue, string $adresse) => new RoutePretePourChargementNotification($this->route()),
            ],
            'route-incident' => [
                'classe' => RouteIncidentNotification::class,
                'vue' => 'emails.route-incident',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Un incident est créé (RouteIncident::booted → notifierAdmins) : email aux admins/gestionnaires si urgent.',
                'construire' => fn(string $langue, string $adresse) => new RouteIncidentNotification(
                    (new RouteIncident())->forceFill([
                        'id' => 1,
                        'id_route' => 12,
                        'id_campagne' => 3,
                        'type' => 'benevole_absent',
                        'statut' => 'ouvert',
                        'notes' => 'Le chauffeur ne répond pas au téléphone.',
                    ]),
                ),
            ],
            'route-modifiee' => [
                'classe' => RouteModifieeNotification::class,
                'vue' => null,
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Une tournée est modifiée par le gestionnaire (RouteMutationService, LivraisonChangementService). Sans gabarit : mise en page Laravel par défaut.',
                'construire' => fn(string $langue, string $adresse) => new RouteModifieeNotification('Un arrêt a été ajouté à votre tournée : famille #1234, 14 rue des Lilas.'),
            ],
            'demande-nouvelle-tournee' => [
                'classe' => DemandeNouvelleTourneeNotification::class,
                'vue' => null,
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Un chauffeur revient au QG et est disponible (RetourQgService). Sans gabarit : mise en page Laravel par défaut.',
                'construire' => fn(string $langue, string $adresse) => new DemandeNouvelleTourneeNotification($this->route()),
            ],
            'packaging-annule' => [
                'classe' => PackagingAnnuleNotification::class,
                'vue' => null,
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Le packaging reprend un colis d’une tournée en chargement (PackagingController::annulerConditionnement). Sans gabarit : mise en page Laravel par défaut.',
                'construire' => fn(string $langue, string $adresse) => new PackagingAnnuleNotification(
                    $this->route(),
                    $this->livraison($langue, $adresse),
                ),
            ],

            // ── Comptes (amana_shared — gabarit amana-shared::emails.compte) ───
            'compte-reinitialisation-mot-de-passe' => [
                'classe' => ResetPasswordNotification::class,
                'vue' => 'amana-shared::emails.compte',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Mot de passe oublié / création de compte (Password::broker, AuthController). Gabarit dans amana_shared.',
                'construire' => fn(string $langue, string $adresse) => new ResetPasswordNotification('Amina', 'https://exemple.test/reinitialisation/jeton', 60),
            ],
            'compte-mot-de-passe-modifie' => [
                'classe' => PasswordChangedNotification::class,
                'vue' => 'amana-shared::emails.compte',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Mot de passe modifié (profil, réinitialisation, création, administrateur). Gabarit dans amana_shared.',
                'construire' => fn(string $langue, string $adresse) => new PasswordChangedNotification('Amina', 'profil', '23/10/2026 à 10:30'),
            ],
            'compte-confirmation-changement-email' => [
                'classe' => EmailChangeConfirmationNotification::class,
                'vue' => 'amana-shared::emails.compte',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Demande de changement d’adresse email : lien de confirmation. Gabarit dans amana_shared.',
                'construire' => fn(string $langue, string $adresse) => new EmailChangeConfirmationNotification('Amina', 'https://exemple.test/email/confirmer/jeton', 60),
            ],
            'compte-email-modifie' => [
                'classe' => EmailChangedNotification::class,
                'vue' => 'amana-shared::emails.compte',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Adresse email modifiée : alerte envoyée à l’ANCIENNE adresse. Gabarit dans amana_shared.',
                'construire' => fn(string $langue, string $adresse) => new EmailChangedNotification('Amina', 'a***@exemple.fr', false, '23/10/2026 à 10:30'),
            ],
            'compte-email-connexion' => [
                'classe' => EmailNowLoginNotification::class,
                'vue' => 'amana-shared::emails.compte',
                'destinataire' => 'personne',
                'langues' => false,
                'declencheur' => 'Adresse email modifiée : information envoyée à la NOUVELLE adresse. Gabarit dans amana_shared.',
                'construire' => fn(string $langue, string $adresse) => new EmailNowLoginNotification('Amina', false, '23/10/2026 à 10:30'),
            ],
        ];
    }

    /** @return list<string> */
    public function cles(): array
    {
        return array_keys($this->entrees());
    }

    /**
     * @return array{classe: class-string<Notification>, vue: string|null, destinataire: 'personne'|'anonyme', langues: bool, declencheur: string, construire: Closure(string, string): Notification}
     */
    public function entree(string $cle): array
    {
        return $this->entrees()[$cle]
            ?? throw new InvalidArgumentException("Email inconnu « {$cle} ». Clés possibles : " . implode(', ', $this->cles()));
    }

    /**
     * La notification d'exemple ET son destinataire, prêts pour toMail().
     *
     * @return array{0: Notification, 1: object}
     */
    public function construire(string $cle, string $langue = 'fr', string $adresse = 'destinataire@exemple.test'): array
    {
        $langue = in_array($langue, self::LANGUES, true) ? $langue : 'fr';
        $entree = $this->entree($cle);

        $notification = ($entree['construire'])($langue, $adresse);
        $destinataire = $entree['destinataire'] === 'anonyme'
            ? $this->destinataireAnonyme($adresse)
            : $this->personne($adresse, 'Amina', 'Benali');

        return [$notification, $destinataire];
    }

    private function destinataireAnonyme(string $adresse): AnonymousNotifiable
    {
        return NotificationFacade::route('mail', $adresse);
    }

    private function personne(string $adresse, string $prenom, string $nom): Personne
    {
        $personne = new Personne();
        $personne->forceFill(['id' => 1, 'prenom' => $prenom, 'nom' => $nom, 'email' => $adresse]);

        return $personne;
    }

    private function famille(string $langue, string $adresse): Famille
    {
        $famille = Famille::factory()->make([
            'prenom' => 'Amina',
            'nom' => 'Benali',
            'email' => $adresse,
            'langue' => $langue,
        ]);
        $famille->id = 1234;

        return $famille;
    }

    private function campagne(): Campagne
    {
        $campagne = new Campagne();
        $campagne->forceFill([
            'id' => 3,
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => '2026-11-10',
            'hq_adresse' => '12 rue de la Solidarité, 44000 Nantes',
        ]);

        return $campagne;
    }

    private function livraison(string $langue, string $adresse, bool $seDeplace = false): Livraison
    {
        $livraison = new Livraison();
        $livraison->forceFill([
            'id' => 4321,
            'id_campagne' => 3,
            'se_deplace' => $seDeplace,
            'heure_arrivee_prevue_hq' => '2026-11-10 10:30:00',
        ]);
        $livraison->setRelation('campagne', $this->campagne());
        $livraison->setRelation('famille', $this->famille($langue, $adresse));

        return $livraison;
    }

    private function route(): RouteLivraison
    {
        $route = new RouteLivraison();
        $route->forceFill(['id' => 12, 'id_campagne' => 3, 'id_benevole' => 1]);

        return $route;
    }
}
