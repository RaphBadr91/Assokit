<?php
/**
 * ============================================================
 * ASSOKIT — Assistant IA (cerveau technique)
 * ============================================================
 * Gère toutes les communications avec l'API Anthropic Claude.
 *
 * Fonctions principales :
 *   - build_project_context()  : construit le contexte d'un projet
 *                                 (infos + étapes + messages + fichiers)
 *   - ask_claude()             : envoie une requête à l'API
 *   - is_ai_enabled()          : vérifie que la clé API est configurée
 * ============================================================
 */

if (!defined('ANTHROPIC_API_KEY')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Vérifie que l'IA est configurée (clé API présente).
 */
function is_ai_enabled() {
    return defined('ANTHROPIC_API_KEY')
        && ANTHROPIC_API_KEY
        && ANTHROPIC_API_KEY !== 'METS_TA_CLE_ICI'
        && strlen(ANTHROPIC_API_KEY) > 20;
}

/**
 * Construit un résumé textuel du projet pour servir de contexte à l'IA.
 * Ce résumé inclut tout ce dont l'IA a besoin pour être pertinente :
 *   - les infos de base
 *   - la liste des étapes (avec statut)
 *   - les 20 derniers messages du chat d'équipe
 *   - la liste des fichiers (nom + type)
 *
 * @param int $project_id
 * @param PDO $pdo
 * @return string Le contexte formaté
 */
function build_project_context($project_id, $pdo) {
    // Infos projet
    $stmt = $pdo->prepare("
        SELECT p.*, f.name AS folder_name,
               u.first_name AS ref_first, u.last_name AS ref_last,
               o.name AS org_name
        FROM projects p
        JOIN folders f ON p.folder_id = f.id
        JOIN organizations o ON f.org_id = o.id
        LEFT JOIN users u ON p.referent_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$project_id]);
    $p = $stmt->fetch();
    if (!$p) return '';

    $ctx = "# PROJET : " . $p['name'] . "\n\n";
    $ctx .= "**Association :** " . $p['org_name'] . "\n";
    $ctx .= "**Dossier / programme :** " . $p['folder_name'] . "\n";
    if ($p['location']) $ctx .= "**Lieu :** " . $p['location'] . "\n";
    if ($p['ref_first']) $ctx .= "**Référent :** " . $p['ref_first'] . ' ' . $p['ref_last'] . "\n";
    if ($p['description']) $ctx .= "\n**Description :**\n" . $p['description'] . "\n";
    if ($p['objective']) $ctx .= "\n**Objectif :**\n" . $p['objective'] . "\n";

    $ctx .= "\n**Chiffres clés :**\n";
    $ctx .= "- Participants : " . (int)$p['participants_count'];
    if ($p['participants_female'] || $p['participants_male']) {
        $ctx .= " (dont " . (int)$p['participants_female'] . " femmes et " . (int)$p['participants_male'] . " hommes)";
    }
    $ctx .= "\n";
    $ctx .= "- Budget prévu : " . number_format((float)$p['budget_planned'], 2, ',', ' ') . " €\n";
    $ctx .= "- Budget utilisé : " . number_format((float)$p['budget_used'], 2, ',', ' ') . " €\n";
    $ctx .= "- Avancement : " . (int)$p['progress_percent'] . " %\n";
    $ctx .= "- Statut : " . $p['status'] . "\n";
    if ($p['start_date']) $ctx .= "- Démarrage : " . $p['start_date'] . "\n";
    if ($p['end_date']) $ctx .= "- Clôture prévue : " . $p['end_date'] . "\n";

    // Étapes
    $stmt = $pdo->prepare("
        SELECT s.title, s.description, s.is_completed, s.completed_at,
               u.first_name AS by_first, u.last_name AS by_last
        FROM project_steps s
        LEFT JOIN users u ON s.completed_by = u.id
        WHERE s.project_id = ?
        ORDER BY s.position ASC
    ");
    $stmt->execute([$project_id]);
    $steps = $stmt->fetchAll();

    if (!empty($steps)) {
        $ctx .= "\n## Étapes du projet\n";
        foreach ($steps as $i => $s) {
            $check = $s['is_completed'] ? '✅' : '⬜';
            $ctx .= ($i + 1) . ". {$check} **" . $s['title'] . "**";
            if ($s['is_completed'] && $s['by_first']) {
                $ctx .= " _(validée par " . $s['by_first'] . ' ' . $s['by_last']
                     . " le " . date('d/m/Y', strtotime($s['completed_at'])) . ")_";
            }
            $ctx .= "\n";
            if ($s['description']) $ctx .= "   " . $s['description'] . "\n";
        }
    }

    // Messages récents (20 derniers)
    $stmt = $pdo->prepare("
        SELECT m.content, m.created_at, u.first_name, u.last_name
        FROM project_messages m
        JOIN users u ON m.author_id = u.id
        WHERE m.project_id = ? AND m.message_type = 'text'
        ORDER BY m.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$project_id]);
    $messages = array_reverse($stmt->fetchAll());

    if (!empty($messages)) {
        $ctx .= "\n## Échanges de l'équipe (20 derniers messages)\n";
        foreach ($messages as $m) {
            $when = date('d/m H:i', strtotime($m['created_at']));
            $ctx .= "\n**{$m['first_name']} {$m['last_name']}** _[{$when}]_\n" . trim($m['content']) . "\n";
        }
    }

    // Fichiers
    $stmt = $pdo->prepare("
        SELECT filename, mime_type, created_at
        FROM project_files
        WHERE project_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$project_id]);
    $files = $stmt->fetchAll();

    if (!empty($files)) {
        $ctx .= "\n## Fichiers partagés\n";
        foreach ($files as $f) {
            $ctx .= "- " . $f['filename']
                 . " (" . ($f['mime_type'] ?: 'inconnu') . ", ajouté le " . date('d/m/Y', strtotime($f['created_at'])) . ")\n";
        }
    }

    // Factures liées au projet (bilan financier détaillé)
    $stmt = $pdo->prepare("
        SELECT supplier_name, category, description, amount_ht, vat_rate, amount_ttc, invoice_date, status
        FROM project_invoices
        WHERE project_id = ?
        ORDER BY status DESC, invoice_date DESC
    ");
    $stmt->execute([$project_id]);
    $invoices = $stmt->fetchAll();

    if (!empty($invoices)) {
        $total_validated_ttc = 0;
        $total_validated_ht = 0;
        $total_pending = 0;
        $by_cat_ht = [];
        $by_cat_ttc = [];
        foreach ($invoices as $inv) {
            if ($inv['status'] === 'validated') {
                $total_validated_ttc += (float)$inv['amount_ttc'];
                $total_validated_ht += (float)($inv['amount_ht'] ?? $inv['amount_ttc']);
                $cat = $inv['category'] ?: 'Non catégorisé';
                $by_cat_ttc[$cat] = ($by_cat_ttc[$cat] ?? 0) + (float)$inv['amount_ttc'];
                $by_cat_ht[$cat] = ($by_cat_ht[$cat] ?? 0) + (float)($inv['amount_ht'] ?? $inv['amount_ttc']);
            } elseif ($inv['status'] === 'pending') {
                $total_pending += (float)$inv['amount_ttc'];
            }
        }
        $total_vat = $total_validated_ttc - $total_validated_ht;

        $ctx .= "\n## Bilan financier détaillé\n";
        $ctx .= "- **Total factures validées TTC :** " . number_format($total_validated_ttc, 2, ',', ' ') . " €\n";
        $ctx .= "- **Total factures validées HT :** " . number_format($total_validated_ht, 2, ',', ' ') . " €\n";
        if ($total_vat > 0.01) {
            $ctx .= "- **TVA totale :** " . number_format($total_vat, 2, ',', ' ') . " €\n";
        }
        if ($total_pending > 0) {
            $ctx .= "- **Total en attente de validation (TTC) :** " . number_format($total_pending, 2, ',', ' ') . " €\n";
        }

        if (!empty($by_cat_ttc)) {
            $ctx .= "\n**Répartition par catégorie (factures validées) :**\n";
            arsort($by_cat_ttc);
            foreach ($by_cat_ttc as $cat => $amount_ttc_cat) {
                $amount_ht_cat = $by_cat_ht[$cat] ?? $amount_ttc_cat;
                $ctx .= "- " . $cat . " : " . number_format($amount_ht_cat, 2, ',', ' ') . " € HT / "
                     . number_format($amount_ttc_cat, 2, ',', ' ') . " € TTC\n";
            }
        }

        $ctx .= "\n**Détail des factures :**\n";
        foreach ($invoices as $inv) {
            $icon = $inv['status'] === 'validated' ? '✅' : ($inv['status'] === 'pending' ? '⏳' : '❌');
            $ht_str = isset($inv['amount_ht']) && $inv['amount_ht'] != $inv['amount_ttc']
                ? number_format((float)$inv['amount_ht'], 2, ',', ' ') . " € HT + TVA " . rtrim(rtrim(number_format((float)$inv['vat_rate'], 2, ',', ''), '0'), ',') . "%"
                : "sans TVA";
            $ctx .= sprintf(
                "- %s %s — %s (%s) — **%s € TTC** (%s) _(payé le %s)_",
                $icon,
                $inv['supplier_name'],
                $inv['category'] ?: '—',
                $inv['status'] === 'validated' ? 'validée' : ($inv['status'] === 'pending' ? 'en attente' : 'rejetée'),
                number_format((float)$inv['amount_ttc'], 2, ',', ' '),
                $ht_str,
                date('d/m/Y', strtotime($inv['invoice_date']))
            );
            if ($inv['description']) {
                $ctx .= "  \n   _" . trim($inv['description']) . "_";
            }
            $ctx .= "\n";
        }
    }

    return $ctx;
}

