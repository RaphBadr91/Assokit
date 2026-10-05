<?php
/**
 * 20 — Assemblées (AG, CA, bureau) et émargement.
 * ------------------------------------------------------------------
 * Le cycle de vie complet se voit d'un coup d'œil : une AG convoquée
 * dans trois semaines, une AGE en préparation, un CA en cours ce matin
 * (où l'on peut voter en direct depuis un téléphone), et quatre séances
 * clôturées avec procès-verbal — dont une résolution REJETÉE, preuve
 * que le moteur de vote calcule vraiment.
 *
 * Émargement : cinq semaines de séances signées, et trois séances
 * ouvertes ce matin. Aucune séance future : elle s'afficherait vide en
 * tête de liste.
 * ------------------------------------------------------------------
 */

/** Signature manuscrite (PNG base64) propre à une personne, identique d'une séance à l'autre. */
function df_signature(int $graine): ?string
{
    static $cache = [];
    if (isset($cache[$graine])) return $cache[$graine];
    if (!function_exists('imagecreatetruecolor')) {
        return $cache[$graine] = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="100"><path d="M20 60 C 60 ' . (20 + $graine % 40)
            . ', 90 90, 130 50 S 200 ' . (30 + $graine % 50) . ', 270 55" stroke="#1e293b" stroke-width="3" fill="none"/></svg>');
    }
    $img = imagecreatetruecolor(300, 100);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 255, 255, 255, 127));
    $encre = imagecolorallocate($img, 30, 41, 59);
    imagesetthickness($img, 3);
    mt_srand(DF_GRAINE + $graine * 13);
    $x = 18; $y = 55 + mt_rand(-10, 10);
    $boucles = mt_rand(5, 9);
    for ($i = 0; $i < $boucles * 6; $i++) {
        $nx = $x + mt_rand(4, 9);
        $ny = (int)(55 + sin($i / 2.0 + $graine) * mt_rand(12, 28));
        imageline($img, $x, $y, $nx, $ny, $encre);
        $x = $nx; $y = $ny;
        if ($x > 280) break;
    }
    imageline($img, 30, 80, min(280, $x), 78 + mt_rand(-4, 4), $encre);
    ob_start();
    imagepng($img, null, 9);
    $png = (string)ob_get_clean();
    imagedestroy($img);
    mt_srand(DF_GRAINE + 777 + $graine);   // on rend la main au tirage du module
    return $cache[$graine] = 'data:image/png;base64,' . base64_encode($png);
}

/** Résultat d'une résolution, selon la règle d'includes-assemblies.php (abstentions exclues). */
function df_resultat(string $type, int $pour, int $contre): string
{
    $exprimes = $pour + $contre;
    if ($exprimes === 0) return 'rejected';
    switch ($type) {
        case 'qualifie_2_3': return $pour * 3 >= $exprimes * 2 ? 'adopted' : 'rejected';
        case 'qualifie_3_4': return $pour * 4 >= $exprimes * 3 ? 'adopted' : 'rejected';
        case 'unanime':      return $contre === 0 ? 'adopted' : 'rejected';
        default:             return $pour * 2 > $exprimes ? 'adopted' : 'rejected';
    }
}

