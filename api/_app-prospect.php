<?php
/**
 * api/_app-prospect.php — Socle du moteur de prospection CONFORME (RGPD).
 * Modèle de données, jetons de tracking, séquences de relance, liens personnalisés.
 * Principes de conformité intégrés :
 *   - lien de désinscription obligatoire dans chaque email,
 *   - respect du statut 'unsubscribed' (plus jamais recontacté),
 *   - montée en charge progressive (warm-up) + plafond quotidien,
 *   - envoi réellement actif UNIQUEMENT si AK_PROSPECT_SENDING_ENABLED = true.
 * App-only. NE MODIFIE PAS le site.
 */

// Envoi réel désactivé par défaut : à activer volontairement APRÈS mise en place
// d'un domaine d'envoi dédié + warm-up. Tant que false, le cron tourne en DRY-RUN (aucun email).
if (!defined('AK_PROSPECT_SENDING_ENABLED')) define('AK_PROSPECT_SENDING_ENABLED', false);
if (!defined('AK_PROSPECT_DAILY_CAP'))       define('AK_PROSPECT_DAILY_CAP', 40);   // plafond/jour (warm-up : monter doucement)
if (!defined('AK_PROSPECT_DOMAIN'))          define('AK_PROSPECT_DOMAIN', 'assokit.fr');       // domaine des liens (site : /p/ , /desinscription/)
// Adresse d'expédition DÉDIÉE à la prospection (sous-domaine isolé, pour protéger
// la réputation du domaine principal). À configurer après validation du sous-domaine
// dans Resend + DNS. La réponse revient sur l'inbox principale (reply_to ci-dessous).
if (!defined('AK_PROSPECT_FROM'))            define('AK_PROSPECT_FROM', 'contact@send.assokit.fr');
if (!defined('AK_PROSPECT_FROM_NAME'))       define('AK_PROSPECT_FROM_NAME', 'Raphael · Assokit');
if (!defined('AK_PROSPECT_REPLY_TO'))        define('AK_PROSPECT_REPLY_TO', 'contact@assokit.fr');

/** Séquence : étape => délai (jours) avant la relance suivante. 5 contacts max, ~2-3 semaines d'écart. */
if (!function_exists('ak_prospect_sequence')) {
    function ak_prospect_sequence(): array {
        return [
            0 => ['delay' => 14, 'label' => 'Premier contact'],
            1 => ['delay' => 18, 'label' => 'Relance 1'],
            2 => ['delay' => 21, 'label' => 'Relance 2'],
            3 => ['delay' => 25, 'label' => 'Relance 3'],
            4 => ['delay' => 0,  'label' => 'Dernier contact'],
        ];
    }
}

/**
 * Angle de personnalisation « rentrée » selon la catégorie d'association.
 * Renvoie ['label' => .., 'hook' => .., 'pain' => ..] pour nourrir l'email.
 */
if (!function_exists('ak_prospect_angle')) {
    function ak_prospect_angle(?string $category): array {
        $map = [
            'sport'         => ['label' => 'club sportif', 'hook' => 'la reprise sportive et les inscriptions de septembre', 'pain' => 'les licences, les cotisations et les plannings d\'entraînement'],
            'culture'       => ['label' => 'association culturelle', 'hook' => 'la reprise des ateliers et la nouvelle saison', 'pain' => 'les inscriptions aux cours et le suivi des adhérents'],
            'social'        => ['label' => 'association', 'hook' => 'la reprise des activités et l\'accueil des bénévoles', 'pain' => 'le suivi des adhérents, des bénévoles et des actions'],
            'sante'         => ['label' => 'association', 'hook' => 'la reprise d\'activité de rentrée', 'pain' => 'le suivi des adhérents et la gestion administrative'],
            'education'     => ['label' => 'association', 'hook' => 'la rentrée et la vague d\'inscriptions', 'pain' => 'les inscriptions, les cotisations et la communication aux familles'],
            'environnement' => ['label' => 'association', 'hook' => 'la reprise des projets de rentrée', 'pain' => 'la coordination des bénévoles et le suivi des projets'],
            'loisirs'       => ['label' => 'association', 'hook' => 'la reprise des activités de septembre', 'pain' => 'les inscriptions et les cotisations des adhérents'],
            'citoyennete'   => ['label' => 'association', 'hook' => 'la reprise de rentrée', 'pain' => 'le suivi des adhérents et l\'organisation des actions'],
            'culte'         => ['label' => 'association', 'hook' => 'la reprise de rentrée', 'pain' => 'le suivi des membres et des dons'],
            'professionnel' => ['label' => 'structure', 'hook' => 'la rentrée', 'pain' => 'le suivi des membres et la comptabilité'],
        ];
        $c = strtolower(trim((string) $category));
        return $map[$c] ?? ['label' => 'association', 'hook' => 'la rentrée et les inscriptions de septembre', 'pain' => 'les adhérents, les cotisations et la comptabilité'];
    }
}

