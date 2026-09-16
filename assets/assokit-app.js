(function () {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('overlay');
  var toggle = document.getElementById('menu-toggle');
  function open() { sidebar.classList.add('open'); overlay.classList.add('open'); }
  function close() { sidebar.classList.remove('open'); overlay.classList.remove('open'); }
  if (toggle) toggle.addEventListener('click', open);
  if (overlay) overlay.addEventListener('click', close);
  document.querySelectorAll('.sb-link').forEach(function (link) {
    link.addEventListener('click', function () { if (window.innerWidth < 900) close(); });
  });
  // Accordéon dossier (pour la page projets)
  document.querySelectorAll('.folder-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { btn.closest('.folder').classList.toggle('open'); });
  });
})();

// ============================================================
// SYSTÈME DE NOTIFICATIONS (style Facebook)
// ============================================================
(function() {
    var badge = document.getElementById('sbNotifBadge');
    var toastContainer = document.getElementById('notifToastContainer');
    if (!badge || !toastContainer) return;
    
    // ID des notifs déjà vues (pour ne pas afficher 2x le même toast)
    var seenIds = new Set();
    var firstLoad = true;
    var POLL_INTERVAL = 30000; // 30 secondes
    
    function showToast(notif) {
        if (seenIds.has(notif.id)) return;
        seenIds.add(notif.id);
        
        var toast = document.createElement('a');
        toast.href = notif.link_url || '#';
        toast.className = 'notif-toast';
        toast.dataset.notifId = notif.id;
        
        var iconHtml = '';
        if (notif.actor) {
            // Avatar de l'auteur
            iconHtml = '<div class="notif-toast-icon" style="background:' + colorToHex(notif.actor.color) + ';">' + 
                escHtml(notif.actor.initials) + '</div>';
        } else {
            iconHtml = '<div class="notif-toast-icon" style="background:' + notif.color + ';">' + notif.icon + '</div>';
        }
        
        toast.innerHTML = 
            iconHtml +
            '<div class="notif-toast-body">' +
                '<div class="notif-toast-title">' + escHtml(notif.title) + '</div>' +
                (notif.body ? '<div class="notif-toast-text">' + escHtml(notif.body) + '</div>' : '') +
                '<div class="notif-toast-time">' + escHtml(notif.time_ago) + '</div>' +
            '</div>' +
            '<button type="button" class="notif-toast-close" onclick="event.preventDefault(); event.stopPropagation(); this.parentElement.classList.add(\'hide\'); setTimeout(()=>this.parentElement.remove(),300);">×</button>';
        
        toastContainer.appendChild(toast);
        // Trigger animation
        setTimeout(function(){ toast.classList.add('show'); }, 10);
        
        // Auto-hide après 6s
        setTimeout(function() {
            if (toast.parentNode) {
                toast.classList.add('hide');
                setTimeout(function(){ if (toast.parentNode) toast.remove(); }, 300);
            }
        }, 6000);
        
        // Marquer comme lu au clic (avant de naviguer)
        toast.addEventListener('click', function(e) {
            var notifId = this.dataset.notifId;
            try {
                var fd = new FormData();
                fd.append('csrf_token', getCsrfToken());
                fd.append('action', 'mark_one');
                fd.append('notif_id', notifId);
                fetch('/action-mark-read', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd, keepalive: true
                });
            } catch(_){}
        });
    }
    
    function escHtml(s) {
        if (s === null || s === undefined) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    
    function colorToHex(color) {
        var map = { blue: '#3B82F6', purple: '#8B5CF6', amber: '#F59E0B', pink: '#EC4899', teal: '#14B8A6', green: '#10B981', red: '#EF4444', indigo: '#6366F1' };
        return map[color] || '#3B82F6';
    }
    
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.content;
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }
    
    function updateBadge(count) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'inline-flex';
            badge.style.background = '#EF4444';
            badge.style.color = '#fff';
            badge.style.fontWeight = '600';
        } else {
            badge.style.display = 'none';
        }
    }
    
    function pollNotifications() {
        if (document.hidden) return;
        
        fetch('/api-notifications.php?action=count', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(data) {
            if (!data || !data.ok) return;
            var newCount = data.unread_notifs || 0;
            updateBadge(newCount);
            // 🔔 Son + favicon badge si nouvelle notif
            if (newCount > lastUnreadCount && !firstLoad) {
                playDing();
                updateFavicon(newCount);
            } else if (newCount > 0 && document.hidden) {
                updateFavicon(newCount);
            } else if (newCount === 0) {
                updateFavicon(0);
            }
            lastUnreadCount = newCount;
            
            // Si nouvelles notifs, on récupère les détails pour afficher des toasts
            if (data.unread_notifs > 0 && !firstLoad) {
                fetchAndShowToasts();
            }
            firstLoad = false;
        })
        .catch(function(){});
    }
    
    // 🔔 SON DING (WebAudio, pas de fichier externe)
    var lastUnreadCount = 0;
    var soundEnabled = (localStorage.getItem('ak_sound') !== '0');
    function playDing() {
        if (!soundEnabled) return;
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var now = ctx.currentTime;
            // 2 notes : do-mi montant
            [880, 1175].forEach(function(freq, i) {
                var o = ctx.createOscillator();
                var g = ctx.createGain();
                o.type = 'sine';
                o.frequency.value = freq;
                g.gain.setValueAtTime(0, now + i*0.08);
                g.gain.linearRampToValueAtTime(0.18, now + i*0.08 + 0.01);
                g.gain.exponentialRampToValueAtTime(0.001, now + i*0.08 + 0.25);
                o.connect(g); g.connect(ctx.destination);
                o.start(now + i*0.08); o.stop(now + i*0.08 + 0.3);
            });
        } catch(e) {}
    }
    
    // 🔴 FAVICON BADGE (overlay rouge sur favicon natif)
    var origFavicon = null;
    function getFavicon() {
        var l = document.querySelector('link[rel*="icon"]');
        if (!l) { l = document.createElement('link'); l.rel = 'icon'; document.head.appendChild(l); }
        if (origFavicon === null) origFavicon = l.href;
        return l;
    }
    function updateFavicon(count) {
        var link = getFavicon();
        if (count <= 0) { link.href = origFavicon; document.title = document.title.replace(/^\(\d+\+?\)\s*/, ''); return; }
        // Dessine pastille sur canvas 32×32
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            var canvas = document.createElement('canvas');
            canvas.width = 32; canvas.height = 32;
            var c = canvas.getContext('2d');
            try { c.drawImage(img, 0, 0, 32, 32); } catch(e) {}
            // Pastille rouge
            c.fillStyle = '#EF4444';
            c.beginPath(); c.arc(24, 8, 8, 0, Math.PI*2); c.fill();
            c.fillStyle = '#fff';
            c.font = 'bold 11px sans-serif';
            c.textAlign = 'center'; c.textBaseline = 'middle';
            c.fillText(count > 9 ? '9+' : String(count), 24, 9);
            try { link.href = canvas.toDataURL('image/png'); } catch(e) {}
        };
        img.onerror = function() {
            // fallback : pastille seule
            var canvas = document.createElement('canvas');
            canvas.width = 32; canvas.height = 32;
            var c = canvas.getContext('2d');
            c.fillStyle = '#EF4444';
            c.beginPath(); c.arc(16, 16, 14, 0, Math.PI*2); c.fill();
            c.fillStyle = '#fff'; c.font = 'bold 16px sans-serif';
            c.textAlign = 'center'; c.textBaseline = 'middle';
            c.fillText(count > 9 ? '9+' : String(count), 16, 17);
            link.href = canvas.toDataURL('image/png');
        };
        img.src = origFavicon || '/favicon.ico';
        // Title aussi
        var t = document.title.replace(/^\(\d+\+?\)\s*/, '');
        document.title = '(' + (count > 9 ? '9+' : count) + ') ' + t;
    }
    
    function fetchAndShowToasts() {
        fetch('/api-notifications.php?action=list&unread=1&limit=5', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(data) {
            if (!data || !data.ok || !data.notifications) return;
            // Afficher les nouveaux toasts (les autres sont déjà dans seenIds)
            data.notifications.forEach(function(n) {
                if (!n.is_read && !seenIds.has(n.id)) {
                    showToast(n);
                    // 🔔 Notification native OS (si autorisée + onglet pas focus)
                    showNativeNotif(n);
                }
            });
        })
        .catch(function(){});
    }

    // ============ PUSH NATIVES (Notification API) ============
    var pushEnabled = (localStorage.getItem('ak_push') === '1');
    function showNativeNotif(n) {
        if (!pushEnabled || !('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;
        if (document.visibilityState === 'visible' && document.hasFocus()) return; // Onglet actif → toast suffit
        try {
            var title = (n.title || 'AssoKit') + '';
            var body  = (n.body || n.message || '') + '';
            var url   = n.url || n.link || '/notifications';
            var notif = new Notification(title, {
                body: body.substring(0, 180),
                icon: '/icons/icon-192.png',
                badge: '/icons/icon-192.png',
                tag: 'ak-' + n.id,
                requireInteraction: false,
                silent: !soundEnabled
            });
            notif.onclick = function() {
                window.focus();
                if (url) window.location.href = url;
                notif.close();
            };
            // Auto-close après 8s
            setTimeout(function(){ try { notif.close(); } catch(e){} }, 8000);
        } catch(e) {}
    }

    function akAskPushPermission(silent) {
        if (!('Notification' in window)) {
            if (!silent) alert('Ton navigateur ne supporte pas les notifications natives.');
            return;
        }
        if (Notification.permission === 'denied') {
            if (!silent) alert('Notifications bloquées. Pour les autoriser : clique sur l\'icône 🔒 à gauche de l\'URL → Notifications → Autoriser.');
            return;
        }
        if (Notification.permission === 'granted') {
            pushEnabled = !pushEnabled;
            localStorage.setItem('ak_push', pushEnabled ? '1' : '0');
            refreshPushToggle();
            if (pushEnabled && !silent) {
                try { new Notification('🎉 Notifications activées', { body: 'Tu recevras une notif système pour chaque alerte AssoKit.', icon: '/icons/icon-192.png' }); } catch(e){}
            }
            return;
        }
        Notification.requestPermission().then(function(p) {
            if (p === 'granted') {
                pushEnabled = true;
                localStorage.setItem('ak_push', '1');
                refreshPushToggle();
                try { new Notification('🎉 Notifications activées', { body: 'Tu recevras une notif système pour chaque alerte AssoKit.', icon: '/icons/icon-192.png' }); } catch(e){}
            }
        });
    }
    function refreshPushToggle() {
        var on = document.querySelector('.ak-push-on');
        var off = document.querySelector('.ak-push-off');
        var allowed = ('Notification' in window) && Notification.permission === 'granted' && pushEnabled;
        if (on)  on.hidden  = !allowed;
        if (off) off.hidden = allowed;
    }
    window.akTogglePush = akAskPushPermission;
    
    // Au premier chargement, on remplit seenIds avec les notifs déjà visibles
    // pour éviter de spammer de toasts
    function initSeenIds() {
        fetch('/api-notifications.php?action=list&limit=20', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(data) {
            if (!data || !data.ok) return;
            data.notifications.forEach(function(n) { seenIds.add(n.id); });
        })
        .catch(function(){});
    }
    
    // Démarrage
    initSeenIds();
    refreshPushToggle();
    setTimeout(pollNotifications, 2000);
    setInterval(pollNotifications, POLL_INTERVAL);
    
    // Reprise immédiate quand on revient sur l'onglet
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            pollNotifications();
            // Quand l'utilisateur revient, on reset le favicon (les notifs ne sont plus "nouvelles")
            updateFavicon(0);
        }
    });
    
    // Toggle son : exposé globalement
    function refreshSoundToggle() {
        var on = document.querySelector('.ak-sound-on');
        var off = document.querySelector('.ak-sound-off');
        if (on && off) { on.hidden = !soundEnabled; off.hidden = soundEnabled; }
    }
    window.akToggleSound = function() {
        soundEnabled = !soundEnabled;
        localStorage.setItem('ak_sound', soundEnabled ? '1' : '0');
        refreshSoundToggle();
        if (soundEnabled) playDing();
    };
    refreshSoundToggle();
})();
