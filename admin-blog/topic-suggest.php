<?php
/**
 * topic-suggest.php — Suggestion de 10 sujets par IA depuis un thème
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/article-helper.php';

send_security_headers();
auth_start_session();
auth_require();

$page_title = 'Suggérer des sujets · IA';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div>
            <h1>🤖 Suggérer des sujets par IA</h1>
            <p class="dim">Tape un thème, Claude te propose 10 sujets d'articles SEO. Tu choisis ceux qui te plaisent → ils partent en file d'attente pour génération automatique.</p>
        </div>
        <a href="/admin-blog/topics.php" class="btn-ghost-sm">← Retour aux sujets</a>
    </div>

    <!-- Formulaire de génération -->
    <div class="card">
        <form id="suggest-form">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

            <label class="form-label">
                Thème ou sujet général <span class="dim">(optionnel)</span>
                <input type="text" id="theme-input" name="theme" maxlength="200"
                       placeholder="Ex : Stratégies de levée de fonds pour assos · Gestion comptable d'une TPE · Communication réseaux sociaux pour assos sportives"
                       autofocus>
                <small class="dim">💡 <strong>Laisse vide</strong> pour que l'IA propose les meilleures opportunités SEO d'Assokit (stratégie Search Console : clusters prioritaires, quick wins, saisonnalité). Ou saisis un thème précis pour cadrer.</small>
            </label>

            <div class="form-row">
                <label class="form-label" style="flex:1;">
                    Catégorie souhaitée (optionnel)
                    <select id="category-input" name="category">
                        <option value="">Toutes (Claude choisit la plus adaptée)</option>
                        <?php foreach (CATEGORIES as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars(CATEGORY_LABELS[$c]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="form-label" style="width:140px;">
                    Nombre
                    <select id="count-input" name="count">
                        <option value="5">5 sujets</option>
                        <option value="10" selected>10 sujets</option>
                        <option value="15">15 sujets</option>
                    </select>
                </label>
            </div>

            <button type="submit" id="submit-btn" class="btn-primary btn-block">
                ✨ Générer les suggestions
            </button>
        </form>
    </div>

    <!-- Zone résultats -->
    <div id="results-zone" style="margin-top: 24px;"></div>
</div>

<style>
.suggest-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding: 14px 18px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    flex-wrap: wrap;
    gap: 12px;
}
.suggest-toolbar h3 {
    margin: 0;
    font-size: 16px;
    color: white;
}
.suggest-toolbar-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.suggest-card {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 18px;
    margin-bottom: 12px;
    transition: all 0.15s;
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.suggest-card:hover {
    border-color: #667eea;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
}
.suggest-card.selected {
    border-color: #38a169;
    background: #f0fff4;
}
.suggest-card.added {
    border-color: #cbd5e0;
    background: #f7fafc;
    opacity: 0.6;
}
.suggest-checkbox {
    margin-top: 4px;
    width: 20px;
    height: 20px;
    cursor: pointer;
    flex-shrink: 0;
}
.suggest-body { flex: 1; }
.suggest-title {
    font-weight: 600;
    color: #1a1a2e;
    margin: 0 0 6px;
    font-size: 15px;
    line-height: 1.4;
}
.suggest-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 8px;
    font-size: 12px;
    color: #4a5568;
}
.suggest-cat-pill {
    display: inline-block;
    padding: 2px 9px;
    background: #edf2f7;
    border-radius: 999px;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.suggest-keywords { color: #718096; font-size: 12px; }
.suggest-briefing {
    color: #4a5568;
    font-size: 13px;
    line-height: 1.5;
    margin: 6px 0 0;
    padding: 8px 10px;
    background: #f7fafc;
    border-left: 3px solid #cbd5e0;
    border-radius: 0 6px 6px 0;
}
.suggest-action-btn {
    padding: 6px 12px;
    background: #38a169;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    flex-shrink: 0;
}
.suggest-action-btn:hover { background: #2f855a; }
.suggest-action-btn:disabled { background: #cbd5e0; cursor: default; }
.suggest-loading {
    text-align: center;
    padding: 40px 20px;
    color: #718096;
}
.suggest-spinner {
    display: inline-block;
    width: 32px;
    height: 32px;
    border: 3px solid #e2e8f0;
    border-top-color: #667eea;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 12px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.btn-toolbar {
    padding: 6px 12px;
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
}
.btn-toolbar:hover { background: rgba(255, 255, 255, 0.3); }
.btn-toolbar-primary {
    background: white;
    color: #667eea;
    border-color: white;
}
.btn-toolbar-primary:hover { background: #f7fafc; }

@media (max-width: 640px) {
    .suggest-card { flex-wrap: wrap; }
    .suggest-action-btn { width: 100%; margin-top: 8px; }
    .form-row { flex-direction: column; }
    .form-row label { width: 100% !important; }
}
</style>

<script>
(function() {
    const form        = document.getElementById('suggest-form');
    const resultsZone = document.getElementById('results-zone');
    const submitBtn   = document.getElementById('submit-btn');

    let currentSuggestions = [];

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const theme    = document.getElementById('theme-input').value.trim();
        const category = document.getElementById('category-input').value;
        const count    = parseInt(document.getElementById('count-input').value, 10) || 10;
        const csrf     = form.querySelector('[name=_csrf]').value;

        if (theme.length > 0 && theme.length < 3) return;

        // Loading state
        const loadingScope = theme ? `sur "${escapeHtml(theme)}"` : 'à partir de la stratégie SEO Assokit';
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Claude réfléchit...';
        resultsZone.innerHTML = `
            <div class="suggest-loading">
                <div class="suggest-spinner"></div>
                <p>Claude génère ${count} sujets ${loadingScope}...<br>
                <small>Patiente 10-30 secondes</small></p>
            </div>`;

        try {
            const res = await fetch('/admin-blog/api/suggest-topics.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _csrf: csrf, theme, category, count }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erreur inconnue');

            currentSuggestions = data.suggestions || [];
            renderResults(currentSuggestions);
        } catch (err) {
            resultsZone.innerHTML = `<div class="alert alert-error">❌ ${escapeHtml(err.message)}</div>`;
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '✨ Générer les suggestions';
        }
    });

    function renderResults(items) {
        if (!items.length) {
            resultsZone.innerHTML = '<div class="alert alert-info">Aucune suggestion générée.</div>';
            return;
        }

        let html = `
            <div class="suggest-toolbar">
                <h3>💡 ${items.length} suggestions générées</h3>
                <div class="suggest-toolbar-actions">
                    <button type="button" class="btn-toolbar" onclick="window.toggleAllSuggest(true)">☑ Tout cocher</button>
                    <button type="button" class="btn-toolbar" onclick="window.toggleAllSuggest(false)">☐ Tout décocher</button>
                    <button type="button" class="btn-toolbar btn-toolbar-primary" id="add-selected-btn" onclick="window.addSelected()">
                        ✓ Ajouter à la file
                    </button>
                </div>
            </div>
        `;

        items.forEach((item, idx) => {
            html += `
                <div class="suggest-card" data-idx="${idx}">
                    <input type="checkbox" class="suggest-checkbox" data-idx="${idx}" checked
                           onchange="window.toggleCard(${idx})">
                    <div class="suggest-body">
                        <p class="suggest-title">${escapeHtml(item.title)}</p>
                        <div class="suggest-meta">
                            <span class="suggest-cat-pill">${escapeHtml(item.category)}</span>
                            <span class="suggest-keywords">🏷 ${escapeHtml(item.keywords || '')}</span>
                            <span>⚡ Priorité ${item.priority || 5}</span>
                        </div>
                        ${item.briefing ? `<p class="suggest-briefing">💡 ${escapeHtml(item.briefing)}</p>` : ''}
                    </div>
                    <button type="button" class="suggest-action-btn" data-idx="${idx}"
                            onclick="window.addSingle(${idx})">
                        ✓ Ajouter
                    </button>
                </div>
            `;
        });

        resultsZone.innerHTML = html;
        // Marquer toutes les cards comme selected par défaut
        document.querySelectorAll('.suggest-card').forEach(c => c.classList.add('selected'));
    }

    window.toggleCard = function(idx) {
        const card = document.querySelector(`.suggest-card[data-idx="${idx}"]`);
        const cb = document.querySelector(`.suggest-checkbox[data-idx="${idx}"]`);
        if (!card || !cb) return;
        card.classList.toggle('selected', cb.checked);
    };

    window.toggleAllSuggest = function(state) {
        document.querySelectorAll('.suggest-checkbox').forEach(cb => {
            if (cb.disabled) return;
            cb.checked = state;
            const idx = parseInt(cb.dataset.idx, 10);
            window.toggleCard(idx);
        });
    };

    window.addSingle = async function(idx) {
        const item = currentSuggestions[idx];
        if (!item) return;
        const btn = document.querySelector(`.suggest-action-btn[data-idx="${idx}"]`);
        const card = document.querySelector(`.suggest-card[data-idx="${idx}"]`);
        if (!btn || !card) return;

        btn.disabled = true;
        btn.textContent = '⏳';

        try {
            const csrf = form.querySelector('[name=_csrf]').value;
            const res = await fetch('/admin-blog/api/add-topics-bulk.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _csrf: csrf, topics: [item] }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erreur');

            btn.textContent = '✅ Ajouté';
            card.classList.add('added');
            card.classList.remove('selected');
            const cb = card.querySelector('.suggest-checkbox');
            if (cb) { cb.checked = false; cb.disabled = true; }
        } catch (err) {
            btn.disabled = false;
            btn.textContent = '✓ Ajouter';
            alert('Erreur : ' + err.message);
        }
    };

    window.addSelected = async function() {
        const checked = Array.from(document.querySelectorAll('.suggest-checkbox:checked:not(:disabled)'))
            .map(cb => parseInt(cb.dataset.idx, 10))
            .map(idx => currentSuggestions[idx])
            .filter(Boolean);

        if (!checked.length) {
            alert('Aucun sujet sélectionné.');
            return;
        }

        const btn = document.getElementById('add-selected-btn');
        btn.disabled = true;
        btn.textContent = '⏳ Ajout...';

        try {
            const csrf = form.querySelector('[name=_csrf]').value;
            const res = await fetch('/admin-blog/api/add-topics-bulk.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _csrf: csrf, topics: checked }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Erreur');

            // Marquer comme ajoutés
            document.querySelectorAll('.suggest-checkbox:checked:not(:disabled)').forEach(cb => {
                const idx = parseInt(cb.dataset.idx, 10);
                const card = document.querySelector(`.suggest-card[data-idx="${idx}"]`);
                const ab = document.querySelector(`.suggest-action-btn[data-idx="${idx}"]`);
                if (card) { card.classList.add('added'); card.classList.remove('selected'); }
                if (ab) { ab.disabled = true; ab.textContent = '✅ Ajouté'; }
                cb.checked = false;
                cb.disabled = true;
            });

            btn.textContent = `🎉 ${data.added} ajouté(s) !`;
            setTimeout(() => {
                btn.disabled = false;
                btn.textContent = '✓ Ajouter à la file';
            }, 2500);
        } catch (err) {
            btn.disabled = false;
            btn.textContent = '✓ Ajouter à la file';
            alert('Erreur : ' + err.message);
        }
    };

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({
            '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
        }[c]));
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
