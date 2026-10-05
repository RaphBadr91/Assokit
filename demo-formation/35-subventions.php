<?php
/**
 * 35 — Subventions : candidatures à tous les stades, et radar de financements.
 * ------------------------------------------------------------------
 * Treize dossiers couvrent tout le cycle : brouillons avec échéance
 * proche, dossiers déposés, en instruction, accordés (bilan à rendre),
 * bilans rendus, un refus. Financeurs publics réels (comme dans la vraie
 * vie d'une association), financeurs privés fictifs.
 *
 * Deux pièges à éviter :
 *   - les rappels au jour près (J-7, J-1, J0, J+30…) : cron-grant-reminders
 *     écrirait aux admins. Chaque dossier est marqué « déjà rappelé » pour
 *     les sept types de rappel, et les échéances évitent ces écarts exacts ;
 *   - le catalogue national du radar est partagé avec les vraies
 *     associations : on n'y touche pas. DEMO F a ses propres dispositifs
 *     (org_id = DEMO F), purgés chaque nuit avec elle.
 * ------------------------------------------------------------------
 */

/**
 * 08-compte-apple-review.sql supprime chaque nuit les subventions 9001 à 9010 :
 * les nôtres doivent naître au-dessus.
 */
function df_pre_subventions(): void
{
    if (!df_a_table('grants')) return;
    $ai = (int)df_val("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grants'");
    if ($ai > 0 && $ai < 9100) DF::$pdo->exec("ALTER TABLE grants AUTO_INCREMENT = 9100");
}

