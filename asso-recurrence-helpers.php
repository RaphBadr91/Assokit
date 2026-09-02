<?php
/**
 * asso-recurrence-helpers.php
 * --------------------------------------------------------------
 * Helpers pour récurrences de factures — VERSION CORRIGÉE
 * Aligné avec le schéma réel de asso_invoices / asso_invoice_lines / asso_invoice_recurrences
 *
 * Format numéro de facture : {slug-org}-{year}-{sequence_6_digits}
 *   ex : latitude91-2026-000005
 * --------------------------------------------------------------
 */

// --------------------------------------------------------------
// Calcul prochaine date selon fréquence
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_next_date')) {
    /**
     * @param string $freq daily|weekly|monthly|quarterly|yearly
     * @param int $interval nombre d'unités à ajouter
     * @param string $from_date date de départ (Y-m-d)
     * @param ?int $day_of_month forcer un jour du mois (1-31, ajusté si trop grand)
     */
    function ak_recurrence_next_date(string $freq, int $interval, string $from_date, ?int $day_of_month = null): string {
        $interval = max(1, $interval);
        $ts = strtotime($from_date);
        if ($ts === false) $ts = time();

        switch ($freq) {
            case 'daily':     $next = strtotime("+{$interval} day", $ts); break;
            case 'weekly':    $next = strtotime("+{$interval} week", $ts); break;
            case 'quarterly': $next = strtotime("+" . ($interval * 3) . " month", $ts); break;
            case 'yearly':    $next = strtotime("+{$interval} year", $ts); break;
            case 'monthly':
            default:
                $next = strtotime("+{$interval} month", $ts);
        }

        // Jour du mois forcé (1-31)
        if ($day_of_month !== null && $day_of_month >= 1 && $day_of_month <= 31) {
            $year  = (int)date('Y', $next);
            $month = (int)date('n', $next);
            $last_day = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            $forced_day = min($day_of_month, $last_day);
            $next = mktime(0, 0, 0, $month, $forced_day, $year);
        }

        return date('Y-m-d', $next);
    }
}

// --------------------------------------------------------------
// Récupérer le slug de l'organisation pour le numéro de facture
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_get_org_slug')) {
    function ak_recurrence_get_org_slug(PDO $pdo, int $org_id): string {
        try {
            $st = $pdo->prepare("SELECT slug FROM organizations WHERE id = :o LIMIT 1");
            $st->execute([':o' => $org_id]);
            $slug = (string)$st->fetchColumn();
            if ($slug !== '') return $slug;
        } catch (Throwable $e) { /* silent */ }

        // Fallback : tenter de retrouver depuis une facture existante
        try {
            $st = $pdo->prepare("SELECT invoice_number FROM asso_invoices WHERE org_id = :o ORDER BY id DESC LIMIT 1");
            $st->execute([':o' => $org_id]);
            $num = (string)$st->fetchColumn();
            if ($num && preg_match('/^(.+?)-\d{4}-\d+$/', $num, $m)) return $m[1];
        } catch (Throwable $e) { /* silent */ }

        return 'org' . $org_id;
    }
}

// --------------------------------------------------------------
// Génère le prochain numéro de facture pour une org
// Format : {slug}-{year}-{sequence_padded_6}
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_generate_invoice_number')) {
    function ak_recurrence_generate_invoice_number(PDO $pdo, int $org_id, int $year): array {
        // Source de vérité UNIQUE : le compteur atomique org_invoice_settings
        // (piste d'audit fiable — même numérotation que la voie manuelle,
        //  séquence continue sans trou ni doublon).
        if (!function_exists('ak_asso_invoice_next_number_parts') && is_file(__DIR__ . '/asso-invoice-helpers.php')) {
            require_once __DIR__ . '/asso-invoice-helpers.php';
        }
        if (function_exists('ak_asso_invoice_next_number_parts')) {
            $p = ak_asso_invoice_next_number_parts($pdo, $org_id);
            return ['number' => $p['number'], 'sequence' => $p['sequence']];
        }

        // Fallback (ne devrait pas être atteint) — ancienne logique MAX+1
        $slug = ak_recurrence_get_org_slug($pdo, $org_id);
        $next_seq = 1;
        try {
            $st = $pdo->prepare("SELECT MAX(invoice_sequence) FROM asso_invoices WHERE org_id = :o AND invoice_year = :y");
            $st->execute([':o' => $org_id, ':y' => $year]);
            $max = (int)$st->fetchColumn();
            $next_seq = $max + 1;
        } catch (Throwable $e) { /* on garde 1 */ }

        $number = $slug . '-' . $year . '-' . str_pad((string)$next_seq, 6, '0', STR_PAD_LEFT);
        return ['number' => $number, 'sequence' => $next_seq];
    }
}

