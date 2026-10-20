<?php
// app/Support/Guide/GuideContenu.php

declare(strict_types=1);

namespace App\Support\Guide;

use Amana\Shared\Models\Personne;
use App\Models\CampagneEquipeMembre;

/**
 * Contenu du « Guide d'utilisation » d'AMANA Familles (18/10/2026), filtré par rôle CÔTÉ SERVEUR.
 *
 * Pourquoi du contenu PHP et pas un template Vue (comme le guide de planning) : une section qu'un rôle n'a pas le
 * droit de lire n'est jamais envoyée à son navigateur (ni dans le HTML, ni dans le bundle JS, ni dans les props
 * Inertia) — chaque rôle n'accède qu'à SON guide, plus les pages communes. Le squelette visuel (sommaire,
 * sections, accordéons) vient de @amana/shared-ui (GuidePage, GuideSection, GuideItem) ; la page Vue ne fait que
 * rendre ces données (voir resources/js/pages/Guide/GuideIndex.vue).
 *
 * Règles de visibilité :
 *  - `bienvenue`, `statistiques` : toute personne connectée (pages communes) ;
 *  - hiérarchie cumulative : membre → `dossiers` ; bénévole (chauffeur) → `ma-tournee` ;
 *    gestionnaire → `gestion` (+ tout ce que voit un membre, un bénévole et les équipes, qu'il peut suppléer) ;
 *    admin → `administration` en plus ;
 *  - équipes (réception, pesée, packaging, chargement/retrait QG) : UNIQUEMENT leur poste, que la personne ait le
 *    rôle `equipe_*` ou soit affectée à une campagne (CampagneEquipeMembre::estAffecteQuelquePart(), comme le lien
 *    de sidebar correspondant).
 *
 * Le HTML des contenus est écrit ici, en dur et de confiance : ce n'est jamais une saisie utilisateur.
 */
final class GuideContenu
{
    /** Identifiants de section, dans l'ordre d'affichage. */
    public const SECTIONS = [
        'bienvenue', 'dossiers', 'statistiques', 'ma-tournee',
        'reception', 'pesee', 'packaging', 'chargement',
        'gestion', 'administration',
    ];

    /**
     * Sections visibles par cette personne, dans l'ordre d'affichage.
     *
     * @return list<array{id: string, icon: string, title: string, subtitle: string|null, items: list<array{icon: string, title: string, open: bool, html: string, example: string|null}>}>
     */
    public static function sectionsPour(?Personne $personne): array
    {
        if ($personne === null) {
            return [];
        }

        $sections = [];
        foreach (self::SECTIONS as $id) {
            if (self::peutVoir($personne, $id)) {
                $sections[] = self::section($id);
            }
        }

        return $sections;
    }

    /** @return list<string> */
    public static function idsPour(?Personne $personne): array
    {
        return array_map(fn(array $s) => $s['id'], self::sectionsPour($personne));
    }

    public static function peutVoir(Personne $personne, string $id): bool
    {
        $gestionnaire = $personne->hasAtLeastRole('gestionnaire');

        return match ($id) {
            'bienvenue', 'statistiques' => true,
            'dossiers' => $personne->hasAtLeastRole('membre'),
            'ma-tournee' => $personne->hasAtLeastRole('benevole'),
            'reception' => $gestionnaire || self::dansEquipe($personne, 'equipe_reception'),
            'pesee' => $gestionnaire || self::dansEquipe($personne, 'equipe_pesee'),
            'packaging' => $gestionnaire || self::dansEquipe($personne, 'equipe_packaging'),
            'chargement' => $gestionnaire || self::dansEquipe($personne, 'equipe_chargement'),
            'gestion' => $gestionnaire,
            'administration' => $personne->hasAtLeastRole('admin'),
            default => false,
        };
    }

    private static function dansEquipe(Personne $personne, string $role): bool
    {
        return $personne->hasRole($role) || CampagneEquipeMembre::estAffecteQuelquePart($personne->id, $role);
    }

