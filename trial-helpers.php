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
