<?php
/**
 * ASSOKIT — Export Excel (.xlsx natif, sans dépendance) du bilan analytique.
 * Usage : download-bilan-analytique-xlsx.php?project=<id>[&all=1]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pca-mapping.php';

if (!function_exists('ak_xlsx_col')) {
    function ak_xlsx_col(int $i): string { $s=''; $i++; while($i>0){ $m=($i-1)%26; $s=chr(65+$m).$s; $i=intdiv($i-1,26);} return $s; }
}
if (!function_exists('ak_xlsx_cell')) {
    function ak_xlsx_cell(int $col, int $row, array $c): string {
        $ref = ak_xlsx_col($col) . $row;
        $s = (int)($c['s'] ?? 0);
        $v = $c['v'] ?? '';
        if (!empty($c['n'])) {
            $num = number_format((float)$v, 2, '.', '');
            return '<c r="'.$ref.'" s="'.$s.'"><v>'.$num.'</v></c>';
        }
        if ($v === '' || $v === null) return '<c r="'.$ref.'" s="'.$s.'"/>';
        $t = htmlspecialchars((string)$v, ENT_QUOTES|ENT_XML1, 'UTF-8');
        return '<c r="'.$ref.'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.$t.'</t></is></c>';
    }
}
if (!function_exists('ak_xlsx_build_bilan')) {
    function ak_xlsx_build_bilan(array $ctx, array $dossier, bool $validated_only): string {
        $fd = function($d){ if(!$d) return ''; $t=strtotime((string)$d); return $t?date('d/m/Y',$t):''; };
        $rows = [];
        $rows[] = [['v'=>$ctx['org_name'],'s'=>1]];
        $rows[] = [['v'=>'Bilan analytique — '.$ctx['name'],'s'=>1]];
        $rows[] = [['v'=>'Pièces : '.(int)$dossier['count'].'  ·  '.($validated_only?'Factures validées':'Toutes les factures').'  ·  Édité le '.date('d/m/Y'),'s'=>0]];
        $rows[] = [];
        foreach (['Compte','Libellé du compte','Journal','Pièce','Date','Contrepar.','Libellé Entête','Libellé Mouvement','Débit','OK','Solde'] as $hh) $hdr[]=['v'=>$hh,'s'=>2];
        $rows[] = $hdr;

        if ((int)$dossier['count']===0) {
            $rows[] = [['v'=>'Aucune facture '.($validated_only?'validée ':'').'pour ce projet.','s'=>0]];
        } else {
            foreach ($dossier['postes'] as $p) {
                $pr=[['v'=>$p['label'],'s'=>3]]; for($i=1;$i<11;$i++) $pr[]=['v'=>'','s'=>3]; $rows[]=$pr;
                $solde=0.0;
                foreach($p['lines'] as $r){
                    $solde += (float)$r['montant'];
                    $ok = (($r['status']??'')==='validated')?'x':'0';
                    $lib = $r['compte_lib'].(!empty($r['unmapped'])?' (à reclasser)':'');
                    $rows[] = [
                        ['v'=>'','s'=>0],['v'=>$lib,'s'=>0],['v'=>'AC','s'=>0],['v'=>(int)$r['piece'],'s'=>0],
                        ['v'=>$fd($r['invoice_date']),'s'=>0],['v'=>$r['contrepartie'],'s'=>0],
                        ['v'=>$r['supplier_name'],'s'=>0],['v'=>$r['description'],'s'=>0],
                        ['v'=>(float)$r['montant'],'s'=>4,'n'=>true],['v'=>$ok,'s'=>0],['v'=>$solde,'s'=>4,'n'=>true],
                    ];
                }
                $tr=[['v'=>'TOTALE','s'=>1]]; for($i=1;$i<8;$i++) $tr[]=['v'=>'','s'=>0];
                $tr[]=['v'=>(float)$p['total'],'s'=>5,'n'=>true]; $tr[]=['v'=>'','s'=>0]; $tr[]=['v'=>'','s'=>0]; $rows[]=$tr;
            }
            $gr=[['v'=>'TOTAL GÉNÉRAL DES DÉPENSES','s'=>6]]; for($i=1;$i<8;$i++) $gr[]=['v'=>'','s'=>6];
            $gr[]=['v'=>(float)$dossier['total'],'s'=>6,'n'=>true]; $gr[]=['v'=>'','s'=>6]; $gr[]=['v'=>'','s'=>6]; $rows[]=$gr;
        }

        $sd=''; $rn=0;
        foreach($rows as $cells){ $rn++; $sd.='<row r="'.$rn.'">'; $ci=0; foreach($cells as $c){ $sd.=ak_xlsx_cell($ci,$rn,$c); $ci++; } $sd.='</row>'; }
        $cols='<cols><col min="1" max="1" width="9"/><col min="2" max="2" width="32"/><col min="3" max="3" width="8"/><col min="4" max="4" width="7"/><col min="5" max="5" width="11"/><col min="6" max="6" width="12"/><col min="7" max="7" width="26"/><col min="8" max="8" width="30"/><col min="9" max="9" width="12"/><col min="10" max="10" width="5"/><col min="11" max="11" width="12"/></cols>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$cols.'<sheetData>'.$sd.'</sheetData></worksheet>';
    }
}
if (!function_exists('ak_xlsx_write')) {
    function ak_xlsx_write(string $sheetXml, string $outPath): void {
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'<xf numFmtId="4" fontId="2" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
        $relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Bilan analytique" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip = new ZipArchive();
        if ($zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer le fichier Excel.');
        }
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $relsRoot);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
    }
}

if (PHP_SAPI !== 'cli') {
    require_login();
    $project_id = (int)($_GET['project'] ?? 0);
    $validated_only = !isset($_GET['all']);
    if ($project_id <= 0) { http_response_code(400); die('Projet invalide.'); }
    $user = current_user();
    $stmt = $pdo->prepare("SELECT p.id,p.name,p.location,o.name AS org_name, f.org_id AS proj_org_id FROM projects p JOIN folders f ON f.id=p.folder_id JOIN organizations o ON o.id=f.org_id WHERE p.id=?");
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
    $sheet = ak_xlsx_build_bilan($ctx, $dossier, $validated_only);
    $tmp = tempnam(sys_get_temp_dir(), 'akxlsx');
    ak_xlsx_write($sheet, $tmp);
    $slug = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$ctx['name']), '-'); if ($slug==='') $slug='projet';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="bilan-analytique-' . $slug . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); unlink($tmp); exit;
}
