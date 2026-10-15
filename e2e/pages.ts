// e2e/pages.ts
// Inventaire des pages testées (routes GET statiques de routes/*.php, 14/10/2026).
// `roles` = rôles qui doivent y accéder. À CONFIRMER après le premier passage
// (hiérarchie admin > gestionnaire > membre > benevole ; gestionnaire_externe à part).
//
// Hors périmètre pour l'instant (besoin d'un identifiant de campagne/famille ou
// d'appartenance à une équipe de livraison, donc de données seedées précises) :
// /familles/{id}, /livraison/campagnes/{campagne}/…, /livraison/reception|pesee|
// packaging|chargement, /admin/benevoles/{id}, /admin/imports/{id}. Compléter ici
// une fois les données de LivraisonDemoSeeder confirmées.

import type { Role } from './fixtures';

export interface PageSpec {
    slug: string;
    path: string;
    roles: Role[];
}

const ALL_RANG: Role[] = ['admin', 'gestionnaire', 'membre', 'benevole'];
const MEMBRE_PLUS: Role[] = ['admin', 'gestionnaire', 'membre'];
const GEST_PLUS: Role[] = ['admin', 'gestionnaire'];
const ADMIN: Role[] = ['admin'];
const EXTERNE: Role[] = ['gestionnaire_externe'];

export const PUBLIC_PAGES = [
    { slug: 'login', path: '/login' },
    { slug: 'mot-de-passe-oublie', path: '/mot-de-passe-oublie' },
    { slug: 'demande', path: '/demande' },
    { slug: 'devenir-benevole', path: '/devenir-benevole' },
];

export const AUTH_PAGES: PageSpec[] = [
    { slug: 'familles', path: '/', roles: MEMBRE_PLUS },
    { slug: 'familles-nouvelles', path: '/nouvelles', roles: MEMBRE_PLUS },
    { slug: 'mon-profil', path: '/mon-profil', roles: [...ALL_RANG, ...EXTERNE] },
    { slug: 'familles-creer', path: '/familles/creer', roles: GEST_PLUS },
    { slug: 'familles-statistiques', path: '/familles/statistiques', roles: GEST_PLUS },
    { slug: 'settings', path: '/settings', roles: GEST_PLUS },
    { slug: 'rattachements', path: '/rattachements', roles: GEST_PLUS },
    { slug: 'livraison-campagnes', path: '/livraison/campagnes', roles: GEST_PLUS },
    { slug: 'livraison-campagnes-creer', path: '/livraison/campagnes/creer', roles: GEST_PLUS },
    { slug: 'livraison-contacts', path: '/livraison/contacts', roles: GEST_PLUS },
    { slug: 'livraison-contacts-statistiques', path: '/livraison/contacts/statistiques', roles: GEST_PLUS },
    { slug: 'livraison-suivi', path: '/livraison/suivi-livraison', roles: GEST_PLUS },
    { slug: 'livraison-familles-eligibles', path: '/livraison/familles-eligibles', roles: GEST_PLUS },
    { slug: 'livraison-statistiques', path: '/livraison/statistiques', roles: ALL_RANG },
    { slug: 'livraison-ma-route', path: '/livraison/benevole/ma-route', roles: ALL_RANG },
    { slug: 'mes-imports', path: '/mes-imports', roles: EXTERNE },
    { slug: 'mes-imports-creer', path: '/mes-imports/creer', roles: EXTERNE },
    { slug: 'admin-personnes', path: '/admin/personnes', roles: ADMIN },
    { slug: 'admin-personnes-creer', path: '/admin/personnes/creer', roles: ADMIN },
    { slug: 'admin-imports', path: '/admin/imports', roles: ADMIN },
    { slug: 'admin-imports-creer', path: '/admin/imports/creer', roles: ADMIN },
    { slug: 'admin-activite', path: '/admin/activite', roles: ADMIN },
    { slug: 'admin-journal', path: '/admin/journal', roles: ADMIN },
    { slug: 'admin-verifications', path: '/admin/verifications', roles: ADMIN },
    { slug: 'admin-benevoles', path: '/admin/benevoles', roles: ADMIN },
];