function df_seed_subventions(): void
{
    if (!df_a_table('grants')) return;
    $u = DF::$u;
    $an = df_annee();
    $p = fn(?string $k) => $k ? df_id('projets', $k) : null;
    $auteurs = [$u['admin'], $u['tresoriere'] ?? $u['admin'], $u['salarie']];
    $etapes = [
        'draft'     => ['Lire le cahier des charges', 'Rédiger le projet', 'Construire le budget prévisionnel', 'Rassembler les pièces (statuts, RIB, comptes)', 'Faire relire par le bureau', 'Déposer en ligne'],
        'submitted' => ['Lire le cahier des charges', 'Rédiger le projet', 'Construire le budget prévisionnel', 'Rassembler les pièces (statuts, RIB, comptes)', 'Déposer en ligne', 'Répondre aux demandes de l\'instructeur'],
        'granted'   => ['Rédiger le projet', 'Déposer en ligne', 'Signer la convention', 'Recevoir le 1er versement', 'Lancer l\'action', 'Suivre les indicateurs', 'Rédiger le compte-rendu financier CERFA 15059*02'],
        'reported'  => ['Déposer en ligne', 'Signer la convention', 'Recevoir le versement', 'Réaliser l\'action', 'Rendre le bilan'],
        'rejected'  => ['Rédiger le projet', 'Déposer en ligne', 'Relance du financeur'],
    ];
    $dossiers = [
        // [nom, financeur, type, statut, demandé, accordé, dépôt, soumis, décision, bilan, rendu, cerfa, réf, plateforme, url, projet, étapes faites, notes]
        ['FDVA 1 — Formation des bénévoles formateurs ' . $an, 'DRAJES Île-de-France — FDVA', 'etat', 'granted', 8000, 6500, -210, -214, -130, 6, null,
         '12156*06', "FDVA1-$an-91-0213", 'lecompteasso', 'https://lecompteasso.associations.gouv.fr', 'fle-automne', 6, 'Bilan à rendre sous huit jours : le compte-rendu financier est presque prêt.'],
        ['Programme régional Compétences numériques pour l\'emploi', 'Région Île-de-France', 'region', 'granted', 48000, 42000, -165, -170, -95, 25, null,
         null, "IDF-CN-$an-00457", 'region', 'https://mesdemarches.iledefrance.fr', 'pass-numerique', 5, null],
        ['Contrat de ville ' . $an . ' — Ateliers sociolinguistiques (ASL)', 'Préfecture de l\'Essonne — Politique de la ville', 'etat', 'granted', 18000, 15000, -240, -245, -180, 150, null,
         '12156*06', null, 'dauphin', 'https://usager-dauphin.cget.gouv.fr', 'fle-pro', 5, null],
        ['Parcours emploi des jeunes décrocheurs', 'Fondation Avenir Solidaire', 'fondation', 'granted', 25000, 20000, -120, -128, -70, 260, null,
         null, 'FAS-' . $an . '-031', 'email', null, 'plie', 4, 'Interlocutrice : Claire Vasseur, qui suit le projet en direct dans Assokit.'],
        ['Fonds d\'initiatives pour l\'insertion ' . $an . '-' . ($an + 1), 'Conseil départemental de l\'Essonne', 'departement', 'in_review', 22000, null, null, -26, null, null, null,
         null, null, 'departement', null, 'job-dating', 6, 'Commission permanente prévue le mois prochain.'],
        ['FSE+ Inclusion active — accompagnement renforcé des 16-25 ans', 'Union européenne — FSE+ (Région Île-de-France)', 'europe', 'in_review', 85000, null, null, -48, null, null, null,
         null, "FSE-IDF-$an-0872", 'autre', 'https://ma-demarche-fse-plus.fr', 'remobilisation', 6, 'Pièces complémentaires transmises il y a dix jours.'],
        ['Inclusion numérique des seniors', 'Fondation Numérique Pour Tous', 'fondation', 'submitted', 15000, null, 19, -3, null, null, null,
         null, null, 'email', null, 'permanences', 5, null],
        ['Subvention de fonctionnement ' . ($an + 1), 'Ville de Massy', 'commune', 'submitted', 12000, null, 12, -2, null, null, null,
         '12156*06', null, 'commune', null, null, 5, null],
        ['CLAS ' . $an . '-' . ($an + 1) . ' — Accompagnement à la scolarité', 'CAF de l\'Essonne', 'caf', 'draft', 9500, null, 5, null, null, null, null,
         null, null, 'elan', 'https://elan.caf.fr', 'devoirs', 2, null],
        ['Prépa-apprentissage — cohorte ' . ($an + 1), 'Fonds de dotation Horizon Compétences', 'fondation', 'draft', 30000, null, 26, null, null, null, null,
         null, null, 'email', null, null, 1, null],
        ['Contrat de ville ' . ($an - 1) . ' — Permanences emploi', 'Préfecture de l\'Essonne', 'etat', 'reported', 14000, 14000, -560, -565, -500, -40, -55,
         '12156*06', null, 'dauphin', null, 'permanences', 5, null],
        ['FDVA 2 — Fonctionnement ' . ($an - 1), 'DRAJES Île-de-France — FDVA', 'etat', 'reported', 10000, 7000, -420, -425, -360, -100, -120,
         '12156*06', null, 'lecompteasso', null, null, 5, null],
        ['Mécénat — équipement de la salle informatique', 'Groupe Verdier Logistique', 'entreprise', 'rejected', 12000, null, null, -80, -35, null, null,
         null, null, 'email', null, 'pass-numerique', 3, 'Refus : enveloppe mécénat déjà engagée. Recontacter en janvier avec le bilan d\'impact.'],
    ];
    $contacts = ['Mme Durand', 'M. Leroy', 'Mme Benoît', 'M. Caron', 'Mme Fabre', 'M. Garnier'];
    $libStatut = ['submitted' => 'Déposé', 'in_review' => 'Instruction', 'granted' => 'Accordé', 'reported' => 'Bilan rendu', 'rejected' => 'Refusé'];

    foreach ($dossiers as $n => [$nom, $fin, $type, $statut, $dem, $acc, $dep, $soum, $dec, $bilan, $rendu, $cerfa, $ref, $plat, $url, $projet, $faites, $notes]) {
        $creation = min(-5, ($soum ?? ($dep ?? 0) - 40) - df_entre(20, 45));
        $auteur = $auteurs[$n % 3];
        $contact = $contacts[$n % count($contacts)];
        $gid = df_ins('grants', [
            'org_id' => DF::$org, 'project_id' => $p($projet), 'name' => $nom, 'funder' => $fin, 'funder_type' => $type,
            'description' => 'Dossier porté par l\'association DEMO F.', 'amount_requested' => $dem, 'amount_granted' => $acc,
            'currency' => 'EUR', 'status' => $statut,
            'deadline_apply' => $dep !== null ? df_j($dep) : null, 'submitted_at' => $soum !== null ? df_j($soum) : null,
            'decision_at' => $dec !== null ? df_j($dec) : null, 'deadline_report' => $bilan !== null ? df_j($bilan) : null,
            'reported_at' => $rendu !== null ? df_j($rendu) : null, 'cerfa_number' => $cerfa, 'reference' => $ref,
            'platform' => $plat, 'platform_url' => $url, 'contact_name' => $contact,
            'contact_email' => 'instruction.' . ($n + 1) . '@example.org', 'contact_phone' => df_tel(3000 + $n * 11, false),
            'notes' => $notes, 'last_relance_at' => $statut === 'in_review' && $n === 4 ? df_jh(-4, '10:00') : null,
            'last_relance_by' => $statut === 'in_review' && $n === 4 ? $u['admin'] : null,
            'created_by' => $auteur, 'created_at' => df_jh($creation, '10:00'), 'archived_at' => null,
        ]);
        if (!$gid) continue;
        df_retenir('subventions', (string)$n, $gid);

        // Étapes
        $liste = $etapes[$statut === 'in_review' ? 'submitted' : $statut];
        $fin_etapes = $soum ?? -1;
        foreach ($liste as $pos => $titre) {
            $fait = $pos < $faites;
            $quand = $fait ? df_jh(min($fin_etapes, $creation + 3 + $pos * 4), '17:00') : null;
            df_ins('grant_steps', ['grant_id' => $gid, 'position' => $pos, 'title' => $titre, 'is_completed' => $fait ? 1 : 0,
                                   'completed_at' => $quand, 'completed_by' => $fait ? $auteur : null]);
            if ($fait) df_ins('grant_activity_log', ['grant_id' => $gid, 'user_id' => $auteur, 'action_type' => 'step_toggle',
                                                     'action_label' => '✅ Étape cochée : ' . $titre, 'created_at' => $quand]);
        }
        // Historique
        $log = fn(?int $uid, string $type, string $lib, int $j, string $h = '11:00') =>
            df_ins('grant_activity_log', ['grant_id' => $gid, 'user_id' => $uid, 'action_type' => $type, 'action_label' => $lib, 'created_at' => df_jh($j, $h)]);
        $log($auteur, 'create', 'Dossier créé', $creation, '10:00');
        if ($soum !== null) $log($auteur, 'status_change', '🔄 Statut → Déposé', $soum);
        if (in_array($statut, ['in_review', 'granted', 'reported', 'rejected'], true) && $soum !== null) $log($auteur, 'status_change', '🔄 Statut → Instruction', $soum + 3);
        if (in_array($statut, ['granted', 'reported', 'rejected'], true) && $dec !== null) $log($auteur, 'status_change', '🔄 Statut → ' . ($statut === 'rejected' ? 'Refusé' : 'Accordé'), $dec);
        if ($statut === 'reported' && $rendu !== null) $log($auteur, 'status_change', '🔄 Statut → Bilan rendu', $rendu);
        if ($n === 0) $log(null, 'reminder_email', '📨 Rappel envoyé : [AssoKit] BILAN J-30 · ' . $nom, -24, '07:00');
        if ($n === 1) $log(null, 'reminder_email', '📨 Rappel envoyé : [AssoKit] BILAN J-30 · ' . $nom, -5, '07:00');
        if ($n === 8) $log(null, 'reminder_email', '📨 Rappel envoyé : [AssoKit] J-7 · ' . $nom, -2, '07:00');
        if ($n === 4) $log($u['admin'], 'relance_sent', '📧 Relance envoyée au financeur', -4, '10:00');

        // Garde-fou : tous les rappels sont réputés envoyés.
        if (df_a_table('grant_reminders_sent')) {
            foreach (['apply_7d', 'apply_1d', 'apply_0d', 'apply_overdue', 'report_30d', 'report_7d', 'report_overdue'] as $r) {
                df_ins('grant_reminders_sent', ['grant_id' => $gid, 'reminder_type' => $r, 'sent_at' => df_jh(-df_entre(2, 30), '07:00')]);
            }
        }
        if ($projet && ($pid = $p($projet)) && df_a_table('project_grants')) {
            df_ins('project_grants', ['project_id' => $pid, 'grant_id' => $gid, 'amount_allocated' => $acc ?? $dem, 'notes' => null,
                                      'created_at' => df_jh($creation, '10:30')]);
        }
    }

    df_seed_radar();
}

