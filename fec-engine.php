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
 *   - Cotisations encaissées          -> journal BQ : 512 / 756 (campagne de dons : 512 / 754100 Dons manuels)
 *   - Frais Stripe des paiements en ligne (Stripe Connect) -> journal BQ : 627800 / 512
 *   - Achats fournisseurs (validés)   -> journal AC : 6xxxx (poste du plan
 *     comptable associatif, comme le bilan analytique) + 44566 / 401.
 *     Association non assujettie à la TVA : charge au TTC, sans 44566.
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

require_once __DIR__ . '/pca-mapping.php';   // comptes de charge (plan comptable associatif)

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

if (!function_exists('fec_supplier_aux')) {
/**
 * Compte auxiliaire fournisseur : un par fournisseur (nom normalisé),
 * et non plus un par facture. Sans nom : « FDIVERS ». Un nom de plus de
 * 20 caractères garde ses 16 premiers et 4 caractères tirés du nom
 * complet : deux fournisseurs au même début ne partagent pas un compte.
 */
function fec_supplier_aux(?string $supplier): string {
    $s = function_exists('mb_strtoupper') ? mb_strtoupper(trim((string)$supplier), 'UTF-8') : strtoupper(trim((string)$supplier));
    $s = strtr($s, ['É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','À'=>'A','Â'=>'A','Ä'=>'A','Ç'=>'C','Ô'=>'O','Ö'=>'O','Û'=>'U','Ü'=>'U','Î'=>'I','Ï'=>'I','Ù'=>'U','Œ'=>'OE','Æ'=>'AE']);
    $s = preg_replace('/[^A-Z0-9]/', '', $s);
    if ($s === '') return 'FDIVERS';
    if (strlen($s) > 20) $s = substr($s, 0, 16) . strtoupper(substr(md5($s), 0, 4));
    return 'F' . $s;
}
}

if (!function_exists('fec_charge_account')) {
/**
 * Compte de charge d'un achat : celui du plan comptable associatif déjà
 * utilisé par le bilan analytique (catégorie de la facture, ou poste choisi
 * à la main), complété à 6 chiffres. Un même numéro garde un seul libellé
 * dans tout le fichier.
 * @return array{0:string,1:string} [CompteNum, CompteLib]
 */
function fec_charge_account(?string $category, ?string $override): array {
    $m = ak_pca_map($category, $override);
    $compte = (string)$m['compte'];
    $lib = (string)$m['libelle'];
    $comptes = ak_pca_comptes();
    if (isset($comptes[$compte])) $lib = $comptes[$compte]['label'];
    // 60680 sert aussi aux catégories non reconnues : libellé du PCG (6068).
    if ($compte === '60680') $lib = 'AUTRES MATIERES ET FOURNITURES';
    return [str_pad($compte, 6, '0'), $lib];
}
}