/**
 * Le prompt système de base d'Assokit.
 * C'est la "personnalité" de l'IA, ce qu'elle sait faire et ne pas faire.
 */
function assokit_system_prompt($project_context) {
    $today = date('d/m/Y');
    return <<<PROMPT
Tu es l'Assistant IA d'Assokit, un logiciel de gestion de projets pour les associations françaises.

**Ta mission :** aider les équipes associatives à documenter et valoriser leur travail — rédiger des bilans, des rapports, des emails, des synthèses. Tu es un collègue bienveillant et compétent, pas une machine.

**Ton style :**
- Français impeccable, chaleureux mais professionnel
- Tu vouvoies par défaut
- Tu vas droit au but, pas de blabla
- Tu structures tes réponses quand c'est utile (titres, listes)
- Tu n'inventes jamais de faits : si une info manque, tu dis "je ne dispose pas de cette information"
- Tu es concis par défaut. Longues réponses uniquement si demandé

**Ce que tu connais sur le projet en cours :**
{$project_context}

**Date du jour :** {$today}

**Règles importantes :**
1. Tu ne parles QUE du projet ci-dessus, jamais d'autres projets
2. Pour les bilans et rapports, tu t'appuies uniquement sur les faits présents dans le contexte
3. Si on te demande de rédiger un document officiel, respecte les codes du monde associatif français
4. Tu n'as aucune opinion politique, religieuse ou personnelle
5. Si une tâche dépasse tes compétences (démarche juridique complexe, avis médical, etc.), tu recommandes de consulter un professionnel
PROMPT;
}

