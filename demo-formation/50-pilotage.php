<?php
/**
 * 50 — Pilotage : notifications, suggestions « Aujourd'hui », rapports
 * hebdomadaires du Coach Assokit, espace public.
 * ------------------------------------------------------------------
 * Joué en dernier : les textes citent des chiffres RELUS dans la base
 * (factures en retard, adhésions à renouveler, prochaine échéance de
 * subvention…), ils sont donc toujours justes, quel que soit le jour.
 *
 * Deux effets de bord évités ici :
 *   - « Aujourd'hui » : sans ligne en cache pour le jour, le tableau de
 *     bord appelle l'IA à chaque affichage ;
 *   - Coach : sans rapport « envoyé » pour la semaine écoulée, le cron
 *     appelle l'IA puis écrit aux administrateurs.
 * ------------------------------------------------------------------
 */

function df_chiffres(): array
{
    $o = DF::$org;
    $v = fn(string $sql, array $p = []) => df_a_table(explode(' ', trim(substr($sql, stripos($sql, ' FROM ') + 6)))[0]) ? df_val($sql, $p) : null;
    return [
        'adherents'   => (int)$v("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL", [$o]),
        'nouveaux7'   => (int)$v("SELECT COUNT(*) FROM users WHERE org_id = ? AND deleted_at IS NULL AND created_at >= NOW() - INTERVAL 7 DAY", [$o]),
        'nouveaux30'  => (int)$v("SELECT COUNT(*) FROM users WHERE org_id = ? AND deleted_at IS NULL AND created_at >= NOW() - INTERVAL 30 DAY", [$o]),
        'retard_nb'   => (int)$v("SELECT COUNT(*) FROM asso_invoices WHERE org_id = ? AND status = 'overdue'", [$o]),
        'retard_eur'  => (int)round(((int)$v("SELECT COALESCE(SUM(amount_ttc_cents),0) FROM asso_invoices WHERE org_id = ? AND status = 'overdue'", [$o])) / 100),
        'retard_client' => (string)$v("SELECT c.display_name FROM asso_invoices i JOIN asso_clients c ON c.id = i.client_id WHERE i.org_id = ? AND i.status = 'overdue' ORDER BY i.due_at LIMIT 1", [$o]),
        'devis_envoyes' => (int)$v("SELECT COUNT(*) FROM asso_quotes WHERE org_id = ? AND status = 'sent'", [$o]),
        'a_renouveler'  => (int)$v("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL AND adhesion_valid_until < CURDATE() + INTERVAL 30 DAY", [$o]),
        'expires'     => (int)$v("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL AND adhesion_valid_until < CURDATE()", [$o]),
        'subv_nom'    => (string)$v("SELECT name FROM grants WHERE org_id = ? AND status IN ('draft','submitted','in_review') AND deadline_apply >= CURDATE() ORDER BY deadline_apply LIMIT 1", [$o]),
        'subv_j'      => (int)$v("SELECT DATEDIFF(MIN(deadline_apply), CURDATE()) FROM grants WHERE org_id = ? AND status IN ('draft','submitted','in_review') AND deadline_apply >= CURDATE()", [$o]),
        'subv_id'     => (int)$v("SELECT id FROM grants WHERE org_id = ? AND status IN ('draft','submitted','in_review') AND deadline_apply >= CURDATE() ORDER BY deadline_apply LIMIT 1", [$o]),
        'bilan_nom'   => (string)$v("SELECT name FROM grants WHERE org_id = ? AND status = 'granted' AND reported_at IS NULL AND deadline_report >= CURDATE() ORDER BY deadline_report LIMIT 1", [$o]),
        'bilan_j'     => (int)$v("SELECT DATEDIFF(MIN(deadline_report), CURDATE()) FROM grants WHERE org_id = ? AND status = 'granted' AND reported_at IS NULL AND deadline_report >= CURDATE()", [$o]),
        'event_titre' => (string)$v("SELECT title FROM events WHERE org_id = ? AND deleted_at IS NULL AND starts_at >= NOW() AND event_type = 'public' ORDER BY starts_at LIMIT 1", [$o]),
        'event_date'  => (string)$v("SELECT starts_at FROM events WHERE org_id = ? AND deleted_at IS NULL AND starts_at >= NOW() AND event_type = 'public' ORDER BY starts_at LIMIT 1", [$o]),
        'cotis_semaine' => (int)$v("SELECT COUNT(*) FROM cotisation_payments WHERE org_id = ? AND status = 'paid' AND paid_at >= NOW() - INTERVAL 7 DAY", [$o]),
        'emargements' => (int)$v("SELECT COUNT(*) FROM attendance_sessions WHERE org_id = ? AND is_open = 1", [$o]),
        'notes_frais' => (int)$v("SELECT COUNT(*) FROM expense_reports WHERE org_id = ? AND status = 'submitted'", [$o]),
    ];
}