function df_seed_assemblees(): void
{
    if (!df_a_table('assemblies')) return;
    $u = DF::$u;
    $an = df_annee();
    $infos = function (int $uid): array {
        static $c = [];
        if (!isset($c[$uid])) {
            $q = DF::$pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
            $q->execute([$uid]);
            $c[$uid] = $q->fetch(PDO::FETCH_ASSOC) ?: ['first_name' => '', 'last_name' => '', 'email' => null];
        }
        return $c[$uid];
    };

    $ag = function (string $cle, array $a) use ($u) {
        $id = df_ins('assemblies', $a + ['org_id' => DF::$org, 'created_by' => $u['admin'], 'archived_at' => null,
                                         'location_url' => null, 'notes_internal' => null]);
        return df_retenir('assemblees', $cle, $id);
    };
    $convoquer = function (int $agId, string $cle, int $uid, string $statut, ?string $signe, ?string $envoi, ?string $ouvert) use ($infos) {
        $i = $infos($uid);
        return df_ins('assembly_attendees', [
            'assembly_id' => $agId, 'adherent_id' => $uid, 'user_id' => $uid,
            'full_name' => trim($i['first_name'] . ' ' . $i['last_name']), 'email' => $i['email'],
            'access_token' => sha1("demo-formation|ag|$cle|$uid"), 'status' => $statut,
            'signature_data' => $signe ? df_signature($uid) : null, 'signed_at' => $signe,
            'invitation_sent_at' => $envoi, 'invitation_opened_at' => $ouvert, 'created_at' => $envoi ?? df_jh(-1, '10:00'),
        ]);
    };
    /** Résolutions et votes. $res : [[titre, description, type, [pour, contre, abst] | null], …] */
    $resolutions = function (int $agId, array $res, array $votants, bool $close, ?string $ouv, ?string $fin) {
        foreach ($res as $pos => [$titre, $desc, $type, $votes]) {
            $pour = $votes[0] ?? 0; $contre = $votes[1] ?? 0;
            $rid = df_ins('assembly_resolutions', [
                'assembly_id' => $agId, 'position' => $pos, 'title' => $titre, 'description' => $desc, 'vote_type' => $type,
                'is_open' => $close ? 0 : 1, 'result' => $close ? df_resultat($type, $pour, $contre) : 'pending',
                'closed_at' => $close ? $fin : null,
            ]);
            if (!$rid || !$votes) continue;
            $choix = array_merge(array_fill(0, $votes[0], 'for'), array_fill(0, $votes[1], 'against'), array_fill(0, $votes[2], 'abstain'));
            $qui = df_echantillon($votants, count($choix));
            $t0 = strtotime($ouv);
            $t1 = $fin ? strtotime($fin) : time();
            foreach ($qui as $i => $att) {
                df_ins('assembly_votes', ['resolution_id' => $rid, 'attendee_id' => $att, 'choice' => $choix[$i],
                                          'voted_at' => date('Y-m-d H:i:s', $t0 + (int)(($t1 - $t0) * ($pos + 0.5) / (count($res) + 1)) + df_entre(0, 240))]);
            }
        }
    };

    $membres = array_values(array_diff(df_tous_membres(), [$u['financeur'] ?? 0]));
    $q = DF::$pdo->prepare("SELECT id FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL AND role <> 'follower'");
    $q->execute([DF::$org]);
    $membres = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $ca = array_values(array_filter(array_map(fn($k) => $u[$k] ?? null,
        ['admin', 'secretaire', 'tresoriere', 'vicepres', 'fle2', 'devoirs', 'emploi', 'numerique2', 'evenements', 'membre'])));
    $ca[] = df_choix(DF::$g['adherents']);
    $bureau = array_values(array_filter(array_map(fn($k) => $u[$k] ?? null, ['admin', 'secretaire', 'tresoriere', 'vicepres', 'salarie'])));

    // ---------- 1. AGO convoquée dans trois semaines ----------
    $j = df_ouvre(21);
    $conv = df_jh(-3, '10:15');
    $id = $ag('ago', [
        'type' => 'ag_ord', 'title' => "Assemblée générale ordinaire $an", 'status' => 'sent',
        'description' => 'Ordre du jour : rapports moral, d\'activité et financier, approbation des comptes, renouvellement du tiers sortant du conseil d\'administration.',
        'scheduled_at' => df_jh($j, '18:30'), 'location' => 'Salle polyvalente — 12 rue des Ateliers, 91300 Massy (et en visio)',
        'location_url' => 'https://meet.jit.si/DemoF-AG-' . $an, 'quorum_required' => 38, 'convocation_sent_at' => $conv,
        'notes_internal' => "Salle réservée (120 places). 2 pouvoirs maximum par membre (art. 9 des statuts). Émargement sur tablette à l'entrée. Pot de l'amitié offert.",
        'created_at' => df_jh(-12, '09:30'),
    ]);
    if ($id) {
        $libres = [$u['membre'], $u['benevole'], $u['salarie']];
        $autres = df_echantillon(array_values(array_diff($membres, $libres)), count($membres));
        $votants = [];
        foreach ($autres as $n => $uid) {
            $statut = $n < 43 ? 'present' : ($n < 46 ? 'proxy' : ($n < 60 ? 'excused' : 'invited'));
            $signe = $statut === 'present' ? df_jh(-df_entre(0, 3), sprintf('%02d:%02d', df_entre(8, 21), df_entre(0, 59))) : null;
            $convoquer($id, 'ago', $uid, $statut, $signe, $conv, df_proba(0.62) ? df_jh(-df_entre(0, 3), '12:00') : null);
        }
        foreach ($libres as $uid) $convoquer($id, 'ago', $uid, 'invited', null, $conv, df_jh(-2, '19:40'));
        $resolutions($id, [
            ['Approbation du procès-verbal de l\'AG précédente', 'Le procès-verbal a été adressé avec la convocation.', 'simple', null],
            ['Approbation du rapport moral', 'Présenté par la présidente, Sophie Laurent.', 'simple', null],
            ['Approbation du rapport d\'activité', '1 380 heures de formation, 214 apprenants accompagnés.', 'simple', null],
            ['Approbation des comptes de l\'exercice', 'Présentés par la trésorière, Nadia Haddad.', 'simple', null],
            ['Affectation du résultat', 'Affectation de l\'excédent au fonds associatif.', 'simple', null],
            ['Quitus au conseil d\'administration', null, 'simple', null],
            ['Renouvellement du tiers sortant du CA', 'Trois postes à pourvoir, quatre candidatures.', 'simple', null],
        ], [], false, null, null);
    }

    // ---------- 2. AGE en préparation ----------
    $id = $ag('age', [
        'type' => 'ag_ext', 'title' => 'AGE — mise à jour des statuts (activité d\'organisme de formation)', 'status' => 'draft',
        'description' => 'Adapter l\'objet social à l\'activité d\'organisme de formation certifié Qualiopi.',
        'scheduled_at' => df_jh(df_ouvre(60), '18:30'), 'location' => 'Salle polyvalente — 12 rue des Ateliers, 91300 Massy',
        'quorum_required' => 50, 'convocation_sent_at' => null,
        'notes_internal' => 'Statuts en relecture par l\'avocat bénévole.', 'created_at' => df_jh(-5, '20:00'),
    ]);
    if ($id) {
        $resolutions($id, [
            ['Modification de l\'article 2 — objet social', 'Ajout des actions de formation professionnelle continue.', 'qualifie_2_3', null],
            ['Modification de l\'article 11 — composition du CA', 'Passage de 9 à 11 administrateurs.', 'qualifie_2_3', null],
            ['Pouvoirs pour formalités', null, 'simple', null],
        ], [], false, null, null);
    }

    // ---------- 3. CA en cours ce matin ----------
    $ouv = df_jh(0, '08:34');
    $id = $ag('ca-en-cours', [
        'type' => 'ca', 'title' => 'Conseil d\'administration — séance en visioconférence', 'status' => 'in_progress',
        'description' => 'Budget prévisionnel, ouverture d\'un groupe FLE du soir, convention avec la Fondation.',
        'scheduled_at' => df_jh(0, '08:30'), 'location' => 'Visioconférence', 'location_url' => 'https://meet.jit.si/DemoF-CA',
        'quorum_required' => 6, 'convocation_sent_at' => df_jh(-8, '17:00'), 'opened_at' => $ouv, 'created_at' => df_jh(-9, '18:00'),
    ]);
    if ($id) {
        $votants = [];
        foreach ($ca as $n => $uid) {
            $libre = in_array($uid, [$u['membre'], $u['numerique2'] ?? 0], true);
            $statut = $libre ? 'invited' : ($n < 8 ? 'present' : ($n === 8 ? 'proxy' : 'excused'));
            if ($statut === 'present' && count($votants) >= 7) $statut = 'proxy';
            $att = $convoquer($id, 'ca', $uid, $statut, $statut === 'present' ? df_jh(0, sprintf('08:%02d', df_entre(22, 41))) : null,
                              df_jh(-8, '17:00'), df_jh(-df_entre(1, 7), '19:00'));
            if ($att && in_array($statut, ['present', 'proxy'], true)) $votants[] = $att;
        }
        $resolutions($id, [
            ['Budget prévisionnel de l\'exercice', 'Budget équilibré à 248 000 €.', 'simple', [5, 0, 1]],
            ['Ouverture d\'un 2e groupe FLE du soir', 'Liste d\'attente de 7 personnes.', 'simple', [4, 1, 0]],
            ['Convention pluriannuelle avec la Fondation Avenir Solidaire', '45 000 € sur 3 ans.', 'qualifie_2_3', [3, 0, 0]],
            ['Désignation des délégués à l\'AG de la fédération', null, 'simple', [0, 0, 0]],
        ], $votants, false, $ouv, null);
    }

    // ---------- 4 à 7. Séances clôturées ----------
    $clore = function (string $cle, string $type, string $titre, int $j, string $h, int $duree, int $quorum, array $convoques,
                       array $repartition, array $res, string $desc) use ($ag, $convoquer, $resolutions) {
        $debut = df_jh($j, $h);
        $ouv = date('Y-m-d H:i:s', strtotime($debut) + 180);
        $fin = date('Y-m-d H:i:s', strtotime($ouv) + $duree * 60);
        $id = $ag($cle, [
            'type' => $type, 'title' => $titre, 'status' => 'closed', 'description' => $desc,
            'scheduled_at' => $debut, 'location' => 'Salle polyvalente — 12 rue des Ateliers, 91300 Massy',
            'quorum_required' => $quorum, 'convocation_sent_at' => df_jh($j - 16, '10:00'),
            'opened_at' => $ouv, 'closed_at' => $fin, 'pv_generated_at' => date('Y-m-d H:i:s', strtotime($fin) + 1800),
            'created_at' => df_jh($j - 25, '10:00'),
        ]);
        if (!$id) return;
        $votants = [];
        $n = 0;
        foreach ($repartition as $statut => $nb) {
            for ($x = 0; $x < $nb && $n < count($convoques); $x++, $n++) {
                $signe = $statut === 'present' ? date('Y-m-d H:i:s', strtotime($debut) - df_entre(0, 900)) : null;
                $att = $convoquer($id, $cle, $convoques[$n], $statut, $signe, df_jh($j - 16, '10:00'), df_jh($j - df_entre(2, 15), '12:00'));
                if ($att && in_array($statut, ['present', 'proxy'], true)) $votants[] = $att;
            }
        }
        $resolutions($id, $res, $votants, true, $ouv, $fin);
    };

    $clore('bureau', 'bureau', 'Réunion de bureau — préparation de l\'AG', df_ouvre(-9), '18:30', 85, 3, $bureau,
           ['present' => 5],
           [['Calendrier de préparation de l\'AG', null, 'simple', [4, 0, 0]],
            ['Choix du traiteur pour le pot de l\'AG', null, 'simple', [4, 0, 0]]],
           'Préparation de l\'assemblée générale ordinaire.');
    $clore('ca-rentree', 'ca', 'Conseil d\'administration — rentrée', df_ouvre(-40), '18:00', 110, 6, $ca,
           ['present' => 9, 'excused' => 2],
           [['Plan de formation du trimestre', null, 'simple', [9, 0, 0]],
            ['Recrutement d\'un·e formateur·rice FLE en CDD (24 h/semaine)', null, 'simple', [8, 0, 1]],
            ['Candidature à l\'appel à projets « Inclusion numérique » de la Région', null, 'simple', [9, 0, 0]],
            ['Grille tarifaire des formations entreprises : 650 € HT/jour', null, 'qualifie_2_3', [7, 1, 1]]],
           'Rentrée : plan de formation, recrutement, tarifs.');

    $anciens = df_echantillon($membres, 142);
    $clore('ago-precedente', 'ag_ord', 'Assemblée générale ordinaire ' . ($an - 1), df_ouvre(-343), '18:30', 125, 36, $anciens,
           ['present' => 58, 'proxy' => 9, 'excused' => 21, 'absent' => 54],
           [['Approbation du procès-verbal de l\'AG précédente', null, 'simple', [63, 0, 4]],
            ['Approbation du rapport moral', null, 'simple', [65, 0, 2]],
            ['Approbation du rapport d\'activité', '1 240 heures de formation, 186 apprenants.', 'simple', [66, 0, 1]],
            ['Approbation des comptes', 'Excédent de 12 480 €.', 'simple', [60, 2, 5]],
            ['Affectation du résultat', null, 'simple', [61, 1, 5]],
            ['Quitus au conseil d\'administration', null, 'simple', [59, 3, 5]],
            ['Élection du tiers sortant', null, 'simple', [62, 1, 4]],
            ['Cotisation portée de 20 € à 30 €', 'Proposition du trésorier sortant.', 'simple', [24, 39, 4]],
            ['Budget prévisionnel', null, 'simple', [57, 4, 6]]],
           'Assemblée générale annuelle.');
    $clore('age-precedente', 'ag_ext', 'AG extraordinaire ' . ($an - 1) . ' — modification de l\'objet social', df_ouvre(-343), '20:45', 40, 47, $anciens,
           ['present' => 54, 'proxy' => 7, 'excused' => 30, 'absent' => 51],
           [['Modification de l\'objet social', null, 'qualifie_2_3', [58, 2, 1]],
            ['Transfert du siège social', 'Du 4 rue Pasteur au 12 rue des Ateliers.', 'qualifie_2_3', [55, 4, 2]],
            ['Pouvoirs pour formalités', null, 'simple', [60, 0, 1]]],
           'Mise à jour des statuts.');

    df_seed_emargement();
}

