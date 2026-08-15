<?php
/**
 * seed-blog-topics-facture-electronique.php
 * --------------------------------------------------------------
 * Ajoute dans la file de génération (asso_blog_topics, status=pending)
 * le COCON SÉMANTIQUE "facture électronique" : 1 article pilier + son
 * cluster. Chaque sujet embarque son mot-clé principal (1er de la liste)
 * et un briefing qui donne l'angle différenciant.
 *
 * Les articles seront générés au nouveau format SEO (garde-fous :
 * meta ---META---, encadré "En bref", 4-6 H2, FAQ FAQPage, E-E-A-T,
 * maillage interne). Voir admin-blog/includes/claude.php.
 *
 * Usage :
 *   CLI  : php seed-blog-topics-facture-electronique.php
 *   web  : https://assokit.fr/seed-blog-topics-facture-electronique.php?token=CRON_TOKEN
 *
 * Idempotent : un sujet déjà présent en 'pending' est ignoré (skip).
 * IMPORTANT : à supprimer du serveur après exécution.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (!defined('CRON_TOKEN') || CRON_TOKEN === '' || !hash_equals(CRON_TOKEN, (string)$token)) {
        http_response_code(403);
        exit('Forbidden — utiliser ?token=CRON_TOKEN');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

echo "=== SEED TOPICS : cocon facture électronique ===\n\n";

$ALLOWED_CATS = ['associations', 'tpe', 'comptabilite', 'juridique', 'communication', 'gestion'];

// Rappel commun injecté dans chaque briefing (fraîcheur + E-E-A-T sur un sujet fiscal)
$COMMON = "IMPORTANT : sujet fiscal sensible — n'invente aucune date, aucun montant, aucun article de loi. "
        . "Le calendrier de la réforme a déjà été décalé par le passé : présente-le prudemment et renvoie à la source officielle "
        . "(impots.gouv.fr / service-public.fr). Affiche une logique \"à jour, à revérifier à sa source\". "
        . "Fais un lien interne vers l'article pilier \"facture électronique association\" quand il existe.";

$topics = [

    // ========================= ARTICLE PILIER =========================
    [
        'title'    => 'Facture électronique et associations : êtes-vous concerné ? (guide 2026-2027)',
        'category' => 'comptabilite',
        'priority' => 1,
        'keywords' => 'facture électronique association, association facture électronique obligatoire, facturation électronique association loi 1901, réforme facture électronique 2026',
        'briefing' => "ARTICLE PILIER du cocon. Angle unique que personne ne traite : le cas SPÉCIFIQUE des associations (la plupart des contenus ne parlent que des entreprises). "
                    . "Distingue clairement : (1) association NON assujettie à la TVA -> obligation surtout de RÉCEPTION ; (2) association avec activité commerciale assujettie -> concernée par l'ÉMISSION ; "
                    . "(3) les subventions ne sont pas des factures (pas de contrepartie) donc hors champ. Inclure un mini arbre de décision \"suis-je concerné ?\" et un tableau calendrier réception/émission. " . $COMMON,
    ],

    // ========================= CLUSTER =========================
    [
        'title'    => "Factur-X : c'est quoi et comment ça marche ? (le format expliqué simplement)",
        'category' => 'comptabilite',
        'priority' => 3,
        'keywords' => 'factur-x, format factur-x, facture hybride pdf xml, factur-x association',
        'briefing' => "Explique Factur-X = PDF/A-3 lisible par l'humain + XML de données (CII) lisible par la machine, dans un seul fichier. "
                    . "Vulgarise les profils (MINIMUM, BASIC, EN 16931...) sans jargon. Pourquoi ce format est retenu en France. Ce que ça change concrètement pour une asso/TPE. " . $COMMON,
    ],
    [
        'title'    => 'PDP : comment choisir sa plateforme de dématérialisation partenaire ?',
        'category' => 'comptabilite',
        'priority' => 3,
        'keywords' => 'plateforme de dématérialisation partenaire, pdp facture électronique, choisir une pdp, pdp ou ppf',
        'briefing' => "Explique le rôle d'une PDP, la différence PDP vs portail public (PPF), pourquoi il faudra passer par une plateforme. "
                    . "Donne une checklist de critères de choix (conformité, tarifs, connecteurs, support, réversibilité). Cas d'une petite structure. " . $COMMON,
    ],
    [
        'title'    => 'Facture électronique : le calendrier 2026-2027 (qui, quoi, quand)',
        'category' => 'comptabilite',
        'priority' => 2,
        'keywords' => 'facture électronique calendrier 2026, obligation facture électronique date, réforme facturation électronique échéances',
        'briefing' => "Article \"calendrier\" à forte intention. Tableau : obligation de RÉCEPTION (toutes tailles) vs ÉMISSION (grandes entreprises/ETI puis PME/micro). "
                    . "Insiste sur la date de mise à jour visible et sur la vérification à la source (le calendrier a déjà glissé). Répond à \"suis-je en retard ?\". " . $COMMON,
    ],
    [
        'title'    => 'Association et TVA : ce que le régime change pour vos factures',
        'category' => 'juridique',
        'priority' => 2,
        'keywords' => 'association assujettie tva, association tva facture, franchise tva association, association non lucrative tva',
        'briefing' => "Clarifie assujetti vs non assujetti, activités lucratives sectorisées, franchise en base, mention \"TVA non applicable, art. 293 B du CGI\". "
                    . "Relie ce statut à l'obligation (ou non) d'émettre des factures électroniques. Beaucoup d'assos ignorent leur propre situation : aide-les à se situer. " . $COMMON,
    ],
    [
        'title'    => 'Subvention ou prestation : quand une association doit-elle facturer ?',
        'category' => 'comptabilite',
        'priority' => 4,
        'keywords' => 'association facturation subvention prestation, subvention facture, association facture prestation de service',
        'briefing' => "Distingue subvention (aide sans contrepartie directe -> pas de facture) et prestation/vente (contrepartie -> facture). "
                    . "Conséquence directe sur la réforme : la subvention est hors champ facture électronique, la prestation entre assujettis y entre. Exemples concrets d'assos. " . $COMMON,
    ],
    [
        'title'    => 'TPE et facture électronique : êtes-vous prêt pour l\'obligation ?',
        'category' => 'tpe',
        'priority' => 2,
        'keywords' => 'facture électronique tpe, facture électronique auto-entrepreneur, obligation facturation électronique petite entreprise',
        'briefing' => "Pendant TPE/indépendants du pilier associatif. Rappelle que même une micro-entreprise devra recevoir puis émettre. "
                    . "Checklist de préparation en 6 étapes (identifier son statut, choisir une PDP, adapter son outil, tester, former, archiver). Ton rassurant, actionnable. " . $COMMON,
    ],
    [
        'title'    => 'Les mentions obligatoires d\'une facture en 2026 (association et TPE)',
        'category' => 'comptabilite',
        'priority' => 4,
        'keywords' => 'mentions obligatoires facture, mentions légales facture 2026, facture conforme association tpe',
        'briefing' => "Liste à jour des mentions obligatoires (identité, SIREN/SIRET, TVA ou mention de franchise, date, numéro séquentiel, conditions et pénalités de retard, indemnité 40 €). "
                    . "Ajoute les 4 nouvelles mentions introduites par la réforme e-invoicing (ex. n° SIREN du client, type d'opération, option paiement TVA). Renvoie à service-public.fr. " . $COMMON,
    ],
    [
        'title'    => 'E-reporting : l\'autre volet de la réforme (transmission des données)',
        'category' => 'comptabilite',
        'priority' => 5,
        'keywords' => 'e-reporting, e-reporting facturation, transmission données transaction dgfip, e-reporting b2c',
        'briefing' => "Explique la différence e-invoicing (factures B2B domestiques entre assujettis) vs e-reporting (données de transactions B2C, avec des non-assujettis, à l'international). "
                    . "Qui est concerné par l'e-reporting et pourquoi. Sujet peu couvert = opportunité SEO. " . $COMMON,
    ],
    [
        'title'    => 'Piste d\'audit fiable : la mettre en place simplement (asso et TPE)',
        'category' => 'juridique',
        'priority' => 6,
        'keywords' => 'piste d\'audit fiable, paf facturation, traçabilité facture obligation, contrôle fiscal facture',
        'briefing' => "Vulgarise la piste d'audit fiable (PAF) : documenter le chemin devis -> commande -> facture -> paiement, garantir l'intégrité, la lisibilité et la conservation (10 ans). "
                    . "Donne 5 bonnes pratiques concrètes. Relie à la facture électronique (numérotation continue, immutabilité, archivage). " . $COMMON,
    ],
];

// ------------------------------------------------------------
// Insertion (idempotente)
// ------------------------------------------------------------
$added = 0; $skipped = 0; $errors = 0;

$check = $pdo->prepare("SELECT id FROM asso_blog_topics WHERE topic_title = ? AND status = 'pending' LIMIT 1");
$ins   = $pdo->prepare(
    "INSERT INTO asso_blog_topics (topic_title, category, target_keywords, briefing_extra, priority, status, created_at)
     VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
);

foreach ($topics as $t) {
    $title = trim((string)$t['title']);
    $cat   = (string)$t['category'];

    if ($title === '' || mb_strlen($title) > 255) { echo "SKIP (titre invalide): {$title}\n"; $skipped++; continue; }
    if (!in_array($cat, $ALLOWED_CATS, true)) { echo "SKIP (catégorie invalide '{$cat}'): {$title}\n"; $skipped++; continue; }

    try {
        $check->execute([$title]);
        if ($check->fetch()) { echo "SKIP (déjà en file): {$title}\n"; $skipped++; continue; }

        $priority = (int)($t['priority'] ?? 5);
        if ($priority < 1 || $priority > 10) $priority = 5;

        $ins->execute([
            $title,
            $cat,
            mb_substr((string)($t['keywords'] ?? ''), 0, 500),
            mb_substr((string)($t['briefing'] ?? ''), 0, 1000),
            $priority,
        ]);
        echo "OK   (+{$priority}) [{$cat}] {$title}\n";
        $added++;
    } catch (Throwable $e) {
        echo "ERR  {$title} -> " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== Terminé : {$added} ajouté(s), {$skipped} ignoré(s), {$errors} erreur(s) ===\n";
echo "Générez-les depuis /admin-blog (file des sujets) ou laissez le CRON les traiter.\n";
echo "Pensez à SUPPRIMER ce fichier du serveur après usage.\n";