/**
 * Appelle l'API Anthropic Claude.
 *
 * @param string $system_prompt Instructions système (personnalité + contexte)
 * @param array  $messages      [['role'=>'user'|'assistant', 'content'=>'...'], ...]
 * @param int    $max_tokens    Longueur max de la réponse
 * @return array ['success' => bool, 'content' => string, 'error' => string|null, 'tokens' => int]
 */
function ask_claude($system_prompt, $messages, $max_tokens = null) {
    if (!is_ai_enabled()) {
        return [
            'success' => false,
            'content' => '',
            'error' => 'L\'IA n\'est pas configurée. Demandez à l\'administrateur d\'ajouter la clé API Anthropic dans config.php.',
            'tokens' => 0,
        ];
    }

    $max_tokens = $max_tokens ?: AI_MAX_TOKENS;

    $payload = [
        'model' => ANTHROPIC_MODEL,
        'max_tokens' => (int)$max_tokens,
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return [
            'success' => false,
            'content' => '',
            'error' => 'Problème de connexion à l\'IA : ' . $curl_err,
            'tokens' => 0,
        ];
    }

    $data = json_decode($response, true);

    if ($http_code !== 200) {
        $msg = $data['error']['message'] ?? 'Erreur inconnue (code ' . $http_code . ')';
        return [
            'success' => false,
            'content' => '',
            'error' => 'L\'IA a répondu avec une erreur : ' . $msg,
            'tokens' => 0,
        ];
    }

    // Extraction du texte
    $content_text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $content_text .= $block['text'];
        }
    }

    $tokens = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

    return [
        'success' => true,
        'content' => $content_text,
        'error' => null,
        'tokens' => $tokens,
    ];
}

