<?php
/**
 * ============================================================
 * ASSOKIT — PDF différencié par catégorie de document
 * URL : /communication-pdf?id={campaign_id}
 * Templates : letter, warm, admin, social, report, default
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limit-helper.php';
ak_rate_limit_or_die('pdf_communication', 15, 60, (string)($_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'anon')));
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/report-charts-helper.php';
require_once __DIR__ . '/vendor/autoload.php';
require_login();

$current = current_user();
$org_id = (int)($current['org_id'] ?? 0);
$campaign_id = (int)($_GET['id'] ?? 0);

if ($campaign_id <= 0 || $org_id <= 0) {
    http_response_code(400);
    die('ID invalide');
}

$stmt = $pdo->prepare("
    SELECT c.*, u.first_name AS author_first, u.last_name AS author_last
    FROM communication_campaigns c
    LEFT JOIN users u ON c.created_by = u.id
    WHERE c.id = ? AND c.org_id = ?
    LIMIT 1
");
$stmt->execute([$campaign_id, $org_id]);
$camp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$camp) { http_response_code(404); die('Document introuvable.'); }

$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
$stmt->execute([$org_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// HELPERS
// ============================================================

$logo_assokit_path = __DIR__ . '/assets/logo-assokit.png';
$logo_assokit_b64 = '';
if (file_exists($logo_assokit_path)) {
    $logo_assokit_b64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_assokit_path));
}

$logo_org_path = !empty($org['logo_path']) ? __DIR__ . '/' . ltrim($org['logo_path'], '/') : null;
$logo_org_b64 = '';
if ($logo_org_path && file_exists($logo_org_path)) {
    $ext = strtolower(pathinfo($logo_org_path, PATHINFO_EXTENSION));
    $mime = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png');
    $logo_org_b64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logo_org_path));
}

function ak_date_fr_long($datetime) {
    $mois = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $ts = strtotime($datetime);
    return date('j', $ts) . ' ' . $mois[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function comm_format_content($text) {
    $text = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.+)$/m', '<h1 class="ch1">$1</h1>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<![\*\w])\*([^\*\n]+?)\*(?!\*)/', '<em>$1</em>', $text);
    $text = preg_replace_callback('/(?:^[-*\x{2022}] .+(?:\n|$))+/mu', function($m) {
        $items = array_filter(array_map(function($l) {
            return preg_replace('/^[-*\x{2022}] /u', '', trim($l));
        }, explode("\n", trim($m[0]))));
        return '<ul>' . implode('', array_map(function($i){return '<li>'.$i.'</li>';}, $items)) . '</ul>';
    }, $text);
    $paragraphs = preg_split('/\n{2,}/', $text);
    $out = '';
    foreach ($paragraphs as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^<(h[1-3]|ul|ol|table|div|blockquote)/', $p)) {
            $out .= $p . "\n";
        } else {
            $p = str_replace("\n", '<br>', $p);
            $out .= '<p>' . $p . '</p>' . "\n";
        }
    }
    return $out;
}

/**
 * Détecte (catégorie, type) depuis le titre stocké en BDD.
 * Le titre est de la forme "Titre du type — DD/MM/YYYY HH:MM"
 */
function comm_detect_category_and_type(string $title): array {
    $prefix = trim(explode(' — ', $title, 2)[0]);
    // Retire emojis/symboles en tête (ex: '✨ Rapport...' → 'Rapport...')
    $prefix = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $prefix));
    $map = [
        'Convocation AG ordinaire'        => ['ag', 'convocation_ag_ordinaire'],
        'Convocation AG extraordinaire'   => ['ag', 'convocation_ag_extra'],
        "Procès-verbal d'AG"              => ['ag', 'pv_ag'],
        'Rapport moral du président'      => ['ag', 'rapport_moral'],
        'Convocation CA / Bureau'         => ['ag', 'convocation_ca'],
        'Appel à dons'                    => ['dons', 'appel_dons'],
        'Remerciement donateur'           => ['dons', 'remerciement_donateur'],
        'Appel à bénévoles'               => ['adherents', 'appel_benevoles'],
        'Relance cotisation'              => ['adherents', 'relance_cotisation'],
        'Accueil nouveau membre'          => ['adherents', 'accueil_nouveau_membre'],
        'Attestation de bénévolat'        => ['adherents', 'attestation_benevolat'],
        'Newsletter mensuelle'            => ['adherents', 'newsletter_mensuelle'],
        'Post Facebook'                   => ['reseaux_sociaux', 'post_facebook'],
        'Post Instagram'                  => ['reseaux_sociaux', 'post_instagram'],
        'Post LinkedIn'                   => ['reseaux_sociaux', 'post_linkedin'],
        'Story Instagram'                 => ['reseaux_sociaux', 'story_instagram'],
        'Série multi-plateformes'         => ['reseaux_sociaux', 'serie_multi_reseaux'],
        "Rapport d'activité annuel"       => ['rapport', 'rapport_activite'],
        'Rapport financier simplifié'     => ['rapport', 'rapport_financier'],
        'Bilan de projet'                 => ['rapport', 'bilan_projet'],
        'Courrier Mairie'                 => ['courriers', 'courrier_mairie'],
        'Communiqué de presse'            => ['courriers', 'communique_presse'],
        'Invitation presse'               => ['courriers', 'invitation_presse'],
        'Demande de partenariat entreprise' => ['courriers', 'partenariat_entreprise'],
    ];
    return $map[$prefix] ?? ['default', 'unknown'];
}