function df_seed_pilotage(): void
{
    $u = DF::$u;
    $c = df_chiffres();
    $eur = fn(int $n) => number_format($n, 0, ',', ' ') . ' €';
    $date = fn(string $d) => $d ? date('d/m', strtotime($d)) : '';

    // ---------------- Suggestions « Aujourd'hui » ----------------
    $s = fn(string $icone, string $titre, string $desc, string $lien, string $libelle, string $prio) =>
        ['icon' => $icone, 'title' => $titre, 'description' => $desc, 'link' => $lien, 'link_label' => $libelle, 'priority' => $prio];
    $pf = df_id('projets', 'fle-automne');
    $suggestions = [
        'admin' => ['admin', [
            $s('⚠️', $c['retard_nb'] . ' factures clients à relancer', 'Dont ' . ($c['retard_client'] ?: 'un client') . '. Total en retard : ' . $eur($c['retard_eur']) . '.', '/relances', 'Relancer', 'urgent'),
            $s('📑', 'Bilan à rendre dans ' . $c['bilan_j'] . ' jours', $c['bilan_nom'] ?: 'Compte-rendu financier de subvention.', '/subventions', 'Voir le dossier', 'important'),
            $s('⏳', $c['a_renouveler'] . ' adhésions à renouveler', 'Dont ' . $c['expires'] . ' déjà expirées. Une relance groupée prend deux minutes.', '/relances', 'Voir la liste', 'important'),
            $s('👋', $c['nouveaux7'] . ' nouveaux adhérents cette semaine', $c['nouveaux30'] . ' sur les 30 derniers jours : la rentrée bat son plein.', '/adherents', 'Les accueillir', 'info'),
            $s('🧾', $c['notes_frais'] . ' notes de frais à valider', 'Déposées par l\'équipe cette semaine.', '/notes-de-frais', 'Valider', 'info'),
        ]],
        'salarie' => ['coordinator', [
            $s('✍️', $c['emargements'] . ' émargements ouverts ce matin', 'Les apprenants signent sur la tablette de l\'accueil.', '/emargement', 'Suivre', 'urgent'),
            $s('📞', 'Rappels de prospection du jour', 'Trois rappels prévus aujourd\'hui, dont un déjà en retard.', '/prospection', 'Appeler', 'important'),
            $s('🎯', 'Qualiopi : preuves de l\'indicateur 22', 'L\'audit de renouvellement approche, une étape vous est assignée.', '/projets', 'Voir l\'étape', 'important'),
            $s('📅', ($c['event_titre'] ?: 'Prochain événement') . ' le ' . $date($c['event_date']), 'Vérifiez les inscriptions et le planning des bénévoles.', '/agenda', 'Ouvrir l\'agenda', 'info'),
        ]],
        'benevole' => ['referent', [
            $s('📝', 'Préparer les évaluations intermédiaires', 'Étape qui vous est assignée sur le parcours FLE.', $pf ? "/projet/$pf" : '/projets', 'Ouvrir le projet', 'important'),
            $s('💬', '4 messages non lus sur le parcours FLE', 'Karim et Hélène ont avancé sur le chiffrage du 4e groupe.', $pf ? "/projet/$pf/messages" : '/messages', 'Lire', 'important'),
            $s('☕', 'Café des langues ce soir à 17 h 30', '22 inscrits : c\'est complet !', '/agenda', 'Voir', 'info'),
            $s('🤝', 'Forum emploi : un créneau d\'accueil vous attend', 'Samedi matin, avec Camille.', '/messages?c=benevoles', 'Confirmer', 'info'),
        ]],
        'membre' => ['member', [
            $s('✍️', 'Signez votre présence à l\'atelier', 'L\'émargement de l\'atelier numérique est ouvert.', '/emargement', 'Signer', 'important'),
            $s('🎤', 'Élodie vous propose de témoigner', 'Pour la fiche de communication du Pass Numérique.', '/messages?c=parcours-numerique', 'Répondre', 'important'),
            $s('📅', 'Forum de l\'emploi dans 5 jours', 'Vous êtes inscrit : pensez à imprimer votre CV.', '/agenda', 'Voir', 'info'),
            $s('🗳️', 'Assemblée générale dans 3 semaines', 'Votre convocation est arrivée, vous pouvez voter en ligne le jour J.', '/agenda', 'En savoir plus', 'info'),
        ]],
    ];
    foreach (['secretaire' => 'coordinator', 'tresoriere' => 'coordinator'] as $k => $profil) {
        if (!empty($u[$k])) $suggestions[$k] = [$profil, $suggestions['salarie'][1]];
    }
    foreach ($suggestions as $k => [$profil, $liste]) {
        if (empty($u[$k])) continue;
        df_ins('today_suggestions', ['user_id' => $u[$k], 'org_id' => DF::$org, 'profile' => $profil, 'generation_date' => df_j(0),
                                     'suggestions_json' => $liste, 'has_error' => 0, 'error_message' => null, 'refresh_count' => 0,
                                     'tokens_input' => 2200, 'tokens_output' => 550, 'last_generated_at' => df_jh(0, '05:30')]);
    }

    // ---------------- Coach Assokit : 10 semaines de rapports ----------------
    if (df_a_table('coach_reports')) {
        $lundi = strtotime('monday last week', DF::$t0);
        $histoire = [
            'Rentrée réussie : les trois groupes FLE sont complets et la liste d\'attente s\'allonge.',
            'Le FDVA est accordé : 6 500 € pour former les bénévoles formateurs.',
            'Convention signée avec la Ville de Démoville pour former 18 agents à la bureautique.',
            'Relance groupée des adhésions : 23 renouvellements en une semaine.',
            'Préparation du forum emploi : 15 entreprises confirmées.',
            'Une formation Excel vendue à une clinique : nouveau client entreprise.',
            'Alerte de trésorerie sur une facture impayée, réglée la semaine suivante.',
            'Audit blanc Qualiopi : 30 indicateurs sur 32 conformes.',
            'Pass Numérique : 100 bénéficiaires accompagnés, objectif presque atteint.',
        ];
        for ($k = 0; $k < 10; $k++) {
            $debut = $lundi - $k * 7 * 86400;
            $fin = $debut + 6 * 86400;
            $gen = $fin + 86400 + 7 * 3600 + df_entre(0, 50) * 60;
            if ($k === 0) {
                $resume = "Semaine dense pour DEMO F : {$c['adherents']} adhérents actifs, dont {$c['nouveaux7']} arrivés ces sept derniers jours. "
                        . "{$c['retard_nb']} factures clients sont en retard pour " . $eur($c['retard_eur']) . ($c['retard_client'] ? ", la plus ancienne chez {$c['retard_client']}" : '') . '. '
                        . ($c['subv_nom'] ? "Le dossier « {$c['subv_nom']} » est à déposer dans {$c['subv_j']} jours. " : '')
                        . "{$c['cotis_semaine']} cotisations encaissées cette semaine et {$c['devis_envoyes']} devis en attente de réponse.";
                $hl = ["{$c['nouveaux30']} nouveaux adhérents en 30 jours", 'Le PLIE atteint 14 sorties positives sur 40 à mi-parcours', 'Le module Word de la Ville de Démoville noté 4,7/5'];
                $wa = ["{$c['retard_nb']} factures en retard (" . $eur($c['retard_eur']) . ')', 'Stage de remobilisation : budget consommé à 94 %'];
                $re = [['icon' => '📞', 'title' => 'Relancer les factures en retard', 'why' => 'Trois relances suffisent souvent à récupérer la moitié du montant.'],
                       ['icon' => '📅', 'title' => 'Boucler le dossier de subvention', 'why' => $c['subv_nom'] ? "L'échéance de « {$c['subv_nom']} » tombe dans {$c['subv_j']} jours." : 'Une échéance approche.'],
                       ['icon' => '🤝', 'title' => 'Accueillir les nouveaux adhérents', 'why' => 'Un message de bienvenue dans la semaine double le taux de réinscription.']];
            } else {
                $resume = $histoire[($k - 1) % count($histoire)] . ' L\'équipe a validé ' . df_entre(4, 11) . ' étapes de projet et l\'activité reste soutenue sur les parcours FLE et numérique. '
                        . 'Les encaissements de la semaine couvrent les dépenses courantes.';
                $hl = [$histoire[($k - 1) % count($histoire)], df_entre(8, 25) . ' messages échangés par l\'équipe'];
                $wa = [df_choix(['Deux bénévoles à remplacer sur l\'aide aux devoirs', 'Une facture approche de son échéance', 'Le budget du FLE pro se tend'])];
                $re = [['icon' => '💡', 'title' => 'Partager la bonne nouvelle', 'why' => 'Les financeurs aiment être tenus au courant des réussites.'],
                       ['icon' => '📊', 'title' => 'Mettre à jour les indicateurs', 'why' => 'Le prochain bilan sera plus rapide à rédiger.'],
                       ['icon' => '✅', 'title' => 'Clore les étapes terminées', 'why' => 'Le tableau de bord reflète alors la réalité.']];
            }
            $obj = ['summary' => $resume, 'highlights' => $hl, 'warnings' => $wa, 'recos' => $re];
            $manuel = ($k === 2);
            df_ins('coach_reports', [
                'org_id' => DF::$org, 'week_start' => date('Y-m-d', $debut), 'week_end' => date('Y-m-d', $fin),
                'summary_md' => $resume, 'highlights_json' => $hl, 'warnings_json' => $wa, 'recos_json' => $re, 'raw_response' => $obj,
                // Rapport « envoyé » : le cron ne rappellera ni l'IA ni la messagerie pour ces semaines.
                'generated_at' => date('Y-m-d H:i:s', $manuel ? $gen + 2 * 86400 + 11 * 3600 : $gen),
                'sent_email_at' => date('Y-m-d H:i:s', $gen + df_entre(30, 60)),
                'generated_by' => $manuel ? $u['admin'] : null,
            ]);
        }
    }

    // ---------------- Espace public (widget WordPress) ----------------
    df_ins('org_espace_tokens', ['org_id' => DF::$org, 'token' => hash('sha256', 'demo-formation|espace-public'), 'label' => 'WordPress',
                                 'revoked_at' => null, 'view_count' => 1342, 'last_viewed_at' => df_jh(-1, '21:14'), 'created_at' => df_jh(-140, '10:00')]);

    df_seed_notifications();
}

