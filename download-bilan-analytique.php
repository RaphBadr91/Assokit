<?php
/**
 * ASSOKIT — Bilan analytique d'un projet (PDF)
 * Factures regroupées par poste comptable (PCA) + sous-totaux + total général.
 * Usage : download-bilan-analytique.php?project=<id>[&all=1]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/pca-mapping.php';

if (!function_exists('ak_render_bilan_html')) {
    function ak_render_bilan_html(array $ctx, array $dossier, bool $validated_only): string {
        $e    = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $eur  = function($v){ return number_format((float)$v, 2, ',', ' '); };
        $fd   = function($d) use ($e){ if(!$d) return ''; $t=strtotime((string)$d); return $t?date('d/m/Y',$t):$e($d); };

        $periode = '';
        if (!empty($ctx['start_date']) || !empty($ctx['end_date'])) {
            $periode = trim($fd($ctx['start_date']) . ' – ' . $fd($ctx['end_date']), ' –');
        }
        $logo = '';
        if (!empty($ctx['logo_path'])) {
            $lp = __DIR__ . '/' . ltrim((string)$ctx['logo_path'], '/');
            if (is_file($lp)) $logo = '<img src="' . $e($lp) . '" style="height:40px;"> ';
        }

        $h  = '<style>';
        $h .= 'body{font-family:dejavusans,sans-serif;font-size:8.5px;color:#1a1a1a;}';
        $h .= '.org{font-size:13px;font-weight:bold;color:#1D4ED8;}';
        $h .= '.title{font-size:17px;font-weight:bold;margin:6px 0 2px;}';
        $h .= '.sub{font-size:10px;color:#555;}.meta{font-size:9px;color:#666;margin:4px 0 8px;}';
        $h .= 'table{width:100%;border-collapse:collapse;}';
        $h .= 'th{background:#1D4ED8;color:#fff;font-size:8px;padding:4px 3px;text-align:left;}';
        $h .= 'td{padding:3px 3px;border-bottom:0.5px solid #E5E7EB;vertical-align:top;}';
        $h .= '.num{text-align:right;white-space:nowrap;}.ctr{text-align:center;}';
        $h .= 'tr.poste td{background:#DBEAFE;font-weight:bold;color:#1E3A8A;font-size:9px;padding:5px 3px;}';
        $h .= 'tr.totale td{font-weight:bold;border-top:1px solid #1D4ED8;background:#F1F5F9;}';
        $h .= 'tr.grand td{font-weight:bold;font-size:11px;background:#1D4ED8;color:#fff;padding:6px 3px;}';
        $h .= '.reclass{color:#B45309;font-style:italic;}.foot{margin-top:10px;font-size:8px;color:#888;}';
        $h .= '</style>';

        $h .= '<div class="org">' . $logo . $e($ctx['org_name']) . '</div>';
        $h .= '<div class="title">Bilan analytique</div>';
        $h .= '<div class="sub">Projet : <strong>' . $e($ctx['name']) . '</strong>';
        if (!empty($ctx['location'])) $h .= ' — ' . $e($ctx['location']);
        $h .= '</div><div class="meta">';
        if ($periode) $h .= 'Période : ' . $periode . ' &nbsp;·&nbsp; ';
        $h .= 'Pièces : ' . (int)$dossier['count'] . ' &nbsp;·&nbsp; '
            . ($validated_only ? 'Factures validées' : 'Toutes les factures')
            . ' &nbsp;·&nbsp; Édité le ' . date('d/m/Y') . '</div>';

        $h .= '<table><thead><tr>'
            . '<th>Compte</th><th>Libellé du compte</th><th>Journal</th><th>Pièce</th>'
            . '<th>Date</th><th>Contrepar.</th><th>Libellé Entête</th><th>Libellé Mouvement</th>'
            . '<th class="num">Débit</th><th class="ctr">OK</th><th class="num">Solde</th>'
            . '</tr></thead><tbody>';

        if ((int)$dossier['count'] === 0) {
            $h .= '<tr><td colspan="11" style="padding:14px;text-align:center;color:#888;">Aucune facture '
                . ($validated_only ? 'validée ' : '') . 'pour ce projet.</td></tr>';
        } else {
            foreach ($dossier['postes'] as $p) {
                $h .= '<tr class="poste"><td colspan="11">' . $e($p['label']) . '</td></tr>';
                $solde = 0.0;
                foreach ($p['lines'] as $r) {
                    $solde += (float)$r['montant'];
                    $ok  = (($r['status'] ?? '') === 'validated') ? 'x' : '0';
                    $lib = $e($r['compte_lib']);
                    if (!empty($r['unmapped'])) $lib .= ' <span class="reclass">(à reclasser)</span>';
                    $h .= '<tr><td></td><td>' . $lib . '</td><td>AC</td><td>' . (int)$r['piece'] . '</td>'
                        . '<td>' . $fd($r['invoice_date']) . '</td><td>' . $e($r['contrepartie']) . '</td>'
                        . '<td>' . $e($r['supplier_name']) . '</td><td>' . $e($r['description']) . '</td>'
                        . '<td class="num">' . $eur($r['montant']) . '</td><td class="ctr">' . $ok . '</td>'
                        . '<td class="num">' . $eur($solde) . '</td></tr>';
                }
                $h .= '<tr class="totale"><td>TOTALE</td><td colspan="7"></td>'
                    . '<td class="num">' . $eur($p['total']) . '</td><td></td><td></td></tr>';
            }
            $h .= '<tr class="grand"><td colspan="8">TOTAL GÉNÉRAL DES DÉPENSES</td>'
                . '<td class="num">' . $eur($dossier['total']) . '</td><td></td><td></td></tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<div class="foot">Document généré par Assokit — pièces justificatives jointes séparément, '
            . 'numérotées selon la colonne « Pièce ».</div>';
        return $h;
    }
}

if (PHP_SAPI !== 'cli') {
    require_login();
    $project_id = (int)($_GET['project'] ?? 0);
    $validated_only = !isset($_GET['all']);
    if ($project_id <= 0) { http_response_code(400); die('Projet invalide.'); }
    $user = current_user();
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.location, p.start_date, p.end_date, p.budget_planned,
               o.name AS org_name, o.logo_path, f.org_id AS proj_org_id
        FROM projects p
        JOIN folders f ON f.id = p.folder_id
        JOIN organizations o ON o.id = f.org_id
        WHERE p.id = ?
    ");
    $stmt->execute([$project_id]);
    $ctx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ctx) { http_response_code(404); die('Projet introuvable.'); }
    require_once __DIR__ . '/includes-permissions.php';
    if (!user_can_view_org((int)$ctx['proj_org_id'])) { http_response_code(403); die('Accès refusé à ce projet.'); }

    require_once __DIR__ . '/plan-helpers.php';
    if (function_exists('ak_trial_gate')) {
        $__g = ak_trial_gate($pdo, (int)$ctx['proj_org_id'], 'bilan_analytique', 1);
        if (!empty($__g['blocked'])) {
            http_response_code(402);
            header('Content-Type: text/html; charset=utf-8');
            $pn = htmlspecialchars((string)($__g['plan']['name'] ?? 'Essai'), ENT_QUOTES, 'UTF-8');
            echo '<!doctype html><meta charset="utf-8"><title>Export limite</title>'
               . '<div style="max-width:540px;margin:80px auto;font-family:system-ui,Segoe UI,Arial,sans-serif;text-align:center;color:#1e293b;">'
               . '<div style="font-size:42px;">🔒</div>'
               . '<h1 style="font-size:20px;margin:14px 0 8px;">Export réservé à 1 génération en essai</h1>'
               . '<p style="font-size:14px;color:#475569;line-height:1.55;">Votre offre <strong>' . $pn . '</strong> autorise cet export une seule fois en essai. Passez au plan <strong>Pro</strong> pour des exports illimités.</p>'
               . '<a href="/abonnement" style="display:inline-block;margin-top:18px;background:#1D4ED8;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:600;font-size:14px;">Passer au plan Pro</a>'
               . '</div>';
            exit;
        }
    }
    $dossier = ak_invoice_dossier($pdo, $project_id, $validated_only);
    $html = ak_render_bilan_html($ctx, $dossier, $validated_only);

    $mpdf = new \Mpdf\Mpdf([
        'format'=>'A4-L','orientation'=>'L',
        'margin_left'=>10,'margin_right'=>10,'margin_top'=>12,'margin_bottom'=>12,
        'tempDir'=>sys_get_temp_dir(),
    ]);
    $mpdf->SetTitle('Bilan analytique - ' . $ctx['name']);
    $mpdf->WriteHTML($html);
    $slug = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$ctx['name']), '-');
    if ($slug === '') $slug = 'projet';
    $mpdf->Output('bilan-analytique-' . $slug . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
}