/**
 * Construit le sujet + corps HTML d'un email de prospection (rentrée, personnalisé
 * catégorie/ville). Utilisé par le cron ET la page fondateur web (envoi manuel).
 * Personnalisation IA Claude si le helper est présent, sinon gabarit professionnel.
 */
if (!function_exists('ak_prospect_build_email')) {
    function ak_prospect_build_email(array $p, int $step, array $seq): array {
        $type = ($p['type'] ?? 'asso') === 'tpe' ? 'tpe' : 'asso';
        $who  = $type === 'tpe' ? 'votre entreprise' : 'votre association';
        $name = trim((string) ($p['name'] ?? ''));
        $org  = trim((string) ($p['org_name'] ?? ''));
        $city = trim((string) ($p['city'] ?? ''));
        $angle = ak_prospect_angle($p['category'] ?? null);
        $orgBit = $org !== '' ? $org : ('votre ' . $angle['label']);
        $cityBit = $city !== '' ? " à $city" : '';
        $hello = $name !== '' ? "Bonjour $name," : "Bonjour,";
        $link  = ak_prospect_link((int) $p['id'], (string) $p['email']);
        $unsub = ak_prospect_unsub_link((int) $p['id'], (string) $p['email']);

        $body_txt = null;
        if (class_exists('ClaudeAPI') && method_exists('ClaudeAPI', 'callMessages')) {
            try {
                $sys = "Tu écris un email de prospection B2B court, humain et chaleureux en français pour Assokit, "
                     . "logiciel tout-en-un pour " . ($type === 'tpe' ? 'TPE/PME et indépendants' : 'associations loi 1901') . ". "
                     . "CONTEXTE SAISONNIER : c'est la RENTRÉE (septembre), le pic d'activité. "
                     . "3-5 phrases max, ton respectueux, pas de superlatifs racoleurs, une seule idée + un appel à l'action doux. "
                     . "Ne mets NI objet, NI formule d'ouverture, NI signature : juste le corps.";
                $u = "Étape : " . ($seq[$step]['label'] ?? 'contact') . ". Destinataire : " . $orgBit . $cityBit . ". "
                   . "Angle rentrée : " . $angle['hook'] . ". Douleur à soulager : " . $angle['pain'] . ".";
                $body_txt = trim((string) ClaudeAPI::callMessages($sys, $u, 400));
            } catch (Throwable $e) { $body_txt = null; }
        }
        if (!$body_txt) {
            $templates = [
                0 => "À l'approche de " . $angle['hook'] . ", je me permets de contacter " . $orgBit . $cityBit . " : c'est souvent la période la plus chargée pour " . $angle['pain'] . ". Assokit réunit tout ça dans un seul outil simple, pour aborder la rentrée sans paperasse. J'ai pensé que ça pourrait vous faire gagner un temps précieux ce mois-ci.",
                1 => "Je reviens vers vous : en pleine rentrée, beaucoup de structures comme la vôtre nous disent gagner plusieurs heures par semaine sur " . $angle['pain'] . " grâce à Assokit. Cela vaut peut-être un coup d'œil avant que le rush ne s'installe ?",
                2 => "Petit rappel amical — si simplifier " . $angle['pain'] . " pour cette rentrée vous parle, je serais ravi de vous montrer concrètement Assokit en 20 minutes, adapté à " . $orgBit . ".",
                3 => "Je ne voudrais pas vous déranger inutilement en cette période chargée. Si ce n'est pas le bon moment, dites-le moi simplement. Sinon, la page ci-dessous résume tout en 2 minutes.",
                4 => "Dernier message de ma part : je vous laisse la page de présentation, à consulter quand vous voulez. Et si la gestion de " . $who . " devient un casse-tête cette saison, vous saurez où nous trouver !",
            ];
            $body_txt = $templates[$step] ?? $templates[0];
        }

        $subjects = [
            0 => "Une rentrée plus simple pour " . $orgBit,
            1 => "Re: gagner du temps pour la rentrée",
            2 => "20 min pour alléger votre rentrée ?",
            3 => "Est-ce le bon moment ?",
            4 => "Je vous laisse ça pour la rentrée",
        ];
        $subject = $subjects[$step] ?? "Assokit — " . $who;

        $html = "<div style=\"font-family:system-ui,Arial,sans-serif;max-width:560px;margin:0 auto;color:#0F172A;line-height:1.6;font-size:15px;\">"
            . "<p>" . htmlspecialchars($hello) . "</p>"
            . "<p>" . nl2br(htmlspecialchars($body_txt)) . "</p>"
            . "<p><a href=\"" . htmlspecialchars($link) . "\" style=\"display:inline-block;background:#059669;color:#fff;text-decoration:none;padding:11px 20px;border-radius:9px;font-weight:600;\">Découvrir Assokit en 2 minutes</a></p>"
            . "<p style=\"margin-top:18px;\">Bien à vous,<br>Raphael · Assokit<br><a href=\"https://assokit.fr\" style=\"color:#059669;\">assokit.fr</a></p>"
            . "<hr style=\"border:none;border-top:1px solid #E2E8F0;margin:20px 0;\">"
            . "<p style=\"font-size:11px;color:#94A3B8;\">Vous recevez cet email professionnel car votre structure pourrait être concernée. "
            . "Pour ne plus être contacté·e : <a href=\"" . htmlspecialchars($unsub) . "\" style=\"color:#94A3B8;\">se désinscrire</a>.</p>"
            . "</div>";
        return ['subject' => $subject, 'html' => $html];
    }
}