if (!function_exists('fec_build')) {
/**
 * Construit toutes les écritures FEC de l'org pour l'exercice $year.
 *
 * Les écritures sont d'abord collectées sans numéro, puis numérotées par
 * journal dans l'ordre chronologique (règlements et cotisations ensemble
 * dans BQ), et le fichier est trié par date : l'article A47 A-1 du LPF
 * exige une numérotation continue et chronologique.
 * @return array{rows:array<array>, stats:array}
 */
function fec_build(PDO $pdo, int $org_id, int $year): array {
    $start = sprintf('%04d-01-01', $year);
    $end   = sprintf('%04d-12-31', $year);
    $nb_sales = 0; $nb_cotis = 0; $nb_buy = 0;

    // Écritures sans numéro : ['j'=>journal, 'date'=>AAAA-MM-JJ, 'lines'=>[...]].
    // L'ordre d'ajout départage deux écritures d'un même jour.
    $entries = [];
    $partial = false; // vrai si une catégorie d'écritures a échoué (n'annonce pas "prêt")

    // Régime de TVA : une association non assujettie ne récupère pas la
    // TVA de ses achats, la charge est le TTC (pas de 445660).
    $vat_subject = true;
    try {
        $st = $pdo->prepare("SELECT vat_subject FROM organizations WHERE id = ?");
        $st->execute([$org_id]);
        $v = $st->fetchColumn();
        if ($v !== false) $vat_subject = !empty($v);
    } catch (Throwable $e) { error_log('[fec] regime TVA: '.$e->getMessage()); }

    // ── VENTES (asso_invoices non brouillon, émises dans l'exercice) ──
    // La TVA portée par la facture est reprise telle quelle : mentionnée sur
    // la facture, elle est due (art. 283-3 du CGI), même sans assujettissement.
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
            $nb_sales++;
            $aux = $i['client_id'] ? 'C'.(int)$i['client_id'] : '';
            $lib = ($avoir ? 'Avoir ' : 'Facture ').$i['invoice_number'].($i['client'] ? ' - '.$i['client'] : '');
            $base = ['jcode'=>'VT','jlib'=>'Ventes','edate'=>$i['issued'],'pdate'=>$i['issued'],'piece'=>$i['invoice_number'],'lib'=>$lib];
            // Sens normal pour une facture, inversé pour un avoir.
            $lines = [];
            $lines[] = $base + ['compte'=>'411000','compte_lib'=>'Clients','aux'=>$aux,'aux_lib'=>$i['client'] ?? '', ($avoir?'credit':'debit')=>$a_ttc];
            $lines[] = $base + ['compte'=>'706000','compte_lib'=>'Prestations de services', ($avoir?'debit':'credit')=>$a_ht];
            if ($a_tva > 0.001) $lines[] = $base + ['compte'=>'445710','compte_lib'=>'TVA collectée', ($avoir?'debit':'credit')=>$a_tva];
            $entries[] = ['j'=>'VT', 'date'=>(string)$i['issued'], 'lines'=>$lines];
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
            $aux = $i['client_id'] ? 'C'.(int)$i['client_id'] : '';
            $bb = ['jcode'=>'BQ','jlib'=>'Banque','edate'=>$i['paid'],'pdate'=>$i['paid'],'piece'=>$i['invoice_number'],'lib'=>'Règlement '.$i['invoice_number']];
            $entries[] = ['j'=>'BQ', 'date'=>(string)$i['paid'], 'lines'=>[
                $bb + ['compte'=>'512000','compte_lib'=>'Banque', ($avoir?'credit':'debit')=>$a],
                $bb + ['compte'=>'411000','compte_lib'=>'Clients','aux'=>$aux,'aux_lib'=>$i['client'] ?? '', ($avoir?'debit':'credit')=>$a],
            ]];
        }
    } catch (Throwable $e) { $partial = true; error_log('[fec] encaissements: '.$e->getMessage()); }

    // ── COTISATIONS encaissées (campagne de dons, migration 2026-10-30 : 754100 Dons manuels) ──
    try {
        $has_kind = false;
        try { $has_kind = (bool)$pdo->query("SHOW COLUMNS FROM cotisation_campaigns LIKE 'kind'")->fetch(); } catch (Throwable $e) {}
        $st = $pdo->prepare(
            "SELECT p.id, p.amount, DATE(p.paid_at) paid, p.payer_name, p.reference, " . ($has_kind ? "c.kind" : "NULL") . " AS kind
             FROM cotisation_payments p" . ($has_kind ? " LEFT JOIN cotisation_campaigns c ON c.id = p.campaign_id AND c.org_id = p.org_id" : "") . "
             WHERE p.org_id = :o AND COALESCE(p.status,'paid') = 'paid' AND DATE(p.paid_at) BETWEEN :s AND :e
             ORDER BY p.paid_at ASC, p.id ASC");
        $st->execute([':o'=>$org_id, ':s'=>$start, ':e'=>$end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $amt = (float)$p['amount']; if (abs($amt) < 0.005) continue;
            $avoir = $amt < 0; $a = abs($amt);
            $nb_cotis++;
            $don = ($p['kind'] ?? '') === 'don';
            $piece = $p['reference'] ?: ('COT'.(int)$p['id']);
            $lib = ($don ? 'Don' : 'Cotisation').($p['payer_name'] ? ' - '.$p['payer_name'] : '');
            $bb = ['jcode'=>'BQ','jlib'=>'Banque','edate'=>$p['paid'],'pdate'=>$p['paid'],'piece'=>$piece,'lib'=>$lib];
            $entries[] = ['j'=>'BQ', 'date'=>(string)$p['paid'], 'lines'=>[
                $bb + ['compte'=>'512000','compte_lib'=>'Banque', ($avoir?'credit':'debit')=>$a],
                $bb + ($don ? ['compte'=>'754100','compte_lib'=>'Dons manuels'] : ['compte'=>'756000','compte_lib'=>'Cotisations']) + [($avoir?'debit':'credit')=>$a],
            ]];
        }
    } catch (Throwable $e) { $partial = true; error_log('[fec] cotisations: '.$e->getMessage()); }

    // ── FRAIS STRIPE des paiements en ligne (Stripe Connect) : le règlement est compté brut (512 / 411 ou 756),
    // les frais prélevés par Stripe sur chaque paiement sont une charge (627800), à la date du paiement ──
    try {
        $has_op = (bool)$pdo->query("SHOW TABLES LIKE 'asso_online_payments'")->fetch();
        if ($has_op) {
            $st = $pdo->prepare(
                "SELECT id, fee_cents, DATE(paid_at) paid, payment_intent_id, kind
                 FROM asso_online_payments
                 WHERE org_id = :o AND fee_cents > 0 AND paid_at IS NOT NULL AND overpaid = 0
                   AND status IN ('paid', 'refunded', 'partially_refunded', 'disputed') AND DATE(paid_at) BETWEEN :s AND :e
                 ORDER BY paid_at ASC, id ASC");
            $st->execute([':o'=>$org_id, ':s'=>$start, ':e'=>$end]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $a = (int)$f['fee_cents'] / 100;
                $piece = $f['payment_intent_id'] ?: ('PEL'.(int)$f['id']);
                $bb = ['jcode'=>'BQ','jlib'=>'Banque','edate'=>$f['paid'],'pdate'=>$f['paid'],'piece'=>$piece,'lib'=>'Frais Stripe paiement en ligne'];
                $entries[] = ['j'=>'BQ', 'date'=>(string)$f['paid'], 'lines'=>[
                    $bb + ['compte'=>'627800','compte_lib'=>'Frais et commissions bancaires','debit'=>$a],
                    $bb + ['compte'=>'512000','compte_lib'=>'Banque','credit'=>$a],
                ]];
            }
        }
    } catch (Throwable $e) { $partial = true; error_log('[fec] frais Stripe: '.$e->getMessage()); }

    // ── ACHATS fournisseurs (project_invoices validées) ──
    // Compte de charge = poste du plan comptable associatif (comme le bilan
    // analytique) ; un compte auxiliaire par fournisseur.
    try {
        $st = $pdo->prepare(
            "SELECT pi.id, pi.supplier_name, pi.category, pi.account_override, pi.amount_ht, pi.amount_ttc, DATE(pi.invoice_date) idate
             FROM project_invoices pi JOIN projects p ON p.id = pi.project_id
             JOIN folders f ON f.id = p.folder_id
             WHERE f.org_id = :o AND pi.status = 'validated' AND DATE(pi.invoice_date) BETWEEN :s AND :e
             ORDER BY pi.invoice_date ASC, pi.id ASC");
        $st->execute([':o'=>$org_id, ':s'=>$start, ':e'=>$end]);
        // Un seul libellé par compte auxiliaire : le premier nom lu (ordre
        // de la requête) pour toutes les écritures du même fournisseur.
        $aux_lib = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $ttc = (float)$b['amount_ttc'];
            if (abs($ttc) < 0.005) continue;
            $ht = (float)($b['amount_ht'] ?: $b['amount_ttc']);
            $avoir = $ttc < 0; $a_ttc = abs($ttc); $a_ht = abs($ht);
            if ($a_ht > $a_ttc) $a_ht = $a_ttc;              // I3 : HT ne peut excéder TTC
            if (!$vat_subject) $a_ht = $a_ttc;               // non assujettie : TVA non récupérable, charge au TTC
            $a_tva = round($a_ttc - $a_ht, 2);
            $nb_buy++;
            [$compte, $compte_lib] = fec_charge_account($b['category'] ?? null, $b['account_override'] ?? null);
            $lib = ($avoir ? 'Avoir achat' : 'Achat').($b['supplier_name'] ? ' - '.$b['supplier_name'] : '');
            $base = ['jcode'=>'AC','jlib'=>'Achats','edate'=>$b['idate'],'pdate'=>$b['idate'],'piece'=>'FRN'.(int)$b['id'],'lib'=>$lib];
            $lines = [];
            $lines[] = $base + ['compte'=>$compte,'compte_lib'=>$compte_lib, ($avoir?'credit':'debit')=>$a_ht];
            if ($a_tva > 0.001) $lines[] = $base + ['compte'=>'445660','compte_lib'=>'TVA déductible', ($avoir?'credit':'debit')=>$a_tva];
            $aux = fec_supplier_aux($b['supplier_name'] ?? '');
            $aux_lib[$aux] ??= (trim((string)($b['supplier_name'] ?? '')) ?: 'Fournisseur divers');
            $lines[] = $base + ['compte'=>'401000','compte_lib'=>'Fournisseurs','aux'=>$aux,'aux_lib'=>$aux_lib[$aux], ($avoir?'debit':'credit')=>$a_ttc];
            $entries[] = ['j'=>'AC', 'date'=>(string)$b['idate'], 'lines'=>$lines];
        }
    } catch (Throwable $e) { $partial = true; error_log('[fec] achats: '.$e->getMessage()); }

    // ── Numérotation chronologique par journal, puis tri du fichier par date ──
    // usort est stable (PHP 8) : à date égale, l'ordre de lecture est gardé.
    usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']));
    $seq = [];
    $rows = []; $tot_debit = 0.0; $tot_credit = 0.0;
    foreach ($entries as $en) {
        $seq[$en['j']] = ($seq[$en['j']] ?? 0) + 1;
        $num = $en['j'] . $seq[$en['j']];
        foreach ($en['lines'] as $o) {
            $rows[] = fec_line($o + ['num'=>$num]);
            $tot_debit  += (float)($o['debit']  ?? 0);
            $tot_credit += (float)($o['credit'] ?? 0);
        }
    }

    return ['rows'=>$rows, 'stats'=>[
        'lines'=>count($rows), 'sales'=>$nb_sales, 'cotis'=>$nb_cotis, 'buys'=>$nb_buy,
        'debit'=>round($tot_debit,2), 'credit'=>round($tot_credit,2),
        'partial'=>$partial,
        'balanced'=>!$partial && abs($tot_debit - $tot_credit) < 0.01,
        'vat_subject'=>$vat_subject,
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
