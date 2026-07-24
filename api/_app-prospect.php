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
if (!function_exists('ak_prospect_format_body')) {
    /** Met en forme le corps : échappe le HTML, gère **gras** et les mini-paragraphes (\n\n). */
    function ak_prospect_format_body(string $raw, string $font): string {
        $raw = trim($raw);
        if ($raw === '') return '';
        $paras = preg_split('/\n\s*\n/', $raw);              // paragraphes séparés par une ligne vide
        $out = '';
        foreach ($paras as $i => $para) {
            $esc = htmlspecialchars(trim($para), ENT_QUOTES, 'UTF-8');
            $esc = preg_replace('/\*\*(.+?)\*\*/s', '<strong style="color:#0F172A;font-weight:700;">$1</strong>', $esc);
            $esc = nl2br($esc);                               // saut de ligne simple -> <br>
            $mb = $i === count($paras) - 1 ? '20px' : '14px';
            $out .= '<p style="margin:0 0 ' . $mb . ';font-family:' . $font . ';font-size:15.5px;line-height:1.72;color:#334155;">' . $esc . '</p>';
        }
        return $out;
    }
}

if (!function_exists('ak_prospect_mockup')) {
    /** Mockup produit (fenêtre app) en HTML email-safe, thématisé asso/TPE. */
    function ak_prospect_mockup(string $type, string $font): string {
        $title = $type === 'tpe' ? 'Assokit &#183; Tableau de bord' : 'Assokit &#183; Adhérents';
        $rows = $type === 'tpe' ? [
            ['&#129534;', '#DBEAFE', 'Factures du mois', '6 payées &#183; 2 en attente', '8 400 &#8364;', '#065F46', '#D1FAE5'],
            ['&#127959;&#65039;', '#EDE9FE', 'Chantier Villa Sud', 'Budget suivi &#183; 3 factures', '62%', '#5B21B6', '#EDE9FE'],
            ['&#128202;', '#E0F2FE', 'Comptabilité analytique', 'Rentabilité par chantier', 'Live', '#0369A1', '#E0F2FE'],
            ['&#128176;', '#FEF3C7', 'Trésorerie', 'Relances automatiques', 'Saine', '#047857', '#D1FAE5'],
        ] : [
            ['&#128101;', '#DCFCE7', '248 adhérents', 'Annuaire à jour', '+12', '#047857', '#D1FAE5'],
            ['&#128179;', '#FCE7F3', 'Cotisations', 'Statut par adhérent', '94%', '#047857', '#D1FAE5'],
            ['&#128202;', '#E0F2FE', 'Comptabilité analytique', 'Rentabilité par projet', 'Live', '#0369A1', '#E0F2FE'],
            ['&#129534;', '#DBEAFE', 'Facture 2026-014', 'Émise le 2 sept.', 'Payée', '#1D4ED8', '#DBEAFE'],
        ];
        $body = '';
        foreach ($rows as $i => $r) {
            $bt = $i > 0 ? 'border-top:1px solid #F1F5F4;' : '';
            $body .= '<tr><td style="padding:13px 18px;' . $bt . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                . '<td valign="middle" style="width:40px;"><div style="width:36px;height:36px;border-radius:10px;background:' . $r[1] . ';text-align:center;line-height:36px;font-size:17px;">' . $r[0] . '</div></td>'
                . '<td valign="middle" style="padding-left:12px;font-family:' . $font . ';">'
                . '<div style="font-size:14px;font-weight:700;color:#0F172A;">' . $r[2] . '</div>'
                . '<div style="font-size:12px;color:#94A3B8;margin-top:2px;">' . $r[3] . '</div></td>'
                . '<td valign="middle" align="right"><span style="display:inline-block;font-family:' . $font . ';font-size:12.5px;font-weight:800;color:' . $r[5] . ';background:' . $r[6] . ';padding:5px 11px;border-radius:999px;">' . $r[4] . '</span></td>'
                . '</tr></table></td></tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 26px;background:#ffffff;border:1px solid #E8EEEC;border-radius:16px;overflow:hidden;box-shadow:0 16px 34px -18px rgba(15,23,42,.22);">'
            // barre fenêtre
            . '<tr><td style="background:#F8FAFB;border-bottom:1px solid #EEF2F0;padding:12px 16px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td style="width:52px;"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#F87171;"></span> <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#FBBF24;"></span> <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#34D399;"></span></td>'
            . '<td align="center" style="font-family:' . $font . ';font-size:12px;font-weight:700;color:#64748B;">' . $title . '</td>'
            . '<td style="width:52px;">&nbsp;</td>'
            . '</tr></table></td></tr>'
            . $body
            . '</table>';
    }
}

if (!function_exists('ak_prospect_html_template')) {
    function ak_prospect_html_template(array $d): string {
        $FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $logo = (defined('AK_PUBLIC_DOMAIN') ? rtrim(AK_PUBLIC_DOMAIN, '/') : 'https://assokit.fr') . '/assets/logo-assokit.png';
        $hello = htmlspecialchars((string) ($d['hello'] ?? 'Bonjour,'));
        $head  = htmlspecialchars((string) ($d['headline'] ?? 'La rentrée, simplifiée.'));
        $bodyHtml = ak_prospect_format_body((string) ($d['body'] ?? ''), "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif");
        $link  = htmlspecialchars((string) ($d['link'] ?? 'https://assokit.fr'));
        $unsub = htmlspecialchars((string) ($d['unsub'] ?? 'https://assokit.fr'));
        $type  = ($d['type'] ?? 'asso') === 'tpe' ? 'tpe' : 'asso';
        $offer = !array_key_exists('offer', $d) || !empty($d['offer']);
        $cta   = htmlspecialchars((string) ($d['cta'] ?? ($offer ? 'Activer mon mois gratuit' : 'Découvrir Assokit')));
        $pre   = htmlspecialchars(($offer ? '1 mois offert, sans engagement — ' : '') . mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($d['body'] ?? '')))), 0, 90));

        $benefits = $type === 'tpe'
            ? ['Devis &amp; factures créés en 2 minutes', 'Trésorerie suivie, relances automatiques', 'Application mobile incluse']
            : ['Adhérents &amp; cotisations centralisés', 'Comptabilité et factures simplifiées', 'Application mobile incluse'];
        $benefitRows = '';
        foreach ($benefits as $b) {
            $benefitRows .= '<tr>'
                . '<td valign="top" style="padding:7px 12px 7px 0;width:26px;">'
                . '<div style="width:22px;height:22px;border-radius:50%;background:#D1FAE5;color:#047857;font-weight:800;font-size:13px;line-height:22px;text-align:center;font-family:' . $FONT . ';">&#10003;</div>'
                . '</td>'
                . '<td valign="middle" style="padding:7px 0;font-family:' . $FONT . ';font-size:14.5px;color:#334155;font-weight:500;">' . $b . '</td>'
                . '</tr>';
        }

        // ---- Offre 1 mois (accent doré de la charte) ----
        $offerBlock = '';
        if ($offer) {
            $offerBlock = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 24px;"><tr>'
                . '<td style="background:#FFFDF5;border:1px solid #FDE68A;border-left:4px solid #F59E0B;border-radius:12px;padding:16px 20px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                . '<td valign="middle" style="width:40px;font-size:26px;line-height:1;">&#127873;</td>'
                . '<td valign="middle" style="font-family:' . $FONT . ';">'
                . '<div style="font-size:16px;font-weight:800;color:#0F172A;">1 mois gratuit, offert pour la rentrée</div>'
                . '<div style="font-size:12.5px;color:#92400E;margin-top:2px;font-weight:600;">Sans engagement &#183; sans carte bancaire &#183; résiliable en 1 clic</div>'
                . '</td></tr></table></td></tr></table>';
        }

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"></head>'
            . '<body style="margin:0;padding:0;background:#EEF2F1;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $pre . '&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F1;padding:28px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 22px 60px -24px rgba(15,23,42,.30);">'
            // ---- Header marque : logo réel + wordmark ----
            . '<tr><td style="background:#047857;background:linear-gradient(125deg,#059669 0%,#047857 100%);padding:24px 30px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td valign="middle" style="width:40px;"><img src="' . $logo . '" width="34" height="34" alt="Assokit" style="display:block;width:34px;height:34px;border-radius:9px;"></td>'
            . '<td valign="middle" style="padding-left:11px;font-family:' . $FONT . ';font-size:20px;font-weight:800;color:#ffffff;letter-spacing:-.2px;">Assokit</td>'
            . '<td align="right" valign="middle"><span style="display:inline-block;font-family:' . $FONT . ';font-size:10.5px;font-weight:700;color:#ffffff;background:rgba(255,255,255,.16);padding:5px 12px;border-radius:999px;letter-spacing:.5px;text-transform:uppercase;">Rentrée 2026</span></td>'
            . '</tr></table></td></tr>'
            . '<tr><td style="height:3px;background:#FCD34D;font-size:0;line-height:0;">&nbsp;</td></tr>'
            // ---- Corps ----
            . '<tr><td style="padding:34px 34px 8px;">'
            . '<div style="font-family:' . $FONT . ';font-size:11.5px;font-weight:800;color:#059669;letter-spacing:1.4px;text-transform:uppercase;margin-bottom:12px;">Le logiciel des associations &amp; TPE</div>'
            . '<h1 style="margin:0 0 20px;font-family:' . $FONT . ';font-size:28px;line-height:1.24;color:#0F172A;font-weight:800;letter-spacing:-.5px;">' . $head . '</h1>'
            . '<p style="margin:0 0 14px;font-family:' . $FONT . ';font-size:15.5px;line-height:1.65;color:#334155;">' . $hello . '</p>'
            . $bodyHtml
            // ---- Argument phare : compta analytique temps réel ----
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 24px;"><tr>'
            . '<td style="background:#F0F9FF;border:1px solid #BAE6FD;border-left:4px solid #0369A1;border-radius:12px;padding:17px 20px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td valign="top" style="width:38px;font-size:24px;line-height:1.2;">&#128202;</td>'
            . '<td valign="top" style="font-family:' . $FONT . ';">'
            . '<div style="font-size:15px;font-weight:800;color:#0F172A;margin-bottom:5px;">La comptabilité analytique en temps réel</div>'
            . '<div style="font-size:14px;line-height:1.6;color:#334155;">Vos comptes sont <strong style="color:#0F172A;">à jour en continu, tout seuls</strong>. Fini le comptable au quotidien : un <strong style="color:#0F172A;">expert-comptable valide simplement vos documents en fin d&#039;année</strong>. Un <strong style="color:#0369A1;">gain de temps et d&#039;argent considérable</strong> &#8212; surtout par les temps qui courent.</div>'
            . '</td></tr></table></td></tr></table>'
            // ---- Mockup produit ----
            . ak_prospect_mockup($type, $FONT)
            // ---- Offre ----
            . $offerBlock
            // ---- Bouton 3D ----
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:2px 0 10px;"><tr>'
            . '<td align="center" bgcolor="#059669" style="border-radius:13px;background:linear-gradient(180deg,#10B981 0%,#059669 100%);box-shadow:0 5px 0 #04653F, 0 16px 26px rgba(4,101,63,.36);">'
            . '<a href="' . $link . '" style="display:inline-block;padding:16px 42px;font-family:' . $FONT . ';font-size:16px;font-weight:800;color:#ffffff;text-decoration:none;letter-spacing:.2px;">' . $cta . ' &#8594;</a>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 26px;font-family:' . $FONT . ';font-size:12px;color:#94A3B8;">Activation en 2 minutes &#183; aucune installation &#183; sans carte bancaire</p>'
            // ---- Bénéfices ----
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7FBFA;border:1px solid #E6F0EC;border-radius:14px;margin-bottom:22px;"><tr><td style="padding:14px 20px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0">' . $benefitRows . '</table>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 24px;font-family:' . $FONT . ';font-size:12.5px;color:#64748B;text-align:center;">&#127467;&#127479; Hébergé en France&#160;&#160;&#183;&#160;&#160;&#128274; Données sécurisées&#160;&#160;&#183;&#160;&#160;&#9989; Sans engagement</p>'
            // ---- Signature ----
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:26px;"><tr>'
            . '<td valign="middle" style="width:44px;"><div style="width:40px;height:40px;border-radius:50%;background:#ECFDF5;text-align:center;line-height:40px;font-family:' . $FONT . ';font-weight:800;color:#059669;font-size:15px;">R</div></td>'
            . '<td valign="middle" style="padding-left:12px;font-family:' . $FONT . ';">'
            . '<div style="font-size:14.5px;font-weight:700;color:#0F172A;">Raphael</div>'
            . '<div style="font-size:12.5px;color:#64748B;">Fondateur &#183; <a href="https://assokit.fr" style="color:#059669;text-decoration:none;font-weight:600;">assokit.fr</a></div>'
            . '</td></tr></table>'
            . '</td></tr>'
            // ---- Footer ----
            . '<tr><td style="padding:18px 34px 26px;border-top:1px solid #EEF2F0;background:#FBFCFB;">'
            . '<p style="margin:0;font-family:' . $FONT . ';font-size:11.5px;line-height:1.6;color:#94A3B8;">Vous recevez cet email professionnel car votre structure pourrait être concernée par nos services de gestion. '
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
                     . "FORMAT OBLIGATOIRE : 2 à 3 MINI-PARAGRAPHES très courts séparés par une ligne vide (\\n\\n), "
                     . "et mets en **gras** (avec des doubles astérisques) 2 ou 3 expressions clés pour attirer l'oeil. "
                     . "Ton respectueux, pas de superlatifs racoleurs, une seule idée forte + un appel à l'action doux. "
                     . "Ne mets NI objet, NI formule d'ouverture (pas de 'Bonjour'), NI signature : juste le corps.";
                $u = "Étape : " . ($seq[$step]['label'] ?? 'contact') . ". Destinataire : " . $orgBit . $cityBit . ". "
                   . "Angle rentrée : " . $angle['hook'] . ". Douleur à soulager : " . $angle['pain'] . ".";
                $body_txt = trim((string) ClaudeAPI::callMessages($sys, $u, 400));
            } catch (Throwable $e) { $body_txt = null; }
        }
        if (!$body_txt) {
            $templates = [
                0 => "À l'approche de **" . $angle['hook'] . "**, je me permets de contacter **" . $orgBit . $cityBit . "**.\n\n"
                   . "C'est souvent la période la plus chargée pour " . $angle['pain'] . ".\n\n"
                   . "**Assokit réunit tout ça dans un seul outil simple** — pour aborder la rentrée sans paperasse et **gagner un temps précieux** dès ce mois-ci.",
                1 => "Je me permets de revenir vers vous en pleine rentrée.\n\n"
                   . "Beaucoup de structures comme la vôtre nous disent **gagner plusieurs heures par semaine** sur " . $angle['pain'] . " grâce à Assokit.\n\n"
                   . "Cela vaut peut-être un coup d'œil **avant que le rush ne s'installe** ?",
                2 => "Petit rappel amical.\n\n"
                   . "Si **simplifier " . $angle['pain'] . "** pour cette rentrée vous parle, je serais ravi de vous montrer concrètement Assokit **en 20 minutes**, adapté à " . $orgBit . ".",
                3 => "Je ne voudrais pas vous déranger inutilement en cette période chargée.\n\n"
                   . "Si ce n'est **pas le bon moment**, dites-le moi simplement.\n\n"
                   . "Sinon, la page ci-dessous **résume tout en 2 minutes**.",
                4 => "Dernier message de ma part.\n\n"
                   . "Je vous laisse la page de présentation, à consulter quand vous voulez.\n\n"
                   . "Et si la gestion de " . $who . " devient un casse-tête cette saison, **vous saurez où nous trouver** !",
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
