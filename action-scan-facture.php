<?php
/**
 * ============================================================
 * ASSOKIT — Scan automatique de facture par IA (vision)
 * ============================================================
 * Reçoit un fichier (PDF/image), l'envoie à Claude qui extrait
 * les infos clés : fournisseur, montant HT, TVA, TTC, date, catégorie.
 * Renvoie le résultat en JSON pour pré-remplir le formulaire.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai-helper.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Session expirée']);
    exit;
}

if (!is_ai_enabled()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'L\'IA n\'est pas configurée. Ajoutez la clé API Anthropic dans config.php']);
    exit;
}

$project_id = (int)($_POST['project_id'] ?? 0);
if ($project_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID projet invalide']);
    exit;
}

$user = current_user();

// Vérifier appartenance à l'org
$stmt = $pdo->prepare("
    SELECT p.id FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? AND f.org_id = ?
");
$stmt->execute([$project_id, $user['org_id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé']);
    exit;
}

// Vérifier le fichier uploadé
if (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Fichier manquant ou erreur d\'upload']);
    exit;
}

$f = $_FILES['invoice_file'];
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
$allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

if (!in_array($ext, $allowed_exts, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Format non supporté (PDF, JPG, PNG uniquement)']);
    exit;
}
if ($f['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Fichier trop gros (max 10 Mo)']);
    exit;
}

// Lire le contenu et l'encoder en base64 pour l'envoi à Claude
$file_content = file_get_contents($f['tmp_name']);
if ($file_content === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Impossible de lire le fichier']);
    exit;
}
$base64 = base64_encode($file_content);

// Type MIME selon l'extension
$media_type = 'application/pdf';
if ($ext === 'jpg' || $ext === 'jpeg') $media_type = 'image/jpeg';
elseif ($ext === 'png') $media_type = 'image/png';

// On stocke aussi le fichier en temp pour pouvoir le rattacher ensuite à la facture
$temp_dir = __DIR__ . '/uploads/temp';
if (!is_dir($temp_dir)) mkdir($temp_dir, 0755, true);
$temp_filename = 'scan_' . $user['id'] . '_' . time() . '.' . $ext;
$temp_path = $temp_dir . '/' . $temp_filename;
move_uploaded_file($f['tmp_name'], $temp_path);

// Prompt pour Claude
$today = date('d/m/Y');
$system_prompt = <<<PROMPT
Tu es un expert-comptable français. Tu reçois une facture (en PDF ou image) et tu en extrais les informations clés.

**IMPORTANT :** Tu DOIS répondre UNIQUEMENT avec un objet JSON valide, sans aucun texte avant ou après. Pas de markdown, pas de backticks, juste du JSON pur.

Format de réponse attendu :
{
  "success": true,
  "supplier_name": "Nom du fournisseur (émetteur de la facture)",
  "invoice_number": "Numéro de facture si visible, sinon null",
  "invoice_date": "Date de la facture au format YYYY-MM-DD",
  "amount_ht": montant_HT_en_nombre_décimal_point,
  "vat_rate": taux_tva_principal_en_pourcentage,
  "vat_amount": montant_tva_en_nombre,
  "amount_ttc": montant_TTC_en_nombre,
  "category": "Catégorie probable parmi : Matériel vidéo, Matériel audio, Matériel informatique, Fournitures, Alimentation, Transport, Location, Télécom, Livres / Matériel pédagogique, Frais administratifs, Prestations externes, Autre",
  "description": "Description courte du contenu de la facture (max 120 caractères)",
  "confidence": "high|medium|low",
  "warnings": ["éventuels avertissements, ex: montants illisibles, plusieurs taux TVA, etc."]
}

**Règles strictes :**
- Montants en NOMBRES DÉCIMAUX avec POINT comme séparateur (ex: 1240.50 et non "1240,50 €")
- Date au format ISO YYYY-MM-DD
- Si plusieurs taux de TVA sur la facture : utilise celui qui correspond au plus gros montant et ajoute un warning
- Si un champ n'est pas lisible/trouvé : mets null, pas une invention
- Si tu détectes que ce n'est PAS une facture : réponds {"success": false, "error": "Ce document ne ressemble pas à une facture"}
- Date de référence (si besoin) : $today

Ne fournis AUCUN texte avant ou après le JSON.
PROMPT;

// Construire les messages avec le document en base64
$messages = [
    [
        'role' => 'user',
        'content' => [
            [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $media_type,
                    'data' => $base64,
                ],
            ],
            [
                'type' => 'text',
                'text' => 'Extrais les informations de cette facture et réponds uniquement avec le JSON demandé.',
            ],
        ],
    ],
];

// Adapter pour les images (le type "document" ne marche que pour PDF)
if ($media_type !== 'application/pdf') {
    $messages = [
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $media_type,
                        'data' => $base64,
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => 'Extrais les informations de cette facture et réponds uniquement avec le JSON demandé.',
                ],
            ],
        ],
    ];
}

// Appel direct à l'API (on ne peut pas utiliser ask_claude() qui attend des messages simples)
$payload = [
    'model' => ANTHROPIC_MODEL,
    'max_tokens' => 1024,
    'system' => $system_prompt,
    'messages' => $messages,
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . ANTHROPIC_API_KEY,
    'anthropic-version: 2023-06-01',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 90);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    echo json_encode(['success' => false, 'error' => 'Problème de connexion à l\'IA : ' . $curl_err]);
    exit;
}

$data = json_decode($response, true);
if ($http_code !== 200) {
    $msg = $data['error']['message'] ?? 'Code HTTP ' . $http_code;
    echo json_encode(['success' => false, 'error' => 'Erreur API : ' . $msg]);
    exit;
}

// Extraire la réponse texte
$response_text = '';
foreach ($data['content'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'text') {
        $response_text .= $block['text'];
    }
}

// Parser le JSON retourné par Claude
// Claude peut parfois entourer sa réponse de ```json ... ``` ou d'espaces, on nettoie
$response_text = trim($response_text);
$response_text = preg_replace('/^```(?:json)?\s*/', '', $response_text);
$response_text = preg_replace('/\s*```$/', '', $response_text);

$extracted = json_decode($response_text, true);
if (!is_array($extracted)) {
    echo json_encode([
        'success' => false,
        'error' => 'L\'IA n\'a pas renvoyé un JSON valide. Essayez avec une image plus nette.',
        'debug' => DEBUG ? substr($response_text, 0, 200) : null,
    ]);
    exit;
}

// Si l'IA a dit que ce n'était pas une facture
if (isset($extracted['success']) && $extracted['success'] === false) {
    echo json_encode($extracted);
    exit;
}

// Retourner le résultat avec le chemin du fichier temp pour pouvoir l'attacher ensuite
$extracted['temp_file'] = 'uploads/temp/' . $temp_filename;
$extracted['original_name'] = $f['name'];
$extracted['success'] = true;

// Stocker en session pour pouvoir récupérer le fichier temp au moment de l'enregistrement final
$_SESSION['invoice_scan_result'] = [
    'temp_file' => $extracted['temp_file'],
    'original_name' => $f['name'],
    'scanned_at' => time(),
];

echo json_encode($extracted);
exit;