    /**
     * @return array{id: string, icon: string, title: string, subtitle: string|null, items: list<array{icon: string, title: string, open: bool, html: string, example: string|null}>}
     */
    private static function section(string $id): array
    {
        return match ($id) {
            'bienvenue' => self::bienvenue(),
            'dossiers' => self::dossiers(),
            'statistiques' => self::statistiques(),
            'ma-tournee' => self::maTournee(),
            'reception' => self::reception(),
            'pesee' => self::pesee(),
            'packaging' => self::packaging(),
            'chargement' => self::chargement(),
            'gestion' => self::gestion(),
            'administration' => self::administration(),
            default => throw new \InvalidArgumentException("Section de guide inconnue : {$id}"),
        };
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3?: string|null, 4?: bool}>  $items  [icône, titre, html, exemple, ouvert]
     * @return array{id: string, icon: string, title: string, subtitle: string|null, items: list<array{icon: string, title: string, open: bool, html: string, example: string|null}>}
     */
    private static function construire(string $id, string $icone, string $titre, ?string $sousTitre, array $items): array
    {
        return [
            'id' => $id,
            'icon' => $icone,
            'title' => $titre,
            'subtitle' => $sousTitre,
            'items' => array_map(fn(array $i) => [
                'icon' => $i[0],
                'title' => $i[1],
                'html' => $i[2],
                'example' => $i[3] ?? null,
                'open' => $i[4] ?? false,
            ], $items),
        ];
    }

    // ── Pages communes ───────────────────────────────────────────────────

    private static function bienvenue(): array
    {
        return self::construire('bienvenue', '👋', 'Pour bien démarrer', null, [
            ['🔑', 'Se connecter et retrouver son mot de passe',
                "<p>Connectez-vous avec votre adresse email et votre mot de passe. Si vous l'avez oublié, utilisez le lien <strong>Mot de passe oublié</strong> de la page de connexion : un email vous permet d'en choisir un nouveau.</p>",
                null, true],
            ['🧭', 'Se repérer dans la barre latérale',
                "<p>La barre latérale à gauche liste uniquement les écrans auxquels vous avez accès, regroupés par thème (Dossiers, Livraison, Administration…). Sur téléphone, elle s'ouvre avec le bouton menu en haut de l'écran.</p>"
                . "<p>En bas de la barre, une rangée de boutons : <strong>Se déconnecter</strong>, le bouton <strong>❓ Guide d'utilisation</strong> (cette page) et la bascule du <strong>thème clair / sombre</strong>.</p>"],
            ['👤', 'Modifier mon profil',
                "<p>Cliquez sur votre nom en haut de la barre latérale pour ouvrir votre profil : coordonnées, adresse email et mot de passe. Un changement d'adresse email doit être confirmé depuis le lien reçu par email.</p>"],
            ['🌓', 'Passer en thème sombre',
                '<p>Le bouton 🌙 en bas de la barre latérale bascule entre thème clair et thème sombre. Votre choix est mémorisé sur cet appareil.</p>'],
            ['🚪', 'Se déconnecter',
                "<p>Le bouton <strong>Se déconnecter</strong> demande d'abord confirmation : répondez <strong>Oui</strong> pour fermer votre session, <strong>Non</strong> pour rester connecté.</p>",
                'Vous avez cliqué sur Se déconnecter par erreur : répondez « Non », vous restez connecté et rien n’est perdu.'],
            ['❓', 'Ouvrir ce guide à tout moment',
                "<p>Le bouton ❓ de la barre latérale ouvre ce guide depuis n'importe quel écran. Il ne montre que les parties qui concernent votre rôle : si une fonction n'y figure pas, c'est qu'elle ne fait pas partie de vos accès.</p>"],
        ]);
    }

    private static function statistiques(): array
    {
        return self::construire('statistiques', '📊', 'Statistiques de livraison', 'Lecture seule, ouverte à tous', [
            ['📈', 'Suivre l\'avancement des livraisons',
                "<p>L'écran <strong>Statistiques</strong> (section Livraison) présente, en lecture seule, les indicateurs d'avancement de la campagne en cours. Il n'y a rien à saisir : c'est un tableau de suivi partagé par toute l'équipe.</p>"],
        ]);
    }

    // ── Dossiers ─────────────────────────────────────────────────────────

