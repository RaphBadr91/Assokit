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
     * @param ?int $day_of_month jour du mois voulu (1-31, ramené au dernier jour d'un mois plus court) ;
     *                           mensuel, trimestriel et annuel seulement. À défaut : le jour de $from_date.
     */
    function ak_recurrence_next_date(string $freq, int $interval, string $from_date, ?int $day_of_month = null): string {
        $interval = max(1, $interval);
        $ts = strtotime($from_date);
        if ($ts === false) $ts = time();

        if ($freq === 'daily')  return date('Y-m-d', strtotime("+{$interval} day", $ts));
        if ($freq === 'weekly') return date('Y-m-d', strtotime("+{$interval} week", $ts));

        // Mensuel / trimestriel / annuel : on avance de N mois depuis le 1er du mois, puis
        // on place le jour voulu, borné à la longueur du mois (31/01 → 28/02 → 31/03).
        // strtotime('+1 month') sur un 31 débordait sur le mois suivant (31/01 → 03/03)
        // avant que le jour soit corrigé : des mois entiers étaient sautés.
        $months = $freq === 'quarterly' ? 3 * $interval : ($freq === 'yearly' ? 12 * $interval : $interval);
        $day = ($day_of_month !== null && $day_of_month >= 1 && $day_of_month <= 31) ? $day_of_month : (int)date('j', $ts);
        $m = (int)date('n', $ts) - 1 + $months;
        $year  = (int)date('Y', $ts) + intdiv($m, 12);
        $month = $m % 12 + 1;
        $last_day = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last_day));
    }
}

// --------------------------------------------------------------
// Date de la prochaine facture après une génération
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_following_date')) {
    /**
     * Prochaine échéance d'une récurrence qui vient de facturer : calculée depuis
     * l'échéance prévue (next_run_date), pas depuis le jour d'exécution — sinon un cron
     * en retard décalait toutes les factures suivantes. Les périodes déjà passées
     * (cron arrêté, récurrence remise en route après une pause) ne sont pas
     * rattrapées en rafale : on avance jusqu'à la première date à venir.
     */
    function ak_recurrence_following_date(array $rec, ?string $today = null): string {
        $today = $today ?: date('Y-m-d');
        $freq  = (string)($rec['frequency'] ?? 'monthly');
        $ic    = max(1, (int)($rec['interval_count'] ?? 1));
        $from  = !empty($rec['next_run_date']) && strtotime((string)$rec['next_run_date']) ? date('Y-m-d', strtotime((string)$rec['next_run_date'])) : $today;

        // Jour du mois : celui choisi dans la récurrence ; à défaut celui de l'échéance prévue,
        // sauf si elle avait été ramenée au dernier jour d'un mois court (récurrence lancée
        // un 31 : 31/01 → 28/02 → 31/03, et non 28/03).
        $dom = !empty($rec['day_of_month']) ? (int)$rec['day_of_month'] : null;
        if ($dom === null && !empty($rec['start_date']) && ($sd = strtotime((string)$rec['start_date']))) {
            $ft = strtotime($from);
            if (date('j', $ft) === date('t', $ft) && (int)date('j', $sd) > (int)date('j', $ft)) $dom = (int)date('j', $sd);
        }

        $next = ak_recurrence_next_date($freq, $ic, $from, $dom);
        for ($guard = 0; $next <= $today && $guard < 1000; $guard++) {
            $next = ak_recurrence_next_date($freq, $ic, $next, $dom);
        }
        return $next;
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
// Lignes enregistrées dans template_data (JSON)
//
// Deux formats coexistent :
//  - HT : designation / unit_price_ht_cents (ou unit_price_ht) / vat_rate ;
//  - TTC : celui qu'écrit le formulaire (mon-asso-recurrence-save.php) :
//    label / unit_price_cents_ttc, le taux étant le vat_mode de la récurrence.
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_vat_rate')) {
    /** Taux de TVA du vat_mode d'une récurrence ('5.5', '10', '20'), null sans TVA. */
    function ak_recurrence_vat_rate(?string $vat_mode): ?float {
        $vm = (string)$vat_mode;
        return in_array($vm, ['5.5', '10', '20'], true) ? (float)$vm : null;
    }
}

