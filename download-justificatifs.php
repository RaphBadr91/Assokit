<?php
/**
 * ASSOKIT — PDF des pièces justificatives d'un projet
 * Reprend la numérotation du bilan analytique (colonne « Pièce »).
 * Usage : download-justificatifs.php?project=<id>[&all=1]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/pca-mapping.php';

if (!function_exists('ak_build_justificatifs')) {
    function ak_build_justificatifs(\Mpdf\Mpdf $mpdf, array $ctx, array $dossier, bool $validated_only): void {
        $e   = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $eur = function($v){ return number_format((float)$v, 2, ',', ' '); };
        $fd  = function($d){ if(!$d) return ''; $t=strtotime((string)$d); return $t?date('d/m/Y',$t):''; };

        $g  = '<div style="text-align:center;margin-top:70px;font-family:dejavusans,sans-serif;">';
        $g .= '<div style="font-size:20px;font-weight:bold;color:#1D4ED8;">' . $e($ctx['org_name']) . '</div>';
        $g .= '<div style="font-size:30px;font-weight:bold;margin-top:24px;">Pièces justificatives</div>';
        $g .= '<div style="font-size:14px;color:#555;margin-top:14px;">Projet : <strong>' . $e($ctx['name']) . '</strong></div>';
        $g .= '<div style="font-size:12px;color:#777;margin-top:8px;">' . (int)$dossier['count'] . ' pièces &nbsp;·&nbsp; '
            . ($validated_only ? 'Factures validées' : 'Toutes les factures') . ' &nbsp;·&nbsp; Édité le ' . date('d/m/Y') . '</div>';
        $g .= '<div style="font-size:11px;color:#999;margin-top:34px;">Les numéros de pièce correspondent à la colonne « Pièce » du bilan analytique.</div></div>';
        $mpdf->WriteHTML($g);

        if ((int)$dossier['count'] === 0) {
            $mpdf->AddPage();
            $mpdf->WriteHTML('<p style="text-align:center;color:#888;margin-top:40px;">Aucune facture '
                . ($validated_only ? 'validée ' : '') . 'à justifier pour ce projet.</p>');
            return;
        }

        foreach ($dossier['lines'] as $r) {
            $cart  = '<table style="width:100%;border-collapse:collapse;font-family:dejavusans,sans-serif;margin-bottom:6px;"><tr>';
            $cart .= '<td style="background:#1D4ED8;color:#fff;font-size:18px;font-weight:bold;padding:8px 6px;width:90px;text-align:center;">Pièce<br>N° ' . (int)$r['piece'] . '</td>';
            $cart .= '<td style="background:#EFF6FF;padding:8px 12px;font-size:10.5px;color:#1e293b;">'
                   . '<strong>' . $e($r['supplier_name']) . '</strong><br>'
                   . $fd($r['invoice_date']) . ' &nbsp;·&nbsp; ' . $e($r['compte']) . ' ' . $e($r['compte_lib'])
                   . (!empty($r['unmapped']) ? ' <span style="color:#B45309;">(à reclasser)</span>' : '')
                   . '<br><span style="font-size:14px;font-weight:bold;color:#1D4ED8;">' . $eur($r['montant']) . ' €</span> TTC</td>';
            $cart .= '</tr></table>';

            $rel  = (string)$r['photo'];
            $path = $rel !== '' ? (__DIR__ . '/' . ltrim($rel, '/')) : '';
            $ext  = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

            if (!$path || !is_file($path)) {
                $mpdf->AddPage();
                $mpdf->WriteHTML($cart . '<p style="color:#94A3B8;font-family:dejavusans,sans-serif;font-size:11px;margin-top:10px;">' . (($rel === '') ? 'Aucune photo jointe à cette facture.' : 'Photo jointe introuvable sur le serveur.') . '</p>');
                continue;
            }

            if ($ext === 'pdf') {
                $mpdf->AddPage();
                $mpdf->WriteHTML($cart . '<p style="color:#888;font-family:dejavusans,sans-serif;font-size:10px;">Facture au format PDF (page(s) ci-après).</p>');
                if (method_exists($mpdf, 'setSourceFile')) {
                    try {
                        $n = $mpdf->setSourceFile($path);
                        for ($i = 1; $i <= $n; $i++) {
                            $tpl = $mpdf->importPage($i);
                            $mpdf->AddPage();
                            $mpdf->useTemplate($tpl, 0, 0, 210);
                        }
                    } catch (\Throwable $ex) {
                        $mpdf->WriteHTML('<p style="color:#B45309;font-size:10px;">PDF non affichable.</p>');
                    }
                }
            } else {
                $mpdf->AddPage();
                $mpdf->WriteHTML($cart . '<div style="text-align:center;margin-top:6px;"><img src="' . $e($path) . '" style="max-width:100%;max-height:540px;"></div>');
            }
        }
    }
}

if (PHP_SAPI !== 'cli') {
    require_login();
    $project_id = (int)($_GET['project'] ?? 0);
    $validated_only = !isset($_GET['all']);
    if ($project_id <= 0) { http_response_code(400); die('Projet invalide.'); }
    $user = current_user();
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.location, o.name AS org_name, f.org_id AS proj_org_id
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
        $__g = ak_trial_gate($pdo, (int)$ctx['proj_org_id'], 'justificatifs', 1);
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
    $mpdf = new \Mpdf\Mpdf([
        'format'=>'A4','orientation'=>'P',
        'margin_left'=>10,'margin_right'=>10,'margin_top'=>10,'margin_bottom'=>10,
        'tempDir'=>sys_get_temp_dir(),
    ]);
    $mpdf->SetTitle('Justificatifs - ' . $ctx['name']);
    ak_build_justificatifs($mpdf, $ctx, $dossier, $validated_only);
    $slug = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$ctx['name']), '-');
    if ($slug === '') $slug = 'projet';
    $mpdf->Output('justificatifs-' . $slug . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
}