if (!function_exists('ak_prospect_secret')) {
    function ak_prospect_secret(): string {
        if (defined('AK_CONTACT_SECRET') && AK_CONTACT_SECRET) return (string) AK_CONTACT_SECRET;
        if (defined('RESEND_API_KEY') && RESEND_API_KEY) return hash('sha256', 'akpro|' . RESEND_API_KEY);
        return 'ak-prospect-fallback-secret';
    }
}

if (!function_exists('ak_prospect_token')) {
    function ak_prospect_token(int $id, string $email): string {
        return substr(hash_hmac('sha256', 'p' . $id . '|' . strtolower(trim($email)), ak_prospect_secret()), 0, 20);
    }
}

if (!function_exists('ak_prospect_link')) {
    /** Lien personnalisé de tracking → redirige vers la landing selon le type, en loggant le clic. */
    function ak_prospect_link(int $id, string $email): string {
        return 'https://' . AK_PROSPECT_DOMAIN . '/p/' . $id . '.' . ak_prospect_token($id, $email);
    }
}
if (!function_exists('ak_prospect_unsub_link')) {
    function ak_prospect_unsub_link(int $id, string $email): string {
        return 'https://' . AK_PROSPECT_DOMAIN . '/desinscription/' . $id . '.' . ak_prospect_token($id, $email);
    }
}
if (!function_exists('ak_prospect_landing_path')) {
    function ak_prospect_landing_path(string $type): string {
        return $type === 'tpe' ? '/pour-tpe' : '/pour-associations';
    }
}

if (!function_exists('ak_prospect_tables_ensure')) {
    function ak_prospect_tables_ensure(PDO $pdo): void {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS asso_prospects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) DEFAULT NULL,
                org_name VARCHAR(200) DEFAULT NULL,
                type VARCHAR(8) NOT NULL DEFAULT 'asso',
                email VARCHAR(255) NOT NULL,
                city VARCHAR(120) DEFAULT NULL,
                source VARCHAR(80) DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'new',
                step TINYINT NOT NULL DEFAULT 0,
                consent_basis VARCHAR(40) DEFAULT 'b2b_legitimate_interest',
                next_send_at DATETIME DEFAULT NULL,
                last_sent_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_email (email),
                INDEX idx_status (status),
                INDEX idx_next (next_send_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
        // Migrations douces : colonnes de personnalisation / localisation.
        $addCol = function (string $col, string $ddl) use ($pdo) {
            try {
                $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_prospects' AND COLUMN_NAME = ?");
                $has->execute([$col]);
                if (!(int) $has->fetchColumn()) $pdo->exec("ALTER TABLE asso_prospects ADD COLUMN $ddl");
            } catch (Throwable $e) {}
        };
        $addCol('category', "category VARCHAR(40) DEFAULT NULL AFTER type");
        $addCol('dept_code', "dept_code VARCHAR(3) DEFAULT NULL AFTER city");
        $addCol('dept_name', "dept_name VARCHAR(80) DEFAULT NULL AFTER dept_code");
        $addCol('region', "region VARCHAR(80) DEFAULT NULL AFTER dept_name");
        $addCol('enriched_at', "enriched_at DATETIME DEFAULT NULL");
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS asso_prospect_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                prospect_id INT NOT NULL,
                type VARCHAR(16) NOT NULL,
                step TINYINT DEFAULT NULL,
                meta VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_prospect (prospect_id),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('ak_prospect_event')) {
    function ak_prospect_event(PDO $pdo, int $pid, string $type, ?int $step = null, ?string $meta = null): void {
        try {
            ak_prospect_tables_ensure($pdo);
            $pdo->prepare("INSERT INTO asso_prospect_events (prospect_id, type, step, meta, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$pid, $type, $step, $meta !== null ? mb_substr($meta, 0, 255) : null]);
        } catch (Throwable $e) {}
    }
}

if (!function_exists('ak_prospect_parse')) {
    /** Extrait [id, token] d'un segment "123.abcdef..." ; vérifie le jeton contre l'email en base. */
    function ak_prospect_parse(PDO $pdo, string $seg): ?array {
        if (!preg_match('/^(\d+)\.([a-f0-9]{12,40})$/i', trim($seg), $m)) return null;
        $id = (int) $m[1]; $tok = strtolower($m[2]);
        try {
            $st = $pdo->prepare("SELECT id, email, type FROM asso_prospects WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return null;
            if (!hash_equals(ak_prospect_token($id, (string) $p['email']), $tok)) return null;
            return $p;
        } catch (Throwable $e) { return null; }
    }
}
