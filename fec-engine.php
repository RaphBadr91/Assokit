<?php
/**
 * fec-engine.php — Export FEC (Fichier des Écritures Comptables)
 * ------------------------------------------------------------------
 * Génère un FEC conforme à l'article A47 A-1 du LPF (arrêté du 29/07/2013) :
 * 18 champs obligatoires, séparateur TABULATION, une ligne d'écriture par
 * mouvement, chaque écriture équilibrée (débit = crédit).
 *
 * Les écritures sont RECONSTITUÉES à partir des données Assokit :
 *   - Ventes (asso_invoices)          -> journal VT : 411 / 706 + 44571
 *   - Encaissements factures (paid)   -> journal BQ : 512 / 411
 *   - Cotisations encaissées          -> journal BQ : 512 / 756
 *   - Achats fournisseurs (validés)   -> journal AC : 606 + 44566 / 401
 *
 * ⚠️ Assokit n'est pas un logiciel de comptabilité en partie double :
 *    ce FEC est une reconstitution fidèle des flux, à faire valider par
 *    l'expert-comptable. Chaque écriture est néanmoins équilibrée.
 *
 * Montants : asso_invoices en CENTIMES ; cotisation_payments & project_invoices
 * en EUROS (float). Sortie FEC en euros, virgule décimale, 2 décimales.
 * ------------------------------------------------------------------
 */

if (!defined('FEC_SEP')) define('FEC_SEP', "\t");

if (!function_exists('fec_fields')) {
function fec_fields(): array {
    return ['JournalCode','JournalLib','EcritureNum','EcritureDate','CompteNum','CompteLib',
        'CompAuxNum','CompAuxLib','PieceRef','PieceDate','EcritureLib','Debit','Credit',
        'EcritureLet','DateLet','ValidDate','Montantdevise','Idevise'];
}
}

if (!function_exists('fec_amount')) {
/** Montant FEC : virgule décimale, 2 décimales, pas de séparateur de milliers. */
function fec_amount(float $v): string { return number_format($v, 2, ',', ''); }
}
if (!function_exists('fec_date')) {
function fec_date(?string $d): string { return $d ? date('Ymd', strtotime($d)) : ''; }
}
if (!function_exists('fec_clean')) {
/** Nettoie un libellé : pas de tabulation ni de saut de ligne. */
function fec_clean(?string $s): string { return trim(preg_replace('/[\t\r\n]+/', ' ', (string)$s)); }
}

if (!function_exists('fec_line')) {
/** Construit une ligne d'écriture (tableau associatif des 18 champs). */
function fec_line(array $o): array {
    return [
        'JournalCode'   => $o['jcode'],
        'JournalLib'    => $o['jlib'],
        'EcritureNum'   => $o['num'],
        'EcritureDate'  => fec_date($o['edate']),
        'CompteNum'     => $o['compte'],
        'CompteLib'     => fec_clean($o['compte_lib']),
        'CompAuxNum'    => $o['aux'] ?? '',
        'CompAuxLib'    => fec_clean($o['aux_lib'] ?? ''),
        'PieceRef'      => fec_clean($o['piece'] ?? ''),
        'PieceDate'     => fec_date($o['pdate'] ?? $o['edate']),
        'EcritureLib'   => fec_clean($o['lib']),
        'Debit'         => fec_amount($o['debit'] ?? 0.0),
        'Credit'        => fec_amount($o['credit'] ?? 0.0),
        'EcritureLet'   => '',
        'DateLet'       => '',
        'ValidDate'     => fec_date($o['vdate'] ?? $o['edate']),
        'Montantdevise' => '',
        'Idevise'       => '',
    ];
}
}

