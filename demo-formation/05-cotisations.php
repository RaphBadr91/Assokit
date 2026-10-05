<?php
/**
 * 05 — Cotisations : campagnes, tarifs, paiements, relances d'adhésion.
 * ------------------------------------------------------------------
 * Les paiements sont la vérité ; users.adhesion_valid_until en découle
 * (« date à date » : fin = paiement + 364 jours). Le module 00 a déjà
 * réparti les échéances (à jour, bientôt échues, expirées) : on retrouve
 * ici la date de chaque paiement par le calcul inverse, et on redistribue
 * seulement les « à jour » selon une saisonnalité réaliste (gros pic de
 * rentrée et de janvier), pour que les prévisions aient du relief.
 *
 * Campagnes :
 *   A  « Adhésions {Y} »                       active
 *   C  « Ateliers — participation aux frais »   active (saison S-S+1)
 *   B  « Adhésions {Y-1} »                     en pause
 *   D  « Adhésions {Y-2} »                     archivée (nourrit l'historique)
 * ------------------------------------------------------------------
 */

const DF_TARIFS = [
    // clé => [nom, prix Y, prix Y-1 et avant, description]
    'solidaire'   => ['Adhésion solidaire',   10.00,  8.00, 'Demandeurs d\'emploi, minima sociaux, étudiants, moins de 26 ans.'],
    'individuelle'=> ['Adhésion individuelle', 25.00, 22.00, 'Tarif standard — 12 mois de date à date.'],
    'famille'     => ['Adhésion famille',      40.00, 35.00, 'Jusqu\'à 4 personnes d\'un même foyer.'],
    'bienfaiteur' => ['Membre bienfaiteur',    80.00, 75.00, 'Soutien renforcé au projet associatif.'],
    'structure'   => ['Structure partenaire', 150.00, 150.00, 'Entreprises, collectivités et associations partenaires.'],
];

const DF_ATELIERS = [
    'numerique' => ['Atelier numérique — trimestre',        30.00, 'Dix séances de deux heures, supports compris.'],
    'fle'       => ['Parcours FLE — semestre',              45.00, 'Six heures de cours par semaine, manuels prêtés.'],
    'emploi'    => ['Coaching emploi — 5 séances',          20.00, 'Accompagnement individuel vers l\'emploi.'],
    'pix'       => ['Préparation certification Pix',        60.00, 'Préparation et passage de la certification Pix.'],
    'qf'        => ['Tarif solidaire (QF < 600)',            5.00, 'Sur simple présentation de l\'attestation CAF.'],
];

