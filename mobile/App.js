import React, { useRef, useState, useEffect, useCallback } from 'react';
import {
  SafeAreaView,
  StyleSheet,
  View,
  Text,
  Image,
  TouchableOpacity,
  ActivityIndicator,
  BackHandler,
  Platform,
  StatusBar,
  Modal,
  Pressable,
  ScrollView,
  RefreshControl,
  TextInput,
  Switch,
  KeyboardAvoidingView,
  Alert,
} from 'react-native';
import { WebView } from 'react-native-webview';
import { LinearGradient } from 'expo-linear-gradient';
import { BlurView } from 'expo-blur';
import { Ionicons } from '@expo/vector-icons';
import * as Notifications from 'expo-notifications';
import * as ImagePicker from 'expo-image-picker';
import * as LocalAuthentication from 'expo-local-authentication';
import * as SecureStore from 'expo-secure-store';
import * as FileSystem from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import Constants from 'expo-constants';

const BASE = 'https://assokit.fr';
const BRAND = '#059669';
const INK = '#0F172A';
const MUTE = '#94A3B8';

const APP_ONLY_CSS = `
(function(){ try {
  if (!document.getElementById('ak-app-only-css')) {
    var s = document.createElement('style');
    s.id = 'ak-app-only-css';
    s.textContent = '.ak-trial-banner{display:none!important}#ak-pwa-banner{display:none!important}.sb-mobile-header{display:none!important}';
    (document.head || document.documentElement).appendChild(s);
  }
} catch(e){} })();
true;
`;

const OPEN_MENU_JS = `
(function(){ try {
  var sb = document.getElementById('sidebar');
  var ov = document.getElementById('overlay') || document.querySelector('.sb-overlay');
  if (sb) sb.classList.add('open');
  if (ov) ov.classList.add('active');
} catch(e){} })();
true;
`;

const FETCH_KPIS_JS = `
(function(){ try {
  fetch('/api/app-dashboard.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'include' })
    .then(function(r){ return r.json(); })
    .then(function(d){ window.ReactNativeWebView.postMessage(JSON.stringify({ __akkpi: d })); })
    .catch(function(){ window.ReactNativeWebView.postMessage(JSON.stringify({ __akkpi: { ok: false } })); });
} catch(e){} })();
true;
`;

const FETCH_PROJECTS_JS = `
(function(){ try {
  fetch('/api/app-projects.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'include' })
    .then(function(r){ return r.json(); })
    .then(function(d){ window.ReactNativeWebView.postMessage(JSON.stringify({ __akprojects: d })); })
    .catch(function(){ window.ReactNativeWebView.postMessage(JSON.stringify({ __akprojects: { ok: false } })); });
} catch(e){} })();
true;
`;

function fetchJS(endpoint, key) {
  return "(function(){ try { fetch('" + endpoint + "', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'include' })"
    + ".then(function(r){ return r.json(); })"
    + ".then(function(d){ window.ReactNativeWebView.postMessage(JSON.stringify({ " + key + ": d })); })"
    + ".catch(function(){ window.ReactNativeWebView.postMessage(JSON.stringify({ " + key + ": { ok: false } })); });"
    + " } catch(e){} })(); true;";
}

const FETCH_MEMBERS_JS = fetchJS('/api/app-members.php', '__akmembers');
const FETCH_CLIENTS_JS = fetchJS('/api/app-clients.php', '__akclients');
const FETCH_INVOICES_JS = fetchJS('/api/app-invoices.php', '__akinvoices');
const FETCH_CSRF_JS = fetchJS('/api/app-csrf.php', '__akcsrf');

// POST JSON depuis la WebView (meme session/cookies) -> renvoie sous la clé `key`
function postJS(endpoint, payload, key) {
  key = key || '__akwrite';
  const body = JSON.stringify(JSON.stringify(payload)); // littéral JS échappé proprement
  const k = JSON.stringify(key);
  return "(function(){ try {"
    + " fetch('" + endpoint + "', { method:'POST', credentials:'include',"
    + " headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: " + body + " })"
    + ".then(function(r){ return r.json().catch(function(){ return { ok:false, message:'Réponse invalide.' }; }); })"
    + ".then(function(d){ var o={}; o[" + k + "]=d; window.ReactNativeWebView.postMessage(JSON.stringify(o)); })"
    + ".catch(function(){ var o={}; o[" + k + "]={ ok:false, message:'Connexion impossible.' }; window.ReactNativeWebView.postMessage(JSON.stringify(o)); });"
    + " } catch(e){ var o={}; o[" + k + "]={ ok:false, message:'Erreur.' }; window.ReactNativeWebView.postMessage(JSON.stringify(o)); } })(); true;";
}

// Envoi d'une photo de facture au moteur IA (multipart, dans la WebView = même session)
function scanJS(base64, mime, projectId, csrf) {
  return "(function(){ try {"
    + " var b64=" + JSON.stringify(base64) + ";"
    + " var bin=atob(b64); var arr=new Uint8Array(bin.length);"
    + " for (var i=0;i<bin.length;i++) arr[i]=bin.charCodeAt(i);"
    + " var blob=new Blob([arr], { type: " + JSON.stringify(mime) + " });"
    + " var fd=new FormData();"
    + " fd.append('invoice_file', blob, 'facture.jpg');"
    + " fd.append('project_id', " + JSON.stringify(String(projectId)) + ");"
    + " fd.append('csrf_token', " + JSON.stringify(csrf) + ");"
    + " fetch('/action-scan-facture.php', { method:'POST', credentials:'include', body: fd })"
    + ".then(function(r){ return r.json().catch(function(){ return { success:false, error:'Réponse invalide.' }; }); })"
    + ".then(function(d){ window.ReactNativeWebView.postMessage(JSON.stringify({ __akscan: d })); })"
    + ".catch(function(){ window.ReactNativeWebView.postMessage(JSON.stringify({ __akscan: { success:false, error:'Analyse impossible.' } })); });"
    + " } catch(e){ window.ReactNativeWebView.postMessage(JSON.stringify({ __akscan: { success:false, error:'Image illisible.' } })); } })(); true;";
}

// Calcul des totaux d'une facture/devis (miroir de ak_asso_line_compute)
function computeTotals(lines) {
  let ht = 0, vat = 0;
  for (const l of lines) {
    const q = parseFloat(String(l.quantity).replace(',', '.')) || 0;
    const u = parseFloat(String(l.unit_price_ht).replace(',', '.')) || 0;
    const r = (l.vat_rate === '' || l.vat_rate == null) ? 0 : (parseFloat(String(l.vat_rate).replace(',', '.')) || 0);
    const lineHt = Math.round(q * u * 100);
    ht += lineHt;
    vat += r > 0 ? Math.round(lineHt * r / 100) : 0;
  }
  return { ht: ht / 100, vat: vat / 100, ttc: (ht + vat) / 100 };
}

function gotoJS(path) {
  return "(function(){ try { window.location.href='" + BASE + path + "'; } catch(e){} })(); true;";
}

// Capture les identifiants à la connexion (pour "rester connecté" via Face ID)
const CAPTURE_CREDS_JS = "(function(){ try {"
  + " var f=document.querySelector('form');"
  + " if(f && !f.__akhook){ f.__akhook=1; f.addEventListener('submit', function(){ try {"
  + "   var e=document.querySelector('input[type=email],input[name=email]');"
  + "   var p=document.querySelector('input[type=password]');"
  + "   if(e&&p&&e.value&&p.value){ window.ReactNativeWebView.postMessage(JSON.stringify({__akcreds:{email:e.value,password:p.value}})); }"
  + " } catch(x){} }, true); }"
  + " } catch(x){} })(); true;";

// Re-remplit et soumet le formulaire de connexion (auto-login Face ID)
function autoLoginJS(email, password) {
  return "(function(){ try {"
    + " var e=document.querySelector('input[type=email],input[name=email]');"
    + " var p=document.querySelector('input[type=password]');"
    + " if(e&&p){ e.value=" + JSON.stringify(email) + "; p.value=" + JSON.stringify(password) + ";"
    + " e.dispatchEvent(new Event('input',{bubbles:true})); p.dispatchEvent(new Event('input',{bubbles:true}));"
    + " var f=e.form||document.querySelector('form'); if(f){ if(f.requestSubmit){ f.requestSubmit(); } else { f.submit(); } } }"
    + " } catch(x){} })(); true;";
}

// Récupère un PDF authentifié en base64 (pour partage/téléchargement natif)
function fetchPdfJS(url) {
  return "(function(){ try {"
    + " fetch(" + JSON.stringify(url) + ", {credentials:'include'})"
    + ".then(function(r){ return r.blob(); })"
    + ".then(function(b){ var fr=new FileReader(); fr.onloadend=function(){ var s=String(fr.result||''); var i=s.indexOf('base64,'); window.ReactNativeWebView.postMessage(JSON.stringify({__akpdf:{ok:true, data: i>=0 ? s.slice(i+7) : ''}})); }; fr.onerror=function(){ window.ReactNativeWebView.postMessage(JSON.stringify({__akpdf:{ok:false}})); }; fr.readAsDataURL(b); })"
    + ".catch(function(){ window.ReactNativeWebView.postMessage(JSON.stringify({__akpdf:{ok:false}})); });"
    + " } catch(x){ window.ReactNativeWebView.postMessage(JSON.stringify({__akpdf:{ok:false}})); } })(); true;";
}

const STATUS_META = {
  warning: { label: 'À suivre', color: '#D97706', bg: '#FFFBEB' },
  active: { label: 'En cours', color: '#059669', bg: '#ECFDF5' },
  done: { label: 'Terminé', color: '#2563EB', bg: '#EFF6FF' },
};

function fmtEuro(n) {
  n = Number(n) || 0;
  if (n >= 1000) return (n / 1000).toFixed(1).replace('.', ',') + ' k€';
  return Math.round(n) + ' €';
}

function greeting() {
  const h = new Date().getHours();
  if (h < 6) return 'Bonne nuit';
  if (h < 18) return 'Bonjour';
  return 'Bonsoir';
}

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
  }),
});

const FEATURES = [
  { icon: 'briefcase', label: 'Projets, adhérents & cotisations' },
  { icon: 'document-text', label: 'Factures & reçus fiscaux' },
  { icon: 'stats-chart', label: 'Comptabilité analytique incluse' },
];

/* Barre d'onglets adaptative selon le profil (association vs TPE) */
function tabsFor(profile) {
  const isTpe = profile === 'tpe';
  return [
    { key: 'accueil', label: 'Accueil', icon: 'home' },
    isTpe
      ? { key: 'factures', label: 'Factures', icon: 'receipt' }
      : { key: 'projets', label: 'Projets', icon: 'folder' },
    { key: 'add', label: '', icon: 'add' },
    isTpe
      ? { key: 'people', label: 'Clients', icon: 'briefcase' }
      : { key: 'people', label: 'Membres', icon: 'people' },
    { key: 'menu', label: 'Plus', icon: 'grid' },
  ];
}

const QUICK_ACTIONS_ASSO = [
  { label: 'Nouveau projet', icon: 'add-circle', color: '#059669', form: 'project' },
  { label: 'Scanner une facture', icon: 'camera', color: '#0EA5E9', form: 'expense' },
  { label: 'Nouvelle facture', icon: 'document-text', color: '#2563EB', form: 'invoice' },
  { label: 'Nouvel adhérent', icon: 'person-add', color: '#D97706', form: 'member' },
  { label: 'Nouveau message', icon: 'chatbubble-ellipses', color: '#7C3AED', path: '/messages' },
];

const QUICK_ACTIONS_TPE = [
  { label: 'Nouvelle facture', icon: 'document-text', color: '#2563EB', form: 'invoice' },
  { label: 'Nouveau devis', icon: 'create', color: '#059669', form: 'quote' },
  { label: 'Nouveau client', icon: 'person-add', color: '#D97706', form: 'client' },
  { label: 'Nouveau message', icon: 'chatbubble-ellipses', color: '#7C3AED', path: '/messages' },
];

const SHORTCUTS_ASSO = [
  { label: 'Projets', icon: 'folder-open', path: '/projets' },
  { label: 'Factures', icon: 'receipt', path: '/mon-asso-factures' },
  { label: 'Agenda', icon: 'calendar', path: '/agenda' },
  { label: 'Messages', icon: 'chatbubbles', path: '/messages' },
];

const SHORTCUTS_TPE = [
  { label: 'Factures', icon: 'receipt', path: '/mon-asso-factures' },
  { label: 'Devis', icon: 'document-text', path: '/mon-asso-devis' },
  { label: 'Clients', icon: 'people', path: '/mon-asso-clients' },
  { label: 'Recettes', icon: 'stats-chart', path: '/mon-asso-stats' },
];

/* Couleurs des statuts de facture (par "kind" renvoye par l'API) */
const INV_KIND = {
  done:  { color: '#065F46', bg: '#D1FAE5' },
  wait:  { color: '#92400E', bg: '#FEF3C7' },
  late:  { color: '#991B1B', bg: '#FEE2E2' },
  draft: { color: '#475569', bg: '#F1F5F9' },
  off:   { color: '#64748B', bg: '#F1F5F9' },
};

/* ================================================================== */
/*  ECRAN D'ACCUEIL (marketing) natif                                  */
/* ================================================================== */
function WelcomeScreen({ onLogin, onSignup }) {
  return (
    <View style={styles.wBg}>
      <LinearGradient
        colors={['#10C98D', '#059669', '#04624A', '#022E22']}
        locations={[0, 0.42, 0.78, 1]}
        start={{ x: 0.15, y: 0 }}
        end={{ x: 0.85, y: 1 }}
        style={StyleSheet.absoluteFill}
      />
      <View style={[styles.blob, styles.blob1]} />
      <View style={[styles.blob, styles.blob2]} />
      <View style={[styles.blob, styles.blob3]} />
      <StatusBar barStyle="light-content" backgroundColor="transparent" translucent />
      <SafeAreaView style={styles.wSafe}>
        <View style={styles.wTop}>
          <View style={styles.logoHalo}>
            <View style={styles.logoRing}>
              <LinearGradient colors={['#FFFFFF', '#E9FBF3']} start={{ x: 0.2, y: 0 }} end={{ x: 0.8, y: 1 }} style={styles.logoTile}>
                <View style={styles.logoGloss} />
                <View style={styles.logoDot} />
              </LinearGradient>
            </View>
          </View>
          <Text style={styles.brand}>Assokit</Text>
          <View style={styles.taglinePill}>
            <Text style={styles.tagline}>L'art de mener vos projets</Text>
          </View>
        </View>

        <View style={styles.wFeatures}>
          {FEATURES.map((f, i) => (
            <BlurView key={i} intensity={22} tint="light" style={styles.glassCard}>
              <View style={styles.wFeatureIcon}>
                <Ionicons name={f.icon} size={20} color="#fff" />
              </View>
              <Text style={styles.wFeatureTxt}>{f.label}</Text>
              <Ionicons name="checkmark-circle" size={22} color="#fff" />
            </BlurView>
          ))}
        </View>

        <View style={styles.actions}>
          <TouchableOpacity style={styles.btnPrimary} onPress={onLogin} activeOpacity={0.9}>
            <Text style={styles.btnPrimaryTxt}>Se connecter</Text>
            <Ionicons name="arrow-forward" size={18} color="#047857" />
          </TouchableOpacity>
          <TouchableOpacity activeOpacity={0.85} onPress={onSignup} style={styles.btnGlassWrap}>
            <BlurView intensity={18} tint="light" style={styles.btnGlass}>
              <Text style={styles.btnGhostTxt}>Créer ma démo</Text>
            </BlurView>
          </TouchableOpacity>
          <Text style={styles.wFooter}>🇫🇷 Hébergé en France · Conforme RGPD</Text>
        </View>
      </SafeAreaView>
    </View>
  );
}

/* ================================================================== */
/*  ACCUEIL NATIF (KPIs premium)                                       */
/* ================================================================== */
function NativeHome({ data, loading, onRefresh, onGoto, profile }) {
  const k = (data && data.kpis) || {};
  const isTpe = profile === 'tpe';
  const cards = isTpe
    ? [
        { icon: 'briefcase', color: '#2563EB', bg: '#EFF6FF', label: 'Clients', value: String(k.clients ?? 0), sub: 'au total', path: '/mon-asso-clients' },
        { icon: 'document-text', color: '#059669', bg: '#ECFDF5', label: 'Devis en cours', value: String(k.devis_encours ?? 0), sub: 'à relancer', path: '/mon-asso-devis' },
        { icon: 'wallet', color: '#7C3AED', bg: '#F5F3FF', label: 'CA encaissé', value: fmtEuro(k.ca_paid), sub: (k.factures ?? 0) + ' facture' + ((k.factures ?? 0) > 1 ? 's' : ''), path: '/mon-asso-factures' },
        { icon: 'alert-circle', color: '#D97706', bg: '#FFFBEB', label: 'Impayés', value: fmtEuro(k.impayes), sub: 'à recouvrer', path: '/mon-asso-factures' },
      ]
    : [
        { icon: 'folder', color: '#059669', bg: '#ECFDF5', label: 'Projets actifs', value: String(k.projets_actifs ?? 0), sub: 'en cours', path: '/projets' },
        { icon: 'people', color: '#2563EB', bg: '#EFF6FF', label: 'Membres', value: String(k.membres ?? 0), sub: (k.membres_nouveaux > 0 ? '+' + k.membres_nouveaux + ' en 30j' : 'actifs'), path: '/adherents' },
        { icon: 'calendar', color: '#D97706', bg: '#FFFBEB', label: 'Événements', value: String(k.evenements ?? 0), sub: 'à venir', path: '/agenda' },
        { icon: 'wallet', color: '#7C3AED', bg: '#F5F3FF', label: 'Budget engagé', value: fmtEuro(k.budget_used), sub: 'sur ' + fmtEuro(k.budget_planned), path: '/projets' },
      ];
  const shortcuts = isTpe ? SHORTCUTS_TPE : SHORTCUTS_ASSO;

  return (
    <ScrollView
      style={styles.homeScroll}
      contentContainerStyle={styles.homeContent}
      showsVerticalScrollIndicator={false}
      refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}
    >
      <LinearGradient
        colors={['#0BBE85', '#059669', '#036B4E']}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 1 }}
        style={styles.hHeader}
      >
        <View style={styles.hHeaderRow}>
          <View style={{ flex: 1 }}>
            <Text style={styles.hHello}>{greeting()}{data && data.first_name ? ',' : ''}</Text>
            {data && data.first_name ? <Text style={styles.hName}>{data.first_name} 👋</Text> : null}
            <Text style={styles.hOrg}>{(data && data.org_name) || ' '}</Text>
          </View>
          <View style={styles.hAvatar}>
            {data && data.org_logo ? (
              <Image source={{ uri: data.org_logo }} style={styles.hAvatarImg} resizeMode="cover" />
            ) : (
              <Text style={styles.hAvatarTxt}>{(data && data.org_initials) || '·'}</Text>
            )}
          </View>
        </View>
      </LinearGradient>

      {!data ? (
        <View style={styles.homeLoader}>
          <ActivityIndicator size="large" color={BRAND} />
          <Text style={styles.homeLoaderTxt}>Chargement de vos données…</Text>
        </View>
      ) : (
        <>
          <View style={styles.kpiGrid}>
            {cards.map((c) => (
              <TouchableOpacity key={c.label} style={styles.kpiCard} activeOpacity={0.85} onPress={() => onGoto(c.path)}>
                <View style={styles.kpiTop}>
                  <View style={[styles.kpiIcon, { backgroundColor: c.bg }]}>
                    <Ionicons name={c.icon} size={20} color={c.color} />
                  </View>
                </View>
                <Text style={styles.kpiValue}>{c.value}</Text>
                <Text style={styles.kpiLabel}>{c.label}</Text>
                <Text style={styles.kpiSub}>{c.sub}</Text>
              </TouchableOpacity>
            ))}
          </View>

          <Text style={styles.sectionTitle}>Accès rapide</Text>
          <View style={styles.shortcuts}>
            {shortcuts.map((s) => (
              <TouchableOpacity key={s.label} style={styles.shortcut} activeOpacity={0.8} onPress={() => onGoto(s.path)}>
                <View style={styles.shortcutIcon}>
                  <Ionicons name={s.icon} size={22} color={BRAND} />
                </View>
                <Text style={styles.shortcutTxt}>{s.label}</Text>
              </TouchableOpacity>
            ))}
          </View>

          <TouchableOpacity style={styles.openFull} activeOpacity={0.8} onPress={() => onGoto('/dashboard')}>
            <Text style={styles.openFullTxt}>Ouvrir le tableau de bord complet</Text>
            <Ionicons name="arrow-forward" size={18} color={BRAND} />
          </TouchableOpacity>
        </>
      )}
    </ScrollView>
  );
}

/* ================================================================== */
/*  PROJETS (liste native)                                             */
/* ================================================================== */
function NativeProjects({ data, loading, onRefresh, onOpen, onNew, onBack }) {
  const projects = (data && data.projects) || [];
  return (
    <View style={styles.projWrap}>
      <View style={styles.projHeader}>
        {onBack && <TouchableOpacity onPress={onBack} style={styles.projBack} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="chevron-back" size={26} color={INK} /></TouchableOpacity>}
        <View style={{ flex: 1 }}>
          <Text style={styles.projTitle}>Projets</Text>
          <Text style={styles.projSub}>{projects.length} projet{projects.length > 1 ? 's' : ''}</Text>
        </View>
        <TouchableOpacity style={styles.projNewBtn} onPress={onNew} activeOpacity={0.85}>
          <Ionicons name="add" size={19} color="#fff" />
          <Text style={styles.projNewTxt}>Nouveau</Text>
        </TouchableOpacity>
      </View>
      {!data ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : projects.length === 0 ? (
        <View style={styles.emptyBox}>
          <Ionicons name="folder-open-outline" size={44} color="#CBD5E1" />
          <Text style={styles.emptyTxt}>Aucun projet en cours</Text>
          <TouchableOpacity style={styles.emptyBtn} onPress={onNew} activeOpacity={0.85}>
            <Text style={styles.emptyBtnTxt}>Créer un projet</Text>
          </TouchableOpacity>
        </View>
      ) : (
        <ScrollView
          contentContainerStyle={{ paddingHorizontal: 16, paddingBottom: 20 }}
          showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}
        >
          {projects.map((p) => {
            const sm = STATUS_META[p.status] || STATUS_META.active;
            const pct = Math.max(0, Math.min(100, p.progress || 0));
            return (
              <TouchableOpacity key={p.id} style={styles.projCard} activeOpacity={0.85} onPress={() => onOpen(p.id)}>
                <View style={styles.projCardTop}>
                  <View style={{ flex: 1, paddingRight: 10 }}>
                    <Text style={styles.projName} numberOfLines={1}>{p.name}</Text>
                    <Text style={styles.projFolder} numberOfLines={1}>{p.folder}</Text>
                  </View>
                  <View style={[styles.projChip, { backgroundColor: sm.bg }]}>
                    <Text style={[styles.projChipTxt, { color: sm.color }]}>{sm.label}</Text>
                  </View>
                </View>
                <View style={styles.progRow}>
                  <View style={styles.progTrack}>
                    <View style={[styles.progFill, { width: pct + '%', backgroundColor: sm.color }]} />
                  </View>
                  <Text style={styles.progTxt}>{pct}%</Text>
                </View>
              </TouchableOpacity>
            );
          })}
        </ScrollView>
      )}
    </View>
  );
}