if (!function_exists('fec_build')) {
/**
 * Construit toutes les écritures FEC de l'org pour l'exercice $year.
 * @return array{rows:array<array>, stats:array}
 */
function fec_build(PDO $pdo, int $org_id, int $year): array {
    $rows = [];
    $start = sprintf('%04d-01-01', $year);
    $end   = sprintf('%04d-12-31', $year);
    $seq = ['VT'=>0, 'AC'=>0, 'BQ'=>0];
    $nb_sales = 0; $nb_cotis = 0; $nb_buy = 0; $tot_debit = 0.0; $tot_credit = 0.0;

    $add = function(array $o) use (&$rows, &$tot_debit, &$tot_credit) {
        $rows[] = fec_line($o);
        $tot_debit  += (float)($o['debit']  ?? 0);
        $tot_credit += (float)($o['credit'] ?? 0);
    };

    $partial = false; // vrai si une catégorie d'écritures a échoué (n'annonce pas "prêt")

    // ── VENTES (asso_invoices non brouillon, émises dans l'exercice) ──
    try {
        $st = $pdo->prepare(
            "SELECT i.id, i.invoice_number, i.amount_ht_cents, i.amount_vat_cents, i.amount_ttc_cents,
                    DATE(i.issued_at) issued, i.client_id, c.display_name client
             FROM asso_invoices i LEFT JOIN asso_clients c ON c.id = i.client_id AND c.org_id = i.org_id
             WHERE i.org_id = :o AND i.status <> 'draft' AND DATE(i.issued_at) BETWEEN :s AND :e
             ORDER BY i.issued_at ASC, i.id ASC");
        $st->execute([':o'=>$org_id, ':s'=>$start, ':e'=>$end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $ttc = (int)$i['amount_ttc_cents']/100;
            if (abs($ttc) < 0.005) continue;                 // I2 : ne sauter que le zéro (les avoirs < 0 sont traités)
            $tva = (int)$i['amount_vat_cents']/100;
            $avoir = $ttc < 0;
            $a_ttc = abs($ttc); $a_tva = abs($tva);
            $a_ht = round($a_ttc - $a_tva, 2);               // I1 : HT dérivé = TTC - TVA -> écriture équilibrée par construction
            if ($a_ht < 0) { $a_ht = $a_ttc; $a_tva = 0.0; }
            $seq['VT']++; $num = 'VT'.$seq['VT']; $nb_sales++;
            $aux = $i['client_id'] ? 'C'.(int)$i['client_id'] : '';
            $lib = ($avoir ? 'Avoir ' : 'Facture ').$i['invoice_number'].($i['client'] ? ' - '.$i['client'] : '');
            $base = ['jcode'=>'VT','jlib'=>'Ventes','num'=>$num,'edate'=>$i['issued'],'pdate'=>$i['issued'],'piece'=>$i['invoice_number'],'lib'=>$lib];
            // Sens normal pour une facture, inversé pour un avoir.
            $add($base + ['compte'=>'411000','compte_lib'=>'Clients','aux'=>$aux,'aux_lib'=>$i['client'] ?? '', ($avoir?'credit':'debit')=>$a_ttc]);
            $add($base + ['compte'=>'706000','compte_lib'=>'Prestations de services', ($avoir?'debit':'credit')=>$a_ht]);
            if ($a_tva > 0.001) $add($base + ['compte'=>'445710','compte_lib'=>'TVA collectée', ($avoir?'debit':'credit')=>$a_tva]);
        }
    } catch (Throwable $e) { $partial = true; error_log('[fec] ventes: '.$e->getMessage()); }

    // ── ENCAISSEMENTS clients (filtrés sur la date de RÈGLEMENT — B1) ──
    try {
        $st = $pdo->prepare(
            "SELECT i.invoice_number, i.amount_ttc_cents, DATE(i.paid_at) paid, i.client_id, c.display_name client
             FROM asso_invoices i LEFT JOIN asso_clients c ON c.id = i.client_id AND c.org_id = i.org_id
             WHERE i.org_id = :o AND i.status = 'paid' AND i.paid_at IS NOT NULL
               AND DATE(i.paid_at) BETWEEN :s AND :e
             ORDER BY i.paid_at ASC, i.id ASC");
        $st->execute([':o'=>$org_id, ':s'=>$start, ':e'=>$end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $ttc = (int)$i['amount_ttc_cents']/100;
            if (abs($ttc) < 0.005) continue;
            $avoir = $ttc < 0; $a = abs($ttc);
            $seq['BQ']++; $num = 'BQ'.$seq['BQ'];
            $aux = $i['client_id'] ? 'C'.(int)$i['client_id'] : '';
            $bb = ['jcode'=>'BQ','jlib'=>'Banque','num'=>$num,'edate'=>$i['paid'],'pdate'=>$i['paid'],'piece'=>$i['invoice_number'],'lib'=>'Règlement '.$i['invoice_number']];
            $add($bb + ['compte'=>'512000','compte_lib'=>'Banque', ($avoir?'credit':'debit')=>$a]);
            $add($bb + ['compte'=>'411000','compte_lib'=>'Clients','aux'=>$aux,'aux_lib'=>$i['client'] ?? '', ($avoir?'debit':'credit')=>$a]);
        }
    } catch (Throwable $e) { $partial = true; error_log('[fec] encaissements: '.$e->getMessage()); }

    // ── COTISATIONS encaissées ──
    try {
        $st = $pdo->prepare(
            "SELECT id, amount, DATE(paid_at) paid, payer_name, reference
             FROM cotisation_payments
             WHERE org_id = :o AND COALESCE(status,'paid') = 'paid' AND DATE(paid_at) BETWEEN :s AND :e
             ORDER BY paid_at ASC, id ASC");
        $st->execute([':o'=>$org_id, ':s'=>$start, ':e'=>$end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $amt = (float)$p['amount']; if (abs($amt) < 0.005) continue;
            $avoir = $amt < 0; $a = abs($amt);
            $seq['BQ']++; $num = 'BQ'.$seq['BQ']; $nb_cotis++;
            $piece = $p['reference'] ?: ('COT'.(int)$p['id']);
            $lib = 'Cotisation'.($p['payer_name'] ? ' - '.$p['payer_name'] : '');
            $bb = ['jcode'=>'BQ','jlib'=>'Banque','num'=>$num,'edate'=>$p['paid'],'pdate'=>$p['paid'],'piece'=>$piece,'lib'=>$lib];
            $add($bb + ['compte'=>'512000','compte_lib'=>'Banque', ($avoir?'credit':'debit')=>$a]);
            $add($bb + ['compte'=>'756000','compte_lib'=>'Cotisations', ($avoir?'debit':'credit')=>$a]);
        }
    } catch (Throwable $e) { $partial = true; error_log('[fec] cotisations: '.$e->getMessage()); }

    // ── ACHATS fournisseurs (project_invoices validées) ──
    try {
        $st = $pdo->prepare(
            "SELECT pi.id, pi.supplier_name, pi.amount_ht, pi.amount_ttc, DATE(pi.invoice_date) idate
             FROM project_invoices pi JOIN projects p ON p.id = pi.project_id
             WHERE p.org_id = :o AND pi.status = 'validated' AND DATE(pi.invoice_date) BETWEEN :s AND :e
             ORDER BY pi.invoice_date ASC, pi.id ASC");
        $st->execute([':o'=>$org_id, ':s'=>$start, ':e'=>$end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $ttc = (float)$b['amount_ttc'];
            if (abs($ttc) < 0.005) continue;
            $ht = (float)($b['amount_ht'] ?: $b['amount_ttc']);
            $avoir = $ttc < 0; $a_ttc = abs($ttc); $a_ht = abs($ht);
            if ($a_ht > $a_ttc) $a_ht = $a_ttc;              // I3 : HT ne peut excéder TTC
            $a_tva = round($a_ttc - $a_ht, 2);
            $seq['AC']++; $num = 'AC'.$seq['AC']; $nb_buy++;
            $lib = ($avoir ? 'Avoir achat' : 'Achat').($b['supplier_name'] ? ' - '.$b['supplier_name'] : '');
            $base = ['jcode'=>'AC','jlib'=>'Achats','num'=>$num,'edate'=>$b['idate'],'pdate'=>$b['idate'],'piece'=>'FRN'.(int)$b['id'],'lib'=>$lib];
            $add($base + ['compte'=>'606000','compte_lib'=>'Achats', ($avoir?'credit':'debit')=>$a_ht]);
            if ($a_tva > 0.001) $add($base + ['compte'=>'445660','compte_lib'=>'TVA déductible', ($avoir?'credit':'debit')=>$a_tva]);
            $add($base + ['compte'=>'401000','compte_lib'=>'Fournisseurs','aux'=>'F'.(int)$b['id'],'aux_lib'=>$b['supplier_name'] ?? '', ($avoir?'debit':'credit')=>$a_ttc]);
        }
    } catch (Throwable $e) { $partial = true; error_log('[fec] achats: '.$e->getMessage()); }

    return ['rows'=>$rows, 'stats'=>[
        'lines'=>count($rows), 'sales'=>$nb_sales, 'cotis'=>$nb_cotis, 'buys'=>$nb_buy,
        'debit'=>round($tot_debit,2), 'credit'=>round($tot_credit,2),
        'partial'=>$partial,
        'balanced'=>!$partial && abs($tot_debit - $tot_credit) < 0.01,
    ]];
}
}

if (!function_exists('fec_render')) {
/** Rend le FEC en texte (en-tête + lignes, séparateur TAB). */
function fec_render(array $rows): string {
    $fields = fec_fields();
    $out = implode(FEC_SEP, $fields)."\r\n";
    foreach ($rows as $r) {
        $line = [];
        foreach ($fields as $f) $line[] = $r[$f] ?? '';
        $out .= implode(FEC_SEP, $line)."\r\n";
    }
    return $out;
}
}

if (!function_exists('fec_parse_amount')) {
/** Reconvertit un montant FEC ("1200,00") en float. */
function fec_parse_amount(string $s): float { return (float)str_replace([' ',','], ['','.'], $s); }
}

if (!function_exists('fec_balance')) {
/**
 * Balance comptable : agrège les lignes FEC par compte.
 * @return array<array{compte:string,lib:string,debit:float,credit:float,solde:float}>
 */
function fec_balance(array $rows): array {
    $acc = [];
    foreach ($rows as $r) {
        $c = $r['CompteNum'];
        if (!isset($acc[$c])) $acc[$c] = ['compte'=>$c, 'lib'=>$r['CompteLib'], 'debit'=>0.0, 'credit'=>0.0];
        $acc[$c]['debit']  += fec_parse_amount($r['Debit']);
        $acc[$c]['credit'] += fec_parse_amount($r['Credit']);
    }
    foreach ($acc as &$a) $a['solde'] = round($a['debit'] - $a['credit'], 2);
    unset($a);
    ksort($acc);
    return array_values($acc);
}
}

if (!function_exists('fec_ledger')) {
/**
 * Grand livre : lignes groupées par compte, triées par date.
 * @return array<string,array{lib:string,lines:array,debit:float,credit:float}>
 */
function fec_ledger(array $rows): array {
    $led = [];
    foreach ($rows as $r) {
        $c = $r['CompteNum'];
        if (!isset($led[$c])) $led[$c] = ['lib'=>$r['CompteLib'], 'lines'=>[], 'debit'=>0.0, 'credit'=>0.0];
        $led[$c]['lines'][] = $r;
        $led[$c]['debit']  += fec_parse_amount($r['Debit']);
        $led[$c]['credit'] += fec_parse_amount($r['Credit']);
    }
    ksort($led);
    foreach ($led as &$g) {
        usort($g['lines'], fn($a,$b) => strcmp($a['EcritureDate'], $b['EcritureDate']));
    }
    unset($g);
    return $led;
}
}

if (!function_exists('fec_filename')) {
/** Nom de fichier normalisé : <SIREN>FEC<AAAAMMJJ clôture>.txt */
function fec_filename(PDO $pdo, int $org_id, int $year): string {
    $siren = '';
    try {
        $st = $pdo->prepare("SELECT siren, siret FROM organizations WHERE id = ?");
        $st->execute([$org_id]);
        if ($o = $st->fetch()) {
            $siren = preg_replace('/\D/', '', (string)($o['siren'] ?? ''));
            if ($siren === '' && !empty($o['siret'])) $siren = substr(preg_replace('/\D/', '', $o['siret']), 0, 9);
        }
    } catch (Throwable $e) {}
    if ($siren === '') $siren = 'ORG'.$org_id;
    return $siren.'FEC'.sprintf('%04d1231', $year).'.txt';
}
}