    private static function dossiers(): array
    {
        return self::construire('dossiers', '🗂️', 'Dossiers des familles', null, [
            ['📥', 'Traiter les nouvelles demandes',
                "<p>L'écran <strong>Nouvelles demandes</strong> liste les familles qui viennent de déposer un dossier. Ouvrez une demande pour relire les informations et la traiter selon les droits de votre rôle.</p>",
                null, true],
            ['🔍', 'Retrouver une famille',
                "<p>L'écran <strong>Familles</strong> regroupe tous les dossiers. Utilisez la recherche et les filtres pour retrouver une famille, puis ouvrez sa fiche pour consulter ou mettre à jour ses informations.</p>"],
        ]);
    }

    // ── Chauffeur ────────────────────────────────────────────────────────

    private static function maTournee(): array
    {
        return self::construire('ma-tournee', '🚚', 'Ma tournée (chauffeur)', null, [
            ['▶️', 'Démarrer ma tournée',
                "<p>L'écran <strong>Ma tournée</strong> affiche les familles à livrer, dans l'ordre conseillé, avec l'adresse et un lien d'itinéraire. Quand vos colis sont chargés, démarrez la tournée avec le bouton prévu en haut de l'écran.</p>",
                null, true],
            ['✅', 'Confirmer chaque livraison',
                "<p>À chaque arrêt, confirmez la livraison dès qu'elle est faite. Si une livraison est impossible (famille absente, adresse introuvable…), utilisez <strong>Ignorer cette livraison</strong> : un incident est ouvert pour que les gestionnaires décident de la suite.</p>"],
            ['🏁', 'Terminer et répondre à « Revenez-vous au QG ? »',
                "<p>Une fois toutes les familles traitées, appuyez sur <strong>Livraison terminé</strong>. L'application demande ensuite si vous revenez au QG :</p>"
                . '<ul><li><strong>Oui</strong> : votre tournée est terminée et vous redevenez disponible pour une nouvelle tournée.</li>'
                . "<li><strong>Non</strong> : votre tournée reste enregistrée comme terminée, mais vous n'êtes plus proposé pour les prochaines tournées de la journée.</li></ul>"
                . "<p>Vous pouvez changer d'avis : appuyez de nouveau sur <strong>Livraison terminé</strong> et répondez <strong>Oui</strong>, ou demandez à un gestionnaire de vous remettre dans le pool.</p>",
                'Vous aviez répondu « Non » mais êtes finalement revenu : appuyez de nouveau sur « Livraison terminé » et répondez « Oui », ou prévenez un gestionnaire.'],
        ]);
    }

    // ── Équipes ──────────────────────────────────────────────────────────

    private static function reception(): array
    {
        return self::construire('reception', '🧾', 'Réception', 'Équipe réception', [
            ['🚪', 'Accéder à l\'écran de réception',
                "<p>Le lien <strong>Réception</strong> de la barre latérale commence par vous demander la campagne sur laquelle vous travaillez, puis ouvre l'écran de réception de cette campagne. Vous n'y voyez que ce qui concerne votre poste.</p>",
                null, true],
            ['📝', 'Enregistrer ce qui arrive',
                "<p>Saisissez chaque arrivage au fil de l'eau, dès qu'il est reçu : ce que l'équipe réception enregistre alimente les écrans de pesée, de packaging et les statistiques de la campagne. Vérifiez vos saisies avant de les valider.</p>"],
        ]);
    }

    private static function pesee(): array
    {
        return self::construire('pesee', '⚖️', 'Pesée', 'Équipe pesée', [
            ['🚪', 'Accéder à l\'écran de pesée',
                "<p>Le lien <strong>Pesée</strong> de la barre latérale demande d'abord la campagne, puis ouvre l'écran de pesée correspondant.</p>",
                null, true],
            ['⚖️', 'Enregistrer les poids',
                "<p>Les poids saisis alimentent la couverture de la collecte (poids engagé et reste à conditionner), visible dans l'écran de packaging. Vérifiez la valeur avant de valider.</p>"],
        ]);
    }

