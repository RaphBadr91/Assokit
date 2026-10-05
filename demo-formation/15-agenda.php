<?php
/**
 * 15 — Agenda, événements publics avec inscriptions, emploi du temps, absences.
 * ------------------------------------------------------------------
 * L'agenda doit être plein à toutes les échelles : aujourd'hui (même en
 * fin de journée), la semaine, le mois. D'où des séries hebdomadaires
 * (une ligne par occurrence, comme les crée l'application) et une
 * vingtaine d'événements ponctuels, de J-42 à J+70.
 *
 * Les sessions sur plusieurs jours sont « journée entière » : l'agenda
 * avance de 24 h depuis l'heure de début et raterait sinon le dernier jour.
 * Les couleurs se limitent aux 7 classes CSS existantes.
 * ------------------------------------------------------------------
 */

/** Les événements publics s'insèrent au-dessus de 1000 : la plage 127-198 est réservée par le snapshot démo. */
function df_pre_agenda(): void
{
    foreach (['communication_events' => 1000] as $t => $min) {
        if (!df_a_table($t)) continue;
        $ai = (int)df_val("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]);
        $max = (int)df_val("SELECT COALESCE(MAX(id), 0) FROM `$t`");
        if ($ai < $min && $max < $min) DF::$pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = $min");
    }
}

function df_seed_agenda(): void
{
    $u = DF::$u;
    $qui = fn(string $k) => $u[$k] ?? $u['admin'];
    $p = fn(string $k) => df_id('projets', $k);
    $contact = "\nContact : contact@" . DF_DOMAINE . ' — ' . df_tel(1234, false);

    $ev = function (array $e) use ($contact) {
        $debut = $e['debut'];
        return df_ins('events', [
            'org_id' => DF::$org, 'project_id' => $e['projet'] ?? null, 'created_by' => $e['par'],
            'title' => $e['titre'], 'description' => ($e['desc'] ?? null) ? $e['desc'] . $contact : null,
            'rsvp_token' => null, 'rsvp_enabled' => 0, 'location' => $e['lieu'] ?? null,
            'event_type' => $e['type'], 'color_theme' => $e['couleur'],
            'starts_at' => $debut, 'ends_at' => $e['fin'], 'is_all_day' => $e['journee'] ?? 0,
            'visibility' => $e['visibilite'] ?? 'organization', 'sync_origin' => 'assokit',
            'google_calendar_id' => null, 'google_event_id' => null, 'synced_at' => null,
            'created_at' => $e['creation'], 'updated_at' => $e['creation'], 'deleted_at' => null,
        ]);
    };
    $creation = fn(string $debut) => date('Y-m-d H:i:s', min(time() - 3600, strtotime($debut) - 86400 * df_entre(7, 30)));

    // ---------- Séries hebdomadaires ----------
    $series = [
        // [jour ISO, début, fin, titre, type, couleur, lieu, projet, auteur]
        [1, '09:30', '10:30', 'Réunion d\'équipe salariés', 'meeting', 'blue', 'Salle Hopper', null, 'salarie'],
        [2, '09:30', '12:00', 'Atelier FLE — niveau A2', 'workshop', 'purple', 'Salle Lovelace', 'fle-automne', 'benevole'],
        [3, '14:00', '16:30', 'Atelier numérique seniors', 'workshop', 'teal', 'Salle informatique', 'pass-numerique', 'formatrice'],
        [4, '14:00', '17:00', 'Permanence emploi & CV', 'other', 'amber', 'Accueil', 'plie', 'insertion'],
        [5, '14:00', '16:00', 'Aide aux devoirs', 'workshop', 'pink', 'Collège des Tilleuls', 'devoirs', 'devoirs'],
    ];
    $lundi = (int)date('N', DF::$t0) - 1;
    for ($sem = -6; $sem <= 10; $sem++) {
        foreach ($series as [$jour, $h1, $h2, $titre, $type, $coul, $lieu, $projet, $par]) {
            $j = $sem * 7 - $lundi + ($jour - 1);
            if ($j < -42 || $j > 70) continue;
            $d = df_jh($j, $h1);
            $ev(['titre' => $titre, 'type' => $type, 'couleur' => $coul, 'lieu' => $lieu, 'projet' => $projet ? $p($projet) : null,
                 'par' => $qui($par), 'debut' => $d, 'fin' => df_jh($j, $h2), 'creation' => $creation($d)]);
        }
    }

    // ---------- Événements ponctuels ----------
    $jour = fn(int $j) => [df_jh($j, '00:00'), df_jh($j, '23:59:59')];
    $plage = fn(int $a, int $b) => [df_jh($a, '00:00'), df_jh($b, '23:59:59')];
    $ponctuels = [
        // [jours, heures (null = journée), titre, type, couleur, lieu, projet, auteur, visibilité, description]
        [[-38, -37], null, 'Formation Excel — Clinique (client)', 'workshop', 'red', 'Antony — sur site', 'excel-ccas', 'salarie', 'organization', 'Deux jours de formation Excel pour l\'équipe administrative.'],
        [-30, ['18:30', '20:30'], 'Conseil d\'administration', 'meeting', 'blue', 'Salle Hopper', null, 'admin', 'organization', 'Ordre du jour : budget, Qualiopi, recrutement.'],
        [-24, null, 'Bilan intermédiaire Fondation', 'deadline', 'red', null, 'pass-numerique', 'formatrice', 'project_only', 'Envoyer le bilan intermédiaire à la Fondation Avenir Solidaire.'],
        [-17, ['10:00', '12:00'], 'Comité de suivi Fondation', 'meeting', 'blue', 'Visio (Teams)', 'plie', 'insertion', 'project_only', 'Point d\'étape avec Claire Vasseur.'],
        [-10, ['18:00', '21:00'], 'Soirée de rentrée bénévoles', 'internal', 'teal', 'Locaux DEMO F', null, 'evenements', 'organization', 'Accueil des nouveaux bénévoles, buffet partagé.'],
        [-3, ['09:00', '12:30'], 'Jury de certification Pix', 'workshop', 'purple', 'Salle informatique', 'pass-numerique', 'formatrice', 'organization', '12 candidats.'],
        [0, ['17:30', '19:00'], 'Café des langues', 'public', 'green', 'Médiathèque de Massy', 'fle-automne', 'benevole', 'public', 'Pratiquer le français autour d\'un café, ouvert à tous.'],
        [0, ['19:00', '20:30'], 'Réunion de bureau', 'meeting', 'blue', 'Salle Hopper', null, 'admin', 'organization', 'Préparation de l\'assemblée générale.'],
        [1, ['10:00', '12:00'], 'RDV OPCO — plan de formation', 'meeting', 'blue', 'Visio', 'clea', 'salarie', 'organization', 'Financement de la certification CléA.'],
        [[2, 4], null, 'Formation SST — Ville (client)', 'workshop', 'red', 'Démoville — Hôtel de ville', 'bureautique-mairie', 'formatrice', 'organization', 'Sauveteur secouriste du travail, 12 agents.'],
        [5, ['14:00', '18:00'], 'Forum emploi & alternance', 'public', 'green', 'Salle Jean-Moulin', 'job-dating', 'insertion', 'public', '15 entreprises qui recrutent.'],
        [7, null, 'Date limite dossier FDVA', 'deadline', 'red', null, 'remobilisation', 'admin', 'organization', 'Déposer le dossier FDVA fonctionnement-innovation.'],
        [9, ['10:00', '12:00'], 'Visite Fondation Avenir Solidaire', 'meeting', 'pink', 'Locaux DEMO F', 'pass-numerique', 'admin', 'project_only', 'Visite des ateliers numériques avec Claire Vasseur.'],
        [12, ['10:00', '17:00'], 'Journée portes ouvertes', 'public', 'green', 'Locaux DEMO F', null, 'evenements', 'public', 'Découverte des ateliers, inscriptions sur place.'],
        [14, ['18:30', '20:30'], 'Conseil d\'administration', 'meeting', 'blue', 'Salle Hopper', 'ag', 'admin', 'organization', 'Validation du rapport moral et des comptes.'],
        [[16, 17], null, 'Accueil du public — CCAS (client)', 'workshop', 'amber', 'Démoville — CCAS', 'excel-ccas', 'salarie', 'organization', 'Formation à l\'accueil du public.'],
        [19, ['18:00', '20:00'], 'Remise certificats Pix & DELF', 'public', 'pink', 'Salle des fêtes', 'delf-b1', 'admin', 'public', 'Cérémonie de remise des certificats.'],
        [23, null, 'Rapport trimestriel Région', 'deadline', 'red', null, 'plie', 'insertion', 'organization', 'Rapport d\'activité trimestriel à transmettre.'],
        [26, ['14:00', '16:00'], 'Webinaire démarches en ligne', 'public', 'green', 'En ligne', 'permanences', 'insertion', 'public', 'Faire ses démarches CAF et impôts en ligne.'],
        [30, ['09:00', '17:00'], 'Formation des formateurs bénévoles', 'internal', 'teal', 'Locaux DEMO F', null, 'salarie', 'organization', 'Pédagogie pour adultes, gestion de groupe.'],
        [35, null, 'Clôture inscriptions session', 'deadline', 'red', null, 'fle-automne', 'benevole', 'organization', 'Fin des inscriptions au 4e groupe FLE.'],
        [42, ['18:30', '22:00'], 'Soirée solidaire de fin d\'année', 'public', 'pink', 'Salle des fêtes', null, 'evenements', 'public', 'Repas partagé, spectacle des apprenants.'],
        [56, ['10:00', '12:00'], 'Comité de pilotage Région', 'meeting', 'blue', 'Visio', 'plie', 'admin', 'project_only', 'Bilan à mi-parcours du PLIE.'],
    ];
    foreach ($ponctuels as [$j, $h, $titre, $type, $coul, $lieu, $projet, $par, $vis, $desc]) {
        if ($h === null) {
            [$d, $f] = is_array($j) ? $plage($j[0], $j[1]) : $jour($j);
            $journee = 1;
        } else {
            $d = df_jh($j, $h[0]); $f = df_jh($j, $h[1]); $journee = 0;
        }
        $id = $ev(['titre' => $titre, 'type' => $type, 'couleur' => $coul, 'lieu' => $lieu, 'projet' => $projet ? $p($projet) : null,
                   'par' => $qui($par), 'debut' => $d, 'fin' => $f, 'journee' => $journee, 'visibilite' => $vis,
                   'desc' => $desc, 'creation' => $creation($d)]);
        if ($id) df_retenir('evenements', $titre . '|' . (is_array($j) ? $j[0] : $j), $id);
        // Réponses de l'équipe aux réunions à venir.
        if ($id && (is_array($j) ? $j[0] : $j) >= 0 && in_array($type, ['meeting', 'internal'], true) && df_a_table('event_participants')) {
            foreach (df_echantillon(df_equipe_active(), df_entre(5, 12)) as $uid) {
                $r = df_entre(1, 10);
                df_ins('event_participants', ['event_id' => $id, 'user_id' => $uid, 'response' => $r <= 6 ? 'yes' : ($r <= 9 ? 'maybe' : 'no'),
                                              'responded_at' => df_jh(-df_entre(0, 6), '12:00'), 'created_at' => df_jh(-df_entre(7, 10), '12:00')]);
            }
        }
    }

    df_seed_evenements_publics();
    df_seed_emploi_du_temps();

    // Abonnement calendrier (Apple / Google) de la présidente.
    df_ins('user_calendar_tokens', ['user_id' => $u['admin'], 'token' => substr(hash('sha256', 'demo-f|calendrier|admin'), 0, 40),
                                    'label' => 'iPhone de Sophie', 'fetch_count' => 214, 'last_used_at' => df_jh(0, '06:12'),
                                    'revoked_at' => null, 'created_at' => df_jh(-120, '19:00')]);
}

/** Événements publics avec page d'inscription, invitations envoyées et réponses. */
function df_seed_evenements_publics(): void
{
    if (!df_a_table('communication_events')) return;
    $u = DF::$u;
    $actifs = (int)df_val("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL", [DF::$org]);
    $invites = ['Martine Lopez', 'Rachid Benhamou', 'Claire Dumont', 'Youssef El Amrani', 'Sandrine Vidal', 'Jean-Pierre Morel',
                'Aïssatou Ba', 'Kevin Moreau', 'Laura Petitjean', 'Hamid Cherif', 'Céline Roy', 'David Nguyen', 'Fanny Leclerc',
                'Ibrahima Sow', 'Manon Garcia', 'Patrice Colas', 'Leila Mansouri', 'Thierry Blanc', 'Nadia Ferhat', 'Olivier Brun'];
    $commentaires = ['Je viendrai avec ma fille.', 'Je peux aider à l\'installation dès 17 h.', 'Allergie aux arachides.',
                     'Merci pour l\'invitation !', 'J\'arriverai un peu en retard.', 'Est-ce accessible en fauteuil ?', 'Avec mon CV imprimé 🙂'];
    $liste = [
        // [titre, jour, début, fin, lieu, adresse, max, projet, [oui, peut-être, non], statut]
        ['Forum de l\'emploi & de l\'alternance', 5, '14:00', '18:00', 'Salle Jean-Moulin', '2 avenue Jean-Moulin, 91300 Massy', 120, 'job-dating', [78, 14, 6], 'published'],
        ['Café des langues', 0, '17:30', '19:00', 'Médiathèque de Massy', 'Place de France, 91300 Massy', 25, 'fle-automne', [22, 3, 1], 'published'],
        ['Journée portes ouvertes', 12, '10:00', '17:00', 'Locaux DEMO F', '12 rue des Ateliers, 91300 Massy', null, null, [41, 12, 3], 'published'],
        ['Remise des certificats Pix & DELF', 19, '18:00', '20:00', 'Salle des fêtes', '1 place de la Mairie, 91300 Massy', 80, 'delf-b1', [35, 10, 2], 'published'],
        ['Soirée solidaire de fin d\'année', 42, '18:30', '22:00', 'Salle des fêtes', '1 place de la Mairie, 91300 Massy', 150, null, [18, 9, 1], 'published'],
        ['Rentrée solidaire — inscriptions', -27, '10:00', '17:00', 'Locaux DEMO F', '12 rue des Ateliers, 91300 Massy', null, null, [64, 7, 5], 'published'],
        ['Conférence « Le numérique pour tous »', -52, '18:30', '20:30', 'Médiathèque de Massy', 'Place de France, 91300 Massy', 60, 'pass-numerique', [37, 6, 4], 'published'],
        ['Sortie culturelle au musée', 8, '13:30', '18:00', 'Paris', 'Palais de la Porte Dorée, 75012 Paris', 20, 'fle-automne', [9, 0, 0], 'cancelled'],
    ];
    $membres = df_tous_membres();
    foreach ($liste as $i => [$titre, $j, $h1, $h2, $lieu, $adresse, $max, $projet, [$oui, $peut, $non], $statut]) {
        $cree = min(-1, $j - df_entre(14, 30));
        $bid = df_ins('communication_broadcasts', [
            'org_id' => DF::$org, 'created_by_user_id' => $u['admin'], 'campaign_id' => null,
            'subject' => 'Invitation : ' . $titre,
            'body' => "Bonjour,\n\nNous serions heureux de vous accueillir : $titre, le " . date('d/m/Y', strtotime(df_j($j))) . " à $h1, $lieu.\n\nInscription en un clic sur la page de l'événement.\n\nL'équipe DEMO F",
            'recipient_type' => 'all', 'recipient_roles' => null, 'recipient_user_ids' => null,
            'status' => 'sent', 'nb_total' => $actifs, 'nb_sent' => max(0, $actifs - 2), 'nb_failed' => 2,
            'sent_at' => df_jh($cree, '10:05'), 'created_at' => df_jh($cree, '09:50'),
        ]);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(df_ascii($titre))), '-') . '-' . substr(hash('sha256', "demo-f|ev|$i"), 0, 6);
        $eid = df_ins('communication_events', [
            'org_id' => DF::$org, 'created_by_user_id' => $u['admin'], 'project_id' => $projet ? df_id('projets', $projet) : null,
            'title' => $titre,
            'description' => "Un moment ouvert à tous, organisé par l'association DEMO F.\n\nEntrée libre sur inscription. Contact : contact@" . DF_DOMAINE,
            'location' => $lieu, 'location_address' => $adresse,
            'start_date' => df_jh($j, $h1), 'end_date' => df_jh($j, $h2), 'max_attendees' => $max,
            'public_slug' => $slug, 'is_public' => 1, 'rsvp_enabled' => 1, 'status' => $statut,
            'broadcast_id' => $bid, 'created_at' => df_jh($cree, '09:45'),
        ]);
        if (!$eid) continue;
        df_retenir('evenements_publics', $titre, $eid);
        $pool = df_echantillon($membres, count($membres));
        $k = 0;
        foreach (['yes' => $oui, 'maybe' => $peut, 'no' => $non] as $rep => $nb) {
            for ($x = 0; $x < $nb; $x++) {
                $membre = df_proba(0.6) && isset($pool[$k]);
                $uid = $membre ? $pool[$k++] : null;
                $g = $membre ? null : df_choix($invites);
                df_ins('communication_event_rsvps', [
                    'event_id' => $eid, 'user_id' => $uid,
                    'guest_name' => $g, 'guest_email' => $g ? df_email(...explode(' ', $g, 2)) : null,
                    'response' => $rep, 'nb_accompanying' => $rep === 'yes' ? [0, 0, 0, 1, 1, 2][df_entre(0, 5)] : 0,
                    'comment' => df_proba(0.3) ? df_choix($commentaires) : null,
                    'responded_at' => df_jh(df_entre($cree, min(0, $j)), sprintf('%02d:%02d', df_entre(8, 22), df_entre(0, 59))),
                ]);
            }
        }
        // Les comptes de démo ont répondu : Thomas au Forum et au Café des langues, Karim partout.
        foreach ([['membre', [0, 1]], ['salarie', [0, 1, 2, 3, 4]]] as [$cle, $evts]) {
            if (in_array($i, $evts, true)) {
                df_ins('communication_event_rsvps', ['event_id' => $eid, 'user_id' => $u[$cle], 'response' => 'yes', 'nb_accompanying' => 0,
                                                     'comment' => null, 'responded_at' => df_jh(min(0, $cree + 2), '20:15')]);
            }
        }
    }
}