/** 12 à 18 notifications par compte, liées à de vrais messages, étapes et projets. */
function df_seed_notifications(): void
{
    if (!df_a_table('user_notifications')) return;
    $u = DF::$u;
    $noms = [];
    $nom = function (int $id) use (&$noms): string {
        if (!isset($noms[$id])) $noms[$id] = (string)df_val("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?", [$id]);
        return $noms[$id];
    };
    $canaux = df_cat_canaux();
    $proj = df_cat_projets();
    $dossiers = df_cat_dossiers();
    $notif = function (int $pour, string $type, string $titre, ?string $corps, string $lien, string $quand, bool $lu, array $liens = []) {
        df_ins('user_notifications', $liens + [
            'user_id' => $pour, 'notification_type' => $type, 'title' => mb_substr($titre, 0, 200), 'body' => $corps !== null ? mb_substr($corps, 0, 500) : null,
            'link_url' => $lien, 'is_read' => $lu ? 1 : 0, 'read_at' => $lu ? date('Y-m-d H:i:s', min(time() - 60, strtotime($quand) + 3600 * df_entre(1, 6))) : null,
            'created_at' => $quand,
        ]);
    };
    $prenomCle = ['admin' => 'sophie', 'salarie' => 'karim', 'benevole' => 'julie', 'membre' => 'thomas'];

    foreach (['admin', 'salarie', 'benevole', 'membre', 'financeur'] as $k) {
        $moi = $u[$k];
        $items = [];
        // Messages de canal récents écrits par d'autres, dans des canaux visibles par ce compte.
        foreach (DF::$ids['messages_canaux'] ?? [] as $slug => $liste) {
            $membres = $canaux[$slug][5] ?? [];
            if ($membres && !isset($membres[$k])) continue;
            if ($k === 'financeur' && $slug !== 'annonces') continue;
            foreach ($liste as [$mid, $auteur, $quand, $texte]) {
                if ($auteur === $moi || strtotime($quand) < DF::$t0 - 10 * 86400) continue;
                $mention = isset($prenomCle[$k]) && stripos($texte, '@' . $prenomCle[$k]) !== false;
                $items[] = [$quand, $mention ? 'mention' : 'message',
                            $nom($auteur) . ($mention ? ' vous a mentionné dans #' : ' a écrit dans #') . $canaux[$slug][0],
                            mb_substr(strip_tags($texte), 0, $mention ? 200 : 150), '/messages?c=' . $slug, ['message_id' => $mid, 'actor_id' => $auteur]];
            }
        }
        // Messages des projets où ce compte est dans l'équipe.
        foreach (DF::$ids['messages'] ?? [] as $cle => $liste) {
            $equipe = $proj[$cle][11] ?? [];
            if (!isset($equipe[$k])) continue;
            $pid = df_id('projets', $cle);
            foreach ($liste as [$mid, $auteur, $quand, $texte]) {
                if ($auteur === $moi || strtotime($quand) < DF::$t0 - 10 * 86400) continue;
                $items[] = [$quand, 'message', $nom($auteur) . ' a écrit dans « ' . $proj[$cle][1] . ' »', mb_substr($texte, 0, 150),
                            "/projet/$pid/messages", ['project_id' => $pid, 'message_id' => $mid, 'actor_id' => $auteur]];
            }
        }
        // Étapes assignées.
        foreach (DF::$ids['etapes_assignees'][$k] ?? [] as $n => [$sid, $pid, $titre]) {
            $cle = array_search($pid, DF::$ids['projets'] ?? [], true);
            $ref = $u[$proj[$cle][3] ?? 'admin'] ?? $u['admin'];
            $items[] = [df_jh(-df_entre(2, 8), '09:3' . $n), 'step_assigned', $nom($ref === $moi ? $u['admin'] : $ref) . ' vous a assigné à une étape de « ' . ($proj[$cle][1] ?? '') . ' »',
                        '📝 Étape : ' . $titre, "/projet/$pid", ['project_id' => $pid, 'step_id' => $sid, 'actor_id' => $ref === $moi ? $u['admin'] : $ref]];
        }
        // Ajout à une équipe de projet (le financeur, sur ses deux projets).
        foreach ($proj as $cle => $p) {
            if (!isset($p[11][$k]) || ($k !== 'financeur' && df_proba(0.75))) continue;
            $pid = df_id('projets', $cle);
            if (!$pid) continue;
            $ref = $u[$p[3]] ?? $u['admin'];
            if ($ref === $moi) continue;
            $items[] = [df_jh(-df_entre(6, 10), '11:00'), 'team_added', $nom($ref) . ' vous a ajouté au projet « ' . $p[1] . ' »',
                        ($dossiers[$p[0]][0] ?? '') . ' · DEMO F', "/projet/$pid", ['project_id' => $pid, 'actor_id' => $ref]];
        }
        if ($k === 'admin') {
            $items[] = [df_jh(-2, '07:30'), 'grant_radar', '🎯 6 nouvelles pistes de subvention', 'Le radar a trouvé des dispositifs adaptés à votre profil.', '/financements', []];
        }
        usort($items, fn($a, $b) => strcmp($b[0], $a[0]));
        $items = array_slice($items, 0, $k === 'financeur' ? 4 : 16);
        $nonLus = $k === 'financeur' ? 1 : 4;
        foreach ($items as $i => [$quand, $type, $titre, $corps, $lien, $liens]) {
            $notif($moi, $type, $titre, $corps, $lien, $quand, $i >= $nonLus, $liens);
        }
    }
}