/* ================================================================== */
/*  MEMBRES / CLIENTS (liste native)                                   */
/* ================================================================== */
function NativePeople({ mode, data, loading, onRefresh, onOpen, onNew, onBack }) {
  const isClients = mode === 'clients';
  const list = data ? (isClients ? (data.clients || []) : (data.members || [])) : null;
  const title = isClients ? 'Clients' : 'Membres';
  const newLabel = isClients ? 'Nouveau' : 'Inviter';

  return (
    <View style={styles.projWrap}>
      <View style={styles.projHeader}>
        {onBack && <TouchableOpacity onPress={onBack} style={styles.projBack} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="chevron-back" size={26} color={INK} /></TouchableOpacity>}
        <View style={{ flex: 1 }}>
          <Text style={styles.projTitle}>{title}</Text>
          <Text style={styles.projSub}>{(list ? list.length : 0)} {title.toLowerCase()}</Text>
        </View>
        <TouchableOpacity style={styles.projNewBtn} onPress={onNew} activeOpacity={0.85}>
          <Ionicons name={isClients ? 'add' : 'person-add'} size={18} color="#fff" />
          <Text style={styles.projNewTxt}>{newLabel}</Text>
        </TouchableOpacity>
      </View>
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}>
          <Ionicons name={isClients ? 'briefcase-outline' : 'people-outline'} size={44} color="#CBD5E1" />
          <Text style={styles.emptyTxt}>{isClients ? 'Aucun client' : 'Aucun membre'}</Text>
          <TouchableOpacity style={styles.emptyBtn} onPress={onNew} activeOpacity={0.85}>
            <Text style={styles.emptyBtnTxt}>{isClients ? 'Ajouter un client' : 'Inviter un membre'}</Text>
          </TouchableOpacity>
        </View>
      ) : (
        <ScrollView
          contentContainerStyle={{ paddingHorizontal: 16, paddingBottom: 20 }}
          showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}
        >
          {list.map((p) => (
            <TouchableOpacity key={p.id} style={styles.personCard} activeOpacity={0.85} onPress={() => onOpen(p.id)}>
              <View style={[styles.personAvatar, { backgroundColor: (p.color || BRAND) }]}>
                <Text style={styles.personAvatarTxt}>{p.initials}</Text>
              </View>
              <View style={{ flex: 1, paddingRight: 8 }}>
                <Text style={styles.personName} numberOfLines={1}>{p.name}</Text>
                <Text style={styles.personSub} numberOfLines={1}>
                  {isClients ? (p.city || p.email || '—') : (p.email || '—')}
                </Text>
              </View>
              {isClients ? (
                <Text style={styles.personRight}>{fmtEuro(p.total_paid)}</Text>
              ) : (
                <View style={styles.personBadges}>
                  <View style={[styles.roleChip, { backgroundColor: '#EEF2F6' }]}>
                    <Text style={styles.roleChipTxt}>{p.role_label}</Text>
                  </View>
                  <View style={[styles.dot, { backgroundColor: p.up_to_date ? '#10B981' : '#F59E0B' }]} />
                </View>
              )}
            </TouchableOpacity>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

/* ================================================================== */
/*  FACTURES (liste native)                                            */
/* ================================================================== */
function NativeInvoices({ data, loading, onRefresh, onOpen, onNew, onBack, aiText, aiLoading, onAnalyze }) {
  const list = data ? (data.invoices || []) : null;
  const t = (list || []).reduce((a, i) => {
    const v = i.amount || 0; a.total += v;
    if (i.status === 'paid') a.paid += v;
    else if (i.status === 'overdue') a.overdue += v;
    else if (i.status === 'pending') a.pending += v;
    return a;
  }, { total: 0, paid: 0, pending: 0, overdue: 0 });
  return (
    <View style={styles.projWrap}>
      <View style={styles.projHeader}>
        {onBack && <TouchableOpacity onPress={onBack} style={styles.projBack} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="chevron-back" size={26} color={INK} /></TouchableOpacity>}
        <View style={{ flex: 1 }}>
          <Text style={styles.projTitle}>Factures</Text>
          <Text style={styles.projSub}>{(list ? list.length : 0)} facture{(list && list.length > 1) ? 's' : ''}</Text>
        </View>
        <TouchableOpacity style={styles.projNewBtn} onPress={onNew} activeOpacity={0.85}>
          <Ionicons name="add" size={19} color="#fff" />
          <Text style={styles.projNewTxt}>Nouvelle</Text>
        </TouchableOpacity>
      </View>
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView
          contentContainerStyle={{ paddingHorizontal: 16, paddingBottom: 20 }}
          showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}
        >
          <View style={styles.miniKpiRow}>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(t.total)}</Text><Text style={styles.miniKpiLbl}>Total</Text></View>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(t.paid)}</Text><Text style={styles.miniKpiLbl}>Encaissé</Text></View>
            <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: (t.overdue + t.pending) > 0 ? '#B45309' : '#047857' }]}>{fmtEuro(t.overdue + t.pending)}</Text><Text style={styles.miniKpiLbl}>Impayés</Text></View>
          </View>

          <View style={styles.aiCard}>
            <View style={styles.aiHead}><Ionicons name="sparkles" size={15} color="#7C3AED" /><Text style={styles.aiTitle}>Analyse IA</Text></View>
            {aiText ? <Text style={styles.aiTxt}>{aiText}</Text> : <Text style={styles.aiMuted}>Un conseil de trésorerie personnalisé sur vos factures.</Text>}
            <TouchableOpacity style={[styles.aiBtn, aiLoading ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={aiLoading ? undefined : onAnalyze}>
              {aiLoading ? <ActivityIndicator size="small" color="#7C3AED" /> : <Ionicons name="sparkles" size={15} color="#7C3AED" />}
              <Text style={styles.aiBtnTxt}>{aiLoading ? 'Analyse…' : (aiText ? 'Actualiser l\'analyse' : 'Analyser via IA')}</Text>
            </TouchableOpacity>
          </View>

          {list.length === 0 ? (
            <View style={styles.emptyBox}>
              <Ionicons name="receipt-outline" size={44} color="#CBD5E1" />
              <Text style={styles.emptyTxt}>Aucune facture</Text>
              <TouchableOpacity style={styles.emptyBtn} onPress={onNew} activeOpacity={0.85}><Text style={styles.emptyBtnTxt}>Créer une facture</Text></TouchableOpacity>
            </View>
          ) : list.map((inv) => {
            const km = INV_KIND[inv.status_kind] || INV_KIND.wait;
            return (
              <TouchableOpacity key={inv.id} style={styles.invCard} activeOpacity={0.85} onPress={() => onOpen(inv.id)}>
                <View style={{ flex: 1, paddingRight: 10 }}>
                  <Text style={styles.invNum} numberOfLines={1}>{inv.number}</Text>
                  <Text style={styles.invClient} numberOfLines={1}>{inv.client || '—'}{inv.date ? '  ·  ' + inv.date : ''}</Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={styles.invAmount}>{fmtEuro(inv.amount)}</Text>
                  <View style={[styles.projChip, { backgroundColor: km.bg, marginTop: 5 }]}>
                    <Text style={[styles.projChipTxt, { color: km.color }]}>{inv.status_label}</Text>
                  </View>
                </View>
              </TouchableOpacity>
            );
          })}
        </ScrollView>
      )}
    </View>
  );
}

/* ================================================================== */
/*  FICHES DÉTAIL (natives)                                            */
/* ================================================================== */
function DetailHeader({ title, onBack }) {
  return (
    <View style={styles.dHeader}>
      <TouchableOpacity onPress={onBack} style={styles.dBack} hitSlop={{ top: 12, bottom: 12, left: 12, right: 12 }} activeOpacity={0.7}>
        <Ionicons name="chevron-back" size={26} color={INK} />
      </TouchableOpacity>
      <Text style={styles.dTitle} numberOfLines={1}>{title}</Text>
      <View style={{ width: 34 }} />
    </View>
  );
}

function DetailLoading({ title, onBack }) {
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title={title} onBack={onBack} />
      <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
    </View>
  );
}

function DetailError({ title, onBack, onRetry }) {
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title={title} onBack={onBack} />
      <View style={styles.emptyBox}>
        <Ionicons name="cloud-offline-outline" size={44} color="#CBD5E1" />
        <Text style={styles.emptyTxt}>Chargement impossible. Vérifiez votre connexion.</Text>
        <TouchableOpacity style={styles.emptyBtn} onPress={onRetry} activeOpacity={0.85}><Text style={styles.emptyBtnTxt}>Réessayer</Text></TouchableOpacity>
      </View>
    </View>
  );
}

function InfoRow({ icon, label, value, onPress }) {
  if (!value) return null;
  const Row = onPress ? TouchableOpacity : View;
  return (
    <Row style={styles.infoRow} onPress={onPress} activeOpacity={0.7}>
      <View style={styles.infoIcon}><Ionicons name={icon} size={18} color={BRAND} /></View>
      <View style={{ flex: 1 }}>
        <Text style={styles.infoLabel}>{label}</Text>
        <Text style={[styles.infoValue, onPress ? { color: BRAND } : null]}>{value}</Text>
      </View>
      {onPress ? <Ionicons name="chevron-forward" size={16} color="#CBD5E1" /> : null}
    </Row>
  );
}

function NativeProjectDetail({ entry, onBack, onRefresh, onWeb, onAddExpense, onSharePdf, pdfBusy }) {
  const d = entry.data;
  if (d && d.ok === false) return <DetailError title="Projet" onBack={onBack} onRetry={onRefresh} />;
  if (!d || !d.project) return <DetailLoading title="Projet" onBack={onBack} />;
  const p = d.project;
  const bilan = entry.bilan;
  const sm = STATUS_META[p.status] || STATUS_META.active;
  const pct = Math.max(0, Math.min(100, p.progress || 0));
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Projet" onBack={onBack} />
      <ScrollView contentContainerStyle={styles.detailContent} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!entry.loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <Text style={styles.dName}>{p.name}</Text>
        <Text style={styles.dFolder}>{p.folder}</Text>
        <View style={[styles.projChip, { backgroundColor: sm.bg, alignSelf: 'flex-start', marginTop: 10 }]}>
          <Text style={[styles.projChipTxt, { color: sm.color }]}>{sm.label}</Text>
        </View>

        <View style={styles.dCard}>
          <View style={styles.dCardRow}>
            <Text style={styles.dCardLabel}>Avancement</Text>
            <Text style={styles.dCardStrong}>{pct}%</Text>
          </View>
          <View style={styles.progTrack}><View style={[styles.progFill, { width: pct + '%', backgroundColor: sm.color }]} /></View>
          <Text style={styles.dSteps}>{p.steps_done}/{p.steps_total} étape{p.steps_total > 1 ? 's' : ''} terminée{p.steps_done > 1 ? 's' : ''}</Text>
        </View>

        {p.budget_planned > 0 && (
          <View style={styles.dCard}>
            <View style={styles.dCardRow}>
              <Text style={styles.dCardLabel}>Budget engagé</Text>
              <Text style={styles.dCardStrong}>{fmtEuro(p.budget_used)} / {fmtEuro(p.budget_planned)}</Text>
            </View>
            <View style={styles.progTrack}><View style={[styles.progFill, { width: Math.min(100, p.budget_pct) + '%', backgroundColor: '#7C3AED' }]} /></View>
          </View>
        )}

        {!!p.description && (<><Text style={styles.dSection}>Description</Text><Text style={styles.dText}>{p.description}</Text></>)}
        {!!p.objective && (<><Text style={styles.dSection}>Objectif</Text><Text style={styles.dText}>{p.objective}</Text></>)}

        {p.referent && (
          <>
            <Text style={styles.dSection}>Référent</Text>
            <View style={styles.personCard}>
              <View style={[styles.personAvatar, { backgroundColor: BRAND }]}><Text style={styles.personAvatarTxt}>{p.referent.initials}</Text></View>
              <Text style={styles.personName}>{p.referent.name}</Text>
            </View>
          </>
        )}

        {d.steps && d.steps.length > 0 && (
          <>
            <Text style={styles.dSection}>Étapes</Text>
            {d.steps.map((s) => (
              <View key={s.id} style={styles.stepRow}>
                <Ionicons name={s.done ? 'checkmark-circle' : 'ellipse-outline'} size={22} color={s.done ? '#10B981' : '#CBD5E1'} />
                <View style={{ flex: 1, marginLeft: 10 }}>
                  <Text style={[styles.stepTitle, s.done ? styles.stepDone : null]}>{s.title}</Text>
                  {!!s.desc && <Text style={styles.stepDesc}>{s.desc}</Text>}
                </View>
              </View>
            ))}
          </>
        )}

        <View style={styles.bilanHead}>
          <Text style={styles.dSection}>Bilan analytique</Text>
          <Ionicons name="pie-chart" size={18} color={BRAND} />
        </View>
        {!bilan ? (
          <View style={styles.dCard}><ActivityIndicator color={BRAND} /></View>
        ) : bilan.allowed === false ? (
          <View style={styles.upsellCard}>
            <Ionicons name="lock-closed" size={22} color="#B45309" />
            <Text style={styles.upsellTxt}>{bilan.upsell || 'Fonctionnalité incluse dans le plan Pro.'}</Text>
            <TouchableOpacity style={styles.upsellBtn} activeOpacity={0.85} onPress={() => onWeb('/mon-asso-plan')}>
              <Text style={styles.upsellBtnTxt}>Voir les plans</Text>
            </TouchableOpacity>
          </View>
        ) : (bilan.count || 0) === 0 ? (
          <View style={styles.dCard}><Text style={styles.dMuted}>Aucune dépense enregistrée. Scannez une facture pour l'ajouter au projet.</Text></View>
        ) : (
          <View style={styles.dCard}>
            {(bilan.postes || []).map((po) => (
              <View key={po.code} style={styles.bilanRow}>
                <View style={{ flex: 1, paddingRight: 10 }}>
                  <Text style={styles.bilanLabel} numberOfLines={1}>{po.code} · {po.label}</Text>
                  <Text style={styles.bilanCount}>{po.count} facture{po.count > 1 ? 's' : ''}</Text>
                </View>
                <Text style={styles.bilanAmount}>{fmtEuro(po.total)}</Text>
              </View>
            ))}
            <View style={[styles.bilanRow, styles.bilanTotalRow]}>
              <Text style={styles.dCardLabel}>Total dépenses</Text>
              <Text style={styles.dTotal}>{fmtEuro(bilan.total)}</Text>
            </View>
          </View>
        )}

        <Text style={styles.bilanNote}>ℹ️ Sans factures ni informations saisies, le bilan analytique sera incomplet.</Text>
        <View style={styles.pdfRow}>
          <TouchableOpacity style={[styles.pdfBtn, pdfBusy ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={() => !pdfBusy && onSharePdf('/download-bilan-analytique.php?project=' + p.id)}>
            {pdfBusy ? <ActivityIndicator size="small" color="#4F46E5" /> : <Ionicons name="share-outline" size={17} color="#4F46E5" />}
            <Text style={styles.pdfBtnTxt}>{pdfBusy ? 'Préparation…' : 'Bilan analytique (PDF)'}</Text>
          </TouchableOpacity>
          <TouchableOpacity style={styles.pdfBtn} activeOpacity={0.85} onPress={() => onWeb('/projet/' + p.id + '/bilan')}>
            <Ionicons name="document-text" size={17} color="#4F46E5" />
            <Text style={styles.pdfBtnTxt}>Bilan du projet</Text>
          </TouchableOpacity>
        </View>

        <TouchableOpacity style={styles.dPrimaryBtn} activeOpacity={0.85} onPress={() => onAddExpense(p.id)}>
          <Ionicons name="camera" size={19} color="#fff" />
          <Text style={styles.dPrimaryBtnTxt}>Scanner une facture</Text>
        </TouchableOpacity>
        <TouchableOpacity style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb('/projet/' + p.id)}>
          <Text style={styles.dWebBtnTxt}>Ouvrir la fiche complète</Text>
          <Ionicons name="open-outline" size={18} color={BRAND} />
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

function NativeMemberDetail({ entry, onBack, onRefresh, onOpenProject, onWeb }) {
  const d = entry.data;
  if (d && d.ok === false) return <DetailError title="Membre" onBack={onBack} onRetry={onRefresh} />;
  if (!d || !d.member) return <DetailLoading title="Membre" onBack={onBack} />;
  const m = d.member;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Membre" onBack={onBack} />
      <ScrollView contentContainerStyle={styles.detailContent} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!entry.loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <View style={styles.dHero}>
          <View style={[styles.dHeroAvatar, { backgroundColor: m.color || BRAND }]}><Text style={styles.dHeroAvatarTxt}>{m.initials}</Text></View>
          <Text style={styles.dName}>{m.name}</Text>
          <View style={styles.dChipsRow}>
            <View style={[styles.roleChip, { backgroundColor: '#EEF2F6' }]}><Text style={styles.roleChipTxt}>{m.role_label}</Text></View>
            <View style={[styles.roleChip, { backgroundColor: m.up_to_date ? '#D1FAE5' : '#FEF3C7' }]}>
              <Text style={[styles.roleChipTxt, { color: m.up_to_date ? '#065F46' : '#92400E' }]}>{m.up_to_date ? 'À jour' : 'Cotisation à renouveler'}</Text>
            </View>
          </View>
        </View>

        <Text style={styles.dSection}>Contact</Text>
        <View style={styles.dCard}>
          <InfoRow icon="mail" label="Email" value={m.email} onPress={m.email ? () => onWeb('mailto:' + m.email) : null} />
          <InfoRow icon="call" label="Téléphone" value={m.phone} />
          <InfoRow icon="location" label="Ville" value={m.city} />
        </View>

        <Text style={styles.dSection}>Adhésion</Text>
        <View style={styles.dCard}>
          <InfoRow icon="calendar" label="Adhérent depuis" value={m.adhesion_since} />
          <InfoRow icon="time" label="Valide jusqu'au" value={m.adhesion_until} />
          <InfoRow icon="log-in" label="Dernière connexion" value={m.last_login} />
        </View>

        {d.projects && d.projects.length > 0 && (
          <>
            <Text style={styles.dSection}>Projets pilotés</Text>
            {d.projects.map((p) => {
              const sm = STATUS_META[p.status] || STATUS_META.active;
              return (
                <TouchableOpacity key={p.id} style={styles.projCard} activeOpacity={0.85} onPress={() => onOpenProject(p.id)}>
                  <View style={styles.projCardTop}>
                    <View style={{ flex: 1, paddingRight: 10 }}>
                      <Text style={styles.projName} numberOfLines={1}>{p.name}</Text>
                      <Text style={styles.projFolder} numberOfLines={1}>{p.folder}</Text>
                    </View>
                    <View style={[styles.projChip, { backgroundColor: sm.bg }]}><Text style={[styles.projChipTxt, { color: sm.color }]}>{sm.label}</Text></View>
                  </View>
                </TouchableOpacity>
              );
            })}
          </>
        )}

        <TouchableOpacity style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb('/adherent?id=' + m.id)}>
          <Text style={styles.dWebBtnTxt}>Ouvrir la fiche complète</Text>
          <Ionicons name="open-outline" size={18} color={BRAND} />
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

function NativeClientDetail({ entry, onBack, onRefresh, onOpenInvoice, onWeb }) {
  const d = entry.data;
  if (d && d.ok === false) return <DetailError title="Client" onBack={onBack} onRetry={onRefresh} />;
  if (!d || !d.client) return <DetailLoading title="Client" onBack={onBack} />;
  const c = d.client;
  const s = d.stats || {};
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Client" onBack={onBack} />
      <ScrollView contentContainerStyle={styles.detailContent} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!entry.loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <View style={styles.dHero}>
          <View style={[styles.dHeroAvatar, { backgroundColor: '#2563EB' }]}><Text style={styles.dHeroAvatarTxt}>{c.initials}</Text></View>
          <Text style={styles.dName}>{c.name}</Text>
          <Text style={styles.dFolder}>{c.type === 'individual' ? 'Particulier' : 'Entreprise'}</Text>
        </View>

        <View style={styles.miniKpiRow}>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(s.paid)}</Text><Text style={styles.miniKpiLbl}>Encaissé</Text></View>
          <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: '#B45309' }]}>{fmtEuro((s.pending || 0) + (s.overdue || 0))}</Text><Text style={styles.miniKpiLbl}>En attente</Text></View>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.nb || 0}</Text><Text style={styles.miniKpiLbl}>Factures</Text></View>
        </View>

        <Text style={styles.dSection}>Contact</Text>
        <View style={styles.dCard}>
          <InfoRow icon="mail" label="Email" value={c.email} onPress={c.email ? () => onWeb('mailto:' + c.email) : null} />
          <InfoRow icon="call" label="Téléphone" value={c.phone} />
          <InfoRow icon="location" label="Adresse" value={c.address} />
          <InfoRow icon="business" label="SIREN" value={c.siren} />
          <InfoRow icon="pricetag" label="N° TVA" value={c.vat_number} />
        </View>

        {d.invoices && d.invoices.length > 0 && (
          <>
            <Text style={styles.dSection}>Factures</Text>
            {d.invoices.map((inv) => {
              const km = INV_KIND[inv.status_kind] || INV_KIND.wait;
              return (
                <TouchableOpacity key={inv.id} style={styles.invCard} activeOpacity={0.85} onPress={() => onOpenInvoice(inv.id)}>
                  <View style={{ flex: 1, paddingRight: 10 }}>
                    <Text style={styles.invNum} numberOfLines={1}>{inv.number}</Text>
                    <Text style={styles.invClient} numberOfLines={1}>{inv.date || '—'}</Text>
                  </View>
                  <View style={{ alignItems: 'flex-end' }}>
                    <Text style={styles.invAmount}>{fmtEuro(inv.amount)}</Text>
                    <View style={[styles.projChip, { backgroundColor: km.bg, marginTop: 5 }]}><Text style={[styles.projChipTxt, { color: km.color }]}>{inv.status_label}</Text></View>
                  </View>
                </TouchableOpacity>
              );
            })}
          </>
        )}

        <TouchableOpacity style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb('/mon-asso-client-detail?id=' + c.id)}>
          <Text style={styles.dWebBtnTxt}>Ouvrir la fiche complète</Text>
          <Ionicons name="open-outline" size={18} color={BRAND} />
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