/**
 * Extrait les infos d'une facture depuis une image ou un PDF.
 * Utilise Claude Vision pour "lire" la photo et retourner les champs structurés.
 *
 * @param string $image_path  Chemin absolu vers l'image sur le disque
 * @param string $mime_type   Type MIME ('image/jpeg', 'image/png', 'application/pdf')
 * @return array {
 *   success => bool,
 *   data => [
 *     supplier_name => string|null,
 *     category => string|null,
 *     amount_ttc => float|null,
 *     invoice_date => string|null (YYYY-MM-DD),
 *     description => string|null,
 *   ],
 *   error => string|null,
 *   tokens => int,
 * }
 */
function extract_invoice_from_image($image_path, $mime_type) {
    if (!is_ai_enabled()) {
        return ['success' => false, 'data' => [], 'error' => 'IA non configurée', 'tokens' => 0];
    }

    if (!file_exists($image_path)) {
        return ['success' => false, 'data' => [], 'error' => 'Fichier introuvable', 'tokens' => 0];
    }

    // Conversion en base64
    $image_data = base64_encode(file_get_contents($image_path));

    // Claude accepte : image/jpeg, image/png, image/gif, image/webp, application/pdf
    $supported_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    if (!in_array($mime_type, $supported_types, true)) {
        return ['success' => false, 'data' => [], 'error' => 'Format non supporté pour l\'analyse IA', 'tokens' => 0];
    }

    $system_prompt = <<<PROMPT
Tu es un expert-comptable français. Ta mission : extraire les informations clés d'une facture française à partir d'une image ou d'un PDF.

**Tu dois répondre UNIQUEMENT avec un objet JSON**, sans aucun texte avant ou après. Format strict :

```json
{
  "supplier_name": "Nom du fournisseur tel qu'écrit sur la facture",
  "category": "Catégorie parmi : Matériel vidéo, Matériel audio, Matériel informatique, Fournitures, Alimentation, Transport, Location, Télécom, Livres / Matériel pédagogique, Frais administratifs, Prestations externes, Autre",
  "amount_ttc": 1234.56,
  "invoice_date": "2026-04-22",
  "description": "Résumé court en 1 phrase du contenu acheté (produits principaux)"
}
```

**Règles strictes :**
- Si une info est illisible ou absente, mets `null` pour ce champ (pas une chaîne vide, null)
- Le montant doit être le **total TTC** (pas HT). Utilise un point comme séparateur décimal
- La date doit être au format ISO `YYYY-MM-DD`. Si il y a plusieurs dates (date facture vs date échéance), prends la date d'émission de la facture
- Le nom du fournisseur : prends le nom commercial tel qu'il apparaît en haut de la facture (pas la SIRET ni l'adresse)
- Pour la catégorie : choisis UNIQUEMENT dans la liste ci-dessus. Si tu hésites, prends "Autre"
- Pour la description : 1 phrase courte qui décrit ce qui a été acheté, pas plus
- Ne mets JAMAIS d'explication en dehors du JSON
- Si ce n'est pas une facture (autre document), retourne `{"supplier_name": null, "category": null, "amount_ttc": null, "invoice_date": null, "description": "Ce document ne semble pas être une facture"}`
PROMPT;

    // Construction du message avec l'image
    $content_blocks = [
        [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mime_type,
                'data' => $image_data,
            ],
        ],
        [
            'type' => 'text',
            'text' => 'Extrais les informations de cette facture au format JSON strict.',
        ],
    ];

    // Pour un PDF, on utilise le type "document" au lieu de "image"
    if ($mime_type === 'application/pdf') {
        $content_blocks[0] = [
            'type' => 'document',
            'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $image_data,
            ],
        ];
    }

    $messages = [
        ['role' => 'user', 'content' => $content_blocks],
    ];

    // Appel API direct (on n'utilise pas ask_claude car la structure du message est différente)
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 90); // Un peu plus long car analyse d'image

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['success' => false, 'data' => [], 'error' => 'Erreur de connexion : ' . $curl_err, 'tokens' => 0];
    }

    $data = json_decode($response, true);

    if ($http_code !== 200) {
        $msg = $data['error']['message'] ?? 'Erreur ' . $http_code;
        return ['success' => false, 'data' => [], 'error' => 'L\'IA n\'a pas pu lire le document : ' . $msg, 'tokens' => 0];
    }

    // Récupère le texte de la réponse
    $content_text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $content_text .= $block['text'];
        }
    }

    $tokens = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);

    // On parse le JSON retourné par l'IA
    // Au cas où l'IA entoure son JSON de ```json ... ```, on nettoie
    $json_text = trim($content_text);
    $json_text = preg_replace('/^```(?:json)?\s*/', '', $json_text);
    $json_text = preg_replace('/\s*```$/', '', $json_text);

    $extracted = json_decode($json_text, true);

    if (!$extracted || !is_array($extracted)) {
        return [
            'success' => false,
            'data' => [],
            'error' => 'L\'IA n\'a pas retourné un format valide. Saisie manuelle nécessaire.',
            'tokens' => $tokens,
        ];
    }

    // On normalise les champs retournés
    $clean_data = [
        'supplier_name' => !empty($extracted['supplier_name']) ? trim($extracted['supplier_name']) : null,
        'category'      => !empty($extracted['category']) ? trim($extracted['category']) : null,
        'amount_ttc'    => isset($extracted['amount_ttc']) && is_numeric($extracted['amount_ttc']) ? (float)$extracted['amount_ttc'] : null,
        'invoice_date'  => !empty($extracted['invoice_date']) ? trim($extracted['invoice_date']) : null,
        'description'   => !empty($extracted['description']) ? trim($extracted['description']) : null,
    ];

    // Validation de la date ISO
    if ($clean_data['invoice_date'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean_data['invoice_date'])) {
        $clean_data['invoice_date'] = null;
    }

    return [
        'success' => true,
        'data' => $clean_data,
        'error' => null,
        'tokens' => $tokens,
    ];
}

