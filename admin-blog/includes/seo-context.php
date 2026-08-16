<?php
/**
 * seo-context.php
 * --------------------------------------------------------------
 * Contexte SEO stratégique d'Assokit, issu de l'analyse Search Console.
 * Injecté dans la SUGGESTION de sujets par IA (et réutilisable pour la
 * génération) afin d'orienter les propositions vers ce qui rapporte
 * réellement du trafic qualifié.
 *
 * 👉 ÉDITABLE : mets à jour le bloc "DONNÉES SEARCH CONSOLE" avec les vraies
 *    requêtes de la GSC (onglet Performances -> Requêtes). Les plus rentables :
 *    - Requêtes en position 5-20 avec des impressions (quick wins : un article
 *      dédié peut les faire monter en page 1).
 *    - Requêtes à impressions élevées mais CTR faible (title/meta à retravailler).
 *    - Requêtes "logiciel/outil/meilleur" (intention transactionnelle -> money).
 * --------------------------------------------------------------
 */

if (!function_exists('ak_seo_strategy_context')) {
    /**
     * Renvoie le brief SEO stratégique injecté dans les prompts.
     */
    function ak_seo_strategy_context(): string
    {
        return <<<'SEO'
CONTEXTE SEO ASSOKIT (à respecter dans toutes les propositions) :

OBJECTIF : capter du trafic organique qualifié (cible 15 000 visites/mois) et
convertir vers Assokit. Chaque sujet doit servir une intention de recherche
réelle et se rattacher à un cluster ci-dessous (logique de cocon sémantique).

AUDIENCE : dirigeants bénévoles d'associations loi 1901 (président, trésorier,
secrétaire) et gérants de TPE/indépendants français. Peu de temps, pas experts
comptables/juridiques. Ton clair, concret, rassurant.

DIFFÉRENCIATION (angle éditorial gagnant) : traiter le CAS SPÉCIFIQUE des
associations/TPE françaises là où les contenus concurrents restent génériques
"entreprise". C'est là qu'on gagne : faible concurrence, forte pertinence.

CLUSTERS PRIORITAIRES (pilier -> money page à mailler) :
1. Gestion d'association loi 1901 -> /logiciel-association
2. Adhérents & cotisations (relances, reçus, paiement en ligne) -> /logiciel-adherents, /logiciel-cotisation-association
3. Comptabilité associative & analytique par projet -> /logiciel-comptabilite-association, /comptabilite-analytique
4. Facturation & FACTURE ÉLECTRONIQUE (Factur-X, PDP, calendrier 2026-2027) -> /logiciel-facturation
5. Subventions (recherche, dossier, suivi, CERFA) -> /pour-associations
6. Juridique & obligations (statuts, AG, RGPD, registre) -> /logiciel-association
7. Communication & IA (newsletter, compte-rendu, réseaux) -> /fonctionnalites
8. TPE/indépendants : devis, factures, trésorerie -> /logiciel-gestion-tpe

INTENTIONS À COUVRIR (varier le tunnel) :
- Informationnel (comment faire, guide, définition) : top of funnel, gros volume.
- Comparatif / "meilleur logiciel / outil / alternative" : intention transactionnelle
  forte -> à privilégier (proche de la conversion).
- Modèles / gratuit / exemple (statuts, PV, budget, facture) : très recherché.

SAISONNALITÉ (à exploiter selon le mois) :
- Sept-oct : rentrée associative, cotisations, recrutement bénévoles.
- Déc-jan : assemblées générales, bilan, budget prévisionnel.
- Avr-juin : subventions, clôture d'exercice, comptes annuels.
- 2026-2027 : réforme FACTURE ÉLECTRONIQUE (sujet chaud, faible concurrence côté assos).

QUICK WINS (prioriser priority=1-2) :
- Sujets proches d'une requête où le site a déjà des impressions mais une position
  moyenne (5-20) : un article dédié et mieux optimisé peut passer en page 1.
- Sujets qui complètent un cocon existant (renforce le pilier par maillage interne).

DONNÉES SEARCH CONSOLE (à compléter avec les vraies requêtes GSC) :
- [Colle ici les top requêtes réelles : "requête" — impressions — position moyenne]
- [Ex : "logiciel gestion association gratuit" — 1 200 impr. — pos. 12 -> quick win]
- [Ex : "comment créer une association" — 3 400 impr. — pos. 8]
- (Tant que ce bloc n'est pas rempli, appuie-toi sur les CLUSTERS et INTENTIONS ci-dessus.)

À ÉVITER : sujets hors cible (grandes entreprises, particuliers), doublons/variantes
proches de l'existant, sujets sans volume de recherche plausible, clickbait.
SEO;
    }
}