if (!function_exists('ak_recurrence_template_lines')) {
    /**
     * Lignes de facture (montants HT, TVA, TTC en centimes) tirées de template_data.
     * @return array<int, array{line_order:int, designation:string, quantity:float, unit_price_ht_cents:int,
     *                          vat_rate:?float, total_ht_cents:int, total_vat_cents:int, total_ttc_cents:int}>
     */
    function ak_recurrence_template_lines(array $recurrence): array {
        $td = json_decode((string)($recurrence['template_data'] ?? ''), true);
        if (!is_array($td) || empty($td['lines']) || !is_array($td['lines'])) return [];
        $rec_rate = ak_recurrence_vat_rate($recurrence['vat_mode'] ?? null);
        $lines = [];
        foreach ($td['lines'] as $tl) {
            if (!is_array($tl)) continue;
            $qty = round((float)($tl['quantity'] ?? 1), 2);
            $vat = array_key_exists('vat_rate', $tl)
                ? ($tl['vat_rate'] === null || $tl['vat_rate'] === '' ? null : (float)$tl['vat_rate'])
                : $rec_rate;
            if ($vat !== null && $vat <= 0) $vat = null;
            if (isset($tl['unit_price_ht_cents']) || isset($tl['unit_price_ht'])) {
                $up   = isset($tl['unit_price_ht_cents']) ? (int)$tl['unit_price_ht_cents'] : (int)round((float)$tl['unit_price_ht'] * 100);
                $ht   = (int)round($qty * $up);
                $vatC = $vat !== null ? (int)round($ht * $vat / 100) : 0;
                $ttc  = $ht + $vatC;
            } else {
                // Prix saisi TTC : le TTC de la ligne est celui affiché dans le formulaire,
                // le HT et la TVA en sont déduits.
                $ttcU = (int)($tl['unit_price_cents_ttc'] ?? 0);
                $ttc  = (int)round($qty * $ttcU);
                $ht   = $vat !== null ? (int)round($ttc / (1 + $vat / 100)) : $ttc;
                $vatC = $ttc - $ht;
                $up   = $vat !== null ? (int)round($ttcU / (1 + $vat / 100)) : $ttcU;
            }
            $lines[] = [
                'line_order'          => (int)($tl['line_order'] ?? count($lines)),
                'designation'         => (string)($tl['designation'] ?? ($tl['label'] ?? '')),
                'quantity'            => $qty,
                'unit_price_ht_cents' => $up,
                'vat_rate'            => $vat,
                'total_ht_cents'      => $ht,
                'total_vat_cents'     => $vatC,
                'total_ttc_cents'     => $ttc,
            ];
        }
        return $lines;
    }
}

