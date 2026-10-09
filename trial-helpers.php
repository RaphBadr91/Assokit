<?php
/**
 * ============================================================
 * ASSOKIT — Essai gratuit (inscription publique)
 * ============================================================
 * Un seul endroit pour :
 *   - la durée de l'essai (AK_TRIAL_DAYS, 14 jours) ;
 *   - le plan d'essai, le slug d'une nouvelle association ;
 *   - des écritures en base qui s'adaptent au schéma réel.
 *
 * Pourquoi « s'adapter au schéma » : le config.php et la base de prod
 * ne sont pas versionnés. L'inscription a cassé pendant des mois sur
 * une seule colonne inexistante (organizations.plan_type), et tous les
 * prospects ont vu « Erreur technique ». ak_db_insert() n'écrit que les
 * colonnes qui existent vraiment, et ak_db_accepts() vérifie qu'une
 * valeur est admise par un ENUM avant de l'écrire.
 * ============================================================
 */

if (!defined('AK_TRIAL_DAYS')) define('AK_TRIAL_DAYS', 14);

if (!function_exists('ak_db_columns')) {
    /**
     * Colonnes d'une table : [nom => ['type' => 'enum(...)', 'null' => bool, 'default' => ?string]].
     * Tableau vide si la table n'existe pas. Mis en cache pour la requête.
     */
    function ak_db_columns(PDO $pdo, string $table): array {
        static $cache = [];
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) return [];
        if (isset($cache[$table])) return $cache[$table];
        $cols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[$c['Field']] = [
                    'type'    => strtolower((string)$c['Type']),
                    'null'    => strtoupper((string)$c['Null']) === 'YES',
                    'default' => $c['Default'],
                ];
            }
        } catch (Throwable $e) {
            $cols = [];
        }
        return $cache[$table] = $cols;
    }
}

if (!function_exists('ak_db_accepts')) {
    /** La colonne existe-t-elle, et accepte-t-elle cette valeur (ENUM / SET) ? */
    function ak_db_accepts(PDO $pdo, string $table, string $col, string $value): bool {
        $cols = ak_db_columns($pdo, $table);
        if (!isset($cols[$col])) return false;
        if (preg_match('/^(enum|set)\((.*)\)$/', $cols[$col]['type'], $m)) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $m[2], $vals);
            $allowed = array_map(fn($v) => str_replace("''", "'", $v), $vals[1]);
            return in_array($value, $allowed, true);
        }
        return true;
    }
}

if (!function_exists('ak_db_pick')) {
    /** Première valeur de $candidates admise par la colonne, ou null. */
    function ak_db_pick(PDO $pdo, string $table, string $col, array $candidates): ?string {
        foreach ($candidates as $v) if (ak_db_accepts($pdo, $table, $col, (string)$v)) return (string)$v;
        return null;
    }
}

if (!function_exists('ak_db_fit')) {
    /** Coupe une chaîne à la longueur d'une colonne VARCHAR/CHAR (le mode strict refuse sinon toute la ligne). */
    function ak_db_fit(array $cols, string $col, $v) {
        if (!is_string($v) || !isset($cols[$col])) return $v;
        if (preg_match('/^(?:var)?char\((\d+)\)/', $cols[$col]['type'], $m) && mb_strlen($v) > (int)$m[1]) {
            return mb_substr($v, 0, (int)$m[1]);
        }
        if (strpos($cols[$col]['type'], 'tinytext') === 0 && strlen($v) > 255) return mb_strcut($v, 0, 255);
        return $v;
    }
}