function NativeInvoiceDetail({ entry, onBack, onRefresh, onWeb }) {
  const d = entry.data;
  const isQuote = d && d.invoice && d.invoice.is_quote;
  if (d && d.ok === false) return <DetailError title="Document" onBack={onBack} onRetry={onRefresh} />;
  if (!d || !d.invoice) return <DetailLoading title={isQuote ? 'Devis' : 'Facture'} onBack={onBack} />;
  const inv = d.invoice;
  const km = INV_KIND[inv.status_kind] || INV_KIND.wait;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title={isQuote ? 'Devis' : 'Facture'} onBack={onBack} />
      <ScrollView contentContainerStyle={styles.detailContent} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!entry.loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <Text style={styles.dName}>{inv.number}</Text>
        {!!inv.client && <Text style={styles.dFolder}>{inv.client}</Text>}
        <View style={[styles.projChip, { backgroundColor: km.bg, alignSelf: 'flex-start', marginTop: 10 }]}>
          <Text style={[styles.projChipTxt, { color: km.color }]}>{inv.status_label}</Text>
        </View>

        <View style={styles.dCard}>
          <View style={styles.dCardRow}><Text style={styles.dCardLabel}>Total TTC</Text><Text style={styles.dTotal}>{fmtEuro(inv.amount_ttc)}</Text></View>
          <View style={[styles.dCardRow, { marginTop: 6 }]}><Text style={styles.dMuted}>Total HT</Text><Text style={styles.dMuted}>{fmtEuro(inv.amount_ht)}</Text></View>
          <View style={[styles.dCardRow, { marginTop: 4 }]}><Text style={styles.dMuted}>TVA</Text><Text style={styles.dMuted}>{fmtEuro(inv.amount_vat)}</Text></View>
        </View>

        <View style={styles.dCard}>
          <InfoRow icon="calendar" label="Émis le" value={inv.issued_at} />
          <InfoRow icon="time" label={isQuote ? 'Valable jusqu\'au' : 'Échéance'} value={inv.due_at} />
          {!isQuote && <InfoRow icon="checkmark-done" label="Payée le" value={inv.paid_at} />}
        </View>

        {d.lines && d.lines.length > 0 && (
          <>
            <Text style={styles.dSection}>Détail</Text>
            <View style={styles.dCard}>
              {d.lines.map((l, i) => (
                <View key={i} style={[styles.lineRow, i > 0 ? styles.lineSep : null]}>
                  <View style={{ flex: 1, paddingRight: 10 }}>
                    <Text style={styles.lineLabel} numberOfLines={2}>{l.label}</Text>
                    <Text style={styles.lineQty}>{l.qty} × {fmtEuro(l.unit)}{l.vat ? '  · TVA ' + l.vat + '%' : ''}</Text>
                  </View>
                  <Text style={styles.lineTotal}>{fmtEuro(l.total)}</Text>
                </View>
              ))}
            </View>
          </>
        )}

        {!!inv.description && (<><Text style={styles.dSection}>Note</Text><Text style={styles.dText}>{inv.description}</Text></>)}

        {!!inv.public_uuid && (
          <TouchableOpacity style={styles.dPrimaryBtn} activeOpacity={0.85} onPress={() => onWeb((isQuote ? '/devis/' : '/facture/') + inv.public_uuid)}>
            <Ionicons name="document-text" size={18} color="#fff" />
            <Text style={styles.dPrimaryBtnTxt}>{isQuote ? 'Voir / envoyer le devis' : 'Voir / envoyer la facture'}</Text>
          </TouchableOpacity>
        )}
        <TouchableOpacity style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb((isQuote ? '/mon-asso-devis-edit?id=' : '/mon-asso-facture-edit?id=') + inv.id)}>
          <Text style={styles.dWebBtnTxt}>{isQuote ? 'Modifier le devis' : 'Modifier la facture'}</Text>
          <Ionicons name="open-outline" size={18} color={BRAND} />
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

/* ================================================================== */
/*  FORMULAIRES DE CRÉATION (natifs)                                   */
/* ================================================================== */
function Field({ label, hint, ...props }) {
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={styles.fLabel}>{label}</Text>
      <TextInput
        style={styles.fInput}
        placeholderTextColor="#B6C0CC"
        {...props}
      />
      {!!hint && <Text style={styles.fHint}>{hint}</Text>}
    </View>
  );
}

function Segmented({ options, value, onChange }) {
  return (
    <View style={styles.segWrap}>
      {options.map((o) => {
        const on = value === o.value;
        return (
          <TouchableOpacity key={o.value} style={[styles.segItem, on ? styles.segItemOn : null]} activeOpacity={0.8} onPress={() => onChange(o.value)}>
            <Text style={[styles.segTxt, on ? styles.segTxtOn : null]}>{o.label}</Text>
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

function FormShell({ title, onBack, onSubmit, submitLabel, submitting, error, children }) {
  return (
    <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <DetailHeader title={title} onBack={onBack} />
      <ScrollView contentContainerStyle={styles.formContent} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
        {!!error && (
          <View style={styles.formErr}>
            <Ionicons name="alert-circle" size={18} color="#B91C1C" />
            <Text style={styles.formErrTxt}>{error}</Text>
          </View>
        )}
        {children}
      </ScrollView>
      <View style={styles.formFooter}>
        <TouchableOpacity style={[styles.dPrimaryBtn, { marginTop: 0 }, submitting ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={submitting ? undefined : onSubmit}>
          {submitting ? <ActivityIndicator color="#fff" /> : <Ionicons name="checkmark-circle" size={19} color="#fff" />}
          <Text style={styles.dPrimaryBtnTxt}>{submitting ? 'Enregistrement…' : submitLabel}</Text>
        </TouchableOpacity>
      </View>
    </KeyboardAvoidingView>
  );
}

function MemberForm({ onBack, onSubmit, submitting, error, canAdmin }) {
  const [f, setF] = useState({ first_name: '', last_name: '', email: '', phone: '', city: '', role: 'member', send_email: true });
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));
  const roles = [
    { value: 'member', label: 'Membre' },
    { value: 'referent', label: 'Référent' },
    { value: 'coordinator', label: 'Coordinateur' },
  ];
  if (canAdmin) roles.push({ value: 'admin', label: 'Admin' });
  return (
    <FormShell title="Nouvel adhérent" onBack={onBack} onSubmit={() => onSubmit(f)} submitLabel="Créer l'adhérent" submitting={submitting} error={error}>
      <Field label="Prénom *" value={f.first_name} onChangeText={set('first_name')} autoCapitalize="words" />
      <Field label="Nom *" value={f.last_name} onChangeText={set('last_name')} autoCapitalize="words" />
      <Field label="Email *" value={f.email} onChangeText={set('email')} keyboardType="email-address" autoCapitalize="none" placeholder="prenom.nom@example.com" />
      <Field label="Téléphone" value={f.phone} onChangeText={set('phone')} keyboardType="phone-pad" />
      <Field label="Ville" value={f.city} onChangeText={set('city')} autoCapitalize="words" />
      <Text style={styles.fLabel}>Rôle</Text>
      <Segmented options={roles} value={f.role} onChange={set('role')} />
      <View style={styles.switchRow}>
        <View style={{ flex: 1, paddingRight: 12 }}>
          <Text style={styles.switchLabel}>Envoyer l'email d'invitation</Text>
          <Text style={styles.switchSub}>L'adhérent crée lui-même son mot de passe (lien sécurisé 7 j).</Text>
        </View>
        <Switch value={f.send_email} onValueChange={set('send_email')} trackColor={{ true: BRAND }} />
      </View>
    </FormShell>
  );
}

function ClientForm({ onBack, onSubmit, submitting, error }) {
  const [f, setF] = useState({ client_type: 'company', display_name: '', email: '', phone: '', address_city: '', siren: '', vat_number: '' });
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));
  return (
    <FormShell title="Nouveau client" onBack={onBack} onSubmit={() => onSubmit(f)} submitLabel="Enregistrer le client" submitting={submitting} error={error}>
      <Text style={styles.fLabel}>Type</Text>
      <Segmented options={[{ value: 'company', label: 'Entreprise' }, { value: 'individual', label: 'Particulier' }]} value={f.client_type} onChange={set('client_type')} />
      <Field label="Nom / Raison sociale *" value={f.display_name} onChangeText={set('display_name')} autoCapitalize="words" />
      <Field label="Email *" value={f.email} onChangeText={set('email')} keyboardType="email-address" autoCapitalize="none" />
      <Field label="Téléphone" value={f.phone} onChangeText={set('phone')} keyboardType="phone-pad" />
      <Field label="Ville" value={f.address_city} onChangeText={set('address_city')} autoCapitalize="words" />
      {f.client_type === 'company' && (
        <>
          <Field label="SIREN" value={f.siren} onChangeText={set('siren')} keyboardType="number-pad" />
          <Field label="N° TVA" value={f.vat_number} onChangeText={set('vat_number')} autoCapitalize="characters" />
        </>
      )}
    </FormShell>
  );
}

function BillingForm({ mode, onBack, onSubmit, submitting, error, clients }) {
  const isQuote = mode === 'quote';
  const [client, setClient] = useState({ id: 0, client_type: 'company', display_name: '', email: '', phone: '', address_city: '' });
  const [lines, setLines] = useState([{ designation: '', quantity: '1', unit_price_ht: '', vat_rate: '20' }]);
  const [status, setStatus] = useState(isQuote ? 'draft' : 'pending');
  const [dueDays, setDueDays] = useState('30');
  const [pickerOpen, setPickerOpen] = useState(false);

  const setC = (k) => (v) => setClient((s) => ({ ...s, [k]: v, id: 0 }));
  const setLine = (i, k, v) => setLines((s) => s.map((l, j) => (j === i ? { ...l, [k]: v } : l)));
  const addLine = () => setLines((s) => [...s, { designation: '', quantity: '1', unit_price_ht: '', vat_rate: '20' }]);
  const rmLine = (i) => setLines((s) => (s.length > 1 ? s.filter((_, j) => j !== i) : s));
  const pickClient = (c) => { setClient({ id: c.id, display_name: c.name, email: c.email, client_type: c.type || 'company', phone: '', address_city: c.city || '' }); setPickerOpen(false); };

  const t = computeTotals(lines);
  const submit = () => {
    const payload = { lines, status };
    if (!isQuote) payload.due_days = parseInt(dueDays, 10) || 30;
    if (client.id > 0) payload.client_id = client.id;
    else payload.client = { client_type: client.client_type, display_name: client.display_name, email: client.email, phone: client.phone, address_city: client.address_city };
    onSubmit(payload);
  };

  return (
    <FormShell title={isQuote ? 'Nouveau devis' : 'Nouvelle facture'} onBack={onBack} onSubmit={submit}
      submitLabel={isQuote ? 'Créer le devis' : 'Créer la facture'} submitting={submitting} error={error}>

      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Client</Text>
        {clients && clients.length > 0 && (
          <TouchableOpacity onPress={() => setPickerOpen(true)} activeOpacity={0.7}><Text style={styles.formLink}>Choisir un client</Text></TouchableOpacity>
        )}
      </View>
      {client.id > 0 ? (
        <View style={styles.pickedClient}>
          <View style={{ flex: 1 }}>
            <Text style={styles.pickedName}>{client.display_name}</Text>
            <Text style={styles.pickedMail}>{client.email}</Text>
          </View>
          <TouchableOpacity onPress={() => setClient({ id: 0, client_type: 'company', display_name: '', email: '', phone: '', address_city: '' })} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
            <Ionicons name="close-circle" size={22} color="#CBD5E1" />
          </TouchableOpacity>
        </View>
      ) : (
        <>
          <Segmented options={[{ value: 'company', label: 'Entreprise' }, { value: 'individual', label: 'Particulier' }]} value={client.client_type} onChange={setC('client_type')} />
          <View style={{ height: 12 }} />
          <Field label="Nom du client *" value={client.display_name} onChangeText={setC('display_name')} autoCapitalize="words" />
          <Field label="Email *" value={client.email} onChangeText={setC('email')} keyboardType="email-address" autoCapitalize="none" hint="Un email déjà connu réutilise le client existant." />
        </>
      )}

      <Text style={[styles.formCardTitle, { marginTop: 20 }]}>Lignes</Text>
      {lines.map((l, i) => (
        <View key={i} style={styles.lineCard}>
          <View style={styles.lineCardHead}>
            <Text style={styles.lineCardIdx}>Ligne {i + 1}</Text>
            {lines.length > 1 && (
              <TouchableOpacity onPress={() => rmLine(i)} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}>
                <Ionicons name="trash-outline" size={18} color="#EF4444" />
              </TouchableOpacity>
            )}
          </View>
          <Field label="Désignation *" value={l.designation} onChangeText={(v) => setLine(i, 'designation', v)} />
          <View style={styles.line3}>
            <View style={{ flex: 1 }}><Field label="Qté" value={l.quantity} onChangeText={(v) => setLine(i, 'quantity', v)} keyboardType="decimal-pad" /></View>
            <View style={{ flex: 1.3 }}><Field label="P.U. HT €" value={l.unit_price_ht} onChangeText={(v) => setLine(i, 'unit_price_ht', v)} keyboardType="decimal-pad" /></View>
            <View style={{ flex: 1 }}><Field label="TVA %" value={l.vat_rate} onChangeText={(v) => setLine(i, 'vat_rate', v)} keyboardType="decimal-pad" /></View>
          </View>
        </View>
      ))}
      <TouchableOpacity style={styles.addLineBtn} onPress={addLine} activeOpacity={0.8}>
        <Ionicons name="add" size={18} color={BRAND} /><Text style={styles.addLineTxt}>Ajouter une ligne</Text>
      </TouchableOpacity>

      <View style={styles.totalsBox}>
        <View style={styles.dCardRow}><Text style={styles.dMuted}>Total HT</Text><Text style={styles.dMuted}>{fmtEuro(t.ht)}</Text></View>
        <View style={[styles.dCardRow, { marginTop: 4 }]}><Text style={styles.dMuted}>TVA</Text><Text style={styles.dMuted}>{fmtEuro(t.vat)}</Text></View>
        <View style={[styles.dCardRow, { marginTop: 8 }]}><Text style={styles.dCardLabel}>Total TTC</Text><Text style={styles.dTotal}>{fmtEuro(t.ttc)}</Text></View>
      </View>

      {!isQuote && <View style={{ marginTop: 16 }}><Field label="Échéance (jours)" value={dueDays} onChangeText={setDueDays} keyboardType="number-pad" /></View>}

      <Text style={[styles.fLabel, { marginTop: 8 }]}>Statut</Text>
      <Segmented
        options={isQuote ? [{ value: 'draft', label: 'Brouillon' }, { value: 'sent', label: 'Envoyé' }] : [{ value: 'pending', label: 'À encaisser' }, { value: 'draft', label: 'Brouillon' }]}
        value={status} onChange={setStatus} />

      <Modal visible={pickerOpen} transparent animationType="slide" onRequestClose={() => setPickerOpen(false)}>
        <Pressable style={styles.sheetBackdrop} onPress={() => setPickerOpen(false)}>
          <Pressable style={[styles.sheet, { maxHeight: '70%' }]}>
            <View style={styles.sheetHandle} />
            <Text style={styles.sheetTitle}>Clients existants</Text>
            <ScrollView>
              {(clients || []).map((c) => (
                <TouchableOpacity key={c.id} style={styles.qaRow} activeOpacity={0.7} onPress={() => pickClient(c)}>
                  <View style={[styles.personAvatar, { backgroundColor: '#2563EB', width: 40, height: 40, borderRadius: 12, marginRight: 12 }]}><Text style={styles.personAvatarTxt}>{c.initials}</Text></View>
                  <View style={{ flex: 1 }}><Text style={styles.qaLabel}>{c.name}</Text><Text style={styles.pickedMail}>{c.email}</Text></View>
                </TouchableOpacity>
              ))}
            </ScrollView>
          </Pressable>
        </Pressable>
      </Modal>
    </FormShell>
  );
}

function ProjectForm({ onBack, onSubmit, submitting, error, folders }) {
  const [name, setName] = useState('');
  const [folderId, setFolderId] = useState(0);
  const [newFolder, setNewFolder] = useState('');
  const [pickerOpen, setPickerOpen] = useState(false);
  const [steps, setSteps] = useState(['Préparation', 'Organisation', 'Réalisation', 'Bilan']);
  const [description, setDescription] = useState('');
  const [budget, setBudget] = useState('');

  const setStep = (i, v) => setSteps((s) => s.map((x, j) => (j === i ? v : x)));
  const addStep = () => setSteps((s) => [...s, '']);
  const rmStep = (i) => setSteps((s) => (s.length > 4 ? s.filter((_, j) => j !== i) : s));
  const selFolder = folders && folders.find((f) => f.id === folderId);

  const submit = () => {
    const payload = {
      name,
      steps: steps.map((t) => ({ title: t })).filter((x) => x.title.trim() !== ''),
      description,
      budget_planned: budget,
    };
    if (folderId > 0) payload.folder_id = folderId; else payload.new_folder = newFolder;
    onSubmit(payload);
  };

  return (
    <FormShell title="Nouveau projet" onBack={onBack} onSubmit={submit} submitLabel="Créer le projet" submitting={submitting} error={error}>
      <Field label="Nom du projet *" value={name} onChangeText={setName} autoCapitalize="sentences" />

      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Dossier</Text>
        {folders && folders.length > 0 && (
          <TouchableOpacity onPress={() => setPickerOpen(true)} activeOpacity={0.7}><Text style={styles.formLink}>Choisir un dossier</Text></TouchableOpacity>
        )}
      </View>
      {selFolder ? (
        <View style={styles.pickedClient}>
          <View style={{ flex: 1 }}><Text style={styles.pickedName}>{selFolder.name}</Text></View>
          <TouchableOpacity onPress={() => setFolderId(0)} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="close-circle" size={22} color="#CBD5E1" /></TouchableOpacity>
        </View>
      ) : (
        <Field label="Nouveau dossier *" value={newFolder} onChangeText={setNewFolder} placeholder="Ex : Événements 2026" autoCapitalize="sentences" hint="Un dossier regroupe vos projets." />
      )}

      <Text style={[styles.formCardTitle, { marginTop: 20 }]}>Étapes (4 minimum)</Text>
      {steps.map((s, i) => (
        <View key={i} style={styles.stepEditRow}>
          <Text style={styles.stepEditIdx}>{i + 1}</Text>
          <TextInput style={[styles.fInput, { flex: 1 }]} value={s} onChangeText={(v) => setStep(i, v)} placeholder={'Étape ' + (i + 1)} placeholderTextColor="#B6C0CC" />
          {steps.length > 4 && (
            <TouchableOpacity onPress={() => rmStep(i)} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }} style={{ marginLeft: 8 }}>
              <Ionicons name="close-circle" size={22} color="#CBD5E1" />
            </TouchableOpacity>
          )}
        </View>
      ))}
      <TouchableOpacity style={[styles.addLineBtn, { marginTop: 4 }]} onPress={addStep} activeOpacity={0.8}>
        <Ionicons name="add" size={18} color={BRAND} /><Text style={styles.addLineTxt}>Ajouter une étape</Text>
      </TouchableOpacity>

      <View style={{ height: 18 }} />
      <Field label="Description" value={description} onChangeText={setDescription} multiline numberOfLines={3} style={[styles.fInput, { height: 90, textAlignVertical: 'top' }]} />
      <Field label="Budget prévu (€)" value={budget} onChangeText={setBudget} keyboardType="decimal-pad" />

      <Modal visible={pickerOpen} transparent animationType="slide" onRequestClose={() => setPickerOpen(false)}>
        <Pressable style={styles.sheetBackdrop} onPress={() => setPickerOpen(false)}>
          <Pressable style={[styles.sheet, { maxHeight: '70%' }]}>
            <View style={styles.sheetHandle} />
            <Text style={styles.sheetTitle}>Dossiers</Text>
            <ScrollView>
              {(folders || []).map((fo) => (
                <TouchableOpacity key={fo.id} style={styles.qaRow} activeOpacity={0.7} onPress={() => { setFolderId(fo.id); setPickerOpen(false); }}>
                  <View style={[styles.shortcutIcon, { marginRight: 12 }]}><Ionicons name="folder" size={20} color={BRAND} /></View>
                  <Text style={styles.qaLabel}>{fo.name}</Text>
                </TouchableOpacity>
              ))}
            </ScrollView>
          </Pressable>
        </Pressable>
      </Modal>
    </FormShell>
  );
}

const EXPENSE_CATS = ['Matériel', 'Fournitures', 'Alimentation', 'Transport', 'Location', 'Télécom', 'Prestations externes', 'Frais administratifs', 'Autre'];

function ExpenseForm({ onBack, onSubmit, submitting, error, projects, preProject, scanData, scanning, onScan }) {
  const [projectId, setProjectId] = useState(preProject || 0);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [supplier, setSupplier] = useState('');
  const [category, setCategory] = useState('');
  const [mode, setMode] = useState('ttc');
  const [vat, setVat] = useState('20');
  const [amount, setAmount] = useState('');
  const [date, setDate] = useState('');
  const [desc, setDesc] = useState('');
  const [tempFile, setTempFile] = useState('');
  const [scanned, setScanned] = useState(false);

  // Applique les données extraites par l'IA
  useEffect(() => {
    if (!scanData) return;
    if (scanData.supplier_name) setSupplier(String(scanData.supplier_name));
    if (scanData.invoice_date) setDate(String(scanData.invoice_date));
    if (scanData.amount_ttc != null) { setAmount(String(scanData.amount_ttc)); setMode('ttc'); }
    if (scanData.vat_rate != null) setVat(String(scanData.vat_rate));
    if (scanData.category) setCategory(String(scanData.category));
    if (scanData.description) setDesc(String(scanData.description));
    if (scanData.temp_file) setTempFile(String(scanData.temp_file));
    setScanned(true);
  }, [scanData]);

  const selProject = projects && projects.find((p) => p.id === projectId);
  const submit = () => {
    onSubmit({
      project_id: projectId, supplier_name: supplier, category, description: desc,
      amount_mode: mode, vat_rate: vat, amount_ttc: mode !== 'ht' ? amount : '', amount_ht: mode === 'ht' ? amount : '',
      invoice_date: date, temp_file: tempFile,
    });
  };

  return (
    <FormShell title="Facture de projet" onBack={onBack} onSubmit={submit} submitLabel="Ajouter au projet" submitting={submitting} error={error}>
      <Text style={styles.fLabel}>Projet</Text>
      <TouchableOpacity style={styles.selectRow} activeOpacity={0.8} onPress={() => setPickerOpen(true)}>
        <Text style={[styles.selectVal, !selProject ? { color: '#B6C0CC' } : null]}>{selProject ? selProject.name : 'Choisir un projet…'}</Text>
        <Ionicons name="chevron-down" size={18} color={MUTE} />
      </TouchableOpacity>

      <TouchableOpacity style={[styles.scanBtn, (!projectId || scanning) ? { opacity: 0.5 } : null]} activeOpacity={0.85}
        onPress={() => { if (projectId && !scanning) onScan(projectId); }}>
        {scanning ? <ActivityIndicator color={BRAND} /> : <Ionicons name="camera" size={20} color={BRAND} />}
        <Text style={styles.scanBtnTxt}>{scanning ? 'Analyse en cours…' : (scanned ? 'Rescanner une photo' : 'Scanner la facture (IA)')}</Text>
      </TouchableOpacity>
      {!projectId && <Text style={styles.fHint}>Choisissez d'abord un projet pour scanner.</Text>}
      {scanned && <Text style={[styles.fHint, { color: BRAND }]}>✓ Champs pré-remplis par l'IA — vérifiez puis validez.</Text>}

      <View style={{ height: 8 }} />
      <Field label="Fournisseur *" value={supplier} onChangeText={setSupplier} autoCapitalize="words" />
      <Field label="Date *" value={date} onChangeText={setDate} placeholder="AAAA-MM-JJ" keyboardType="numbers-and-punctuation" />

      <Text style={styles.fLabel}>Montant</Text>
      <Segmented options={[{ value: 'ttc', label: 'TTC' }, { value: 'ht', label: 'HT' }, { value: 'no_vat', label: 'Sans TVA' }]} value={mode} onChange={setMode} />
      <View style={{ height: 12 }} />
      <View style={styles.line3}>
        <View style={{ flex: 1.4 }}><Field label={'Montant ' + (mode === 'ht' ? 'HT' : 'TTC') + ' €'} value={amount} onChangeText={setAmount} keyboardType="decimal-pad" /></View>
        {mode !== 'no_vat' && <View style={{ flex: 1 }}><Field label="TVA %" value={vat} onChangeText={setVat} keyboardType="decimal-pad" /></View>}
      </View>

      <Text style={styles.fLabel}>Catégorie</Text>
      <View style={styles.catWrap}>
        {EXPENSE_CATS.map((c) => (
          <TouchableOpacity key={c} style={[styles.catChip, category === c ? styles.catChipOn : null]} activeOpacity={0.8} onPress={() => setCategory(c)}>
            <Text style={[styles.catTxt, category === c ? styles.catTxtOn : null]}>{c}</Text>
          </TouchableOpacity>
        ))}
      </View>

      <View style={{ height: 14 }} />
      <Field label="Note" value={desc} onChangeText={setDesc} multiline numberOfLines={2} style={[styles.fInput, { height: 70, textAlignVertical: 'top' }]} />

      <Modal visible={pickerOpen} transparent animationType="slide" onRequestClose={() => setPickerOpen(false)}>
        <Pressable style={styles.sheetBackdrop} onPress={() => setPickerOpen(false)}>
          <Pressable style={[styles.sheet, { maxHeight: '70%' }]}>
            <View style={styles.sheetHandle} />
            <Text style={styles.sheetTitle}>Projets</Text>
            <ScrollView>
              {(projects || []).map((p) => (
                <TouchableOpacity key={p.id} style={styles.qaRow} activeOpacity={0.7} onPress={() => { setProjectId(p.id); setPickerOpen(false); }}>
                  <View style={[styles.shortcutIcon, { marginRight: 12 }]}><Ionicons name="folder" size={20} color={BRAND} /></View>
                  <View style={{ flex: 1 }}><Text style={styles.qaLabel}>{p.name}</Text><Text style={styles.pickedMail}>{p.folder}</Text></View>
                </TouchableOpacity>
              ))}
              {(!projects || projects.length === 0) && <Text style={[styles.dMuted, { padding: 16 }]}>Aucun projet actif.</Text>}
            </ScrollView>
          </Pressable>
        </Pressable>
      </Modal>
    </FormShell>
  );
}

/* ================================================================== */
/*  AGENDA (natif)                                                     */
/* ================================================================== */
function NativeAgenda({ data, loading, onRefresh, onOpen, onBack }) {
  const events = data ? (data.events || []) : null;
  // Grouper par jour
  const groups = [];
  if (events) {
    let cur = null;
    for (const e of events) {
      if (!cur || cur.key !== e.day_key) { cur = { key: e.day_key, label: e.day_label, items: [] }; groups.push(cur); }
      cur.items.push(e);
    }
  }
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Agenda" onBack={onBack} />
      {!events ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : events.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="calendar-outline" size={44} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun événement à venir</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {groups.map((g) => (
            <View key={g.key} style={{ marginBottom: 18 }}>
              <Text style={styles.agDay}>{g.label}</Text>
              {g.items.map((e) => (
                <TouchableOpacity key={e.id} style={styles.agCard} activeOpacity={0.85} onPress={() => onOpen(e.id)}>
                  <View style={[styles.agBar, { backgroundColor: e.color }]} />
                  <View style={styles.agTime}><Text style={styles.agTimeTxt}>{e.time}</Text></View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.agTitle} numberOfLines={2}>{e.title}</Text>
                    {(e.location || e.project) ? (
                      <Text style={styles.agSub} numberOfLines={1}>{[e.location, e.project].filter(Boolean).join(' · ')}</Text>
                    ) : null}
                  </View>
                </TouchableOpacity>
              ))}
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

/* ================================================================== */
/*  MES FACTURES (abonnement Stripe) — natif                           */
/* ================================================================== */
function NativeSubInvoices({ data, loading, onRefresh, onBack, onWeb }) {
  const list = data ? (data.invoices || []) : null;
  const s = (data && data.stats) || {};
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Mes factures" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <View style={styles.miniKpiRow}>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(s.total_paid)}</Text><Text style={styles.miniKpiLbl}>Total payé</Text></View>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.nb_paid || 0}</Text><Text style={styles.miniKpiLbl}>Payées</Text></View>
            <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: s.nb_pending ? '#B45309' : '#047857' }]}>{s.nb_pending || 0}</Text><Text style={styles.miniKpiLbl}>En attente</Text></View>
          </View>
          {list.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="receipt-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune facture</Text></View>
          ) : list.map((inv, i) => {
            const km = INV_KIND[inv.status_kind] || INV_KIND.wait;
            return (
              <TouchableOpacity key={i} style={styles.invCard} activeOpacity={inv.pdf ? 0.85 : 1} onPress={() => inv.pdf && onWeb(inv.pdf)}>
                <View style={{ flex: 1, paddingRight: 10 }}>
                  <Text style={styles.invNum} numberOfLines={1}>{inv.number || '—'}</Text>
                  <Text style={styles.invClient} numberOfLines={1}>{inv.date}{inv.period ? '  ·  ' + inv.period : ''}</Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={styles.invAmount}>{fmtEuro(inv.amount)}</Text>
                  <View style={[styles.projChip, { backgroundColor: km.bg, marginTop: 5 }]}><Text style={[styles.projChipTxt, { color: km.color }]}>{inv.status_label}</Text></View>
                </View>
              </TouchableOpacity>
            );
          })}
        </ScrollView>
      )}
    </View>
  );
}