if (!function_exists('ak_recurrence_decode_template')) {
    /**
     * template_data → lignes du formulaire de la récurrence (prix unitaires saisis TTC).
     * Les lignes au format HT sont converties en TTC avec leur propre taux (sinon celui
     * de la récurrence), comme à la génération.
     * @return array{lines: array<int, array{label:string, quantity:float, unit_price_cents_ttc:int}>,
     *               meta: array, vat_mode: ?string, mixed_vat: bool}
     *         vat_mode : taux commun à toutes les lignes ('none', '5.5', '10', '20'), null si inconnu ;
     *         mixed_vat : lignes à des taux différents (le formulaire n'en gère qu'un).
     */
    function ak_recurrence_decode_template(?string $json, ?string $vat_mode = null): array {
        $td = json_decode((string)$json, true);
        $out = ['lines' => [], 'meta' => (is_array($td) && is_array($td['meta'] ?? null)) ? $td['meta'] : [],
                'vat_mode' => null, 'mixed_vat' => false];
        if (!is_array($td) || !is_array($td['lines'] ?? null)) return $out;

        $rec_rate = ak_recurrence_vat_rate($vat_mode);
        $rates = [];
        foreach ($td['lines'] as $l) {
            if (!is_array($l)) continue;
            $qty = round((float)($l['quantity'] ?? 1), 2);
            if (isset($l['unit_price_cents_ttc']) && !isset($l['unit_price_ht_cents']) && !isset($l['unit_price_ht'])) {
                $ttc = (int)$l['unit_price_cents_ttc'];
                $rate = $rec_rate;
            } else {
                $ht = isset($l['unit_price_ht_cents']) ? (int)$l['unit_price_ht_cents']
                    : (isset($l['unit_price_ht']) ? (int)round((float)$l['unit_price_ht'] * 100) : (int)($l['unit_price_cents_ttc'] ?? 0));
                $rate = array_key_exists('vat_rate', $l)
                    ? (($l['vat_rate'] === null || $l['vat_rate'] === '' || (float)$l['vat_rate'] <= 0) ? null : (float)$l['vat_rate'])
                    : $rec_rate;
                $ttc = $rate !== null ? (int)round($ht * (1 + $rate / 100)) : $ht;
            }
            $rates[$rate === null ? 'none' : rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.')] = true;
            $out['lines'][] = [
                'label'                => (string)($l['label'] ?? ($l['designation'] ?? '')),
                'quantity'             => $qty,
                'unit_price_cents_ttc' => $ttc,
            ];
        }
        if (count($rates) === 1) {
            $only = (string)array_key_first($rates);
            $out['vat_mode'] = in_array($only, ['none', '5.5', '10', '20'], true) ? $only : null;
        }
        $out['mixed_vat'] = count($rates) > 1;
        return $out;
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

        // 2. Récupère les lignes (priorité : template_data, c.-à-d. les lignes affichées et
        //    modifiées dans le formulaire de la récurrence → à défaut, celles de la facture source)
        $lines = ak_recurrence_template_lines($recurrence);
        if (empty($lines) && $source) {
            $lines = ak_recurrence_load_source_lines($pdo, $source_id);
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
        // Une facture à 0 € n'est jamais émise : elle consommerait un numéro (séquence
        // continue obligatoire) et partirait au client en « auto-envoi ».
        if ($total_ttc <= 0) {
            return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null,
                    'error' => "Montant nul pour la récurrence #{$rec_id} : vérifiez les lignes (quantités et prix) avant de générer la facture."];
        }

        // 4. Snapshots : identité actuelle de l'association et fiche actuelle du client choisi
        //    dans la récurrence (une facture émise aujourd'hui porte les coordonnées du jour).
        //    La facture source ne fournit le client que si la récurrence n'en désigne pas.
        if ($client_id <= 0 && $source && !empty($source['client_id'])) {
            $client_id = (int)$source['client_id'];
        }
        $built = ak_recurrence_build_snapshots_from_scratch($pdo, $org_id, $client_id);
        $emitter_snapshot = $built['emitter'];
        $client_snapshot  = $built['client'];
        if ($client_id > 0 && $source && (int)($source['client_id'] ?? 0) === $client_id
            && json_decode($client_snapshot, true) === ['id' => $client_id, 'org_id' => $org_id]) {
            // Fiche client supprimée depuis : coordonnées figées sur la facture source
            $client_snapshot = (string)($source['client_snapshot'] ?? $client_snapshot);
        }

        // 5. Année de numérotation (le numéro lui-même est réservé dans la transaction, étape 8)
        $today = new DateTime('now');
        $year  = (int)$today->format('Y');

        // 6. Dates : émission aujourd'hui, échéance = même delta que la source, sinon le délai
        // de paiement par défaut des paramètres de facturation (30 j s'il n'est pas renseigné)
        $issued_at = $today->format('Y-m-d 00:00:00');
        if (!function_exists('ak_asso_default_due_days') && is_file(__DIR__ . '/asso-invoice-helpers.php')) {
            require_once __DIR__ . '/asso-invoice-helpers.php';
        }
        $due_days = function_exists('ak_asso_default_due_days') ? ak_asso_default_due_days($pdo, $org_id) : 30;
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
        // La note imprimée sur la facture (« Note : … ») vient du champ « Notes / Conditions
        // de paiement » de la récurrence, ou de la facture source ; jamais du titre, qui est
        // un libellé interne (« visible uniquement par vous »).
        $description     = trim((string)($recurrence['notes'] ?? ''));
        if ($description === '' && $source) $description = trim((string)($source['description'] ?? ''));
        $currency        = (string)($recurrence['currency'] ?? ($source['currency'] ?? 'EUR'));
        $auto_send       = !empty($recurrence['auto_send']);
        $status          = $auto_send ? 'pending' : 'draft';
        $dunning_enabled = $source ? (int)($source['dunning_enabled'] ?? 1) : 1;

        // 8. Réservation du numéro + INSERT de la facture et de ses lignes, dans une seule
        //    transaction : si l'enregistrement échoue, le numéro n'est pas consommé
        //    (le numéroteur est réentrant et s'appuie sur cette transaction).
        try {
            $pdo->beginTransaction();

            $num = ak_recurrence_generate_invoice_number($pdo, $org_id, $year);

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
                ':desc'      => $description !== '' ? $description : null,
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
            // Détail technique au journal du serveur ; message lisible pour l'historique et l'écran
            error_log('[ak_recurrence_generate_invoice] #' . $rec_id . ' ' . $e->getMessage());
            return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null,
                    'error' => 'erreur technique à l’enregistrement de la facture (aucun numéro consommé). Réessayez plus tard.'];
        }
    }
}

