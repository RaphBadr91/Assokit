// =====================================
// ASSOKIT ADMIN BLOG - JS
// =====================================

document.addEventListener('DOMContentLoaded', () => {

    // CSRF token (récupéré du DOM)
    const csrfEl = document.getElementById('csrf-token');
    const CSRF = csrfEl ? csrfEl.value : '';

    // ----------------------------------------
    // 1. Génération d'article (formulaire libre)
    // ----------------------------------------
    const generateForm = document.getElementById('generate-form');
    const generateStatus = document.getElementById('generate-status');

    if (generateForm) {
        generateForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(generateForm);
            const data = {
                mode: 'free',
                _csrf: CSRF,
                topic_title: formData.get('topic_title'),
                category: formData.get('category'),
                keywords: formData.get('keywords'),
                briefing_extra: formData.get('briefing_extra'),
                is_published: formData.get('is_published') ? 1 : 0,
            };

            setStatus('loading', `<span class="spinner"></span> Génération en cours… (Claude rédige, ça peut prendre 30-90 secondes)`);
            const submitBtn = generateForm.querySelector('button[type=submit]');
            if (submitBtn) submitBtn.disabled = true;

            try {
                const res = await fetch('/admin-blog/api/generate-article.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: JSON.stringify(data),
                });
                const json = await res.json();
                if (!json.ok) throw new Error(json.error || 'Erreur inconnue');
                setStatus('success', `
                    ✅ <strong>Article créé !</strong><br>
                    📝 <strong>${escapeHtml(json.article.title)}</strong><br>
                    📊 ${json.article.word_count} mots · ${json.article.reading_time_min} min de lecture<br><br>
                    <a href="${json.edit_url}" class="btn-primary">✏️ Éditer</a>
                    <a href="${json.public_url}" target="_blank" class="btn-secondary">↗ Voir en ligne</a>
                `);
                generateForm.reset();
            } catch (err) {
                setStatus('error', `❌ ${escapeHtml(err.message || String(err))}`);
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    function setStatus(type, html) {
        if (!generateStatus) return;
        generateStatus.style.display = 'block';
        generateStatus.className = 'generate-status ' + type;
        generateStatus.innerHTML = html;
        generateStatus.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ----------------------------------------
    // 2. Génération depuis un sujet (file)
    // ----------------------------------------
    document.querySelectorAll('[data-generate-topic]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const topicId = btn.getAttribute('data-generate-topic');
            const li = btn.closest('li');
            if (!confirm('Lancer la génération de cet article via Claude ?')) return;
            btn.disabled = true;
            const oldText = btn.textContent;
            btn.innerHTML = '<span class="spinner"></span>';

            try {
                const res = await fetch('/admin-blog/api/generate-article.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: JSON.stringify({
                        mode: 'topic',
                        topic_id: parseInt(topicId, 10),
                        is_published: 1,
                        _csrf: CSRF,
                    }),
                });
                const json = await res.json();
                if (!json.ok) throw new Error(json.error || 'Erreur');
                if (li) {
                    li.style.transition = 'opacity 0.4s';
                    li.style.opacity = '0.4';
                    li.querySelector('strong').innerHTML += ` <span class="badge badge-ok">✓ Généré</span>`;
                }
                btn.textContent = '✓ Créé';
                setTimeout(() => {
                    if (confirm(`Article créé : ${json.article.title}\n\nVoir l'éditeur ?`)) {
                        window.location.href = json.edit_url;
                    }
                }, 200);
            } catch (err) {
                alert('Erreur : ' + (err.message || err));
                btn.disabled = false;
                btn.textContent = oldText;
            }
        });
    });

    // ----------------------------------------
    // 3. Suppression d'article
    // ----------------------------------------
    document.querySelectorAll('[data-delete-slug]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const slug = btn.getAttribute('data-delete-slug');
            const title = btn.getAttribute('data-delete-title') || slug;
            if (!confirm(`⚠️ Supprimer définitivement « ${title} » ?\n\nCette action est irréversible.`)) return;

            try {
                const res = await fetch('/admin-blog/api/delete-article.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: JSON.stringify({ slug, _csrf: CSRF }),
                });
                const json = await res.json();
                if (!json.ok) throw new Error(json.error || 'Erreur');

                // Si on est sur la page d'édition → redirection
                if (window.location.pathname.includes('article-edit.php')) {
                    window.location.href = '/admin-blog/articles.php';
                    return;
                }
                // Sinon retire la ligne du tableau
                const row = btn.closest('tr');
                if (row) {
                    row.style.transition = 'opacity 0.3s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
            } catch (err) {
                alert('Erreur : ' + (err.message || err));
            }
        });
    });

    // ----------------------------------------
    // 4. Helpers
    // ----------------------------------------
    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = String(s ?? '');
        return div.innerHTML;
    }
});
