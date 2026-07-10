<?php
/**
 * fondateur-pilotage.php
 * --------------------------------------------------------------
 * Page-hub "Pilotage Fondateur" : regroupe tous les outils Fondateur
 * sous forme de cartes cliquables (au lieu d'un long menu déroulant).
 * Accès réservé au Fondateur.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();

if (empty($ctx['is_founder'])) {
    http_response_code(403);
    sa_render_head('Accès refusé');
    sa_render_sidebar('fondateur-pilotage');
    echo '<div style="max-width:560px;margin:50px auto;text-align:center;padding:34px;background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:16px;">';
    echo '<div style="font-size:44px;margin-bottom:12px;">🏗️</div>';
    echo '<h1 style="font-size:20px;margin:0 0 10px;">Réservé au Fondateur</h1>';
    echo '<p style="color:var(--sa-ink-3);font-size:14px;">Cette section de pilotage est accessible uniquement au Fondateur de la plateforme.</p>';
    echo '<a href="/super-admin" class="sa-btn sa-btn-ghost" style="margin-top:16px;">← Retour au dashboard</a>';
    echo '</div>';
    sa_render_foot();
    exit;
}

// Groupes de cartes
$groups = [
    [
        'label' => 'Monétisation',
        'accent' => '#34D399',
        'items' => [
            ['ic' => '💶', 'title' => 'Tarifs & TVA',     'desc' => 'Taux de TVA et tarifs de référence',       'url' => '/fondateur-pricing'],
            ['ic' => '💼', 'title' => 'Plans tarifaires',  'desc' => 'Formules, prix, quotas et options',        'url' => '/fondateur-plans'],
            ['ic' => '💳', 'title' => 'Config Stripe',     'desc' => 'Clés API et paiement en ligne',            'url' => '/fondateur-stripe-config'],
        ],
    ],
    [
        'label' => 'Croissance',
        'accent' => '#A78BFA',
        'items' => [
            ['ic' => '🌱', 'title' => 'Créer un compte',   'desc' => 'Nouvelle organisation en création directe', 'url' => '/fondateur-create-organization'],
            ['ic' => '🌐', 'title' => 'Domaines',          'desc' => 'Sous-domaines et domaines personnalisés',   'url' => '/fondateur-domains'],
            ['ic' => '✍️', 'title' => 'Blog SEO',          'desc' => 'Articles et référencement naturel',         'url' => '/admin-blog/', 'ext' => true],
        ],
    ],
    [
        'label' => 'Supervision',
        'accent' => '#FCD34D',
        'items' => [
            ['ic' => '🕵️', 'title' => 'Activité utilisateurs', 'desc' => 'Journal d\'activité de la plateforme',  'url' => '/fondateur-activity.php'],
        ],
    ],
];

sa_render_head('Pilotage Fondateur');
sa_render_sidebar('fondateur-pilotage');
?>

<style>
.fp-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:26px; }
.fp-title { font-size:28px; font-weight:800; letter-spacing:-0.03em; display:flex; align-items:center; gap:12px; margin:0; }
.fp-seal { font-size:11.5px; font-weight:800; letter-spacing:.05em; padding:5px 12px; border-radius:999px; background:linear-gradient(135deg,#FCD34D,#F59E0B); color:#3A2A08; box-shadow:0 8px 18px -6px rgba(245,158,11,.55); }
.fp-sub { color:var(--sa-ink-3); font-size:13.5px; margin-top:8px; }

.fp-group { margin-bottom:26px; }
.fp-group-label { font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--sa-ink-4); display:flex; align-items:center; gap:9px; margin:0 0 12px; }
.fp-group-label::before { content:""; width:9px; height:9px; border-radius:3px; background:var(--fp-accent,#FCD34D); box-shadow:0 0 0 4px color-mix(in srgb, var(--fp-accent,#FCD34D) 18%, transparent); }

.fp-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:14px; }
.fp-card {
    display:flex; align-items:center; gap:14px; padding:18px 18px; border-radius:16px;
    background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,0) 60%), var(--sa-bg-2);
    border:1px solid rgba(255,255,255,.09); text-decoration:none;
    box-shadow:0 1px 2px rgba(0,0,0,.3), 0 20px 44px -24px rgba(0,0,0,.75);
    transition:transform .14s ease, border-color .14s ease;
    position:relative; overflow:hidden;
}
.fp-card::before { content:""; position:absolute; inset:0 0 auto 0; height:3px; background:var(--fp-accent,#FCD34D); opacity:.85; }
.fp-card:hover { transform:translateY(-3px); border-color:color-mix(in srgb, var(--fp-accent,#FCD34D) 55%, transparent); }
.fp-card-ic { width:46px; height:46px; flex:none; border-radius:13px; display:grid; place-items:center; font-size:22px; background:color-mix(in srgb, var(--fp-accent,#FCD34D) 14%, var(--sa-bg-3)); }
.fp-card-b { flex:1; min-width:0; }
.fp-card-t { font-size:15px; font-weight:700; color:var(--sa-ink); letter-spacing:-0.01em; display:flex; align-items:center; gap:6px; }
.fp-card-t .ext { font-size:11px; opacity:.5; }
.fp-card-d { font-size:12.5px; color:var(--sa-ink-3); margin-top:3px; line-height:1.4; }
.fp-card-go { flex:none; color:var(--sa-ink-4); font-size:16px; transition:transform .14s ease, color .14s ease; }
.fp-card:hover .fp-card-go { transform:translateX(3px); color:var(--sa-ink-2); }

@media (max-width:560px){ .fp-grid{ grid-template-columns:1fr; } }
</style>

<div class="fp-head">
    <div>
        <h1 class="fp-title">⭐ Pilotage Fondateur <span class="fp-seal">🏗️ FONDATEUR</span></h1>
        <div class="fp-sub">Tous vos outils de pilotage de la plateforme, regroupés au même endroit.</div>
    </div>
    <a href="/super-admin" class="sa-btn sa-btn-ghost sa-btn-sm">← Dashboard</a>
</div>

<?php foreach ($groups as $g): ?>
<div class="fp-group" style="--fp-accent:<?= h($g['accent']) ?>;">
    <div class="fp-group-label"><?= h($g['label']) ?></div>
    <div class="fp-grid">
        <?php foreach ($g['items'] as $it): ?>
        <a href="<?= h($it['url']) ?>" class="fp-card"<?= !empty($it['ext']) ? ' target="_blank" rel="noopener"' : '' ?>>
            <div class="fp-card-ic"><?= $it['ic'] ?></div>
            <div class="fp-card-b">
                <div class="fp-card-t"><?= h($it['title']) ?><?= !empty($it['ext']) ? ' <span class="ext">↗</span>' : '' ?></div>
                <div class="fp-card-d"><?= h($it['desc']) ?></div>
            </div>
            <div class="fp-card-go">→</div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php sa_render_foot(); ?>