function df_seed_cotisations(): void
{
    if (!df_a_table('cotisation_campaigns')) return;
    $Y = df_annee();
    $S = (int)date('n', DF::$t0) >= 9 ? $Y : $Y - 1;
    $treso = DF::$u['tresoriere'] ?? DF::$u['admin'];
    $saisisseurs = array_merge(array_fill(0, 6, $treso), array_fill(0, 2, DF::$u['admin']), array_fill(0, 2, DF::$u['salarie']));

    // ---------- Campagnes et tarifs ----------
    $camp = function (string $nom, int $annee, string $ouv, string $ferm, int $actif, ?string $archive, string $creation, string $desc) use ($treso) {
        return df_ins('cotisation_campaigns', [
            'org_id' => DF::$org, 'name' => $nom, 'year' => $annee, 'description' => $desc,
            'currency' => 'EUR', 'opens_at' => $ouv, 'closes_at' => $ferm, 'is_active' => $actif,
            'public_token' => bin2hex(random_bytes(20)), 'created_by' => $treso,
            'created_at' => $creation, 'archived_at' => $archive,
        ]);
    };
    $descAdh = 'Adhésion annuelle, valable 12 mois de date à date. Elle donne accès aux ateliers numériques, FLE et emploi, '
             . 'à l\'accompagnement individuel et au vote en assemblée générale. Tarif solidaire sur simple déclaration.';
    $C = [
        'A' => $camp("Adhésions $Y", $Y, "$Y-01-01", "$Y-12-31", 1, null, ($Y - 1) . '-12-02 10:15:00', $descAdh),
        'C' => $camp("Ateliers — participation aux frais $S-" . ($S + 1), $S, "$S-09-01", ($S + 1) . '-07-10', 1, null, "$S-08-26 16:40:00",
                     'Participation aux frais pédagogiques (supports, matériel, certification Pix). Personne n\'est refusé faute '
                   . 'de moyens : tarif solidaire et prises en charge acceptées (Pass Numérique APTIC, France Travail, CCAS).'),
        'B' => $camp('Adhésions ' . ($Y - 1), $Y - 1, ($Y - 1) . '-01-01', ($Y - 1) . '-12-31', 0, null, ($Y - 2) . '-12-04 09:30:00', $descAdh),
        'D' => $camp('Adhésions ' . ($Y - 2), $Y - 2, ($Y - 2) . '-01-01', ($Y - 2) . '-12-31', 0, ($Y - 1) . '-02-01 10:00:00', ($Y - 3) . '-12-03 11:00:00', $descAdh),
    ];
    foreach ($C as $k => $id) df_retenir('cotisations', $k, $id);
    if (!$C['A']) return;

    $T = [];   // [campagne][clé] => [id, montant]
    foreach (['A', 'B', 'D'] as $k) {
        $pos = 0;
        foreach (DF_TARIFS as $cle => [$nom, $prix, $ancien, $desc]) {
            $montant = $k === 'A' ? $prix : $ancien;
            $T[$k][$cle] = [df_ins('cotisation_tiers', ['campaign_id' => $C[$k], 'name' => $nom, 'amount' => $montant,
                                                         'description' => $desc, 'position' => $pos++]), $montant];
        }
    }
    $pos = 0;
    foreach (DF_ATELIERS as $cle => [$nom, $prix, $desc]) {
        $T['C'][$cle] = [df_ins('cotisation_tiers', ['campaign_id' => $C['C'], 'name' => $nom, 'amount' => $prix,
                                                     'description' => $desc, 'position' => $pos++]), $prix];
    }

    // ---------- Personnes et dates ----------
    $q = DF::$pdo->prepare("SELECT id, first_name, last_name, email, adhesion_date, adhesion_valid_until, is_active, deleted_at
                              FROM users WHERE org_id = ? AND role <> 'follower'");
    $q->execute([DF::$org]);
    $personnes = $q->fetchAll(PDO::FETCH_ASSOC);

    $campagneDe = function (string $date) use ($Y): ?string {
        $a = (int)substr($date, 0, 4);
        return $a === $Y ? 'A' : ($a === $Y - 1 ? 'B' : ($a === $Y - 2 ? 'D' : null));
    };
    $refs = [];
    $reference = function (string $methode, string $date) use (&$refs): string {
        $a = substr($date, 0, 4);
        do {
            $r = [
                'bank'  => 'VIR-' . $a . '-' . df_entre(1000, 9999),
                'check' => 'CHQ ' . df_entre(1000000, 9999999),
                'cash'  => 'ESP-' . $a . '-' . str_pad((string)df_entre(1, 999), 3, '0', STR_PAD_LEFT),
                'other' => df_choix(['APTIC-' . $a . '-00' . df_entre(100, 999), 'AIF France Travail n° ' . df_entre(100000, 999999),
                                     'CCAS Massy — prise en charge']),
            ][$methode];
        } while (isset($refs[$r]) && $methode !== 'other');
        $refs[$r] = true;
        return $r;
    };
    $methodeAdh = fn() => df_choix(['bank', 'bank', 'bank', 'bank', 'check', 'check', 'check', 'cash', 'cash', 'other']);
    $tarifAdh = function () {
        $r = df_entre(1, 100);
        return $r <= 46 ? 'solidaire' : ($r <= 82 ? 'individuelle' : ($r <= 92 ? 'famille' : 'bienfaiteur'));
    };
    $payer = function (string $k, string $tarif, array $p, string $quand, string $methode, string $statut = 'paid',
                       ?string $ref = null, ?string $notes = null) use ($C, &$T, $saisisseurs, $reference) {
        if (empty($C[$k]) || empty($T[$k][$tarif])) return null;
        [$tier, $montant] = $T[$k][$tarif];
        return df_ins('cotisation_payments', [
            'campaign_id' => $C[$k], 'tier_id' => $tier, 'org_id' => DF::$org,
            'adherent_id' => $p['id'] ?? null,
            'payer_name' => $p['nom'] ?? trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
            'payer_email' => $p['email'] ?? null,
            'amount' => $montant, 'currency' => 'EUR', 'payment_method' => $methode, 'status' => $statut,
            'reference' => $ref ?? ($statut === 'paid' ? $reference($methode, $quand) : null),
            'notes' => $notes,
            'paid_at' => $statut === 'paid' || $statut === 'refunded' ? $quand : null,
            'created_by' => df_choix($saisisseurs),
            'created_at' => $statut === 'paid' ? date('Y-m-d H:i:s', strtotime($quand) + df_entre(0, 120)) : $quand,
        ]);
    };

    /** Jour de paiement saisonnier entre $min et $max (offsets en jours, négatifs). */
    $saison = function (int $min, int $max): int {
        // Poids par mois : renouvellements de janvier, rentrée de septembre.
        $poids = [1 => 22, 2 => 6, 3 => 6, 4 => 5, 5 => 5, 6 => 4, 7 => 2, 8 => 3, 9 => 24, 10 => 9, 11 => 5, 12 => 3];
        $jours = [];
        for ($j = $min; $j <= $max; $j++) {
            $m = (int)date('n', strtotime(df_j($j)));
            $jours[] = [$j, $poids[$m]];
        }
        $tot = array_sum(array_column($jours, 1));
        $r = df_entre(1, max(1, $tot));
        foreach ($jours as [$j, $w]) { $r -= $w; if ($r <= 0) return $j; }
        return $max;
    };

    $comptes = array_flip(array_map(fn($k) => DF::$u[$k], ['admin', 'salarie', 'benevole', 'membre']));
    $doublonFait = false;
    $nbPendingA = 0;
    $anciens = array_flip(DF::$g['anciens'] ?? []);
    $corbeille = array_flip(DF::$g['corbeille'] ?? []);
    $nouveaux = array_flip(DF::$g['nouveaux'] ?? []);

    foreach ($personnes as $p) {
        $id = (int)$p['id'];
        if (!$p['adhesion_valid_until'] || isset($corbeille[$id])) continue;
        $p['nom'] = trim($p['first_name'] . ' ' . $p['last_name']);
        $fin = $p['adhesion_valid_until'];
        $paye = (int)round((strtotime($fin) - DF::$t0) / 86400) - 364;   // offset du dernier paiement
        $debut = (int)round((strtotime($p['adhesion_date']) - DF::$t0) / 86400);

        // Les « à jour » de longue date : paiement redistribué selon la saison.
        if ($paye >= -319 && $paye <= -35 && !isset($nouveaux[$id]) && !isset($comptes[$id])) {
            $min = max(-319, $debut);
            if ($min <= -35) {
                $paye = $saison($min, -35);
                df_maj('users', ['adhesion_valid_until' => df_j($paye + 364)], 'id = ?', [$id]);
            }
        }

        $quand = df_jh($paye, sprintf('%02d:%02d:%02d', df_entre(9, 19), df_entre(0, 59), df_entre(0, 59)));
        $k = $campagneDe($quand);

        // Comptes de connexion : un parcours précis, racontable en démo.
        if ($id === (DF::$u['admin'] ?? 0)) {
            $payer($k ?? 'A', 'bienfaiteur', $p, $quand, 'bank', 'paid', 'VIR SEPA LAURENT S.');
        } elseif ($id === (DF::$u['membre'] ?? 0)) {
            $payer($k ?? 'A', 'solidaire', $p, $quand, 'cash');
        } elseif (isset($comptes[$id])) {
            $payer($k ?? 'A', 'individuelle', $p, $quand, 'check');
        } elseif ($k) {
            $tarif = $tarifAdh();
            $methode = $methodeAdh();
            $payer($k, $tarif, $p, $quand, $methode);
            // Démo des anomalies : un adhérent a réglé deux fois (virement puis chèque).
            if (!$doublonFait && $k === 'A' && $tarif === 'individuelle' && $paye < -40) {
                $payer('A', 'individuelle', $p, df_jh($paye + 6, '18:05'), 'check');
                $doublonFait = true;
            }
        }

        // Paiement de l'année précédente, pour les fidèles.
        $avant = $paye - 365 + df_entre(-15, 15);
        if ($avant >= $debut && !isset($nouveaux[$id])) {
            $q2 = df_jh($avant, sprintf('%02d:%02d', df_entre(9, 19), df_entre(0, 59)));
            if ($k2 = $campagneDe($q2)) $payer($k2, isset($comptes[$id]) ? 'individuelle' : $tarifAdh(), $p, $q2, $methodeAdh());
        }

        // Échéance proche ou passée : certains ont déjà annoncé leur règlement.
        $jFin = (int)round((strtotime($fin) - DF::$t0) / 86400);
        if (!isset($anciens[$id]) && $jFin < 31 && $jFin > -120 && $nbPendingA < 12 && df_proba($jFin >= 0 ? 0.4 : 0.3)) {
            $creation = max(-df_entre(1, 25), (int)round((strtotime("$Y-01-01") - DF::$t0) / 86400));
            $m = df_choix(['check', 'bank', 'cash']);
            $payer('A', df_choix(['solidaire', 'individuelle', 'individuelle']), $p,
                   df_jh($creation, sprintf('%02d:%02d', df_entre(9, 18), df_entre(0, 59))), $m, 'pending', null,
                   ['check' => 'Chèque remis à la permanence du mardi, à déposer.',
                    'bank'  => 'Virement annoncé par e-mail.',
                    'cash'  => 'Promet de régler à l\'atelier de jeudi.'][$m]);
            $nbPendingA++;
        }
    }

    // Structures partenaires, sans compte adhérent.
    foreach ([['Numéris Formation SARL', 'partenariats.numeris'], ['Centre social Les Franciscaines', 'partenariats.franciscaines'],
              ['Ville de Démoville — service jeunesse', 'partenariats.demoville'], ['Boulangerie Durand & Fils', 'partenariats.durand']] as $i => [$nom, $local]) {
        $j = $saison(-(int)date('z', DF::$t0), -2);
        $payer('A', 'structure', ['nom' => $nom, 'email' => $local . '@' . DF_DOMAINE], df_jh($j, '11:0' . $i), 'bank');
    }

    // Quelques situations de la vraie vie.
    $quelquun = df_choix(DF::$g['adherents']);
    $pp = ['id' => $quelquun, 'nom' => df_val("SELECT CONCAT(first_name,' ',last_name) FROM users WHERE id = ?", [$quelquun]),
           'email' => df_val("SELECT email FROM users WHERE id = ?", [$quelquun])];
    $j = max(-60, -(int)date('z', DF::$t0));
    $payer('A', 'individuelle', $pp, df_jh($j, '10:20'), 'bank', 'cancelled', null, 'Saisie en double — annulé par la trésorière.');
    $pp2 = ['id' => df_choix(DF::$g['adherents'])];
    $pp2['nom'] = df_val("SELECT CONCAT(first_name,' ',last_name) FROM users WHERE id = ?", [$pp2['id']]);
    $payer('A', 'individuelle', $pp2, df_jh(max(-90, -(int)date('z', DF::$t0)), '15:00'), 'check', 'refunded', 'CHQ 4471203',
           'Déménagement à Lyon — cotisation remboursée.');
    $payer('B', 'individuelle', $pp2, ($Y - 1) . '-03-14 17:30:00', 'check', 'cancelled', null, 'Chèque rejeté — réglé ensuite par virement.');

    // ---------- Campagne Ateliers (C) ----------
    $debutC = (int)round((strtotime("$S-09-01") - DF::$t0) / 86400);
    $apprenants = df_echantillon(DF::$g['apprenants'], 55);
    $n = 0;
    foreach ($apprenants as $i => $uid) {
        $u = DF::$pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ?");
        $u->execute([$uid]);
        $p = $u->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;
        $atelier = $uid === (DF::$u['membre'] ?? 0) ? 'numerique'
                 : df_choix(['numerique', 'numerique', 'fle', 'fle', 'emploi', 'pix', 'qf', 'qf']);
        if ($i < 46 || $uid === (DF::$u['membre'] ?? 0)) {
            // Dense pendant les trois premières semaines de septembre.
            $fen = min(-1, $debutC + (df_proba(0.7) ? 21 : 400));
            $j = df_entre(min($debutC, -1), max(min($debutC, -1), $fen));
            $methode = $uid === (DF::$u['membre'] ?? 0) ? 'other' : df_choix(['cash', 'check', 'bank', 'other', 'other', 'cash', 'check']);
            $ref = $uid === (DF::$u['membre'] ?? 0) ? "APTIC-$S-00412" : null;
            $notes = $methode === 'other' ? df_choix(['Pass Numérique APTIC', 'Prise en charge France Travail (AIF)', 'Prise en charge CCAS']) : null;
            if ($uid === (DF::$u['membre'] ?? 0)) { $j = min(-1, $debutC + 7); $notes = 'Pass Numérique APTIC'; }
            $payer('C', $atelier, $p, df_jh($j, sprintf('%02d:%02d', df_entre(9, 19), df_entre(0, 59))), $methode, 'paid', $ref, $notes);
            $n++;
        } else {
            $payer('C', $atelier, $p, df_jh(-df_entre(1, 20), '14:00'), df_choix(['check', 'other']), 'pending', null,
                   df_choix(['Prise en charge France Travail — accord attendu.', 'Paiement en 3 fois — 1er chèque reçu.']));
        }
    }

    // ---------- Relances d'adhésion déjà envoyées ----------
    if (df_a_table('asso_membership_reminders')) {
        $q = DF::$pdo->prepare("SELECT id, adhesion_valid_until FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL
                                   AND adhesion_valid_until < ? ORDER BY adhesion_valid_until DESC");
        $q->execute([DF::$org, df_j(15)]);
        $rel = function (int $uid, int $stage, int $j) use ($treso) {
            df_ins('asso_membership_reminders', ['org_id' => DF::$org, 'user_id' => $uid, 'stage' => $stage, 'channel' => 'email',
                                                 'sent_by_user_id' => df_proba(0.7) ? $treso : null, 'sent_at' => df_jh($j, '09:1' . $stage)]);
        };
        $cpt = ['bientot' => 0, 's2' => 0, 's3' => 0, 'fin' => 0];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $jf = (int)round((strtotime($r['adhesion_valid_until']) - DF::$t0) / 86400);
            $uid = (int)$r['id'];
            if ($jf <= -45 && $cpt['fin'] < 1) { $rel($uid, 1, $jf + 1); $rel($uid, 2, $jf + 15); $rel($uid, 3, $jf + 31); $cpt['fin']++; }
            elseif ($jf >= 0 && $cpt['bientot'] < 2) { $rel($uid, 1, -3); $cpt['bientot']++; }
            elseif ($jf <= -14 && $jf > -30 && $cpt['s2'] < 3) { $rel($uid, 1, min(-7, $jf + 1)); $cpt['s2']++; }
            elseif ($jf <= -30 && $cpt['s3'] < 4) { $rel($uid, 1, $jf + 1); $rel($uid, 2, max($jf + 15, -9)); $cpt['s3']++; }
        }
    }

    // ---------- Réglages ----------
    // Relances automatiques coupées : sinon cron-relances écrirait toutes les 10 minutes.
    df_ins('org_relance_prefs', ['org_id' => DF::$org, 'auto_invoices' => 0, 'auto_memberships' => 0,
                                 'max_stage' => 2, 'min_gap_days' => 7, 'updated_at' => df_jh(-60, '10:00')]);
    df_ins('org_payment_settings', [
        'org_id' => DF::$org, 'bank_holder' => 'Association DEMO F — Formation & Insertion',
        'bank_iban' => 'FR76 3000 6000 0112 3456 7890 189', 'bank_bic' => 'AGRIFRPP', 'check_payable_to' => 'Association DEMO F',
        'stripe_enabled' => 0, 'stripe_mode' => 'test',
        'stripe_publishable_key' => null, 'stripe_secret_key' => null, 'stripe_webhook_secret' => null,
    ]);
}
