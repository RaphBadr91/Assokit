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
 * Gabarit HTML premium d'email (compatible clients mail : tables + styles inline).
 * Header dégradé émeraude, titre accrocheur, bouton 3D en relief, bande de bénéfices.
 */
if (!function_exists('ak_prospect_html_template')) {
    function ak_prospect_html_template(array $d): string {
        $hello = htmlspecialchars((string) ($d['hello'] ?? 'Bonjour,'));
        $head  = htmlspecialchars((string) ($d['headline'] ?? 'La rentrée, simplifiée.'));
        $body  = nl2br(htmlspecialchars((string) ($d['body'] ?? '')));
        $link  = htmlspecialchars((string) ($d['link'] ?? 'https://assokit.fr'));
        $unsub = htmlspecialchars((string) ($d['unsub'] ?? 'https://assokit.fr'));
        $type  = ($d['type'] ?? 'asso') === 'tpe' ? 'tpe' : 'asso';
        $offer = !array_key_exists('offer', $d) || !empty($d['offer']);   // offre 1 mois par défaut
        $cta   = htmlspecialchars((string) ($d['cta'] ?? ($offer ? 'Activer mon mois gratuit' : 'Découvrir Assokit en 2 min')));
        $pre   = htmlspecialchars(($offer ? '🎁 1 mois offert, sans engagement — ' : '') . mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($d['body'] ?? '')))), 0, 90));

        $benefits = $type === 'tpe'
            ? ['Devis &amp; factures créés en 2 minutes', 'Trésorerie suivie, relances automatiques', 'Application mobile incluse']
            : ['Adhérents &amp; cotisations centralisés', 'Comptabilité et factures simplifiées', 'Application mobile incluse'];

        $benefitRows = '';
        foreach ($benefits as $b) {
            $benefitRows .= '<tr>'
                . '<td valign="top" style="padding:7px 12px 7px 0;width:28px;">'
                . '<div style="width:24px;height:24px;border-radius:50%;background:#D1FAE5;color:#047857;font-weight:800;font-size:14px;line-height:24px;text-align:center;font-family:Arial,sans-serif;">&#10003;</div>'
                . '</td>'
                . '<td valign="middle" style="padding:7px 0;font-family:Arial,Helvetica,sans-serif;font-size:14.5px;color:#334155;font-weight:500;">' . $b . '</td>'
                . '</tr>';
        }

        // ---- Bloc offre 1 mois gratuit (accent doré) ----
        $offerBlock = '';
        if ($offer) {
            $offerBlock = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:2px 0 22px;"><tr>'
                . '<td style="background:#FFFBEB;background:linear-gradient(135deg,#FEF3C7 0%,#FDE68A 100%);border:1.5px solid #FBBF24;border-radius:16px;padding:18px 22px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                . '<td valign="middle" style="font-size:34px;line-height:1;width:46px;">&#127873;</td>'
                . '<td valign="middle" style="font-family:Arial,Helvetica,sans-serif;">'
                . '<div style="font-size:18px;font-weight:800;color:#78350F;letter-spacing:.2px;">1 mois gratuit &#8212; offert pour la rentrée</div>'
                . '<div style="font-size:13px;color:#92400E;margin-top:3px;font-weight:600;">Sans engagement &#183; sans carte bancaire &#183; résiliable en 1 clic</div>'
                . '</td></tr></table></td></tr></table>';
        }

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"></head>'
            . '<body style="margin:0;padding:0;background:#F1F5F4;">'
            // preheader caché (texte d'aperçu dans la boîte de réception)
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $pre . '&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F4;padding:26px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px -22px rgba(4,101,63,.35);">'
            // ---- Header dégradé + halo ----
            . '<tr><td style="background:#059669;background:linear-gradient(120deg,#0CCB8F 0%,#059669 52%,#047857 100%);padding:28px 30px 26px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="font-family:Arial,Helvetica,sans-serif;font-size:21px;font-weight:800;color:#ffffff;letter-spacing:.2px;">&#127807; Assokit</td>'
            . '<td align="right"><span style="display:inline-block;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:800;color:#78350F;background:#FCD34D;padding:5px 11px;border-radius:999px;letter-spacing:.4px;text-transform:uppercase;">&#127881; Spécial rentrée</span></td>'
            . '</tr></table></td></tr>'
            // fine barre d'accent dorée
            . '<tr><td style="height:4px;background:linear-gradient(90deg,#FCD34D,#FBBF24,#F59E0B);font-size:0;line-height:0;">&nbsp;</td></tr>'
            // ---- Corps ----
            . '<tr><td style="padding:32px 32px 8px;">'
            . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:800;color:#059669;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:12px;">Le logiciel des assos &amp; TPE</div>'
            . '<h1 style="margin:0 0 20px;font-family:Georgia,\'Times New Roman\',serif;font-size:29px;line-height:1.22;color:#0F172A;font-weight:700;">' . $head . '</h1>'
            . '<p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15.5px;line-height:1.65;color:#334155;">' . $hello . '</p>'
            . '<p style="margin:0 0 22px;font-family:Arial,Helvetica,sans-serif;font-size:15.5px;line-height:1.72;color:#334155;">' . $body . '</p>'
            // ---- Offre ----
            . $offerBlock
            // ---- Bouton 3D ----
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:2px 0 10px;"><tr>'
            . '<td align="center" bgcolor="#059669" style="border-radius:14px;background:linear-gradient(180deg,#10B981 0%,#059669 100%);box-shadow:0 6px 0 #04653F, 0 18px 30px rgba(4,101,63,.42);">'
            . '<a href="' . $link . '" style="display:inline-block;padding:17px 44px;font-family:Arial,Helvetica,sans-serif;font-size:16.5px;font-weight:800;color:#ffffff;text-decoration:none;letter-spacing:.3px;">' . $cta . ' &#8594;</a>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#94A3B8;">Activation en 2 minutes &#183; aucune installation</p>'
            // ---- Bande de bénéfices ----
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6FBF9;border:1px solid #E2F3EC;border-radius:14px;margin-bottom:22px;"><tr><td style="padding:14px 20px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0">' . $benefitRows . '</table>'
            . '</td></tr></table>'
            // ---- Preuve de confiance ----
            . '<p style="margin:0 0 22px;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;color:#64748B;text-align:center;">&#127467;&#127479; Hébergé en France &#160;&#160;&#183;&#160;&#160; &#128274; Données sécurisées &#160;&#160;&#183;&#160;&#160; &#9989; Sans engagement</p>'
            // ---- Signature ----
            . '<p style="margin:0 0 4px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#334155;">Bien à vous,</p>'
            . '<p style="margin:0 0 26px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#0F172A;"><strong>Raphael</strong> · Assokit<br><a href="https://assokit.fr" style="color:#059669;text-decoration:none;font-weight:600;">assokit.fr</a></p>'
            . '</td></tr>'
            // ---- Footer ----
            . '<tr><td style="padding:20px 32px 28px;border-top:1px solid #EEF2F0;background:#FBFCFB;">'
            . '<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:11.5px;line-height:1.6;color:#94A3B8;">Vous recevez cet email professionnel car votre structure pourrait être concernée par nos services de gestion. '
            . 'Pour ne plus être contacté&#183;e : <a href="' . $unsub . '" style="color:#94A3B8;text-decoration:underline;">se désinscrire</a>.</p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
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

        $html = ak_prospect_html_template([
            'hello'    => $hello,
            'headline' => 'La rentrée, sans la paperasse.',
            'body'     => $body_txt,
            'link'     => $link,
            'unsub'    => $unsub,
            'type'     => $type,
            'angle'    => $angle,
        ]);
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
