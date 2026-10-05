<?php
/**
 * demo-formation/catalogue.php — Les dossiers et projets de DEMO F.
 * ------------------------------------------------------------------
 * Partagé par tous les modules : l'agenda, les subventions, la
 * messagerie ou les notifications désignent un projet par sa CLÉ
 * (df_id('projets', 'fle-automne')), jamais par son nom ni son id.
 * Le module 10-projets crée les lignes et remplit le registre.
 *
 * Les clients, financeurs et partenaires cités sont fictifs ; les noms
 * de communes et de dispositifs publics (Pass Numérique, PLIE, Qualiopi,
 * DELF, Pix) sont réels, comme dans le quotidien d'une vraie association.
 *
 * Clés de personnes : celles de DF::$u (admin, salarie, benevole, membre,
 * financeur, secretaire, tresoriere, vicepres, formatrice, insertion,
 * communication, civique, prestataire, fle2, devoirs, emploi, numerique2,
 * evenements, logistique, accueil).
 * ------------------------------------------------------------------
 */

/** cle => [nom, couleur, icône, archivé il y a N jours (null = actif)] */
function df_cat_dossiers(): array
{
    return [
        'fle'       => ['Français Langue Étrangère (FLE)',       'indigo',  'graduation-cap', null],
        'numerique' => ['Inclusion numérique',                   'blue',    'lightbulb',      null],
        'emploi'    => ['Insertion & retour à l\'emploi',        'emerald', 'briefcase',      null],
        'clients'   => ['Formations professionnelles (clients)', 'purple',  'target',         null],
        'jeunesse'  => ['Jeunesse & accompagnement scolaire',    'amber',   'users',          null],
        'vie'       => ['Vie associative & qualité',             'teal',    'handshake',      null],
        'ete'       => ['Ateliers d\'été ' . (df_annee() - 1),   'slate',   'calendar',       6],
    ];
}

/**
 * cle => [
 *   dossier, nom, statut (draft|active|warning|done), référent (clé DF::$u),
 *   lieu, budget prévu (€), participants, [femmes, hommes],
 *   début (jours), fin (jours), étapes [[intitulé, cochée?, assignée à (clé|null)], …],
 *   équipe [clé => rôle], suivi par le financeur ?, archivé (null | [jours, via le dossier ?]),
 *   description
 * ]
 */