    private static function packaging(): array
    {
        return self::construire('packaging', '📦', 'Packaging', 'Équipe packaging', [
            ['🚪', 'Accéder à l\'écran de packaging',
                "<p>Le lien <strong>Packaging</strong> de la barre latérale demande d'abord la campagne, puis ouvre l'écran de conditionnement : une carte par famille, avec ses colis à préparer.</p>",
                null, true],
            ['✅', 'Marquer les colis prêts',
                "<p>Pour chaque famille, marquez les colis au fur et à mesure qu'ils sont prêts. Une famille dont seule une partie des colis est prête passe « en cours de conditionnement » : elle n'est complète que lorsque tous ses colis sont prêts.</p>"],
            ['📊', 'Comprendre « Reste à conditionner »',
                "<p>Le bandeau de couverture indique le poids déjà engagé (colis prêts, familles livrées) et le <strong>reste à conditionner</strong>. Le reste est calculé colis par colis : une famille à moitié prête compte pour moitié dans l'engagé et pour moitié dans le reste, il ne tombe donc à zéro que lorsque tous les colis de toutes les familles sont prêts.</p>"
                . "<p>Si les poids moyens de la campagne ont changé depuis, les colis déjà prêts gardent leur poids d'origine ; seuls les colis restants sont recalculés.</p>"],
            ['↩️', 'Annuler un conditionnement',
                "<p>Si un colis a été préparé par erreur ou doit être refait, annulez son conditionnement : la famille repasse à préparer. Si une tournée était déjà organisée avec elle, signalez-le à un gestionnaire pour qu'il l'ajuste.</p>"],
        ]);
    }

    private static function chargement(): array
    {
        return self::construire('chargement', '🚛', 'Chargement et retrait au QG', 'Équipe chargement', [
            ['🚪', 'Accéder aux écrans',
                "<p>Les liens <strong>Chargement</strong> et <strong>Retrait QG</strong> de la barre latérale demandent d'abord la campagne. Chargement concerne les tournées des chauffeurs ; Retrait QG concerne les familles qui viennent chercher leur colis.</p>",
                null, true],
            ['🚛', 'Charger une tournée',
                '<p>Chaque carte représente une tournée : chauffeur, créneau, familles et progression du chargement. Chargez les colis famille par famille ; la tournée passe « chargée » quand tout est à bord et le chauffeur peut démarrer.</p>'],
            ['⚠️', 'Quand le chauffeur n\'est pas encore rentré',
                "<p>Un bandeau jaune sur une carte signale que le chauffeur est encore en livraison : sa tournée précédente n'est pas terminée. Inutile de l'attendre pour préparer, mais le chargement ne peut être remis qu'à son retour.</p>"
                . '<p>Un bandeau rouge signale que le chauffeur a répondu « Non » à « Revenez-vous au QG ? » : il ne reviendra pas. Un gestionnaire doit alors réassigner ou répartir la tournée (incident « Chauffeur indisponible »).</p>'],
            ['🏠', 'Remettre un colis au QG',
                "<p>Dans <strong>Retrait QG</strong>, remettez le colis à la famille qui se présente et validez la remise. Si la famille ne vient pas, utilisez <strong>Non livré</strong> : un incident permet aux gestionnaires de décider de la suite (par exemple une livraison à domicile). En cas d'erreur, un « Non livré » se corrige et le colis redevient prêt.</p>"],
        ]);
    }

    // ── Gestionnaires ────────────────────────────────────────────────────

