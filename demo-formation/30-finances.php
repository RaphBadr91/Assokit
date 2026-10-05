<?php
/**
 * 30 — Pilotage financier : notes de frais, solde de trésorerie pour les
 * prévisions, anomalies « ignorées » par la trésorière.
 * ------------------------------------------------------------------
 * Les écrans Comptabilité, Export FEC, Factur-X, Relances et Prévisions
 * n'ont pas de tables propres : ils lisent les factures, les cotisations
 * et les dépenses des modules précédents. On ajoute ici ce qui leur
 * manque, puis on vérifie que le moteur d'anomalies ne trouve que les
 * anomalies voulues (le reste serait un faux positif devant un prospect).
 * ------------------------------------------------------------------
 */

/** Les tables des notes de frais sont créées par l'application à la première visite. */
function df_pre_finances(): void
{
    if (!df_a_table('expense_reports') && is_file(dirname(__DIR__) . '/migrations/2026-08-20-notes-de-frais.sql')) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^\s*--.*$/m', '', file_get_contents(dirname(__DIR__) . '/migrations/2026-08-20-notes-de-frais.sql'))))) as $sql) {
            if (stripos($sql, 'CREATE TABLE') === 0) DF::$pdo->exec($sql);
        }
    }
}

function df_seed_finances(): void
{
    $u = DF::$u;
    $treso = $u['tresoriere'] ?? $u['admin'];

    // ---------- Notes de frais ----------
    if (df_a_table('expense_reports')) {
        $ik = function (int $cv, int $km): int {
            if (function_exists('nf_ik_amount_cents')) return nf_ik_amount_cents($cv, $km);
            $taux = [3 => 0.529, 4 => 0.606, 5 => 0.636, 6 => 0.665, 7 => 0.697];
            return (int)round($km * ($taux[max(3, min(7, $cv))]) * 100);
        };
        @include_once dirname(__DIR__) . '/notes-frais-engine.php';
        $moisPrec = df_mois_fr(df_mois(-1, 1));
        $notes = [
            // [personne, titre, statut, jours, lignes [[mode, cat, description, TTC €|km, TVA €|cv, jour]], décideur, motif]
            ['salarie', "Déplacements formateurs — $moisPrec", 'submitted', -3,
                [['mileage', 'mileage', 'Massy → Démoville (formation agents) A/R', 34, 5, -20], ['mileage', 'mileage', 'Massy → Les Ulis (FLE pro) A/R', 22, 5, -15], ['mileage', 'mileage', 'Massy → Antony (clinique) A/R', 18, 5, -9]], null, null],
            ['salarie', 'Salon de l\'insertion — Paris', 'approved', -12,
                [['receipt', 'transport', 'RER B aller', 4.30, 0, -14], ['receipt', 'transport', 'RER B retour', 4.30, 0, -14], ['receipt', 'repas', 'Déjeuner sur le salon', 14.90, 1.35, -14]], 'admin', null],
            ['salarie', 'Frais ' . df_mois_fr(df_mois(-3, 1)), 'reimbursed', -80,
                [['receipt', 'fourniture', 'Marqueurs et paperboard', 38.60, 6.43, -84], ['mileage', 'mileage', 'Visite entreprise partenaire', 26, 5, -82]], 'tresoriere', null],
            ['benevole', 'Covoiturage forum emploi', 'reimbursed', -45,
                [['mileage', 'mileage', 'Massy → Évry (forum) aller', 38, 5, -47], ['mileage', 'mileage', 'Évry → Massy (forum) retour', 38, 5, -47]], 'tresoriere', null],
            ['benevole', 'Repas formation bénévoles', 'rejected', -20,
                [['receipt', 'repas', 'Restaurant (12 couverts)', 186.00, 16.91, -22]], 'admin', 'Repas déjà pris en charge par le budget « vie associative ».'],
            ['benevole', 'Fournitures atelier FLE', 'draft', -1,
                [['receipt', 'fourniture', 'Cahiers et stylos pour le groupe A1', 27.45, 4.58, -2]], null, null],
            ['membre', 'Forum de l\'emploi — trajet', 'submitted', -2,
                [['receipt', 'transport', 'Bus aller-retour', 4.20, 0, -4]], null, null],
            ['fle2', 'Manuels prêtés aux apprenants', 'submitted', -5,
                [['receipt', 'fourniture', 'Manuel « Ensemble A2 » (2 ex.)', 49.80, 2.60, -8]], null, null],
            ['insertion', 'Déplacements PLIE', 'submitted', -4,
                [['mileage', 'mileage', 'Massy → Les Ulis (agence) ×3', 66, 6, -10]], null, null],
            ['emploi', 'Impression CV ateliers', 'approved', -16,
                [['receipt', 'fourniture', 'Impression 120 CV couleur', 36.00, 6.00, -18]], 'admin', null],
            ['devoirs', 'Goûters aide aux devoirs', 'rejected', -25,
                [['receipt', 'repas', 'Goûters (mois en cours)', 58.40, 3.04, -27]], 'tresoriere', 'Pris en charge par la boulangerie partenaire.'],
            ['formatrice', 'Matériel atelier seniors', 'draft', 0,
                [['receipt', 'fourniture', 'Stylets tactiles (×10)', 29.90, 4.98, -1]], null, null],
            ['admin', 'Réception partenaires', 'draft', -1,
                [['receipt', 'repas', 'Café d\'accueil comité de pilotage', 42.00, 3.82, -3]], null, null],
            ['formatrice', 'Déplacements jury Pix', 'reimbursed', -30,
                [['mileage', 'mileage', 'Massy → Orsay A/R', 28, 4, -33]], 'tresoriere', null],
            ['insertion', 'Frais ' . df_mois_fr(df_mois(-2, 1)), 'reimbursed', -50,
                [['receipt', 'transport', 'Navigo hebdomadaire', 30.75, 0, -55]], 'tresoriere', null],
            ['fle2', 'Sortie musée — billets accompagnateurs', 'reimbursed', -44,
                [['receipt', 'autre', 'Billets accompagnateurs (2)', 22.00, 0, -48]], 'admin', null],
            ['evenements', 'Décoration soirée de rentrée', 'reimbursed', -9,
                [['receipt', 'fourniture', 'Guirlandes et nappes', 33.70, 5.62, -11]], 'tresoriere', null],
            ['logistique', 'Carburant camionnette', 'reimbursed', -60,
                [['receipt', 'transport', 'Gazole — transport du matériel du forum', 61.20, 10.20, -63]], 'tresoriere', null],
            ['communication', 'Impression flyers portes ouvertes', 'reimbursed', -21,
                [['receipt', 'fourniture', 'Flyers A5 (500 ex.)', 48.00, 8.00, -24]], 'admin', null],
            ['tresoriere', 'Frais bancaires remboursés', 'reimbursed', -70,
                [['receipt', 'autre', 'Frais de remise de chèques', 12.00, 0, -72]], 'admin', null],
        ];
        foreach ($notes as [$k, $titre, $statut, $j, $lignes, $decideur, $motif]) {
            if (empty($u[$k])) continue;
            $creee = df_jh($j - df_entre(1, 3), sprintf('%02d:%02d', df_entre(9, 20), df_entre(0, 59)));
            $decide = $decideur && !in_array($statut, ['draft', 'submitted'], true) ? df_jh(min(0, $j), '10:30') : null;
            $rid = df_ins('expense_reports', [
                'org_id' => DF::$org, 'user_id' => $u[$k], 'title' => $titre, 'status' => $statut, 'total_cents' => 0,
                'note' => $motif, 'decided_by' => $decide ? $u[$decideur] : null, 'decided_at' => $decide,
                'created_at' => $creee, 'updated_at' => $decide ?? $creee,
            ]);
            if (!$rid) continue;
            $total = 0;
            foreach ($lignes as [$mode, $cat, $desc, $a, $b, $jl]) {
                if ($mode === 'mileage') { $montant = $ik((int)$b, (int)$a); $tva = 0; $km = (int)$a; $cv = (int)$b; }
                else { $montant = (int)round($a * 100); $tva = (int)round($b * 100); $km = null; $cv = null; }
                $total += $montant;
                df_ins('expense_report_lines', ['report_id' => $rid, 'org_id' => DF::$org, 'spent_at' => df_j($jl), 'mode' => $mode,
                                                'category' => $cat, 'description' => $desc, 'amount_ttc_cents' => $montant,
                                                'vat_cents' => $tva, 'km' => $km, 'vehicle_cv' => $cv, 'created_at' => $creee]);
            }
            df_maj('expense_reports', ['total_cents' => $total], 'id = ?', [$rid]);
        }
    }

    // ---------- Prévisions : solde de départ ----------
    df_ins('org_forecast_prefs', ['org_id' => DF::$org, 'start_balance_cents' => 4850000,
                                  'balance_set_at' => df_jh(-2, '09:15'), 'updated_at' => df_jh(-2, '09:15')]);

    // ---------- Anomalies : l'avoir est « ignoré » par la trésorière ----------
    $moteur = dirname(__DIR__) . '/anomalies-engine.php';
    if (is_file($moteur) && df_a_table('anomaly_dismissed')) {
        require_once $moteur;
        if (function_exists('ak_anom_scan') && function_exists('ak_anom_hash')) {
            try {
                $trouvees = ak_anom_scan(DF::$pdo, DF::$org, true);
                $categories = [];
                foreach ($trouvees as $f) {
                    $categories[] = $f['category'] ?? '?';
                    if (($f['category'] ?? '') === 'amount-zero' && !empty(DF::$ids['avoir'])
                        && strpos((string)($f['title'] ?? ''), DF::$ids['avoir']) !== false) {
                        df_ins('anomaly_dismissed', ['org_id' => DF::$org, 'finding_hash' => ak_anom_hash($f), 'category' => 'amount-zero',
                                                     'dismissed_by' => $treso, 'dismissed_at' => df_jh(-55, '11:20')]);
                    }
                }
                sort($categories);
                $attendu = ['amount-outlier', 'amount-zero', 'cotis-double', 'invoice-duplicate', 'numbering-gap', 'status-paid-nodate'];
                $en_trop = array_diff(array_unique($categories), $attendu);
                $parCat = array_count_values($categories);
                DF::$rapport['notes'][] = 'Anomalies détectées : ' . implode(', ', array_map(fn($c, $n) => "{$c} ×{$n}", array_keys($parCat), $parCat));
                if ($en_trop) df_erreur('Anomalies non voulues : ' . implode(', ', $en_trop));
            } catch (Throwable $e) {
                DF::$rapport['notes'][] = 'Scan des anomalies impossible : ' . $e->getMessage();
            }
        }
    }
}

/** « septembre 2026 » à partir d'une date Y-m-d. */
function df_mois_fr(string $date): string
{
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $mois[(int)substr($date, 5, 2) - 1] . ' ' . substr($date, 0, 4);
}