function df_cat_projets(): array
{
    $s = df_annee();
    return [
        // ---------------- FLE ----------------
        'fle-automne' => ['fle', 'Parcours FLE A1→A2 — Session automne', 'active', 'benevole',
            'Massy — Centre social Les Ateliers', 18000, 24, [17, 7], -75, 80, [
                ['Tests de positionnement', true, null],
                ['Constitution des 3 groupes de niveau', true, null],
                ['Convention salle avec le centre social', true, null],
                ['Lancement des cours (6 h/semaine)', true, null],
                ['Sortie au Musée de l\'histoire de l\'immigration', true, null],
                ['Préparer les évaluations intermédiaires', false, 'benevole'],
                ['Passage du DILF / TCF', false, null],
                ['Bilan final & remise des attestations', false, null],
            ], ['benevole' => 'Référent', 'fle2' => 'Animateur', 'salarie' => 'Coordinateur', 'admin' => 'Bénévole', 'accueil' => 'Bénévole'],
            false, null,
            'Cours de français pour 24 adultes primo-arrivants, trois groupes de niveau, 6 heures par semaine. Objectif : 80 % de réussite au DILF.'],
        'delf-b1' => ['fle', 'Préparation DELF B1 — Cohorte ' . $s, 'active', 'fle2',
            'Massy — Médiathèque', 6500, 14, [10, 4], -60, 45, [
                ['Inscriptions et entretiens individuels', true, null],
                ['Achat des manuels DELF B1', true, null],
                ['Examen blanc n°1', true, null],
                ['Ateliers production orale', true, null],
                ['Examen blanc n°2', true, null],
                ['Inscription à la session officielle', true, null],
                ['Passage de l\'examen', false, null],
            ], ['fle2' => 'Référent', 'benevole' => 'Animateur', 'salarie' => 'Coordinateur'],
            false, null,
            'Préparation intensive au DELF B1, exigé pour la naturalisation et de nombreuses formations qualifiantes.'],
        'fle-pro' => ['fle', 'FLE à visée professionnelle', 'warning', 'formatrice',
            'Les Ulis — Pôle emploi formation', 9800, 12, [8, 4], -50, 60, [
                ['Diagnostic linguistique', true, null],
                ['Lexique métiers : aide à domicile', true, null],
                ['Lexique métiers : logistique', true, null],
                ['Simulations d\'entretien en français', false, null],
                ['Stage d\'observation en entreprise', false, null],
                ['Visites d\'entreprises partenaires', false, null],
                ['Évaluation finale', false, null],
                ['Bilan avec les prescripteurs', false, null],
            ], ['formatrice' => 'Référent', 'benevole' => 'Bénévole', 'insertion' => 'Coordinateur'],
            false, null,
            'Français appliqué aux métiers qui recrutent localement. Budget presque consommé : à surveiller.'],

        // ---------------- Numérique ----------------
        'pass-numerique' => ['numerique', 'Pass Numérique — 120 bénéficiaires', 'active', 'formatrice',
            'Massy — Salle informatique DEMO F', 24000, 120, [74, 46], -140, 50, [
                ['Convention avec le département', true, null],
                ['Équipement de la salle (12 postes)', true, null],
                ['Recrutement des bénéficiaires', true, null],
                ['Ateliers « premiers pas » (8 groupes)', true, null],
                ['Ateliers « démarches en ligne »', true, null],
                ['Témoigner pour la fiche de communication', false, 'membre'],
            ], ['formatrice' => 'Référent', 'numerique2' => 'Animateur', 'civique' => 'Bénévole', 'membre' => 'Bénévole', 'salarie' => 'Coordinateur', 'financeur' => 'Financeur'],
            true, null,
            '120 habitants accompagnés vers l\'autonomie numérique grâce aux chèques Pass Numérique. Cofinancé par la Fondation Avenir Solidaire.'],
        'permanences' => ['numerique', 'Permanences démarches en ligne', 'active', 'insertion',
            'Massy — Espace France Services', 7200, 310, [182, 128], -200, 90, [
                ['Planning des permanences (mardi, jeudi)', true, null],
                ['Formation des bénévoles à FranceConnect', true, null],
                ['Affiches et flyers', true, null],
                ['Partenariat avec la CAF', true, null],
                ['Permanences du 1er trimestre', true, null],
                ['Permanences du 2e trimestre', true, null],
                ['Permanences du 3e trimestre', true, null],
                ['Rapport d\'activité aux partenaires', false, null],
            ], ['insertion' => 'Référent', 'accueil' => 'Bénévole', 'numerique2' => 'Bénévole', 'membre' => 'Bénévole'],
            false, null,
            'Aide aux démarches administratives en ligne : CAF, impôts, retraite, France Travail. 310 personnes reçues depuis janvier.'],
        'smartphone' => ['numerique', 'Ateliers smartphone seniors', 'done', 'formatrice',
            'Massy — Résidence autonomie Les Tilleuls', 3400, 36, [25, 11], -160, -20, [
                ['Inscriptions via le CCAS', true, null],
                ['Prêt de 10 smartphones', true, null],
                ['Séance 1 : appeler, écrire, photographier', true, null],
                ['Séance 2 : WhatsApp et visio avec la famille', true, null],
                ['Séance 3 : sécurité et arnaques', true, null],
                ['Questionnaire de satisfaction', true, null],
            ], ['formatrice' => 'Référent', 'evenements' => 'Bénévole'],
            false, null,
            'Six séances pour 36 seniors. 97 % de satisfaction, 31 participants désormais autonomes en visio.'],

        // ---------------- Emploi ----------------
        'plie' => ['emploi', 'Accompagnement renforcé PLIE — 40 participants', 'active', 'insertion',
            'Les Ulis — Agence France Travail', 32000, 40, [22, 18], -110, 140, [
                ['Orientation des participants par le PLIE', true, null],
                ['Diagnostics individuels', true, null],
                ['Ateliers CV et lettre de motivation', true, null],
                ['Immersions en entreprise (PMSMP)', true, null],
                ['Job dating de l\'automne', false, null],
                ['Suivi en emploi à 3 mois', false, null],
                ['Bilan intermédiaire au comité de pilotage', false, null],
                ['Bilan final', false, null],
            ], ['insertion' => 'Référent', 'emploi' => 'Bénévole', 'salarie' => 'Coordinateur', 'admin' => 'Bénévole', 'financeur' => 'Financeur'],
            true, null,
            '40 demandeurs d\'emploi de longue durée accompagnés 12 mois. Déjà 14 retours à l\'emploi ou en formation qualifiante.'],
        'job-dating' => ['emploi', 'Job dating & simulations d\'entretien', 'active', 'insertion',
            'Massy — Salle polyvalente Jean-Moulin', 4500, 65, [34, 31], -40, 25, [
                ['Prospection de 15 entreprises', true, null],
                ['Ateliers simulation d\'entretien', true, null],
                ['Réservation de la salle', true, null],
                ['Communication auprès des participants', true, null],
                ['Jour J : job dating', false, null],
            ], ['insertion' => 'Référent', 'emploi' => 'Bénévole', 'evenements' => 'Bénévole', 'communication' => 'Bénévole'],
            false, null,
            '15 entreprises qui recrutent, 65 candidats préparés : le rendez-vous emploi de l\'automne.'],
        'atelier-cv' => ['emploi', 'Atelier CV & LinkedIn — printemps', 'done', 'salarie',
            'Massy — Locaux DEMO F', 2100, 28, [15, 13], -200, -110, [
                ['Programme et supports', true, null],
                ['5 ateliers collectifs', true, null],
                ['Séance photo professionnelle', true, null],
                ['Bilan et témoignages', true, null],
            ], ['salarie' => 'Référent', 'emploi' => 'Bénévole', 'communication' => 'Bénévole'],
            false, null,
            '28 participants, 28 CV refaits, 19 profils LinkedIn créés.'],

        // ---------------- Clients ----------------
        'bureautique-mairie' => ['clients', 'Bureautique — agents de la Ville de Démoville', 'active', 'salarie',
            'Démoville — Hôtel de ville', 8400, 18, [12, 6], -45, 40, [
                ['Recueil des besoins avec la DRH', true, null],
                ['Signature de la convention de formation', true, null],
                ['Tests de niveau', true, null],
                ['Module Word (2 jours)', true, null],
                ['Module Excel (3 jours)', false, null],
                ['Évaluation et attestations', false, null],
            ], ['salarie' => 'Référent', 'formatrice' => 'Animateur', 'prestataire' => 'Animateur'],
            false, null,
            'Formation de 18 agents municipaux, facturée à la Ville (convention signée, 3 factures émises).'],
        'excel-ccas' => ['clients', 'Excel & outils collaboratifs — CCAS de Démoville', 'active', 'salarie',
            'Démoville — CCAS', 5600, 10, [8, 2], -20, 50, [
                ['Devis accepté', true, null],
                ['Planification des sessions', true, null],
                ['Session 1 : tableaux et formules', false, null],
                ['Session 2 : tableaux croisés', false, null],
                ['Session 3 : partage et coédition', false, null],
                ['Bilan avec la direction', false, null],
            ], ['salarie' => 'Référent', 'numerique2' => 'Animateur'],
            false, null,
            'Montée en compétences de l\'équipe administrative du CCAS sur Excel et les outils collaboratifs.'],
        'clea' => ['clients', 'Certification CléA numérique — session entreprises', 'draft', 'salarie',
            'Massy — Salle informatique DEMO F', 6000, 0, [0, 0], 21, 90, [
                ['Signer la convention de formation', false, 'salarie'],
                ['Habilitation évaluateur CléA', false, null],
                ['Constitution du groupe (8 salariés)', false, null],
                ['Formation (35 h)', false, null],
                ['Passage de la certification', false, null],
            ], ['salarie' => 'Référent', 'prestataire' => 'Animateur'],
            false, null,
            'Nouvelle offre : certification CléA numérique pour les salariés des entreprises partenaires (OPCO).'],

        // ---------------- Jeunesse ----------------
        'devoirs' => ['jeunesse', 'Aide aux devoirs — Collège des Tilleuls', 'active', 'devoirs',
            'Massy — Collège des Tilleuls', 5200, 32, [15, 17], -35, 250, [
                ['Convention avec le collège', true, null],
                ['Recrutement de 8 bénévoles', true, null],
                ['Repérage des élèves avec les professeurs', true, null],
                ['Lancement des séances (lundi, jeudi)', true, null],
                ['Rencontre avec les parents', false, null],
                ['Bilan du 1er trimestre', false, null],
                ['Bilan de fin d\'année', false, null],
            ], ['devoirs' => 'Référent', 'civique' => 'Bénévole', 'logistique' => 'Bénévole', 'accueil' => 'Bénévole'],
            false, null,
            '32 collégiens accompagnés deux soirs par semaine par 8 bénévoles.'],
        'remobilisation' => ['jeunesse', 'Stage de remobilisation 16-25 ans', 'warning', 'salarie',
            'Massy — Locaux DEMO F', 12000, 16, [6, 10], -30, 70, [
                ['Repérage avec la Mission locale', true, null],
                ['Entretiens d\'entrée', true, null],
                ['Semaine 1 : confiance en soi', false, null],
                ['Semaine 2 : découverte des métiers', false, null],
                ['Stage en entreprise', false, null],
                ['Atelier code de la route', false, null],
                ['Projet collectif', false, null],
                ['Restitution aux familles', false, null],
                ['Bilan avec la Mission locale', false, null],
            ], ['salarie' => 'Référent', 'insertion' => 'Coordinateur', 'civique' => 'Bénévole'],
            false, null,
            '16 jeunes sans emploi ni formation remobilisés en 10 semaines. Budget sous tension : 92 % déjà engagés.'],

        // ---------------- Vie associative ----------------
        'ag' => ['vie', 'Assemblée générale & rapport d\'activité ' . $s, 'active', 'admin',
            'Massy — Salle des fêtes', 2500, 0, [0, 0], -25, 20, [
                ['Fixer la date et réserver la salle', true, null],
                ['Collecter les chiffres de chaque pôle', true, null],
                ['Rédiger le rapport d\'activité', true, null],
                ['Faire valider le rapport moral en CA', false, 'admin'],
                ['Envoyer les convocations', false, null],
                ['Préparer le rapport financier', false, null],
                ['Tenue de l\'AG et procès-verbal', false, null],
            ], ['admin' => 'Référent', 'secretaire' => 'Coordinateur', 'tresoriere' => 'Bénévole', 'communication' => 'Bénévole'],
            false, null,
            'Préparation de l\'assemblée générale annuelle : rapport moral, rapport d\'activité, comptes et élection du bureau.'],
        'qualiopi' => ['vie', 'Renouvellement de la certification Qualiopi', 'active', 'salarie',
            'Massy — Locaux DEMO F', 6800, 0, [0, 0], -120, 35, [
                ['Autodiagnostic des 32 indicateurs', true, null],
                ['Mise à jour du livret d\'accueil', true, null],
                ['Questionnaires de satisfaction à chaud et à froid', true, null],
                ['Procédure de réclamation', true, null],
                ['Veille réglementaire documentée', true, null],
                ['Audit blanc', true, null],
                ['Envoyer les preuves de l\'indicateur 22 à l\'auditeur', false, 'salarie'],
                ['Audit de renouvellement', false, null],
            ], ['salarie' => 'Référent', 'admin' => 'Bénévole', 'formatrice' => 'Animateur', 'communication' => 'Bénévole'],
            false, null,
            'La certification Qualiopi conditionne les financements publics de la formation. Audit de renouvellement dans 5 semaines.'],
        'forum-assos' => ['vie', 'Forum des associations ' . ($s - 1), 'done', 'evenements',
            'Massy — Parc Georges-Brassens', 800, 210, [120, 90], -400, -390, [
                ['Inscription au forum', true, null],
                ['Stand et kakémono', true, null],
                ['Planning des bénévoles', true, null],
            ], ['evenements' => 'Référent', 'admin' => 'Bénévole'],
            false, [9, false],
            '210 visiteurs, 34 nouvelles adhésions sur la journée.'],

        // ---------------- Archivés avec leur dossier ----------------
        'ete-numerique' => ['ete', 'Atelier vacances numériques juillet', 'done', 'formatrice',
            'Massy — Locaux DEMO F', 1200, 18, [9, 9], -460, -430, [
                ['Programme', true, null], ['Ateliers', true, null], ['Bilan', true, null],
            ], ['formatrice' => 'Référent', 'civique' => 'Bénévole'],
            false, [6, true], 'Ateliers numériques d\'été pour les 11-15 ans.'],
        'ete-sorties' => ['ete', 'Sorties culturelles FLE août', 'done', 'fle2',
            'Paris', 900, 22, [14, 8], -430, -400, [
                ['Choix des sorties', true, null], ['Sorties', true, null], ['Bilan', true, null],
            ], ['fle2' => 'Référent', 'benevole' => 'Bénévole'],
            false, [6, true], 'Trois sorties culturelles pour pratiquer le français hors de la classe.'],
    ];
}
