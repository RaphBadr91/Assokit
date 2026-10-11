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
     * La base est coupée d'après la longueur RÉELLE de organizations.slug, en gardant
     * la place d'un suffixe « -N » ou « -xxxxxx » : le SELECT ci-dessous vérifie ainsi
     * exactement la valeur qui sera écrite (une colonne courte coupait le suffixe à
     * l'INSERT, et toutes les inscriptions d'un nom long échouaient en doublon).
     */
    function ak_signup_slug(PDO $pdo, string $name): string {
        $max = 255;
        if (preg_match('/char\((\d+)\)/', ak_db_columns($pdo, 'organizations')['slug']['type'] ?? '', $m)) $max = (int)$m[1];
        $cap = max(3, min(50, $max - 7));
        $s = $name;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($t) && $t !== '') $s = $t;
        }
        $s = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s), '-'));
        if (strpos($s, 'demo') === 0) $s = 'asso-' . $s;
        $s = trim(substr($s, 0, $cap), '-');
        if (strlen($s) < 3) $s = substr('asso-' . bin2hex(random_bytes(3)), 0, $cap);
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
    /**
     * Comptes fondateurs actifs (cloche de notifications web + appli).
     * Une adresse ne suffit jamais : le compte doit aussi être marqué fondateur ou
     * super admin. Sinon, s'inscrire avec contact@assokit.fr (adresse d'alerte, sans
     * compte) donnait les nom, e-mail et structure de chaque prospect suivant.
     */
    function ak_founder_user_ids(PDO $pdo): array {
        $emails = ak_founder_emails();
        try {
            $cols = ak_db_columns($pdo, 'users');
            $w = [];
            $params = [];
            if (isset($cols['is_founder'])) $w[] = 'is_founder = 1';
            if ($emails && isset($cols['is_super_admin'])) {
                $w[] = '(is_super_admin = 1 AND LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '?')) . '))';
                $params = $emails;
            }
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
     * L'association est-elle en essai gratuit (en cours, ou terminé sans être devenue cliente) ?
     * Sert à plafonner les envois de masse des comptes créés librement.
     *   - client payant (abonnement actif hors essai : prix > 0, Sur-mesure ou abonnement Stripe) :
     *     jamais, même si organizations.status est resté « trial » ;
     *   - essai en cours (organizations.status « trial ») : oui ;
     *   - essai terminé sans conversion : oui, même rétrogradé en Démarrage par cron-job-essai.
     *     Toute conversion (paiement Stripe, ak_org_set_plan vers un plan hors essai) efface
     *     trial_ends_at : une fin d'essai encore posée veut dire « essai jamais converti » ;
     *   - ligne d'abonnement courante sur l'essai : oui.
     * Comptes de démo, anciens ou offerts (aucune fin d'essai) : non.
     */
    function ak_org_is_trial(PDO $pdo, int $org_id): bool {
        static $cache = [];
        if ($org_id <= 0) return false;
        if (isset($cache[$org_id])) return $cache[$org_id];
        $trial = false;
        try {
            $st = $pdo->prepare("SELECT status, trial_ends_at FROM organizations WHERE id = ?");
            $st->execute([$org_id]);
            $org = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $paid = $pdo->prepare("SELECT 1 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                   WHERE s.org_id = ? AND " . ak_paid_sub_sql($pdo) . " LIMIT 1");
            $paid->execute([$org_id]);
            if ($paid->fetchColumn()) return $cache[$org_id] = false;
            if (($org['status'] ?? '') === 'trial' || !empty($org['trial_ends_at'])) {
                $trial = true;
            } else {
                $st = $pdo->prepare("SELECT s.status, (" . ak_plan_trial_sql($pdo) . ") AS plan_trial FROM asso_subscriptions s
                                     JOIN asso_plans p ON p.id = s.plan_id
                                     WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
                $st->execute([$org_id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                $trial = $row && ($row['status'] === 'trial' || !empty($row['plan_trial']));
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
// (ligne courante, ordre ak_sub_order(), le même que ak_get_current_plan).
// La date de fin d'essai vit sur organizations.trial_ends_at.
// organizations.plan est un ancien libellé, gardé synchronisé par
// ak_org_set_plan() mais jamais lu pour décider.
//
// Le calcul de l'état (essai, fin d'essai, badge) est fait UNE fois, dans
// ak_plan_state_compute() : ak_org_plan_state() (une association),
// sa_org_plan_states() (listes du cockpit) et fd_org_tones() (compteurs de
// l'appli) ne font que lire la même ligne et l'appeler.

if (!function_exists('ak_sub_order')) {
    /**
     * ORDER BY de la ligne d'abonnement courante : la ligne active, puis l'essai en cours, puis la
     * plus récente. Un paiement Stripe en attente ou refusé (« pending_payment », « overdue »,
     * ligne créée au clic sur « Payer ») ne passe donc jamais devant l'essai : l'essai reste la
     * ligne courante tant que l'abonnement payant n'est pas actif.
     */
    function ak_sub_order(string $a = 's'): string {
        if (!preg_match('/^[a-z0-9_]+$/i', $a)) $a = 's';
        return "($a.status = 'active') DESC, ($a.status = 'trial') DESC, $a.id DESC";
    }
}

if (!function_exists('ak_plan_trial_sql')) {
    /** Condition SQL « plan d'essai » sur l'alias d'asso_plans (même règle que ak_plan_state_compute). */
    function ak_plan_trial_sql(PDO $pdo, string $p = 'p'): string {
        if (!preg_match('/^[a-z0-9_]+$/i', $p)) $p = 'p';
        return isset(ak_db_columns($pdo, 'asso_plans')['is_trial'])
            ? "COALESCE($p.is_trial, 0) = 1 OR $p.slug = 'pro-essai'"
            : "$p.slug = 'pro-essai'";
    }
}

if (!function_exists('ak_paid_sub_sql')) {
    /**
     * Condition SQL « abonnement payant en cours » (alias $s = asso_subscriptions, $p = asso_plans) :
     * ligne active, hors plan d'essai, payante (prix > 0), Sur-mesure (facturé à part) ou abonnement
     * Stripe. Même règle que ak_plan_state_compute() ('is_paid').
     */
    function ak_paid_sub_sql(PDO $pdo, string $s = 's', string $p = 'p'): string {
        if (!preg_match('/^[a-z0-9_]+$/i', $s)) $s = 's';
        if (!preg_match('/^[a-z0-9_]+$/i', $p)) $p = 'p';
        $stripe = isset(ak_db_columns($pdo, 'asso_subscriptions')['stripe_subscription_id'])
            ? " OR COALESCE($s.stripe_subscription_id, '') <> ''" : '';
        return "($s.status = 'active' AND NOT (" . ak_plan_trial_sql($pdo, $p) . ")"
             . " AND (COALESCE($p.price_cents, 0) > 0 OR $p.slug = 'sur-mesure'$stripe))";
    }
}

if (!function_exists('ak_plan_off_stripe_sql')) {
    /**
     * Condition SQL « plan qui n'est pas facturé par Stripe » (alias $p = asso_plans) : plan d'essai,
     * gratuit, Sur-mesure / sur devis (facturé à part), ou sans prix Stripe (stripe_price_id_monthly
     * vide : aucun paiement Stripe ne peut porter ce plan, le webhook reconnaît les plans par cette
     * colonne). Une ligne Stripe sur un tel plan a été changée à la main par le Fondateur : Stripe
     * facture encore l'ANCIENNE formule et ne décide plus du statut de la ligne. Une seule règle pour
     * l'alerte du cockpit (ak_stripe_left_on_free) et pour stripe-webhook.php.
     */
    function ak_plan_off_stripe_sql(PDO $pdo, string $p = 'p'): string {
        if (!preg_match('/^[a-z0-9_]+$/i', $p)) $p = 'p';
        $pcols = ak_db_columns($pdo, 'asso_plans');
        return "((" . ak_plan_trial_sql($pdo, $p) . ") OR COALESCE($p.price_cents, 0) <= 0 OR $p.slug = 'sur-mesure'"
             . (isset($pcols['is_custom_quote']) ? " OR COALESCE($p.is_custom_quote, 0) = 1" : "")
             . (isset($pcols['stripe_price_id_monthly']) ? " OR COALESCE($p.stripe_price_id_monthly, '') = ''" : "")
             . ")";
    }
}

if (!class_exists('AkTrialBlocked')) {
    /** Action sur l'essai refusée pour une raison métier : le message est montré tel quel au Fondateur. */
    class AkTrialBlocked extends RuntimeException {}
}

if (!function_exists('ak_sub_state_cols')) {
    /** Colonnes de la ligne courante lues par ak_plan_state_compute() (alias s / p). */
    function ak_sub_state_cols(PDO $pdo): string {
        return "s.status AS sub_status, p.id AS plan_id, p.slug, p.name, "
             . (isset(ak_db_columns($pdo, 'asso_plans')['is_trial']) ? "p.is_trial" : "0") . " AS is_trial, "
             . "COALESCE(p.price_cents, 0) AS price_cents, "
             . (isset(ak_db_columns($pdo, 'asso_subscriptions')['stripe_subscription_id']) ? "s.stripe_subscription_id" : "NULL") . " AS stripe_subscription_id";
    }
}

if (!function_exists('ak_trial_days_left')) {
    /**
     * Jours CALENDAIRES (heure de Paris) d'aujourd'hui à la date de fin : 0 le dernier jour, négatif
     * une fois la date passée. Compter en jours de calendrier (et non en tranches de 24 h) évite
     * le jour de trop au passage à l'heure d'hiver, et un « J-2 » le matin devenu « J-1 » le soir.
     */
    function ak_trial_days_left(?string $sql_date): ?int {
        if (!$sql_date || !strtotime($sql_date)) return null;
        $day = preg_match('/^\d{4}-\d{2}-\d{2}/', $sql_date, $m) ? $m[0] : date('Y-m-d', strtotime($sql_date));
        $utc = new DateTimeZone('UTC');
        $today = (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
        return (int)(new DateTime($today, $utc))->diff(new DateTime($day, $utc))->format('%r%a');
    }
}

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

if (!function_exists('ak_plan_state_compute')) {
    /**
     * État du plan à partir d'une ligne déjà lue — LE calcul commun (fiche, listes du cockpit, appli).
     * Clés lues : org_status, org_plan, trial_ends_at (organizations) ; plan_id, slug, name, is_trial,
     * price_cents, sub_status, stripe_subscription_id (ligne courante, plan_id null s'il n'y en a pas).
     *
     * Règles, dans l'ordre :
     *   - is_paid : ligne active hors essai, payante, Sur-mesure ou Stripe. Un client payant n'est
     *     jamais « en essai », même si organizations.status est resté « trial » ;
     *   - is_trial : ligne courante sur l'essai, ou association en statut « trial » ;
     *   - trial_expired : date de fin PASSÉE (à l'instant près, comme les crons) et association en
     *     essai ou suspendue par la fin d'essai ;
     *   - trial_paused : essai suspendu à la main avant sa fin (pas « Essai terminé le <date future> ») ;
     *   - badge : Résiliée (association ou abonnement résilié) > Essai terminé > Essai suspendu >
     *     ESSAI > Suspendue > Paiement en attente / en retard > Active.
     * @return array{plan_id:?int, plan_slug:string, plan_label:string, sub_status:string, org_status:string,
     *               is_trial:bool, trial_expired:bool, trial_paused:bool, is_paid:bool, trial_ends_at:?string,
     *               days_left:?int, price_cents:int, badge:string, badge_tone:string}
     *  badge / badge_tone : texte et ton communs ('trial', 'expired', 'active', 'suspended', 'cancelled', 'pending').
     */
    function ak_plan_state_compute(array $r): array {
        $st = ['plan_id' => null, 'plan_slug' => '', 'plan_label' => '—', 'sub_status' => '', 'org_status' => '',
               'is_trial' => false, 'trial_expired' => false, 'trial_paused' => false, 'is_paid' => false,
               'trial_ends_at' => null, 'days_left' => null, 'price_cents' => 0,
               'badge' => '—', 'badge_tone' => 'gray'];
        $st['org_status'] = (string)($r['org_status'] ?? '');
        $st['trial_ends_at'] = $r['trial_ends_at'] ?? null;
        $st['plan_slug'] = (string)($r['org_plan'] ?? '');
        $row_trial = false;
        if (isset($r['plan_id']) && $r['plan_id'] !== null) {
            $st['plan_id'] = (int)$r['plan_id'];
            $st['plan_slug'] = (string)($r['slug'] ?? '');
            $st['plan_label'] = ak_plan_label($r['slug'] ?? '', $r['name'] ?? null);
            $st['sub_status'] = (string)($r['sub_status'] ?? '');
            $st['price_cents'] = (int)($r['price_cents'] ?? 0);
            $plan_trial = !empty($r['is_trial']) || $st['plan_slug'] === 'pro-essai';
            $row_trial = $plan_trial || $st['sub_status'] === 'trial';
            $st['is_paid'] = $st['sub_status'] === 'active' && !$plan_trial
                && ($st['price_cents'] > 0 || $st['plan_slug'] === 'sur-mesure' || !empty($r['stripe_subscription_id']));
        } else {
            $st['plan_label'] = ak_plan_label($st['plan_slug']);
        }
        $st['is_trial'] = !$st['is_paid'] && ($row_trial || $st['org_status'] === 'trial');
        $end = $st['trial_ends_at'] ? strtotime((string)$st['trial_ends_at']) : false;
        if ($end) $st['days_left'] = ak_trial_days_left((string)$st['trial_ends_at']);
        // Essai terminé : date passée, association en essai ou suspendue par la fin d'essai (jamais un client payant)
        if ($end && $end <= time() && !$st['is_paid'] && ($st['org_status'] === 'suspended' || $st['is_trial'])) {
            $st['trial_expired'] = true;
        }
        // Suspendue à la main PENDANT l'essai (fin encore à venir) : l'essai est en pause, pas terminé
        $st['trial_paused'] = $st['is_trial'] && !$st['trial_expired'] && $st['org_status'] === 'suspended';
        $end_fr = $end ? date('d/m/Y', $end) : '';

        if ($st['org_status'] === 'cancelled' || $st['sub_status'] === 'cancelled') {
            $st['badge'] = 'Résiliée'; $st['badge_tone'] = 'cancelled';
        } elseif ($st['trial_expired']) {
            $st['badge'] = 'Essai terminé' . ($end_fr !== '' ? ' le ' . $end_fr : '');
            $st['badge_tone'] = 'expired';
        } elseif ($st['trial_paused']) {
            $st['badge'] = 'Essai suspendu' . ($end_fr !== '' ? ' · fin prévue le ' . $end_fr : '');
            $st['badge_tone'] = 'suspended';
        } elseif ($st['is_trial']) {
            $d = $st['days_left'];
            $st['badge'] = 'ESSAI' . ($end_fr !== '' ? ' · fin ' . $end_fr : '')
                         . ($d !== null ? ' (' . ($d <= 0 ? 'dernier jour' : 'J-' . $d) . ')' : '');
            $st['badge_tone'] = 'trial';
        } elseif ($st['org_status'] === 'suspended') {
            $st['badge'] = 'Suspendue'; $st['badge_tone'] = 'suspended';
        } elseif (in_array($st['sub_status'], ['pending_payment', 'overdue'], true)) {
            $st['badge'] = $st['sub_status'] === 'overdue' ? 'Paiement en retard' : 'Paiement en attente'; $st['badge_tone'] = 'pending';
        } else {
            $st['badge'] = 'Active'; $st['badge_tone'] = 'active';
        }
        return $st;
    }
}

if (!function_exists('ak_org_plan_state')) {
    /**
     * État du plan d'UNE association (voir ak_plan_state_compute pour les clés et les règles).
     */
    function ak_org_plan_state(PDO $pdo, int $org_id): array {
        $r = [];
        try {
            $o = $pdo->prepare("SELECT status, plan, trial_ends_at FROM organizations WHERE id = ?");
            $o->execute([$org_id]);
            $org = $o->fetch(PDO::FETCH_ASSOC) ?: [];
            $r = ['org_status' => $org['status'] ?? '', 'org_plan' => $org['plan'] ?? '', 'trial_ends_at' => $org['trial_ends_at'] ?? null];
            $s = $pdo->prepare("SELECT " . ak_sub_state_cols($pdo) . "
                                FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
            $s->execute([$org_id]);
            if ($row = $s->fetch(PDO::FETCH_ASSOC)) $r = $row + $r;
        } catch (Throwable $e) {}
        return ak_plan_state_compute($r);
    }
}

if (!function_exists('ak_legacy_sub_trial')) {
    /**
     * Ancienne table subscriptions (rappels J-7 / J-3 / J-1 / J-0 et e-mail de fin d'essai) : remet en
     * essai jusqu'à $end la dernière ligne (n'importe laquelle si $any_status, sinon une ligne « trial »
     * ou « suspended »), ou en CRÉE une s'il n'y en a pas. Une association créée hors inscription
     * (fondateur, appli, ancien compte) n'en a pas : sans elle, aucun rappel ni e-mail de fin ne partait.
     */
    function ak_legacy_sub_trial(PDO $pdo, int $org_id, string $end, bool $any_status): void {
        try {
            $cols = ak_db_columns($pdo, 'subscriptions');
            if (!$cols || !ak_db_accepts($pdo, 'subscriptions', 'status', 'trial')) return;
            $find = function (bool $any) use ($pdo, $org_id): int {
                $q = $pdo->prepare("SELECT id FROM subscriptions WHERE org_id = ?" . ($any ? "" : " AND status IN ('trial', 'suspended')") . " ORDER BY id DESC LIMIT 1");
                $q->execute([$org_id]);
                return (int)$q->fetchColumn();
            };
            $id = $find($any_status);
            if ($id <= 0) {
                // rowCount() ne dit rien ici (0 aussi quand rien ne change) : l'absence de ligne est vérifiée avant
                $row = ['org_id' => $org_id, 'status' => 'trial', 'price_ht' => 0.00, 'tva_rate' => 20.00, 'current_period_end' => $end];
                if ($v = ak_db_pick($pdo, 'subscriptions', 'plan', ['pro', 'association', 'essentiel'])) $row['plan'] = $v;
                if ($v = ak_db_pick($pdo, 'subscriptions', 'billing_cycle', ['monthly'])) $row['billing_cycle'] = $v;
                try {
                    ak_db_insert($pdo, 'subscriptions', $row, ['started_at' => 'NOW()', 'current_period_start' => 'NOW()', 'created_at' => 'NOW()']);
                    return;
                } catch (PDOException $e) {
                    // Une seule ligne par association (clé unique) : on reprend la ligne existante
                    if ((string)$e->getCode() !== '23000' || ($id = $find(true)) <= 0) throw $e;
                }
            }
            $sets = "status = 'trial'" . (isset($cols['current_period_end']) ? ", current_period_end = ?" : "") . (isset($cols['updated_at']) ? ", updated_at = NOW()" : "");
            $args = isset($cols['current_period_end']) ? [$end, $id] : [$id];
            $pdo->prepare("UPDATE subscriptions SET $sets WHERE id = ?")->execute($args);
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
     * Sortie d'essai (cockpit web, appli, page fondateur) : la fin d'essai ne reste pas comme
     * « échéance » du nouveau plan, et le mode « Offert » (free_grant) posé à l'inscription passe en
     * « manuel » si le nouveau plan est payant ou sur devis : le client paiera.
     * Les données de paiement Stripe ne sont jamais effacées : un client Stripe passé sur un plan que
     * Stripe ne facture pas (gratuit, Sur-mesure…) garde son abonnement Stripe, à annuler dans Stripe
     * (ak_stripe_left_on_free le signale). Un motif de suspension n'est pas gardé quand l'association
     * repart en essai ou active.
     * organizations.plan (ancien libellé, parfois un ENUM) n'est écrit que s'il accepte la valeur.
     */
    function ak_org_set_plan(PDO $pdo, int $org_id, int $plan_id): void {
        $pcols = ak_db_columns($pdo, 'asso_plans');
        $p = $pdo->prepare("SELECT id, slug, COALESCE(price_cents, 0) AS price_cents"
                           . (isset($pcols['is_trial']) ? ", is_trial" : ", 0 AS is_trial")
                           . (isset($pcols['is_custom_quote']) ? ", COALESCE(is_custom_quote, 0) AS is_custom_quote" : ", 0 AS is_custom_quote")
                           . " FROM asso_plans WHERE id = ?");
        $p->execute([$plan_id]);
        $plan = $p->fetch(PDO::FETCH_ASSOC);
        if (!$plan) throw new InvalidArgumentException('Plan inconnu.');
        $is_trial = !empty($plan['is_trial']) || $plan['slug'] === 'pro-essai';
        $paid_plan = !$is_trial && ((int)$plan['price_cents'] > 0 || $plan['slug'] === 'sur-mesure' || !empty($plan['is_custom_quote']));
        $before = ak_org_plan_state($pdo, $org_id);
        $leaving_trial = !$is_trial && ($before['is_trial'] || $before['trial_expired']);

        $cur = $pdo->prepare("SELECT s.id, s.plan_id FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                              WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
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
            if (isset($cols['current_period_end'])) {
                if ($is_trial) { $sets[] = 'current_period_end = ?'; $args[] = $end; }
                elseif ($leaving_trial) $sets[] = 'current_period_end = NULL';   // la fin d'essai n'est pas une échéance
            }
            if ($leaving_trial && $paid_plan && isset($cols['payment_mode']) && ak_db_accepts($pdo, 'asso_subscriptions', 'payment_mode', 'manual')) {
                $sets[] = "payment_mode = IF(payment_mode = 'free_grant', 'manual', payment_mode)";
            }
            foreach (['grace_period_end', 'downgraded_at', 'cancelled_at'] as $c) if (isset($cols[$c])) $sets[] = "$c = NULL";
            if (isset($cols['updated_at'])) $sets[] = 'updated_at = NOW()';
            $args[] = (int)$row['id'];
            $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        } else {
            ak_db_insert($pdo, 'asso_subscriptions', ['org_id' => $org_id, 'plan_id' => $plan_id, 'status' => $status,
                'current_period_end' => $is_trial ? $end : null], ['started_at' => 'NOW()', 'created_at' => 'NOW()', 'updated_at' => 'NOW()']);
        }

        $set_label = ak_db_accepts($pdo, 'organizations', 'plan', (string)$plan['slug']);
        if ($is_trial) {
            // Remise en essai : un ancien motif de suspension ne reste pas (cron-trial-check le lirait
            // comme « suspendue à la main » à la fin de l'essai, sans e-mail de fin)
            ak_db_update($pdo, 'organizations', $org_id, ['trial_ends_at' => $end]
                + ($set_label ? ['plan' => $plan['slug']] : [])
                + (ak_db_accepts($pdo, 'organizations', 'status', 'trial') ? ['status' => 'trial', 'suspended_reason' => null] : []));
            ak_legacy_sub_trial($pdo, $org_id, $end, true);
        } else {
            // Fin d'essai : l'association repasse active (une suspension manuelle sans essai n'est pas touchée),
            // sans motif de suspension. MySQL lit les SET de gauche à droite : le motif est effacé d'après
            // le statut et la fin d'essai AVANT leur mise à jour.
            $unsuspend = "status = 'trial' OR (status = 'suspended' AND trial_ends_at IS NOT NULL)";
            $reason = isset(ak_db_columns($pdo, 'organizations')['suspended_reason']) ? "suspended_reason = IF($unsuspend, NULL, suspended_reason), " : "";
            $pdo->prepare("UPDATE organizations SET " . ($set_label ? "plan = ?, " : "") . $reason . "status = IF($unsuspend, 'active', status), trial_ends_at = NULL WHERE id = ?")
                ->execute($set_label ? [$plan['slug'], $org_id] : [$org_id]);
            try { $pdo->prepare("UPDATE subscriptions SET status = 'active' WHERE org_id = ? AND status IN ('trial', 'suspended')")->execute([$org_id]); } catch (Throwable $e) {}
        }
    }
}

if (!function_exists('ak_trial_extend')) {
    /**
     * Prolonge (ou rouvre) l'essai de N jours à partir de la fin actuelle si elle est future,
     * sinon d'aujourd'hui. Met à jour tous les endroits qui portent l'essai :
     *   - organizations : fin d'essai, statut « trial », ancien libellé plan (s'il accepte la valeur) ;
     *   - ancienne table subscriptions (rappels) : ligne remise en essai, ou créée ;
     *   - ligne d'abonnement courante remise sur le plan d'essai, qu'elle ait été rétrogradée en
     *     Démarrage par la fin d'essai (avec ou sans previous_plan_id) ou non. Jamais un abonnement
     *     payant (les appelants ne prolongent qu'un essai en cours ou terminé), ni un paiement Stripe
     *     en attente ou refusé : celui-ci reste tel quel et l'essai a sa propre ligne ; si le schéma
     *     n'admet qu'une ligne par association, AkTrialBlocked (rien n'est écrit).
     * Une erreur SQL remonte à l'appelant (transaction) : jamais d'essai à moitié rouvert.
     * @return string nouvelle date de fin (SQL)
     */
    function ak_trial_extend(PDO $pdo, int $org_id, int $days): string {
        $days = max(1, min(90, $days));
        $end = (string)$pdo->query("SELECT DATE_ADD(GREATEST(NOW(), COALESCE((SELECT trial_ends_at FROM organizations WHERE id = " . (int)$org_id . "), NOW())), INTERVAL " . $days . " DAY)")->fetchColumn();
        $trial = ak_trial_plan($pdo);
        $cols = ak_db_columns($pdo, 'asso_subscriptions');
        $r = $pdo->prepare("SELECT s.id, s.plan_id, s.status, "
                           . (isset($cols['stripe_subscription_id']) ? "s.stripe_subscription_id" : "NULL") . " AS stripe_subscription_id,
                                   (" . ak_paid_sub_sql($pdo) . ") AS is_paid
                            FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                            WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
        $r->execute([$org_id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        $status = ak_db_pick($pdo, 'asso_subscriptions', 'status', ['trial', 'active']) ?? 'trial';
        $sub_row = ['org_id' => $org_id, 'plan_id' => $trial['id'] ?? 0, 'status' => $status, 'current_period_end' => $end];
        $sub_raw = ['started_at' => 'NOW()', 'created_at' => 'NOW()', 'updated_at' => 'NOW()'];

        // Paiement Stripe en attente ou refusé sur un plan payant (ligne créée au clic sur « Payer ») :
        // l'abonnement vit encore dans Stripe. Transformé en ligne d'essai, il gardait son identifiant
        // Stripe, et le paiement qui suivait était ignoré (plan d'essai) : le client restait en essai,
        // tombait en Démarrage à la fin, et Stripe prélevait quand même. La ligne Stripe reste telle
        // quelle ; l'essai rouvert a sa propre ligne (prioritaire, ak_sub_order), et le paiement, s'il
        // arrive, met fin à l'essai normalement (stripe-webhook, ak_end_trial_after_payment).
        $stripe_pending = $row && empty($row['is_paid']) && trim((string)$row['stripe_subscription_id']) !== ''
            && in_array((string)$row['status'], ['pending_payment', 'overdue'], true)
            && !($trial && (int)$row['plan_id'] === $trial['id']);
        if ($stripe_pending) {
            $blocked = 'Abonnement Stripe ' . $row['stripe_subscription_id'] . ' en attente de paiement : régularisez-le ou annulez-le dans Stripe avant de prolonger l’essai.';
            if (!$trial) throw new AkTrialBlocked($blocked);
            try {
                ak_db_insert($pdo, 'asso_subscriptions', $sub_row, $sub_raw);
            } catch (PDOException $e) {
                // Une seule ligne par association (clé unique) : impossible sans toucher la ligne Stripe
                if ((string)$e->getCode() !== '23000') throw $e;
                throw new AkTrialBlocked($blocked);
            }
        }

        $upd = ['trial_ends_at' => $end];
        // Remise en essai : un ancien motif de suspension ne reste pas (sinon, à la fin de l'essai,
        // cron-trial-check la croirait « suspendue à la main » et n'enverrait pas l'e-mail de fin)
        if (ak_db_accepts($pdo, 'organizations', 'status', 'trial')) { $upd['status'] = 'trial'; $upd['suspended_reason'] = null; }
        if ($trial && ak_db_accepts($pdo, 'organizations', 'plan', $trial['slug'])) $upd['plan'] = $trial['slug'];
        ak_db_update($pdo, 'organizations', $org_id, $upd);
        ak_legacy_sub_trial($pdo, $org_id, $end, false);

        if ($stripe_pending) return $end;
        if ($row && empty($row['is_paid'])) {
            $sets = []; $args = [];
            if ($trial && (int)$row['plan_id'] !== $trial['id']) {
                // MySQL lit les SET de gauche à droite : previous_plan_id reçoit l'ancien plan
                if (isset($cols['previous_plan_id'])) $sets[] = 'previous_plan_id = plan_id';
                $sets[] = 'plan_id = ?'; $args[] = $trial['id'];
            }
            $sets[] = 'status = ?'; $args[] = $status;
            if (isset($cols['current_period_end'])) { $sets[] = 'current_period_end = ?'; $args[] = $end; }
            foreach (['downgraded_at', 'grace_period_end', 'cancelled_at'] as $c) if (isset($cols[$c])) $sets[] = "$c = NULL";
            if (isset($cols['updated_at'])) $sets[] = 'updated_at = NOW()';
            $args[] = (int)$row['id'];
            $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        } elseif (!$row && $trial) {
            ak_db_insert($pdo, 'asso_subscriptions', $sub_row, $sub_raw);
        }
        return $end;
    }
}

if (!function_exists('ak_org_sync_plan_label')) {
    /** Ancien libellé organizations.plan recalé sur le plan réel (ligne courante), s'il accepte la valeur. */
    function ak_org_sync_plan_label(PDO $pdo, int $org_id): void {
        try {
            $st = ak_org_plan_state($pdo, $org_id);
            $slug = (string)$st['plan_slug'];
            if ($st['plan_id'] === null || $slug === '' || !ak_db_accepts($pdo, 'organizations', 'plan', $slug)) return;
            $pdo->prepare("UPDATE organizations SET plan = ? WHERE id = ? AND (plan IS NULL OR plan <> ?)")->execute([$slug, $org_id, $slug]);
        } catch (Throwable $e) {
            error_log('[ak_org_sync_plan_label] ' . get_class($e));
        }
    }
}

if (!function_exists('ak_essai_vers_gratuit')) {
    /**
     * Fin d'essai : les lignes d'ESSAI passent au plan gratuit « demarrage », actives. Ligne d'essai =
     * statut « trial », ou plan d'essai encore actif (ENUM sans « trial ») ou annulé à tort (ancien
     * bouton « Passer au gratuit »). previous_plan_id garde le plan d'essai (« Prolonger » le rouvre).
     * Les données sont conservées. Une association avec un abonnement payant en cours n'est jamais touchée.
     * @return int lignes rétrogradées
     */
    function ak_essai_vers_gratuit(PDO $pdo, int $org_id): int {
        try {
            $paye = $pdo->prepare("SELECT 1 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                   WHERE s.org_id = ? AND " . ak_paid_sub_sql($pdo) . " LIMIT 1");
            $paye->execute([$org_id]);
            if ($paye->fetchColumn()) return 0;
            $free = (int)$pdo->query("SELECT id FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
            if ($free <= 0) return 0;
            $q = $pdo->prepare("SELECT s.id FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                WHERE s.org_id = ? AND (s.status = 'trial' OR ((" . ak_plan_trial_sql($pdo) . ") AND s.status IN ('active', 'cancelled')))");
            $q->execute([$org_id]);
            $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) return 0;
            $cols = ak_db_columns($pdo, 'asso_subscriptions');
            // MySQL évalue SET de gauche à droite (UPDATE d'une seule table) : previous_plan_id lit l'ancien plan_id
            $sets = [];
            if (isset($cols['previous_plan_id'])) $sets[] = 'previous_plan_id = plan_id';
            $sets[] = 'plan_id = ?';
            $sets[] = "status = 'active'";
            if (isset($cols['current_period_end'])) $sets[] = 'current_period_end = NULL';   // la fin d'essai n'est pas une échéance
            foreach (['grace_period_end', 'cancelled_at'] as $c) if (isset($cols[$c])) $sets[] = "$c = NULL";
            if (isset($cols['downgraded_at']))    $sets[] = 'downgraded_at = NOW()';
            if (isset($cols['updated_at']))       $sets[] = 'updated_at = NOW()';
            $st = $pdo->prepare("UPDATE asso_subscriptions SET " . implode(', ', $sets) . " WHERE id IN (" . implode(',', $ids) . ")");
            $st->execute([$free]);
            return $st->rowCount();
        } catch (Throwable $e) {
            error_log('[ak_essai_vers_gratuit] org #' . $org_id . ' : ' . get_class($e));
            return 0;
        }
    }
}

if (!function_exists('ak_trial_expired_org_ids')) {
    /**
     * Associations dont l'essai est terminé et pas encore traité par ak_trial_expire() : fin d'essai
     * passée (trial_ends_at < NOW()), et association encore « trial » ou ligne d'essai encore là, quel
     * que soit le statut de l'association (suspendue ou réactivée à la main, ligne annulée à tort).
     * Ni client payant, ni association supprimée, ni démo. Une association traitée n'y figure plus.
     * @return int[]
     */
    function ak_trial_expired_org_ids(PDO $pdo, int $limit = 500): array {
        $st = $pdo->query("
            SELECT o.id FROM organizations o
            WHERE o.trial_ends_at IS NOT NULL AND o.trial_ends_at < NOW()
              AND o.deleted_at IS NULL
              AND (o.slug IS NULL OR o.slug NOT LIKE 'demo-%')
              AND (o.status = 'trial' OR EXISTS (
                    SELECT 1 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                    WHERE s.org_id = o.id
                      AND (s.status = 'trial' OR ((" . ak_plan_trial_sql($pdo) . ") AND s.status IN ('active', 'cancelled')))))
              AND NOT EXISTS (SELECT 1 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                              WHERE s.org_id = o.id AND " . ak_paid_sub_sql($pdo) . ")
            ORDER BY o.trial_ends_at ASC
            LIMIT " . max(1, (int)$limit));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('ak_trial_expire')) {
    /**
     * Fin d'essai d'UNE association dont la date de fin est passée (cron-trial-check toutes les
     * 10 minutes, cron-job-essai en rattrapage) : statut « trial » → « suspended » (une association
     * suspendue, réactivée ou résiliée à la main garde son statut), lignes d'essai → Démarrage,
     * ancien libellé organizations.plan recalé. Idempotent.
     * Jamais touchées : client payant, association supprimée, espace de démonstration (demo-*),
     * essai dont la date n'est pas passée.
     * @return bool l'association remplissait les conditions et a été traitée
     */
    function ak_trial_expire(PDO $pdo, int $org_id): bool {
        $q = $pdo->prepare("SELECT o.id FROM organizations o
                            WHERE o.id = ? AND o.trial_ends_at IS NOT NULL AND o.trial_ends_at < NOW()
                              AND o.deleted_at IS NULL AND (o.slug IS NULL OR o.slug NOT LIKE 'demo-%')
                              AND NOT EXISTS (SELECT 1 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                              WHERE s.org_id = o.id AND " . ak_paid_sub_sql($pdo) . ")");
        $q->execute([$org_id]);
        if (!$q->fetchColumn()) return false;
        // Une association encore « trial » n'a pas été suspendue à la main : un ancien motif de suspension
        // resté là ne doit pas faire croire à cron-trial-check que c'est le cas (pas d'e-mail de fin)
        $reason = isset(ak_db_columns($pdo, 'organizations')['suspended_reason']) ? ", suspended_reason = NULL" : "";
        $pdo->prepare("UPDATE organizations SET status = 'suspended'$reason WHERE id = ? AND status = 'trial'")->execute([$org_id]);
        if (ak_essai_vers_gratuit($pdo, $org_id) > 0) ak_org_sync_plan_label($pdo, $org_id);
        return true;
    }
}

if (!function_exists('ak_org_suspend')) {
    /**
     * Suspension à la main (cockpit web, appli fondateur ; refus d'une association). Suspendue
     * pendant son essai : l'ancienne table passe aussi en « suspended », sinon les rappels
     * J-7 / J-3 / J-0 et l'e-mail de fin d'essai partiraient encore. « Réactiver » rouvre l'essai
     * s'il reste des jours (ak_org_reactivate). suspended_reason distingue aussi une suspension
     * à la main d'une fin d'essai (cron-trial-check).
     */
    function ak_org_suspend(PDO $pdo, int $org_id, string $reason): void {
        $before = ak_org_plan_state($pdo, $org_id);
        ak_db_update($pdo, 'organizations', $org_id, ['status' => 'suspended', 'suspended_reason' => $reason !== '' ? $reason : 'Suspendue par le Fondateur']);
        if ($before['is_trial'] && ak_db_accepts($pdo, 'subscriptions', 'status', 'suspended')) {
            try {
                $pdo->prepare("UPDATE subscriptions SET status = 'suspended' WHERE org_id = ? AND status = 'trial'")->execute([$org_id]);
            } catch (Throwable $e) {
                error_log('[ak_org_suspend] subscriptions: ' . get_class($e));
            }
        }
    }
}

if (!function_exists('ak_org_reactivate')) {
    /**
     * « Réactiver » une association suspendue — même règle sur le web et dans l'appli :
     *   - essai dont la date est déjà passée : il se termine ici (ak_trial_expire, lignes d'essai →
     *     Démarrage, comme le cron), puis l'association repart « active » sur le plan gratuit.
     *     La remettre « trial » laissait le cron la re-suspendre dans les 10 minutes : la
     *     réactivation était défaite sans bruit. $trial_ended reçoit alors la date de fin d'essai ;
     *   - essai en cours → statut « trial », l'ancienne table rouvre l'essai jusqu'à sa fin ;
     *   - sinon (client, plan gratuit) → « active ».
     * @return string statut écrit ('trial' ou 'active')
     */
    function ak_org_reactivate(PDO $pdo, int $org_id, ?string &$trial_ended = null): string {
        $trial_ended = null;
        $before = ak_org_plan_state($pdo, $org_id);
        if (($before['is_trial'] || $before['trial_expired']) && ak_trial_expire($pdo, $org_id)) {
            ak_db_update($pdo, 'organizations', $org_id, ['status' => 'active', 'suspended_reason' => null]);
            $trial_ended = (string)$before['trial_ends_at'];
            return 'active';
        }
        $ocols = ak_db_columns($pdo, 'organizations');
        $status = $before['is_trial'] && (!$ocols || ak_db_accepts($pdo, 'organizations', 'status', 'trial')) ? 'trial' : 'active';
        ak_db_update($pdo, 'organizations', $org_id, ['status' => $status, 'suspended_reason' => null]);
        $end = $before['trial_ends_at'] ? strtotime((string)$before['trial_ends_at']) : false;
        if ($status === 'trial' && $end && $end > time()) ak_legacy_sub_trial($pdo, $org_id, date('Y-m-d H:i:s', $end), false);
        return $status;
    }
}

if (!function_exists('ak_org_reject')) {
    /** Refus d'une association par le Fondateur (web « Refuser », appli « reject ») : refusée ET suspendue. */
    function ak_org_reject(PDO $pdo, int $org_id, string $reason, int $by_user_id): void {
        $reason = $reason !== '' ? $reason : 'Refusé par le Fondateur';
        ak_db_update($pdo, 'organizations', $org_id,
            ['validation_status' => 'rejected', 'rejection_reason' => $reason, 'validated_by_user_id' => $by_user_id > 0 ? $by_user_id : null],
            ['validated_at' => 'NOW()']);
        ak_org_suspend($pdo, $org_id, $reason);
    }
}

if (!function_exists('ak_stripe_left_on_free')) {
    /**
     * Abonnement Stripe encore actif sur un plan que Stripe ne facture pas (ak_plan_off_stripe_sql) :
     * client Stripe passé à la main sur Démarrage, l'essai, Sur-mesure ou un plan sans prix Stripe.
     * Renvoie l'identifiant de l'abonnement Stripe, sinon null. Stripe continue de prélever l'ancienne
     * formule tant qu'il n'est pas annulé DANS Stripe : le cockpit, l'appli et la page de l'association
     * doivent le signaler. $free : true si la formule actuelle est gratuite (ou l'essai), false si elle
     * est payante mais facturée à part (Sur-mesure, sur devis…).
     */
    function ak_stripe_left_on_free(PDO $pdo, int $org_id, ?bool &$free = null): ?string {
        $free = null;
        $cols = ak_db_columns($pdo, 'asso_subscriptions');
        if (!isset($cols['stripe_subscription_id'])) return null;
        $pcols = ak_db_columns($pdo, 'asso_plans');
        try {
            $custom = isset($pcols['is_custom_quote']) ? " AND COALESCE(p.is_custom_quote, 0) = 0" : "";
            $st = $pdo->prepare("SELECT s.stripe_subscription_id, s.status, " . (isset($cols['payment_mode']) ? "s.payment_mode" : "'stripe'") . " AS payment_mode,
                                        (" . ak_plan_off_stripe_sql($pdo) . ") AS off_stripe,
                                        ((" . ak_plan_trial_sql($pdo) . ") OR (COALESCE(p.price_cents, 0) <= 0 AND p.slug <> 'sur-mesure'$custom)) AS plan_free
                                 FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                 WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
            $st->execute([$org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || trim((string)$row['stripe_subscription_id']) === '' || $row['payment_mode'] !== 'stripe'
                || $row['status'] === 'cancelled' || empty($row['off_stripe'])) return null;
            $free = !empty($row['plan_free']);
            return (string)$row['stripe_subscription_id'];
        } catch (Throwable $e) {
            return null;
        }
    }
}