/* ================================================================== */
/*  MESSAGES (canaux + chat natif)                                     */
/* ================================================================== */
const CHAN_ICON = { announce: 'megaphone', private: 'lock-closed', public: 'chatbubbles' };

function NativeChannels({ data, loading, onRefresh, onOpen, onBack }) {
  const list = data ? (data.channels || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Messages" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="chatbubbles-outline" size={44} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun canal</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {list.map((c) => (
            <TouchableOpacity key={c.id} style={styles.chanCard} activeOpacity={0.85} onPress={() => onOpen(c)}>
              <View style={[styles.chanIcon, { backgroundColor: (c.color || BRAND) + '22' }]}>
                <Ionicons name={CHAN_ICON[c.type] || 'chatbubbles'} size={20} color={c.color || BRAND} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.chanName} numberOfLines={1}>{c.name}</Text>
                <Text style={styles.chanSub}>{c.count} message{c.count > 1 ? 's' : ''}</Text>
              </View>
              {c.unread ? <View style={styles.chanDot} /> : null}
              <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
            </TouchableOpacity>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

function NativeChat({ channel, data, loading, sending, onBack, onSend, onRefresh }) {
  const [text, setText] = useState('');
  const msgs = data ? (data.messages || []) : null;
  const scRef = useRef(null);
  const submit = () => { const t = text.trim(); if (!t || sending) return; onSend(channel.id, t); setText(''); };
  return (
    <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={0}>
      <DetailHeader title={channel.name} onBack={onBack} />
      {!msgs ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView ref={scRef} contentContainerStyle={{ padding: 14, paddingBottom: 18 }} showsVerticalScrollIndicator={false}
          onContentSizeChange={() => scRef.current && scRef.current.scrollToEnd({ animated: false })}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {msgs.length === 0 ? (
            <View style={{ alignItems: 'center', paddingTop: 40 }}><Text style={styles.dMuted}>Aucun message. Lancez la discussion !</Text></View>
          ) : msgs.map((m) => (
            <View key={m.id} style={[styles.msgRow, m.is_self ? styles.msgRowSelf : null]}>
              {!m.is_self && <View style={[styles.msgAvatar, { backgroundColor: m.color || BRAND }]}><Text style={styles.msgAvatarTxt}>{m.initials}</Text></View>}
              <View style={[styles.msgBubbleWrap, m.is_self ? { alignItems: 'flex-end' } : null]}>
                {!m.is_self && <Text style={styles.msgAuthor}>{m.author}</Text>}
                {m.reply ? (
                  <View style={styles.msgReply}><Text style={styles.msgReplyAuthor} numberOfLines={1}>{m.reply.author}</Text><Text style={styles.msgReplyTxt} numberOfLines={1}>{m.reply.content}</Text></View>
                ) : null}
                <View style={[styles.msgBubble, m.is_self ? styles.msgBubbleSelf : null]}>
                  <Text style={[styles.msgTxt, m.is_self ? { color: '#fff' } : null]}>{m.content}</Text>
                </View>
                <Text style={styles.msgTime}>{m.time}</Text>
              </View>
            </View>
          ))}
        </ScrollView>
      )}
      <View style={styles.composer}>
        <TextInput style={styles.composerInput} value={text} onChangeText={setText} placeholder="Écrire un message…" placeholderTextColor="#94A3B8" multiline />
        <TouchableOpacity style={[styles.composerBtn, (!text.trim() || sending) ? { opacity: 0.5 } : null]} onPress={submit} activeOpacity={0.85}>
          {sending ? <ActivityIndicator color="#fff" size="small" /> : <Ionicons name="send" size={18} color="#fff" />}
        </TouchableOpacity>
      </View>
    </KeyboardAvoidingView>
  );
}

/* ================================================================== */
/*  MENU « PLUS » (hub natif)                                           */
/* ================================================================== */
const MORE_GROUPS = [
  {
    title: 'Association',
    items: [
      { label: 'Adhérents', icon: 'people', nav: { screen: 'members' } },
      { label: 'Agenda', icon: 'calendar', nav: { screen: 'agenda' } },
      { label: 'Cotisations', icon: 'card', nav: { screen: 'cotisations' } },
      { label: 'Subventions', icon: 'cash', nav: { screen: 'subventions' } },
      { label: 'Assemblées', icon: 'clipboard', nav: { screen: 'assemblies' } },
      { label: 'Émargement', icon: 'checkbox', nav: { screen: 'attendance' } },
    ],
  },
  {
    title: 'Finances',
    items: [
      { label: 'Factures', icon: 'receipt', nav: { tab: 'factures' } },
      { label: 'Devis', icon: 'document-text', nav: { screen: 'devis' } },
      { label: 'Clients', icon: 'briefcase', nav: { screen: 'clients' } },
      { label: 'Statistiques', icon: 'stats-chart', nav: { screen: 'stats' } },
      { label: 'Mon abonnement', icon: 'card-outline', nav: { screen: 'subinvoices' } },
    ],
  },
  {
    title: 'Communication',
    items: [
      { label: 'Messages', icon: 'chatbubbles', nav: { screen: 'messages' } },
      { label: 'Notifications', icon: 'notifications', nav: { screen: 'notifications' } },
      { label: 'Communication', icon: 'mail', nav: { screen: 'broadcasts' } },
      { label: 'Coach IA', icon: 'sparkles', nav: { screen: 'coach' } },
    ],
  },
  {
    title: 'Compte',
    items: [
      { label: 'Paramètres', icon: 'settings', nav: { screen: 'settings' } },
      { label: 'Support', icon: 'help-buoy', nav: { screen: 'tickets' } },
    ],
  },
];

const FOUNDER_SHORTCUTS = [
  { label: 'Associations', icon: 'business', web: '/super-admin/associations' },
  { label: 'Abonnements', icon: 'card', web: '/super-admin/abonnements' },
  { label: 'Support', icon: 'chatbubbles', web: '/super-admin/support' },
  { label: 'Stats', icon: 'stats-chart', web: '/super-admin/stats' },
  { label: 'Super admins', icon: 'shield-checkmark', web: '/super-admin/super-admins' },
  { label: 'Pilotage', icon: 'grid', web: '/fondateur-pilotage' },
];

function NativeMore({ orgName, initials, logo, isFounder, onNav, onLogout }) {
  return (
    <View style={styles.detailWrap}>
      <View style={styles.moreHeader}>
        <View style={styles.moreAvatar}>
          {logo ? <Image source={{ uri: logo }} style={styles.moreAvatarImg} /> : <Text style={styles.moreAvatarTxt}>{initials || '·'}</Text>}
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.moreOrg} numberOfLines={1}>{orgName || 'Mon organisation'}</Text>
          <Text style={styles.moreSub}>Tous vos outils</Text>
        </View>
      </View>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 30 }} showsVerticalScrollIndicator={false}>
        {isFounder && (
          <View style={styles.founderBlock}>
            <TouchableOpacity style={styles.founderBanner} activeOpacity={0.9} onPress={() => onNav({ web: '/super-admin' })}>
              <View style={styles.founderStar}><Ionicons name="star" size={20} color="#78350F" /></View>
              <View style={{ flex: 1 }}>
                <Text style={styles.founderTitle}>Espace Fondateur</Text>
                <Text style={styles.founderSub}>Piloter toute la plateforme Assokit</Text>
              </View>
              <Ionicons name="arrow-forward" size={18} color="#FCD34D" />
            </TouchableOpacity>
            <View style={styles.founderGrid}>
              {FOUNDER_SHORTCUTS.map((it) => (
                <TouchableOpacity key={it.label} style={styles.founderItem} activeOpacity={0.8} onPress={() => onNav({ web: it.web })}>
                  <View style={styles.founderItemIcon}><Ionicons name={it.icon} size={20} color="#B45309" /></View>
                  <Text style={styles.founderItemTxt} numberOfLines={1}>{it.label}</Text>
                </TouchableOpacity>
              ))}
            </View>
          </View>
        )}
        {MORE_GROUPS.map((g) => (
          <View key={g.title} style={{ marginBottom: 20 }}>
            <Text style={styles.moreGroupTitle}>{g.title}</Text>
            <View style={styles.moreGrid}>
              {g.items.map((it) => (
                <TouchableOpacity key={it.label} style={styles.moreItem} activeOpacity={0.8} onPress={() => onNav(it.nav)}>
                  <View style={styles.moreItemIcon}><Ionicons name={it.icon} size={22} color={BRAND} /></View>
                  <Text style={styles.moreItemTxt} numberOfLines={1}>{it.label}</Text>
                </TouchableOpacity>
              ))}
            </View>
          </View>
        ))}
        <TouchableOpacity style={styles.logoutBtn} activeOpacity={0.85} onPress={onLogout}>
          <Ionicons name="log-out-outline" size={19} color="#DC2626" />
          <Text style={styles.logoutTxt}>Se déconnecter</Text>
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

/* ================================================================== */
/*  DEVIS / STATS / NOTIFS / COTISATIONS / SUBVENTIONS (natifs)        */
/* ================================================================== */
function NativeQuotes({ data, loading, onRefresh, onOpen, onNew, onBack }) {
  const list = data ? (data.quotes || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Devis" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <TouchableOpacity style={[styles.projNewBtn, { alignSelf: 'flex-start', marginBottom: 14 }]} onPress={onNew} activeOpacity={0.85}>
            <Ionicons name="add" size={18} color="#fff" /><Text style={styles.projNewTxt}>Nouveau devis</Text>
          </TouchableOpacity>
          {list.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="document-text-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun devis</Text></View>
          ) : list.map((q) => {
            const km = INV_KIND[q.status_kind] || INV_KIND.wait;
            return (
              <TouchableOpacity key={q.id} style={styles.invCard} activeOpacity={0.85} onPress={() => onOpen(q.id)}>
                <View style={{ flex: 1, paddingRight: 10 }}>
                  <Text style={styles.invNum} numberOfLines={1}>{q.number}</Text>
                  <Text style={styles.invClient} numberOfLines={1}>{q.client || '—'}{q.date ? '  ·  ' + q.date : ''}</Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={styles.invAmount}>{fmtEuro(q.amount)}</Text>
                  <View style={[styles.projChip, { backgroundColor: km.bg, marginTop: 5 }]}><Text style={[styles.projChipTxt, { color: km.color }]}>{q.status_label}</Text></View>
                </View>
              </TouchableOpacity>
            );
          })}
        </ScrollView>
      )}
    </View>
  );
}

function NativeStats({ data, loading, onRefresh, onBack, cockpit, cockpitLoading, onCockpit }) {
  if (!data) return <DetailLoading title="Cockpit" onBack={onBack} />;
  if (data.allowed === false) {
    return (
      <View style={styles.detailWrap}>
        <DetailHeader title="Cockpit" onBack={onBack} />
        <View style={styles.emptyBox}><Ionicons name="lock-closed" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>{data.message || 'Réservé aux administrateurs.'}</Text></View>
      </View>
    );
  }
  const k = data.kpis || {};
  const months = data.months || [];
  const maxv = data.month_max || 1;
  const top = data.top_clients || [];
  const cards = [
    { label: 'CA encaissé', value: fmtEuro(k.ca), color: '#047857' },
    { label: 'En attente', value: fmtEuro(k.pending), color: '#B45309' },
    { label: 'Factures', value: String(k.nb_invoices || 0), color: INK },
    { label: 'Conversion devis', value: (k.conversion || 0) + '%', color: '#2563EB' },
  ];
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title={'Cockpit ' + (data.year || '')} onBack={onBack} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 28 }} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>

        <LinearGradient colors={['#4F46E5', '#7C3AED']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.cockpitCard}>
          <View style={styles.cockpitHead}>
            <Ionicons name="sparkles" size={18} color="#fff" />
            <Text style={styles.cockpitTitle}>Directeur financier IA</Text>
            {cockpit && cockpit.health ? <View style={styles.healthPill}><Text style={styles.healthTxt}>{cockpit.health}</Text></View> : null}
          </View>
          {cockpit ? (
            <>
              {!!cockpit.summary && <Text style={styles.cockpitSummary}>{cockpit.summary}</Text>}
              {(cockpit.insights || []).map((s, i) => (
                <View key={i} style={styles.cockpitInsight}><Text style={styles.cockpitBullet}>›</Text><Text style={styles.cockpitInsightTxt}>{s}</Text></View>
              ))}
              {(cockpit.actions || []).length > 0 && (
                <View style={{ marginTop: 12, gap: 8 }}>
                  {cockpit.actions.map((a, i) => (
                    <View key={i} style={styles.cockpitAction}>
                      <Text style={{ fontSize: 20 }}>{a.icon}</Text>
                      <View style={{ flex: 1 }}><Text style={styles.cockpitActTitle}>{a.title}</Text>{!!a.why && <Text style={styles.cockpitActWhy}>{a.why}</Text>}</View>
                    </View>
                  ))}
                </View>
              )}
            </>
          ) : (
            <Text style={styles.cockpitEmpty}>Une analyse complète de votre santé financière et vos actions prioritaires, par l'IA.</Text>
          )}
          <TouchableOpacity style={[styles.cockpitBtn, cockpitLoading ? { opacity: 0.7 } : null]} activeOpacity={0.85} onPress={cockpitLoading ? undefined : onCockpit}>
            {cockpitLoading ? <ActivityIndicator size="small" color="#4F46E5" /> : <Ionicons name="sparkles" size={16} color="#4F46E5" />}
            <Text style={styles.cockpitBtnTxt}>{cockpitLoading ? 'Analyse en cours…' : (cockpit ? 'Actualiser' : 'Générer mon cockpit IA')}</Text>
          </TouchableOpacity>
        </LinearGradient>

        <View style={styles.statGrid}>
          {cards.map((c) => (
            <View key={c.label} style={styles.statCard}>
              <Text style={[styles.statVal, { color: c.color }]}>{c.value}</Text>
              <Text style={styles.statLbl}>{c.label}</Text>
            </View>
          ))}
        </View>
        {months.length > 0 && (
          <>
            <Text style={styles.dSection}>Encaissé (6 mois)</Text>
            <View style={[styles.dCard, { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', height: 150, paddingTop: 20 }]}>
              {months.map((m, i) => (
                <View key={i} style={{ flex: 1, alignItems: 'center' }}>
                  <Text style={styles.barVal}>{m.paid >= 1000 ? Math.round(m.paid / 1000) + 'k' : Math.round(m.paid)}</Text>
                  <View style={[styles.bar, { height: Math.max(4, Math.round((m.paid / maxv) * 80)) }]} />
                  <Text style={styles.barLbl}>{m.label}</Text>
                </View>
              ))}
            </View>
          </>
        )}
        {top.length > 0 && (
          <>
            <Text style={styles.dSection}>Meilleurs clients</Text>
            <View style={styles.dCard}>
              {top.map((c, i) => (
                <View key={i} style={[styles.bilanRow, i > 0 ? { borderTopWidth: 1, borderTopColor: '#F1F5F9' } : null]}>
                  <View style={{ flex: 1, paddingRight: 10 }}>
                    <Text style={styles.bilanLabel} numberOfLines={1}>{c.name}</Text>
                    <Text style={styles.bilanCount}>{c.nb} facture{c.nb > 1 ? 's' : ''}</Text>
                  </View>
                  <Text style={styles.bilanAmount}>{fmtEuro(c.paid)}</Text>
                </View>
              ))}
            </View>
          </>
        )}
      </ScrollView>
    </View>
  );
}

function NativeNotifications({ data, loading, onRefresh, onOpen, onBack }) {
  const list = data ? (data.items || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Notifications" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="notifications-off-outline" size={44} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune notification</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {list.map((n) => (
            <TouchableOpacity key={n.id} style={[styles.notifCard, !n.read ? styles.notifUnread : null]} activeOpacity={n.link ? 0.85 : 1} onPress={() => n.link && onOpen(n.link)}>
              <View style={[styles.notifIcon, !n.read ? { backgroundColor: '#ECFDF5' } : null]}><Ionicons name={n.icon} size={18} color={!n.read ? BRAND : '#94A3B8'} /></View>
              <View style={{ flex: 1 }}>
                <Text style={[styles.notifTitle, !n.read ? { fontWeight: '700' } : null]} numberOfLines={2}>{n.title}</Text>
                {!!n.body && <Text style={styles.notifBody} numberOfLines={2}>{n.body}</Text>}
                <Text style={styles.notifAgo}>{n.ago}</Text>
              </View>
              {!n.read ? <View style={styles.chanDot} /> : null}
            </TouchableOpacity>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

function NativeCotisations({ data, loading, onRefresh, onBack }) {
  const list = data ? (data.campaigns || []) : null;
  const s = (data && data.stats) || {};
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Cotisations" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <View style={styles.miniKpiRow}>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(s.total)}</Text><Text style={styles.miniKpiLbl}>Encaissé</Text></View>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.active || 0}</Text><Text style={styles.miniKpiLbl}>Campagnes actives</Text></View>
          </View>
          {list.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="card-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune campagne</Text></View>
          ) : list.map((c) => (
            <View key={c.id} style={styles.projCard}>
              <View style={styles.projCardTop}>
                <View style={{ flex: 1, paddingRight: 10 }}>
                  <Text style={styles.projName} numberOfLines={1}>{c.name}</Text>
                  <Text style={styles.projFolder}>{c.year} · {c.paid} payé{c.paid > 1 ? 's' : ''} · {c.pending} en attente</Text>
                </View>
                <View style={[styles.projChip, { backgroundColor: c.active ? '#D1FAE5' : '#F1F5F9' }]}>
                  <Text style={[styles.projChipTxt, { color: c.active ? '#065F46' : '#64748B' }]}>{c.active ? 'Active' : 'Clôturée'}</Text>
                </View>
              </View>
              <Text style={[styles.dTotal, { marginTop: 10, fontSize: 18 }]}>{fmtEuro(c.total)}</Text>
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

function NativeGrants({ data, loading, onRefresh, onBack }) {
  const list = data ? (data.grants || []) : null;
  const s = (data && data.stats) || {};
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Subventions" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <View style={styles.miniKpiRow}>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(s.granted)}</Text><Text style={styles.miniKpiLbl}>Accordé</Text></View>
            <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: INK }]}>{fmtEuro(s.requested)}</Text><Text style={styles.miniKpiLbl}>Demandé</Text></View>
            <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: '#B45309' }]}>{s.pending || 0}</Text><Text style={styles.miniKpiLbl}>En cours</Text></View>
          </View>
          {list.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="cash-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune subvention</Text></View>
          ) : list.map((g) => {
            const km = INV_KIND[g.status_kind] || INV_KIND.wait;
            return (
              <View key={g.id} style={styles.projCard}>
                <View style={styles.projCardTop}>
                  <View style={{ flex: 1, paddingRight: 10 }}>
                    <Text style={styles.projName} numberOfLines={2}>{g.name}</Text>
                    <Text style={styles.projFolder} numberOfLines={1}>{[g.funder, g.funder_type].filter(Boolean).join(' · ')}</Text>
                  </View>
                  <View style={[styles.projChip, { backgroundColor: km.bg }]}><Text style={[styles.projChipTxt, { color: km.color }]}>{g.status_label}</Text></View>
                </View>
                <View style={[styles.dCardRow, { marginTop: 12 }]}>
                  <Text style={styles.dMuted}>{g.deadline ? 'Échéance ' + g.deadline : ' '}</Text>
                  <Text style={styles.bilanAmount}>{fmtEuro(g.status === 'granted' ? g.granted : g.requested)}</Text>
                </View>
              </View>
            );
          })}
        </ScrollView>
      )}
    </View>
  );
}