if (!function_exists('ak_db_insert')) {
    /**
     * INSERT qui n'écrit que les colonnes existantes.
     *   $data : colonne => valeur (paramètres liés)
     *   $raw  : colonne => expression SQL écrite par le code (ex. 'NOW()'), jamais une saisie.
     * Si la table n'a pas pu être lue (droits SHOW refusés), tout est écrit tel quel.
     * Renvoie lastInsertId. Lève l'exception PDO en cas d'échec.
     */
    function ak_db_insert(PDO $pdo, string $table, array $data, array $raw = []): int {
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) throw new InvalidArgumentException('table');
        $cols = ak_db_columns($pdo, $table);
        $names = []; $place = []; $params = [];
        foreach ($data as $k => $v) {
            if ($cols && !isset($cols[$k])) continue;
            $names[] = "`$k`"; $place[] = '?'; $params[] = ak_db_fit($cols, $k, $v);
        }
        foreach ($raw as $k => $expr) {
            if ($cols && !isset($cols[$k])) continue;
            $names[] = "`$k`"; $place[] = $expr;
        }
        if (!$names) throw new RuntimeException("Aucune colonne écrivable dans $table");
        $pdo->prepare("INSERT INTO `$table` (" . implode(', ', $names) . ") VALUES (" . implode(', ', $place) . ")")
            ->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('ak_db_update')) {
    /** UPDATE ... WHERE id = ? limité aux colonnes existantes. Renvoie le nombre de lignes. */
    function ak_db_update(PDO $pdo, string $table, int $id, array $data, array $raw = []): int {
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) throw new InvalidArgumentException('table');
        $cols = ak_db_columns($pdo, $table);
        $sets = []; $params = [];
        foreach ($data as $k => $v) {
            if ($cols && !isset($cols[$k])) continue;
            $sets[] = "`$k` = ?"; $params[] = ak_db_fit($cols, $k, $v);
        }
        foreach ($raw as $k => $expr) {
            if ($cols && !isset($cols[$k])) continue;
            $sets[] = "`$k` = $expr";
        }
        if (!$sets) return 0;
        $params[] = $id;
        $st = $pdo->prepare("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id = ?");
        $st->execute($params);
        return $st->rowCount();
    }
}

if (!function_exists('ak_trial_plan')) {
    /**
     * Plan attribué pendant l'essai : 'pro-essai', sinon un plan marqué is_trial,
     * sinon la formule payante principale (essai de la formule complète).
     * @return array{id:int, slug:string, is_trial:bool}|null
     */
    function ak_trial_plan(PDO $pdo): ?array {
        try {
            $has_trial = isset(ak_db_columns($pdo, 'asso_plans')['is_trial']);
            $sql = "SELECT id, slug" . ($has_trial ? ", is_trial" : ", 0 AS is_trial") . " FROM asso_plans WHERE slug = 'pro-essai'"
                 . ($has_trial ? " OR is_trial = 1" : "")
                 . " ORDER BY (slug = 'pro-essai') DESC, id LIMIT 1";
            $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                $r = $pdo->query("SELECT id, slug, 0 AS is_trial FROM asso_plans WHERE slug IN ('assokit', 'pro', 'association')
                                  ORDER BY FIELD(slug, 'assokit', 'pro', 'association') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            }
            return $r ? ['id' => (int)$r['id'], 'slug' => (string)$r['slug'], 'is_trial' => !empty($r['is_trial'])] : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ak_signup_slug')) {
    /**
     * Slug unique, en minuscules, jamais préfixé « demo » (les slugs demo-* sont
     * traités comme espaces de démonstration : e-mails simulés, crons ignorés).
     */
    function ak_signup_slug(PDO $pdo, string $name): string {
        $s = $name;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($t) && $t !== '') $s = $t;
        }
        $s = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s), '-'));
        $s = trim(substr($s, 0, 50), '-');
        if (strlen($s) < 3) $s = 'asso-' . bin2hex(random_bytes(3));
        if (strpos($s, 'demo') === 0) $s = 'asso-' . $s;
        $base = $s;
        for ($i = 2; $i < 60; $i++) {
            try {
                $st = $pdo->prepare("SELECT 1 FROM organizations WHERE slug = ? LIMIT 1");
                $st->execute([$s]);
                if (!$st->fetchColumn()) return $s;
            } catch (Throwable $e) {
                return $s;
            }
            $s = $base . '-' . $i;
        }
        return $base . '-' . bin2hex(random_bytes(3));
    }
}

