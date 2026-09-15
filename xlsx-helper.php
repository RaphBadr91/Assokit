<?php
/**
 * xlsx-helper.php — Lire et écrire des tableurs, sans dépendance.
 * ------------------------------------------------------------------
 * Un .xlsx est une archive ZIP contenant du XML. PHP sait faire les deux
 * (ZipArchive, SimpleXML) : inutile d'ajouter PhpSpreadsheet et ses
 * quelques mégaoctets pour lire quatre colonnes.
 *
 * Écriture : ak_xlsx_octets()  — rend le fichier en mémoire, prêt à servir.
 * Lecture  : ak_tableur_lire() — accepte .xlsx, .csv et .txt, et rend un
 *            tableau de lignes de chaînes.
 *
 * Trois pièges que ce fichier traite, parce qu'ils cassent un import une
 * fois sur deux dans la vraie vie :
 *
 *   1. Les cellules vides ne sont pas écrites dans le XML. Il faut lire la
 *      référence de chaque cellule (« C7 ») pour savoir dans quelle colonne
 *      elle tombe, sinon les valeurs se décalent dès qu'un champ est vide.
 *   2. Excel en français enregistre ses CSV avec des points-virgules, Excel
 *      en anglais avec des virgules, et les exports de bases avec des
 *      tabulations. On détecte au lieu de supposer.
 *   3. Un CSV enregistré depuis un vieil Excel est en Windows-1252, pas en
 *      UTF-8 : sans conversion, tous les accents se transforment en « ? ».
 * ------------------------------------------------------------------
 */

if (!function_exists('ak_xlsx_colonne')) {

/** Indice (0-based) → lettre de colonne : 0 → A, 26 → AA. */
function ak_xlsx_colonne(int $i): string
{
    $s = ''; $i++;
    while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
    return $s;
}

/** Lettre de colonne → indice (0-based) : « A » → 0, « AA » → 26. */
function ak_xlsx_indice(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) return 0;
    $n = 0;
    foreach (str_split($m[1]) as $c) $n = $n * 26 + (ord($c) - 64);
    return $n - 1;
}

/**
 * Construit un .xlsx et rend ses octets.
 *
 * @param array  $lignes   Lignes de cellules. Une cellule est une chaîne, un
 *                         nombre, ou ['v' => …, 'entete' => true] pour le
 *                         style d'en-tête.
 * @param string $feuille  Nom de l'onglet (31 caractères max chez Excel).
 * @param array  $largeurs Largeurs de colonnes, en caractères.
 */
function ak_xlsx_octets(array $lignes, string $feuille = 'Feuille1', array $largeurs = []): string
{
    $feuille = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $feuille), 0, 31);

    $cellule = function ($col, $ligne, $val): string {
        $ref = ak_xlsx_colonne($col) . $ligne;
        $style = 0;
        if (is_array($val)) { $style = !empty($val['entete']) ? 1 : 0; $val = $val['v'] ?? ''; }
        if ($val === '' || $val === null) return '<c r="' . $ref . '" s="' . $style . '"/>';
        // Les nombres écrits en texte (numéros de téléphone, codes postaux)
        // doivent le rester : Excel mangerait le zéro initial.
        if (is_int($val) || is_float($val)) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . $val . '</v></c>';
        }
        $t = htmlspecialchars((string) $val, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $t . '</t></is></c>';
    };

    $sd = ''; $n = 0;
    foreach ($lignes as $cells) {
        $n++;
        $sd .= '<row r="' . $n . '">';
        $i = 0;
        foreach ($cells as $c) { $sd .= $cellule($i, $n, $c); $i++; }
        $sd .= '</row>';
    }

    $cols = '';
    if ($largeurs) {
        $cols = '<cols>';
        foreach (array_values($largeurs) as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
           . $cols . '<sheetData>' . $sd . '</sheetData></worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF059669"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . htmlspecialchars($feuille, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'akxlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Impossible de créer le fichier Excel.');
    }
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    $octets = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $octets;
}

/**
 * Lit la première feuille d'un .xlsx.
 *
 * @return array Lignes de chaînes, colonnes alignées sur leur position réelle.
 */