// --------------------------------------------------------------
// Après la génération : PDF, envoi automatique au client, avis à l'admin
//
// Commun au cron et au bouton « Générer maintenant ».
// @return array ['pdf_path' => ?string, 'auto_send' => bool, 'sent' => bool,
//                'send_message' => ?string, 'notified' => int, 'errors' => string[]]
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_finalize_invoice')) {
    function ak_recurrence_finalize_invoice(PDO $pdo, array $recurrence, int $invoice_id, ?int $by_user_id = null): array {
        $out = ['pdf_path' => null, 'auto_send' => !empty($recurrence['auto_send']), 'sent' => false,
                'send_message' => null, 'notified' => 0, 'errors' => []];
        if (!function_exists('ak_asso_invoice_render_pdf')) require_once __DIR__ . '/asso-invoice-helpers.php';

        // 1. PDF (Factur-X) : sans lui, pas de pièce jointe à l'envoi ni de PDF sur la page publique.
        try {
            $out['pdf_path'] = ak_asso_invoice_render_pdf($pdo, $invoice_id);
        } catch (Throwable $e) {
            $out['errors'][] = 'PDF : ' . $e->getMessage();
            error_log('[recurrence finalize pdf] #' . $invoice_id . ' ' . $e->getMessage());
        }

        // 2. « Envoyer automatiquement par email » : e-mail initial au client (journal,
        //    plafond de l'essai gratuit et sent_at gérés par ak_asso_invoice_send_email).
        if ($out['auto_send']) {
            try {
                require_once __DIR__ . '/asso-invoice-email-helpers.php';
                $r = ak_asso_invoice_send_email($pdo, $invoice_id, 'initial', $by_user_id);
                $out['sent'] = !empty($r['success']);
                $out['send_message'] = (string)($r['message'] ?? '');
            } catch (Throwable $e) {
                $msg = $e->getMessage() === 'Email client vide' ? 'le client n’a pas d’adresse e-mail' : $e->getMessage();
                $out['send_message'] = 'Envoi impossible : ' . $msg;
            }
            if (!$out['sent']) $out['errors'][] = 'Envoi au client : ' . $out['send_message'];
        }

        // 3. « Me notifier à chaque génération » ; un envoi automatique en échec est
        //    toujours signalé (l'admin croit la facture partie chez le client).
        if (!empty($recurrence['notify_admin']) || ($out['auto_send'] && !$out['sent'])) {
            $out['notified'] = ak_recurrence_notify_admin($pdo, $recurrence, $invoice_id, $out, $by_user_id);
        }
        return $out;
    }
}