function df_seed_emargement(): void
{
    if (!df_a_table('attendance_sessions')) return;
    $u = DF::$u;
    $p = fn(string $k) => df_id('projets', $k);
    $apprenants = DF::$g['apprenants'];
    $cohortes = [
        'fle-matin' => df_echantillon($apprenants, 12),
        'fle-soir'  => df_echantillon($apprenants, 14),
        'num1'      => df_echantillon($apprenants, 10),
        'num2'      => df_echantillon($apprenants, 10),
        'emploi'    => df_echantillon($apprenants, 8),
    ];
    $infos = [];
    $info = function (int $uid) use (&$infos) {
        if (!isset($infos[$uid])) {
            $q = DF::$pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id = ?");
            $q->execute([$uid]);
            $infos[$uid] = $q->fetch(PDO::FETCH_ASSOC);
        }
        return $infos[$uid];
    };
    $salle = 'Salle Molière — 12 rue des Ateliers, Massy';
    $info_ = 'Salle informatique (12 postes) — Bâtiment B';
    $programme = [
        // [jours ISO, début, fin, titre, cohorte, projet, lieu]
        [[1, 3, 5], '09:00', '12:00', 'FLE A1 — groupe du matin', 'fle-matin', 'fle-automne', $salle],
        [[2, 4], '18:00', '20:00', 'FLE A2/B1 — cours du soir', 'fle-soir', 'delf-b1', $salle],
        [[2], '09:30', '12:00', 'Atelier numérique — premiers pas sur ordinateur', 'num1', 'pass-numerique', $info_],
        [[4], '14:00', '16:30', 'Atelier numérique — démarches en ligne (CAF, ameli, France Travail)', 'num2', 'pass-numerique', $info_],
        [[3], '14:00', '16:00', 'Atelier emploi — CV & simulation d\'entretien', 'emploi', 'plie', $salle],
    ];
    $session = function (string $titre, ?int $projet, string $lieu, string $debut, string $fin, bool $ouverte, string $cle) use ($u) {
        return df_ins('attendance_sessions', [
            'org_id' => DF::$org, 'project_id' => $projet, 'event_id' => null, 'title' => $titre, 'description' => null,
            'location' => $lieu, 'starts_at' => $debut, 'ends_at' => $fin,
            'access_token' => sha1('demo-formation|em|' . $cle), 'require_signature' => 1,
            'is_open' => $ouverte ? 1 : 0, 'closed_at' => $ouverte ? null : date('Y-m-d H:i:s', strtotime($fin) + 60 * df_entre(5, 20)),
            'archived_at' => null, 'created_by' => df_proba(0.7) ? $u['salarie'] : $u['admin'],
        ]);
    };
    $signer = function (int $sid, string $debut, ?int $uid, ?string $nom = null) use ($info) {
        $i = $uid ? $info($uid) : null;
        $retard = df_proba(0.06) ? df_entre(25, 35) : df_entre(-12, 20);
        df_ins('attendance_records', [
            'session_id' => $sid, 'user_id' => $uid, 'full_name' => $nom ?? trim(($i['first_name'] ?? '') . ' ' . ($i['last_name'] ?? '')),
            'email' => $uid && df_proba(0.6) ? $i['email'] : null, 'phone' => $uid && df_proba(0.4) ? $i['phone'] : null,
            'signature_data' => df_signature($uid ?? crc32((string)$nom)),
            'ip' => df_proba(0.7) ? '203.0.113.42' : '198.51.100.' . df_entre(2, 250),
            'signed_at' => date('Y-m-d H:i:s', strtotime($debut) + 60 * $retard),
        ]);
    };

    $lundi = (int)date('N', DF::$t0) - 1;
    for ($j = -35; $j <= -1; $j++) {
        $w = (int)date('N', strtotime(df_j($j)));
        foreach ($programme as [$jours, $h1, $h2, $titre, $coh, $proj, $lieu]) {
            if (!in_array($w, $jours, true)) continue;
            $d = df_jh($j, $h1);
            $sid = $session($titre, $p($proj), $lieu, $d, df_jh($j, $h2), false, "$coh|$j");
            if (!$sid) continue;
            $presents = df_echantillon($cohortes[$coh], max(1, count($cohortes[$coh]) - df_entre(0, 4)));
            foreach ($presents as $uid) $signer($sid, $d, $uid);
        }
        // Formation entreprise un vendredi sur deux, salariés du client (sans compte).
        if ($w === 5 && intdiv(-$j, 7) % 2 === 0) {
            $d = df_jh($j, '09:00');
            $sid = $session('Formation entreprise — Excel intermédiaire (CCAS de Démoville)', $p('excel-ccas'), 'Démoville — CCAS, salle de réunion', $d, df_jh($j, '17:00'), false, "excel|$j");
            if ($sid) foreach (array_slice(['Isabelle Garnier', 'Mourad Haddou', 'Christine Lefort', 'Bruno Caillaud', 'Aurélie Mendes', 'Pascal Vidal', 'Nora Belkacem', 'Sylvain Roux'], 0, df_entre(6, 8)) as $nom) $signer($sid, $d, null, $nom);
        }
    }
    // Formations des bénévoles et comité de pilotage (signé par la financeuse).
    foreach ([[-26, 'Formation des bénévoles — accueillir un public allophone'], [-12, 'Formation des bénévoles — accueillir un public allophone']] as $n => [$j, $titre]) {
        $d = df_jh($j, '18:00');
        $sid = $session($titre, null, $salle, $d, df_jh($j, '20:30'), false, "benevoles|$n");
        if ($sid) foreach (df_echantillon(DF::$g['benevoles'], 9) as $uid) $signer($sid, $d, $uid);
    }
    $d = df_jh(-17, '10:00');
    $sid = $session('Comité de pilotage — Parcours numérique (Fondation Avenir Solidaire)', $p('pass-numerique'), 'Visioconférence', $d, df_jh(-17, '12:00'), false, 'copil');
    if ($sid) foreach (array_filter([$u['financeur'] ?? null, $u['admin'], $u['formatrice'] ?? null, $u['salarie']]) as $uid) $signer($sid, $d, $uid);

    // Ce matin : trois séances ouvertes, qui commencent tôt.
    foreach ([['08:45', '12:00', 'Permanence emploi', 'emploi', 'plie', 3], ['09:00', '12:00', 'FLE A1 — groupe du matin', 'fle-matin', 'fle-automne', 9],
              ['09:30', '12:00', 'Atelier numérique — premiers pas sur ordinateur', 'num1', 'pass-numerique', 6]] as [$h1, $h2, $titre, $coh, $proj, $nb]) {
        $d = df_jh(0, $h1);
        $sid = $session($titre, $p($proj), $coh === 'num1' ? $info_ : $salle, $d, df_jh(0, $h2), true, "$coh|aujourdhui");
        if (!$sid) continue;
        df_retenir('emargement', $coh, $sid);
        $signes = array_slice($cohortes[$coh], 0, $nb);
        // L'adhérent de démo n'a pas encore signé : on peut le faire en direct.
        $signes = array_values(array_diff($signes, [$u['membre']]));
        foreach ($signes as $uid) {
            df_ins('attendance_records', [
                'session_id' => $sid, 'user_id' => $uid, 'full_name' => trim($info($uid)['first_name'] . ' ' . $info($uid)['last_name']),
                'email' => null, 'phone' => null, 'signature_data' => df_signature($uid), 'ip' => '203.0.113.42',
                'signed_at' => date('Y-m-d H:i:s', min(time() - 60, strtotime($d) + 60 * df_entre(-10, 15))),
            ]);
        }
    }
}