if (!function_exists('ak_founder_emails')) {
    /** Adresses qui reçoivent les alertes « demande d'essai ». */
    function ak_founder_emails(): array {
        $list = [];
        if (defined('AK_DEMO_NOTIFY_EMAIL') && AK_DEMO_NOTIFY_EMAIL) $list[] = AK_DEMO_NOTIFY_EMAIL;
        else $list[] = 'contact@assokit.fr';
        @require_once __DIR__ . '/api/_app-founder.php';
        if (function_exists('app_founder_emails')) $list = array_merge($list, app_founder_emails());
        $list = array_values(array_unique(array_filter(array_map('strtolower', $list), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
        return $list;
    }
}

if (!function_exists('ak_founder_user_ids')) {
    /** Comptes fondateurs actifs (cloche de notifications web + appli). */
    function ak_founder_user_ids(PDO $pdo): array {
        $emails = ak_founder_emails();
        try {
            $cols = ak_db_columns($pdo, 'users');
            $w = [];
            $params = [];
            if (isset($cols['is_founder'])) $w[] = 'is_founder = 1';
            if ($emails) { $w[] = 'LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '?')) . ')'; $params = $emails; }
            if (!$w) return [];
            $sql = "SELECT id FROM users WHERE (" . implode(' OR ', $w) . ")"
                 . (isset($cols['is_active']) ? " AND is_active = 1" : "")
                 . (isset($cols['deleted_at']) ? " AND deleted_at IS NULL" : "");
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ak_org_is_trial')) {
    /**
     * L'association est-elle en essai gratuit (en cours ou terminé sans abonnement) ?
     * Sert à plafonner les envois de masse des comptes créés librement.
     */
    function ak_org_is_trial(PDO $pdo, int $org_id): bool {
        static $cache = [];
        if ($org_id <= 0) return false;
        if (isset($cache[$org_id])) return $cache[$org_id];
        $trial = false;
        try {
            $st = $pdo->prepare("SELECT status FROM organizations WHERE id = ?");
            $st->execute([$org_id]);
            $status = (string)$st->fetchColumn();
            if ($status === 'trial') $trial = true;
            if (!$trial) {
                // Essai terminé (suspendu) sans abonnement actif payant
                $st = $pdo->prepare("SELECT s.status, p.is_trial, p.price_cents FROM asso_subscriptions s
                                     JOIN asso_plans p ON p.id = s.plan_id
                                     WHERE s.org_id = ? ORDER BY (s.status = 'active') DESC, s.id DESC LIMIT 1");
                $st->execute([$org_id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row && ($row['status'] === 'trial' || !empty($row['is_trial']))) $trial = true;
                if (!$row && $status === 'suspended') $trial = true;
            }
        } catch (Throwable $e) {
            $trial = false;
        }
        return $cache[$org_id] = $trial;
    }
}

if (!function_exists('ak_trial_end_label')) {
    /** « 24/10/2026 » à partir d'une date SQL. */
    function ak_trial_end_label(?string $sql_date): string {
        $t = $sql_date ? strtotime($sql_date) : false;
        return $t ? date('d/m/Y', $t) : '';
    }
}

// ============================================================
// ÉTAT DU PLAN — une seule lecture pour tous les écrans
// ============================================================
// Source de vérité des fonctionnalités : asso_subscriptions + asso_plans
// (ligne courante, même ordre que ak_get_current_plan). La date de fin
// d'essai vit sur organizations.trial_ends_at. organizations.plan est un
// ancien libellé, gardé synchronisé par ak_org_set_plan() mais jamais lu
// pour décider.

if (!function_exists('ak_plan_label')) {
    /** Libellé lisible d'un plan (slug asso_plans ou ancien libellé organizations.plan). */
    function ak_plan_label(?string $slug, ?string $name = null): string {
        $slug = strtolower(trim((string)$slug));
        $map = ['pro-essai' => 'Essai PRO', 'demarrage' => 'Démarrage', 'assokit' => 'Assokit', 'sur-mesure' => 'Sur-mesure',
                'essentiel' => 'Essentiel', 'association' => 'Association', 'organisation' => 'Organisation', 'pro' => 'Pro'];
        if (isset($map[$slug])) return $map[$slug];
        if ($name !== null && trim($name) !== '') return trim($name);
        return $slug === '' ? '—' : ucfirst($slug);
    }
}

if (!function_exists('ak_org_plan_state')) {
    /**
     * @return array{plan_id:?int, plan_slug:string, plan_label:string, sub_status:string, org_status:string,
     *               is_trial:bool, trial_expired:bool, trial_ends_at:?string, days_left:?int, price_cents:int,
     *               badge:string, badge_tone:string}
     *  badge / badge_tone : texte et ton communs ('trial', 'expired', 'active', 'suspended', 'cancelled', 'pending').
     */
    function ak_org_plan_state(PDO $pdo, int $org_id): array {
        $st = ['plan_id' => null, 'plan_slug' => '', 'plan_label' => '—', 'sub_status' => '', 'org_status' => '',
               'is_trial' => false, 'trial_expired' => false, 'trial_ends_at' => null, 'days_left' => null, 'price_cents' => 0,
               'badge' => '—', 'badge_tone' => 'gray'];
        try {
            $o = $pdo->prepare("SELECT status, plan, trial_ends_at FROM organizations WHERE id = ?");
            $o->execute([$org_id]);
            $org = $o->fetch(PDO::FETCH_ASSOC) ?: [];
            $st['org_status'] = (string)($org['status'] ?? '');
            $st['trial_ends_at'] = $org['trial_ends_at'] ?? null;
            $st['plan_slug'] = (string)($org['plan'] ?? '');
            $has_trial_col = isset(ak_db_columns($pdo, 'asso_plans')['is_trial']);
            $s = $pdo->prepare("SELECT s.status, p.id, p.slug, p.name, " . ($has_trial_col ? "p.is_trial" : "0 AS is_trial") . ", COALESCE(p.price_cents, 0) AS price_cents
                                FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                WHERE s.org_id = ? ORDER BY (s.status = 'active') DESC, s.id DESC LIMIT 1");
            $s->execute([$org_id]);
            if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
                $st['plan_id'] = (int)$row['id'];
                $st['plan_slug'] = (string)$row['slug'];
                $st['plan_label'] = ak_plan_label($row['slug'], $row['name']);
                $st['sub_status'] = (string)$row['status'];
                $st['price_cents'] = (int)$row['price_cents'];
                $st['is_trial'] = !empty($row['is_trial']) || $row['status'] === 'trial';
            } else {
                $st['plan_label'] = ak_plan_label($st['plan_slug']);
            }
            if ($st['org_status'] === 'trial') $st['is_trial'] = true;
            if ($st['trial_ends_at']) {
                $end = strtotime((string)$st['trial_ends_at']);
                if ($end) $st['days_left'] = (int)ceil(($end - time()) / 86400);
            }
            // Essai terminé : association suspendue par la fin d'essai, ou date dépassée sans plan payant
            if ($st['trial_ends_at'] && ($st['org_status'] === 'suspended' || ($st['is_trial'] && $st['days_left'] !== null && $st['days_left'] < 0))) {
                $st['trial_expired'] = true;
            }
        } catch (Throwable $e) {}

        if ($st['trial_expired']) {
            $st['badge'] = 'Essai terminé' . ($st['trial_ends_at'] ? ' le ' . date('d/m/Y', strtotime((string)$st['trial_ends_at'])) : '');
            $st['badge_tone'] = 'expired';
        } elseif ($st['is_trial']) {
            $d = $st['days_left'];
            $st['badge'] = 'ESSAI' . ($st['trial_ends_at'] ? ' · fin ' . date('d/m/Y', strtotime((string)$st['trial_ends_at'])) : '')
                         . ($d !== null ? ' (' . ($d <= 0 ? 'dernier jour' : 'J-' . $d) . ')' : '');
            $st['badge_tone'] = 'trial';
        } elseif ($st['org_status'] === 'suspended') {
            $st['badge'] = 'Suspendue'; $st['badge_tone'] = 'suspended';
        } elseif ($st['org_status'] === 'cancelled') {
            $st['badge'] = 'Résiliée'; $st['badge_tone'] = 'cancelled';
        } elseif (in_array($st['sub_status'], ['pending_payment', 'overdue'], true)) {
            $st['badge'] = $st['sub_status'] === 'overdue' ? 'Paiement en retard' : 'Paiement en attente'; $st['badge_tone'] = 'pending';
        } else {
            $st['badge'] = 'Active'; $st['badge_tone'] = 'active';
        }
        return $st;
    }
}

if (!function_exists('ak_legacy_sub_trial')) {
    /** Ancienne table subscriptions (bandeau + rappels) : remet la dernière ligne en essai jusqu'à $end. */
    function ak_legacy_sub_trial(PDO $pdo, int $org_id, string $end, bool $any_status): void {
        try {
            $cols = ak_db_columns($pdo, 'subscriptions');
            if (!$cols || !ak_db_accepts($pdo, 'subscriptions', 'status', 'trial')) return;
            $sets = "status = 'trial'" . (isset($cols['current_period_end']) ? ", current_period_end = ?" : "") . (isset($cols['updated_at']) ? ", updated_at = NOW()" : "");
            $args = isset($cols['current_period_end']) ? [$end, $org_id] : [$org_id];
            $pdo->prepare("UPDATE subscriptions SET $sets WHERE org_id = ?" . ($any_status ? "" : " AND status IN ('trial', 'suspended')") . " ORDER BY id DESC LIMIT 1")->execute($args);
        } catch (Throwable $e) {
            error_log('[ak_legacy_sub_trial] ' . get_class($e));
        }
    }
}

if (!function_exists('ak_org_set_plan')) {
    /**
     * Change le plan d'une association PARTOUT à la fois : ligne courante d'asso_subscriptions
     * (ou création), organizations.plan, et état d'essai (organizations.status / trial_ends_at,
     * ancienne table subscriptions). Plan d'essai → essai (fin conservée, ou J+AK_TRIAL_DAYS) ;
     * autre plan → essai terminé, association active.
     */
    function ak_org_set_plan(PDO $pdo, int $org_id, int $plan_id): void {
        $p = $pdo->prepare("SELECT id, slug" . (isset(ak_db_columns($pdo, 'asso_plans')['is_trial']) ? ", is_trial" : ", 0 AS is_trial") . " FROM asso_plans WHERE id = ?");
        $p->execute([$plan_id]);
        $plan = $p->fetch(PDO::FETCH_ASSOC);
        if (!$plan) throw new InvalidArgumentException('Plan inconnu.');
        $is_trial = !empty($plan['is_trial']) || $plan['slug'] === 'pro-essai';

        $cur = $pdo->prepare("SELECT id, plan_id FROM asso_subscriptions WHERE org_id = ? ORDER BY (status = 'active') DESC, id DESC LIMIT 1");
        $cur->execute([$org_id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        $cols = ak_db_columns($pdo, 'asso_subscriptions');
        $status = $is_trial ? (ak_db_pick($pdo, 'asso_subscriptions', 'status', ['trial', 'active']) ?? 'trial') : 'active';

        if ($is_trial) {
            $end = (string)$pdo->query("SELECT COALESCE((SELECT trial_ends_at FROM organizations WHERE id = " . (int)$org_id . " AND trial_ends_at > NOW()), DATE_ADD(NOW(), INTERVAL " . (int)AK_TRIAL_DAYS . " DAY))")->fetchColumn();
        }
        if ($row) {
            $sets = [];
            $args = [];
            if (isset($cols['previous_plan_id']) && (int)$row['plan_id'] !== (int)$plan_id) $sets[] = 'previous_plan_id = plan_id';
            $sets[] = 'plan_id = ?'; $args[] = $plan_id;
            $sets[] = 'status = ?';  $args[] = $status;
            if ($is_trial && isset($cols['current_period_end'])) { $sets[] = 'current_period_end = ?'; $args[] = $end; }
            foreach (['grace_period_end', 'downgraded_at', 'cancelled_at'] as $c) if (isset($cols[$c])) $sets[] = "$c = NULL";
            if (isset($cols['updated_at'])) $sets[] = 'updated_at = NOW()';
            $args[] = (int)$row['id'];
            $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        } else {
            ak_db_insert($pdo, 'asso_subscriptions', ['org_id' => $org_id, 'plan_id' => $plan_id, 'status' => $status,
                'current_period_end' => $is_trial ? $end : null], ['started_at' => 'NOW()', 'created_at' => 'NOW()', 'updated_at' => 'NOW()']);
        }

        if ($is_trial) {
            ak_db_update($pdo, 'organizations', $org_id, ['plan' => $plan['slug'], 'trial_ends_at' => $end]
                + (ak_db_accepts($pdo, 'organizations', 'status', 'trial') ? ['status' => 'trial'] : []));
            ak_legacy_sub_trial($pdo, $org_id, $end, true);
        } else {
            // Fin d'essai : l'association repasse active (une suspension manuelle sans essai n'est pas touchée)
            $pdo->prepare("UPDATE organizations SET plan = ?, status = IF(status = 'trial' OR (status = 'suspended' AND trial_ends_at IS NOT NULL), 'active', status), trial_ends_at = NULL WHERE id = ?")
                ->execute([$plan['slug'], $org_id]);
            try { $pdo->prepare("UPDATE subscriptions SET status = 'active' WHERE org_id = ? AND status IN ('trial', 'suspended')")->execute([$org_id]); } catch (Throwable $e) {}
        }
    }
}

if (!function_exists('ak_trial_extend')) {
    /**
     * Prolonge (ou rouvre) l'essai de N jours à partir de la fin actuelle si elle est future,
     * sinon d'aujourd'hui. Met à jour les trois endroits qui portent l'essai et remet
     * l'association en essai si la fin d'essai l'avait suspendue.
     * @return string nouvelle date de fin (SQL)
     */
    function ak_trial_extend(PDO $pdo, int $org_id, int $days): string {
        $days = max(1, min(90, $days));
        $end = (string)$pdo->query("SELECT DATE_ADD(GREATEST(NOW(), COALESCE((SELECT trial_ends_at FROM organizations WHERE id = " . (int)$org_id . "), NOW())), INTERVAL " . $days . " DAY)")->fetchColumn();
        $upd = ['trial_ends_at' => $end];
        if (ak_db_accepts($pdo, 'organizations', 'status', 'trial')) $upd['status'] = 'trial';
        ak_db_update($pdo, 'organizations', $org_id, $upd);
        ak_legacy_sub_trial($pdo, $org_id, $end, false);
        // Ligne d'essai : si la fin d'essai l'avait rétrogradée en Démarrage, on remet le plan d'essai
        $trial = ak_trial_plan($pdo);
        $cols = ak_db_columns($pdo, 'asso_subscriptions');
        try {
            $r = $pdo->prepare("SELECT s.id, s.status, s.plan_id" . (isset($cols['previous_plan_id']) ? ", s.previous_plan_id" : ", NULL AS previous_plan_id") . "
                                FROM asso_subscriptions s WHERE s.org_id = ? ORDER BY (s.status = 'active') DESC, s.id DESC LIMIT 1");
            $r->execute([$org_id]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            $demarrage = (int)$pdo->query("SELECT id FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
            $status = ak_db_pick($pdo, 'asso_subscriptions', 'status', ['trial', 'active']) ?? 'trial';
            if ($row && ($row['status'] === 'trial' || ((int)$row['plan_id'] === $demarrage && $trial && (int)$row['previous_plan_id'] === $trial['id']))) {
                $sets = ['status = ?']; $args = [$status];
                if ($trial) { $sets[] = 'plan_id = ?'; $args[] = $trial['id']; }
                if (isset($cols['current_period_end'])) { $sets[] = 'current_period_end = ?'; $args[] = $end; }
                if (isset($cols['downgraded_at'])) $sets[] = 'downgraded_at = NULL';
                if (isset($cols['updated_at'])) $sets[] = 'updated_at = NOW()';
                $args[] = (int)$row['id'];
                $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
            } elseif (!$row && $trial) {
                ak_db_insert($pdo, 'asso_subscriptions', ['org_id' => $org_id, 'plan_id' => $trial['id'], 'status' => $status, 'current_period_end' => $end],
                    ['started_at' => 'NOW()', 'created_at' => 'NOW()', 'updated_at' => 'NOW()']);
            }
        } catch (Throwable $e) {
            error_log('[ak_trial_extend] ' . get_class($e));
        }
        return $end;
    }
}