if (!function_exists('ak_recurrence_notify_admin')) {
    /**
     * E-mail d'information à l'admin : l'auteur de la récurrence s'il est toujours actif
     * dans l'association, sinon ses administrateurs, à défaut l'e-mail de facturation.
     * La personne qui a cliqué « Générer maintenant » voit déjà le résultat à l'écran.
     * @return int nombre d'e-mails partis
     */
    function ak_recurrence_notify_admin(PDO $pdo, array $recurrence, int $invoice_id, array $outcome, ?int $skip_user_id = null): int {
        $org_id = (int)($recurrence['org_id'] ?? 0);
        try {
            $st = $pdo->prepare("
                SELECT i.invoice_number, i.amount_ttc_cents, i.status, c.display_name AS client_name, c.email AS client_email,
                       o.name AS org_name, o.billing_email
                FROM asso_invoices i
                LEFT JOIN asso_clients c ON c.id = i.client_id AND c.org_id = i.org_id
                LEFT JOIN organizations o ON o.id = i.org_id
                WHERE i.id = :id AND i.org_id = :o LIMIT 1
            ");
            $st->execute([':id' => $invoice_id, ':o' => $org_id]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$inv) return 0;

            $to = [];
            $u = $pdo->prepare("SELECT id, email FROM users WHERE id = :u AND org_id = :o AND is_active = 1 AND deleted_at IS NULL LIMIT 1");
            $u->execute([':u' => (int)($recurrence['created_by'] ?? 0), ':o' => $org_id]);
            $author = $u->fetch(PDO::FETCH_ASSOC);
            if ($author) {
                $to[(int)$author['id']] = (string)$author['email'];
            } else {
                $a = $pdo->prepare("SELECT id, email FROM users WHERE org_id = :o AND role = 'admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 5");
                $a->execute([':o' => $org_id]);
                foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $row) $to[(int)$row['id']] = (string)$row['email'];
            }
            if ($skip_user_id) unset($to[$skip_user_id]);
            if (!$to && !$author && !empty($inv['billing_email'])) $to[0] = (string)$inv['billing_email'];
            $to = array_filter($to, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
            if (!$to) return 0;

            $hh = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            $num = (string)$inv['invoice_number'];
            $amount = number_format(((int)$inv['amount_ttc_cents']) / 100, 2, ',', ' ') . ' €';
            $client = trim((string)($inv['client_name'] ?? '')) !== '' ? (string)$inv['client_name'] : 'votre client';
            $failed = !empty($outcome['auto_send']) && empty($outcome['sent']);
            if (!empty($outcome['sent'])) {
                $state = 'Elle a été envoyée par e-mail à ' . $hh($inv['client_email']) . '.';
            } elseif ($failed) {
                $state = '<strong>L’envoi automatique au client n’a pas abouti</strong> : ' . $hh(preg_replace('/^Envoi impossible : /', '', (string)$outcome['send_message']))
                       . '. La facture est enregistrée : envoyez-la depuis Assokit.';
            } else {
                $state = 'Elle est en brouillon : vérifiez-la puis envoyez-la à votre client depuis Assokit.';
            }
            $url = 'https://assokit.fr/mon-asso-facture-edit?id=' . $invoice_id;
            $subject = $failed ? "Facture {$num} générée — envoi au client à faire" : "Facture récurrente {$num} générée";
            $html = '<div style="font-family:-apple-system,Segoe UI,sans-serif;max-width:520px;margin:0 auto;padding:24px;background:#f9fafb;">'
                  . '<div style="background:#fff;border-radius:14px;padding:26px;border:1px solid #e5e7eb;">'
                  . '<h1 style="font-size:19px;margin:0 0 12px;color:#111827;">Facture ' . $hh($num) . ' générée</h1>'
                  . '<p style="color:#4b5563;font-size:14px;line-height:1.55;margin:0 0 12px;">La récurrence « ' . $hh($recurrence['title'] ?? '') . ' » vient de générer la facture <strong>'
                  . $hh($num) . '</strong> pour ' . $hh($client) . ' (' . $hh($amount) . ' TTC).</p>'
                  . '<p style="color:#4b5563;font-size:14px;line-height:1.55;margin:0 0 20px;">' . $state . '</p>'
                  . '<a href="' . $hh($url) . '" style="display:inline-block;background:#10B981;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Ouvrir la facture →</a>'
                  . '</div><p style="text-align:center;color:#6b7280;font-size:11px;margin:18px 0 0;">Assokit · ' . $hh($inv['org_name'] ?? '') . '</p></div>';

            require_once __DIR__ . '/resend-helper.php';
            $sent = 0;
            foreach ($to as $email) {
                $r = send_transactional_email($email, $subject, $html, ['tag' => 'invoice_recurrence']);
                if (!empty($r['success'])) $sent++;
                else error_log('[recurrence notify] ' . $email . ' : ' . ($r['error'] ?? '?'));
            }
            return $sent;
        } catch (Throwable $e) {
            error_log('[recurrence notify] ' . $e->getMessage());
            return 0;
        }
    }
}

// --------------------------------------------------------------
// CRON : exécute toutes les récurrences dues
//
// @return array ['generated' => int, 'failed' => int, 'errors' => string[], 'invoices' => int[],
//                'sent' => int, 'notified' => int]
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_run_due')) {
    function ak_recurrence_run_due(PDO $pdo): array {
        $stats = ['generated' => 0, 'failed' => 0, 'errors' => [], 'invoices' => [], 'sent' => 0, 'notified' => 0];
        if (!function_exists('ak_asso_org_active_sql')) require_once __DIR__ . '/asso-invoice-helpers.php';

        try {
            // Associations supprimées, suspendues ou résiliées : rien n'est facturé ni envoyé.
            $st = $pdo->prepare("
                SELECT r.* FROM asso_invoice_recurrences r
                JOIN organizations o ON o.id = r.org_id
                WHERE r.status = 'active'
                  AND r.next_run_date <= CURRENT_DATE()
                  AND " . ak_asso_org_active_sql('o') . "
                ORDER BY r.next_run_date ASC, r.id ASC
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

            // Terminée (nombre max atteint ou date de fin dépassée) : rien à générer
            if ($ended = ak_recurrence_end_reason($rec)) {
                try {
                    $pdo->prepare("UPDATE asso_invoice_recurrences SET status = 'ended' WHERE id = :id")->execute([':id' => $rec_id]);
                } catch (Throwable $e) {}
                continue;
            }

            $res = ak_recurrence_run_one($pdo, $rec, null);

            if ($res['ok']) {
                $stats['generated']++;
                $stats['invoices'][] = $res['invoice_id'];
                $fin = $res['finalize'];
                if ($fin['sent']) $stats['sent']++;
                $stats['notified'] += (int)$fin['notified'];
                foreach ($fin['errors'] as $err) $stats['errors'][] = "Facture {$res['invoice_number']} : {$err}";
            } elseif ($res['code'] !== 'busy') {
                $stats['failed']++;
                $stats['errors'][] = "Récurrence #{$rec_id} : " . ($res['error'] ?? 'inconnue');
            }
        }

        return $stats;
    }
}

// --------------------------------------------------------------
// Récurrence terminée ? (nombre max de factures atteint, date de fin dépassée)
// @return ?string motif lisible, null si elle peut encore facturer
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_end_reason')) {
    function ak_recurrence_end_reason(array $rec): ?string {
        if (!empty($rec['max_occurrences']) && (int)($rec['occurrences_count'] ?? 0) >= (int)$rec['max_occurrences']) {
            return 'le nombre maximum de factures (' . (int)$rec['max_occurrences'] . ') est atteint';
        }
        if (!empty($rec['end_date']) && strtotime((string)$rec['end_date']) && strtotime((string)$rec['end_date']) < strtotime('today')) {
            return 'sa date de fin (' . date('d/m/Y', strtotime((string)$rec['end_date'])) . ') est passée';
        }
        return null;
    }
}