    private static function gestion(): array
    {
        return self::construire('gestion', '⚙️', 'Gestion de la livraison', 'Visible par les gestionnaires et les administrateurs', [
            ['🎁', 'Piloter une campagne',
                "<p>L'écran <strong>Campagnes</strong> liste les campagnes ; chacune a un hub qui suit les étapes dans l'ordre : paramètres, sélection des familles, contacts, génération des routes, démarrage, suivi livraison et clôture. Les pastilles indiquent l'avancement de chaque étape.</p>",
                null, true],
            ['🧺', 'Sélectionner les familles et suivre les contacts',
                '<p><strong>Sélection des familles</strong> détermine qui est livré ; <strong>Suivi des contacts</strong> permet de confirmer avec chaque famille sa présence et son mode de réception (livraison ou retrait au QG).</p>'],
            ['🗺️', 'Générer les routes',
                '<p>Dans <strong>Génération des routes</strong>, choisissez le mode automatique ou personnalisé, puis les chauffeurs de chaque créneau. Chaque chauffeur affiche son état :</p>'
                . '<ul><li><strong>Déjà en tournée</strong> : grisé, déjà pris sur ce créneau.</li>'
                . "<li><strong>Parti · ne revient pas</strong> : grisé, il a répondu « Non » à « Revenez-vous au QG ? » et n'est plus proposé.</li>"
                . "<li><strong>⚠️ En livraison</strong> : sélectionnable, à titre indicatif — un créneau dure deux heures et un chauffeur peut enchaîner plusieurs allers-retours. S'il répond « Non » ensuite, un incident est levé.</li></ul>",
                'Marie est encore en livraison mais reviendra dans l’heure : vous pouvez la retenir pour le créneau suivant, l’avertissement est seulement informatif.'],
            ['🚨', 'Résoudre l\'incident « Chauffeur indisponible »',
                "<p>Quand un chauffeur retenu pour une tournée à venir répond « Non » au retour au QG, un incident s'ouvre sur cette tournée. Deux solutions :</p>"
                . '<ul><li><strong>La réassigner</strong> à un autre chauffeur disponible dont le véhicule est de même type ou plus grand (capacité et nombre de colis), au choix.</li>'
                . "<li><strong>La répartir</strong> entre au moins deux chauffeurs quand aucun véhicule assez grand n'est libre : les arrêts sont découpés en tronçons cohérents, au prorata de la capacité de chaque véhicule.</li></ul>"
                . "<p>Si le chauffeur est finalement revenu, utilisez <strong>Le remettre dans le pool</strong> : sa tournée est terminée, il redevient disponible et l'incident se résout.</p>"],
            ['📍', 'Suivre les tournées',
                "<p><strong>Suivi livraison</strong> montre en direct les tournées, les livraisons restantes et les incidents. Une tournée dont toutes les livraisons sont faites compte comme terminée, même si le chauffeur n'a pas répondu à « Revenez-vous au QG ? » ou a répondu « Non ».</p>"],
            ['🏁', 'Clôturer la campagne',
                "<p>Avant la clôture, l'écran liste les tournées non terminées (en cours, annulées de packaging…) et les incidents ouverts. Une tournée aux livraisons terminées ne bloque plus la clôture.</p>"],
            ['🔧', 'Régler les paramètres',
                "<p>L'écran <strong>Paramètres</strong> regroupe les réglages généraux, l'adresse du QG, les types de véhicule, les organisations et les adresses d'hôtel utilisées par l'application.</p>"],
            ['📊', 'Consulter les statistiques des dossiers',
                "<p>L'écran <strong>Statistiques</strong> de la section Dossiers donne la vue d'ensemble des familles : répartition, évolution et indicateurs de suivi.</p>"],
        ]);
    }

    // ── Administrateurs ──────────────────────────────────────────────────

    private static function administration(): array
    {
        return self::construire('administration', '🛡️', 'Administration', 'Visible par les administrateurs uniquement', [
            ['✅', 'Vérifier les dossiers',
                "<p>L'écran <strong>Vérifications</strong> liste les contrôles en attente (cohérence des dossiers, doublons). Traitez-les un par un : chaque décision est tracée dans le journal d'audit.</p>",
                null, true],
            ['📥', 'Importer des dossiers en masse',
                "<p>L'écran <strong>Imports</strong> permet de charger plusieurs familles à la fois depuis un fichier. Relisez le résumé avant de confirmer l'import : il signale les lignes ignorées ou en doublon.</p>"],
            ['🤝', 'Gérer les candidatures bénévoles',
                '<p><strong>Candidatures bénévoles</strong> liste les personnes qui proposent leur aide : validez, refusez ou demandez des précisions. Un bénévole validé peut ensuite être retenu comme chauffeur.</p>'],
            ['👥', 'Gérer les personnes et les rôles',
                "<p>L'écran <strong>Personnes</strong> permet de consulter un compte, de lui donner ou retirer des rôles (gestionnaire, équipes réception, pesée, packaging, chargement…) et d'activer ou désactiver l'accès.</p>"],
            ['📈', 'Statistiques d\'activité et journal d\'audit',
                "<p><strong>Statistiques d'activité</strong> mesure l'usage de l'application ; le <strong>Journal d'audit</strong> garde la trace des modifications sensibles (qui, quoi, quand) pour retrouver l'origine d'un changement.</p>"],
        ]);
    }
}