/** Radar de financements : profil, dispositifs propres à DEMO F, correspondances calculées par le moteur. */
function df_seed_radar(): void
{
    if (!df_a_table('org_grant_profile')) return;
    df_ins('org_grant_profile', [
        'org_id' => DF::$org, 'sectors' => 'emploi,education,social,jeunesse,numerique', 'is_qpv' => 1, 'is_interet_general' => 1,
        'region_code' => '11', 'dept_code' => '91', 'is_zrr' => 0, 'members_count' => 150, 'annual_budget' => 620000,
        'updated_at' => df_jh(-20, '10:00'),
    ]);
    df_ins('grant_alert_prefs', ['org_id' => DF::$org, 'notify_new_match' => 1, 'min_match_score' => 60, 'notify_deadlines' => 1,
                                 'channel_email' => 0, 'channel_app' => 1]);

    $globaux = df_a_table('grant_catalog') ? (int)df_val("SELECT COUNT(*) FROM grant_catalog WHERE status = 'active' AND org_id IS NULL") : 0;
    if ($globaux === 0) {
        DF::$rapport['notes'][] = 'Radar de subventions : le catalogue national est vide sur ce serveur. Lancez api/seed-grants-catalog.php, puis rejouez la démo.';
    }
    $propres = [
        ['df-region-numerique', 'AAP « Compétences numériques & inclusion »', 'Région Île-de-France', 'region', 'region', '11', null, 'emploi,numerique', 10000, 60000, 0, 0, 'annuel', 9],
        ['df-cd91-insertion', 'Fonds d\'initiatives pour l\'insertion', 'Conseil départemental de l\'Essonne', 'departement', 'departement', '11', '91', 'emploi,social', 5000, 25000, 0, 0, 'annuel', 23],
        ['df-fondation-numerique', 'Appel à projets « Le numérique pour tous »', 'Fondation Numérique Pour Tous', 'fondation', 'national', null, null, 'numerique,education', 5000, 30000, 0, 1, 'annuel', 38],
        ['df-fondation-sante', 'Prévention et santé dans les quartiers', 'Fondation Santé & Territoires', 'fondation', 'national', null, null, 'sante', 3000, 15000, 0, 1, 'annuel', 52],
        ['df-fonds-culturel', 'Fonds culturel de quartier', 'Fonds culturel de quartier', 'fondation', 'national', null, null, 'culture', 2000, 10000, 1, 0, 'ponctuel', 17],
        ['df-opco', 'Développement des compétences des salariés', 'OPCO Cohésion & Territoires', 'autre', 'national', null, null, 'emploi,education', 2000, 20000, 0, 0, 'permanent', null],
    ];
    $ids = [];
    if (df_a_table('grant_catalog')) {
        foreach ($propres as [$ref, $titre, $fin, $type, $portee, $reg, $dep, $secteurs, $min, $max, $qpv, $ig, $rec, $j]) {
            $ids[$ref] = df_ins('grant_catalog', [
                'title' => $titre, 'funder_name' => $fin, 'funder_type' => $type, 'program_code' => null,
                'summary' => 'Dispositif de démonstration, propre à l\'association DEMO F.', 'geo_scope' => $portee,
                'region_code' => $reg, 'dept_code' => $dep, 'sectors' => $secteurs, 'beneficiary' => 'association',
                'amount_min' => $min, 'amount_max' => $max, 'req_qpv' => $qpv, 'req_interet_general' => $ig, 'recurrence' => $rec,
                'opens_at' => df_j(-30), 'deadline_apply' => $j !== null ? df_j($j) : null, 'next_expected' => null,
                'apply_url' => 'https://example.org/appel-a-projets', 'source' => 'demo_formation', 'source_ref' => $ref,
                'source_url' => null, 'verified_at' => df_jh(-3, '10:00'), 'is_verified' => 1, 'status' => 'active',
                'org_id' => DF::$org, 'created_at' => df_jh(-30, '10:00'), 'updated_at' => df_jh(-3, '10:00'),
            ]);
        }
    }

    // Les correspondances : calculées par le moteur de l'application, jamais écrites à la main.
    $moteur = dirname(__DIR__) . '/financements-engine.php';
    if (is_file($moteur) && df_a_table('grant_matches')) {
        require_once $moteur;
        try {
            if (function_exists('fin_build_profile')) {
                $profil = fin_build_profile(DF::$pdo, DF::$org);
                if (($profil['org_type'] ?? '') !== 'association') df_erreur('Radar : DEMO F est vue comme « ' . ($profil['org_type'] ?? '?') . ' » au lieu d\'« association ».');
            }
            $n = function_exists('fin_compute_matches') ? fin_compute_matches(DF::$pdo, DF::$org) : 0;
            DF::$rapport['notes'][] = "Radar de subventions : $n correspondance(s) calculée(s).";
        } catch (Throwable $e) {
            df_erreur('Radar : ' . $e->getMessage());
        }
        // Pistes suivies et pistes écartées.
        foreach (['df-region-numerique', 'df-fondation-numerique'] as $ref) {
            if (!empty($ids[$ref])) df_maj('grant_matches', ['saved' => 1], 'org_id = ? AND catalog_id = ?', [DF::$org, $ids[$ref]]);
        }
        foreach (['etat-qpv' => 'saved', 'fdva-2' => 'saved', 'etat-ademe' => 'dismissed'] as $ref => $col) {
            df_sql("UPDATE grant_matches m JOIN grant_catalog c ON c.id = m.catalog_id SET m.$col = 1
                     WHERE m.org_id = ? AND c.org_id IS NULL AND c.source_ref = ?", [DF::$org, $ref]);
        }
        // Alertes déjà envoyées : sinon le radar écrirait chaque matin après le reset.
        if (df_a_table('grant_alert_sent')) {
            $q = DF::$pdo->prepare("SELECT catalog_id FROM grant_matches WHERE org_id = ?");
            $q->execute([DF::$org]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                foreach (['new_match', 'deadline_30', 'deadline_7'] as $type) {
                    df_ins('grant_alert_sent', ['org_id' => DF::$org, 'catalog_id' => (int)$cid, 'alert_type' => $type,
                                                'sent_at' => df_jh(-df_entre(2, 9), '07:30')]);
                }
            }
        }
    }
}
