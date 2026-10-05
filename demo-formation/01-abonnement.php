<?php
/**
 * 01 — Abonnement Assokit de DEMO F et logo de l'association.
 * ------------------------------------------------------------------
 * Sans ligne asso_subscriptions, plan-helpers.php crée tout seul un
 * abonnement « Démarrage » bridé à la première visite : quotas, flous,
 * bandeau « Passer à Assokit ». La démo doit au contraire tout montrer :
 * on la place sur la formule la plus complète qui existe sur le serveur.
 *
 * Le plan est lu en base, jamais supposé : « sur-mesure » s'il existe et
 * n'est pas un essai, sinon le plan non-essai qui débloque le plus.
 * Une limite à 0 veut dire « non inclus » ; seule NULL est illimitée.
 * ------------------------------------------------------------------
 */

/** Choisit le plan le plus complet. Renvoie la ligne asso_plans ou null. */
function df_plan_complet(): ?array
{
    if (!df_a_table('asso_plans')) return null;
    $plans = DF::$pdo->query("SELECT * FROM asso_plans")->fetchAll(PDO::FETCH_ASSOC);
    $meilleur = null;
    $score = -1;
    foreach ($plans as $p) {
        if (!empty($p['is_trial'])) continue;
        $sc = 0;
        foreach ($p as $col => $v) {
            if (strpos($col, 'limit_') === 0 && $v === null) $sc += 2;
            if (strpos($col, 'feature_') === 0 && (int)$v === 1) $sc += 2;
        }
        if (($p['slug'] ?? '') === 'sur-mesure') $sc += 1;
        if ($sc > $score) { $score = $sc; $meilleur = $p; }
    }
    return $meilleur;
}

function df_seed_abonnement(): void
{
    $plan = df_plan_complet();
    if ($plan) {
        $limites = [];
        foreach ($plan as $col => $v) {
            if (strpos($col, 'limit_') === 0 && $v !== null) $limites[] = "$col=$v";
            if (strpos($col, 'feature_') === 0 && (int)$v !== 1) $limites[] = "$col=0";
        }
        if ($limites) {
            DF::$rapport['notes'][] = "Plan « {$plan['slug']} » choisi, mais il n'est pas illimité : " . implode(', ', $limites);
        }
        df_ins('asso_subscriptions', [
            'org_id'                 => DF::$org,
            'plan_id'                => (int)$plan['id'],
            'status'                 => 'active',
            // « Carte bancaire automatique » à l'écran, sans abonnement Stripe réel :
            // aucun bouton ne peut atteindre Stripe, l'annulation reste locale.
            'payment_mode'           => 'stripe',
            'stripe_subscription_id' => null,
            'started_at'             => df_jh(-1100, '09:00'),
            'current_period_end'     => date('Y-m-t 23:59:59', DF::$t0),
            'grace_period_end'       => null,
            'cancel_at_period_end'   => 0,
            'cancelled_at'           => null,
            'notes'                  => 'Démo DEMO F — abonnement fictif, reconstruit chaque nuit.',
            'created_at'             => df_jh(-1100, '09:00'),
            'updated_at'             => df_jh(-30, '09:00'),
        ]);
    } else {
        DF::$rapport['notes'][] = 'Aucun plan Assokit trouvé (asso_plans) : abonnement non créé.';
    }

    $logo = df_logo();
    df_maj('organizations', ['logo_path' => $logo, 'logo_uploaded_at' => $logo ? df_jh(-900, '10:12') : null],
           'id = ?', [DF::$org]);
}

/**
 * Logo de DEMO F dessiné avec GD : un livre ouvert blanc sur fond indigo.
 * Sans GD ou sans droit d'écriture : pas de logo plutôt qu'une image cassée.
 */
function df_logo(): ?string
{
    // Réécrit à chaque reconstruction : « Supprimer le logo » efface le
    // fichier du disque (mon-asso-logo.php), la base seule ne le ferait pas revenir.
    $rel = '/uploads/asso-logos/asso-' . DF::$org . '-demof.png';
    $abs = dirname(__DIR__) . $rel;
    if (!function_exists('imagecreatetruecolor')) return null;
    $dir = dirname($abs);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) return null;

    $t = 512;
    $img = imagecreatetruecolor($t, $t);
    imageantialias($img, true);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    $indigo = imagecolorallocate($img, 79, 70, 229);
    $fonce  = imagecolorallocate($img, 55, 48, 163);
    $blanc  = imagecolorallocate($img, 255, 255, 255);
    $ambre  = imagecolorallocate($img, 251, 191, 36);

    // Carré arrondi
    $r = 96;
    imagefilledrectangle($img, $r, 0, $t - $r, $t, $indigo);
    imagefilledrectangle($img, 0, $r, $t, $t - $r, $indigo);
    foreach ([[$r, $r], [$t - $r, $r], [$r, $t - $r], [$t - $r, $t - $r]] as [$x, $y]) {
        imagefilledellipse($img, $x, $y, 2 * $r, 2 * $r, $indigo);
    }
    imagefilledrectangle($img, 0, (int)($t * 0.72), $t, $t - $r, $fonce);
    imagefilledrectangle($img, $r, (int)($t * 0.72), $t - $r, $t, $fonce);
    imagefilledarc($img, $r, $t - $r, 2 * $r, 2 * $r, 90, 180, $fonce, IMG_ARC_PIE);
    imagefilledarc($img, $t - $r, $t - $r, 2 * $r, 2 * $r, 0, 90, $fonce, IMG_ARC_PIE);

    // Livre ouvert : deux pages inclinées
    imagefilledpolygon($img, [118, 170, 250, 200, 250, 380, 118, 350], $blanc);
    imagefilledpolygon($img, [394, 170, 262, 200, 262, 380, 394, 350], $blanc);
    // Lignes de texte
    imagesetthickness($img, 8);
    foreach ([235, 270, 305] as $y) {
        imageline($img, 145, $y - 8, 225, $y + 8, $indigo);
        imageline($img, 287, $y + 8, 367, $y - 8, $indigo);
    }
    // Étincelle : la formation qui ouvre une porte
    imagefilledellipse($img, 256, 120, 46, 46, $ambre);

    $ok = imagepng($img, $abs);
    imagedestroy($img);
    return $ok ? $rel : null;
}