function ak_xlsx_lire(string $chemin, int $maxLignes = 5000): array
{
    $zip = new ZipArchive();
    if ($zip->open($chemin) !== true) {
        throw new RuntimeException("Ce fichier n'est pas un classeur Excel lisible.");
    }

    // Chaînes partagées : Excel y range le texte et ne met qu'un indice
    // dans la cellule.
    $partagees = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false && $ss !== '') {
        $xml = @simplexml_load_string($ss);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                // Un texte enrichi est découpé en plusieurs <r><t>… : on recolle.
                $txt = '';
                if (isset($si->t)) $txt = (string) $si->t;
                elseif (isset($si->r)) foreach ($si->r as $r) $txt .= (string) $r->t;
                $partagees[] = $txt;
            }
        }
    }

    // La première feuille référencée par le classeur, et non « sheet1.xml »
    // au hasard : un classeur réenregistré peut nommer ses parties autrement.
    $cible = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $xwb = @simplexml_load_string($wb);
        $xrl = @simplexml_load_string($rels);
        if ($xwb !== false && $xrl !== false && isset($xwb->sheets->sheet[0])) {
            $rid = (string) $xwb->sheets->sheet[0]->attributes('r', true)->id;
            foreach ($xrl->Relationship as $r) {
                if ((string) $r['Id'] === $rid) {
                    $t = ltrim((string) $r['Target'], '/');
                    $cible = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                }
            }
        }
    }

    $feuille = $zip->getFromName($cible);
    if ($feuille === false) $feuille = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($feuille === false) throw new RuntimeException('Classeur vide ou illisible.');

    $xml = @simplexml_load_string($feuille);
    if ($xml === false) throw new RuntimeException('Classeur vide ou illisible.');

    $lignes = [];
    foreach ($xml->sheetData->row as $row) {
        if (count($lignes) >= $maxLignes) break;
        $cells = [];
        foreach ($row->c as $c) {
            // La référence dit la colonne. Sans elle, une cellule vide
            // décalerait toutes les suivantes.
            $i = ak_xlsx_indice((string) $c['r']);
            $type = (string) $c['t'];
            if ($type === 's') {
                $idx = (int) $c->v;
                $v = $partagees[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = '';
                if (isset($c->is->t)) $v = (string) $c->is->t;
                elseif (isset($c->is->r)) foreach ($c->is->r as $r) $v .= (string) $r->t;
            } else {
                $v = isset($c->v) ? (string) $c->v : '';
                // Un nombre long revient parfois en notation scientifique :
                // un numéro de téléphone deviendrait « 6.01020304E+9 ».
                if ($v !== '' && is_numeric($v) && stripos($v, 'e') !== false) {
                    $v = rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
                }
            }
            $cells[$i] = trim($v);
        }
        if (!$cells) { $lignes[] = []; continue; }
        // Remplit les trous pour que $ligne[2] soit toujours la 3e colonne.
        $max = max(array_keys($cells));
        $plate = [];
        for ($i = 0; $i <= $max; $i++) $plate[] = $cells[$i] ?? '';
        $lignes[] = $plate;
    }
    return $lignes;
}

/** Lit un CSV ou un TSV, quelle que soit sa ponctuation et son encodage. */
function ak_csv_lire(string $chemin, int $maxLignes = 5000): array
{
    $brut = (string) file_get_contents($chemin);
    if ($brut === '') return [];

    // BOM UTF-8 : sinon la première en-tête s'appelle « ﻿Prénom ».
    if (str_starts_with($brut, "\xEF\xBB\xBF")) $brut = substr($brut, 3);

    if (!mb_check_encoding($brut, 'UTF-8')) {
        // Excel sous Windows enregistre en 1252 ; sans ça, plus d'accents.
        $brut = mb_convert_encoding($brut, 'UTF-8', 'Windows-1252');
    }

    // Détection du séparateur sur la première ligne non vide : le plus
    // fréquent gagne. Deviner juste vaut mieux qu'imposer le point-virgule.
    $premiere = '';
    foreach (preg_split('/\r\n|\r|\n/', $brut) as $l) {
        if (trim($l) !== '') { $premiere = $l; break; }
    }
    $sep = ';';
    $meilleur = -1;
    foreach ([';', ',', "\t", '|'] as $c) {
        $n = substr_count($premiere, $c);
        if ($n > $meilleur) { $meilleur = $n; $sep = $c; }
    }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $brut);
    rewind($fh);
    $lignes = [];
    while (($r = fgetcsv($fh, 0, $sep, '"', "\\")) !== false) {
        if (count($lignes) >= $maxLignes) break;
        $lignes[] = array_map(fn($v) => trim((string) $v), $r);
    }
    fclose($fh);
    return $lignes;
}

/** Lit un tableur, quel que soit son format. */
function ak_tableur_lire(string $chemin, string $nomOriginal, int $maxLignes = 5000): array
{
    // Le contenu prime sur le nom. Un classeur enregistré puis renommé
    // « contacts.csv » reste un ZIP, et le lire en texte ne donnerait que
    // des octets binaires — cas vu en vrai, et l'utilisateur n'y comprend
    // rien puisque son fichier « est » un CSV. Aucun CSV ne peut commencer
    // par la signature d'une archive ZIP.
    if ((string) file_get_contents($chemin, false, null, 0, 4) === "PK\x03\x04") {
        return ak_xlsx_lire($chemin, $maxLignes);
    }

    $ext = strtolower(pathinfo($nomOriginal, PATHINFO_EXTENSION));
    if ($ext === 'xlsx' || $ext === 'xlsm') {
        throw new RuntimeException("Ce fichier porte l'extension .$ext mais n'est pas un classeur Excel. "
            . "S'il vient d'un export, réenregistrez-le en « Classeur Excel (.xlsx) » ou en CSV.");
    }
    return ak_csv_lire($chemin, $maxLignes);
}

/** Envoie un fichier au navigateur et coupe court. */
function ak_tableur_envoyer(string $octets, string $nom): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . strlen($octets));
    header('Cache-Control: no-store');
    echo $octets;
    exit;
}

}