// --------------------------------------------------------------
// Historique des générations (asso_invoice_recurrence_runs)
// Table créée par migrations/2026-10-11-L05-facturation.sql ; tant qu'elle n'existe
// pas, la génération fonctionne sans historique.
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_log_run')) {
    function ak_recurrence_log_run(PDO $pdo, array $rec, ?int $invoice_id, bool $ok, ?string $error = null): void {
        try {
            if (!function_exists('ak_db_insert')) require_once __DIR__ . '/trial-helpers.php';
            if (!ak_db_columns($pdo, 'asso_invoice_recurrence_runs')) return;
            ak_db_insert($pdo, 'asso_invoice_recurrence_runs', [
                'recurrence_id' => (int)$rec['id'],
                'org_id'        => (int)$rec['org_id'],
                'invoice_id'    => $invoice_id,
                'run_date'      => date('Y-m-d'),
                'status'        => $ok ? 'success' : 'failed',
                'error_message' => $error !== null ? mb_substr($error, 0, 1000) : null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('[recurrence log_run] #' . (int)($rec['id'] ?? 0) . ' ' . $e->getMessage());
        }
    }
}

// --------------------------------------------------------------
// Une génération, commune au cron et au bouton « Générer maintenant » :
//   1. l'échéance est réservée (next_run_date avancé, à condition qu'elle n'ait pas
//      bougé entre-temps) : un double clic, ou « Générer maintenant » suivi du cron
//      du jour, ne facture pas deux fois la même période ;
//   2. facture générée, sinon échéance rendue (le cron réessaiera) ;
//   3. compteur, date de dernière génération, fin de la récurrence si elle est atteinte ;
//   4. ligne d'historique (réussite ou échec, avec le motif) ;
//   5. PDF, envoi automatique au client, avis à l'admin.
//
// @return array ['ok' => bool, 'code' => 'ok'|'busy'|'failed', 'invoice_id' => ?int,
//                'invoice_number' => ?string, 'error' => ?string, 'next_run_date' => ?string,
//                'finalize' => ?array (cf. ak_recurrence_finalize_invoice)]
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_run_one')) {
    function ak_recurrence_run_one(PDO $pdo, array $rec, ?int $by_user_id = null): array {
        $rec_id = (int)$rec['id'];
        $org_id = (int)$rec['org_id'];
        $old    = !empty($rec['next_run_date']) ? date('Y-m-d', strtotime((string)$rec['next_run_date'])) : null;
        $next   = ak_recurrence_following_date($rec);
        $out    = ['ok' => false, 'code' => 'failed', 'invoice_id' => null, 'invoice_number' => null,
                   'error' => null, 'next_run_date' => $next, 'finalize' => null];

        // 1. Réservation de l'échéance
        try {
            $claim = $pdo->prepare("
                UPDATE asso_invoice_recurrences SET next_run_date = :next
                WHERE id = :id AND org_id = :o AND " . ($old === null ? "next_run_date IS NULL" : "next_run_date = :old"));
            $claim->execute([':next' => $next, ':id' => $rec_id, ':o' => $org_id] + ($old === null ? [] : [':old' => $old]));
            if ($claim->rowCount() < 1) {
                $out['code']  = 'busy';
                $out['error'] = 'Cette échéance vient déjà d’être facturée : rechargez la page pour voir la facture générée.';
                return $out;
            }
        } catch (Throwable $e) {
            $out['error'] = 'Récurrence indisponible : ' . $e->getMessage();
            return $out;
        }

        // 2. Génération
        $res = ak_recurrence_generate_invoice($pdo, $rec);
        if (empty($res['ok'])) {
            try {
                $pdo->prepare("UPDATE asso_invoice_recurrences SET next_run_date = :old WHERE id = :id AND org_id = :o AND next_run_date = :next")
                    ->execute([':old' => $old, ':id' => $rec_id, ':o' => $org_id, ':next' => $next]);
            } catch (Throwable $e) {
                error_log('[recurrence run_one] #' . $rec_id . ' échéance non rendue : ' . $e->getMessage());
            }
            $out['error'] = (string)($res['error'] ?? 'erreur inconnue');
            $out['next_run_date'] = $old;
            ak_recurrence_log_run($pdo, $rec, null, false, $out['error']);
            return $out;
        }
        $out['ok'] = true;
        $out['code'] = 'ok';
        $out['invoice_id'] = (int)$res['invoice_id'];
        $out['invoice_number'] = (string)$res['invoice_number'];

        // 3. Compteur, dernière génération, fin éventuelle
        $count = (int)($rec['occurrences_count'] ?? 0) + 1;
        $ends  = (!empty($rec['max_occurrences']) && $count >= (int)$rec['max_occurrences'])
              || (!empty($rec['end_date']) && strtotime((string)$rec['end_date']) && $next > date('Y-m-d', strtotime((string)$rec['end_date'])));
        try {
            $pdo->prepare("
                UPDATE asso_invoice_recurrences
                SET last_run_date = CURRENT_DATE(),
                    occurrences_count = COALESCE(occurrences_count, 0) + 1,
                    status = " . ($ends ? "'ended'" : "status") . ",
                    updated_at = NOW()
                WHERE id = :id AND org_id = :o
            ")->execute([':id' => $rec_id, ':o' => $org_id]);
        } catch (Throwable $e) {
            error_log('[recurrence run_one] #' . $rec_id . ' compteur : ' . $e->getMessage());
        }

        // 4. Historique
        ak_recurrence_log_run($pdo, $rec, $out['invoice_id'], true);

        // 5. PDF, envoi automatique au client, avis à l'admin (après l'avancée de la
        //    récurrence : un incident d'envoi ne doit pas faire refacturer la période).
        $out['finalize'] = ak_recurrence_finalize_invoice($pdo, $rec, $out['invoice_id'], $by_user_id);
        return $out;
    }
}

// --------------------------------------------------------------
// Messages des pages récurrences : une clé de session propre au module, lue et
// effacée par les pages du module (les clés génériques flash_success / flash_error
// ressortaient plus tard, hors contexte, sur d'autres pages).
// --------------------------------------------------------------
if (!function_exists('ak_recurrence_flash')) {
    function ak_recurrence_flash(string $type, string $message): void {
        $_SESSION['flash_recurrences'] = ['type' => $type === 'success' ? 'success' : 'error', 'message' => $message];
    }
}

if (!function_exists('ak_recurrence_flash_html')) {
    /** Bandeau du message en attente (vide s'il n'y en a pas), puis effacement. */
    function ak_recurrence_flash_html(): string {
        $f = $_SESSION['flash_recurrences'] ?? null;
        unset($_SESSION['flash_recurrences']);
        if (!is_array($f) || trim((string)($f['message'] ?? '')) === '') return '';
        $ok = ($f['type'] ?? '') === 'success';
        return '<div role="' . ($ok ? 'status' : 'alert') . '" style="background:' . ($ok ? '#ECFDF5' : '#FEF2F2') . ';border:1px solid '
             . ($ok ? '#A7F3D0' : '#FECACA') . ';border-radius:10px;padding:14px;margin-bottom:18px;color:' . ($ok ? '#065F46' : '#991B1B')
             . ';font-size:14px;">' . htmlspecialchars((string)$f['message'], ENT_QUOTES, 'UTF-8') . '</div>';
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
        // Les factures elles-mêmes font foi (asso_invoices.recurrence_id) : l'historique
        // asso_invoice_recurrence_runs compte aussi les échecs et peut manquer.
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
        } catch (Throwable $e) {
            error_log('[ak_recurrence_count_invoices_this_month] ' . $e->getMessage());
            return 0;
        }
    }
}