/** Emploi du temps de l'équipe et absences. */
function df_seed_emploi_du_temps(): void
{
    $u = DF::$u;
    $couleur = ['present' => 'blue', 'remote' => 'purple', 'meeting' => 'amber', 'other' => 'teal'];
    $hebdo = [
        ['salarie', 1, '09:00', '12:30', 'present', 'Coordination pédagogique'], ['salarie', 1, '14:00', '15:30', 'meeting', 'Réunion d\'équipe'],
        ['salarie', 2, '09:00', '17:30', 'present', 'Bureau'], ['salarie', 3, '09:00', '12:30', 'remote', 'Dossiers OPCO & financeurs'],
        ['salarie', 4, '09:00', '17:30', 'present', 'Bureau'], ['salarie', 5, '09:00', '12:30', 'present', 'Bureau'],
        ['formatrice', 1, '09:30', '12:00', 'present', 'Atelier bureautique'], ['formatrice', 4, '09:30', '12:00', 'present', 'Atelier bureautique'],
        ['formatrice', 2, '14:00', '17:00', 'present', 'Préparation Pix'], ['formatrice', 3, '14:00', '16:30', 'present', 'Atelier numérique seniors'],
        ['formatrice', 5, '14:00', '17:00', 'remote', 'Préparation des supports'],
        ['insertion', 1, '09:30', '12:30', 'present', 'Permanence emploi'], ['insertion', 2, '14:00', '17:30', 'present', 'Entretiens individuels'],
        ['insertion', 4, '14:00', '17:00', 'present', 'Permanence emploi & CV'], ['insertion', 5, '09:30', '12:00', 'meeting', 'Point partenaires France Travail'],
        ['communication', 1, '09:00', '17:00', 'present', 'Communication'], ['communication', 2, '09:00', '17:00', 'present', 'Communication'],
        ['communication', 4, '10:00', '11:00', 'meeting', 'Point com'],
        ['civique', 2, '09:30', '16:30', 'present', 'Accueil & médiation numérique'], ['civique', 3, '09:30', '16:30', 'present', 'Accueil & médiation numérique'],
        ['civique', 4, '09:30', '16:30', 'present', 'Accueil & médiation numérique'],
        ['benevole', 2, '09:30', '12:00', 'other', 'Atelier FLE A2'], ['benevole', 4, '09:30', '12:00', 'other', 'Atelier FLE A2'],
        ['fle2', 1, '14:00', '16:00', 'other', 'FLE grands débutants'], ['devoirs', 3, '16:30', '18:30', 'other', 'Aide aux devoirs'],
        ['numerique2', 5, '14:00', '16:00', 'other', 'Bureautique seniors'], ['accueil', 1, '09:00', '12:00', 'present', 'Accueil'],
        ['accueil', 3, '09:00', '12:00', 'present', 'Accueil'], ['admin', 4, '18:00', '19:30', 'meeting', 'Permanence de la présidente'],
    ];
    foreach ($hebdo as [$k, $jour, $h1, $h2, $type, $titre]) {
        if (empty($u[$k])) continue;
        df_ins('assokit_schedules', ['org_id' => DF::$org, 'user_id' => $u[$k], 'title' => $titre, 'type' => $type, 'recurrence' => 'weekly',
                                     'day_of_week' => $jour, 'specific_date' => null, 'start_time' => $h1 . ':00', 'end_time' => $h2 . ':00',
                                     'location' => $type === 'remote' ? 'Télétravail' : 'Locaux DEMO F', 'notes' => null,
                                     'color' => $couleur[$type], 'created_by' => $u[$k], 'created_at' => df_jh(-90, '10:00'), 'updated_at' => df_jh(-90, '10:00')]);
    }
    $ponctuels = [
        ['salarie', 1, '10:00', '12:00', 'meeting', 'RDV OPCO'], ['salarie', 9, '10:00', '12:00', 'meeting', 'Visite Fondation'],
        ['formatrice', 2, '08:30', '17:00', 'other', 'Formation SST sur site'], ['formatrice', 3, '08:30', '17:00', 'other', 'Formation SST sur site'],
        ['formatrice', 4, '08:30', '17:00', 'other', 'Formation SST sur site'], ['insertion', 5, '13:30', '18:30', 'other', 'Forum de l\'emploi'],
        ['communication', 12, '09:00', '18:00', 'other', 'Portes ouvertes'], ['civique', 12, '09:00', '18:00', 'other', 'Portes ouvertes'],
        ['formatrice', -3, '09:00', '12:30', 'other', 'Jury Pix'], ['admin', 14, '18:30', '20:30', 'meeting', 'Conseil d\'administration'],
    ];
    foreach ($ponctuels as [$k, $j, $h1, $h2, $type, $titre]) {
        if (empty($u[$k])) continue;
        df_ins('assokit_schedules', ['org_id' => DF::$org, 'user_id' => $u[$k], 'title' => $titre, 'type' => $type, 'recurrence' => 'once',
                                     'day_of_week' => null, 'specific_date' => df_j($j), 'start_time' => $h1 . ':00', 'end_time' => $h2 . ':00',
                                     'location' => null, 'notes' => null, 'color' => $couleur[$type], 'created_by' => $u['admin'],
                                     'created_at' => df_jh(min(-1, $j - 10), '10:00'), 'updated_at' => df_jh(min(-1, $j - 10), '10:00')]);
    }
    $absences = [
        // [clé, type, début, fin, motif, approuvée]
        ['formatrice', 'vacation', 20, 31, 'Congés de fin d\'année', 1], ['insertion', 'vacation', 45, 52, 'Congés', 0],
        ['communication', 'other', 6, 10, 'Semaine d\'école (alternance)', 1], ['communication', 'other', 27, 31, 'Semaine d\'école (alternance)', 1],
        ['salarie', 'personal', 3, 3, 'Rendez-vous personnel', 1], ['civique', 'sick', -4, -2, 'Arrêt maladie', 1],
        ['formatrice', 'sick', -15, -14, null, 1], ['insertion', 'vacation', -40, -30, 'Congés d\'été', 1],
        ['salarie', 'vacation', -60, -46, 'Congés d\'été', 1], ['prestataire', 'other', 8, 8, 'Indisponible', 1],
        ['civique', 'personal', 15, 15, 'Journée de formation service civique', 0], ['benevole', 'vacation', 10, 17, 'Vacances', 1],
    ];
    foreach ($absences as [$k, $type, $a, $b, $motif, $ok]) {
        if (empty($u[$k])) continue;
        df_ins('assokit_absences', ['org_id' => DF::$org, 'user_id' => $u[$k], 'type' => $type, 'start_date' => df_j($a), 'end_date' => df_j($b),
                                    'reason' => $motif, 'is_approved' => $ok, 'approved_by' => $ok ? $u['admin'] : null,
                                    'approved_at' => $ok ? df_jh(min(-1, $a - 5), '11:00') : null,
                                    'created_at' => df_jh(min(-2, $a - 12), '09:00'), 'updated_at' => df_jh(min(-1, $a - 5), '11:00')]);
    }
}