function NativeEventDetail({ entry, onBack, onRefresh, onWeb }) {
  const d = entry.data;
  if (d && d.ok === false) return <DetailError title="Événement" onBack={onBack} onRetry={onRefresh} />;
  if (!d || !d.event) return <DetailLoading title="Événement" onBack={onBack} />;
  const e = d.event;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Événement" onBack={onBack} />
      <ScrollView contentContainerStyle={styles.detailContent} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!entry.loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <View style={[styles.projChip, { backgroundColor: e.color + '22', alignSelf: 'flex-start', marginBottom: 10 }]}>
          <Text style={[styles.projChipTxt, { color: e.color }]}>{e.type_label}</Text>
        </View>
        <Text style={styles.dName}>{e.title}</Text>
        <View style={styles.dCard}>
          <InfoRow icon="time" label="Quand" value={e.when} />
          <InfoRow icon="location" label="Lieu" value={e.location} />
          <InfoRow icon="folder" label="Projet" value={e.project} />
        </View>
        {!!e.description && (<><Text style={styles.dSection}>Description</Text><Text style={styles.dText}>{e.description}</Text></>)}
        <TouchableOpacity style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb('/evenement/' + e.id)}>
          <Text style={styles.dWebBtnTxt}>Ouvrir la fiche complète</Text>
          <Ionicons name="open-outline" size={18} color={BRAND} />
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

/* ================================================================== */
/*  ASSEMBLÉES / ÉMARGEMENT / COMMUNICATION / SUPPORT / COACH / RÉGLAGES */
/* ================================================================== */
function GatedList({ title, data, loading, onRefresh, onBack, emptyIcon, emptyLabel, renderStats, renderItem, itemsKey }) {
  const allowed = !data || data.allowed !== false;
  const list = data ? (data[itemsKey] || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title={title} onBack={onBack} />
      {!data ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : !allowed ? (
        <View style={styles.emptyBox}><Ionicons name="lock-closed" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>{data.message || 'Accès restreint.'}</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {renderStats ? renderStats(data.stats || {}) : null}
          {(!list || list.length === 0) ? (
            <View style={styles.emptyBox}><Ionicons name={emptyIcon} size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>{emptyLabel}</Text></View>
          ) : list.map(renderItem)}
        </ScrollView>
      )}
    </View>
  );
}

function StatusItemCard({ title, sub, right, kind, statusLabel }) {
  const km = INV_KIND[kind] || INV_KIND.wait;
  return (
    <View style={styles.projCard}>
      <View style={styles.projCardTop}>
        <View style={{ flex: 1, paddingRight: 10 }}>
          <Text style={styles.projName} numberOfLines={2}>{title}</Text>
          {!!sub && <Text style={styles.projFolder} numberOfLines={1}>{sub}</Text>}
        </View>
        {statusLabel ? <View style={[styles.projChip, { backgroundColor: km.bg }]}><Text style={[styles.projChipTxt, { color: km.color }]}>{statusLabel}</Text></View> : right}
      </View>
      {right && statusLabel ? <View style={{ marginTop: 10, alignItems: 'flex-end' }}>{right}</View> : null}
    </View>
  );
}

