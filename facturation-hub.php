<?php
/**
 * facturation-hub.php
 * --------------------------------------------------------------
 * En-tête + barre d'onglets partagée pour le module Facturation.
 * Regroupe Factures / Devis / Clients / Statistiques en une seule
 * "page" à onglets (Liquid Glass 2.0).
 *
 * Usage (dans chaque page, juste après l'ouverture de .main) :
 *   require_once __DIR__ . '/facturation-hub.php';
 *   render_facturation_hub($pdo, $org_id, 'factures', [
 *       'actions' => '<a href="/mon-asso-facture-new" class="fh-btn fh-btn-primary">…</a>',
 *   ]);
 *
 * N.B. Ne modifie AUCUNE logique de permission : les pages gèrent
 * elles-mêmes leurs contrôles d'accès (admin/founder/super_admin).
 * --------------------------------------------------------------
 */

if (!function_exists('render_facturation_hub')) {

    /**
     * Formate un montant en centimes → "1 234 €" (fallback si helper absent).
     */
    function _fh_fmt_cents(int $cents): string
    {
        if (function_exists('ak_asso_fmt_cents')) {
            return ak_asso_fmt_cents($cents);
        }
        return number_format($cents / 100, 0, ',', ' ') . ' €';
    }

    function render_facturation_hub(PDO $pdo, int $org_id, string $active, array $opts = []): void
    {
        // -- KPIs globaux (facturation clients) --------------------------
        $k = [
            'revenue_paid_cents'    => 0,
            'revenue_pending_cents' => 0,
            'total_invoices'        => 0,
            'nb_pending'            => 0,
            'nb_overdue'            => 0,
            'total_quotes'          => 0,
            'total_quoted_cents'    => 0,
            'year'                  => (int)date('Y'),
        ];
        if (!function_exists('ak_stats_global_kpis')) {
            @require_once __DIR__ . '/asso-invoice-helpers.php';
            @require_once __DIR__ . '/asso-stats-helpers.php';
        }
        if (function_exists('ak_stats_global_kpis')) {
            try { $k = array_merge($k, ak_stats_global_kpis($pdo, $org_id)); } catch (Throwable $e) {}
        }

        // Nombre de clients actifs
        $nb_clients = 0;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_clients WHERE org_id = :o AND deleted_at IS NULL");
            $st->execute([':o' => $org_id]);
            $nb_clients = (int)$st->fetchColumn();
        } catch (Throwable $e) {}

        $ca_cents      = (int)$k['revenue_paid_cents'] + (int)$k['revenue_pending_cents'];
        $paid_cents    = (int)$k['revenue_paid_cents'];
        $pending_cents = (int)$k['revenue_pending_cents'];
        $nb_waiting    = (int)$k['nb_pending'] + (int)$k['nb_overdue'];
        $paid_pct      = $ca_cents > 0 ? round($paid_cents / $ca_cents * 100) : 0;
        $year          = (int)$k['year'];

        // Onglets
        $tabs = [
            'factures' => ['/mon-asso-factures', 'Factures',      (int)$k['total_invoices'],
                '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>'],
            'devis'    => ['/mon-asso-devis',    'Devis',         (int)$k['total_quotes'],
                '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 12h6M9 16h6M9 8h2"/>'],
            'clients'  => ['/mon-asso-clients',  'Clients',       $nb_clients,
                '<circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 6a3 3 0 0 1 0 5"/>'],
            'stats'    => ['/mon-asso-stats',    'Statistiques',  null,
                '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>'],
        ];

        $actions_html = (string)($opts['actions'] ?? '');

        // -- CSS (une seule fois) ---------------------------------------
        static $css_done = false;
        if (!$css_done):
            $css_done = true;
        ?>
        <style>
        /* ===== Facturation Hub — Liquid Glass 2.0 ===== */
        .fh-wrap { margin-bottom: 18px; }
        .fh-crumb { display:flex; align-items:center; gap:7px; font-size:12.5px; color:var(--ink-3); margin-bottom:12px; }
        .fh-crumb b { color:var(--ink-2); font-weight:600; }
        .fh-crumb svg { width:13px; height:13px; stroke:var(--ink-4); fill:none; stroke-width:2; }
        .fh-head { display:flex; align-items:flex-end; justify-content:space-between; gap:18px; flex-wrap:wrap; margin-bottom:18px; }
        .fh-title { font-size:30px; font-weight:800; letter-spacing:-.03em; line-height:1; color:var(--ink); margin:0; }
        .fh-sub { color:var(--ink-2); font-size:13.5px; margin-top:10px; display:flex; align-items:center; gap:8px; }
        .fh-sub .fh-dot { width:6px; height:6px; border-radius:50%; background:var(--acc); box-shadow:0 0 0 4px var(--acc-light); flex:none; }
        .fh-actions { display:flex; gap:10px; flex-wrap:wrap; }
        .fh-btn { display:inline-flex; align-items:center; gap:8px; padding:11px 16px; border-radius:var(--radius); font-size:13.5px; font-weight:650; cursor:pointer; border:0; text-decoration:none; color:var(--ink); white-space:nowrap; transition:transform .12s ease, box-shadow .12s ease; }
        .fh-btn svg { width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2; }
        .fh-btn-ghost { background:var(--glass); border:1px solid var(--glass-border); backdrop-filter:blur(12px) saturate(1.4); -webkit-backdrop-filter:blur(12px) saturate(1.4); box-shadow:var(--shadow-card); }
        .fh-btn-ghost:hover { transform:translateY(-1px); }
        .fh-btn-primary { background:linear-gradient(140deg,#10B981,#059669); color:#fff; box-shadow:0 10px 22px -8px rgba(5,150,105,.6), inset 0 1px 0 rgba(255,255,255,.35); }
        .fh-btn-primary svg { stroke:#fff; }
        .fh-btn-primary:hover { transform:translateY(-1px); }

        .fh-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:18px; }
        .fh-kpi { position:relative; overflow:hidden; padding:16px 18px; border-radius:var(--radius-lg); background:var(--glass); border:1px solid var(--glass-border); backdrop-filter:blur(22px) saturate(1.5); -webkit-backdrop-filter:blur(22px) saturate(1.5); box-shadow:var(--shadow-card); }
        .fh-kpi .fh-glow { position:absolute; inset:0 0 auto 0; height:3px; }
        .fh-kpi .fh-lab { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-3); }
        .fh-kpi .fh-num { font-size:26px; font-weight:800; letter-spacing:-.03em; margin-top:9px; line-height:1; color:var(--ink); font-variant-numeric:tabular-nums; }
        .fh-kpi .fh-cap { font-size:11.5px; color:var(--ink-3); margin-top:7px; }
        .fh-k-green .fh-glow { background:linear-gradient(90deg,#34D399,#059669); } .fh-k-green .fh-num { color:var(--acc); }
        .fh-k-blue  .fh-glow { background:linear-gradient(90deg,#60A5FA,#2F73E8); } .fh-k-blue  .fh-num { color:#2F73E8; }
        .fh-k-amber .fh-glow { background:linear-gradient(90deg,#FBBF24,#E0850C); } .fh-k-amber .fh-num { color:#E0850C; }
        .fh-k-ai    .fh-glow { background:linear-gradient(90deg,#8B5CF6,#6366F1); } .fh-k-ai    .fh-num { color:var(--ai); }

        .fh-tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin-bottom:20px; flex-wrap:wrap; }
        .fh-tab { display:inline-flex; align-items:center; gap:8px; padding:13px 16px; font-size:14px; font-weight:600; color:var(--ink-3); cursor:pointer; text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-1px; transition:color .12s ease; }
        .fh-tab svg { width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2; }
        .fh-tab:hover { color:var(--ink); }
        .fh-tab.on { color:var(--acc-dark); border-bottom-color:var(--acc); }
        .fh-tab .fh-b { font-size:11px; font-weight:700; background:var(--acc-light); color:var(--acc-dark); padding:1px 7px; border-radius:999px; }
        .fh-tab.on .fh-b { background:var(--acc); color:#fff; }

        @media (max-width:900px){ .fh-kpis{ grid-template-columns:1fr 1fr; } .fh-title{ font-size:25px; } }
        @media (max-width:560px){ .fh-kpis{ grid-template-columns:1fr 1fr; } }

        /* ===== Harmonisation du corps des pages Facturation (premium & cohérent) ===== */
        .main .card {
          background: var(--glass) !important; border: 1px solid var(--glass-border) !important;
          border-radius: var(--radius-lg) !important; box-shadow: var(--shadow-card) !important;
          backdrop-filter: blur(20px) saturate(1.5); -webkit-backdrop-filter: blur(20px) saturate(1.5);
        }
        .main .ak-table-wrap, .main .ak-filters {
          background: var(--glass) !important; border: 1px solid var(--glass-border) !important;
          border-radius: var(--radius-lg) !important; box-shadow: var(--shadow-card) !important;
          backdrop-filter: blur(20px) saturate(1.5); -webkit-backdrop-filter: blur(20px) saturate(1.5);
        }
        /* Tableaux */
        .main table thead, .main .ak-table thead { background: transparent !important; border-bottom: 1px solid var(--border) !important; }
        .main table thead th, .main .ak-table thead th {
          color: var(--ink-3) !important; font-weight: 700 !important; font-size: 11px !important;
          text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid var(--border) !important;
        }
        .main table tbody tr, .main .ak-table tbody tr { border-top: 1px solid var(--border) !important; }
        .main table tbody tr:hover, .main .ak-table tbody tr:hover { background: var(--bg-2) !important; }

        /* Recherche & filtres — style unifié */
        .fac-search { width: 100%; padding: 12px 16px 12px 16px; border-radius: 12px; border: 1px solid var(--glass-border); background: var(--glass); box-shadow: var(--shadow-card); font-size: 14px; font-family: inherit; color: var(--ink); backdrop-filter: blur(12px) saturate(1.4); -webkit-backdrop-filter: blur(12px) saturate(1.4); transition: border-color .14s ease, box-shadow .14s ease; }
        .fac-search::placeholder { color: var(--ink-4); }
        .fac-search:focus { outline: none; border-color: var(--acc); box-shadow: 0 0 0 3px var(--acc-light); }
        .fac-chip { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px; font-size: 12.5px; font-weight: 600; text-decoration: none; background: var(--glass); border: 1px solid var(--glass-border); color: var(--ink-2); box-shadow: var(--shadow-card); backdrop-filter: blur(10px); transition: transform .12s ease; }
        .fac-chip:hover { transform: translateY(-1px); color: var(--ink); }
        .fac-chip.on { background: var(--acc); border-color: transparent; color: #fff; }
        .fac-label { font-size: 11px; color: var(--ink-3); text-transform: uppercase; letter-spacing: .05em; font-weight: 700; margin-bottom: 8px; }

        /* KPIs "performance" (page Statistiques) */
        .fstat-kpis { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 18px; }
        .fstat-kpi { position: relative; overflow: hidden; padding: 16px 18px; border-radius: var(--radius-lg); background: var(--glass); border: 1px solid var(--glass-border); box-shadow: var(--shadow-card); backdrop-filter: blur(22px) saturate(1.5); -webkit-backdrop-filter: blur(22px) saturate(1.5); }
        .fstat-kpi .g { position: absolute; inset: 0 0 auto 0; height: 3px; }
        .fstat-kpi .l { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--ink-3); }
        .fstat-kpi .n { font-size: 26px; font-weight: 800; letter-spacing: -.03em; margin-top: 9px; line-height: 1; font-variant-numeric: tabular-nums; }
        .fstat-kpi .c { font-size: 11.5px; color: var(--ink-3); margin-top: 7px; }
        @media (max-width:900px){ .fstat-kpis{ grid-template-columns:1fr 1fr; } }
        </style>
        <?php endif; ?>

        <div class="fh-wrap">
          <div class="fh-crumb">
            <a href="/dashboard">Dashboard</a>
            <svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
            <b>Facturation</b>
          </div>

          <div class="fh-head">
            <div>
              <h1 class="fh-title">Facturation</h1>
              <div class="fh-sub"><span class="fh-dot"></span> Factures, devis, clients et statistiques — au même endroit</div>
            </div>
            <?php if ($actions_html !== ''): ?>
              <div class="fh-actions"><?= $actions_html ?></div>
            <?php endif; ?>
          </div>

          <section class="fh-kpis">
            <div class="fh-kpi fh-k-green"><span class="fh-glow"></span>
              <div class="fh-lab">Chiffre d'affaires</div>
              <div class="fh-num"><?= h(_fh_fmt_cents($ca_cents)) ?></div>
              <div class="fh-cap"><?= $year ?> · facturé</div>
            </div>
            <div class="fh-kpi fh-k-blue"><span class="fh-glow"></span>
              <div class="fh-lab">Encaissé</div>
              <div class="fh-num"><?= h(_fh_fmt_cents($paid_cents)) ?></div>
              <div class="fh-cap"><?= $ca_cents > 0 ? $paid_pct . ' % du CA' : '—' ?></div>
            </div>
            <div class="fh-kpi fh-k-amber"><span class="fh-glow"></span>
              <div class="fh-lab">En attente</div>
              <div class="fh-num"><?= h(_fh_fmt_cents($pending_cents)) ?></div>
              <div class="fh-cap"><?= $nb_waiting ?> facture<?= $nb_waiting > 1 ? 's' : '' ?><?= (int)$k['nb_overdue'] > 0 ? ' · ' . (int)$k['nb_overdue'] . ' en retard' : '' ?></div>
            </div>
            <div class="fh-kpi fh-k-ai"><span class="fh-glow"></span>
              <div class="fh-lab">Devis</div>
              <div class="fh-num"><?= (int)$k['total_quotes'] ?></div>
              <div class="fh-cap"><?= h(_fh_fmt_cents((int)$k['total_quoted_cents'])) ?> potentiels</div>
            </div>
          </section>

          <nav class="fh-tabs">
            <?php foreach ($tabs as $key => $t):
                [$href, $label, $count, $icon] = $t;
                $on = ($active === $key);
            ?>
              <a href="<?= h($href) ?>" class="fh-tab <?= $on ? 'on' : '' ?>">
                <svg viewBox="0 0 24 24"><?= $icon ?></svg>
                <?= h($label) ?>
                <?php if ($count !== null): ?><span class="fh-b"><?= (int)$count ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </nav>
        </div>
        <?php
    }
}
