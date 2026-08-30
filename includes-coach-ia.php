<?php
/**
 * Helpers Coach Assokit hebdomadaire
 */

function coach_build_context(PDO $pdo, int $org_id): array {
    $ctx = ['org_id' => $org_id];

    // Période : lundi semaine passée → dimanche
    $week_start = date('Y-m-d', strtotime('monday last week'));
    $week_end   = date('Y-m-d', strtotime('sunday last week'));
    $ctx['week_start'] = $week_start;
    $ctx['week_end']   = $week_end;

    // Org
    try {
        $stmt = $pdo->prepare("SELECT id, name, type FROM organizations WHERE id = ?");
        $stmt->execute([$org_id]);
        $ctx['org'] = $stmt->fetch() ?: ['name' => 'Association', 'type' => 'asso'];
    } catch (Throwable $e) { $ctx['org'] = ['name' => 'Association', 'type' => 'asso']; }

    // Projets
    try {
        $stmt = $pdo->prepare("SELECT
            COUNT(*) total,
            SUM(CASE WHEN p.archived_at IS NULL THEN 1 ELSE 0 END) actifs,
            SUM(CASE WHEN p.created_at >= ? THEN 1 ELSE 0 END) nouveaux,
            SUM(CASE WHEN p.archived_at IS NULL AND p.deadline IS NOT NULL AND p.deadline < CURDATE() AND (p.status IS NULL OR p.status != 'done') THEN 1 ELSE 0 END) retard,
            SUM(CASE WHEN p.archived_at IS NULL AND p.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) deadlines_14j
            FROM projects p
            JOIN folders f ON f.id = p.folder_id
            WHERE f.org_id = ?");
        $stmt->execute([$week_start, $org_id]);
        $ctx['projects'] = $stmt->fetch() ?: [];
    } catch (Throwable $e) { $ctx['projects'] = []; }

    // Top 3 projets en retard
    try {
        $stmt = $pdo->prepare("SELECT p.id, p.name, p.deadline, DATEDIFF(CURDATE(), p.deadline) jours_retard
            FROM projects p JOIN folders f ON f.id = p.folder_id
            WHERE f.org_id = ? AND p.archived_at IS NULL AND p.deadline IS NOT NULL AND p.deadline < CURDATE()
              AND (p.status IS NULL OR p.status != 'done')
            ORDER BY p.deadline ASC LIMIT 5");
        $stmt->execute([$org_id]);
        $ctx['projects_late'] = $stmt->fetchAll();
    } catch (Throwable $e) { $ctx['projects_late'] = []; }

    // Activité (messages, fichiers ajoutés)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_messages pm
            JOIN projects p ON p.id = pm.project_id JOIN folders f ON f.id = p.folder_id
            WHERE f.org_id = ? AND pm.created_at BETWEEN ? AND ?");
        $stmt->execute([$org_id, $week_start, $week_end . ' 23:59:59']);
        $ctx['msgs_week'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $ctx['msgs_week'] = 0; }

    // Adhérents
    try {
        $stmt = $pdo->prepare("SELECT
            SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) total,
            SUM(CASE WHEN deleted_at IS NULL AND created_at >= ? THEN 1 ELSE 0 END) nouveaux
            FROM users WHERE org_id = ?");
        $stmt->execute([$week_start, $org_id]);
        $ctx['adherents'] = $stmt->fetch() ?: [];
    } catch (Throwable $e) { $ctx['adherents'] = []; }

    // Cotisations actives
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cotisation_campaigns WHERE org_id = ? AND status = 'active'");
        $stmt->execute([$org_id]);
        $ctx['cotis_actives'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $ctx['cotis_actives'] = 0; }

    // Subventions urgentes
    try {
        $stmt = $pdo->prepare("SELECT id, name, funder, amount_requested, deadline_apply, status, deadline_report,
            DATEDIFF(deadline_apply, CURDATE()) jours_apply,
            DATEDIFF(deadline_report, CURDATE()) jours_report
            FROM grants WHERE org_id = ? AND archived_at IS NULL AND status NOT IN ('rejected','reported','archived')
            ORDER BY COALESCE(deadline_apply, deadline_report) ASC LIMIT 5");
        $stmt->execute([$org_id]);
        $ctx['grants_active'] = $stmt->fetchAll();
    } catch (Throwable $e) { $ctx['grants_active'] = []; }

    try {
        $stmt = $pdo->prepare("SELECT
            COUNT(*) total,
            SUM(CASE WHEN status='granted' THEN amount_granted ELSE 0 END) accorde,
            SUM(CASE WHEN status='submitted' OR status='in_review' THEN 1 ELSE 0 END) en_cours
            FROM grants WHERE org_id = ? AND archived_at IS NULL");
        $stmt->execute([$org_id]);
        $ctx['grants_stats'] = $stmt->fetch() ?: [];
    } catch (Throwable $e) { $ctx['grants_stats'] = []; }

    // Événements à venir 14j
    try {
        $stmt = $pdo->prepare("SELECT title, start_at FROM events
            WHERE org_id = ? AND deleted_at IS NULL AND start_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)
            ORDER BY start_at ASC LIMIT 5");
        $stmt->execute([$org_id]);
        $ctx['events_14j'] = $stmt->fetchAll();
    } catch (Throwable $e) { $ctx['events_14j'] = []; }

    return $ctx;
}

function coach_build_prompt(array $ctx): string {
    $org = $ctx['org']['name'] ?? 'Association';
    $type_label = ($ctx['org']['type'] ?? 'asso') === 'tpe' ? 'TPE' : 'association loi 1901';

    $p = "Tu es \"AssoCoach\", un coach Assokit hebdomadaire pour les présidents et trésoriers d'associations.\n\n";
    $p .= "Ton rôle : analyser les chiffres de la semaine, repérer les vrais signaux faibles, et proposer 3 actions CONCRÈTES et PRIORITAIRES pour la semaine qui démarre.\n\n";
    $p .= "Ton ton : direct, utile, bienveillant, concret. Pas de blabla, pas de jargon.\n\n";

    $p .= "=== CONTEXTE ===\n";
    $p .= "Organisation : {$org} ({$type_label})\n";
    $p .= "Période analysée : {$ctx['week_start']} au {$ctx['week_end']}\n\n";

    $pj = $ctx['projects'] ?? [];
    $p .= "PROJETS :\n";
    $p .= "- Actifs : " . ((int)($pj['actifs'] ?? 0)) . "\n";
    $p .= "- Nouveaux cette semaine : " . ((int)($pj['nouveaux'] ?? 0)) . "\n";
    $p .= "- En retard : " . ((int)($pj['retard'] ?? 0)) . "\n";
    $p .= "- Échéances dans 14 jours : " . ((int)($pj['deadlines_14j'] ?? 0)) . "\n";

    if (!empty($ctx['projects_late'])) {
        $p .= "\nProjets en retard :\n";
        foreach ($ctx['projects_late'] as $l) {
            $p .= "  · {$l['name']} (retard {$l['jours_retard']} jours)\n";
        }
    }

    $p .= "\nADHÉRENTS :\n";
    $p .= "- Total : " . ((int)($ctx['adherents']['total'] ?? 0)) . "\n";
    $p .= "- Nouveaux cette semaine : " . ((int)($ctx['adherents']['nouveaux'] ?? 0)) . "\n";

    $p .= "\nACTIVITÉ :\n- Messages projets cette semaine : {$ctx['msgs_week']}\n";

    $p .= "\nCOTISATIONS :\n- Campagnes actives : {$ctx['cotis_actives']}\n";

    $gs = $ctx['grants_stats'] ?? [];
    $p .= "\nSUBVENTIONS :\n";
    $p .= "- Total dossiers : " . ((int)($gs['total'] ?? 0)) . "\n";
    $p .= "- Accordé cumulé : " . number_format((float)($gs['accorde'] ?? 0), 0, ',', ' ') . " €\n";
    $p .= "- Dossiers en cours : " . ((int)($gs['en_cours'] ?? 0)) . "\n";

    if (!empty($ctx['grants_active'])) {
        $p .= "\nDossiers à suivre :\n";
        foreach ($ctx['grants_active'] as $g) {
            $line = "  · {$g['name']} ({$g['funder']}, statut={$g['status']}";
            if ($g['jours_apply'] !== null) $line .= ", deadline dépôt J" . ($g['jours_apply'] >= 0 ? "+{$g['jours_apply']}" : $g['jours_apply']);
            if ($g['jours_report'] !== null && $g['status'] === 'granted') $line .= ", bilan J" . ($g['jours_report'] >= 0 ? "+{$g['jours_report']}" : $g['jours_report']);
            $line .= ")\n";
            $p .= $line;
        }
    }

    if (!empty($ctx['events_14j'])) {
        $p .= "\nÉVÉNEMENTS À VENIR (14j) :\n";
        foreach ($ctx['events_14j'] as $e) {
            $p .= "  · " . date('d/m H:i', strtotime($e['start_at'])) . " — {$e['title']}\n";
        }
    }

    $p .= "\n=== TÂCHE ===\n";
    $p .= "Réponds UNIQUEMENT par un JSON valide (pas de texte autour, pas de markdown ```), avec exactement cette structure :\n\n";
    $p .= '{
  "summary": "string · résumé synthétique de la semaine en 4-6 phrases. Cite les chiffres importants. Adapte le ton au contexte (féliciter / alerter).",
  "highlights": ["string", "..."],
  "warnings": ["string", "..."],
  "recos": [
    {"icon": "🎯", "title": "string · action concrète et précise (max 8 mots)", "why": "string · pourquoi c\'est prioritaire (1 phrase)"},
    {"icon": "⚠️", "title": "...", "why": "..."},
    {"icon": "💡", "title": "...", "why": "..."}
  ]
}';
    $p .= "\n\nRègles :\n";
    $p .= "- 1 à 4 highlights (faits positifs réels, pas génériques)\n";
    $p .= "- 0 à 4 warnings (vrais problèmes détectés, sinon vide)\n";
    $p .= "- EXACTEMENT 3 recos triées par priorité\n";
    $p .= "- Icônes parmi : 🎯 ⚠️ 💡 📞 📊 💰 ✅ 📅 📧 🚨 🤝 📝\n";
    $p .= "- Ne pas inventer de chiffres ou faits non présents dans le contexte.\n";
    return $p;
}

function coach_parse_response(string $raw): ?array {
    // Strip markdown fences si présent
    $txt = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
    $data = json_decode($txt, true);
    if (!is_array($data)) return null;
    return [
        'summary' => (string)($data['summary'] ?? ''),
        'highlights' => is_array($data['highlights'] ?? null) ? $data['highlights'] : [],
        'warnings' => is_array($data['warnings'] ?? null) ? $data['warnings'] : [],
        'recos' => is_array($data['recos'] ?? null) ? array_slice($data['recos'], 0, 3) : [],
    ];
}

function coach_save_report(PDO $pdo, int $org_id, array $ctx, array $parsed, string $raw, ?int $user_id = null): int {
    $stmt = $pdo->prepare("INSERT INTO coach_reports
        (org_id, week_start, week_end, summary_md, highlights_json, warnings_json, recos_json, raw_response, generated_by)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
        summary_md=VALUES(summary_md), highlights_json=VALUES(highlights_json),
        warnings_json=VALUES(warnings_json), recos_json=VALUES(recos_json),
        raw_response=VALUES(raw_response), generated_at=NOW(), generated_by=VALUES(generated_by)");
    $stmt->execute([
        $org_id, $ctx['week_start'], $ctx['week_end'],
        $parsed['summary'],
        json_encode($parsed['highlights'], JSON_UNESCAPED_UNICODE),
        json_encode($parsed['warnings'], JSON_UNESCAPED_UNICODE),
        json_encode($parsed['recos'], JSON_UNESCAPED_UNICODE),
        $raw, $user_id
    ]);
    // Récupérer ID
    $stmt = $pdo->prepare("SELECT id FROM coach_reports WHERE org_id = ? AND week_start = ?");
    $stmt->execute([$org_id, $ctx['week_start']]);
    return (int)$stmt->fetchColumn();
}

function coach_load_latest(PDO $pdo, int $org_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM coach_reports WHERE org_id = ? ORDER BY week_start DESC LIMIT 1");
    $stmt->execute([$org_id]);
    return $stmt->fetch() ?: null;
}

function coach_load_history(PDO $pdo, int $org_id, int $limit = 10): array {
    $stmt = $pdo->prepare("SELECT id, week_start, week_end, generated_at FROM coach_reports WHERE org_id = ? ORDER BY week_start DESC LIMIT " . (int)$limit);
    $stmt->execute([$org_id]);
    return $stmt->fetchAll();
}

function coach_render_email_html(array $report, array $org): string {
    $hl = json_decode($report['highlights_json'] ?? '[]', true) ?: [];
    $wn = json_decode($report['warnings_json'] ?? '[]', true) ?: [];
    $rc = json_decode($report['recos_json'] ?? '[]', true) ?: [];
    $url = "https://assokit.fr/coach-ia";
    $week = date('d/m', strtotime($report['week_start'])) . ' → ' . date('d/m/Y', strtotime($report['week_end']));

    $html = '<div style="font-family:-apple-system,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#f9fafb;">';
    $html .= '<div style="background:#fff;border-radius:14px;padding:28px;border:1px solid #e5e7eb;">';
    $html .= '<div style="display:inline-block;background:#10B981;color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:999px;letter-spacing:0.05em;text-transform:uppercase;margin-bottom:10px;">🤖 Coach Assokit · ' . htmlspecialchars($week) . '</div>';
    $html .= '<h1 style="font-size:22px;margin:0 0 14px;color:#111827;">Ton bilan de la semaine — ' . htmlspecialchars($org['name']) . '</h1>';
    $html .= '<p style="color:#374151;font-size:14.5px;line-height:1.6;margin:0 0 18px;white-space:pre-line;">' . htmlspecialchars($report['summary_md']) . '</p>';

    if (!empty($hl)) {
        $html .= '<div style="background:#ECFDF5;border-radius:10px;padding:14px 16px;margin:14px 0;border-left:3px solid #10B981;">';
        $html .= '<div style="font-size:11px;font-weight:700;color:#065F46;text-transform:uppercase;margin-bottom:6px;">✨ Points forts</div>';
        foreach ($hl as $h) $html .= '<div style="font-size:13px;color:#111827;padding:3px 0;">• ' . htmlspecialchars($h) . '</div>';
        $html .= '</div>';
    }
    if (!empty($wn)) {
        $html .= '<div style="background:#FEF3C7;border-radius:10px;padding:14px 16px;margin:14px 0;border-left:3px solid #F59E0B;">';
        $html .= '<div style="font-size:11px;font-weight:700;color:#92400E;text-transform:uppercase;margin-bottom:6px;">⚠️ Vigilance</div>';
        foreach ($wn as $w) $html .= '<div style="font-size:13px;color:#111827;padding:3px 0;">• ' . htmlspecialchars($w) . '</div>';
        $html .= '</div>';
    }

    $html .= '<h2 style="font-size:14px;color:#065F46;margin:22px 0 10px;">🎯 Tes 3 actions prioritaires</h2>';
    foreach ($rc as $r) {
        $html .= '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:13px 16px;margin-bottom:8px;">';
        $html .= '<div style="font-size:14px;font-weight:600;color:#111827;">' . htmlspecialchars($r['icon'] ?? '•') . ' ' . htmlspecialchars($r['title'] ?? '') . '</div>';
        if (!empty($r['why'])) $html .= '<div style="font-size:12.5px;color:#6b7280;margin-top:4px;">' . htmlspecialchars($r['why']) . '</div>';
        $html .= '</div>';
    }

    $html .= '<a href="' . $url . '" style="display:inline-block;background:#10B981;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;margin-top:14px;">Ouvrir mon Coach Assokit →</a>';
    $html .= '</div><p style="text-align:center;color:#9ca3af;font-size:11px;margin:18px 0 0;">AssoKit · ton coach Assokit chaque lundi matin</p></div>';
    return $html;
}