// --------------------------------------------------------------
// Génère un UUID v4
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_uuid_v4')) {
    function ak_recurrence_uuid_v4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

// --------------------------------------------------------------
// Charge les lignes d'une facture source
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_load_source_lines')) {
    function ak_recurrence_load_source_lines(PDO $pdo, int $source_invoice_id): array {
        try {
            $st = $pdo->prepare("
                SELECT line_order, designation, quantity, unit_price_ht_cents, vat_rate,
                       total_ht_cents, total_vat_cents, total_ttc_cents
                FROM asso_invoice_lines
                WHERE invoice_id = :id
                ORDER BY line_order ASC, id ASC
            ");
            $st->execute([':id' => $source_invoice_id]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[recurrence load_source_lines] ' . $e->getMessage());
            return [];
        }
    }
}

// --------------------------------------------------------------
// Charge la facture source (pour copier snapshots + métadonnées)
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_load_source_invoice')) {
    function ak_recurrence_load_source_invoice(PDO $pdo, int $source_invoice_id, int $org_id): ?array {
        try {
            $st = $pdo->prepare("SELECT * FROM asso_invoices WHERE id = :id AND org_id = :o LIMIT 1");
            $st->execute([':id' => $source_invoice_id, ':o' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }
}

// --------------------------------------------------------------
// Reconstruit les snapshots si pas de source disponible
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_build_snapshots_from_scratch')) {
    function ak_recurrence_build_snapshots_from_scratch(PDO $pdo, int $org_id, int $client_id): array {
        $emitter = ['id' => $org_id, 'name' => 'Notre association'];
        $client  = ['id' => $client_id, 'org_id' => $org_id];

        try {
            $st = $pdo->prepare("SELECT * FROM organizations WHERE id = :o LIMIT 1");
            $st->execute([':o' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) $emitter = $row;
        } catch (Throwable $e) {}

        try {
            $st = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id AND org_id = :o LIMIT 1");
            $st->execute([':id' => $client_id, ':o' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) $client = $row;
        } catch (Throwable $e) {}

        return [
            'emitter' => json_encode($emitter, JSON_UNESCAPED_UNICODE),
            'client'  => json_encode($client,  JSON_UNESCAPED_UNICODE),
        ];
    }
}

// --------------------------------------------------------------
// FONCTION PRINCIPALE : génère une facture pour une récurrence
//
// @return array ['ok' => bool, 'invoice_id' => ?int, 'invoice_number' => ?string, 'error' => ?string]
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_generate_invoice')) {
    function ak_recurrence_generate_invoice(PDO $pdo, array $recurrence): array {
        $rec_id   = (int)$recurrence['id'];
        $org_id   = (int)$recurrence['org_id'];
        $client_id = (int)($recurrence['client_id'] ?? 0);
        $source_id = (int)($recurrence['source_invoice_id'] ?? 0);

        // 1. Récupère la source (si dispo) pour copier snapshots + meta
        $source = null;
        if ($source_id > 0) {
            $source = ak_recurrence_load_source_invoice($pdo, $source_id, $org_id);
        }

        // 2. Récupère les lignes (priorité : source → template_data JSON)
        $lines = [];
        if ($source) {
            $lines = ak_recurrence_load_source_lines($pdo, $source_id);
        }
        if (empty($lines) && !empty($recurrence['template_data'])) {
            $td = json_decode((string)$recurrence['template_data'], true);
            if (is_array($td) && !empty($td['lines']) && is_array($td['lines'])) {
                foreach ($td['lines'] as $tl) {
                    $qty   = (float)($tl['quantity'] ?? 1);
                    $up    = (int)  ($tl['unit_price_ht_cents'] ?? round((float)($tl['unit_price_ht'] ?? 0) * 100));
                    $vat   = isset($tl['vat_rate']) ? (float)$tl['vat_rate'] : null;
                    $ht    = (int)round($qty * $up);
                    $vatC  = $vat !== null ? (int)round($ht * $vat / 100) : 0;
                    $ttc   = $ht + $vatC;
                    $lines[] = [
                        'line_order'          => (int)($tl['line_order'] ?? count($lines)),
                        'designation'         => (string)($tl['designation'] ?? ''),
                        'quantity'            => $qty,
                        'unit_price_ht_cents' => $up,
                        'vat_rate'            => $vat,
                        'total_ht_cents'      => $ht,
                        'total_vat_cents'     => $vatC,
                        'total_ttc_cents'     => $ttc,
                    ];
                }
            }
        }
        if (empty($lines)) {
            return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null,
                    'error' => "Aucune ligne disponible pour la récurrence #{$rec_id} (ni source_invoice_id, ni template_data exploitable)."];
        }

        // 3. Calcule les totaux globaux
        $total_ht  = 0; $total_vat = 0; $total_ttc = 0;
        foreach ($lines as $l) {
            $total_ht  += (int)$l['total_ht_cents'];
            $total_vat += (int)$l['total_vat_cents'];
            $total_ttc += (int)$l['total_ttc_cents'];
        }

        // 4. Snapshots
        if ($source) {
            $emitter_snapshot = (string)($source['emitter_snapshot'] ?? '{}');
            $client_snapshot  = (string)($source['client_snapshot']  ?? '{}');
        } else {
            $built = ak_recurrence_build_snapshots_from_scratch($pdo, $org_id, $client_id);
            $emitter_snapshot = $built['emitter'];
            $client_snapshot  = $built['client'];
        }

        // 5. Numéro de facture
        $today = new DateTime('now');
        $year  = (int)$today->format('Y');
        $num   = ak_recurrence_generate_invoice_number($pdo, $org_id, $year);

        // 6. Dates : émission aujourd'hui, échéance = même delta que la source ou +30j
        $issued_at = $today->format('Y-m-d 00:00:00');
        $due_days = 30;
        if ($source && !empty($source['issued_at']) && !empty($source['due_at'])) {
            try {
                $a = new DateTime($source['issued_at']);
                $b = new DateTime($source['due_at']);
                $delta = (int)$a->diff($b)->format('%a');
                if ($delta > 0 && $delta < 365) $due_days = $delta;
            } catch (Throwable $e) {}
        }
        // Échéance à 23:59:59 comme partout ailleurs (sinon « en retard » un jour trop tôt)
        $due_at = (clone $today)->modify("+{$due_days} day")->format('Y-m-d 23:59:59');

        // 7. Champs additionnels
        $description     = trim((string)($recurrence['title'] ?? ''));
        if ($description === '' && $source) $description = (string)($source['description'] ?? '');
        $currency        = (string)($recurrence['currency'] ?? ($source['currency'] ?? 'EUR'));
        $auto_send       = !empty($recurrence['auto_send']);
        $status          = $auto_send ? 'pending' : 'draft';
        $dunning_enabled = $source ? (int)($source['dunning_enabled'] ?? 1) : 1;

        // 8. INSERT de la facture (transaction pour garantir l'intégrité)
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare("
                INSERT INTO asso_invoices (
                    recurrence_id, org_id, client_id,
                    public_uuid, invoice_number, invoice_year, invoice_sequence,
                    issued_at, due_at,
                    amount_ht_cents, amount_vat_cents, amount_ttc_cents, currency,
                    status,
                    emitter_snapshot, client_snapshot,
                    dunning_enabled, dunning_level,
                    description,
                    created_at, updated_at,
                    created_by_user_id
                ) VALUES (
                    :rec_id, :org_id, :client_id,
                    :uuid, :num, :year, :seq,
                    :issued_at, :due_at,
                    :ht, :vat, :ttc, :curr,
                    :status,
                    :emitter, :client,
                    :dunning_en, 0,
                    :desc,
                    NOW(), NOW(),
                    :created_by
                )
            ");
            $st->execute([
                ':rec_id'    => $rec_id,
                ':org_id'    => $org_id,
                ':client_id' => $client_id ?: null,
                ':uuid'      => ak_recurrence_uuid_v4(),
                ':num'       => $num['number'],
                ':year'      => $year,
                ':seq'       => $num['sequence'],
                ':issued_at' => $issued_at,
                ':due_at'    => $due_at,
                ':ht'        => $total_ht,
                ':vat'       => $total_vat,
                ':ttc'       => $total_ttc,
                ':curr'      => $currency,
                ':status'    => $status,
                ':emitter'   => $emitter_snapshot,
                ':client'    => $client_snapshot,
                ':dunning_en'=> $dunning_enabled,
                ':desc'      => $description,
                ':created_by'=> $recurrence['created_by'] ?? null,
            ]);
            $invoice_id = (int)$pdo->lastInsertId();

            // 9. INSERT des lignes
            $stL = $pdo->prepare("
                INSERT INTO asso_invoice_lines
                    (invoice_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
                     total_ht_cents, total_vat_cents, total_ttc_cents)
                VALUES
                    (:inv, :ord, :des, :qty, :up, :vrate, :tht, :tvat, :tttc)
            ");
            foreach ($lines as $i => $l) {
                $stL->execute([
                    ':inv'   => $invoice_id,
                    ':ord'   => (int)($l['line_order'] ?? $i),
                    ':des'   => mb_substr((string)$l['designation'], 0, 500),
                    ':qty'   => (float)$l['quantity'],
                    ':up'    => (int)$l['unit_price_ht_cents'],
                    ':vrate' => isset($l['vat_rate']) ? (float)$l['vat_rate'] : null,
                    ':tht'   => (int)$l['total_ht_cents'],
                    ':tvat'  => (int)$l['total_vat_cents'],
                    ':tttc'  => (int)$l['total_ttc_cents'],
                ]);
            }

            $pdo->commit();

            return [
                'ok'             => true,
                'invoice_id'     => $invoice_id,
                'invoice_number' => $num['number'],
                'error'          => null,
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[ak_recurrence_generate_invoice] ' . $e->getMessage());
            return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null,
                    'error' => $e->getMessage()];
        }
    }
}

// --------------------------------------------------------------
// CRON : exécute toutes les récurrences dues
//
// @return array ['generated' => int, 'failed' => int, 'errors' => string[], 'invoices' => int[]]
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_run_due')) {
    function ak_recurrence_run_due(PDO $pdo): array {
        $stats = ['generated' => 0, 'failed' => 0, 'errors' => [], 'invoices' => []];

        try {
            $st = $pdo->prepare("
                SELECT * FROM asso_invoice_recurrences
                WHERE status = 'active'
                  AND next_run_date <= CURRENT_DATE()
                ORDER BY next_run_date ASC, id ASC
                LIMIT 50
            ");
            $st->execute();
            $due = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $stats['errors'][] = 'Lecture récurrences : ' . $e->getMessage();
            return $stats;
        }

        foreach ($due as $rec) {
            $rec_id = (int)$rec['id'];

            // Vérifie max_occurrences
            if (!empty($rec['max_occurrences']) && (int)$rec['occurrences_count'] >= (int)$rec['max_occurrences']) {
                try {
                    $pdo->prepare("UPDATE asso_invoice_recurrences SET status = 'ended' WHERE id = :id")->execute([':id' => $rec_id]);
                } catch (Throwable $e) {}
                continue;
            }

            // Vérifie end_date
            if (!empty($rec['end_date']) && strtotime($rec['end_date']) < strtotime('today')) {
                try {
                    $pdo->prepare("UPDATE asso_invoice_recurrences SET status = 'ended' WHERE id = :id")->execute([':id' => $rec_id]);
                } catch (Throwable $e) {}
                continue;
            }

            // Génère
            $res = ak_recurrence_generate_invoice($pdo, $rec);

            if ($res['ok']) {
                $stats['generated']++;
                $stats['invoices'][] = $res['invoice_id'];

                // Calcule prochaine date
                $next = ak_recurrence_next_date(
                    (string)$rec['frequency'],
                    (int)$rec['interval_count'],
                    date('Y-m-d'),
                    !empty($rec['day_of_month']) ? (int)$rec['day_of_month'] : null
                );

                try {
                    $pdo->prepare("
                        UPDATE asso_invoice_recurrences
                        SET last_run_date = CURRENT_DATE(),
                            next_run_date = :next,
                            occurrences_count = occurrences_count + 1,
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([':next' => $next, ':id' => $rec_id]);
                } catch (Throwable $e) {
                    $stats['errors'][] = "Update récurrence #{$rec_id} : " . $e->getMessage();
                }
            } else {
                $stats['failed']++;
                $stats['errors'][] = "Récurrence #{$rec_id} : " . ($res['error'] ?? 'inconnue');
            }
        }

        return $stats;
    }
}

// --------------------------------------------------------------
// Helpers UI
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_status_label')) {
    function ak_recurrence_status_label(string $status): array {
        return [
            'active'    => ['label' => 'Active',     'color' => '#059669', 'bg' => '#D1FAE5'],
            'paused'    => ['label' => 'En pause',   'color' => '#92400E', 'bg' => '#FEF3C7'],
            'ended'     => ['label' => 'Terminée',   'color' => '#475569', 'bg' => '#F1F5F9'],
            'cancelled' => ['label' => 'Annulée',    'color' => '#991B1B', 'bg' => '#FEE2E2'],
        ][$status] ?? ['label' => ucfirst($status), 'color' => '#475569', 'bg' => '#F1F5F9'];
    }
}

if (!function_exists('ak_recurrence_frequency_label')) {
    function ak_recurrence_frequency_label(string $freq, int $interval = 1): string {
        $interval = max(1, $interval);
        $singular = [
            'daily'     => 'jour',
            'weekly'    => 'semaine',
            'monthly'   => 'mois',
            'quarterly' => 'trimestre',
            'yearly'    => 'an',
        ];
        $plural = [
            'daily'     => 'jours',
            'weekly'    => 'semaines',
            'monthly'   => 'mois',
            'quarterly' => 'trimestres',
            'yearly'    => 'ans',
        ];
        $unit = ($interval === 1) ? ($singular[$freq] ?? $freq) : ($plural[$freq] ?? $freq);
        return $interval === 1 ? "Tous les {$unit}" : "Tous les {$interval} {$unit}";
    }
}

// ==============================================================
// === FONCTIONS AJOUTÉES (PATCH FIX mon-asso-recurrences) ===
// ==============================================================

// --------------------------------------------------------------
// Charge une récurrence par ID + vérification org
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_load')) {
    /**
     * Charge une récurrence en vérifiant qu'elle appartient bien à l'org.
     *
     * @param PDO $pdo
     * @param int $rec_id ID de la récurrence
     * @param int $org_id ID de l'organisation (sécurité)
     * @return array|null La récurrence (avec ses données) ou null si non trouvée
     */
    function ak_recurrence_load(PDO $pdo, int $rec_id, int $org_id): ?array {
        if ($rec_id <= 0 || $org_id <= 0) return null;
        try {
            $st = $pdo->prepare("
                SELECT r.*, c.display_name AS client_name
                FROM asso_invoice_recurrences r
                LEFT JOIN asso_clients c ON c.id = r.client_id
                WHERE r.id = :id AND r.org_id = :o
                LIMIT 1
            ");
            $st->execute([':id' => $rec_id, ':o' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[ak_recurrence_load] ' . $e->getMessage());
            return null;
        }
    }
}

// --------------------------------------------------------------
// Alias de ak_recurrence_next_date (utilisé dans recurrence-save)
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_calc_next_date')) {
    /**
     * Calcule la prochaine date d'exécution d'une récurrence.
     * Alias plus explicite de ak_recurrence_next_date()
     *
     * @param string $freq daily|weekly|monthly|quarterly|yearly
     * @param int $interval nombre d'unités
     * @param string $from_date date de référence (Y-m-d)
     * @param ?int $day_of_month forcer un jour précis du mois
     * @return string date au format Y-m-d
     */
    function ak_recurrence_calc_next_date(string $freq, int $interval, string $from_date, ?int $day_of_month = null): string {
        if (function_exists('ak_recurrence_next_date')) {
            return ak_recurrence_next_date($freq, $interval, $from_date, $day_of_month);
        }
        // Fallback minimal
        $ts = strtotime($from_date);
        if ($ts === false) $ts = time();
        return date('Y-m-d', strtotime("+{$interval} month", $ts));
    }
}

// --------------------------------------------------------------
// Compteurs par statut (pour KPI dashboard récurrences)
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_count_by_status')) {
    /**
     * Compte les récurrences par statut pour une organisation.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return array ['active' => N, 'paused' => N, 'ended' => N, 'cancelled' => N, 'total' => N]
     */
    function ak_recurrence_count_by_status(PDO $pdo, int $org_id): array {
        $counts = [
            'active'    => 0,
            'paused'    => 0,
            'ended'     => 0,
            'cancelled' => 0,
            'total'     => 0,
        ];

        try {
            $st = $pdo->prepare("
                SELECT status, COUNT(*) AS cnt
                FROM asso_invoice_recurrences
                WHERE org_id = :o
                GROUP BY status
            ");
            $st->execute([':o' => $org_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $status = (string)$row['status'];
                $cnt = (int)$row['cnt'];
                if (isset($counts[$status])) {
                    $counts[$status] = $cnt;
                }
                $counts['total'] += $cnt;
            }
        } catch (Throwable $e) {
            error_log('[ak_recurrence_count_by_status] ' . $e->getMessage());
        }

        return $counts;
    }
}

// --------------------------------------------------------------
// Compte les factures générées ce mois-ci par les récurrences
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_count_invoices_this_month')) {
    /**
     * Compte les factures générées ce mois-ci par les récurrences.
     *
     * @param PDO $pdo
     * @param int $org_id
     * @return int
     */
    function ak_recurrence_count_invoices_this_month(PDO $pdo, int $org_id): int {
        try {
            // Méthode 1 : via la table asso_invoice_recurrence_runs
            $st = $pdo->prepare("
                SELECT COUNT(*) AS cnt
                FROM asso_invoice_recurrence_runs runs
                INNER JOIN asso_invoice_recurrences r ON r.id = runs.recurrence_id
                WHERE r.org_id = :o
                  AND runs.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
                  AND runs.created_at < DATE_FORMAT(NOW() + INTERVAL 1 MONTH, '%Y-%m-01')
            ");
            $st->execute([':o' => $org_id]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            // Fallback : si la table runs n'existe pas, on compte via asso_invoices.recurrence_id
            try {
                $st = $pdo->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM asso_invoices
                    WHERE org_id = :o
                      AND recurrence_id IS NOT NULL
                      AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
                      AND created_at < DATE_FORMAT(NOW() + INTERVAL 1 MONTH, '%Y-%m-01')
                ");
                $st->execute([':o' => $org_id]);
                return (int)$st->fetchColumn();
            } catch (Throwable $e2) {
                error_log('[ak_recurrence_count_invoices_this_month] ' . $e2->getMessage());
                return 0;
            }
        }
    }
}