function ak_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ============================================================
// PRÉPARATION DES DONNÉES
// ============================================================

[$category, $type_key] = comm_detect_category_and_type($camp['title']);

// Pour le mode "admin" : attestation a son propre style
$template = match(true) {
    $type_key === 'attestation_benevolat' => 'admin',
    $category === 'ag' || $category === 'courriers' => 'letter',
    $category === 'dons' => 'warm',
    $category === 'adherents' && in_array($type_key, ['accueil_nouveau_membre', 'newsletter_mensuelle']) => 'warm',
    $category === 'adherents' => 'letter',
    $category === 'reseaux_sociaux' => 'social',
    $category === 'rapport' => 'report',
    default => 'default',
};

// Nettoyer le titre (retirer la date)
$clean_title = trim(explode(' — ', $camp['title'], 2)[0]);
$content_html = comm_format_content($camp['content']);
$generated_at_fr = date('d/m/Y à H:i', strtotime($camp['created_at']));
$generated_date_long = ak_date_fr_long($camp['created_at']);
$author_name = trim(($camp['author_first'] ?? '') . ' ' . ($camp['author_last'] ?? ''));
if ($author_name === '') $author_name = '—';

// Coordonnées asso pour mode lettre
$asso_name = !empty($org['legal_name']) ? $org['legal_name'] : ($org['name'] ?? 'Mon Association');
$asso_form = $org['legal_form'] ?? '';
$asso_address_lines = [];
if (!empty($org['billing_address_street'])) $asso_address_lines[] = $org['billing_address_street'];
if (!empty($org['billing_address_complement'])) $asso_address_lines[] = $org['billing_address_complement'];
$ville = trim(($org['billing_address_zip'] ?? '') . ' ' . ($org['billing_address_city'] ?? ''));
if ($ville) $asso_address_lines[] = $ville;
$asso_email = $org['billing_email'] ?? $org['email'] ?? '';
$asso_phone = $org['billing_phone'] ?? $org['phone'] ?? '';
$asso_siret = $org['siret'] ?? '';
$asso_rna = $org['rna_number'] ?? '';

$ville_emission = $org['billing_address_city'] ?? 'Paris';