function NativeCoach({ data, loading, generating, onGenerate, onRefresh, onBack }) {
  if (!data) return <DetailLoading title="Coach IA" onBack={onBack} />;
  if (data.allowed === false) {
    return (<View style={styles.detailWrap}><DetailHeader title="Coach IA" onBack={onBack} />
      <View style={styles.emptyBox}><Ionicons name="lock-closed" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>{data.message || 'Réservé aux administrateurs.'}</Text></View></View>);
  }
  const r = data.report;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Coach IA" onBack={onBack} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 28 }} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        {!r ? (
          <View style={styles.emptyBox}><Ionicons name="sparkles" size={44} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun rapport encore</Text></View>
        ) : (
          <>
            {!!r.week && <Text style={styles.coachWeek}>Semaine {r.week}</Text>}
            {!!r.summary && <View style={styles.dCard}><Text style={styles.dText}>{r.summary}</Text></View>}
            {r.highlights && r.highlights.length > 0 && (
              <><Text style={styles.dSection}>Points forts</Text>{r.highlights.map((h, i) => (
                <View key={i} style={styles.coachRow}><Ionicons name="checkmark-circle" size={18} color="#10B981" /><Text style={styles.coachRowTxt}>{h}</Text></View>
              ))}</>
            )}
            {r.warnings && r.warnings.length > 0 && (
              <><Text style={styles.dSection}>Vigilance</Text>{r.warnings.map((w, i) => (
                <View key={i} style={styles.coachRow}><Ionicons name="alert-circle" size={18} color="#D97706" /><Text style={styles.coachRowTxt}>{w}</Text></View>
              ))}</>
            )}
            {r.recos && r.recos.length > 0 && (
              <><Text style={styles.dSection}>Actions recommandées</Text>{r.recos.map((a, i) => (
                <View key={i} style={styles.recoCard}>
                  <Text style={styles.recoIcon}>{a.icon}</Text>
                  <View style={{ flex: 1 }}><Text style={styles.recoTitle}>{a.title}</Text>{!!a.why && <Text style={styles.recoWhy}>{a.why}</Text>}</View>
                </View>
              ))}</>
            )}
          </>
        )}
        <TouchableOpacity style={[styles.dPrimaryBtn, generating ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={generating ? undefined : onGenerate}>
          {generating ? <ActivityIndicator color="#fff" /> : <Ionicons name="sparkles" size={18} color="#fff" />}
          <Text style={styles.dPrimaryBtnTxt}>{generating ? 'Génération…' : (r ? 'Générer un nouveau rapport' : 'Générer mon rapport')}</Text>
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

function NativeSettings({ data, onBack, onSave, saving, error, onLogo, logoBusy, onDelete, onLogout, onWeb }) {
  const a = (data && data.account) || null;
  const [f, setF] = useState(null);
  useEffect(() => { if (a && !f) setF({ first_name: a.first_name, last_name: a.last_name, email: a.email, phone: a.phone, city: a.city }); }, [a]);
  if (!data || !f) return <DetailLoading title="Paramètres" onBack={onBack} />;
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));
  const org = data.org || {};
  return (
    <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <DetailHeader title="Paramètres" onBack={onBack} />
      <ScrollView contentContainerStyle={{ padding: 18, paddingBottom: 40 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
        {org.is_admin && (
          <>
            <Text style={styles.formCardTitle}>Logo de l'organisation</Text>
            <View style={styles.logoRow}>
              <View style={styles.logoBox}>
                {org.logo ? <Image source={{ uri: org.logo }} style={styles.logoImg} /> : <Ionicons name="image-outline" size={26} color="#CBD5E1" />}
              </View>
              <TouchableOpacity style={[styles.scanBtn, { flex: 1 }, logoBusy ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={logoBusy ? undefined : onLogo}>
                {logoBusy ? <ActivityIndicator color={BRAND} /> : <Ionicons name="cloud-upload" size={18} color="#0369A1" />}
                <Text style={styles.scanBtnTxt}>{logoBusy ? 'Envoi…' : 'Changer le logo'}</Text>
              </TouchableOpacity>
            </View>
          </>
        )}

        <Text style={[styles.formCardTitle, { marginTop: 20 }]}>Mon compte</Text>
        {!!error && <View style={styles.formErr}><Ionicons name="alert-circle" size={18} color="#B91C1C" /><Text style={styles.formErrTxt}>{error}</Text></View>}
        <Field label="Prénom" value={f.first_name} onChangeText={set('first_name')} autoCapitalize="words" />
        <Field label="Nom" value={f.last_name} onChangeText={set('last_name')} autoCapitalize="words" />
        <Field label="Email" value={f.email} onChangeText={set('email')} keyboardType="email-address" autoCapitalize="none" />
        <Field label="Téléphone" value={f.phone} onChangeText={set('phone')} keyboardType="phone-pad" />
        <Field label="Ville" value={f.city} onChangeText={set('city')} autoCapitalize="words" />
        <TouchableOpacity style={[styles.dPrimaryBtn, saving ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={saving ? undefined : () => onSave(f)}>
          {saving ? <ActivityIndicator color="#fff" /> : <Ionicons name="checkmark-circle" size={18} color="#fff" />}
          <Text style={styles.dPrimaryBtnTxt}>{saving ? 'Enregistrement…' : 'Enregistrer'}</Text>
        </TouchableOpacity>

        <TouchableOpacity style={styles.settingsRow} activeOpacity={0.7} onPress={() => onWeb('/parametres?tab=securite')}>
          <Ionicons name="lock-closed-outline" size={20} color="#475569" />
          <Text style={styles.settingsRowTxt}>Sécurité & mot de passe</Text>
          <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
        </TouchableOpacity>
        {org.is_admin && (
          <TouchableOpacity style={styles.settingsRow} activeOpacity={0.7} onPress={() => onWeb('/parametres?tab=organisation')}>
            <Ionicons name="business-outline" size={20} color="#475569" />
            <Text style={styles.settingsRowTxt}>Infos de l'organisation</Text>
            <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
          </TouchableOpacity>
        )}

        <TouchableOpacity style={styles.logoutBtn} activeOpacity={0.85} onPress={onLogout}>
          <Ionicons name="log-out-outline" size={19} color="#DC2626" /><Text style={styles.logoutTxt}>Se déconnecter</Text>
        </TouchableOpacity>
        {a.can_delete && (
          <TouchableOpacity style={{ marginTop: 14, alignItems: 'center' }} activeOpacity={0.7} onPress={onDelete}>
            <Text style={styles.deleteTxt}>Supprimer mon compte (RGPD)</Text>
          </TouchableOpacity>
        )}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

/* ================================================================== */
/*  SHELL (WebView + nav native + accueil natif)                       */
/* ================================================================== */
function AppShell({ startPath, pushToken, autoCreds, onSaveCreds, onClearCreds, onLogout, onExitToWelcome }) {
  const webRef = useRef(null);
  const pushRegistered = useRef(false);
  const autoLoginTried = useRef(false);
  const pendingCreds = useRef(null);
  const lastUrl = useRef('');
  const [pdfBusy, setPdfBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [canGoBack, setCanGoBack] = useState(false);
  const [active, setActive] = useState('accueil');
  const [quickOpen, setQuickOpen] = useState(false);
  const [authed, setAuthed] = useState(false);
  const [kpi, setKpi] = useState(null);
  const [kpiLoading, setKpiLoading] = useState(false);
  const [projects, setProjects] = useState(null);
  const [projLoading, setProjLoading] = useState(false);
  const [people, setPeople] = useState(null);
  const [peopleLoading, setPeopleLoading] = useState(false);
  const [invoices, setInvoices] = useState(null);
  const [invLoading, setInvLoading] = useState(false);
  const [webMode, setWebMode] = useState(false);
  const [stack, setStack] = useState([]); // pile de fiches détail natives
  const [form, setForm] = useState(null); // { type } formulaire natif ouvert
  const [formErr, setFormErr] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [csrf, setCsrf] = useState('');
  const [pickClients, setPickClients] = useState([]);
  const [folders, setFolders] = useState([]);
  const [scanData, setScanData] = useState(null);
  const [scanning, setScanning] = useState(false);
  const [expenseProject, setExpenseProject] = useState(0);
  const [menuScreen, setMenuScreen] = useState(null); // null=hub | 'agenda' | 'messages' | 'subinvoices'
  const [events, setEvents] = useState(null);
  const [eventsLoading, setEventsLoading] = useState(false);
  const [channels, setChannels] = useState(null);
  const [channelsLoading, setChannelsLoading] = useState(false);
  const [openChannel, setOpenChannel] = useState(null);
  const [chanMsgs, setChanMsgs] = useState(null);
  const [chanLoading, setChanLoading] = useState(false);
  const [sendingMsg, setSendingMsg] = useState(false);
  const [subInv, setSubInv] = useState(null);
  const [subInvLoading, setSubInvLoading] = useState(false);
  const [quotes, setQuotes] = useState(null);
  const [quotesLoading, setQuotesLoading] = useState(false);
  const [stats, setStats] = useState(null);
  const [statsLoading, setStatsLoading] = useState(false);
  const [notifs, setNotifs] = useState(null);
  const [notifsLoading, setNotifsLoading] = useState(false);
  const [coti, setCoti] = useState(null);
  const [cotiLoading, setCotiLoading] = useState(false);
  const [grantsData, setGrantsData] = useState(null);
  const [grantsLoading, setGrantsLoading] = useState(false);
  const [assemblies, setAssemblies] = useState(null);
  const [attendance, setAttendance] = useState(null);
  const [broadcasts, setBroadcasts] = useState(null);
  const [tickets, setTickets] = useState(null);
  const [coach, setCoach] = useState(null);
  const [coachGen, setCoachGen] = useState(false);
  const [account, setAccount] = useState(null);
  const [settingsBusy, setSettingsBusy] = useState(false);
  const [settingsErr, setSettingsErr] = useState('');
  const [logoBusy, setLogoBusy] = useState(false);
  const [secLoading, setSecLoading] = useState(false);
  const [invAI, setInvAI] = useState(null);
  const [invAILoading, setInvAILoading] = useState(false);
  const [statsCockpit, setStatsCockpit] = useState(null);
  const [statsCockpitLoading, setStatsCockpitLoading] = useState(false);

  const profile = (kpi && kpi.profile) === 'tpe' ? 'tpe' : 'asso';
  const isTpe = profile === 'tpe';
  const TABS = tabsFor(profile);
  const QUICK_ACTIONS = isTpe ? QUICK_ACTIONS_TPE : QUICK_ACTIONS_ASSO;

  const inject = useCallback((js) => {
    if (webRef.current) webRef.current.injectJavaScript(js);
  }, []);

  const fetchKpis = useCallback(() => {
    setKpiLoading(true);
    inject(FETCH_KPIS_JS);
  }, [inject]);

  const fetchProjects = useCallback(() => {
    setProjLoading(true);
    inject(FETCH_PROJECTS_JS);
  }, [inject]);

  const fetchPeople = useCallback((tpe) => {
    setPeopleLoading(true);
    setPeople(null);
    inject(tpe ? FETCH_CLIENTS_JS : FETCH_MEMBERS_JS);
  }, [inject]);

  const fetchInvoices = useCallback(() => {
    setInvLoading(true);
    inject(FETCH_INVOICES_JS);
  }, [inject]);

  // --- Pile de fiches détail natives ---------------------------------
  const fetchDetail = useCallback((type, id) => {
    inject(fetchJS('/api/app-' + type + '.php?id=' + id, '__akdetail'));
  }, [inject]);

  const pushDetail = useCallback((type, id) => {
    setStack((s) => [...s, { type, id, data: null, loading: true, bilan: null }]);
    fetchDetail(type, id);
    if (type === 'project') inject(fetchJS('/api/app-project-bilan.php?id=' + id, '__akbilan'));
  }, [fetchDetail, inject]);

  const popDetail = useCallback(() => {
    setStack((s) => s.slice(0, -1));
  }, []);

  const clearDetail = useCallback(() => setStack([]), []);
  const closeForm = useCallback(() => { setForm(null); setFormErr(''); setSubmitting(false); }, []);

  const refreshDetail = useCallback(() => {
    setStack((s) => {
      if (!s.length) return s;
      const top = s[s.length - 1];
      fetchDetail(top.type, top.id);
      const cp = s.slice();
      cp[cp.length - 1] = { ...top, loading: true };
      return cp;
    });
  }, [fetchDetail]);

  // --- Formulaires natifs (création) ---------------------------------
  const CREATE_ENDPOINTS = {
    member:  '/api/app-create-member.php',
    client:  '/api/app-create-client.php',
    invoice: '/api/app-create-invoice.php',
    quote:   '/api/app-create-quote.php',
    project: '/api/app-create-project.php',
    expense: '/api/app-create-expense.php',
  };

  const openForm = useCallback((type, preId = 0) => {
    setQuickOpen(false);
    clearDetail();
    setWebMode(false);
    setFormErr('');
    setForm({ type });
    inject(FETCH_CSRF_JS);
    if (type === 'invoice' || type === 'quote') inject(fetchJS('/api/app-clients.php', '__akpick'));
    if (type === 'project') inject(fetchJS('/api/app-folders.php', '__akfolders'));
    if (type === 'expense') {
      setScanData(null);
      setScanning(false);
      setExpenseProject(preId || 0);
      if (!projects) inject(FETCH_PROJECTS_JS);
    }
  }, [inject, clearDetail, projects]);

  // Capture/sélection d'une photo + envoi à l'IA (scan de facture)
  const runScan = useCallback(async (source, projectId) => {
    try {
      const opts = { mediaTypes: ImagePicker.MediaTypeOptions.Images, base64: true, quality: 0.5 };
      let res;
      if (source === 'camera') {
        const perm = await ImagePicker.requestCameraPermissionsAsync();
        if (!perm.granted) { setFormErr('Autorisez l\'appareil photo pour scanner.'); return; }
        res = await ImagePicker.launchCameraAsync(opts);
      } else {
        const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
        if (!perm.granted) { setFormErr('Autorisez l\'accès aux photos pour scanner.'); return; }
        res = await ImagePicker.launchImageLibraryAsync(opts);
      }
      if (res.canceled || !res.assets || !res.assets[0] || !res.assets[0].base64) return;
      if (!csrf) { setFormErr('Session en préparation, réessayez.'); inject(FETCH_CSRF_JS); return; }
      setScanning(true);
      setFormErr('');
      inject(scanJS(res.assets[0].base64, 'image/jpeg', projectId, csrf));
    } catch (e) {
      setScanning(false);
      setFormErr('Impossible d\'ouvrir la caméra / photothèque.');
    }
  }, [csrf, inject]);

  const pickAndScan = useCallback((projectId) => {
    Alert.alert('Scanner une facture', 'Choisissez la source de la photo', [
      { text: 'Prendre une photo', onPress: () => runScan('camera', projectId) },
      { text: 'Galerie', onPress: () => runScan('library', projectId) },
      { text: 'Annuler', style: 'cancel' },
    ]);
  }, [runScan]);

  const onAddExpense = useCallback((projectId) => { openForm('expense', projectId); }, [openForm]);

  // --- Écrans du menu « Plus » (natifs) ------------------------------
  const fetchEvents = useCallback(() => { setEventsLoading(true); inject(fetchJS('/api/app-events.php', '__akevents')); }, [inject]);
  const fetchChannels = useCallback(() => { setChannelsLoading(true); inject(fetchJS('/api/app-channels.php', '__akchannels')); }, [inject]);
  const fetchSubInv = useCallback(() => { setSubInvLoading(true); inject(fetchJS('/api/app-subscription-invoices.php', '__aksubinv')); }, [inject]);
  const fetchChanMsgs = useCallback((chId) => { setChanLoading(true); inject(fetchJS('/api/app-channel-messages.php?channel_id=' + chId, '__akchanmsgs')); }, [inject]);
  const fetchQuotes = useCallback(() => { setQuotesLoading(true); inject(fetchJS('/api/app-quotes.php', '__akquotes')); }, [inject]);
  const fetchStats = useCallback(() => { setStatsLoading(true); inject(fetchJS('/api/app-stats.php', '__akstats')); }, [inject]);
  const fetchNotifs = useCallback(() => { setNotifsLoading(true); inject(fetchJS('/api/app-notifications.php', '__aknotifs')); }, [inject]);
  const fetchCoti = useCallback(() => { setCotiLoading(true); inject(fetchJS('/api/app-cotisations.php', '__akcoti')); }, [inject]);
  const fetchGrants = useCallback(() => { setGrantsLoading(true); inject(fetchJS('/api/app-grants.php', '__akgrants')); }, [inject]);
  const fetchAssemblies = useCallback(() => { setSecLoading(true); inject(fetchJS('/api/app-assemblies.php', '__akassemblies')); }, [inject]);
  const fetchAttendance = useCallback(() => { setSecLoading(true); inject(fetchJS('/api/app-attendance.php', '__akattendance')); }, [inject]);
  const fetchBroadcasts = useCallback(() => { setSecLoading(true); inject(fetchJS('/api/app-broadcasts.php', '__akbroadcasts')); }, [inject]);
  const fetchTickets = useCallback(() => { setSecLoading(true); inject(fetchJS('/api/app-tickets.php', '__aktickets')); }, [inject]);
  const fetchCoach = useCallback(() => { setSecLoading(true); inject(fetchJS('/api/app-coach.php', '__akcoach')); }, [inject]);
  const fetchAccount = useCallback(() => { inject(fetchJS('/api/app-account.php', '__akaccount')); }, [inject]);

  const openMenuScreen = useCallback((screen) => {
    setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setMenuScreen(screen);
    if (screen === 'agenda') fetchEvents();
    else if (screen === 'messages') { setChannels(null); fetchChannels(); }
    else if (screen === 'subinvoices') fetchSubInv();
    else if (screen === 'devis') fetchQuotes();
    else if (screen === 'stats') fetchStats();
    else if (screen === 'notifications') fetchNotifs();
    else if (screen === 'cotisations') fetchCoti();
    else if (screen === 'subventions') fetchGrants();
    else if (screen === 'members') { setPeople(null); fetchPeople(false); }
    else if (screen === 'clients') { setPeople(null); fetchPeople(true); }
    else if (screen === 'assemblies') fetchAssemblies();
    else if (screen === 'attendance') fetchAttendance();
    else if (screen === 'broadcasts') fetchBroadcasts();
    else if (screen === 'tickets') fetchTickets();
    else if (screen === 'coach') fetchCoach();
    else if (screen === 'settings') { setSettingsErr(''); setAccount(null); fetchAccount(); inject(FETCH_CSRF_JS); }
  }, [clearDetail, closeForm, fetchEvents, fetchChannels, fetchSubInv, fetchQuotes, fetchStats, fetchNotifs, fetchCoti, fetchGrants, fetchPeople, fetchAssemblies, fetchAttendance, fetchBroadcasts, fetchTickets, fetchCoach, fetchAccount, inject]);

  const onAnalyzeInvoices = useCallback(() => { setInvAILoading(true); inject(fetchJS('/api/app-invoices-ai.php', '__akinvai')); }, [inject]);
  const onCockpit = useCallback(() => { setStatsCockpitLoading(true); inject(fetchJS('/api/app-stats-ai.php', '__akstatsai')); }, [inject]);
  const backToHub = useCallback(() => { clearDetail(); closeForm(); setWebMode(false); setActive('menu'); setMenuScreen(null); }, [clearDetail, closeForm]);

  const openChannelFn = useCallback((c) => { setOpenChannel(c); setChanMsgs(null); fetchChanMsgs(c.id); }, [fetchChanMsgs]);

  const sendMessage = useCallback((chId, content) => {
    if (!csrf) { inject(FETCH_CSRF_JS); return; }
    setSendingMsg(true);
    inject(postJS('/api/app-send-message.php', { channel_id: chId, content, csrf }, '__akmsgsent'));
  }, [csrf, inject]);

  const onMoreNav = useCallback((nav) => {
    if (!nav) return;
    if (nav.screen) { openMenuScreen(nav.screen); return; }
    if (nav.tab) {
      clearDetail(); closeForm(); setMenuScreen(null);
      if (nav.tab === 'factures') { setActive('factures'); setWebMode(false); return; }
      if (nav.tab === 'people') { setActive('people'); setWebMode(false); return; }
      setActive(nav.tab); setWebMode(false); return;
    }
    if (nav.web) { clearDetail(); closeForm(); setWebMode(true); inject(gotoJS(nav.web)); }
  }, [openMenuScreen, clearDetail, closeForm, inject]);

  const saveAccount = useCallback((f) => {
    if (!csrf) { setSettingsErr('Session en préparation, réessayez.'); inject(FETCH_CSRF_JS); return; }
    setSettingsErr(''); setSettingsBusy(true);
    inject(postJS('/api/app-update-account.php', { ...f, csrf }, '__akaccountsaved'));
  }, [csrf, inject]);

  const uploadLogo = useCallback(async () => {
    try {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!perm.granted) { setSettingsErr('Autorisez l\'accès aux photos.'); return; }
      const res = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ImagePicker.MediaTypeOptions.Images, base64: true, quality: 0.7 });
      if (res.canceled || !res.assets || !res.assets[0] || !res.assets[0].base64) return;
      if (!csrf) { inject(FETCH_CSRF_JS); return; }
      setLogoBusy(true);
      inject(postJS('/api/app-upload-logo.php', { image: res.assets[0].base64, mime: 'image/jpeg', csrf }, '__aklogosaved'));
    } catch (e) { setLogoBusy(false); setSettingsErr('Impossible d\'ouvrir la photothèque.'); }
  }, [csrf, inject]);

  const generateCoach = useCallback(() => {
    if (!csrf) { inject(FETCH_CSRF_JS); return; }
    setCoachGen(true);
    inject(postJS('/api/app-coach-generate.php', { csrf }, '__akcoachgen'));
  }, [csrf, inject]);

  const deleteAccount = useCallback(() => {
    Alert.alert('Supprimer mon compte', 'Cette action est définitive et anonymise vos données (RGPD). Continuer ?', [
      { text: 'Annuler', style: 'cancel' },
      { text: 'Supprimer', style: 'destructive', onPress: () => {
        if (!csrf) { inject(FETCH_CSRF_JS); return; }
        inject(postJS('/api/app-delete-account.php', { confirm: 'SUPPRIMER', csrf }, '__akdeleted'));
      } },
    ]);
  }, [csrf, inject]);

  const submitForm = useCallback((type, data) => {
    if (!csrf) { setFormErr('Session en cours de préparation, réessayez dans un instant.'); inject(FETCH_CSRF_JS); return; }
    setFormErr('');
    setSubmitting(true);
    inject(postJS(CREATE_ENDPOINTS[type], { ...data, csrf }));
  }, [csrf, inject]);

  useEffect(() => {
    if (webMode || !authed) return;
    if (active === 'accueil') fetchKpis();
    else if (active === 'projets') fetchProjects();
    else if (active === 'factures') fetchInvoices();
    else if (active === 'people') fetchPeople(isTpe);
  }, [active, authed, webMode, isTpe, fetchKpis, fetchProjects, fetchInvoices, fetchPeople]);

  useEffect(() => {
    if (authed && !csrf) inject(FETCH_CSRF_JS);
  }, [authed, csrf, inject]);

  // Enregistre le token de notifications push une fois connecté
  useEffect(() => {
    if (authed && csrf && pushToken && !pushRegistered.current) {
      pushRegistered.current = true;
      inject(postJS('/api/app-register-push.php', { token: pushToken, platform: Platform.OS, csrf }, '__akpushreg'));
    }
  }, [authed, csrf, pushToken, inject]);

  // Mémorise les identifiants (pour Face ID) une fois la connexion réussie
  useEffect(() => {
    if (authed && pendingCreds.current) {
      if (onSaveCreds) onSaveCreds(pendingCreds.current);
      pendingCreds.current = null;
      autoLoginTried.current = false;
    }
  }, [authed, onSaveCreds]);

  // Partage/téléchargement d'un PDF authentifié
  const sharePdf = useCallback((url) => {
    setPdfBusy(true);
    inject(fetchPdfJS(url));
  }, [inject]);

  const sharePdfData = useCallback(async (base64) => {
    try {
      const uri = FileSystem.cacheDirectory + 'assokit-bilan-analytique.pdf';
      await FileSystem.writeAsStringAsync(uri, base64, { encoding: FileSystem.EncodingType.Base64 });
      if (await Sharing.isAvailableAsync()) {
        await Sharing.shareAsync(uri, { mimeType: 'application/pdf', UTI: 'com.adobe.pdf', dialogTitle: 'Bilan analytique' });
      } else {
        Alert.alert('PDF', 'Le partage n\'est pas disponible sur cet appareil.');
      }
    } catch (e) {
      Alert.alert('PDF', 'Impossible d\'ouvrir le document.');
    }
  }, []);

  useEffect(() => {
    if (Platform.OS !== 'android') return;
    const onBack = () => {
      if (quickOpen) { setQuickOpen(false); return true; }
      if (form) { closeForm(); return true; }
      if (stack.length) { popDetail(); return true; }
      if (webMode && canGoBack && webRef.current) { webRef.current.goBack(); return true; }
      if (webMode) { setWebMode(false); return true; }
      if (active === 'menu' && openChannel) { setOpenChannel(null); return true; }
      if (active === 'menu' && menuScreen) { setMenuScreen(null); return true; }
      if (active !== 'accueil') { setActive('accueil'); return true; }
      onExitToWelcome();
      return true;
    };
    const sub = BackHandler.addEventListener('hardwareBackPress', onBack);
    return () => sub.remove();
    // eslint-disable-next-line
  }, [canGoBack, quickOpen, active, webMode, stack.length, form, menuScreen, openChannel, popDetail, closeForm, onExitToWelcome]);

  const onNav = (nav) => {
    setCanGoBack(nav.canGoBack);
    const u = nav.url || '';
    lastUrl.current = u;
    if (/\/(connexion|signup|deconnexion|login|mot-de-passe|verifier-email)/.test(u)) setAuthed(false);
    else if (u.indexOf('assokit.fr') !== -1) setAuthed(true);
  };

  const onMessage = (e) => {
    try {
      const msg = JSON.parse(e.nativeEvent.data);
      if (msg && msg.__akkpi) { setKpi(msg.__akkpi); setKpiLoading(false); }
      if (msg && msg.__akprojects) { setProjects(msg.__akprojects); setProjLoading(false); }
      if (msg && msg.__akmembers) { setPeople(msg.__akmembers); setPeopleLoading(false); }
      if (msg && msg.__akclients) { setPeople(msg.__akclients); setPeopleLoading(false); }
      if (msg && msg.__akinvoices) { setInvoices(msg.__akinvoices); setInvLoading(false); }
      if (msg && msg.__akdetail) {
        setStack((s) => {
          if (!s.length) return s;
          const cp = s.slice();
          cp[cp.length - 1] = { ...cp[cp.length - 1], data: msg.__akdetail, loading: false };
          return cp;
        });
      }
      if (msg && msg.__akcsrf && msg.__akcsrf.ok) setCsrf(msg.__akcsrf.csrf);
      if (msg && msg.__akpick && msg.__akpick.ok) setPickClients(msg.__akpick.clients || []);
      if (msg && msg.__akfolders && msg.__akfolders.ok) setFolders(msg.__akfolders.folders || []);
      if (msg && msg.__akbilan) {
        setStack((s) => {
          if (!s.length) return s;
          const cp = s.slice();
          cp[cp.length - 1] = { ...cp[cp.length - 1], bilan: msg.__akbilan };
          return cp;
        });
      }
      if (msg && msg.__akscan) {
        setScanning(false);
        if (msg.__akscan.success) setScanData(msg.__akscan);
        else setFormErr(msg.__akscan.error || 'La facture n\'a pas pu être analysée.');
      }
      if (msg && msg.__akevents) { setEvents(msg.__akevents); setEventsLoading(false); }
      if (msg && msg.__akchannels) { setChannels(msg.__akchannels); setChannelsLoading(false); }
      if (msg && msg.__akchanmsgs) { setChanMsgs(msg.__akchanmsgs); setChanLoading(false); }
      if (msg && msg.__aksubinv) { setSubInv(msg.__aksubinv); setSubInvLoading(false); }
      if (msg && msg.__akquotes) { setQuotes(msg.__akquotes); setQuotesLoading(false); }
      if (msg && msg.__akstats) { setStats(msg.__akstats); setStatsLoading(false); }
      if (msg && msg.__aknotifs) { setNotifs(msg.__aknotifs); setNotifsLoading(false); }
      if (msg && msg.__akcoti) { setCoti(msg.__akcoti); setCotiLoading(false); }
      if (msg && msg.__akgrants) { setGrantsData(msg.__akgrants); setGrantsLoading(false); }
      if (msg && msg.__akassemblies) { setAssemblies(msg.__akassemblies); setSecLoading(false); }
      if (msg && msg.__akattendance) { setAttendance(msg.__akattendance); setSecLoading(false); }
      if (msg && msg.__akbroadcasts) { setBroadcasts(msg.__akbroadcasts); setSecLoading(false); }
      if (msg && msg.__aktickets) { setTickets(msg.__aktickets); setSecLoading(false); }
      if (msg && msg.__akcoach) { setCoach(msg.__akcoach); setSecLoading(false); }
      if (msg && msg.__akcreds && msg.__akcreds.email && msg.__akcreds.password) { pendingCreds.current = msg.__akcreds; }
      if (msg && msg.__akpdf) {
        setPdfBusy(false);
        if (msg.__akpdf.ok && msg.__akpdf.data) sharePdfData(msg.__akpdf.data);
        else Alert.alert('PDF', 'Impossible de récupérer le document.');
      }
      if (msg && msg.__akinvai) { setInvAI(msg.__akinvai && msg.__akinvai.ok ? (msg.__akinvai.analysis || '') : 'Analyse indisponible.'); setInvAILoading(false); }
      if (msg && msg.__akstatsai) { setStatsCockpit(msg.__akstatsai && msg.__akstatsai.ok ? (msg.__akstatsai.cockpit || null) : null); setStatsCockpitLoading(false); }
      if (msg && msg.__akaccount) { setAccount(msg.__akaccount); }
      if (msg && msg.__akaccountsaved) {
        setSettingsBusy(false);
        if (msg.__akaccountsaved.ok) { Alert.alert('Enregistré', msg.__akaccountsaved.message || 'Profil mis à jour.'); fetchAccount(); }
        else setSettingsErr(msg.__akaccountsaved.message || 'Erreur.');
      }
      if (msg && msg.__aklogosaved) {
        setLogoBusy(false);
        if (msg.__aklogosaved.ok) { Alert.alert('Logo mis à jour ✅'); fetchAccount(); fetchKpis(); }
        else setSettingsErr(msg.__aklogosaved.message || 'Logo non enregistré.');
      }
      if (msg && msg.__akcoachgen) {
        setCoachGen(false);
        if (msg.__akcoachgen.ok) setCoach({ ok: true, allowed: true, report: msg.__akcoachgen.report });
        else Alert.alert('Coach IA', msg.__akcoachgen.message || 'Génération impossible.');
      }
      if (msg && msg.__akdeleted) {
        if (msg.__akdeleted.ok) { if (onClearCreds) onClearCreds(); Alert.alert('Compte supprimé', msg.__akdeleted.message || 'À bientôt.'); onExitToWelcome(); }
        else Alert.alert('Suppression', msg.__akdeleted.message || 'Erreur.');
      }
      if (msg && msg.__akmsgsent) {
        setSendingMsg(false);
        if (msg.__akmsgsent.ok && openChannel) fetchChanMsgs(openChannel.id);
      }
      if (msg && msg.__akwrite) {
        setSubmitting(false);
        const w = msg.__akwrite;
        if (w.ok) {
          const created = form && form.type;
          closeForm();
          Alert.alert('C\'est fait ✅', w.message || 'Enregistré avec succès.');
          // Rafraîchir les données concernées
          fetchKpis();
          if (created === 'member') fetchPeople(false);
          else if (created === 'client') fetchPeople(true);
          else if (created === 'invoice') fetchInvoices();
          else if (created === 'quote') fetchQuotes();
          else if (created === 'project') fetchProjects();
          else if (created === 'expense') { fetchProjects(); if (expenseProject) pushDetail('project', expenseProject); }
        } else {
          setFormErr(w.message || 'Une erreur est survenue.');
        }
      }
    } catch (err) {}
  };

  const goTab = (tab) => {
    if (tab.key === 'add') { setQuickOpen(true); return; }
    clearDetail();
    closeForm();
    if (tab.key === 'menu') { setActive('menu'); setWebMode(false); setMenuScreen(null); setOpenChannel(null); return; }
    // Onglets natifs : accueil / projets / factures / people
    setMenuScreen(null);
    setActive(tab.key);
    setWebMode(false);
  };

  const onQuick = (a) => {
    if (a.form) { openForm(a.form); return; }
    setQuickOpen(false);
    clearDetail();
    setWebMode(true);
    inject(gotoJS(a.path));
  };

  const onGoto = (path) => {
    clearDetail();
    closeForm();
    if (path === '/projets') { setMenuScreen(null); setActive('projets'); setWebMode(false); return; }
    if (path === '/adherents' && !isTpe) { setMenuScreen(null); setActive('people'); setWebMode(false); return; }
    if (path === '/mon-asso-clients' && isTpe) { setMenuScreen(null); setActive('people'); setWebMode(false); return; }
    if (path === '/mon-asso-factures') { setMenuScreen(null); setActive('factures'); setWebMode(false); return; }
    if (path === '/agenda') { openMenuScreen('agenda'); return; }
    if (path === '/messages') { openMenuScreen('messages'); return; }
    setWebMode(true);
    inject(gotoJS(path));
  };

  // Ouvre une page web depuis une fiche détail (PDF, édition, mailto…)
  const openWeb = (path) => {
    if (/^(mailto:|tel:)/.test(path)) {
      inject("(function(){ try { window.location.href='" + path + "'; } catch(e){} })(); true;");
      return;
    }
    clearDetail();
    closeForm();
    setMenuScreen(null);
    setWebMode(true);
    if (/^https?:\/\//.test(path)) {
      inject("(function(){ try { window.location.href='" + path + "'; } catch(e){} })(); true;");
    } else {
      inject(gotoJS(path));
    }
  };

  const openProject = (id) => pushDetail('project', id);
  const openInvoice = (id) => pushDetail('invoice', id);
  const openPerson = (id) => pushDetail(isTpe ? 'client' : 'member', id);

  const detailTop = stack.length ? stack[stack.length - 1] : null;
  const showForm = !!form && authed;
  const showMenu = active === 'menu' && authed && !webMode && !detailTop && !showForm;
  const showHome = active === 'accueil' && authed && !webMode && !detailTop && !showForm;
  const showProjects = active === 'projets' && authed && !webMode && !detailTop && !showForm;
  const showInvoices = active === 'factures' && authed && !webMode && !detailTop && !showForm;
  const showPeople = active === 'people' && authed && !webMode && !detailTop && !showForm;
  const showDetail = !!detailTop && authed && !webMode && !showForm;
  const showWeb = !showHome && !showProjects && !showInvoices && !showPeople && !showDetail && !showForm && !showMenu;

  return (
    <SafeAreaView style={styles.safe}>
      <StatusBar barStyle={showHome ? 'light-content' : 'dark-content'} backgroundColor={showHome ? '#07A873' : '#fff'} />
      <View style={styles.webWrap}>
        <WebView
          ref={webRef}
          source={{ uri: BASE + startPath }}
          onLoadStart={() => setLoading(true)}
          onLoadEnd={() => {
            setLoading(false);
            const u = lastUrl.current || '';
            if (/\/(connexion|login)/.test(u)) {
              inject(CAPTURE_CREDS_JS);
              if (autoCreds && !autoLoginTried.current) { autoLoginTried.current = true; inject(autoLoginJS(autoCreds.email, autoCreds.password)); }
            }
            if (!webMode && authed && active === 'accueil') fetchKpis();
          }}
          onNavigationStateChange={onNav}
          onMessage={onMessage}
          allowsBackForwardNavigationGestures
          pullToRefreshEnabled
          domStorageEnabled
          javaScriptEnabled
          sharedCookiesEnabled
          originWhitelist={['https://*', 'http://*', 'mailto:*', 'tel:*']}
          setSupportMultipleWindows={false}
          applicationNameForUserAgent="AssokitApp/1.0"
          injectedJavaScript={APP_ONLY_CSS}
          style={styles.web}
        />
        {showHome && (
          <View style={styles.homeOverlay}>
            <NativeHome data={kpi} loading={kpiLoading} onRefresh={fetchKpis} onGoto={onGoto} profile={profile} />
          </View>
        )}
        {showProjects && (
          <View style={styles.homeOverlay}>
            <NativeProjects data={projects} loading={projLoading} onRefresh={fetchProjects} onOpen={openProject} onNew={() => openForm('project')}
              onBack={TABS.some((t) => t.key === 'projets') ? undefined : backToHub} />
          </View>
        )}
        {showInvoices && (
          <View style={styles.homeOverlay}>
            <NativeInvoices data={invoices} loading={invLoading} onRefresh={fetchInvoices} onOpen={openInvoice} onNew={() => openForm('invoice')}
              onBack={TABS.some((t) => t.key === 'factures') ? undefined : backToHub}
              aiText={invAI} aiLoading={invAILoading} onAnalyze={onAnalyzeInvoices} />
          </View>
        )}
        {showPeople && (
          <View style={styles.homeOverlay}>
            <NativePeople
              mode={isTpe ? 'clients' : 'members'}
              data={people}
              loading={peopleLoading}
              onRefresh={() => fetchPeople(isTpe)}
              onOpen={openPerson}
              onNew={() => openForm(isTpe ? 'client' : 'member')}
            />
          </View>
        )}
        {showForm && (
          <View style={styles.homeOverlay}>
            {form.type === 'member' && (
              <MemberForm onBack={closeForm} onSubmit={(d) => submitForm('member', d)} submitting={submitting} error={formErr} canAdmin={(kpi && kpi.role) === 'admin'} />
            )}
            {form.type === 'client' && (
              <ClientForm onBack={closeForm} onSubmit={(d) => submitForm('client', d)} submitting={submitting} error={formErr} />
            )}
            {(form.type === 'invoice' || form.type === 'quote') && (
              <BillingForm mode={form.type} onBack={closeForm} onSubmit={(d) => submitForm(form.type, d)} submitting={submitting} error={formErr} clients={pickClients} />
            )}
            {form.type === 'project' && (
              <ProjectForm onBack={closeForm} onSubmit={(d) => submitForm('project', d)} submitting={submitting} error={formErr} folders={folders} />
            )}
            {form.type === 'expense' && (
              <ExpenseForm onBack={closeForm} onSubmit={(d) => submitForm('expense', d)} submitting={submitting} error={formErr}
                projects={(projects && projects.projects) || []} preProject={expenseProject}
                scanData={scanData} scanning={scanning} onScan={pickAndScan} />
            )}
          </View>
        )}
        {showMenu && (
          <View style={styles.homeOverlay}>
            {menuScreen === 'agenda' ? (
              <NativeAgenda data={events} loading={eventsLoading} onRefresh={fetchEvents} onOpen={(id) => pushDetail('event', id)} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'subinvoices' ? (
              <NativeSubInvoices data={subInv} loading={subInvLoading} onRefresh={fetchSubInv} onBack={() => setMenuScreen(null)} onWeb={openWeb} />
            ) : menuScreen === 'devis' ? (
              <NativeQuotes data={quotes} loading={quotesLoading} onRefresh={fetchQuotes} onOpen={(id) => pushDetail('quote', id)} onNew={() => openForm('quote')} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'stats' ? (
              <NativeStats data={stats} loading={statsLoading} onRefresh={fetchStats} onBack={() => setMenuScreen(null)}
                cockpit={statsCockpit} cockpitLoading={statsCockpitLoading} onCockpit={onCockpit} />
            ) : menuScreen === 'members' ? (
              <NativePeople mode="members" data={people} loading={peopleLoading} onRefresh={() => fetchPeople(false)}
                onOpen={(id) => pushDetail('member', id)} onNew={() => openForm('member')} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'clients' ? (
              <NativePeople mode="clients" data={people} loading={peopleLoading} onRefresh={() => fetchPeople(true)}
                onOpen={(id) => pushDetail('client', id)} onNew={() => openForm('client')} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'notifications' ? (
              <NativeNotifications data={notifs} loading={notifsLoading} onRefresh={fetchNotifs} onOpen={openWeb} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'cotisations' ? (
              <NativeCotisations data={coti} loading={cotiLoading} onRefresh={fetchCoti} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'subventions' ? (
              <NativeGrants data={grantsData} loading={grantsLoading} onRefresh={fetchGrants} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'assemblies' ? (
              <GatedList title="Assemblées" data={assemblies} loading={secLoading} onRefresh={fetchAssemblies} onBack={() => setMenuScreen(null)}
                itemsKey="items" emptyIcon="clipboard-outline" emptyLabel="Aucune assemblée"
                renderStats={(s) => (<View style={styles.miniKpiRow}><View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.total || 0}</Text><Text style={styles.miniKpiLbl}>Total</Text></View><View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.upcoming || 0}</Text><Text style={styles.miniKpiLbl}>À venir</Text></View></View>)}
                renderItem={(it) => (<StatusItemCard key={it.id} title={it.title} sub={[it.type, it.date, it.location].filter(Boolean).join(' · ')} statusLabel={it.status_label} kind={it.status_kind} />)} />
            ) : menuScreen === 'attendance' ? (
              <GatedList title="Émargement" data={attendance} loading={secLoading} onRefresh={fetchAttendance} onBack={() => setMenuScreen(null)}
                itemsKey="items" emptyIcon="checkbox-outline" emptyLabel="Aucune session"
                renderStats={(s) => (<View style={styles.miniKpiRow}><View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.open || 0}</Text><Text style={styles.miniKpiLbl}>Ouvertes</Text></View><View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.records || 0}</Text><Text style={styles.miniKpiLbl}>Signatures</Text></View></View>)}
                renderItem={(it) => (<StatusItemCard key={it.id} title={it.title} sub={[it.date, it.location, it.nb_signed + ' signature' + (it.nb_signed > 1 ? 's' : '')].filter(Boolean).join(' · ')} statusLabel={it.is_open ? 'Ouverte' : 'Fermée'} kind={it.is_open ? 'done' : 'off'} />)} />
            ) : menuScreen === 'broadcasts' ? (
              <GatedList title="Communication" data={broadcasts} loading={secLoading} onRefresh={fetchBroadcasts} onBack={() => setMenuScreen(null)}
                itemsKey="items" emptyIcon="mail-outline" emptyLabel="Aucune diffusion"
                renderStats={(s) => (<View style={styles.miniKpiRow}><View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.sent || 0}</Text><Text style={styles.miniKpiLbl}>Envoyées</Text></View><View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.nb || 0}</Text><Text style={styles.miniKpiLbl}>Total</Text></View></View>)}
                renderItem={(it) => (<StatusItemCard key={it.id} title={it.subject} sub={[it.date, it.nb_sent ? it.nb_sent + ' envoyé' + (it.nb_sent > 1 ? 's' : '') : ''].filter(Boolean).join(' · ')} statusLabel={it.status_label} kind={it.status_kind} />)} />
            ) : menuScreen === 'tickets' ? (
              <GatedList title="Support" data={tickets} loading={secLoading} onRefresh={fetchTickets} onBack={() => setMenuScreen(null)}
                itemsKey="items" emptyIcon="help-buoy-outline" emptyLabel="Aucun ticket"
                renderStats={(s) => (<View style={styles.miniKpiRow}><View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.open || 0}</Text><Text style={styles.miniKpiLbl}>Ouverts</Text></View><View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.nb || 0}</Text><Text style={styles.miniKpiLbl}>Total</Text></View></View>)}
                renderItem={(it) => (<StatusItemCard key={it.id} title={it.title} sub={it.date} statusLabel={it.status_label} kind={it.status_kind} />)} />
            ) : menuScreen === 'coach' ? (
              <NativeCoach data={coach} loading={secLoading} generating={coachGen} onGenerate={generateCoach} onRefresh={fetchCoach} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'settings' ? (
              <NativeSettings data={account} onBack={() => setMenuScreen(null)} onSave={saveAccount} saving={settingsBusy} error={settingsErr}
                onLogo={uploadLogo} logoBusy={logoBusy} onDelete={deleteAccount} onWeb={openWeb}
                onLogout={() => { if (onClearCreds) onClearCreds(); setMenuScreen(null); setActive('accueil'); setWebMode(true); autoLoginTried.current = true; inject(gotoJS('/deconnexion.php')); }} />
            ) : menuScreen === 'messages' ? (
              openChannel ? (
                <NativeChat channel={openChannel} data={chanMsgs} loading={chanLoading} sending={sendingMsg}
                  onBack={() => { setOpenChannel(null); }} onSend={sendMessage} onRefresh={() => fetchChanMsgs(openChannel.id)} />
              ) : (
                <NativeChannels data={channels} loading={channelsLoading} onRefresh={fetchChannels} onOpen={openChannelFn} onBack={() => setMenuScreen(null)} />
              )
            ) : (
              <NativeMore
                orgName={kpi && kpi.org_name}
                initials={kpi && kpi.org_initials}
                logo={kpi && kpi.org_logo}
                isFounder={!!(kpi && kpi.is_founder)}
                onNav={onMoreNav}
                onLogout={() => { setMenuScreen(null); setWebMode(true); inject(gotoJS('/deconnexion.php')); }}
              />
            )}
          </View>
        )}
        {showDetail && (
          <View style={styles.homeOverlay}>
            {detailTop.type === 'project' && (
              <NativeProjectDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onWeb={openWeb} onAddExpense={onAddExpense} onSharePdf={sharePdf} pdfBusy={pdfBusy} />
            )}
            {detailTop.type === 'member' && (
              <NativeMemberDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onOpenProject={(id) => pushDetail('project', id)} onWeb={openWeb} />
            )}
            {detailTop.type === 'client' && (
              <NativeClientDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onOpenInvoice={(id) => pushDetail('invoice', id)} onWeb={openWeb} />
            )}
            {detailTop.type === 'invoice' && (
              <NativeInvoiceDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onWeb={openWeb} />
            )}
            {detailTop.type === 'quote' && (
              <NativeInvoiceDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onWeb={openWeb} />
            )}
            {detailTop.type === 'event' && (
              <NativeEventDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onWeb={openWeb} />
            )}
          </View>
        )}
        {loading && showWeb && (
          <View style={styles.loader} pointerEvents="none">
            <ActivityIndicator size="large" color={BRAND} />
          </View>
        )}
        {showWeb && webMode && (
          <TouchableOpacity style={styles.floatBack} activeOpacity={0.85}
            onPress={() => { if (canGoBack && webRef.current) webRef.current.goBack(); else { setWebMode(false); setMenuScreen(null); } }}>
            <Ionicons name="chevron-back" size={20} color={INK} />
            <Text style={styles.floatBackTxt}>Retour</Text>
          </TouchableOpacity>
        )}
      </View>

      <View style={styles.tabBar}>
        {TABS.map((tab) => {
          if (tab.key === 'add') {
            return (
              <TouchableOpacity key={tab.key} style={styles.fabWrap} onPress={() => goTab(tab)} activeOpacity={0.85}>
                <View style={styles.fab}>
                  <Ionicons name="add" size={30} color="#fff" />
                </View>
              </TouchableOpacity>
            );
          }
          const isActive = active === tab.key;
          return (
            <TouchableOpacity key={tab.key} style={styles.tab} onPress={() => goTab(tab)} activeOpacity={0.7}>
              <Ionicons name={isActive ? tab.icon : tab.icon + '-outline'} size={23} color={isActive ? BRAND : MUTE} />
              <Text style={[styles.tabLabel, { color: isActive ? BRAND : MUTE }]}>{tab.label}</Text>
            </TouchableOpacity>
          );
        })}
      </View>

      <Modal visible={quickOpen} transparent animationType="fade" onRequestClose={() => setQuickOpen(false)}>
        <Pressable style={styles.sheetBackdrop} onPress={() => setQuickOpen(false)}>
          <Pressable style={styles.sheet}>
            <View style={styles.sheetHandle} />
            <Text style={styles.sheetTitle}>Créer</Text>
            {QUICK_ACTIONS.map((a) => (
              <TouchableOpacity key={a.label} style={styles.qaRow} onPress={() => onQuick(a)} activeOpacity={0.7}>
                <View style={[styles.qaIcon, { backgroundColor: a.color + '18' }]}>
                  <Ionicons name={a.icon} size={22} color={a.color} />
                </View>
                <Text style={styles.qaLabel}>{a.label}</Text>
                <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
              </TouchableOpacity>
            ))}
          </Pressable>
        </Pressable>
      </Modal>
    </SafeAreaView>
  );
}

async function saveCreds(c) {
  try { await SecureStore.setItemAsync('ak_email', c.email); await SecureStore.setItemAsync('ak_pass', c.password); } catch (e) {}
}
async function clearCreds() {
  try { await SecureStore.deleteItemAsync('ak_email'); await SecureStore.deleteItemAsync('ak_pass'); } catch (e) {}
}

export default function App() {
  const [path, setPath] = useState(null);
  const [pushToken, setPushToken] = useState(null);
  const [autoCreds, setAutoCreds] = useState(null);
  const [ready, setReady] = useState(false);

  // Au lancement : si des identifiants sont mémorisés, déverrouiller par Face ID puis auto-login
  useEffect(() => {
    (async () => {
      try {
        const em = await SecureStore.getItemAsync('ak_email');
        const pw = await SecureStore.getItemAsync('ak_pass');
        if (em && pw) {
          let ok = true;
          try {
            const hasHw = await LocalAuthentication.hasHardwareAsync();
            const enrolled = await LocalAuthentication.isEnrolledAsync();
            if (hasHw && enrolled) {
              const r = await LocalAuthentication.authenticateAsync({ promptMessage: 'Déverrouiller Assokit', fallbackLabel: 'Code', cancelLabel: 'Annuler' });
              ok = !!r.success;
            }
          } catch (e) { ok = true; }
          if (ok) { setAutoCreds({ email: em, password: pw }); setPath('/connexion'); }
        }
      } catch (e) {}
      setReady(true);
    })();
  }, []);

  useEffect(() => {
    (async () => {
      try {
        if (Platform.OS === 'android') {
          await Notifications.setNotificationChannelAsync('default', {
            name: 'Assokit',
            importance: Notifications.AndroidImportance.DEFAULT,
            lightColor: BRAND,
          });
        }
        const { status } = await Notifications.requestPermissionsAsync();
        if (status !== 'granted') return;
        const projectId =
          Constants?.expoConfig?.extra?.eas?.projectId ??
          Constants?.easConfig?.projectId;
        const token = (await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined)).data;
        if (token) setPushToken(token);
      } catch (e) {}
    })();
  }, []);

  if (!ready) {
    return <View style={{ flex: 1, backgroundColor: '#059669', alignItems: 'center', justifyContent: 'center' }}><ActivityIndicator size="large" color="#fff" /></View>;
  }
  if (!path) {
    return <WelcomeScreen onLogin={() => setPath('/connexion')} onSignup={() => setPath('/signup')} />;
  }
  return (
    <AppShell
      startPath={path}
      pushToken={pushToken}
      autoCreds={autoCreds}
      onSaveCreds={saveCreds}
      onClearCreds={clearCreds}
      onLogout={async () => { await clearCreds(); setAutoCreds(null); setPath(null); }}
      onExitToWelcome={() => setPath(null)}
    />
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: '#fff' },
  webWrap: { flex: 1, backgroundColor: '#fff' },
  web: { flex: 1, backgroundColor: '#ffffff' },
  loader: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(255,255,255,0.65)' },
  homeOverlay: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: '#F4F6FA' },

  /* Accueil natif */
  homeScroll: { flex: 1 },
  homeContent: { paddingBottom: 28 },
  hHeader: { paddingTop: 26, paddingBottom: 26, paddingHorizontal: 22, borderBottomLeftRadius: 26, borderBottomRightRadius: 26 },
  hHeaderRow: { flexDirection: 'row', alignItems: 'center' },
  hHello: { color: 'rgba(255,255,255,0.9)', fontSize: 16, fontWeight: '500' },
  hName: { color: '#fff', fontSize: 26, fontWeight: '800', letterSpacing: -0.4, marginTop: 2 },
  hOrg: { color: 'rgba(255,255,255,0.9)', fontSize: 14, marginTop: 6, fontWeight: '500' },
  hAvatar: { width: 50, height: 50, borderRadius: 15, backgroundColor: 'rgba(255,255,255,0.2)', alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: 'rgba(255,255,255,0.35)' },
  hAvatarTxt: { color: '#fff', fontSize: 18, fontWeight: '800' },

  homeLoader: { paddingTop: 60, alignItems: 'center' },
  homeLoaderTxt: { color: MUTE, marginTop: 12, fontSize: 14 },

  kpiGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', paddingHorizontal: 16, marginTop: -14 },
  kpiCard: { width: '48%', backgroundColor: '#fff', borderRadius: 20, padding: 16, marginBottom: 14, shadowColor: '#0F172A', shadowOpacity: 0.08, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: 4 },
  kpiTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  kpiIcon: { width: 42, height: 42, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  kpiValue: { fontSize: 30, fontWeight: '800', color: INK, marginTop: 12, letterSpacing: -0.5 },
  kpiLabel: { fontSize: 14, fontWeight: '600', color: '#334155', marginTop: 2 },
  kpiSub: { fontSize: 12, color: MUTE, marginTop: 3 },

  sectionTitle: { fontSize: 16, fontWeight: '700', color: INK, marginTop: 8, marginBottom: 12, marginHorizontal: 20 },
  shortcuts: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', paddingHorizontal: 16 },
  shortcut: { width: '48%', backgroundColor: '#fff', borderRadius: 16, paddingVertical: 16, paddingHorizontal: 14, marginBottom: 12, flexDirection: 'row', alignItems: 'center', shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  shortcutIcon: { width: 38, height: 38, borderRadius: 11, backgroundColor: '#ECFDF5', alignItems: 'center', justifyContent: 'center', marginRight: 12 },
  shortcutTxt: { fontSize: 14.5, fontWeight: '600', color: INK },

  openFull: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 8, marginHorizontal: 16, paddingVertical: 15, borderRadius: 16, borderWidth: 1.5, borderColor: '#D1FAE5', backgroundColor: '#F0FDF9' },
  openFullTxt: { fontSize: 15, fontWeight: '700', color: BRAND },
  hAvatarImg: { width: 50, height: 50, borderRadius: 15 },

  /* Projets natifs */
  projWrap: { flex: 1, backgroundColor: '#F4F6FA' },
  projHeader: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 20, paddingTop: 18, paddingBottom: 12 },
  projTitle: { fontSize: 26, fontWeight: '800', color: INK, letterSpacing: -0.4 },
  projSub: { fontSize: 13.5, color: MUTE, marginTop: 2 },
  projNewBtn: { flexDirection: 'row', alignItems: 'center', gap: 5, backgroundColor: BRAND, paddingVertical: 10, paddingHorizontal: 14, borderRadius: 12, shadowColor: BRAND, shadowOpacity: 0.3, shadowRadius: 8, shadowOffset: { width: 0, height: 4 }, elevation: 4 },
  projNewTxt: { color: '#fff', fontSize: 14, fontWeight: '700' },
  projCard: { backgroundColor: '#fff', borderRadius: 18, padding: 16, marginBottom: 12, shadowColor: '#0F172A', shadowOpacity: 0.06, shadowRadius: 12, shadowOffset: { width: 0, height: 5 }, elevation: 3 },
  projCardTop: { flexDirection: 'row', alignItems: 'flex-start' },
  projName: { fontSize: 16, fontWeight: '700', color: INK },
  projFolder: { fontSize: 13, color: MUTE, marginTop: 2 },
  projChip: { paddingVertical: 4, paddingHorizontal: 10, borderRadius: 20 },
  projChipTxt: { fontSize: 11.5, fontWeight: '700' },
  progRow: { flexDirection: 'row', alignItems: 'center', marginTop: 14, gap: 10 },
  progTrack: { flex: 1, height: 7, borderRadius: 4, backgroundColor: '#EEF2F6', overflow: 'hidden' },
  progFill: { height: 7, borderRadius: 4 },
  progTxt: { fontSize: 12.5, fontWeight: '700', color: '#64748B', width: 38, textAlign: 'right' },
  /* Membres / Clients */
  personCard: { backgroundColor: '#fff', borderRadius: 16, padding: 14, marginBottom: 10, flexDirection: 'row', alignItems: 'center', shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  personAvatar: { width: 46, height: 46, borderRadius: 14, alignItems: 'center', justifyContent: 'center', marginRight: 13 },
  personAvatarTxt: { color: '#fff', fontSize: 16, fontWeight: '800' },
  personName: { fontSize: 15.5, fontWeight: '700', color: INK },
  personSub: { fontSize: 13, color: MUTE, marginTop: 2 },
  personRight: { fontSize: 15, fontWeight: '800', color: '#047857' },
  personBadges: { alignItems: 'flex-end', gap: 6 },
  roleChip: { paddingVertical: 4, paddingHorizontal: 9, borderRadius: 20 },
  roleChipTxt: { fontSize: 11, fontWeight: '700', color: '#475569' },
  dot: { width: 8, height: 8, borderRadius: 4 },

  /* Factures */
  invCard: { backgroundColor: '#fff', borderRadius: 16, padding: 15, marginBottom: 10, flexDirection: 'row', alignItems: 'center', shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  invNum: { fontSize: 15.5, fontWeight: '700', color: INK },
  invClient: { fontSize: 13, color: MUTE, marginTop: 3 },
  invAmount: { fontSize: 16.5, fontWeight: '800', color: INK },

  /* Fiches détail natives */
  detailWrap: { flex: 1, backgroundColor: '#F4F6FA' },
  dHeader: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12, paddingTop: 10, paddingBottom: 10, backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#EEF2F6' },
  dBack: { width: 34, height: 34, alignItems: 'center', justifyContent: 'center' },
  dTitle: { flex: 1, textAlign: 'center', fontSize: 16.5, fontWeight: '700', color: INK },
  detailContent: { padding: 18, paddingBottom: 40 },
  dName: { fontSize: 24, fontWeight: '800', color: INK, letterSpacing: -0.4 },
  dFolder: { fontSize: 14, color: MUTE, marginTop: 4 },
  dCard: { backgroundColor: '#fff', borderRadius: 18, padding: 16, marginTop: 14, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  dCardRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  dCardLabel: { fontSize: 14, fontWeight: '600', color: '#334155' },
  dCardStrong: { fontSize: 14.5, fontWeight: '800', color: INK },
  dSteps: { fontSize: 12.5, color: MUTE, marginTop: 8 },
  dSection: { fontSize: 15, fontWeight: '700', color: INK, marginTop: 22, marginBottom: 2 },
  dText: { fontSize: 14.5, color: '#334155', lineHeight: 21, marginTop: 8 },
  stepRow: { flexDirection: 'row', alignItems: 'flex-start', backgroundColor: '#fff', borderRadius: 14, padding: 14, marginTop: 8 },
  stepTitle: { fontSize: 14.5, fontWeight: '600', color: INK },
  stepDone: { color: '#94A3B8', textDecorationLine: 'line-through' },
  stepDesc: { fontSize: 13, color: MUTE, marginTop: 3 },
  dHero: { alignItems: 'center', paddingTop: 6, paddingBottom: 4 },
  dHeroAvatar: { width: 76, height: 76, borderRadius: 24, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  dHeroAvatarTxt: { color: '#fff', fontSize: 28, fontWeight: '800' },
  dChipsRow: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', gap: 8, marginTop: 10 },
  infoRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 9 },
  infoIcon: { width: 34, height: 34, borderRadius: 10, backgroundColor: '#ECFDF5', alignItems: 'center', justifyContent: 'center', marginRight: 12 },
  infoLabel: { fontSize: 12, color: MUTE },
  infoValue: { fontSize: 14.5, fontWeight: '600', color: INK, marginTop: 1 },
  miniKpiRow: { flexDirection: 'row', backgroundColor: '#fff', borderRadius: 18, padding: 6, marginTop: 16, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  miniKpi: { flex: 1, alignItems: 'center', paddingVertical: 12 },
  miniKpiVal: { fontSize: 17, fontWeight: '800', color: '#047857' },
  miniKpiLbl: { fontSize: 11.5, color: MUTE, marginTop: 3 },
  dTotal: { fontSize: 20, fontWeight: '800', color: INK },
  dMuted: { fontSize: 13.5, color: '#64748B' },
  lineRow: { flexDirection: 'row', alignItems: 'flex-start', paddingVertical: 12 },
  lineSep: { borderTopWidth: 1, borderTopColor: '#F1F5F9' },
  lineLabel: { fontSize: 14, fontWeight: '600', color: INK },
  lineQty: { fontSize: 12.5, color: MUTE, marginTop: 3 },
  lineTotal: { fontSize: 14.5, fontWeight: '700', color: INK },
  dWebBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 16, paddingVertical: 15, borderRadius: 16, borderWidth: 1.5, borderColor: '#D1FAE5', backgroundColor: '#F0FDF9' },
  dWebBtnTxt: { fontSize: 15, fontWeight: '700', color: BRAND },
  dPrimaryBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 20, paddingVertical: 16, borderRadius: 16, backgroundColor: BRAND, shadowColor: BRAND, shadowOpacity: 0.3, shadowRadius: 10, shadowOffset: { width: 0, height: 6 }, elevation: 5 },
  dPrimaryBtnTxt: { fontSize: 15.5, fontWeight: '800', color: '#fff' },

  /* Formulaires natifs */
  formContent: { padding: 18, paddingBottom: 30 },
  formFooter: { padding: 14, paddingBottom: Platform.OS === 'ios' ? 26 : 14, backgroundColor: '#fff', borderTopWidth: 1, borderTopColor: '#EEF2F6' },
  formErr: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: '#FEF2F2', borderWidth: 1, borderColor: '#FECACA', borderRadius: 12, padding: 12, marginBottom: 14 },
  formErrTxt: { flex: 1, color: '#B91C1C', fontSize: 13.5, fontWeight: '500' },
  fLabel: { fontSize: 13, fontWeight: '600', color: '#334155', marginBottom: 6 },
  fInput: { backgroundColor: '#fff', borderWidth: 1.5, borderColor: '#E2E8F0', borderRadius: 12, paddingHorizontal: 14, paddingVertical: Platform.OS === 'ios' ? 13 : 10, fontSize: 15, color: INK },
  fHint: { fontSize: 11.5, color: MUTE, marginTop: 5 },
  segWrap: { flexDirection: 'row', backgroundColor: '#EEF2F6', borderRadius: 12, padding: 4, gap: 4 },
  segItem: { flex: 1, paddingVertical: 9, borderRadius: 9, alignItems: 'center' },
  segItemOn: { backgroundColor: '#fff', shadowColor: '#0F172A', shadowOpacity: 0.08, shadowRadius: 6, shadowOffset: { width: 0, height: 2 }, elevation: 2 },
  segTxt: { fontSize: 13, fontWeight: '600', color: '#64748B' },
  segTxtOn: { color: BRAND },
  switchRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#F8FAFC', borderRadius: 12, padding: 14, marginTop: 16 },
  switchLabel: { fontSize: 14, fontWeight: '600', color: INK },
  switchSub: { fontSize: 12, color: MUTE, marginTop: 3, lineHeight: 16 },
  formCardHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 },
  formCardTitle: { fontSize: 15, fontWeight: '700', color: INK, marginBottom: 10 },
  formLink: { fontSize: 13.5, fontWeight: '600', color: BRAND },
  pickedClient: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#F0FDF9', borderWidth: 1, borderColor: '#D1FAE5', borderRadius: 12, padding: 14 },
  pickedName: { fontSize: 15, fontWeight: '700', color: INK },
  pickedMail: { fontSize: 12.5, color: MUTE, marginTop: 2 },
  lineCard: { backgroundColor: '#fff', borderWidth: 1, borderColor: '#EEF2F6', borderRadius: 14, padding: 14, marginBottom: 12 },
  lineCardHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 },
  lineCardIdx: { fontSize: 12.5, fontWeight: '700', color: MUTE },
  line3: { flexDirection: 'row', gap: 8 },
  addLineBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, paddingVertical: 12, borderRadius: 12, borderWidth: 1.5, borderStyle: 'dashed', borderColor: '#CBD5E1' },
  addLineTxt: { fontSize: 14, fontWeight: '600', color: BRAND },
  totalsBox: { backgroundColor: '#fff', borderRadius: 14, padding: 16, marginTop: 14, borderWidth: 1, borderColor: '#EEF2F6' },
  stepEditRow: { flexDirection: 'row', alignItems: 'center', marginBottom: 10 },
  stepEditIdx: { width: 24, fontSize: 14, fontWeight: '700', color: MUTE, textAlign: 'center', marginRight: 6 },
  selectRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', borderWidth: 1.5, borderColor: '#E2E8F0', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 14, marginBottom: 12 },
  selectVal: { flex: 1, fontSize: 15, color: INK, fontWeight: '600' },
  scanBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 15, borderRadius: 14, borderWidth: 1.5, borderColor: '#BAE6FD', backgroundColor: '#F0F9FF' },
  scanBtnTxt: { fontSize: 15, fontWeight: '700', color: '#0369A1' },
  catWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  catChip: { paddingVertical: 8, paddingHorizontal: 13, borderRadius: 20, backgroundColor: '#EEF2F6' },
  catChipOn: { backgroundColor: BRAND },
  catTxt: { fontSize: 13, fontWeight: '600', color: '#64748B' },
  catTxtOn: { color: '#fff' },
  bilanHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  bilanRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 10 },
  bilanLabel: { fontSize: 13.5, fontWeight: '600', color: INK },
  bilanCount: { fontSize: 12, color: MUTE, marginTop: 2 },
  bilanAmount: { fontSize: 14.5, fontWeight: '700', color: INK },
  bilanTotalRow: { borderTopWidth: 1, borderTopColor: '#EEF2F6', marginTop: 4, paddingTop: 12, justifyContent: 'space-between' },
  upsellCard: { alignItems: 'center', backgroundColor: '#FFFBEB', borderWidth: 1, borderColor: '#FDE68A', borderRadius: 16, padding: 18, marginTop: 6 },
  upsellTxt: { fontSize: 13.5, color: '#92400E', textAlign: 'center', marginTop: 8, lineHeight: 19 },
  upsellBtn: { marginTop: 12, backgroundColor: '#D97706', paddingVertical: 10, paddingHorizontal: 20, borderRadius: 12 },
  upsellBtnTxt: { color: '#fff', fontSize: 14, fontWeight: '700' },

  /* Agenda */
  agDay: { fontSize: 13, fontWeight: '700', color: '#64748B', textTransform: 'capitalize', marginBottom: 8, marginLeft: 2 },
  agCard: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', borderRadius: 14, padding: 12, marginBottom: 8, overflow: 'hidden', shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 2 },
  agBar: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 4 },
  agTime: { width: 54, alignItems: 'center', marginLeft: 4 },
  agTimeTxt: { fontSize: 13, fontWeight: '700', color: INK },
  agTitle: { fontSize: 14.5, fontWeight: '600', color: INK },
  agSub: { fontSize: 12.5, color: MUTE, marginTop: 2 },

  /* Canaux */
  chanCard: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', borderRadius: 14, padding: 14, marginBottom: 10, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 2 },
  chanIcon: { width: 44, height: 44, borderRadius: 13, alignItems: 'center', justifyContent: 'center', marginRight: 13 },
  chanName: { fontSize: 15.5, fontWeight: '700', color: INK },
  chanSub: { fontSize: 12.5, color: MUTE, marginTop: 2 },
  chanDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: BRAND, marginRight: 8 },

  /* Chat */
  msgRow: { flexDirection: 'row', alignItems: 'flex-end', marginBottom: 12 },
  msgRowSelf: { justifyContent: 'flex-end' },
  msgAvatar: { width: 32, height: 32, borderRadius: 10, alignItems: 'center', justifyContent: 'center', marginRight: 8 },
  msgAvatarTxt: { color: '#fff', fontSize: 12, fontWeight: '800' },
  msgBubbleWrap: { maxWidth: '78%' },
  msgAuthor: { fontSize: 12, fontWeight: '700', color: '#475569', marginBottom: 3, marginLeft: 4 },
  msgReply: { borderLeftWidth: 3, borderLeftColor: '#CBD5E1', paddingLeft: 8, marginBottom: 4, marginLeft: 4 },
  msgReplyAuthor: { fontSize: 11.5, fontWeight: '700', color: '#64748B' },
  msgReplyTxt: { fontSize: 11.5, color: MUTE },
  msgBubble: { backgroundColor: '#fff', borderRadius: 16, paddingVertical: 10, paddingHorizontal: 13, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 6, shadowOffset: { width: 0, height: 2 }, elevation: 1 },
  msgBubbleSelf: { backgroundColor: BRAND },
  msgTxt: { fontSize: 14.5, color: INK, lineHeight: 20 },
  msgTime: { fontSize: 10.5, color: '#B6C0CC', marginTop: 3, marginHorizontal: 4 },
  composer: { flexDirection: 'row', alignItems: 'flex-end', padding: 10, paddingBottom: Platform.OS === 'ios' ? 24 : 10, backgroundColor: '#fff', borderTopWidth: 1, borderTopColor: '#EEF2F6', gap: 8 },
  composerInput: { flex: 1, maxHeight: 110, backgroundColor: '#F1F5F9', borderRadius: 20, paddingHorizontal: 16, paddingTop: Platform.OS === 'ios' ? 11 : 8, paddingBottom: Platform.OS === 'ios' ? 11 : 8, fontSize: 15, color: INK },
  composerBtn: { width: 44, height: 44, borderRadius: 22, backgroundColor: BRAND, alignItems: 'center', justifyContent: 'center' },

  /* Menu Plus (hub) */
  moreHeader: { flexDirection: 'row', alignItems: 'center', padding: 18, paddingTop: 22, backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#EEF2F6' },
  moreAvatar: { width: 52, height: 52, borderRadius: 16, backgroundColor: '#ECFDF5', alignItems: 'center', justifyContent: 'center', marginRight: 14, overflow: 'hidden' },
  moreAvatarImg: { width: 52, height: 52, borderRadius: 16 },
  moreAvatarTxt: { fontSize: 19, fontWeight: '800', color: BRAND },
  moreOrg: { fontSize: 18, fontWeight: '800', color: INK },
  moreSub: { fontSize: 13, color: MUTE, marginTop: 2 },
  moreGroupTitle: { fontSize: 13, fontWeight: '700', color: '#64748B', marginBottom: 10, marginLeft: 2 },
  moreGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  moreItem: { width: '31%', backgroundColor: '#fff', borderRadius: 16, paddingVertical: 16, paddingHorizontal: 6, alignItems: 'center', shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 2 },
  moreItemIcon: { width: 44, height: 44, borderRadius: 13, backgroundColor: '#ECFDF5', alignItems: 'center', justifyContent: 'center', marginBottom: 8 },
  moreItemTxt: { fontSize: 12, fontWeight: '600', color: INK, textAlign: 'center' },
  founderBlock: { marginBottom: 22 },
  founderBanner: { flexDirection: 'row', alignItems: 'center', gap: 13, backgroundColor: '#1F1804', borderRadius: 18, borderWidth: 1, borderColor: 'rgba(252,211,77,0.35)', paddingVertical: 15, paddingHorizontal: 16, marginBottom: 12 },
  founderStar: { width: 40, height: 40, borderRadius: 12, backgroundColor: '#FCD34D', alignItems: 'center', justifyContent: 'center' },
  founderTitle: { fontSize: 15.5, fontWeight: '800', color: '#FCD34D', letterSpacing: 0.2 },
  founderSub: { fontSize: 12, color: '#D6C79A', marginTop: 2 },
  founderGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  founderItem: { width: '31%', backgroundColor: '#FFFBEB', borderRadius: 16, borderWidth: 1, borderColor: '#FDE68A', paddingVertical: 15, paddingHorizontal: 6, alignItems: 'center' },
  founderItemIcon: { width: 42, height: 42, borderRadius: 12, backgroundColor: '#FEF3C7', alignItems: 'center', justifyContent: 'center', marginBottom: 7 },
  founderItemTxt: { fontSize: 11.5, fontWeight: '700', color: '#92400E', textAlign: 'center' },
  logoutBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 6, paddingVertical: 14, borderRadius: 14, borderWidth: 1.5, borderColor: '#FECACA', backgroundColor: '#FEF2F2' },
  logoutTxt: { fontSize: 15, fontWeight: '700', color: '#DC2626' },

  /* Stats */
  statGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  statCard: { width: '48%', backgroundColor: '#fff', borderRadius: 16, padding: 16, marginBottom: 12, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  statVal: { fontSize: 22, fontWeight: '800', letterSpacing: -0.4 },
  statLbl: { fontSize: 12.5, color: MUTE, marginTop: 4 },
  bar: { width: 22, borderRadius: 6, backgroundColor: BRAND, marginTop: 6 },
  barVal: { fontSize: 10, color: '#64748B', fontWeight: '600' },
  barLbl: { fontSize: 10.5, color: MUTE, marginTop: 6 },

  /* Notifications */
  notifCard: { flexDirection: 'row', alignItems: 'flex-start', backgroundColor: '#fff', borderRadius: 14, padding: 13, marginBottom: 8, shadowColor: '#0F172A', shadowOpacity: 0.04, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 1 },
  notifUnread: { backgroundColor: '#F7FFFC', borderWidth: 1, borderColor: '#D1FAE5' },
  notifIcon: { width: 36, height: 36, borderRadius: 11, backgroundColor: '#F1F5F9', alignItems: 'center', justifyContent: 'center', marginRight: 12 },
  notifTitle: { fontSize: 14, fontWeight: '600', color: INK, lineHeight: 19 },
  notifBody: { fontSize: 12.5, color: '#64748B', marginTop: 2 },
  notifAgo: { fontSize: 11, color: MUTE, marginTop: 4 },

  /* Coach IA */
  coachWeek: { fontSize: 13, fontWeight: '700', color: BRAND, marginBottom: 10 },
  coachRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 9, backgroundColor: '#fff', borderRadius: 12, padding: 13, marginBottom: 8 },
  coachRowTxt: { flex: 1, fontSize: 14, color: '#334155', lineHeight: 20 },
  recoCard: { flexDirection: 'row', alignItems: 'flex-start', gap: 12, backgroundColor: '#F0FDF9', borderWidth: 1, borderColor: '#D1FAE5', borderRadius: 14, padding: 14, marginBottom: 10 },
  recoIcon: { fontSize: 22 },
  recoTitle: { fontSize: 14.5, fontWeight: '700', color: INK },
  recoWhy: { fontSize: 13, color: '#475569', marginTop: 3, lineHeight: 18 },

  /* Réglages */
  logoRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  logoBox: { width: 64, height: 64, borderRadius: 16, backgroundColor: '#F1F5F9', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  logoImg: { width: 64, height: 64, borderRadius: 16 },
  settingsRow: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: '#fff', borderRadius: 12, padding: 15, marginTop: 10, shadowColor: '#0F172A', shadowOpacity: 0.04, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 1 },
  settingsRowTxt: { flex: 1, fontSize: 14.5, fontWeight: '600', color: INK },
  deleteTxt: { fontSize: 13.5, color: '#DC2626', fontWeight: '600', textDecorationLine: 'underline' },

  /* Back arrow sur les écrans d'onglet */
  projBack: { width: 34, height: 34, alignItems: 'center', justifyContent: 'center', marginLeft: -6, marginRight: 2 },

  /* Carte Analyse IA (factures) */
  aiCard: { backgroundColor: '#F5F3FF', borderWidth: 1, borderColor: '#DDD6FE', borderRadius: 16, padding: 15, marginTop: 14, marginBottom: 6 },
  aiHead: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: 8 },
  aiTitle: { fontSize: 13, fontWeight: '800', color: '#6D28D9', letterSpacing: 0.2 },
  aiTxt: { fontSize: 14, color: '#334155', lineHeight: 20 },
  aiMuted: { fontSize: 13, color: '#7C6FAE' },
  aiBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 7, marginTop: 12, paddingVertical: 11, borderRadius: 12, backgroundColor: '#EDE9FE' },
  aiBtnTxt: { fontSize: 14, fontWeight: '700', color: '#6D28D9' },

  /* Cockpit IA (stats) */
  cockpitCard: { borderRadius: 20, padding: 18, marginBottom: 18 },
  cockpitHead: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  cockpitTitle: { flex: 1, fontSize: 15.5, fontWeight: '800', color: '#fff' },
  healthPill: { backgroundColor: 'rgba(255,255,255,0.22)', paddingVertical: 4, paddingHorizontal: 11, borderRadius: 20 },
  healthTxt: { color: '#fff', fontSize: 12.5, fontWeight: '800' },
  cockpitSummary: { color: 'rgba(255,255,255,0.95)', fontSize: 14, lineHeight: 20, marginTop: 12 },
  cockpitInsight: { flexDirection: 'row', alignItems: 'flex-start', gap: 8, marginTop: 8 },
  cockpitBullet: { color: 'rgba(255,255,255,0.7)', fontSize: 15, fontWeight: '800', lineHeight: 19 },
  cockpitInsightTxt: { flex: 1, color: 'rgba(255,255,255,0.9)', fontSize: 13.5, lineHeight: 19 },
  cockpitAction: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, backgroundColor: 'rgba(255,255,255,0.14)', borderRadius: 13, padding: 12 },
  cockpitActTitle: { color: '#fff', fontSize: 14, fontWeight: '700' },
  cockpitActWhy: { color: 'rgba(255,255,255,0.82)', fontSize: 12.5, marginTop: 2, lineHeight: 17 },
  cockpitEmpty: { color: 'rgba(255,255,255,0.9)', fontSize: 13.5, lineHeight: 19, marginTop: 10 },
  cockpitBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 16, paddingVertical: 13, borderRadius: 13, backgroundColor: '#fff' },
  cockpitBtnTxt: { fontSize: 14.5, fontWeight: '800', color: '#4F46E5' },

  /* Boutons Bilan PDF */
  bilanNote: { fontSize: 12.5, color: '#94A3B8', marginTop: 12, lineHeight: 17 },
  pdfRow: { flexDirection: 'row', gap: 10, marginTop: 12 },
  pdfBtn: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, paddingVertical: 13, borderRadius: 13, borderWidth: 1.5, borderColor: '#DDD6FE', backgroundColor: '#F5F3FF' },
  pdfBtnTxt: { fontSize: 12.5, fontWeight: '700', color: '#4F46E5' },

  /* Bouton retour flottant (pages web / PDF) */
  floatBack: { position: 'absolute', top: 10, left: 12, flexDirection: 'row', alignItems: 'center', gap: 2, backgroundColor: 'rgba(255,255,255,0.96)', borderRadius: 22, paddingVertical: 8, paddingLeft: 8, paddingRight: 14, shadowColor: '#0F172A', shadowOpacity: 0.16, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 6 },
  floatBackTxt: { fontSize: 14.5, fontWeight: '700', color: INK },

  emptyBox: { alignItems: 'center', paddingTop: 70, paddingHorizontal: 30 },
  emptyTxt: { color: '#64748B', fontSize: 15, marginTop: 14, fontWeight: '500' },
  emptyBtn: { marginTop: 18, backgroundColor: BRAND, paddingVertical: 12, paddingHorizontal: 22, borderRadius: 12 },
  emptyBtnTxt: { color: '#fff', fontSize: 14.5, fontWeight: '700' },

  /* Tab bar */
  tabBar: { flexDirection: 'row', backgroundColor: '#fff', borderTopWidth: 1, borderTopColor: '#EEF2F6', paddingBottom: Platform.OS === 'ios' ? 22 : 10, paddingTop: 8, alignItems: 'flex-end', shadowColor: '#0F172A', shadowOpacity: 0.06, shadowRadius: 12, shadowOffset: { width: 0, height: -3 }, elevation: 12 },
  tab: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3 },
  tabLabel: { fontSize: 11, fontWeight: '600' },
  fabWrap: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  fab: { width: 56, height: 56, borderRadius: 28, backgroundColor: BRAND, alignItems: 'center', justifyContent: 'center', marginTop: -26, shadowColor: BRAND, shadowOpacity: 0.4, shadowRadius: 10, shadowOffset: { width: 0, height: 6 }, elevation: 8, borderWidth: 4, borderColor: '#fff' },

  /* Quick actions sheet */
  sheetBackdrop: { flex: 1, backgroundColor: 'rgba(15,23,42,0.45)', justifyContent: 'flex-end' },
  sheet: { backgroundColor: '#fff', borderTopLeftRadius: 24, borderTopRightRadius: 24, paddingHorizontal: 18, paddingTop: 10, paddingBottom: 34 },
  sheetHandle: { alignSelf: 'center', width: 40, height: 5, borderRadius: 3, backgroundColor: '#E2E8F0', marginBottom: 12 },
  sheetTitle: { fontSize: 18, fontWeight: '700', color: INK, marginBottom: 8, marginLeft: 4 },
  qaRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 13 },
  qaIcon: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center', marginRight: 14 },
  qaLabel: { flex: 1, fontSize: 16, fontWeight: '600', color: INK },

  /* Welcome — Liquid Glass */
  wBg: { flex: 1, backgroundColor: '#059669', overflow: 'hidden' },
  blob: { position: 'absolute', borderRadius: 9999 },
  blob1: { width: 380, height: 380, top: -110, right: -110, backgroundColor: 'rgba(255,255,255,0.10)' },
  blob2: { width: 320, height: 320, bottom: 20, left: -120, backgroundColor: 'rgba(16,201,141,0.22)' },
  blob3: { width: 240, height: 240, top: 200, right: 150, backgroundColor: 'rgba(255,255,255,0.06)' },
  wSafe: { flex: 1, paddingHorizontal: 24, justifyContent: 'space-between' },
  wTop: { alignItems: 'center', marginTop: 40 },
  logoHalo: { width: 116, height: 116, borderRadius: 34, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(255,255,255,0.10)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.18)', marginBottom: 22 },
  logoRing: { width: 88, height: 88, borderRadius: 26, padding: 3, backgroundColor: 'rgba(255,255,255,0.55)', shadowColor: '#000', shadowOpacity: 0.28, shadowRadius: 26, shadowOffset: { width: 0, height: 14 }, elevation: 12 },
  logoTile: { flex: 1, borderRadius: 23, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  logoGloss: { position: 'absolute', top: 0, left: 0, right: 0, height: '48%', backgroundColor: 'rgba(255,255,255,0.55)', borderTopLeftRadius: 23, borderTopRightRadius: 23 },
  logoDot: { position: 'absolute', right: 18, bottom: 18, width: 22, height: 22, borderRadius: 11, backgroundColor: BRAND, shadowColor: BRAND, shadowOpacity: 0.5, shadowRadius: 6, shadowOffset: { width: 0, height: 2 } },
  brand: { color: '#fff', fontSize: 44, fontWeight: '800', letterSpacing: -0.8, textShadowColor: 'rgba(0,0,0,0.18)', textShadowOffset: { width: 0, height: 3 }, textShadowRadius: 12 },
  taglinePill: { marginTop: 10, paddingHorizontal: 14, paddingVertical: 6, borderRadius: 20, backgroundColor: 'rgba(255,255,255,0.14)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.18)' },
  tagline: { color: 'rgba(255,255,255,0.95)', fontSize: 14.5, fontWeight: '500' },
  wFeatures: { marginVertical: 10 },
  glassCard: { flexDirection: 'row', alignItems: 'center', borderRadius: 18, paddingVertical: 16, paddingHorizontal: 16, marginBottom: 12, overflow: 'hidden', borderWidth: 1, borderColor: 'rgba(255,255,255,0.30)' },
  wFeatureIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: 'rgba(255,255,255,0.22)', alignItems: 'center', justifyContent: 'center', marginRight: 14 },
  wFeatureTxt: { color: '#fff', fontSize: 15.5, fontWeight: '600', flex: 1 },
  actions: { marginBottom: 20 },
  btnPrimary: { backgroundColor: '#fff', borderRadius: 16, paddingVertical: 17, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, shadowColor: '#000', shadowOpacity: 0.18, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: 5 },
  btnPrimaryTxt: { color: '#047857', fontSize: 17, fontWeight: '800' },
  btnGlassWrap: { marginTop: 12, borderRadius: 16, overflow: 'hidden' },
  btnGlass: { paddingVertical: 16, alignItems: 'center', borderWidth: 1.5, borderColor: 'rgba(255,255,255,0.45)', borderRadius: 16, overflow: 'hidden' },
  btnGhostTxt: { color: '#fff', fontSize: 16, fontWeight: '700' },
  wFooter: { color: 'rgba(255,255,255,0.9)', fontSize: 12.5, textAlign: 'center', marginTop: 18 },
});