/**
 * Convertit du Markdown basique en HTML (pour afficher les réponses IA).
 * Gère : titres, listes, gras, italique, code inline, retours à la ligne.
 */
function ai_markdown_to_html($md) {
    $h = htmlspecialchars($md, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Titres
    $h = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $h);
    $h = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $h);
    $h = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $h);
    // Gras et italique
    $h = preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $h);
    $h = preg_replace('/(?<!\w)_([^_]+)_(?!\w)/', '<em>$1</em>', $h);
    // Code inline
    $h = preg_replace('/`([^`]+)`/', '<code>$1</code>', $h);
    // Listes
    $h = preg_replace_callback('/((?:^[\-\*] .+(?:\n|$))+)/m', function ($m) {
        $items = preg_replace('/^[\-\*] (.+)$/m', '<li>$1</li>', trim($m[1]));
        return '<ul>' . $items . '</ul>';
    }, $h);
    $h = preg_replace_callback('/((?:^\d+\. .+(?:\n|$))+)/m', function ($m) {
        $items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', trim($m[1]));
        return '<ol>' . $items . '</ol>';
    }, $h);
    // Paragraphes : blocs séparés par ligne vide
    $blocks = preg_split('/\n\s*\n/', $h);
    $h = '';
    foreach ($blocks as $b) {
        $b = trim($b);
        if ($b === '') continue;
        if (preg_match('/^<(h\d|ul|ol|pre)/', $b)) {
            $h .= $b . "\n";
        } else {
            $h .= '<p>' . nl2br($b) . "</p>\n";
        }
    }
    return $h;
}