// ============================================================
// CONSTRUCTION DU PDF
// ============================================================

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'orientation' => 'P',
        'margin_left' => 22,
        'margin_right' => 22,
        'margin_top' => $template === 'letter' ? 44 : ($template === 'admin' ? 14 : 40),
        'margin_bottom' => 24,
        'margin_header' => 10,
        'margin_footer' => 10,
        'default_font' => 'dejavusans',
        'default_font_size' => 11,
    ]);
    $mpdf->SetTitle($asso_name . ' — ' . $clean_title);
    $mpdf->SetAuthor($asso_name);
    $mpdf->SetCreator('Assokit');

    // CSS commun
    $css_base = '
    body { font-family: dejavusans, sans-serif; font-size: 11pt; color: #1a1a1a; line-height: 1.7; }
    p { margin: 0 0 10pt; text-align: justify; }
    strong { color: #0A0A0B; font-weight: 700; }
    em { color: #3F3F46; font-style: italic; }
    ul, ol { margin: 6pt 0 12pt 0; padding-left: 22pt; }
    li { margin-bottom: 4pt; }
    h1.ch1 { font-size: 17pt; margin: 20pt 0 12pt; font-weight: 700; }
    h2 { font-size: 13pt; margin: 18pt 0 8pt; font-weight: 700; page-break-after: avoid; }
    h3 { font-size: 11.5pt; margin: 14pt 0 6pt; font-weight: 600; page-break-after: avoid; }
    ';

    // Footer discret commun (nom de l'association)
    $footer_discreet = '<table style="width:100%;font-size:7.5pt;color:#6b7280;border-top:0.3pt solid #E5E7EB;padding-top:4pt;">
      <tr>
        <td>' . ak_h($asso_name) . '</td>
        <td style="text-align:right;">Page {PAGENO} / {nbpg}</td>
      </tr>
    </table>';

    // ===================================================================
    // === TEMPLATE : LETTRE ASSO v3 (courriers + AG + adhérents formels) ===
    // ===================================================================
    if ($template === 'letter') {
        // Couleur d'accent selon catégorie
        $accent = match($category) {
            'ag'        => '#1E3A8A',   // Bleu marine institutionnel
            'courriers' => '#18181B',   // Anthracite (officiel)
            'adherents' => '#065F46',   // Vert sobre
            default     => '#18181B',
        };
        $accent_light = match($category) {
            'ag'        => '#EFF6FF',
            'courriers' => '#F4F4F5',
            'adherents' => '#ECFDF5',
            default     => '#F4F4F5',
        };
        $fonction_signataire = match($category) {
            'ag'        => 'Le/La Président·e',
            'courriers' => 'Pour ' . $asso_name,
            'adherents' => 'Pour ' . $asso_name,
            default     => 'Pour ' . $asso_name,
        };
        $ref_num = 'AK-' . date('Y', strtotime($camp['created_at'])) . '-' . str_pad((string)$campaign_id, 5, '0', STR_PAD_LEFT);
        $address_html = implode('<br>', array_map('ak_h', $asso_address_lines));
        $mentions_footer = [];
        if ($asso_form) $mentions_footer[] = ak_h($asso_form);
        if ($asso_siret) $mentions_footer[] = 'SIRET ' . ak_h($asso_siret);
        if ($asso_rna) $mentions_footer[] = 'RNA ' . ak_h($asso_rna);
        if ($asso_email) $mentions_footer[] = ak_h($asso_email);
        if ($asso_phone) $mentions_footer[] = ak_h($asso_phone);
        $mentions_footer_str = implode(' &nbsp;·&nbsp; ', $mentions_footer);

        // Header répété sur chaque page : bandeau d'identité asso
        $header_letter = '
        <table style="width:100%;padding-bottom:8pt;border-bottom:1.5pt solid ' . $accent . ';">
          <tr>
            <td style="vertical-align:middle;width:60%;">
              ' . ($logo_org_b64 ? '<img src="'.$logo_org_b64.'" style="height:32px;">' : '<div style="font-size:13pt;font-weight:700;color:' . $accent . ';letter-spacing:0.04em;">' . ak_h(strtoupper($asso_name)) . '</div>') . '
            </td>
            <td style="vertical-align:middle;text-align:right;font-size:8pt;color:#71717A;width:40%;line-height:1.5;">
              <strong style="color:' . $accent . ';font-size:8.5pt;letter-spacing:0.05em;">' . ak_h(strtoupper($asso_name)) . '</strong><br>
              ' . $address_html . '
            </td>
          </tr>
        </table>';
        $mpdf->SetHTMLHeader($header_letter);

        // Footer mentions légales asso enrichi
        $footer_letter = '
        <table style="width:100%;font-size:7.5pt;color:#6B7280;border-top:0.5pt solid #E5E7EB;padding-top:5pt;line-height:1.5;">
          <tr>
            <td style="width:75%;">
              ' . $mentions_footer_str . '
            </td>
            <td style="width:25%;text-align:right;color:#71717A;font-weight:600;">
              Page {PAGENO} / {nbpg}
            </td>
          </tr>
        </table>';
        $mpdf->SetHTMLFooter($footer_letter);

        $html = '
        <style>' . $css_base . '
        .l-topspace { height: 8pt; }
        .l-meta { width: 100%; margin-bottom: 18pt; }
        .l-meta td { vertical-align: top; }
        .l-ref {
          display: inline-block;
          background: ' . $accent_light . ';
          border: 0.5pt solid ' . $accent . ';
          padding: 5pt 11pt;
          font-size: 8.5pt;
          color: ' . $accent . ';
          font-weight: 700;
          letter-spacing: 0.08em;
        }
        .l-citydate {
          font-size: 10.5pt;
          color: #18181B;
          font-style: italic;
        }
        .l-citydate strong { font-style: normal; color: ' . $accent . '; }
        .l-object {
          margin: 26pt 0 22pt;
          padding: 12pt 16pt 12pt 18pt;
          background: ' . $accent_light . ';
          border-left: 4pt solid ' . $accent . ';
          font-size: 11pt;
          color: #18181B;
        }
        .l-object .lbl {
          display: block;
          font-size: 8pt;
          color: ' . $accent . ';
          text-transform: uppercase;
          letter-spacing: 0.18em;
          font-weight: 700;
          margin-bottom: 4pt;
        }
        .l-object .val { font-weight: 600; font-size: 11.5pt; }
        .l-content { margin-top: 4pt; }
        .l-content p { text-align: justify; line-height: 1.75; margin: 0 0 11pt; }
        .l-content p:first-child::first-letter {
          font-size: 19pt;
          font-weight: 700;
          color: ' . $accent . ';
        }
        .l-content h2 {
          color: ' . $accent . ';
          font-size: 13pt;
          margin: 22pt 0 8pt;
          padding-bottom: 5pt;
          border-bottom: 1pt solid ' . $accent . ';
          letter-spacing: 0.02em;
        }
        .l-content h3 { color: #3F3F46; font-size: 11.5pt; margin: 16pt 0 6pt; }
        .l-content ul { margin: 8pt 0 14pt; padding-left: 18pt; }
        .l-content li { margin-bottom: 6pt; line-height: 1.65; }
        .l-content li::marker { color: ' . $accent . '; }
        .l-sig {
          margin-top: 36pt;
          page-break-inside: avoid;
        }
        .l-sig-table { width: 100%; }
        .l-sig-right { text-align: right; vertical-align: top; padding-left: 30pt; }
        .l-sig-fn {
          font-size: 9.5pt;
          color: ' . $accent . ';
          text-transform: uppercase;
          letter-spacing: 0.15em;
          font-weight: 700;
          margin-bottom: 4pt;
        }
        .l-sig-rule {
          border-top: 0.8pt solid ' . $accent . ';
          width: 160pt;
          display: inline-block;
          margin-top: 32pt;
          padding-top: 5pt;
        }
        .l-sig-name {
          font-size: 11.5pt;
          font-weight: 700;
          color: #18181B;
          letter-spacing: 0.03em;
        }
        .l-sig-role {
          font-size: 9pt;
          color: #71717A;
          font-style: italic;
          margin-top: 2pt;
        }
        .l-loi {
          margin-top: 28pt;
          padding-top: 12pt;
          border-top: 0.3pt dashed #D4D4D8;
          font-size: 8pt;
          color: #6B7280;
          text-align: center;
          font-style: italic;
          letter-spacing: 0.02em;
        }
        </style>

        <div class="l-topspace"></div>

        <table class="l-meta">
          <tr>
            <td style="width:50%;">
              <span class="l-ref">RÉF. ' . $ref_num . '</span>
            </td>
            <td style="width:50%;text-align:right;">
              <div class="l-citydate">
                <strong>' . ak_h($ville_emission) . '</strong>, le ' . $generated_date_long . '
              </div>
            </td>
          </tr>
        </table>

        <div class="l-object">
          <span class="lbl">Objet</span>
          <span class="val">' . ak_h($clean_title) . '</span>
        </div>

        <div class="l-content">
        ' . $content_html . '
        </div>

        <div class="l-sig">
          <table class="l-sig-table">
            <tr>
              <td style="width:55%;"></td>
              <td class="l-sig-right">
                <div class="l-sig-fn">' . ak_h($fonction_signataire) . '</div>
                <span class="l-sig-rule"></span><br>
                <span class="l-sig-name">' . ak_h($author_name) . '</span>
                <div class="l-sig-role">' . ak_h($asso_name) . '</div>
              </td>
            </tr>
          </table>
        </div>

        <div class="l-loi">
          Association régie par la loi du 1<sup>er</sup> juillet 1901 et le décret du 16 août 1901
        </div>
        ';
    }


    // ===================================================================
    // === TEMPLATE : WARM (dons, accueil, newsletter) ===
    // ===================================================================
    elseif ($template === 'warm') {
        // Header chaleureux avec logo de l'ASSOCIATION
        $header_warm = '
        <table style="width:100%;border-bottom:2pt solid #F59E0B;padding-bottom:8pt;">
          <tr>
            <td style="vertical-align:bottom;width:55%;">
              ' . ($logo_org_b64 ? '<img src="'.$logo_org_b64.'" style="height:28px;">' : '<strong style="font-size:11pt;color:#92400E;">' . ak_h($asso_name) . '</strong>') . '
            </td>
            <td style="vertical-align:bottom;text-align:right;width:45%;font-size:9pt;color:#92400E;">
              <strong>' . ak_h($asso_name) . '</strong>
            </td>
          </tr>
        </table>';
        $mpdf->SetHTMLHeader($header_warm);
        $mpdf->SetHTMLFooter($footer_discreet);

        // Icone du hero = logo de l'association (fallback : initiale, jamais d'emoji)
        $hero_icon = $logo_org_b64
            ? '<img src="' . $logo_org_b64 . '" style="height:56px;">'
            : '<div style="font-size:34pt;font-weight:700;color:#F59E0B;line-height:1;">' . ak_h(mb_strtoupper(mb_substr($asso_name, 0, 1))) . '</div>';

        $html = '
        <style>' . $css_base . '
        .warm-hero { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); padding: 22pt 28pt; border-radius: 12pt; margin-bottom: 24pt; text-align: center; border: 1pt solid #FDE68A; }
        .warm-icon { margin-bottom: 9pt; }
        .warm-label { font-size: 8.5pt; color: #92400E; text-transform: uppercase; letter-spacing: 0.2em; font-weight: 700; margin-bottom: 7pt;}
        .warm-org { font-size: 14pt; color: #78350F; margin-top: 4pt; font-weight: 700; }
        .warm-content { margin-top: 6pt; }
        .warm-content p:first-of-type::first-letter { font-size: 32pt; color: #F59E0B; font-weight: 700; float: left; line-height: 0.9; padding-right: 6pt; padding-top: 3pt; }
        .warm-content h2 { color: #F59E0B; padding-bottom: 4pt; border-bottom: 1.5pt solid #FDE68A; }
        .warm-content h3 { color: #B45309; }
        .warm-sig { margin-top: 30pt; padding-top: 14pt; border-top: 1pt dashed #FCD34D; text-align: right; }
        .warm-sig-name { font-size: 11pt; font-weight: 700; color: #78350F; }
        .warm-sig-title { font-size: 9.5pt; color: #92400E; font-style: italic; }
        </style>

        <div class="warm-hero">
          <div class="warm-icon">' . $hero_icon . '</div>
          <div class="warm-label">' . ak_h($clean_title) . '</div>
          <div class="warm-org">' . ak_h($asso_name) . '</div>
        </div>

        <div class="warm-content">
        ' . $content_html . '
        </div>

        <div class="warm-sig">
          <div class="warm-sig-name">' . ak_h($author_name) . '</div>
          <div class="warm-sig-title">Pour ' . ak_h($asso_name) . '</div>
        </div>
        ';
    }

    // ===================================================================
    // === TEMPLATE : ADMIN (attestation de bénévolat) ===
    // ===================================================================
    elseif ($template === 'admin') {
        $mpdf->SetHTMLFooter($footer_discreet);
        $ref_num = 'ATT-' . date('Y', strtotime($camp['created_at'])) . '-' . str_pad((string)$campaign_id, 4, '0', STR_PAD_LEFT);
        $address_html = implode(', ', array_map('ak_h', $asso_address_lines));
        $logo_html = $logo_org_b64 ? '<img src="' . $logo_org_b64 . '" style="height:30px;margin-bottom:5pt;">' : '';
        $html = '
        <style>' . $css_base . '
        .admin-frame { border: 1.5pt solid #1F2937; padding: 16pt 24pt; margin: 0; }
        .admin-header { text-align: center; padding-bottom: 8pt; border-bottom: 1pt solid #1F2937; margin-bottom: 12pt; }
        .admin-org { font-size: 13pt; font-weight: 700; letter-spacing: 0.06em; color: #0A0A0B; margin-bottom: 2pt; }
        .admin-org-sub { font-size: 8.5pt; color: #52525B; line-height: 1.4; }
        .admin-title { text-align: center; font-size: 15pt; font-weight: 700; letter-spacing: 0.15em; color: #0A0A0B; margin: 9pt 0 3pt; text-transform: uppercase; }
        .admin-ref { text-align: center; font-size: 8.5pt; color: #52525B; margin-bottom: 12pt; }
        .admin-content { margin: 0 6pt; }
        .admin-content p { text-align: justify; line-height: 1.4; margin: 0 0 5pt; }
        .admin-content p:first-of-type::first-letter { font-size: inherit; color: inherit; font-weight: inherit; float: none; padding: 0; }
        .admin-content h2 { font-size: 11.5pt; margin: 8pt 0 4pt; border: none; }
        .admin-content h3 { font-size: 10.5pt; margin: 7pt 0 3pt; }
        .admin-content ul { margin: 3pt 0 6pt 0; padding-left: 18pt; }
        .admin-content li { margin-bottom: 1.5pt; }
        .admin-cachet { width: 95pt; height: 95pt; border: 1pt dashed #9ca3af; border-radius: 50%; text-align: center; font-size: 8pt; color: #6b7280; padding-top: 40pt; box-sizing: border-box; }
        </style>
        <div class="admin-frame">
          <div class="admin-header">
            ' . $logo_html . '
            <div class="admin-org">' . ak_h(strtoupper($asso_name)) . '</div>
            ' . ($asso_form ? '<div class="admin-org-sub">' . ak_h($asso_form) . '</div>' : '') . '
            <div class="admin-org-sub">' . $address_html . '</div>
            ' . ($asso_siret ? '<div class="admin-org-sub">SIRET : ' . ak_h($asso_siret) . ($asso_rna ? ' &middot; RNA : ' . ak_h($asso_rna) : '') .'</div>' : '') . '
          </div>
          <div class="admin-title">Attestation</div>
          <div class="admin-ref">N&deg; ' . $ref_num . '</div>
          <div class="admin-content">
          ' . $content_html . '
          </div>
          <table style="width:100%;margin-top:16pt;">
            <tr>
              <td style="width:60%;vertical-align:top;font-size:9.5pt;line-height:1.4;">
                Fait &agrave; ' . ak_h($ville_emission) . ',<br>le ' . $generated_date_long . '<br><br>
                <strong>' . ak_h($author_name) . '</strong><br>
                <em style="color:#52525B;font-size:8.5pt;">Pour ' . ak_h($asso_name) . '</em>
              </td>
              <td style="width:40%;text-align:right;vertical-align:top;">
                <div class="admin-cachet">Cachet<br>&amp; signature</div>
              </td>
            </tr>
          </table>
        </div>
        ';
    }
    // ===================================================================
    // === TEMPLATE : SOCIAL (réseaux sociaux) ===
    // ===================================================================
    elseif ($template === 'social') {
        $logo_s = $logo_org_b64 ? '<img src="'.$logo_org_b64.'" style="height:24px;">' : '<strong style="font-size:11pt;color:#4338CA;">'.ak_h($asso_name).'</strong>';
        $header_social = '<table style="width:100%;border-bottom:2pt solid #6366F1;padding-bottom:6pt;">
          <tr><td style="vertical-align:bottom;">' . $logo_s . '</td>
          <td style="vertical-align:bottom;text-align:right;font-size:9pt;color:#4338CA;"><strong>' . ak_h($asso_name) . '</strong></td></tr>
        </table>';
        $mpdf->SetHTMLHeader($header_social);
        $mpdf->SetHTMLFooter($footer_discreet);

        $platform_color = match($type_key) {
            'post_facebook' => '#1877F2',
            'post_instagram', 'story_instagram' => '#E4405F',
            'post_linkedin' => '#0A66C2',
            default => '#6366F1'
        };
        $platform_label = match($type_key) {
            'post_facebook' => 'Facebook',
            'post_instagram' => 'Instagram',
            'post_linkedin' => 'LinkedIn',
            'story_instagram' => 'Story Instagram',
            'serie_multi_reseaux' => 'Serie multi-plateformes',
            default => 'Reseaux sociaux'
        };
        // Guide de publication selon la plateforme
        $guide = match($type_key) {
            'post_facebook' => ['Du mardi au jeudi, 13h-15h', 'Image paysage 1200 x 630 px', '1 a 2 hashtags cibles'],
            'post_instagram' => ['Du lundi au vendredi, 11h-13h ou 19h-21h', 'Image carree 1080 x 1080 px', '5 a 10 hashtags pertinents'],
            'story_instagram' => ['Tous les jours, 12h ou 18h-20h', 'Format vertical 1080 x 1920 px', '2 a 3 hashtags + stickers'],
            'post_linkedin' => ['Mardi a jeudi, 8h-10h', 'Image 1200 x 627 px', '3 a 5 hashtags professionnels'],
            'serie_multi_reseaux' => ['Adapter a chaque reseau', 'Decliner le visuel par format', 'Adapter les hashtags par reseau'],
            default => ['Heures de forte audience', 'Visuel adapte au reseau', '3 a 5 hashtags cibles'],
        };
        // Avatar initiale de l'asso pour l'apercu
        $avatar = $logo_org_b64
            ? '<img src="'.$logo_org_b64.'" style="height:30px;width:30px;">'
            : '<div style="width:30px;height:30px;border-radius:50%;background:'.$platform_color.';color:#fff;text-align:center;font-size:13pt;font-weight:700;line-height:30px;">'.ak_h(mb_strtoupper(mb_substr($asso_name,0,1))).'</div>';

        $html = '
        <style>' . $css_base . '
        .so-badge { display: inline-block; padding: 4pt 14pt; border-radius: 999pt; font-size: 9pt; font-weight: 700; color: #fff; background: ' . $platform_color . '; }
        .so-h { font-size: 15pt; font-weight: 700; color: #0A0A0B; margin: 12pt 0 14pt; }
        .so-preview { border: 1pt solid #E5E7EB; border-radius: 10pt; background: #fff; margin-bottom: 20pt; }
        .so-preview-head { padding: 11pt 14pt; border-bottom: 0.5pt solid #F1F5F9; }
        .so-preview-name { font-size: 10pt; font-weight: 700; color: #0A0A0B; }
        .so-preview-sub { font-size: 8pt; color: #64748B; }
        .so-preview-body { padding: 14pt 16pt; font-size: 10.5pt; line-height: 1.6; color: #1a1a1a; }
        .so-preview-body p { margin: 0 0 7pt; text-align: left; }
        .so-preview-body h2, .so-preview-body h3 { color: ' . $platform_color . '; font-size: 11pt; margin: 8pt 0 4pt; border: none; }
        .so-section-t { font-size: 9pt; font-weight: 700; color: #4338CA; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 9pt; }
        .so-guide td { padding: 8pt 10pt; font-size: 9pt; vertical-align: top; border: 0.5pt solid #E5E7EB; }
        .so-guide-label { font-size: 7.5pt; color: #71717A; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3pt; }
        .so-guide-val { font-size: 9.5pt; color: #0A0A0B; font-weight: 600; }
        .so-check { background: #EEF2FF; border-left: 3pt solid #6366F1; border-radius: 0 8pt 8pt 0; padding: 12pt 16pt; margin-top: 16pt; }
        .so-check li { font-size: 9.5pt; color: #3730A3; margin-bottom: 4pt; }
        </style>

        <div><span class="so-badge">' . ak_h($platform_label) . '</span></div>
        <div class="so-h">' . ak_h($clean_title) . '</div>

        <div class="so-section-t">Apercu du post</div>
        <div class="so-preview">
          <table style="width:100%;"><tr>
            <td class="so-preview-head" style="width:42pt;">' . $avatar . '</td>
            <td class="so-preview-head"><div class="so-preview-name">' . ak_h($asso_name) . '</div><div class="so-preview-sub">' . ak_h($platform_label) . ' &middot; a l\'instant</div></td>
          </tr></table>
          <div class="so-preview-body">' . $content_html . '</div>
        </div>

        <div class="so-section-t">Guide de publication</div>
        <table class="so-guide" style="width:100%;border-collapse:collapse;margin-bottom:4pt;">
          <tr>
            <td style="width:40%;"><div class="so-guide-label">Meilleur moment</div><div class="so-guide-val">' . ak_h($guide[0]) . '</div></td>
            <td style="width:32%;"><div class="so-guide-label">Format visuel</div><div class="so-guide-val">' . ak_h($guide[1]) . '</div></td>
            <td style="width:28%;"><div class="so-guide-label">Hashtags</div><div class="so-guide-val">' . ak_h($guide[2]) . '</div></td>
          </tr>
        </table>

        <div class="so-check">
          <div class="so-section-t" style="color:#4338CA;margin-bottom:7pt;">Avant de publier</div>
          <ul style="margin:0;padding-left:16pt;">
            <li>Relire et personnaliser le texte selon votre actualite</li>
            <li>Joindre un visuel de qualite au bon format</li>
            <li>Verifier les hashtags, mentions et le lien eventuel</li>
            <li>Publier au moment de forte audience indique ci-dessus</li>
            <li>Repondre rapidement aux premiers commentaires</li>
          </ul>
        </div>
        ';
    }
    // ===================================================================
    // === TEMPLATE : REPORT (rapports avec layout éditorial) ===
    // ===================================================================
    elseif ($template === 'report') {
        $header_report = '<table style="width:100%;">
          <tr><td style="vertical-align:bottom;width:55%;">' . ($logo_org_b64 ? '<img src="'.$logo_org_b64.'" style="height:26px;">' : ($logo_assokit_b64 ? '<img src="'.$logo_assokit_b64.'" style="height:22px;">' : '')) . '</td>
          <td style="vertical-align:bottom;text-align:right;width:45%;font-size:8.5pt;color:#52525B;"><strong style="color:#0A0A0B;">' . ak_h(strtoupper($asso_name)) . '</strong></td></tr>
          <tr><td colspan="2" style="padding-top:5pt;border-bottom:1.5pt solid #059669;"></td></tr>
        </table>';
        $mpdf->SetHTMLHeader($header_report);
        $mpdf->SetHTMLFooter('<table style="width:100%;font-size:8pt;color:#6b7280;border-top:0.5pt solid #E5E7EB;padding-top:5pt;">
          <tr><td style="width:60%;">' . ak_h($clean_title) . ' · ' . ak_h($asso_name) . '</td>
          <td style="width:40%;text-align:right;">Page <strong style="color:#52525B;">{PAGENO}</strong> / {nbpg}</td></tr>
        </table>');

        $mpdf->SetWatermarkText('CONFIDENTIEL');
        $mpdf->showWatermarkText = false; // désactivé par défaut, activable si besoin
        $mpdf->watermarkTextAlpha = 0.03;

        $dashboard_html = ak_report_dashboard($pdo, $org_id);

        $html = '
        <style>' . $css_base . '
        .rep-cover { text-align: center; padding: 50pt 0 30pt; border-bottom: 3pt solid #059669; margin-bottom: 30pt; }
        .rep-cover-label { font-size: 9pt; color: #059669; text-transform: uppercase; letter-spacing: 0.3em; font-weight: 700; margin-bottom: 18pt; }
        .rep-cover-title { font-size: 28pt; font-weight: 700; color: #0A0A0B; line-height: 1.15; margin: 0 0 18pt; letter-spacing: -0.02em; }
        .rep-cover-org { font-size: 13pt; color: #3F3F46; font-weight: 600; margin-top: 14pt; }
        .rep-cover-date { font-size: 10pt; color: #71717A; margin-top: 8pt; letter-spacing: 0.05em; }
        .rep-intro { background: #F0FDF4; border-left: 4pt solid #10B981; padding: 14pt 18pt; border-radius: 0 8pt 8pt 0; margin-bottom: 24pt; font-size: 10.5pt; color: #065F46; }
        .rep-content h1.ch1 { color: #059669; font-size: 19pt; }
        .rep-content h2 { color: #059669; padding-bottom: 6pt; border-bottom: 2pt solid #D1FAE5; font-size: 14pt; }
        .rep-content h3 { color: #047857; font-size: 12pt; }
        .rep-content p:first-of-type::first-letter { font-size: 28pt; color: #059669; font-weight: 700; float: left; line-height: 0.95; padding-right: 5pt; }
        .rep-content table { width: 100%; border-collapse: collapse; margin: 12pt 0; }
        .rep-content table th { background: #ECFDF5; color: #064E3B; padding: 7pt 10pt; text-align: left; font-size: 10pt; border-bottom: 1.5pt solid #059669; }
        .rep-content table td { padding: 6pt 10pt; border-bottom: 0.5pt solid #E5E7EB; font-size: 10pt; }
        </style>

        <div class="rep-cover">
          <div class="rep-cover-label">' . ak_h($clean_title) . '</div>
          <div class="rep-cover-title">' . ak_h($asso_name) . '</div>
          <div class="rep-cover-date">' . $generated_date_long . '</div>
        </div>

        ' . $dashboard_html . '

        <div class="rep-content">
        ' . $content_html . '
        </div>
        ';
    }

    // ===================================================================
    // === TEMPLATE : DEFAULT (fallback Assokit générique) ===
    // ===================================================================
    else {
        $header_def = '<table style="width:100%;border-bottom:2pt solid #059669;padding-bottom:6pt;">
          <tr><td style="vertical-align:bottom;">' . ($logo_assokit_b64 ? '<img src="'.$logo_assokit_b64.'" style="height:24px;">' : '') . '</td>
          <td style="vertical-align:bottom;text-align:right;font-size:9pt;"><strong>' . ak_h($asso_name) . '</strong></td></tr>
        </table>';
        $mpdf->SetHTMLHeader($header_def);
        $mpdf->SetHTMLFooter($footer_discreet);

        $html = '
        <style>' . $css_base . '
        .def-card { background: #F0FDF4; border-left: 4pt solid #10B981; padding: 16pt 20pt; border-radius: 0 8pt 8pt 0; margin-bottom: 24pt; }
        .def-label { font-size: 9pt; color: #059669; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 6pt; }
        .def-title { font-size: 22pt; font-weight: 700; color: #0A0A0B; line-height: 1.2; margin: 0; }
        </style>
        <div class="def-card">
          <div class="def-label">Document Assokit</div>
          <div class="def-title">' . ak_h($clean_title) . '</div>
        </div>
        <div>' . $content_html . '</div>
        ';
    }

    $mpdf->WriteHTML($html);

    $safe_title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clean_title);
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $asso_name) . '_' . $safe_title . '_' . date('Ymd-Hi', strtotime($camp['created_at'])) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);

} catch (Throwable $e) {
    error_log('[communication-pdf] Erreur mPDF : ' . $e->getMessage());
    http_response_code(500);
    die('Erreur génération PDF : ' . htmlspecialchars($e->getMessage()));
}
