<?php
/**
 * ============================================================
 * ASSOKIT — asso-invoice-helpers.php
 * Helpers du module Factures Asso (Sous-pack 1)
 * ============================================================
 */

if (!function_exists('ak_asso_slug_from_name')) {

/**
 * Génère un slug ASCII pur depuis un nom d'asso.
 * "Latitude91" → "LATITUDE91"
 * "Mon Asso & Cie" → "MON-ASSO-CIE"
 */
function ak_asso_slug_from_name(string $name): string
{
    // Translittération (é → e, à → a, etc.)
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $slug = strtoupper($slug);
    $slug = preg_replace('/[^A-Z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'ASSO';
    return mb_substr($slug, 0, 30);
}

/**
 * Garantit qu'une asso a un slug. Si vide, génère depuis le nom.
 * Garantit unicité (suffixe -2, -3 si conflit).
 */
function ak_asso_ensure_slug(PDO $pdo, int $org_id): string
{
    $stmt = $pdo->prepare("SELECT slug, name FROM organizations WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $org_id]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Asso introuvable id=' . $org_id);

    if (!empty($row['slug'])) return $row['slug'];

    $base = ak_asso_slug_from_name($row['name']);
    $slug = $base;
    $i = 2;
    while (true) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE slug = :s AND id != :id");
        $check->execute([':s' => $slug, ':id' => $org_id]);
        if ((int)$check->fetchColumn() === 0) break;
        $slug = $base . '-' . $i;
        $i++;
    }

    $upd = $pdo->prepare("UPDATE organizations SET slug = :s WHERE id = :id");
    $upd->execute([':s' => $slug, ':id' => $org_id]);
    return $slug;
}

/**
 * Génère le prochain numéro de facture pour une asso — version structurée.
 *
 * Source de vérité UNIQUE pour la numérotation (piste d'audit fiable) :
 * le compteur atomique org_invoice_settings, verrouillé en FOR UPDATE.
 * Toutes les voies de création de facture (manuelle, récurrence, avoir…)
 * DOIVENT passer par ici pour garantir une séquence continue, sans trou
 * ni doublon (exigence facture électronique / EN 16931 / art. 289 CGI).
 *
 * Auto-réparation : le compteur est aligné sur max(next_sequence, MAX(invoice_sequence)+1)
 * afin de rattraper toute dérive héritée d'anciennes insertions (ex. récurrences
 * qui n'incrémentaient pas le compteur). Couplé à la contrainte UNIQUE
 * (org_id, invoice_year, invoice_sequence) en base, le doublon devient impossible.
 *
 * Réentrant : si un appel se fait déjà dans une transaction, on ne gère pas
 * la nôtre (pas de nesting), on s'appuie sur le verrou FOR UPDATE existant.
 *
 * Format : SLUG-2026-000001
 * @return array{number:string, year:int, sequence:int, slug:string}
 */
function ak_asso_invoice_next_number_parts(PDO $pdo, int $org_id): array
{
    $year = (int) date('Y');
    $slug = ak_asso_ensure_slug($pdo, $org_id);

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT next_sequence, current_year FROM org_invoice_settings WHERE org_id = :id FOR UPDATE");
        $stmt->execute([':id' => $org_id]);
        $row = $stmt->fetch();

        // Séquence réellement déjà utilisée cette année (garde-fou anti-doublon)
        $mx = $pdo->prepare("SELECT COALESCE(MAX(invoice_sequence), 0) FROM asso_invoices WHERE org_id = :id AND invoice_year = :y");
        $mx->execute([':id' => $org_id, ':y' => $year]);
        $maxUsed = (int)$mx->fetchColumn();

        if (!$row) {
            // Création des paramètres pour cette asso
            $current = $maxUsed + 1;
            $pdo->prepare("INSERT INTO org_invoice_settings (org_id, next_sequence, current_year) VALUES (:id, :next, :y)")
                ->execute([':id' => $org_id, ':next' => $current + 1, ':y' => $year]);
        } elseif ((int)$row['current_year'] !== $year) {
            // Nouvelle année → séquence repart à 1 (ou au-delà si des factures existent déjà pour l'année)
            $current = $maxUsed + 1;
            $pdo->prepare("UPDATE org_invoice_settings SET next_sequence = :next, current_year = :y WHERE org_id = :id")
                ->execute([':next' => $current + 1, ':y' => $year, ':id' => $org_id]);
        } else {
            // Auto-heal : ne jamais réutiliser une séquence déjà émise
            $current = max((int)$row['next_sequence'], $maxUsed + 1);
            $pdo->prepare("UPDATE org_invoice_settings SET next_sequence = :next WHERE org_id = :id")
                ->execute([':next' => $current + 1, ':id' => $org_id]);
        }

        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'number'   => sprintf('%s-%d-%06d', $slug, $year, $current),
        'year'     => $year,
        'sequence' => $current,
        'slug'     => $slug,
    ];
}

/**
 * Génère le prochain numéro de facture pour une asso (compat : renvoie la chaîne).
 * Format : SLUG-2026-000001
 */
function ak_asso_invoice_next_number(PDO $pdo, int $org_id): string
{
    return ak_asso_invoice_next_number_parts($pdo, $org_id)['number'];
}

/**
 * Garantit qu'une asso a une ligne dans org_invoice_settings.
 */
function ak_asso_invoice_settings(PDO $pdo, int $org_id): array
{
    $stmt = $pdo->prepare("SELECT * FROM org_invoice_settings WHERE org_id = :id LIMIT 1");
    $stmt->execute([':id' => $org_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $year = (int) date('Y');
    $pdo->prepare("INSERT INTO org_invoice_settings (org_id, next_sequence, current_year) VALUES (:id, 1, :y)")
        ->execute([':id' => $org_id, ':y' => $year]);

    $stmt->execute([':id' => $org_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Format cents → "49,99 €"
 */
function ak_asso_fmt_cents(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

/**
 * Statut → label lisible
 */
function ak_asso_invoice_status_label(string $status): string
{
    return [
        'draft' => 'Brouillon',
        'pending' => 'En attente',
        'paid' => 'Payée',
        'overdue' => 'En retard',
        'cancelled' => 'Annulée',
        'refunded' => 'Remboursée',
    ][$status] ?? $status;
}

/**
 * Cherche un client existant par email. Sinon le crée et le retourne.
 * Utilisé pour la sauvegarde auto à la création d'une facture.
 */
function ak_asso_find_or_create_client(PDO $pdo, int $org_id, array $data): int
{
    $email = trim((string)($data['email'] ?? ''));
    if ($email === '') throw new RuntimeException('Email client obligatoire');

    // Chercher client existant
    $stmt = $pdo->prepare("SELECT id FROM asso_clients WHERE org_id = :org AND email = :em AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':org' => $org_id, ':em' => $email]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;

    // Créer
    $stmt = $pdo->prepare("
        INSERT INTO asso_clients (
            org_id, client_type, display_name, legal_name,
            contact_first_name, contact_last_name,
            email, phone,
            address_street, address_complement, address_zip, address_city, address_country,
            siren, siret, vat_number,
            internal_notes, created_by_user_id
        ) VALUES (
            :org, :ctype, :dname, :lname,
            :fn, :ln,
            :em, :phone,
            :str, :compl, :zip, :city, :country,
            :siren, :siret, :vat,
            :notes, :uid
        )
    ");
    $stmt->execute([
        ':org'   => $org_id,
        ':ctype' => $data['client_type'] ?? 'company',
        ':dname' => mb_substr((string)($data['display_name'] ?? ''), 0, 200),
        ':lname' => $data['legal_name'] ?? null,
        ':fn'    => $data['contact_first_name'] ?? null,
        ':ln'    => $data['contact_last_name'] ?? null,
        ':em'    => $email,
        ':phone' => $data['phone'] ?? null,
        ':str'   => $data['address_street'] ?? null,
        ':compl' => $data['address_complement'] ?? null,
        ':zip'   => $data['address_zip'] ?? null,
        ':city'  => $data['address_city'] ?? null,
        ':country' => $data['address_country'] ?? 'France',
        ':siren' => $data['siren'] ?? null,
        ':siret' => $data['siret'] ?? null,
        ':vat'   => $data['vat_number'] ?? null,
        ':notes' => $data['internal_notes'] ?? null,
        ':uid'   => $data['created_by_user_id'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Met à jour la fiche d'un client de l'association avec les champs fournis.
 * Seules les clés présentes dans $data sont écrites : une valeur vide efface le champ,
 * sauf le nom et l'e-mail (obligatoires), conservés s'ils sont vides ou invalides.
 * $only_filled : n'écrit que les valeurs non vides (complète la fiche sans rien effacer).
 * Retourne false si le client n'appartient pas à l'association (ou est supprimé).
 */
function ak_asso_client_update(PDO $pdo, int $org_id, int $client_id, array $data, bool $only_filled = false): bool
{
    $ck = $pdo->prepare("SELECT id FROM asso_clients WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
    $ck->execute([$client_id, $org_id]);
    if (!$ck->fetchColumn()) return false;

    $fields = ['client_type', 'display_name', 'legal_name', 'contact_first_name', 'contact_last_name', 'email', 'phone',
               'address_street', 'address_complement', 'address_zip', 'address_city', 'address_country',
               'siren', 'siret', 'vat_number'];
    $set = []; $params = [];
    foreach ($fields as $f) {
        if (!array_key_exists($f, $data)) continue;
        $v = $data[$f] === null ? '' : trim((string)$data[$f]);
        if ($f === 'client_type') {
            if (!in_array($v, ['individual', 'company'], true)) continue;
        } elseif ($f === 'display_name') {
            if ($v === '') continue;
            $v = mb_substr($v, 0, 200);
        } elseif ($f === 'email') {
            if ($v === '' || !filter_var($v, FILTER_VALIDATE_EMAIL)) continue;
        } else {
            $v = mb_substr($v, 0, 255);
        }
        if ($v === '' && $only_filled) continue;
        $set[] = "`$f` = ?";
        $params[] = $v === '' ? null : $v;
    }
    if (!$set) return true;
    $params[] = $client_id; $params[] = $org_id;
    $pdo->prepare("UPDATE asso_clients SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = ? AND org_id = ?")
        ->execute($params);
    return true;
}

/**
 * Client d'une facture ou d'un devis saisi dans les formulaires du site, avec ses coordonnées :
 *  - client choisi (id de l'association) avec le même e-mail : sa fiche prend les coordonnées du formulaire ;
 *  - e-mail différent de celui du client choisi : c'est un autre client (la fiche choisie reste
 *    intacte : elle sert aux autres pièces et aux relances) ;
 *  - client existant de même e-mail : sa fiche est complétée (champs renseignés seulement) ;
 *  - sinon nouveau client.
 * L'e-mail d'un client se corrige depuis sa fiche (mon-asso-client-detail.php).
 * Les pièces déjà émises gardent les coordonnées figées à leur émission (client_snapshot).
 */
function ak_asso_client_from_form(PDO $pdo, int $org_id, array $data, int $user_id): int
{
    $id = (int)($data['id'] ?? 0);
    unset($data['id']);
    $email = trim((string)($data['email'] ?? ''));

    if ($id > 0) {
        $st = $pdo->prepare("SELECT email FROM asso_clients WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$id, $org_id]);
        $current = $st->fetchColumn();
        if ($current !== false) {
            $current = trim((string)$current);
            if ($email === '' || $current === '' || strcasecmp($email, $current) === 0) {
                ak_asso_client_update($pdo, $org_id, $id, $data);
                return $id;
            }
        }
    }

    if ($email !== '') {
        $st = $pdo->prepare("SELECT id FROM asso_clients WHERE org_id = ? AND email = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
        $st->execute([$org_id, $email]);
        if ($existing = (int)$st->fetchColumn()) {
            ak_asso_client_update($pdo, $org_id, $existing, $data, true);
            return $existing;
        }
    }
    return ak_asso_find_or_create_client($pdo, $org_id, array_merge($data, ['created_by_user_id' => $user_id]));
}

/**
 * Journal d'audit facturation (asso_invoice_audit, migration 2026-08-12), sans effet si
 * la table manque. Événements : created | finalized | credit_note | status_change…
 */
function ak_asso_invoice_audit(PDO $pdo, int $org_id, int $invoice_id, string $event, ?int $user_id,
                               ?string $from = null, ?string $to = null, array $meta = []): void
{
    try {
        if (!function_exists('ak_db_insert')) require_once __DIR__ . '/trial-helpers.php';
        if (!ak_db_columns($pdo, 'asso_invoice_audit')) return;
        ak_db_insert($pdo, 'asso_invoice_audit', [
            'org_id'        => $org_id,
            'invoice_id'    => $invoice_id,
            'event_type'    => $event,
            'actor_user_id' => $user_id,
            'actor_label'   => $user_id ? null : 'system',
            'from_value'    => $from,
            'to_value'      => $to,
            'meta_json'     => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('[ASSO INVOICE AUDIT] ' . $e->getMessage());
    }
}

/** Une facture est-elle un avoir ? */
function ak_asso_is_credit_note(array $invoice): bool
{
    return ($invoice['invoice_type'] ?? 'invoice') === 'credit_note';
}

/**
 * Avoir d'une facture : retourne la pièce (id, numéro, date) qui annule la facture, ou null.
 */
function ak_asso_invoice_credit_note_of(PDO $pdo, int $org_id, int $invoice_id): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, invoice_number, issued_at, amount_ttc_cents FROM asso_invoices
                             WHERE org_id = ? AND parent_invoice_id = ? AND invoice_type = 'credit_note'
                             ORDER BY id LIMIT 1");
        $st->execute([$org_id, $invoice_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // colonnes de la migration 2026-08-12 absentes : pas d'avoir possible
    }
}

/**
 * Avoir total d'une facture émise (correction d'une facture finalisée, art. 289 CGI :
 * la facture n'est jamais modifiée, un avoir l'annule).
 *  - numéro pris dans la même séquence que les factures, dans la même transaction ;
 *  - lignes et montants de la facture repris en négatif (les sommes s'annulent) ;
 *  - facture en attente ou en retard : passée « Annulée » (plus de relance, plus d'encaissement
 *    attendu) ; l'avoir, qui solde la créance, est rangé avec elle (statut « cancelled ») ;
 *  - facture payée : elle reste payée et l'avoir constate le remboursement du client à la date
 *    du jour (statut « paid ») : chiffre d'affaires et encaissements s'annulent ;
 *  - événement « credit_note » au journal d'audit, PDF « AVOIR » (Factur-X type 381).
 * Retourne ['ok' => bool, 'code' => 'ok'|'not_found'|'state'|'unavailable', 'message' => string,
 *           'id' => ?int, 'invoice_number' => ?string, 'pdf_path' => ?string, 'refund' => bool].
 */
function ak_asso_credit_note_create(PDO $pdo, int $org_id, int $invoice_id, int $user_id, string $reason = ''): array
{
    $fail = fn(string $code, string $msg) => ['ok' => false, 'code' => $code, 'message' => $msg,
                                              'id' => null, 'invoice_number' => null, 'pdf_path' => null, 'refund' => false];
    if (!function_exists('ak_db_columns')) require_once __DIR__ . '/trial-helpers.php';
    $cols = ak_db_columns($pdo, 'asso_invoices');
    if (!isset($cols['invoice_type'], $cols['parent_invoice_id']) || !ak_db_accepts($pdo, 'asso_invoices', 'status', 'cancelled')) {
        return $fail('unavailable', 'Les avoirs ne sont pas encore activés sur ce serveur (mise à jour de la base de données requise). Contactez le support Assokit.');
    }
    $reason = mb_substr(trim($reason), 0, 300);

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM asso_invoices WHERE id = ? AND org_id = ? LIMIT 1 FOR UPDATE");
        $st->execute([$invoice_id, $org_id]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            if ($ownTx) $pdo->rollBack();
            return $fail('not_found', 'Facture introuvable.');
        }
        $num = (string)$inv['invoice_number'];
        $status = (string)$inv['status'];
        $refuse = null;
        if (ak_asso_is_credit_note($inv))            $refuse = 'Un avoir ne peut pas être annulé par un autre avoir.';
        elseif ($status === 'draft')                 $refuse = 'La facture ' . $num . ' est un brouillon : modifiez-la directement, aucun avoir n’est nécessaire.';
        elseif (!in_array($status, ['pending', 'overdue', 'paid'], true))
                                                     $refuse = 'La facture ' . $num . ' est ' . ($status === 'cancelled' ? 'déjà annulée' : mb_strtolower(ak_asso_invoice_status_label($status))) . ' : aucun avoir n’est possible.';
        elseif ($prev = ak_asso_invoice_credit_note_of($pdo, $org_id, $invoice_id))
                                                     $refuse = 'La facture ' . $num . ' est déjà annulée par l’avoir ' . $prev['invoice_number'] . '.';
        if ($refuse) {
            if ($ownTx) $pdo->rollBack();
            return $fail('state', $refuse);
        }
        $refund = $status === 'paid';

        // Numéro : même compteur que les factures (séquence continue, sans trou)
        $parts = ak_asso_invoice_next_number_parts($pdo, $org_id);

        $ub = random_bytes(16);
        $ub[6] = chr((ord($ub[6]) & 0x0f) | 0x40);
        $ub[8] = chr((ord($ub[8]) & 0x3f) | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($ub), 4));

        $o = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
        $o->execute([$org_id]);
        $emitter = json_encode($o->fetch(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_UNICODE);

        $now = date('Y-m-d H:i:s');
        $desc = 'Avoir annulant la facture ' . $num . ' du ' . date('d/m/Y', strtotime((string)$inv['issued_at'])) . '.'
              . ($reason !== '' ? ' Motif : ' . $reason : '');
        $row = [
            'org_id'            => $org_id,
            'client_id'         => $inv['client_id'],
            'public_uuid'       => $uuid,
            'invoice_number'    => $parts['number'],
            'invoice_year'      => $parts['year'],
            'invoice_sequence'  => $parts['sequence'],
            'issued_at'         => $now,
            'due_at'            => date('Y-m-d 23:59:59'),
            'amount_ht_cents'   => -(int)$inv['amount_ht_cents'],
            'amount_vat_cents'  => -(int)$inv['amount_vat_cents'],
            'amount_ttc_cents'  => -(int)$inv['amount_ttc_cents'],
            'currency'          => $inv['currency'] ?: 'EUR',
            'status'            => $refund ? 'paid' : 'cancelled',
            'invoice_type'      => 'credit_note',
            'parent_invoice_id' => $invoice_id,
            'emitter_snapshot'  => $emitter,
            'client_snapshot'   => $inv['client_snapshot'],
            'dunning_enabled'   => 0,
            'dunning_level'     => 0,
            'description'       => $desc,
            'created_by_user_id'=> $user_id,
            'created_at'        => $now,
            'updated_at'        => $now,
            'finalized_at'      => $now,
        ];
        if ($refund) {
            $row += ['paid_at' => $now, 'payment_method' => $inv['payment_method'] ?? null,
                     'payment_reference' => 'Remboursement facture ' . $num];
        }
        $credit_id = ak_db_insert($pdo, 'asso_invoices', $row);

        $l = $pdo->prepare("SELECT * FROM asso_invoice_lines WHERE invoice_id = ? ORDER BY line_order, id");
        $l->execute([$invoice_id]);
        $ins = $pdo->prepare("
            INSERT INTO asso_invoice_lines (invoice_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
                                            total_ht_cents, total_vat_cents, total_ttc_cents)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $i => $line) {
            $ins->execute([$credit_id, $i, $line['designation'], $line['quantity'], -(int)$line['unit_price_ht_cents'],
                           $line['vat_rate'], -(int)$line['total_ht_cents'], -(int)$line['total_vat_cents'], -(int)$line['total_ttc_cents']]);
        }

        // Facture d'origine : plus rien n'est attendu du client (relances arrêtées)
        if (!$refund) {
            $pdo->prepare("UPDATE asso_invoices SET status = 'cancelled', dunning_enabled = 0, next_dunning_at = NULL, updated_at = NOW()
                           WHERE id = ? AND org_id = ?")->execute([$invoice_id, $org_id]);
        }

        ak_asso_invoice_audit($pdo, $org_id, $invoice_id, 'credit_note', $user_id, $status, $refund ? 'paid' : 'cancelled',
                              ['credit_note_id' => $credit_id, 'credit_note_number' => $parts['number'], 'reason' => $reason]);
        ak_asso_invoice_audit($pdo, $org_id, $credit_id, 'created', $user_id, null, $refund ? 'paid' : 'cancelled',
                              ['type' => 'credit_note', 'parent_invoice_id' => $invoice_id, 'parent_number' => $num]);

        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Facture annulée par l'avoir : plus de paiement en ligne possible (pages Stripe ouvertes fermées)
    if (!$refund) ak_asso_invoice_close_online($pdo, $invoice_id);

    // PDF hors transaction (mPDF peut prendre plusieurs secondes)
    $pdf = null;
    try { $pdf = ak_asso_invoice_render_pdf($pdo, $credit_id); }
    catch (Throwable $e) { error_log('[ASSO CREDIT NOTE PDF] #' . $credit_id . ' ' . $e->getMessage()); }

    $amount = number_format(((int)$inv['amount_ttc_cents']) / 100, 2, ',', ' ') . ' €';
    return [
        'ok' => true, 'code' => 'ok', 'id' => $credit_id, 'invoice_number' => $parts['number'], 'pdf_path' => $pdf,
        'refund' => $refund,
        'message' => 'Avoir ' . $parts['number'] . ' créé : il annule la facture ' . $num . ' (' . $amount . ').'
            . ($refund ? ' Le client l’avait réglée : remboursez-lui ' . $amount . '.' : ' Plus aucun règlement ni relance n’est attendu pour cette facture.'),
    ];
}

/**
 * Calcul des montants d'une ligne.
 * Reçoit : quantity, unit_price_ht (€), vat_rate (%)
 * Retourne : total_ht_cents, total_vat_cents, total_ttc_cents
 *
 * Le calcul part des valeurs telles qu'elles sont enregistrées et imprimées :
 * quantité à 2 décimales (colonne DECIMAL(…,2)) et prix unitaire au centime
 * (unit_price_ht_cents). Sinon « 0,125 × 80 € » donnait 10,00 € sur une ligne
 * affichée « 0,13 × 80,00 € ».
 */
function ak_asso_line_compute(float $quantity, float $unit_price_ht, ?float $vat_rate): array
{
    $quantity   = round($quantity, 2);
    $unit_cents = round($unit_price_ht * 100);
    $total_ht_cents = (int) round($quantity * $unit_cents);
    if ($vat_rate !== null && $vat_rate > 0) {
        $total_vat_cents = (int) round($total_ht_cents * $vat_rate / 100);
    } else {
        $total_vat_cents = 0;
    }
    $total_ttc_cents = $total_ht_cents + $total_vat_cents;
    return [
        'total_ht_cents'  => $total_ht_cents,
        'total_vat_cents' => $total_vat_cents,
        'total_ttc_cents' => $total_ttc_cents,
    ];
}

/**
 * Crée une facture asso complète (avec lignes + sauvegarde auto client).
 * Retourne ['id' => int, 'invoice_number' => string, 'pdf_path' => string]
 */
function ak_asso_invoice_create(PDO $pdo, int $org_id, int $created_by_user_id, array $data): array
{
    if (empty($data['client'])) throw new RuntimeException('Données client manquantes');
    if (empty($data['lines'])) throw new RuntimeException('Au moins une ligne est obligatoire');

    // Client, réservation du numéro, en-tête et lignes : une seule transaction.
    // Le numéroteur validait sa propre transaction avant l'INSERT : une facture
    // en échec consommait quand même son numéro (trou dans la séquence, interdit).
    // Réentrant, comme ak_asso_invoice_next_number_parts : si l'appelant a déjà
    // ouvert une transaction, c'est la sienne qui fait foi.
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        // 1) Trouve/crée client
        $client_data = $data['client'];
        $client_data['created_by_user_id'] = $created_by_user_id;
        $client_id = $client_data['id'] ?? null;
        if (empty($client_id)) {
            $client_id = ak_asso_find_or_create_client($pdo, $org_id, $client_data);
        } else {
            // Sécurité (BOLA cross-tenant) : un client fourni par id DOIT appartenir à l'organisation.
            $chk = $pdo->prepare("SELECT id FROM asso_clients WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $chk->execute([(int)$client_id, $org_id]);
            if (!$chk->fetchColumn()) throw new RuntimeException('Client introuvable pour cette organisation.');
        }

        // 2) Calculer les totaux
        $total_ht = 0; $total_vat = 0; $total_ttc = 0;
        foreach ($data['lines'] as $line) {
            $computed = ak_asso_line_compute(
                (float)($line['quantity'] ?? 1),
                (float)($line['unit_price_ht'] ?? 0),
                isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null
            );
            $total_ht += $computed['total_ht_cents'];
            $total_vat += $computed['total_vat_cents'];
            $total_ttc += $computed['total_ttc_cents'];
        }

        // 3) Numérotation
        $invoice_number = ak_asso_invoice_next_number($pdo, $org_id);
        $year = (int) date('Y');
        preg_match('/-(\d+)$/', $invoice_number, $m);
        $sequence = (int)($m[1] ?? 0);

        // 4) UUID public v4 via CSPRNG (mt_rand est prédictible -> lien public devinable).
        $ub = random_bytes(16);
        $ub[6] = chr((ord($ub[6]) & 0x0f) | 0x40);
        $ub[8] = chr((ord($ub[8]) & 0x3f) | 0x80);
        $public_uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($ub), 4));

        // 5) Snapshots
        $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $org_id]);
        $org_data = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $client_id]);
        $client_full = $stmt->fetch(PDO::FETCH_ASSOC);

        $emitter_snap = json_encode($org_data, JSON_UNESCAPED_UNICODE);
        $client_snap  = json_encode($client_full, JSON_UNESCAPED_UNICODE);

        // 6) Dates (sans délai fourni : « Délai de paiement par défaut » des paramètres de
        // facturation, ce qui couvre aussi la conversion d'un devis)
        $issued_at = $data['issued_at'] ?? date('Y-m-d H:i:s');
        $due_days = (isset($data['due_days']) && $data['due_days'] !== '')
            ? (int)$data['due_days']
            : ak_asso_default_due_days($pdo, $org_id);
        $due_at = $data['due_at'] ?? date('Y-m-d 23:59:59', strtotime($issued_at . ' +' . $due_days . ' days'));

        // 7) INSERT facture
        $stmt = $pdo->prepare("
            INSERT INTO asso_invoices (
                org_id, client_id, public_uuid,
                invoice_number, invoice_year, invoice_sequence,
                issued_at, due_at,
                amount_ht_cents, amount_vat_cents, amount_ttc_cents, currency,
                status,
                emitter_snapshot, client_snapshot,
                description, internal_notes,
                created_by_user_id
            ) VALUES (
                :org, :cli, :uuid,
                :num, :year, :seq,
                :issued, :due,
                :ht, :vat, :ttc, 'EUR',
                :status,
                :em_snap, :cl_snap,
                :desc, :notes,
                :uid
            )
        ");
        $stmt->execute([
            ':org'    => $org_id,
            ':cli'    => $client_id,
            ':uuid'   => $public_uuid,
            ':num'    => $invoice_number,
            ':year'   => $year,
            ':seq'    => $sequence,
            ':issued' => $issued_at,
            ':due'    => $due_at,
            ':ht'     => $total_ht,
            ':vat'    => $total_vat,
            ':ttc'    => $total_ttc,
            ':status' => $data['status'] ?? 'pending',
            ':em_snap'=> $emitter_snap,
            ':cl_snap'=> $client_snap,
            ':desc'   => $data['description'] ?? null,
            ':notes'  => $data['internal_notes'] ?? null,
            ':uid'    => $created_by_user_id,
        ]);

        $invoice_id = (int) $pdo->lastInsertId();

        // 8) INSERT lignes
        $stmt = $pdo->prepare("
            INSERT INTO asso_invoice_lines (
                invoice_id, line_order, designation, quantity, unit_price_ht_cents, vat_rate,
                total_ht_cents, total_vat_cents, total_ttc_cents
            ) VALUES (
                :inv, :ord, :des, :qty, :pu, :vat,
                :tht, :tvat, :tttc
            )
        ");
        foreach ($data['lines'] as $i => $line) {
            $computed = ak_asso_line_compute(
                (float)($line['quantity'] ?? 1),
                (float)($line['unit_price_ht'] ?? 0),
                isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null
            );
            $stmt->execute([
                ':inv'  => $invoice_id,
                ':ord'  => $i,
                ':des'  => mb_substr((string)($line['designation'] ?? ''), 0, 500),
                ':qty'  => round((float)($line['quantity'] ?? 1), 2),
                ':pu'   => (int) round((float)($line['unit_price_ht'] ?? 0) * 100),
                ':vat'  => isset($line['vat_rate']) && $line['vat_rate'] !== '' ? (float)$line['vat_rate'] : null,
                ':tht'  => $computed['total_ht_cents'],
                ':tvat' => $computed['total_vat_cents'],
                ':tttc' => $computed['total_ttc_cents'],
            ]);
        }

        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // 9) Génération PDF (hors transaction : mPDF peut prendre plusieurs secondes)
    $pdf_path = ak_asso_invoice_render_pdf($pdo, $invoice_id);

    return [
        'id' => $invoice_id,
        'invoice_number' => $invoice_number,
        'pdf_path' => $pdf_path,
    ];
}

/**
 * Régénère le PDF d'une facture asso.
 */
function ak_asso_invoice_render_pdf(PDO $pdo, int $invoice_id): string
{
    $stmt = $pdo->prepare("SELECT * FROM asso_invoices WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) throw new RuntimeException('Facture introuvable');

    $stmt = $pdo->prepare("SELECT * FROM asso_invoice_lines WHERE invoice_id = :id ORDER BY line_order");
    $stmt->execute([':id' => $invoice_id]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Snapshot illisible (JSON tronqué…) : tableau vide plutôt que null (sinon plus de XML Factur-X)
    $emitter = json_decode((string)($invoice['emitter_snapshot'] ?? ''), true);
    $client  = json_decode((string)($invoice['client_snapshot'] ?? ''), true);
    $emitter = is_array($emitter) ? $emitter : [];
    $client  = is_array($client) ? $client : [];

    // Récup paramètres asso pour IBAN/BIC
    $stmt = $pdo->prepare("SELECT * FROM org_invoice_settings WHERE org_id = :id");
    $stmt->execute([':id' => $invoice['org_id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Avoir : facture d'origine (numéro et date imprimés sur l'avoir)
    $parent = null;
    if (ak_asso_is_credit_note($invoice) && !empty($invoice['parent_invoice_id'])) {
        $stmt = $pdo->prepare("SELECT invoice_number, issued_at FROM asso_invoices WHERE id = :id AND org_id = :org LIMIT 1");
        $stmt->execute([':id' => (int)$invoice['parent_invoice_id'], ':org' => (int)$invoice['org_id']]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Paiement en ligne (Stripe Connect) : lien et QR code « Payer en ligne » sur une facture à régler
    $pdf_pay_url = '';
    try {
        require_once __DIR__ . '/stripe-connect-helpers.php';
        $pdf_pay_url = preg_replace('/#.*$/', '', ak_connect_invoice_pay_url($pdo, $invoice));
    } catch (Throwable $e) {
        $pdf_pay_url = '';
    }

    // Render HTML
    ob_start();
    $pdf_invoice = $invoice;
    $pdf_lines   = $lines;
    $pdf_emitter = $emitter;
    $pdf_client  = $client;
    $pdf_settings = $settings;
    $pdf_parent  = $parent;
    include __DIR__ . '/asso-invoice-pdf-template.php';
    $html = ob_get_clean();

    // Dossier upload
    $dir = __DIR__ . '/uploads/asso-invoices';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Nom de fichier NON DEVINABLE : on ajoute un suffixe issu de l'UUID de la facture,
    // pour empêcher l'énumération inter-organisations des PDF (confidentialité / RGPD / PDP).
    $num_safe = preg_replace('/[^A-Za-z0-9\-]/', '-', (string) $invoice['invoice_number']);
    $uuid_safe = substr(preg_replace('/[^a-f0-9]/', '', (string) ($invoice['public_uuid'] ?? '')), 0, 20);
    $base_name = $num_safe . ($uuid_safe !== '' ? '-' . $uuid_safe : '');
    $filename = $base_name . '.pdf';
    $full_path = $dir . '/' . $filename;

    // Génération du XML Factur-X (norme DGFiP) si possible.
    require_once __DIR__ . '/facturx-helpers.php';
    $facturx_xml = null;
    if (AK_FACTURX_ENABLED) {
        try { $facturx_xml = ak_facturx_build_xml($invoice + ['parent_invoice_number' => $parent['invoice_number'] ?? null], $emitter, $client); }
        catch (Throwable $e) { error_log('[FACTURX] build error : ' . $e->getMessage()); }
    }

    // Fabrique le PDF (Factur-X/PDF-A3 si XML dispo, sinon PDF classique) avec repli.
    $render_pdf = function (bool $with_facturx) use ($html, $invoice, $full_path, $facturx_xml): bool {
        if (!class_exists('\Mpdf\Mpdf')) return false;
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 15, 'margin_bottom' => 15,
            'tempDir' => sys_get_temp_dir(),
        ]);
        if ($with_facturx && $facturx_xml) {
            // PDF/A-3 requis pour embarquer le XML Factur-X
            $mpdf->PDFA = true;
            $mpdf->PDFAauto = true;
            if (property_exists($mpdf, 'PDFAversion')) $mpdf->PDFAversion = '3-B';
        }
        $mpdf->SetTitle((ak_asso_is_credit_note($invoice) ? 'Avoir ' : 'Facture ') . $invoice['invoice_number']);
        $mpdf->WriteHTML($html);
        if ($with_facturx && $facturx_xml) {
            $mpdf->SetAssociatedFiles([[
                'name' => 'factur-x.xml',
                'mime' => 'text/xml',
                'description' => 'Facture électronique Factur-X',
                'AFRelationship' => 'Alternative',
                'content' => $facturx_xml,
            ]]);
            if (method_exists($mpdf, 'SetAdditionalXmpRdf')) $mpdf->SetAdditionalXmpRdf(ak_facturx_xmp());
        }
        $mpdf->Output($full_path, \Mpdf\Output\Destination::FILE);
        return true;
    };

    $pdf_generated = false;
    $facturx_embedded = false;
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('\Mpdf\Mpdf')) {
            // 1) Tentative Factur-X (PDF/A-3 + XML embarqué)
            if ($facturx_xml) {
                try { $pdf_generated = $render_pdf(true); $facturx_embedded = $pdf_generated; }
                catch (Throwable $e) { error_log('[FACTURX] embed error, repli PDF classique : ' . $e->getMessage()); }
            }
            // 2) Repli : PDF classique si Factur-X indisponible ou en échec
            if (!$pdf_generated) {
                try { $pdf_generated = $render_pdf(false); }
                catch (Throwable $e) { error_log('[ASSO INVOICE PDF] mPDF error : ' . $e->getMessage()); }
            }
        }
    }

    if (!$pdf_generated) {
        // Fallback HTML (même nom non devinable)
        $filename = $base_name . '.html';
        $html_path = $dir . '/' . $filename;
        file_put_contents($html_path, $html);
        $full_path = $html_path;
    }

    $relative_path = '/uploads/asso-invoices/' . $filename;
    $pdo->prepare("UPDATE asso_invoices SET pdf_path = :p, pdf_generated_at = NOW() WHERE id = :id")
        ->execute([':p' => $relative_path, ':id' => $invoice_id]);

    return $relative_path;
}

/**
 * Stats d'un client : CA total, nb factures, retards, etc.
 * Même périmètre que le hub Facturation : seules les factures émises comptent
 * (payées, en attente, en retard) ; brouillons et factures annulées sont exclus.
 * « En attente » = non échue, « En retard » = échue et non payée (pas de double compte).
 */
function ak_asso_client_stats(PDO $pdo, int $client_id, ?int $org_id = null): array
{
    $params = [':cid' => $client_id];
    $org_sql = '';
    if ($org_id !== null) { $org_sql = ' AND org_id = :org'; $params[':org'] = $org_id; }
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS nb_invoices,
            SUM(amount_ttc_cents) AS total_ttc_cents,
            SUM(CASE WHEN status = 'paid' THEN amount_ttc_cents ELSE 0 END) AS paid_ttc_cents,
            SUM(CASE WHEN status = 'pending' AND (due_at IS NULL OR due_at >= NOW()) THEN amount_ttc_cents ELSE 0 END) AS pending_ttc_cents,
            SUM(CASE WHEN status = 'overdue' OR (status='pending' AND due_at < NOW()) THEN amount_ttc_cents ELSE 0 END) AS overdue_ttc_cents,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS nb_paid,
            SUM(CASE WHEN status = 'pending' AND (due_at IS NULL OR due_at >= NOW()) THEN 1 ELSE 0 END) AS nb_pending,
            SUM(CASE WHEN status = 'overdue' OR (status='pending' AND due_at < NOW()) THEN 1 ELSE 0 END) AS nb_overdue,
            MAX(issued_at) AS last_invoice_at
        FROM asso_invoices
        WHERE client_id = :cid AND status IN ('paid','pending','overdue'){$org_sql}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'nb_invoices'        => (int)($row['nb_invoices'] ?? 0),
        'total_ttc_cents'    => (int)($row['total_ttc_cents'] ?? 0),
        'paid_ttc_cents'     => (int)($row['paid_ttc_cents'] ?? 0),
        'pending_ttc_cents'  => (int)($row['pending_ttc_cents'] ?? 0),
        'overdue_ttc_cents'  => (int)($row['overdue_ttc_cents'] ?? 0),
        'nb_paid'            => (int)($row['nb_paid'] ?? 0),
        'nb_pending'         => (int)($row['nb_pending'] ?? 0),
        'nb_overdue'         => (int)($row['nb_overdue'] ?? 0),
        'last_invoice_at'    => $row['last_invoice_at'] ?? null,
    ];
}

/**
 * Identité légale de l'émetteur imprimée sur les factures et devis, alignée sur
 * le vendeur déclaré dans le XML Factur-X (facturx-helpers.php) : raison sociale
 * (legal_name, sinon nom usuel), SIREN (+ SIRET s'il est connu), n° de TVA si assujetti.
 * $emitter = snapshot de la ligne organizations figé à l'émission.
 */
function ak_asso_emitter_identity(array $emitter): array
{
    $name = trim((string)($emitter['legal_name'] ?? ''));
    if ($name === '') $name = trim((string)($emitter['legal_name_effective'] ?? ''));
    if ($name === '') $name = trim((string)($emitter['name'] ?? ''));
    if ($name === '') $name = 'Association';

    $siret = preg_replace('/\D/', '', (string)($emitter['siret'] ?? ''));
    $siren = preg_replace('/\D/', '', (string)($emitter['siren'] ?? ''));
    if ($siren === '' && strlen($siret) === 14) $siren = substr($siret, 0, 9);   // comme ak_fx_siren()
    $vat_subject = !empty($emitter['vat_subject']);
    $vat_number = $vat_subject ? strtoupper(preg_replace('/\s+/', '', (string)($emitter['vat_number'] ?? ''))) : '';

    return [
        'name'        => $name,
        'siret'       => strlen($siret) === 14 ? trim(chunk_split(substr($siret, 0, 9), 3, ' ')) . ' ' . substr($siret, 9) : '',
        'siren'       => strlen($siren) === 9 ? trim(chunk_split($siren, 3, ' ')) : trim((string)($emitter['siren'] ?? '')),
        'vat_subject' => $vat_subject,
        'vat_number'  => $vat_number,
    ];
}

/**
 * Mentions TVA d'une facture ou d'un devis selon le régime de l'émetteur :
 *  - non assujetti (vat_subject vide) : franchise en base, art. 293 B du CGI ;
 *  - assujetti sans TVA sur le document : opération exonérée (la mention 293 B
 *    est réservée aux non-assujettis) ;
 *  - assujetti, certaines lignes sans TVA : précision pour ces lignes.
 * Retourne ['zero_label' => libellé de la ligne TVA quand elle vaut 0,
 *           'notice' => mention à imprimer, ou null].
 */
function ak_asso_vat_mentions(array $emitter, int $vat_cents, array $lines = []): array
{
    if (empty($emitter['vat_subject'])) {
        return [
            'zero_label' => 'TVA non applicable',
            'notice'     => $vat_cents === 0 ? 'TVA non applicable — article 293 B du Code Général des Impôts.' : null,
        ];
    }
    if ($vat_cents === 0) {
        return ['zero_label' => 'TVA (exonération)', 'notice' => 'Exonération de TVA — article 261 du Code Général des Impôts.'];
    }
    $untaxed = false;
    foreach ($lines as $l) {
        if (!isset($l['vat_rate']) || $l['vat_rate'] === null || (float)$l['vat_rate'] <= 0) { $untaxed = true; break; }
    }
    return [
        'zero_label' => 'TVA',
        'notice'     => $untaxed ? 'Lignes sans TVA : opérations exonérées — article 261 du Code Général des Impôts.' : null,
    ];
}

/**
 * Conditions de règlement obligatoires sur une facture entre professionnels
 * (art. L441-9 et L441-10 du Code de commerce) : pas d'escompte, pénalités de
 * retard et indemnité forfaitaire de 40 €. Rien pour un client particulier.
 */
function ak_asso_payment_terms(array $client): ?string
{
    if (($client['client_type'] ?? 'company') === 'individual') return null;
    return 'Pas d’escompte pour paiement anticipé. Tout retard de paiement entraîne de plein droit '
         . 'des pénalités au taux de trois fois le taux d’intérêt légal, ainsi qu’une indemnité '
         . 'forfaitaire de 40 € pour frais de recouvrement (articles L441-10 et D441-5 du Code de commerce).';
}

/**
 * Condition SQL « association en activité » pour les envois et générations automatiques
 * (relances, factures récurrentes) : ni supprimée, ni suspendue (essai expiré, impayé,
 * décision du Fondateur), ni résiliée. $alias = alias de la table organizations.
 * Une association réactivée reprend là où elle en était (cadences tirées des journaux).
 */
function ak_asso_org_active_sql(string $alias = 'o'): string
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'o';
    return "{$a}.deleted_at IS NULL AND ({$a}.status IS NULL OR {$a}.status NOT IN ('suspended','cancelled'))";
}

/**
 * « Délai de paiement par défaut » (jours) des paramètres de facturation de l'association
 * (/mon-asso-factures-parametres). 30 jours s'il n'est pas renseigné ou hors bornes (0 à 365).
 */
function ak_asso_default_due_days(PDO $pdo, int $org_id): int
{
    try {
        $st = $pdo->prepare("SELECT * FROM org_invoice_settings WHERE org_id = ? LIMIT 1");
        $st->execute([$org_id]);
        $v = ($st->fetch(PDO::FETCH_ASSOC) ?: [])['default_due_days'] ?? null;
    } catch (Throwable $e) {
        $v = null;
    }
    if ($v === null || $v === '' || !is_numeric($v)) return 30;
    $v = (int)$v;
    return ($v >= 0 && $v <= 365) ? $v : 30;
}

/** Moyens de règlement proposés pour constater un encaissement (valeur => libellé). */
function ak_asso_payment_methods(): array
{
    return [
        'virement'   => 'Virement bancaire',
        'cheque'     => 'Chèque',
        'especes'    => 'Espèces',
        'cb'         => 'Carte bancaire',
        'prelevement'=> 'Prélèvement',
        'autre'      => 'Autre',
    ];
}

/**
 * Encaissement constaté par l'association : bouton « Marquer comme payée » du site
 * (fiche et liste des factures), action mark_paid de l'application, confirmation d'un
 * paiement déclaré par le client sur la page publique.
 * Seule une facture émise et non réglée (en attente ou en retard) est encaissable.
 * Écritures identiques à la confirmation du widget de relances : statut payé, date,
 * qui a confirmé, moyen et référence, ligne dans l'historique des règlements.
 *
 * $opts : paid_at (AAAA-MM-JJ, défaut aujourd'hui ; ni future ni de plus de 5 ans),
 *         method (clé de ak_asso_payment_methods(), défaut 'declared'), reference,
 *         source ('admin' par défaut ; 'stripe' : paiement en ligne reçu par Stripe Connect,
 *         stripe-connect-helpers.php), online_payment_id (asso_online_payments.id).
 * $user_id 0 : aucun utilisateur (paiement en ligne enregistré par le webhook).
 * Retourne ['ok' => bool, 'code' => 'ok'|'not_found'|'state', 'message' => string,
 *           'invoice_number' => string, 'amount_cents' => int] (+ 'state_label' si 'state').
 */
function ak_asso_invoice_mark_paid(PDO $pdo, int $org_id, int $invoice_id, int $user_id, array $opts = []): array
{
    $paid_at = trim((string)($opts['paid_at'] ?? ''));
    if ($paid_at !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $paid_at);
        if (!$d || $d->format('Y-m-d') !== $paid_at
            || $paid_at > date('Y-m-d') || $paid_at < date('Y-m-d', strtotime('-5 years'))) {
            $paid_at = '';
        }
    }
    // Saisie du jour : heure courante ; date passée : midi (pas de décalage de jour à l'affichage)
    $paid_at_full = $paid_at === '' || $paid_at === date('Y-m-d') ? date('Y-m-d H:i:s') : $paid_at . ' 12:00:00';
    $method = (string)($opts['method'] ?? '');
    if (!array_key_exists($method, ak_asso_payment_methods())) $method = 'declared';
    $reference = trim((string)($opts['reference'] ?? ''));
    $reference = $reference === '' ? null : mb_substr($reference, 0, 255);
    $uid = $user_id > 0 ? $user_id : null;
    // Paiement en ligne : « stripe » si la colonne l'accepte (migration 2026-10-30), sinon « admin » comme avant
    $source = 'admin';
    if (($opts['source'] ?? '') === 'stripe') {
        if (!function_exists('ak_db_pick')) require_once __DIR__ . '/trial-helpers.php';
        $source = ak_db_pick($pdo, 'asso_invoice_payments', 'source', ['stripe', 'admin']) ?? 'admin';
    }
    $online_id = (int)($opts['online_payment_id'] ?? 0);
    $payment_id = 0;

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
        // Verrou de ligne : un double clic ne crée pas deux règlements
        $st = $pdo->prepare("SELECT id, status, invoice_number, amount_ttc_cents FROM asso_invoices
                             WHERE id = ? AND org_id = ? LIMIT 1 FOR UPDATE");
        $st->execute([$invoice_id, $org_id]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            if ($ownTx) $pdo->rollBack();
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Facture introuvable.', 'invoice_number' => '', 'amount_cents' => 0];
        }
        $status = (string)$inv['status'];
        if (!in_array($status, ['pending', 'overdue'], true)) {
            if ($ownTx) $pdo->rollBack();
            $lbl = ['paid' => 'déjà payée', 'draft' => 'encore en brouillon', 'cancelled' => 'annulée', 'refunded' => 'remboursée'];
            $state = $lbl[$status] ?? 'dans un état non encaissable';
            return [
                'ok' => false, 'code' => 'state', 'state_label' => $state,
                'message' => 'La facture ' . $inv['invoice_number'] . ' est ' . $state . '.',
                'invoice_number' => (string)$inv['invoice_number'], 'amount_cents' => (int)$inv['amount_ttc_cents'],
            ];
        }

        $pdo->prepare("UPDATE asso_invoices SET status = 'paid', paid_at = ?, confirmed_paid_by_org_at = NOW(),
                              confirmed_paid_by_user_id = ?, payment_method = ?, payment_reference = ?, updated_at = NOW()
                       WHERE id = ? AND org_id = ?")
            ->execute([$paid_at_full, $uid, $method, $reference, $invoice_id, $org_id]);
        try {
            $pdo->prepare("INSERT INTO asso_invoice_payments (invoice_id, org_id, amount_cents, paid_at, payment_method, reference, source, recorded_by_user_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$invoice_id, $org_id, (int)$inv['amount_ttc_cents'], $paid_at_full, $method, $reference, $source, $uid]);
            $payment_id = (int)$pdo->lastInsertId();
            if ($online_id > 0 && $payment_id > 0 && isset(ak_db_columns($pdo, 'asso_invoice_payments')['online_payment_id'])) {
                $pdo->prepare("UPDATE asso_invoice_payments SET online_payment_id = ? WHERE id = ?")->execute([$online_id, $payment_id]);
            }
        } catch (Throwable $e) {
            error_log('[ASSO INVOICE MARK PAID] historique : ' . $e->getMessage());
        }

        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // Réglée autrement qu'en ligne : les pages de paiement Stripe encore ouvertes pour elle sont fermées
    if (($opts['source'] ?? '') !== 'stripe') ak_asso_invoice_close_online($pdo, $invoice_id);

    return [
        'ok' => true, 'code' => 'ok',
        'message' => 'Facture ' . $inv['invoice_number'] . ' marquée payée ('
            . number_format(((int)$inv['amount_ttc_cents']) / 100, 2, ',', ' ') . ' €).',
        'invoice_number' => (string)$inv['invoice_number'], 'amount_cents' => (int)$inv['amount_ttc_cents'],
        'payment_id' => $payment_id,
    ];
}

/**
 * Facture qui ne se paie plus en ligne (payée à la main, annulée par un avoir) : ses pages de paiement Stripe
 * encore ouvertes sont fermées (stripe-connect-helpers.php), au mieux et jamais dans une transaction.
 */
function ak_asso_invoice_close_online(PDO $pdo, int $invoice_id): void
{
    try {
        if ($pdo->inTransaction() || !function_exists('ak_db_columns') || !ak_db_columns($pdo, 'asso_online_payments')) return;
        require_once __DIR__ . '/stripe-connect-helpers.php';
        if (function_exists('ak_connect_invoice_closed')) ak_connect_invoice_closed($pdo, $invoice_id);
    } catch (Throwable $e) {
        error_log('[ASSO INVOICE] pages de paiement #' . $invoice_id . ' : ' . $e->getMessage());
    }
}

/**
 * Jeton de formulaire à usage unique (création d'une facture ou d'un devis) : un double clic
 * ou un formulaire renvoyé ne crée pas deux pièces avec deux numéros. Les requêtes d'une même
 * session PHP sont traitées l'une après l'autre (verrou du fichier de session) : la seconde
 * voit le jeton déjà consommé par la première.
 */
function ak_asso_form_nonce_issue(string $form): string
{
    $nonce = bin2hex(random_bytes(16));
    $list = $_SESSION['ak_form_nonces'][$form] ?? [];
    if (!is_array($list)) $list = [];
    // Plusieurs onglets possibles ; on garde les 20 derniers jetons de moins de 24 h
    $list = array_filter($list, fn($v) => is_array($v) && ($v['t'] ?? 0) > time() - 86400);
    $list[$nonce] = ['t' => time(), 'id' => 0];
    $_SESSION['ak_form_nonces'][$form] = array_slice($list, -20, null, true);
    return $nonce;
}

/** Id de la pièce déjà créée avec ce jeton (> 0), ou 0 s'il est encore libre ou inconnu. */
function ak_asso_form_nonce_used(string $form, string $nonce): int
{
    if ($nonce === '') return 0;
    $v = $_SESSION['ak_form_nonces'][$form][$nonce] ?? null;
    return is_array($v) ? (int)($v['id'] ?? 0) : 0;
}

/** Marque le jeton comme consommé par la pièce $created_id. */
function ak_asso_form_nonce_consume(string $form, string $nonce, int $created_id): void
{
    if ($nonce === '' || $created_id <= 0) return;
    $_SESSION['ak_form_nonces'][$form][$nonce] = ['t' => time(), 'id' => $created_id];
}

/**
 * Script commun aux formulaires facture / devis (création et modification) :
 *  - akLineCents(q, p, r) : montants d'une ligne en centimes, arrondis exactement comme
 *    ak_asso_line_compute() (quantité à 2 décimales, prix au centime, TVA arrondie par ligne),
 *    pour que l'aperçu affiche les totaux du document final ;
 *  - akVatSelected(optionValue, storedRate) : taux enregistré (« 20.00 », 5.5…) comparé en nombre ;
 *  - akGuardSubmit(form, label) : un seul envoi (boutons désactivés, bouton cliqué conservé).
 */
function ak_asso_invoice_form_js(): string
{
    return <<<'JS'
// Arrondi de PHP round() : demi à l'écart de zéro, après correction des erreurs de flottants
function akRound(x, d) {
    const m = Math.pow(10, d || 0);
    const v = Number((Math.abs(x) * m).toPrecision(15));
    return Math.sign(x) * Math.round(v) / m;
}
function akLineCents(q, p, r) {
    const qty = akRound(q || 0, 2);
    const unit = akRound(akRound(p || 0, 2) * 100, 0);
    const ht = akRound(qty * unit, 0);
    const vat = r > 0 ? akRound(ht * r / 100, 0) : 0;
    return { ht: ht, vat: vat };
}
function akVatSelected(optionValue, stored) {
    const empty = stored === '' || stored === null || stored === undefined || !(parseFloat(stored) > 0);
    if (optionValue === '') return empty;
    return !empty && parseFloat(optionValue) === parseFloat(stored);
}
function akGuardSubmit(form, label) {
    if (!form) return;
    const buttons = () => form.querySelectorAll('button[type="submit"], button:not([type])');
    form.addEventListener('submit', function (e) {
        // Second envoi (double clic, Entrée répétée) : ignoré
        if (form.dataset.sending === '1') { e.preventDefault(); return; }
        form.dataset.sending = '1';
        // Boutons neutralisés sans « disabled » : un bouton désactivé n'est pas transmis,
        // et « Enregistrer en brouillon » (save_draft) doit le rester.
        const sub = e.submitter;
        buttons().forEach(function (b) {
            b.setAttribute('aria-disabled', 'true');
            b.style.pointerEvents = 'none';
            b.style.opacity = '0.6';
        });
        if (sub && label) { sub.dataset.akLabel = sub.textContent; sub.textContent = label; }
    });
    // Retour arrière du navigateur (page restaurée) : formulaire de nouveau utilisable
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) return;
        form.dataset.sending = '';
        buttons().forEach(function (b) {
            b.removeAttribute('aria-disabled');
            b.style.pointerEvents = '';
            b.style.opacity = '';
            if (b.dataset.akLabel) { b.textContent = b.dataset.akLabel; delete b.dataset.akLabel; }
        });
    });
}
JS;
}

} // fin if (!function_exists)
