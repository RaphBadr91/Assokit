<?php
/**
 * ============================================================
 * ASSOKIT — Action : chatter avec l'Assistant IA
 * ============================================================
 * 2 modes :
 *   - "chat" : conversation libre (user envoie un message, l'IA répond)
 *   - "generate" : génération d'un document type (bilan AG, email, etc.)
 *
 * v2 : ajout doc_type "bilan_date" → bilan daté depuis cockpit projet
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai-helper.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Session expirée.');
}

$project_id = (int)($_POST['project_id'] ?? 0);
$mode = $_POST['mode'] ?? 'chat';
$user = current_user();

// Vérification projet
$stmt = $pdo->prepare("
    SELECT p.id FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? AND f.org_id = ?
");
$stmt->execute([$project_id, $user['org_id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    die('Accès refusé.');
}

if (!is_ai_enabled()) {
    header('Location: /projet/' . $project_id . '/ia?err=nokey');
    exit;
}

// Construire le contexte du projet (infos + étapes + messages + fichiers)
$context = build_project_context($project_id, $pdo);
$system = assokit_system_prompt($context);

if ($mode === 'generate') {
    // ======= GÉNÉRATION D'UN DOCUMENT TYPE =======
    $doc_type = $_POST['doc_type'] ?? 'autre';
    $valid_types = ['bilan_ag', 'email_parents', 'rapport_subvention', 'fiche_com', 'synthese_etape', 'bilan_date'];
    if (!in_array($doc_type, $valid_types, true)) {
        header('Location: /projet/' . $project_id . '/ia');
        exit;
    }

    // === Bilan daté : récupère la date passée par le cockpit ===
    $bilan_date_iso = null;
    $bilan_date_fr = null;
    if ($doc_type === 'bilan_date') {
        $raw = $_POST['bilan_date'] ?? date('Y-m-d');
        // Validation : YYYY-MM-DD strict
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try {
                $dt = new DateTime($raw);
                $bilan_date_iso = $dt->format('Y-m-d');
                $jours = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
                $mois = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
                $bilan_date_fr = $jours[(int)$dt->format('w')] . ' ' . (int)$dt->format('j') . ' ' . $mois[(int)$dt->format('n')] . ' ' . $dt->format('Y');
            } catch (Throwable $e) {
                $bilan_date_iso = date('Y-m-d');
                $bilan_date_fr = date('d/m/Y');
            }
        } else {
            $bilan_date_iso = date('Y-m-d');
            $bilan_date_fr = date('d/m/Y');
        }
    }

    $prompts = [
        'bilan_ag' => [
            'title' => 'Bilan pour l\'Assemblée Générale',
            'user_msg' => "Rédige un **bilan du projet pour l'Assemblée Générale**.\n\nFormat attendu :\n- Une présentation courte du projet (contexte et objectif)\n- Les étapes franchies (ce qu'on a concrètement accompli)\n- Les chiffres clés (participants, portée)\n- **Bilan financier** : utilise OBLIGATOIREMENT les factures validées listées dans le contexte. Présente le total dépensé, la répartition par catégorie, et les principaux postes de dépense (fournisseurs clés). Compare avec le budget prévu.\n- Les réussites notables et les apprentissages\n- Les perspectivespour la suite\n\nTon : professionnel, fier mais sincère, accessible à des adhérents non-experts.",
        ],
        'email_parents' => [
            'title' => 'Email aux parents',
            'user_msg' => "Rédige un **email à envoyer aux parents des participants** pour les informer de l'avancement du projet.\n\nFormat attendu :\n- Objet clair\n- Corps de l'email chaleureux et informatif\n- Prochaines étapes\n- Signature à compléter\n\nTon : chaleureux, professionnel, rassurant. Ne parle PAS d'argent ni de factures dans un email aux parents.",
        ],
        'rapport_subvention' => [
            'title' => 'Rapport de subvention',
            'user_msg' => "Rédige un **rapport de subvention** pour un financeur public (DRAC, mairie, conseil départemental, etc.).\n\nFormat attendu :\n- Rappel du projet et de ses objectifs\n- Réalisations concrètes (avec chiffres précis)\n- Indicateurs de résultats (participants touchés, H/F, jeunes, etc.)\n- Difficultés rencontrées et solutions apportées\n- **Justification détaillée de l'utilisation du budget** : utilise OBLIGATOIREMENT la liste complète des factures validées. Présente un tableau de dépenses par catégorie, cite les principaux fournisseurs, total dépensé vs prévu, taux d'exécution budgétaire en %.\n- Perspectives\n\nTon : factuel, rigoureux, transparent. Les financeurs veulent voir CHAQUE euro justifié.",
        ],
        'fiche_com' => [
            'title' => 'Fiche de communication',
            'user_msg' => "Rédige une **fiche de communication** pour promouvoir le projet sur les réseaux sociaux et le site de l'association.\n\nFormat attendu :\n- 1 titre accrocheur\n- 1 paragraphe d'intro (3-4 phrases)\n- 3 points clés (en format liste)\n- 1 citation ou témoignage potentiel à intégrer\n- Hashtags suggérés\n\nTon : inspirant, humain, accessible au grand public. Ne parle PAS de factures ou de budget.",
        ],
        'synthese_etape' => [
            'title' => 'Synthèse d\'étape (point d\'avancement)',
            'user_msg' => "Fais une **synthèse d'étape** du projet.\n\nFormat attendu :\n- Où on en est (étapes validées, en cours)\n- **Point budget** : dépenses engagées vs prévu (en % et en €), alertes éventuelles si dérive\n- Ce qui va bien\n- Les points de vigilance\n- Ce qu'il reste à faire\n- Recommandations concrètes\n\nTon : direct, factuel, orienté action. C'est un outil de travail en interne.",
        ],
        'bilan_date' => [
            'title' => 'Bilan du projet — ' . ($bilan_date_fr ?? date('d/m/Y')),
            'user_msg' => "Rédige un **BILAN DU PROJET arrêté à la date du " . ($bilan_date_fr ?? date('d/m/Y')) . "**.\n\nC'est un bilan ponctuel destiné à la fois à l'équipe interne ET à un partage externe (financeurs, partenaires, AG ponctuelle). Il doit être complet, lisible et exportable tel quel.\n\nFormat attendu (utilise des titres en markdown ## et ###) :\n\n## Synthèse exécutive\nUn paragraphe de 4-6 lignes qui résume l'essentiel : contexte, où on en est, et la perspective. Doit pouvoir être lu seul.\n\n## Présentation du projet\n- Contexte et raison d'être\n- Objectif principal (cite l'objectif officiel s'il existe dans le contexte)\n- Public cible / bénéficiaires\n- Lieu et période\n\n## Avancement à la date du " . ($bilan_date_fr ?? date('d/m/Y')) . "\n- Pourcentage d'avancement global\n- Étapes validées (liste avec dates de validation et qui a validé)\n- Étapes en cours ou à venir\n- Indicateur de santé du projet\n\n## Réalisations concrètes\nDécris ce qui a été VRAIMENT accompli, en t'appuyant sur les étapes complétées, les fichiers déposés, et les échanges de l'équipe. Sois factuel, cite des exemples concrets.\n\n## Bilan financier\n**OBLIGATOIRE** : utilise les factures validées listées dans le contexte.\n- Budget prévu vs dépensé (en € et en %)\n- Tableau des principales dépenses par fournisseur/catégorie\n- Reste à engager\n- Si pas de factures dans le contexte : indique-le clairement (\"Aucune facture enregistrée à cette date — le bilan financier sera complété au fur et à mesure\").\n\n## Mobilisation et engagement\n- Nombre de participants impliqués\n- Activité de l'équipe (messages échangés, étapes validées sur la période)\n- Référent et contributeurs principaux\n\n## Points forts et apprentissages\nIdentifie 2 à 4 réussites concrètes, et 1 à 2 apprentissages utiles.\n\n## Points de vigilance\nIdentifie ce qui pourrait poser problème (étapes en retard, budget tendu, baisse d'activité). Sois honnête mais constructif.\n\n## Prochaines étapes\nLes 3 à 5 actions prioritaires pour la suite, avec si possible une échéance.\n\n---\n\nTon : professionnel, fier des accomplissements, transparent sur les difficultés, orienté action pour la suite. Rédige comme si ce bilan allait être imprimé et partagé. Ne mets pas de \"je\" dans le texte — utilise \"l'équipe\", \"le projet\", \"l'association\".\n\nCommence par le bloc \"## Synthèse exécutive\" — n'ajoute pas de titre principal, le titre du document est déjà géré.",
        ],
    ];

    $p = $prompts[$doc_type];
    $result = ask_claude($system, [['role' => 'user', 'content' => $p['user_msg']]]);

    if (!$result['success']) {
        header('Location: /projet/' . $project_id . '/ia?err=' . urlencode($result['error']));
        exit;
    }

    // Enregistrer le document généré
    $pdo->prepare("
        INSERT INTO ai_generated_docs (project_id, user_id, doc_type, title, content)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $project_id, $user['id'], $doc_type, $p['title'], $result['content']
    ]);

    header('Location: /projet/' . $project_id . '/ia?generated=1');
    exit;
}

// ======= MODE CHAT LIBRE =======
$user_message = trim($_POST['message'] ?? '');
if ($user_message === '' || mb_strlen($user_message) > 4000) {
    header('Location: /projet/' . $project_id . '/ia');
    exit;
}

$conv_id = (int)($_POST['conversation_id'] ?? 0);

// Créer une nouvelle conversation si besoin
if ($conv_id <= 0) {
    $title = mb_substr($user_message, 0, 80);
    $pdo->prepare("INSERT INTO ai_conversations (project_id, user_id, title) VALUES (?, ?, ?)")
        ->execute([$project_id, $user['id'], $title]);
    $conv_id = (int)$pdo->lastInsertId();
}

// Charger l'historique de conversation pour contexte
$stmt = $pdo->prepare("
    SELECT role, content FROM ai_messages
    WHERE conversation_id = ?
    ORDER BY created_at ASC, id ASC
");
$stmt->execute([$conv_id]);
$history = $stmt->fetchAll();

// Ajouter le nouveau message utilisateur à l'historique
$messages = [];
foreach ($history as $h) {
    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
}
$messages[] = ['role' => 'user', 'content' => $user_message];

// Sauver le message utilisateur
$pdo->prepare("INSERT INTO ai_messages (conversation_id, role, content) VALUES (?, 'user', ?)")
    ->execute([$conv_id, $user_message]);

// Appel à l'IA
$result = ask_claude($system, $messages);

if (!$result['success']) {
    header('Location: /projet/' . $project_id . '/ia?conv=' . $conv_id . '&err=' . urlencode($result['error']));
    exit;
}

// Sauver la réponse IA
$pdo->prepare("INSERT INTO ai_messages (conversation_id, role, content, tokens_used) VALUES (?, 'assistant', ?, ?)")
    ->execute([$conv_id, $result['content'], $result['tokens']]);

// Mettre à jour le timestamp de la conversation
$pdo->prepare("UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?")->execute([$conv_id]);

header('Location: /projet/' . $project_id . '/ia?conv=' . $conv_id);
exit;
