<?php
/**
 * ============================================================
 * ASSOKIT — cron-email-templates.php
 * Templates HTML des emails envoyés par les jobs CRON
 * ============================================================
 *
 * 7 templates :
 *   - relance_j3   (doux)
 *   - relance_j7   (ferme)
 *   - relance_j15  (urgent)
 *   - essai_j7     (avant-goût)
 *   - essai_j3     (invitation)
 *   - essai_j0     (accueil)
 *   - renouvellement (nouvelle facture émise)
 *
 * Identité visuelle : émeraude #059669, ton positif et humaniste.
 * ============================================================
 */

if (!defined('CRON_EXECUTING') && !defined('CRON_ADMIN_UI')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Retourne le HTML complet d'un email selon son type.
 *
 * @param string $type  relance_j3|relance_j7|relance_j15|essai_j7|essai_j3|essai_j0|renouvellement
 * @param array  $vars  Variables à injecter (first_name, amount, due_date, invoice_number, etc.)
 */
function cron_email_render(string $type, array $vars): array
{
    $vars = array_merge([
        'first_name'     => 'bonjour',
        'asso_name'      => 'votre association',
        'amount'         => '0,00 €',
        'due_date'       => '',
        'trial_end_date' => '',
        'invoice_number' => '',
        'period_end'     => '',
        'login_url'      => 'https://assokit.fr/connexion',
        'billing_url'    => 'https://assokit.fr/facturation',
        'upgrade_url'    => 'https://assokit.fr/tarifs',
    ], $vars);

    switch ($type) {
        case 'relance_j3':
            $subject = 'Petit rappel — votre facture Assokit ' . $vars['invoice_number'];
            $preheader = 'Un rappel tout doux : votre facture attend un règlement.';
            $title = 'Un rappel tout doux 🌿';
            $body = '
                <p>Bonjour ' . htmlspecialchars($vars['first_name']) . ',</p>
                <p>Nous espérons que tout va bien pour ' . htmlspecialchars($vars['asso_name']) . '.</p>
                <p>Nous voulions simplement vous signaler que votre facture <strong>' . htmlspecialchars($vars['invoice_number']) . '</strong>
                   d\'un montant de <strong>' . htmlspecialchars($vars['amount']) . '</strong>
                   n\'a pas encore été réglée. Son échéance était le ' . htmlspecialchars($vars['due_date']) . '.</p>
                <p>Si c\'est un simple oubli, pas de souci — un petit détour par votre espace règle ça en quelques instants.</p>
            ';
            $cta = ['label' => 'Régler ma facture', 'url' => $vars['billing_url']];
            break;

        case 'relance_j7':
            $subject = 'Votre facture Assokit attend — ' . $vars['invoice_number'];
            $preheader = 'Votre facture est toujours en attente de règlement.';
            $title = 'Votre facture attend toujours';
            $body = '
                <p>Bonjour ' . htmlspecialchars($vars['first_name']) . ',</p>
                <p>Votre facture <strong>' . htmlspecialchars($vars['invoice_number']) . '</strong>
                   de <strong>' . htmlspecialchars($vars['amount']) . '</strong>
                   reste à régler. Son échéance était le ' . htmlspecialchars($vars['due_date']) . ', soit il y a déjà une semaine.</p>
                <p>Pour que ' . htmlspecialchars($vars['asso_name']) . ' continue à profiter de toutes les fonctionnalités d\'Assokit,
                   merci de procéder au règlement depuis votre espace de facturation.</p>
                <p>Une difficulté ? Répondez simplement à cet email, notre équipe vous accompagne.</p>
            ';
            $cta = ['label' => 'Régler ma facture', 'url' => $vars['billing_url']];
            break;

        case 'relance_j15':
            $subject = 'Dernière relance — facture Assokit ' . $vars['invoice_number'];
            $preheader = 'Votre facture est en retard de 15 jours. Action requise.';
            $title = 'Un dernier rappel avant suspension';
            $body = '
                <p>Bonjour ' . htmlspecialchars($vars['first_name']) . ',</p>
                <p>Votre facture <strong>' . htmlspecialchars($vars['invoice_number']) . '</strong>
                   de <strong>' . htmlspecialchars($vars['amount']) . '</strong>
                   est désormais en retard de 15 jours (échéance : ' . htmlspecialchars($vars['due_date']) . ').</p>
                <p>Sans règlement rapide, votre accès à Assokit sera temporairement suspendu pour préserver nos services.
                   Vos données restent intactes et seront toujours disponibles dès le règlement effectué.</p>
                <p>Nous souhaitons trouver une solution avec vous. Contactez-nous à tout moment en répondant à cet email —
                   nous sommes là pour vous accompagner.</p>
            ';
            $cta = ['label' => 'Régler maintenant', 'url' => $vars['billing_url']];
            break;

        case 'essai_j7':
            $subject = 'Plus qu\'une semaine pour découvrir Assokit 🌱';
            $preheader = 'Il vous reste 7 jours d\'essai gratuit.';
            $title = 'Votre essai se poursuit 🌱';
            $body = '
                <p>Bonjour ' . htmlspecialchars($vars['first_name']) . ',</p>
                <p>Vous avez encore 7 jours pour explorer toutes les possibilités d\'Assokit avec ' . htmlspecialchars($vars['asso_name']) . '.</p>
                <p>Votre essai gratuit se termine le <strong>' . htmlspecialchars($vars['trial_end_date']) . '</strong>.
                   C\'est le bon moment pour tester :</p>
                <ul>
                    <li>✨ Le copilote IA qui vous suggère les bonnes actions chaque jour</li>
                    <li>📊 La génération automatique de votre bilan à la clôture d\'un projet</li>
                    <li>👥 L\'annuaire adhérents et l\'envoi d\'emails en masse</li>
                </ul>
                <p>Une question ? Répondez-nous, on est là.</p>
            ';
            $cta = ['label' => 'Continuer avec Assokit', 'url' => $vars['upgrade_url']];
            break;

        case 'essai_j3':
            $subject = 'Votre essai Assokit se termine dans 3 jours';
            $preheader = 'Passez au plan Association pour garder toutes les fonctionnalités.';
            $title = 'Plus que 3 jours ✨';
            $body = '
                <p>Bonjour ' . htmlspecialchars($vars['first_name']) . ',</p>
                <p>Votre essai gratuit Assokit se termine dans <strong>3 jours</strong>, le ' . htmlspecialchars($vars['trial_end_date']) . '.</p>
                <p>Pour que ' . htmlspecialchars($vars['asso_name']) . ' continue à bénéficier du copilote IA, des projets illimités,
                   de l\'agenda partagé et des 3 000 emails inclus chaque mois, il vous suffit de choisir le plan Association.</p>
                <p><strong>49,99 € TTC/mois, sans engagement.</strong> Vous pouvez revenir à l\'essentiel gratuit à tout moment.</p>
            ';
            $cta = ['label' => 'Choisir mon plan', 'url' => $vars['upgrade_url']];
            break;

        case 'essai_j0':
            $subject = 'Votre essai Assokit se termine aujourd\'hui';
            $preheader = 'Dernier jour pour choisir votre plan et garder tous vos avantages.';
            $title = 'Votre essai se termine aujourd\'hui';
            $body = '
                <p>Bonjour ' . htmlspecialchars($vars['first_name']) . ',</p>
                <p>Votre période d\'essai Assokit se termine <strong>aujourd\'hui</strong>.</p>
                <p>Si vous souhaitez continuer avec toutes les fonctionnalités pour ' . htmlspecialchars($vars['asso_name']) . '
                   (copilote IA complet, projets illimités, 500 adhérents, agenda, messagerie, 3 000 emails/mois),
                   passez au plan Association dès maintenant.</p>
                <p>Sinon, votre espace reste accessible en version Essentiel (gratuite) — vous gardez vos données,
                   avec les limites du plan gratuit.</p>
            ';
            $cta = ['label' => 'Activer mon plan Association', 'url' => $vars['upgrade_url']];
            break;

        case 'renouvellement':
            $subject = 'Votre nouvelle facture Assokit est prête — ' . $vars['invoice_number'];
            $preheader = 'Votre abonnement a été reconduit. Voici la nouvelle facture.';
            $title = 'Votre abonnement continue 🌿';
            $body = '
                <p>Bonjour ' . htmlspecialchars($vars['first_name']) . ',</p>
                <p>Merci de faire confiance à Assokit pour accompagner ' . htmlspecialchars($vars['asso_name']) . '.</p>
                <p>Votre abonnement vient d\'être reconduit pour la période se terminant le
                   <strong>' . htmlspecialchars($vars['period_end']) . '</strong>.</p>
                <p>Nouvelle facture émise : <strong>' . htmlspecialchars($vars['invoice_number']) . '</strong> —
                   montant : <strong>' . htmlspecialchars($vars['amount']) . '</strong>.</p>
                <p>Vous pouvez la consulter et la régler depuis votre espace de facturation.</p>
            ';
            $cta = ['label' => 'Voir ma facture', 'url' => $vars['billing_url']];
            break;

        default:
            $subject = 'Message Assokit';
            $preheader = '';
            $title = 'Bonjour';
            $body = '<p>Bonjour ' . htmlspecialchars($vars['first_name']) . '.</p>';
            $cta = ['label' => 'Ouvrir mon espace', 'url' => $vars['login_url']];
    }

    $html = cron_email_wrap($title, $body, $cta, $preheader);
    return ['subject' => $subject, 'html' => $html];
}

/**
 * Enveloppe HTML commune : en-tête logo, corps, CTA, footer.
 */
function cron_email_wrap(string $title, string $body, array $cta, string $preheader = ''): string
{
    $titleSafe = htmlspecialchars($title);
    $preheaderSafe = htmlspecialchars($preheader);
    $ctaLabel = htmlspecialchars($cta['label'] ?? 'Ouvrir mon espace');
    $ctaUrl   = htmlspecialchars($cta['url']   ?? 'https://assokit.fr/');

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleSafe}</title>
</head>
<body style="margin:0;padding:0;background:#FAFAF9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0A0A0B;">
<span style="display:none;opacity:0;visibility:hidden;height:0;width:0;overflow:hidden;">{$preheaderSafe}</span>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FAFAF9;padding:40px 20px;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.04);max-width:600px;">

        <!-- HEADER -->
        <tr>
          <td style="padding:32px 36px 24px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="vertical-align:middle;">
                  <span style="display:inline-block;width:28px;height:28px;background:#059669;border-radius:8px;position:relative;vertical-align:middle;"></span>
                </td>
                <td style="padding-left:10px;vertical-align:middle;font-size:18px;font-weight:600;color:#0A0A0B;letter-spacing:-0.02em;">
                  Assokit
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- CORPS -->
        <tr>
          <td style="padding:36px;">
            <h1 style="margin:0 0 20px;font-size:24px;line-height:1.3;color:#0A0A0B;font-weight:600;letter-spacing:-0.02em;">
              {$titleSafe}
            </h1>
            <div style="font-size:15px;line-height:1.65;color:#3F3F46;">
              {$body}
            </div>

            <!-- CTA -->
            <table cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 8px;">
              <tr>
                <td style="background:#059669;border-radius:10px;">
                  <a href="{$ctaUrl}" style="display:inline-block;padding:13px 26px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                    {$ctaLabel}
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="padding:20px 36px 28px;border-top:1px solid rgba(0,0,0,0.06);font-size:12px;color:#71717A;line-height:1.6;">
            <p style="margin:0 0 6px;">
              <strong style="color:#059669;">L'art de mener vos projets.</strong>
            </p>
            <p style="margin:0;">
              Assokit — Hébergé en France · Conforme RGPD ·
              <a href="https://assokit.fr" style="color:#059669;text-decoration:none;">assokit.fr</a>
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>

</body>
</html>
HTML;
}
