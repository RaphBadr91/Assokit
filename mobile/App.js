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
  Linking,
} from 'react-native';
import { WebView } from 'react-native-webview';
import { LinearGradient } from 'expo-linear-gradient';
import { BlurView } from 'expo-blur';
import { Ionicons } from '@expo/vector-icons';
import * as Notifications from 'expo-notifications';
import * as ImagePicker from 'expo-image-picker';
import * as LocalAuthentication from 'expo-local-authentication';
import * as SecureStore from 'expo-secure-store';
import * as FileSystem from 'expo-file-system/legacy';
import * as Sharing from 'expo-sharing';
import Constants from 'expo-constants';

const BASE = 'https://assokit.fr';
const BRAND = '#059669';
const INK = '#0F172A';
const MUTE = '#64748B';

// Verifie qu'une URL appartient bien au domaine assokit.fr (ou un sous-domaine),
// et non a un domaine piege du type "assokit.fr.attaquant.com" ou "evil-assokit.fr".
function isAssokitUrl(u) {
  try {
    const h = new URL(String(u)).hostname.toLowerCase();
    return h === 'assokit.fr' || h.endsWith('.assokit.fr');
  } catch (e) { return false; }
}

const APP_ONLY_CSS = `
(function(){ try {
  if (!document.getElementById('ak-app-only-css')) {
    var s = document.createElement('style');
    s.id = 'ak-app-only-css';
    s.textContent = '.ak-trial-banner{display:none!important}#ak-pwa-banner{display:none!important}.sb-mobile-header{display:none!important}#demo-banner{display:none!important}'
      /* Conformité stores : aucune mention de paiement / abonnement Assokit dans l\'app */
      + 'a[href*="/tarifs"],a[href*="/mon-asso-plan"],a[href*="/mon-asso-abonnement"],a[href*="/mon-asso-annuler-abonnement"],a[href*="/upgrade"],.ak-upsell,.ak-upgrade,.ak-pricing,[data-upsell]{display:none!important}';
    (document.head || document.documentElement).appendChild(s);
  }
} catch(e){} })();
true;
`;

// Style « app-only » du formulaire de connexion (le SITE reste inchangé — logo/police NON touchés)
const LOGIN_CSS = "(function(){ try { if(document.getElementById('ak-login-css')) return true; var s=document.createElement('style'); s.id='ak-login-css'; s.textContent='body{background:radial-gradient(120% 80% at 50% 0%,#E9FBF3 0%,#F3F6F8 45%,#EAF2EE 100%)!important}.login-card{border:1px solid rgba(16,185,129,.12)!important;border-radius:24px!important;box-shadow:0 40px 70px -28px rgba(4,98,74,.35)!important;padding:34px 26px 30px!important}.login-card h1{font-size:27px!important;font-weight:800!important;letter-spacing:-.03em!important}.subtitle{font-size:14px!important}.form-group{margin-bottom:15px!important}.form-group label{font-weight:600!important}.form-group input{padding:15px 16px!important;border-radius:14px!important;border:1.5px solid #E5E7EB!important;font-size:16px!important;background:#F9FAFB!important;transition:all .15s ease!important}.form-group input:focus{border-color:#059669!important;box-shadow:0 0 0 4px rgba(5,150,105,.14)!important;background:#fff!important;outline:none!important}.btn-submit{background:linear-gradient(140deg,#10B981,#059669)!important;border:0!important;border-radius:14px!important;padding:16px!important;font-size:16px!important;font-weight:750!important;box-shadow:0 16px 30px -12px rgba(5,150,105,.65),inset 0 1px 0 rgba(255,255,255,.3)!important;letter-spacing:.01em!important}.forgot-link{color:#059669!important;font-weight:600!important}.footer-note a{color:#059669!important;font-weight:600!important}.error-box{border-radius:12px!important}'; (document.head||document.documentElement).appendChild(s);}catch(e){} return true; })(); true;";

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
    + " var mime=" + JSON.stringify(mime) + ";"
    + " var extMap={ 'image/png':'png','image/jpeg':'jpg','image/jpg':'jpg','image/webp':'webp','image/heic':'heic','image/heif':'heif','application/pdf':'pdf' };"
    + " var ext=extMap[mime] || 'jpg';"
    + " var b64=" + JSON.stringify(base64) + ";"
    + " var bin=atob(b64); var arr=new Uint8Array(bin.length);"
    + " for (var i=0;i<bin.length;i++) arr[i]=bin.charCodeAt(i);"
    + " var blob=new Blob([arr], { type: mime });"
    + " var fd=new FormData();"
    + " fd.append('invoice_file', blob, 'facture.' + ext);"
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
  // Echappement propre via JSON.stringify (evite la casse sur apostrophe + toute injection JS)
  return "(function(){ try { window.location.href=" + JSON.stringify(BASE + path) + "; } catch(e){} })(); true;";
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

// Assombrit une couleur hex (#RRGGBB) pour un dégradé d'icône premium
function shade(hex, f) {
  f = f == null ? 0.78 : f;
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex || ''));
  if (!m) return hex;
  const n = parseInt(m[1], 16);
  const r = Math.round(((n >> 16) & 255) * f);
  const g = Math.round(((n >> 8) & 255) * f);
  const b = Math.round((n & 255) * f);
  return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

function fmtEuro(n) {
  n = Number(n) || 0;
  if (n >= 1000) return (n / 1000).toFixed(1).replace('.', ',') + ' k€';
  return Math.round(n) + ' €';
}

// Montant exact à 2 décimales (cotisations, tarifs, confirmations d'encaissement) :
// fmtEuro arrondit et abrège en k€, ce qui afficherait « 13 € » pour un tarif à 12,50 €.
function fmtEuro2(n) {
  return (Number(n) || 0).toFixed(2).replace('.', ',') + ' €';
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
  { label: 'Nouveau message', icon: 'chatbubble-ellipses', color: '#7C3AED', screen: 'messages' },
];

const QUICK_ACTIONS_TPE = [
  { label: 'Nouvelle facture', icon: 'document-text', color: '#2563EB', form: 'invoice' },
  { label: 'Nouveau devis', icon: 'create', color: '#059669', form: 'quote' },
  { label: 'Nouveau client', icon: 'person-add', color: '#D97706', form: 'client' },
  { label: 'Nouveau message', icon: 'chatbubble-ellipses', color: '#7C3AED', screen: 'messages' },
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
          <TouchableOpacity accessibilityRole="button" style={styles.btnPrimary} onPress={onLogin} activeOpacity={0.9}>
            <Text style={styles.btnPrimaryTxt}>Se connecter</Text>
            <Ionicons name="arrow-forward" size={18} color="#047857" />
          </TouchableOpacity>
          <TouchableOpacity accessibilityRole="button" activeOpacity={0.85} onPress={onSignup} style={styles.btnGlassWrap}>
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
/*  CONNEXION NATIVE (formulaire natif — conforme App Store)           */
/* ================================================================== */
function NativeLogin({ onSubmit, busy, error, onForgot, onDemo, onBack, onFaceId, hasFaceId }) {
  const [email, setEmail] = useState('');
  const [pass, setPass] = useState('');
  const [show, setShow] = useState(false);
  const canSubmit = email.trim().length > 2 && pass.length > 0 && !busy;
  return (
    <View style={styles.lgWrap}>
      <LinearGradient colors={['#E9F7F1', '#F4FAF8', '#E7F5EF']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={StyleSheet.absoluteFill} />
      <View style={[styles.lgBlob, styles.lgBlob1]} />
      <View style={[styles.lgBlob, styles.lgBlob2]} />
      <StatusBar barStyle="dark-content" />
      <SafeAreaView style={{ flex: 1 }}>
        <TouchableOpacity accessibilityRole="button" style={styles.lgBack} activeOpacity={0.8} onPress={onBack} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
          <Ionicons name="chevron-back" size={20} color="#0F172A" />
          <Text style={styles.lgBackTxt}>Retour</Text>
        </TouchableOpacity>
        <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
          <ScrollView contentContainerStyle={styles.lgScroll} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
            <View style={styles.lgCard}>
              <View style={styles.lgBrandRow}>
                <Text style={styles.lgBrand}>asso<Text style={styles.lgBrandKit}>kit</Text><Text style={styles.lgBrandDot}>.</Text></Text>
              </View>
              <Text style={styles.lgTagline}>L'art de mener vos projets</Text>

              <Text style={styles.lgTitle}>Bienvenue</Text>
              <Text style={styles.lgSub}>Connectez-vous à votre espace Assokit</Text>

              <Text style={styles.lgLabel}>Email</Text>
              <TextInput
                style={styles.lgInput}
                value={email}
                onChangeText={setEmail}
                placeholder="vous@association.fr"
                placeholderTextColor="#9AA7A1"
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                textContentType="username"
                returnKeyType="next"
                editable={!busy}
              />

              <Text style={styles.lgLabel}>Mot de passe</Text>
              <View style={styles.lgPassRow}>
                <TextInput
                  style={styles.lgPassInput}
                  value={pass}
                  onChangeText={setPass}
                  placeholder="••••••••"
                  placeholderTextColor="#9AA7A1"
                  secureTextEntry={!show}
                  autoCapitalize="none"
                  autoCorrect={false}
                  textContentType="password"
                  returnKeyType="go"
                  onSubmitEditing={() => canSubmit && onSubmit(email.trim(), pass)}
                  editable={!busy}
                />
                <TouchableOpacity accessibilityRole="button" accessibilityLabel={show ? 'Masquer le mot de passe' : 'Afficher le mot de passe'} onPress={() => setShow((s) => !s)} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
                  <Ionicons name={show ? 'eye-off' : 'eye'} size={20} color="#94A3B8" />
                </TouchableOpacity>
              </View>

              {error ? (
                <View style={styles.lgError}>
                  <Ionicons name="alert-circle" size={16} color="#DC2626" />
                  <Text style={styles.lgErrorTxt}>{error}</Text>
                </View>
              ) : null}

              <TouchableOpacity accessibilityRole="button" style={[styles.lgBtn, !canSubmit && styles.lgBtnOff]} activeOpacity={0.9}
                onPress={() => canSubmit && onSubmit(email.trim(), pass)} disabled={!canSubmit}>
                {busy ? <ActivityIndicator color="#fff" /> : <><Text style={styles.lgBtnTxt}>Se connecter</Text><Ionicons name="arrow-forward" size={18} color="#fff" /></>}
              </TouchableOpacity>

              <TouchableOpacity accessibilityRole="button" onPress={onForgot} activeOpacity={0.7} style={{ alignSelf: 'center', paddingVertical: 6 }}>
                <Text style={styles.lgForgot}>Mot de passe oublié ?</Text>
              </TouchableOpacity>

              {hasFaceId ? (
                <TouchableOpacity accessibilityRole="button" style={styles.lgFace} activeOpacity={0.85} onPress={onFaceId}>
                  <Ionicons name="finger-print" size={19} color="#059669" />
                  <Text style={styles.lgFaceTxt}>Se connecter avec Face ID</Text>
                </TouchableOpacity>
              ) : null}

              <View style={styles.lgDivider} />
              <Text style={styles.lgFooter}>
                Pas encore de compte ? <Text style={styles.lgLink} onPress={onDemo}>Créer ma démo</Text>
              </Text>
            </View>
            <Text style={styles.lgHosted}>🇫🇷 Hébergé en France · Conforme RGPD</Text>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

/* ================================================================== */
/*  ACCUEIL NATIF (KPIs premium)                                       */
/* ================================================================== */
function NativeHome({ data, loading, onRefresh, onGoto, profile, error }) {
  const k = (data && data.kpis) || {};
  const isTpe = profile === 'tpe';

  // Métrique vedette (spotlight) — cœur du dashboard, effet verre liquide
  const spot = isTpe
    ? {
        label: 'Chiffre d\'affaires encaissé',
        value: fmtEuro(k.ca_paid),
        icon: 'trending-up',
        path: '/mon-asso-factures',
        pct: (() => { const t = (k.ca_paid || 0) + (k.impayes || 0); return t > 0 ? Math.round(((k.ca_paid || 0) / t) * 100) : 0; })(),
        barLabel: fmtEuro(k.impayes) + ' en attente',
        chip: (k.factures ?? 0) + ' facture' + ((k.factures ?? 0) > 1 ? 's' : ''),
      }
    : {
        label: 'Budget engagé',
        value: fmtEuro(k.budget_used),
        icon: 'wallet',
        path: '/projets',
        pct: (k.budget_planned > 0 ? Math.min(100, Math.round(((k.budget_used || 0) / k.budget_planned) * 100)) : 0),
        barLabel: 'sur ' + fmtEuro(k.budget_planned) + ' prévus',
        chip: (k.projets_actifs ?? 0) + ' projet' + ((k.projets_actifs ?? 0) > 1 ? 's' : '') + ' actif' + ((k.projets_actifs ?? 0) > 1 ? 's' : ''),
      };

  const cards = isTpe
    ? [
        { icon: 'briefcase', color: '#2563EB', g: ['#EFF6FF', '#DCEAFE'], label: 'Clients', value: String(k.clients ?? 0), sub: 'au total', path: '/mon-asso-clients' },
        { icon: 'document-text', color: '#059669', g: ['#ECFDF5', '#D1FAE5'], label: 'Devis en cours', value: String(k.devis_encours ?? 0), sub: 'à relancer', path: '/mon-asso-devis' },
        { icon: 'card', color: '#7C3AED', g: ['#F5F3FF', '#E9E2FE'], label: 'Factures', value: String(k.factures ?? 0), sub: 'émises', path: '/mon-asso-factures' },
        { icon: 'alert-circle', color: '#D97706', g: ['#FFFBEB', '#FEF0C7'], label: 'Impayés', value: fmtEuro(k.impayes), sub: 'à recouvrer', path: '/mon-asso-factures' },
      ]
    : [
        { icon: 'folder', color: '#059669', g: ['#ECFDF5', '#D1FAE5'], label: 'Projets actifs', value: String(k.projets_actifs ?? 0), sub: 'en cours', path: '/projets' },
        { icon: 'people', color: '#2563EB', g: ['#EFF6FF', '#DCEAFE'], label: 'Membres', value: String(k.membres ?? 0), sub: (k.membres_nouveaux > 0 ? '+' + k.membres_nouveaux + ' en 30j' : 'actifs'), path: '/adherents' },
        { icon: 'calendar', color: '#D97706', g: ['#FFFBEB', '#FEF0C7'], label: 'Événements', value: String(k.evenements ?? 0), sub: 'à venir', path: '/agenda' },
        { icon: 'sparkles', color: '#7C3AED', g: ['#F5F3FF', '#E9E2FE'], label: 'Nouveaux', value: String(k.membres_nouveaux ?? 0), sub: 'ce mois-ci', path: '/adherents' },
      ];
  const shortcuts = isTpe ? SHORTCUTS_TPE : SHORTCUTS_ASSO;

  return (
    <View style={styles.homeAurora}>
      {/* Fond « Aurora Glass » : dégradé doux + halos colorés */}
      <LinearGradient colors={['#EAF7F1', '#E9F0FB', '#F2EBFB']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={StyleSheet.absoluteFill} />
      <View style={styles.auroraOrbA} pointerEvents="none" />
      <View style={styles.auroraOrbB} pointerEvents="none" />
      <ScrollView
        style={styles.homeScroll}
        contentContainerStyle={styles.homeContent}
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}
      >
      <View style={styles.hHeaderWrap}>
        <BlurView intensity={34} tint="light" style={styles.hHeader}>
          {/* Halos aurora subtils */}
          <View style={styles.hOrb1} />
          <View style={styles.hOrb2} />
          <View style={styles.hHeaderRow}>
            <View style={{ flex: 1 }}>
              <Text style={styles.hHello}>{greeting()}{data && data.first_name ? ',' : ''}</Text>
              {data && data.first_name ? <Text style={styles.hName}>{data.first_name} 👋</Text> : null}
              <View style={styles.hOrgPill}>
                <View style={styles.hOrgDot} />
                <Text style={styles.hOrg} numberOfLines={1}>{(data && data.org_name) || ' '}</Text>
              </View>
            </View>
            <View style={styles.hAvatar}>
              {data && data.org_logo ? (
                <Image source={{ uri: data.org_logo }} style={styles.hAvatarImg} resizeMode="contain" />
              ) : (
                <Text style={styles.hAvatarTxt}>{(data && data.org_initials) || '·'}</Text>
              )}
            </View>
          </View>
        </BlurView>
      </View>

      {!data ? (
        error ? (
          <View style={styles.homeLoader}>
            <Ionicons name="cloud-offline-outline" size={40} color={MUTE} />
            <Text style={styles.homeLoaderTxt}>Connexion impossible. Vérifiez votre réseau.</Text>
            <TouchableOpacity
              accessibilityRole="button"
              accessibilityLabel="Réessayer le chargement"
              onPress={onRefresh}
              activeOpacity={0.85}
              style={{ marginTop: 14, backgroundColor: BRAND, paddingHorizontal: 22, paddingVertical: 11, borderRadius: 12 }}
            >
              <Text style={{ color: '#fff', fontWeight: '700', fontSize: 14 }}>Réessayer</Text>
            </TouchableOpacity>
          </View>
        ) : (
          <View style={styles.homeLoader}>
            <ActivityIndicator size="large" color={BRAND} />
            <Text style={styles.homeLoaderTxt}>Chargement de vos données…</Text>
          </View>
        )
      ) : (
        <>
          {/* Carte vedette en verre liquide, flottant sur le header */}
          <TouchableOpacity accessibilityRole="button" activeOpacity={0.9} onPress={() => onGoto(spot.path)} style={styles.spotShadow}>
            <BlurView intensity={38} tint="light" style={styles.spotCard}>
              <View style={styles.spotGloss} />
              <View style={styles.spotTopRow}>
                <View style={styles.spotIconWrap}>
                  <Ionicons name={spot.icon} size={17} color="#065F46" />
                </View>
                <Text style={styles.spotLabel}>{spot.label}</Text>
                <View style={styles.spotChip}><Text style={styles.spotChipTxt}>{spot.chip}</Text></View>
              </View>
              <Text style={styles.spotValue}>{spot.value}</Text>
              <View style={styles.spotBarTrack}>
                <LinearGradient colors={['#0CCB8F', '#059669']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0 }}
                  style={[styles.spotBarFill, { width: Math.max(6, spot.pct) + '%' }]} />
              </View>
              <View style={styles.spotBarMeta}>
                <Text style={styles.spotBarPct}>{spot.pct}%</Text>
                <Text style={styles.spotBarLabel}>{spot.barLabel}</Text>
              </View>
            </BlurView>
          </TouchableOpacity>

          <View style={styles.kpiGrid}>
            {cards.map((c) => (
              <TouchableOpacity accessibilityRole="button" key={c.label} style={styles.kpiShadow} activeOpacity={0.88} onPress={() => onGoto(c.path)}>
                <View style={styles.kpiCard}>
                  <View style={styles.kpiGloss} />
                  <View style={styles.kpiTop}>
                    <LinearGradient colors={[c.color, shade(c.color)]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.kpiIcon}>
                      <Ionicons name={c.icon} size={20} color="#fff" />
                    </LinearGradient>
                    <View style={[styles.kpiDot, { backgroundColor: c.color }]} />
                  </View>
                  <Text style={styles.kpiValue}>{c.value}</Text>
                  <Text style={styles.kpiLabel}>{c.label}</Text>
                  <Text style={styles.kpiSub}>{c.sub}</Text>
                </View>
              </TouchableOpacity>
            ))}
          </View>

          <Text style={styles.sectionTitle}>Accès rapide</Text>
          <View style={styles.shortcuts}>
            {shortcuts.map((s) => (
              <TouchableOpacity accessibilityRole="button" key={s.label} style={styles.shortcut} activeOpacity={0.8} onPress={() => onGoto(s.path)}>
                <View style={styles.shortcutIcon}>
                  <Ionicons name={s.icon} size={22} color={BRAND} />
                </View>
                <Text style={styles.shortcutTxt}>{s.label}</Text>
                <Ionicons name="chevron-forward" size={16} color="#CBD5E1" />
              </TouchableOpacity>
            ))}
          </View>

          <TouchableOpacity accessibilityRole="button" style={styles.openFullShadow} activeOpacity={0.9} onPress={() => onGoto('/dashboard')}>
            <LinearGradient colors={['#0CCB8F', '#047857']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.openFull}>
              <Text style={styles.openFullTxt}>Ouvrir le tableau de bord complet</Text>
              <Ionicons name="arrow-forward" size={18} color="#fff" />
            </LinearGradient>
          </TouchableOpacity>
        </>
      )}
      </ScrollView>
    </View>
  );
}

/* ================================================================== */
/*  PROJETS (liste native)                                             */
/* ================================================================== */
// Chargement de liste : si ça reste bloqué (échec réseau), propose « Réessayer » après 4 s.
function ListLoader({ onRefresh }) {
  const [showRetry, setShowRetry] = useState(false);
  useEffect(() => { const t = setTimeout(() => setShowRetry(true), 4000); return () => clearTimeout(t); }, []);
  return (
    <View style={styles.homeLoader}>
      <ActivityIndicator size="large" color={BRAND} />
      <Text style={styles.homeLoaderTxt}>{showRetry ? 'Connexion lente…' : 'Chargement…'}</Text>
      {showRetry && onRefresh ? (
        <TouchableOpacity accessibilityRole="button" accessibilityLabel="Réessayer" onPress={onRefresh} activeOpacity={0.85}
          style={{ marginTop: 16, backgroundColor: BRAND, paddingHorizontal: 22, paddingVertical: 11, borderRadius: 12 }}>
          <Text style={{ color: '#fff', fontWeight: '700', fontSize: 14 }}>Réessayer</Text>
        </TouchableOpacity>
      ) : null}
    </View>
  );
}

function NativeProjects({ data, loading, onRefresh, onOpen, onNew, onBack }) {
  const projects = (data && data.projects) || [];
  return (
    <View style={styles.projWrap}>
      <LinearGradient colors={['#EAF7F1', '#E9F0FB', '#F2EBFB']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={StyleSheet.absoluteFill} />
      <View style={styles.auroraOrbA} pointerEvents="none" />
      <View style={styles.auroraOrbB} pointerEvents="none" />
      <View style={styles.projHeader}>
        {onBack && <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retour" onPress={onBack} style={styles.projBack} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="chevron-back" size={26} color={INK} /></TouchableOpacity>}
        <View style={{ flex: 1 }}>
          <Text style={styles.projTitle}>Projets</Text>
          <Text style={styles.projSub}>{projects.length} projet{projects.length > 1 ? 's' : ''}</Text>
        </View>
        <TouchableOpacity accessibilityRole="button" style={[styles.projNewBtn, !onNew && { display: 'none' }]} onPress={onNew} activeOpacity={0.85}>
          <Ionicons name="add" size={19} color="#fff" />
          <Text style={styles.projNewTxt}>Nouveau</Text>
        </TouchableOpacity>
      </View>
      {!data ? (
        <ListLoader onRefresh={onRefresh} />
      ) : projects.length === 0 ? (
        <View style={styles.emptyBox}>
          <Ionicons name="folder-open-outline" size={44} color="#CBD5E1" />
          <Text style={styles.emptyTxt}>Aucun projet en cours</Text>
          <TouchableOpacity accessibilityRole="button" style={[styles.emptyBtn, !onNew && { display: 'none' }]} onPress={onNew} activeOpacity={0.85}>
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
              <TouchableOpacity accessibilityRole="button" key={p.id} style={styles.projCard} activeOpacity={0.85} onPress={() => onOpen(p.id)}>
                <View style={[styles.projAccent, { backgroundColor: sm.color }]} />
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
  if (data && data.allowed === false) return <GatedScreen title={isClients ? 'Clients' : 'Membres'} message={data.message} onBack={onBack} />;
  const list = data ? (isClients ? (data.clients || []) : (data.members || [])) : null;
  const title = isClients ? 'Clients' : 'Membres';
  const newLabel = isClients ? 'Nouveau' : 'Inviter';

  return (
    <View style={styles.projWrap}>
      <View style={styles.projHeader}>
        {onBack && <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retour" onPress={onBack} style={styles.projBack} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="chevron-back" size={26} color={INK} /></TouchableOpacity>}
        <View style={{ flex: 1 }}>
          <Text style={styles.projTitle}>{title}</Text>
          <Text style={styles.projSub}>{(list ? list.length : 0)} {title.toLowerCase()}</Text>
        </View>
        <TouchableOpacity accessibilityRole="button" style={[styles.projNewBtn, !onNew && { display: 'none' }]} onPress={onNew} activeOpacity={0.85}>
          <Ionicons name={isClients ? 'add' : 'person-add'} size={18} color="#fff" />
          <Text style={styles.projNewTxt}>{newLabel}</Text>
        </TouchableOpacity>
      </View>
      {!list ? (
        <ListLoader onRefresh={onRefresh} />
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}>
          <Ionicons name={isClients ? 'briefcase-outline' : 'people-outline'} size={44} color="#CBD5E1" />
          <Text style={styles.emptyTxt}>{isClients ? 'Aucun client' : 'Aucun membre'}</Text>
          <TouchableOpacity accessibilityRole="button" style={[styles.emptyBtn, !onNew && { display: 'none' }]} onPress={onNew} activeOpacity={0.85}>
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
            <TouchableOpacity accessibilityRole="button" key={p.id} style={styles.personCard} activeOpacity={0.85} onPress={() => onOpen(p.id)}>
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
  if (data && data.allowed === false) return <GatedScreen title="Factures" message={data.message} onBack={onBack} />;
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
        {onBack && <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retour" onPress={onBack} style={styles.projBack} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="chevron-back" size={26} color={INK} /></TouchableOpacity>}
        <View style={{ flex: 1 }}>
          <Text style={styles.projTitle}>Factures</Text>
          <Text style={styles.projSub}>{(list ? list.length : 0)} facture{(list && list.length > 1) ? 's' : ''}</Text>
        </View>
        <TouchableOpacity accessibilityRole="button" style={[styles.projNewBtn, !onNew && { display: 'none' }]} onPress={onNew} activeOpacity={0.85}>
          <Ionicons name="add" size={19} color="#fff" />
          <Text style={styles.projNewTxt}>Nouvelle</Text>
        </TouchableOpacity>
      </View>
      {!list ? (
        <ListLoader onRefresh={onRefresh} />
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
            <TouchableOpacity accessibilityRole="button" style={[styles.aiBtn, aiLoading ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={aiLoading ? undefined : onAnalyze}>
              {aiLoading ? <ActivityIndicator size="small" color="#7C3AED" /> : <Ionicons name="sparkles" size={15} color="#7C3AED" />}
              <Text style={styles.aiBtnTxt}>{aiLoading ? 'Analyse…' : (aiText ? 'Actualiser l\'analyse' : 'Analyser via IA')}</Text>
            </TouchableOpacity>
          </View>

          {list.length === 0 ? (
            <View style={styles.emptyBox}>
              <Ionicons name="receipt-outline" size={44} color="#CBD5E1" />
              <Text style={styles.emptyTxt}>Aucune facture</Text>
              <TouchableOpacity accessibilityRole="button" style={[styles.emptyBtn, !onNew && { display: 'none' }]} onPress={onNew} activeOpacity={0.85}><Text style={styles.emptyBtnTxt}>Créer une facture</Text></TouchableOpacity>
            </View>
          ) : list.map((inv) => {
            const km = INV_KIND[inv.status_kind] || INV_KIND.wait;
            return (
              <TouchableOpacity accessibilityRole="button" key={inv.id} style={styles.invCard} activeOpacity={0.85} onPress={() => onOpen(inv.id)}>
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
function DetailHeader({ title, onBack, onAction, actionIcon }) {
  return (
    <View style={styles.dHeader}>
      <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retour" onPress={onBack} style={styles.dBack} hitSlop={{ top: 12, bottom: 12, left: 12, right: 12 }} activeOpacity={0.7}>
        <Ionicons name="chevron-back" size={26} color={INK} />
      </TouchableOpacity>
      <Text style={styles.dTitle} numberOfLines={1}>{title}</Text>
      {onAction ? (
        <TouchableOpacity accessibilityRole="button" accessibilityLabel="Ajouter" onPress={onAction} style={styles.dHeadAction} hitSlop={{ top: 12, bottom: 12, left: 12, right: 12 }} activeOpacity={0.8}>
          <Ionicons name={actionIcon || 'add'} size={22} color="#fff" />
        </TouchableOpacity>
      ) : (
        <View style={{ width: 34 }} />
      )}
    </View>
  );
}

// Écran verrouillé par le rôle (le serveur a répondu allowed:false — parité avec le site)
function GatedScreen({ title, message, onBack }) {
  return (
    <View style={styles.detailWrap}>
      {onBack ? <DetailHeader title={title} onBack={onBack} /> : null}
      <View style={styles.emptyBox}>
        <Ionicons name="lock-closed" size={40} color="#CBD5E1" />
        <Text style={styles.emptyTxt}>{message || 'Réservé aux administrateurs.'}</Text>
      </View>
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
        <TouchableOpacity accessibilityRole="button" style={styles.emptyBtn} onPress={onRetry} activeOpacity={0.85}><Text style={styles.emptyBtnTxt}>Réessayer</Text></TouchableOpacity>
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

/* Bloc de texte riche : carte + puces + paragraphes lisibles (au lieu d'un pavé) */
function DescBlock({ label, text, tint }) {
  const raw = String(text || '');
  const lines = raw.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const bulletCount = lines.filter((l) => /^[-–•*·]/.test(l)).length;
  const asBullets = bulletCount >= 2;
  const c = tint || BRAND;
  return (
    <View style={styles.dInfoCard}>
      <Text style={[styles.dEyebrow, { color: c }]}>{label}</Text>
      {asBullets ? (
        lines.map((l, i) => {
          const isB = /^[-–•*·]\s?/.test(l);
          const t = l.replace(/^[-–•*·]\s?/, '');
          return isB ? (
            <View key={i} style={styles.dBullet}>
              <View style={[styles.dBulletDot, { backgroundColor: c }]} />
              <Text style={styles.dBulletTxt}>{t}</Text>
            </View>
          ) : (
            <Text key={i} style={styles.dPara}>{t}</Text>
          );
        })
      ) : (
        <Text style={styles.dPara}>{raw}</Text>
      )}
    </View>
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

        {!!p.description && <DescBlock label="Description" text={p.description} />}
        {!!p.objective && <DescBlock label="Objectif" text={p.objective} tint="#7C3AED" />}

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
            <Text style={styles.upsellTxt}>Cette fonctionnalité n'est pas disponible pour votre organisation.</Text>
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

        <Text style={styles.bilanNote}><Ionicons name="information-circle-outline" size={13} color="#94A3B8" /> Sans factures ni informations saisies, le bilan analytique sera incomplet.</Text>
        <View style={styles.pdfRow}>
          <TouchableOpacity accessibilityRole="button" style={[styles.pdfBtn, pdfBusy ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={() => !pdfBusy && onSharePdf('/download-bilan-analytique.php?project=' + p.id)}>
            {pdfBusy ? <ActivityIndicator size="small" color="#4F46E5" /> : <Ionicons name="share-outline" size={17} color="#4F46E5" />}
            <Text style={styles.pdfBtnTxt}>{pdfBusy ? 'Préparation…' : 'Bilan analytique (PDF)'}</Text>
          </TouchableOpacity>
          <TouchableOpacity accessibilityRole="button" style={[styles.pdfBtn, pdfBusy ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={pdfBusy ? undefined : () => onSharePdf('/download-bilan-analytique.php?project=' + p.id)}>
            <Ionicons name="document-text" size={17} color="#4F46E5" />
            <Text style={styles.pdfBtnTxt}>Bilan du projet</Text>
          </TouchableOpacity>
        </View>

        <TouchableOpacity accessibilityRole="button" style={styles.dPrimaryBtn} activeOpacity={0.85} onPress={() => onAddExpense(p.id)}>
          <Ionicons name="camera" size={19} color="#fff" />
          <Text style={styles.dPrimaryBtnTxt}>Scanner une facture</Text>
        </TouchableOpacity>
        <TouchableOpacity accessibilityRole="button" style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb('/projet/' + p.id)}>
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

        {d.admin === false ? (
          <View style={styles.dLockCard}>
            <Ionicons name="lock-closed" size={18} color="#94A3B8" />
            <Text style={styles.dLockTxt}>Les coordonnées des membres sont réservées aux administrateurs de l'association.</Text>
          </View>
        ) : (
          <>
            <Text style={styles.dSection}>Contact</Text>
            <View style={styles.dCard}>
              <InfoRow icon="mail" label="Email" value={m.email} onPress={m.email ? () => onWeb('mailto:' + m.email) : null} />
              <InfoRow icon="call" label="Téléphone" value={m.phone} onPress={m.phone ? () => onWeb('tel:' + String(m.phone).replace(/\s+/g, '')) : null} />
              <InfoRow icon="location" label="Ville" value={m.city} />
            </View>

            <Text style={styles.dSection}>Adhésion</Text>
            <View style={styles.dCard}>
              <InfoRow icon="calendar" label="Adhérent depuis" value={m.adhesion_since} />
              <InfoRow icon="time" label="Valide jusqu'au" value={m.adhesion_until} />
              <InfoRow icon="log-in" label="Dernière connexion" value={m.last_login} />
            </View>
          </>
        )}

        {d.projects && d.projects.length > 0 && (
          <>
            <Text style={styles.dSection}>Projets pilotés</Text>
            {d.projects.map((p) => {
              const sm = STATUS_META[p.status] || STATUS_META.active;
              return (
                <TouchableOpacity accessibilityRole="button" key={p.id} style={styles.projCard} activeOpacity={0.85} onPress={() => onOpenProject(p.id)}>
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

        {d.admin === false ? null : (
          <TouchableOpacity accessibilityRole="button" style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb('/adherent?id=' + m.id)}>
            <Text style={styles.dWebBtnTxt}>Ouvrir la fiche complète</Text>
            <Ionicons name="open-outline" size={18} color={BRAND} />
          </TouchableOpacity>
        )}
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

        {d.admin === false ? (
          <View style={styles.dLockCard}>
            <Ionicons name="lock-closed" size={18} color="#94A3B8" />
            <Text style={styles.dLockTxt}>Le fichier client (coordonnées et facturation) est réservé aux administrateurs.</Text>
          </View>
        ) : (
        <>
        <View style={styles.miniKpiRow}>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(s.paid)}</Text><Text style={styles.miniKpiLbl}>Encaissé</Text></View>
          <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: '#B45309' }]}>{fmtEuro((s.pending || 0) + (s.overdue || 0))}</Text><Text style={styles.miniKpiLbl}>En attente</Text></View>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.nb || 0}</Text><Text style={styles.miniKpiLbl}>Factures</Text></View>
        </View>

        <Text style={styles.dSection}>Contact</Text>
        <View style={styles.dCard}>
          <InfoRow icon="mail" label="Email" value={c.email} onPress={c.email ? () => onWeb('mailto:' + c.email) : null} />
          <InfoRow icon="call" label="Téléphone" value={c.phone} onPress={c.phone ? () => onWeb('tel:' + String(c.phone).replace(/\s+/g, '')) : null} />
          <InfoRow icon="location" label="Adresse" value={c.address} />
          <InfoRow icon="business" label="SIREN" value={c.siren} />
          <InfoRow icon="pricetag" label="N° TVA" value={c.vat_number} />
        </View>
        </>
        )}

        {d.admin !== false && d.invoices && d.invoices.length > 0 && (
          <>
            <Text style={styles.dSection}>Factures</Text>
            {d.invoices.map((inv) => {
              const km = INV_KIND[inv.status_kind] || INV_KIND.wait;
              return (
                <TouchableOpacity accessibilityRole="button" key={inv.id} style={styles.invCard} activeOpacity={0.85} onPress={() => onOpenInvoice(inv.id)}>
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

        {d.admin === false ? null : (
          <TouchableOpacity accessibilityRole="button" style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb('/mon-asso-client-detail?id=' + c.id)}>
            <Text style={styles.dWebBtnTxt}>Ouvrir la fiche complète</Text>
            <Ionicons name="open-outline" size={18} color={BRAND} />
          </TouchableOpacity>
        )}
      </ScrollView>
    </View>
  );
}

function NativeInvoiceDetail({ entry, onBack, onRefresh, onWeb, onEdit, onAction, busy }) {
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

        {!!inv.description && <DescBlock label="Note" text={inv.description} tint="#2563EB" />}

        {/* ── Actions natives (envoi, encaissement, conversion) ── */}
        {onAction && (
          <>
            {!isQuote && inv.status !== 'draft' && (
              <TouchableOpacity accessibilityRole="button" style={[styles.dPrimaryBtn, busy ? { opacity: 0.6 } : null]} activeOpacity={0.85}
                onPress={busy ? undefined : () => onAction('send')}>
                {busy === 'send' ? <ActivityIndicator color="#fff" /> : <Ionicons name="mail" size={18} color="#fff" />}
                <Text style={styles.dPrimaryBtnTxt}>{inv.status === 'overdue' ? 'Relancer le client par email' : 'Envoyer la facture par email'}</Text>
              </TouchableOpacity>
            )}
            {isQuote && (
              <TouchableOpacity accessibilityRole="button" style={[styles.dPrimaryBtn, busy ? { opacity: 0.6 } : null]} activeOpacity={0.85}
                onPress={busy ? undefined : () => onAction('send')}>
                {busy === 'send' ? <ActivityIndicator color="#fff" /> : <Ionicons name="mail" size={18} color="#fff" />}
                <Text style={styles.dPrimaryBtnTxt}>Envoyer le devis par email</Text>
              </TouchableOpacity>
            )}
            {!isQuote && (inv.status === 'pending' || inv.status === 'overdue') && (
              <TouchableOpacity accessibilityRole="button" style={[styles.dActBtn, busy ? { opacity: 0.6 } : null]} activeOpacity={0.85}
                onPress={busy ? undefined : () => onAction('mark_paid')}>
                {busy === 'mark_paid' ? <ActivityIndicator color="#065F46" /> : <Ionicons name="checkmark-circle" size={18} color="#065F46" />}
                <Text style={styles.dActBtnTxt}>Marquer comme payée</Text>
              </TouchableOpacity>
            )}
            {isQuote && inv.status === 'signed' && (
              <TouchableOpacity accessibilityRole="button" style={[styles.dActBtn, busy ? { opacity: 0.6 } : null]} activeOpacity={0.85}
                onPress={busy ? undefined : () => onAction('convert')}>
                {busy === 'convert' ? <ActivityIndicator color="#065F46" /> : <Ionicons name="swap-horizontal" size={18} color="#065F46" />}
                <Text style={styles.dActBtnTxt}>Convertir en facture</Text>
              </TouchableOpacity>
            )}
          </>
        )}
        {(onEdit && (isQuote ? (inv.status !== 'signed' && inv.status !== 'converted' && inv.status !== 'cancelled') : inv.status === 'draft')) && (
          <TouchableOpacity accessibilityRole="button" style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onEdit(d, !!isQuote)}>
            <Ionicons name="create-outline" size={18} color={BRAND} />
            <Text style={styles.dWebBtnTxt}>{isQuote ? 'Modifier le devis' : 'Modifier la facture'}</Text>
          </TouchableOpacity>
        )}
        {!!inv.public_uuid && (
          <TouchableOpacity accessibilityRole="button" style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb((isQuote ? '/devis/' : '/facture/') + inv.public_uuid)}>
            <Text style={styles.dWebBtnTxt}>{isQuote ? 'Aperçu client du devis' : 'Aperçu client de la facture'}</Text>
            <Ionicons name="open-outline" size={18} color={BRAND} />
          </TouchableOpacity>
        )}
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
        accessibilityLabel={label}
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
          <TouchableOpacity accessibilityRole="button" key={o.value} style={[styles.segItem, on ? styles.segItemOn : null]} activeOpacity={0.8} onPress={() => onChange(o.value)}>
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
        <TouchableOpacity accessibilityRole="button" style={[styles.dPrimaryBtn, { marginTop: 0 }, submitting ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={submitting ? undefined : onSubmit}>
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
        <Switch value={f.send_email} onValueChange={set('send_email')} trackColor={{ true: BRAND }} accessibilityLabel="Envoyer par email" />
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

// Sélecteur générique en bottom-sheet (liste d'options {value,label,sub?})
function SheetPicker({ visible, title, options, selected, onPick, onClose }) {
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={styles.sheetBackdrop} onPress={onClose}>
        <Pressable style={[styles.sheet, { maxHeight: '75%' }]}>
          <View style={styles.sheetHandle} />
          <Text style={styles.sheetTitle}>{title}</Text>
          <ScrollView>
            {options.map((o) => (
              <TouchableOpacity accessibilityRole="button" key={String(o.value)} style={styles.qaRow} activeOpacity={0.7} onPress={() => { onPick(o.value); onClose(); }}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.qaLabel}>{o.label}</Text>
                  {!!o.sub && <Text style={styles.projPersonRole}>{o.sub}</Text>}
                </View>
                {selected === o.value && <Ionicons name="checkmark-circle" size={22} color={BRAND} />}
              </TouchableOpacity>
            ))}
          </ScrollView>
        </Pressable>
      </Pressable>
    </Modal>
  );
}

function todayISO() {
  const d = new Date();
  const p = (n) => (n < 10 ? '0' + n : '' + n);
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}

// ── Enregistrement d'un paiement de cotisation (natif) ────────────────
function CotisationPaymentForm({ onBack, onSubmit, submitting, error, campaigns, members, preCampaign }) {
  const camps = campaigns || [];
  const mbrs = members || [];
  const [campaignId, setCampaignId] = useState(preCampaign || (camps.length === 1 ? camps[0].id : 0));
  const [tierId, setTierId] = useState(0);
  const [adherentId, setAdherentId] = useState(0);
  const [f, setF] = useState({ payer_name: '', payer_email: '', amount: '', reference: '', notes: '', paid_at: todayISO() });
  const [method, setMethod] = useState('bank');
  const [status, setStatus] = useState('paid');
  const [campPicker, setCampPicker] = useState(false);
  const [memPicker, setMemPicker] = useState(false);
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));

  const selCamp = camps.find((c) => c.id === campaignId) || null;
  const selMem = mbrs.find((m) => m.id === adherentId) || null;

  // Les campagnes arrivent en asynchrone après l'ouverture du formulaire :
  // s'il n'y en a qu'une, on la sélectionne automatiquement.
  useEffect(() => {
    if (!campaignId && camps.length === 1) setCampaignId(camps[0].id);
  }, [camps.length]); // eslint-disable-line react-hooks/exhaustive-deps

  // Tarifs de la campagne choisie : sélectionner un tarif pré-remplit le montant.
  const tiers = (selCamp && selCamp.tiers) || [];
  useEffect(() => { setTierId(0); }, [campaignId]);
  const pickTier = (t) => {
    if (tierId === t.id) { setTierId(0); return; }
    setTierId(t.id);
    setF((s) => ({ ...s, amount: String(t.amount) }));
  };

  const pickMember = (id) => {
    setAdherentId(id);
    const m = mbrs.find((x) => x.id === id);
    if (m) setF((s) => ({ ...s, payer_name: m.name || s.payer_name, payer_email: m.email || s.payer_email }));
  };

  const submit = () => {
    if (!campaignId) { Alert.alert('Campagne', 'Sélectionnez la campagne de cotisation concernée.'); return; }
    onSubmit({
      campaign_id: campaignId,
      tier_id: tierId || 0,
      adherent_id: adherentId || 0,
      payer_name: f.payer_name.trim(),
      payer_email: f.payer_email.trim(),
      amount: f.amount,
      payment_method: method,
      status,
      paid_at: f.paid_at.trim(),
      reference: f.reference.trim(),
      notes: f.notes.trim(),
    });
  };

  return (
    <FormShell title="Nouveau paiement" onBack={onBack} onSubmit={submit} submitLabel="Enregistrer le paiement" submitting={submitting} error={error}>
      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Campagne *</Text>
        {camps.length > 0 && <TouchableOpacity accessibilityRole="button" onPress={() => setCampPicker(true)} activeOpacity={0.7}><Text style={styles.formLink}>Choisir</Text></TouchableOpacity>}
      </View>
      <TouchableOpacity accessibilityRole="button" style={[styles.projPickBtn, !camps.length && { opacity: 0.55 }]} activeOpacity={0.8} disabled={!camps.length} onPress={() => setCampPicker(true)}>
        <Ionicons name="pricetag-outline" size={18} color={BRAND} />
        <Text style={styles.projPickTxt}>{selCamp ? (selCamp.name + (selCamp.year ? ' · ' + selCamp.year : '')) : (camps.length ? 'Sélectionner une campagne' : 'Aucune campagne active')}</Text>
      </TouchableOpacity>

      <View style={[styles.formCardHead, { marginTop: 18 }]}><Text style={styles.formCardTitle}>Adhérent</Text>
        {mbrs.length > 0 && <TouchableOpacity accessibilityRole="button" onPress={() => setMemPicker(true)} activeOpacity={0.7}><Text style={styles.formLink}>{selMem ? 'Changer' : 'Choisir'}</Text></TouchableOpacity>}
      </View>
      {selMem ? (
        <View style={styles.pickedClient}>
          <View style={[styles.projPersonAv, { backgroundColor: selMem.color || BRAND }]}><Text style={styles.projPersonAvTxt}>{selMem.initials}</Text></View>
          <View style={{ flex: 1 }}><Text style={styles.pickedName}>{selMem.name}</Text>{!!selMem.email && <Text style={styles.projPersonRole}>{selMem.email}</Text>}</View>
          <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retirer" onPress={() => setAdherentId(0)} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="close-circle" size={22} color="#CBD5E1" /></TouchableOpacity>
        </View>
      ) : (
        <TouchableOpacity accessibilityRole="button" style={[styles.projPickBtn, !mbrs.length && { opacity: 0.55 }]} activeOpacity={0.8} disabled={!mbrs.length} onPress={() => setMemPicker(true)}>
          <Ionicons name="person-outline" size={18} color={BRAND} />
          <Text style={styles.projPickTxt}>{mbrs.length ? 'Rattacher à un adhérent (optionnel)' : 'Saisie libre du payeur'}</Text>
        </TouchableOpacity>
      )}

      {tiers.length > 0 && (
        <>
          <Text style={[styles.fLabel, { marginTop: 18 }]}>Tarif</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
            {tiers.map((t) => {
              const on = tierId === t.id;
              return (
                <TouchableOpacity accessibilityRole="button" key={t.id} style={[styles.tierChip, on && styles.tierChipOn]} activeOpacity={0.8} onPress={() => pickTier(t)}>
                  <Text style={[styles.tierChipTxt, on && styles.tierChipTxtOn]}>{t.name} · {fmtEuro2(t.amount)}</Text>
                </TouchableOpacity>
              );
            })}
          </View>
          <Text style={styles.fHint}>Choisir un tarif pré-remplit le montant. Facultatif.</Text>
        </>
      )}

      <View style={{ height: 18 }} />
      <Field label="Nom du payeur *" value={f.payer_name} onChangeText={set('payer_name')} autoCapitalize="words" />
      <Field label="Email du payeur" value={f.payer_email} onChangeText={set('payer_email')} keyboardType="email-address" autoCapitalize="none" />
      <Field label="Montant (€) *" value={f.amount} onChangeText={set('amount')} keyboardType="decimal-pad" placeholder="Ex : 25" />

      <Text style={styles.fLabel}>Méthode</Text>
      <Segmented options={[{ value: 'bank', label: 'Virement' }, { value: 'check', label: 'Chèque' }, { value: 'cash', label: 'Espèces' }, { value: 'other', label: 'Autre' }]} value={method} onChange={setMethod} />
      <View style={{ height: 14 }} />
      <Text style={styles.fLabel}>Statut</Text>
      <Segmented options={[{ value: 'paid', label: 'Payé' }, { value: 'pending', label: 'En attente' }]} value={status} onChange={setStatus} />
      <View style={{ height: 14 }} />
      <Field label="Date du paiement" value={f.paid_at} onChangeText={set('paid_at')} placeholder="AAAA-MM-JJ" autoCapitalize="none" hint="Format AAAA-MM-JJ." />
      <Field label="Référence (n° chèque, virement…)" value={f.reference} onChangeText={set('reference')} />
      <Field label="Notes" value={f.notes} onChangeText={set('notes')} multiline numberOfLines={2} style={[styles.fInput, { height: 74, textAlignVertical: 'top' }]} />

      <SheetPicker visible={campPicker} title="Campagne de cotisation" onClose={() => setCampPicker(false)} selected={campaignId}
        onPick={setCampaignId} options={camps.map((c) => ({ value: c.id, label: c.name, sub: (c.year ? c.year + ' · ' : '') + (c.active ? 'Active' : 'Clôturée') }))} />
      <SheetPicker visible={memPicker} title="Adhérent" onClose={() => setMemPicker(false)} selected={adherentId}
        onPick={pickMember} options={mbrs.map((m) => ({ value: m.id, label: m.name, sub: m.email || m.role_label }))} />
    </FormShell>
  );
}

// ── Nouvelle campagne de cotisation (natif) ───────────────────────────
function CampaignForm({ onBack, onSubmit, submitting, error }) {
  const [f, setF] = useState({ name: '', year: String(new Date().getFullYear()), description: '', opens_at: '', closes_at: '' });
  const [active, setActive] = useState(true);
  const [tiers, setTiers] = useState([{ name: '', amount: '' }]);
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));
  const setTier = (i, k, v) => setTiers((s) => s.map((t, j) => (j === i ? { ...t, [k]: v } : t)));
  const addTier = () => setTiers((s) => [...s, { name: '', amount: '' }]);
  const rmTier = (i) => setTiers((s) => s.filter((_, j) => j !== i));

  const submit = () => {
    if (!f.name.trim()) { Alert.alert('Nom', 'Donnez un nom à la campagne (ex : Adhésion 2026).'); return; }
    onSubmit({
      name: f.name.trim(),
      year: f.year.trim(),
      description: f.description.trim(),
      opens_at: f.opens_at.trim(),
      closes_at: f.closes_at.trim(),
      is_active: active,
      tiers: tiers.filter((t) => t.name.trim()).map((t) => ({ name: t.name.trim(), amount: t.amount })),
    });
  };

  return (
    <FormShell title="Nouvelle campagne" onBack={onBack} onSubmit={submit} submitLabel="Créer la campagne" submitting={submitting} error={error}>
      <Field label="Nom de la campagne *" value={f.name} onChangeText={set('name')} autoCapitalize="sentences" placeholder="Ex : Adhésion 2026" />
      <Field label="Année" value={f.year} onChangeText={set('year')} keyboardType="number-pad" />

      <View style={styles.switchRow}>
        <View style={{ flex: 1, paddingRight: 12 }}>
          <Text style={styles.switchLabel}>Campagne active</Text>
          <Text style={styles.switchSub}>Les adhérents peuvent y cotiser dès maintenant.</Text>
        </View>
        <Switch value={active} onValueChange={setActive} trackColor={{ true: BRAND }} accessibilityLabel="Campagne active" />
      </View>

      <View style={styles.formRow2}>
        <View style={{ flex: 1 }}><Field label="Ouverture" value={f.opens_at} onChangeText={set('opens_at')} placeholder="AAAA-MM-JJ" autoCapitalize="none" /></View>
        <View style={{ width: 12 }} />
        <View style={{ flex: 1 }}><Field label="Clôture" value={f.closes_at} onChangeText={set('closes_at')} placeholder="AAAA-MM-JJ" autoCapitalize="none" /></View>
      </View>

      <Text style={[styles.formCardTitle, { marginTop: 6 }]}>Tarifs proposés</Text>
      {tiers.map((t, i) => (
        <View key={i} style={styles.stepEditRow}>
          <TextInput style={[styles.fInput, { flex: 1.6 }]} value={t.name} onChangeText={(v) => setTier(i, 'name', v)}
            placeholder={'Tarif ' + (i + 1) + ' (ex : Adulte)'} placeholderTextColor="#B6C0CC" accessibilityLabel={'Nom du tarif ' + (i + 1)} />
          <TextInput style={[styles.fInput, { width: 92, marginLeft: 8 }]} value={t.amount} onChangeText={(v) => setTier(i, 'amount', v)}
            placeholder="€" placeholderTextColor="#B6C0CC" keyboardType="decimal-pad" accessibilityLabel={'Montant du tarif ' + (i + 1)} />
          {tiers.length > 1 && (
            <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retirer le tarif" onPress={() => rmTier(i)} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }} style={{ marginLeft: 8 }}>
              <Ionicons name="close-circle" size={22} color="#CBD5E1" />
            </TouchableOpacity>
          )}
        </View>
      ))}
      <TouchableOpacity accessibilityRole="button" style={[styles.addLineBtn, { marginTop: 4 }]} onPress={addTier} activeOpacity={0.8}>
        <Ionicons name="add" size={18} color={BRAND} /><Text style={styles.addLineTxt}>Ajouter un tarif</Text>
      </TouchableOpacity>

      <View style={{ height: 18 }} />
      <Field label="Description" value={f.description} onChangeText={set('description')} multiline numberOfLines={3}
        style={[styles.fInput, { height: 90, textAlignVertical: 'top' }]} hint="Ce que finance la cotisation, conditions…" />
    </FormShell>
  );
}

// ── Nouvel événement d'agenda (natif) ─────────────────────────────────
function EventForm({ onBack, onSubmit, submitting, error, projects }) {
  const projs = projects || [];
  const [f, setF] = useState({ title: '', location: '', description: '', start_date: todayISO(), start_time: '14:00', end_date: todayISO(), end_time: '16:00' });
  const [allDay, setAllDay] = useState(false);
  const [type, setType] = useState('meeting');
  const [visibility, setVisibility] = useState('organization');
  const [projectId, setProjectId] = useState(0);
  const [projPicker, setProjPicker] = useState(false);
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));
  const selProj = projs.find((p) => p.id === projectId) || null;

  const submit = () => {
    onSubmit({
      title: f.title.trim(),
      location: f.location.trim(),
      description: f.description.trim(),
      event_type: type,
      visibility,
      project_id: projectId || 0,
      is_all_day: allDay,
      start_date: f.start_date.trim(),
      start_time: f.start_time.trim(),
      end_date: f.end_date.trim(),
      end_time: f.end_time.trim(),
    });
  };

  return (
    <FormShell title="Nouvel événement" onBack={onBack} onSubmit={submit} submitLabel="Ajouter à l'agenda" submitting={submitting} error={error}>
      <Field label="Titre *" value={f.title} onChangeText={set('title')} autoCapitalize="sentences" placeholder="Ex : Réunion du bureau" />
      <Text style={styles.fLabel}>Type</Text>
      <Segmented options={[{ value: 'meeting', label: 'Réunion' }, { value: 'workshop', label: 'Atelier' }, { value: 'deadline', label: 'Échéance' }, { value: 'other', label: 'Autre' }]} value={type} onChange={setType} />

      <View style={[styles.switchRow, { marginTop: 16 }]}>
        <View style={{ flex: 1, paddingRight: 12 }}>
          <Text style={styles.switchLabel}>Journée entière</Text>
          <Text style={styles.switchSub}>Sans heure précise.</Text>
        </View>
        <Switch value={allDay} onValueChange={setAllDay} trackColor={{ true: BRAND }} accessibilityLabel="Journée entière" />
      </View>

      <View style={styles.formRow2}>
        <View style={{ flex: 1 }}><Field label="Date début *" value={f.start_date} onChangeText={set('start_date')} placeholder="AAAA-MM-JJ" autoCapitalize="none" /></View>
        {!allDay && <View style={{ width: 12 }} />}
        {!allDay && <View style={{ width: 110 }}><Field label="Heure" value={f.start_time} onChangeText={set('start_time')} placeholder="HH:MM" autoCapitalize="none" /></View>}
      </View>
      <View style={styles.formRow2}>
        <View style={{ flex: 1 }}><Field label="Date fin *" value={f.end_date} onChangeText={set('end_date')} placeholder="AAAA-MM-JJ" autoCapitalize="none" /></View>
        {!allDay && <View style={{ width: 12 }} />}
        {!allDay && <View style={{ width: 110 }}><Field label="Heure" value={f.end_time} onChangeText={set('end_time')} placeholder="HH:MM" autoCapitalize="none" /></View>}
      </View>

      <Field label="Lieu" value={f.location} onChangeText={set('location')} autoCapitalize="sentences" placeholder="Ex : Salle des fêtes" />

      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Projet lié</Text>
        {projs.length > 0 && <TouchableOpacity accessibilityRole="button" onPress={() => setProjPicker(true)} activeOpacity={0.7}><Text style={styles.formLink}>{selProj ? 'Changer' : 'Choisir'}</Text></TouchableOpacity>}
      </View>
      {selProj ? (
        <View style={styles.pickedClient}>
          <View style={{ flex: 1 }}><Text style={styles.pickedName}>{selProj.name}</Text>{!!selProj.folder && <Text style={styles.projPersonRole}>{selProj.folder}</Text>}</View>
          <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retirer" onPress={() => setProjectId(0)} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="close-circle" size={22} color="#CBD5E1" /></TouchableOpacity>
        </View>
      ) : (
        <TouchableOpacity accessibilityRole="button" style={[styles.projPickBtn, !projs.length && { opacity: 0.55 }]} activeOpacity={0.8} disabled={!projs.length} onPress={() => setProjPicker(true)}>
          <Ionicons name="folder-outline" size={18} color={BRAND} />
          <Text style={styles.projPickTxt}>{projs.length ? 'Associer à un projet (optionnel)' : 'Aucun projet'}</Text>
        </TouchableOpacity>
      )}

      <View style={{ height: 18 }} />
      <Text style={styles.fLabel}>Visibilité</Text>
      <Segmented options={[{ value: 'organization', label: 'Organisation' }, { value: 'public', label: 'Public' }]} value={visibility} onChange={setVisibility} />
      <View style={{ height: 14 }} />
      <Field label="Description" value={f.description} onChangeText={set('description')} multiline numberOfLines={3} style={[styles.fInput, { height: 90, textAlignVertical: 'top' }]} />

      <SheetPicker visible={projPicker} title="Projet lié" onClose={() => setProjPicker(false)} selected={projectId}
        onPick={setProjectId} options={projs.map((p) => ({ value: p.id, label: p.name, sub: p.folder }))} />
    </FormShell>
  );
}

// ── Nouvelle demande de subvention (natif) ────────────────────────────
const GRANT_FUNDERS = [
  { value: 'etat', label: 'État' }, { value: 'region', label: 'Région' }, { value: 'departement', label: 'Département' },
  { value: 'commune', label: 'Commune' }, { value: 'epci', label: 'EPCI (intercommunalité)' }, { value: 'caf', label: 'CAF' },
  { value: 'fondation', label: 'Fondation' }, { value: 'entreprise', label: 'Entreprise / mécénat' }, { value: 'europe', label: 'Europe' },
  { value: 'autre', label: 'Autre' },
];
function GrantForm({ onBack, onSubmit, submitting, error, projects }) {
  const projs = projects || [];
  const [f, setF] = useState({ name: '', funder: '', amount_requested: '', amount_granted: '', deadline_apply: '', description: '' });
  const [funderType, setFunderType] = useState('commune');
  const [status, setStatus] = useState('draft');
  const [projectId, setProjectId] = useState(0);
  const [ftPicker, setFtPicker] = useState(false);
  const [projPicker, setProjPicker] = useState(false);
  const [steps, setSteps] = useState(['Constituer le dossier', 'Déposer la demande', 'Relancer le financeur']);
  const setStep = (i, v) => setSteps((s) => s.map((x, j) => (j === i ? v : x)));
  const addStep = () => setSteps((s) => [...s, '']);
  const rmStep = (i) => setSteps((s) => s.filter((_, j) => j !== i));
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v }));
  const selProj = projs.find((p) => p.id === projectId) || null;
  const selFunder = GRANT_FUNDERS.find((x) => x.value === funderType) || GRANT_FUNDERS[GRANT_FUNDERS.length - 1];

  const submit = () => {
    onSubmit({
      name: f.name.trim(),
      funder: f.funder.trim(),
      funder_type: funderType,
      status,
      amount_requested: f.amount_requested,
      amount_granted: status === 'granted' ? f.amount_granted : '',
      deadline_apply: f.deadline_apply.trim(),
      project_id: projectId || 0,
      description: f.description.trim(),
      steps: steps.map((s) => s.trim()).filter(Boolean),
    });
  };

  return (
    <FormShell title="Nouvelle subvention" onBack={onBack} onSubmit={submit} submitLabel="Créer la demande" submitting={submitting} error={error}>
      <Field label="Nom de la demande *" value={f.name} onChangeText={set('name')} autoCapitalize="sentences" placeholder="Ex : Fonds de développement 2026" />
      <Field label="Financeur *" value={f.funder} onChangeText={set('funder')} autoCapitalize="words" placeholder="Ex : Mairie de Lyon" />

      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Type de financeur</Text>
        <TouchableOpacity accessibilityRole="button" onPress={() => setFtPicker(true)} activeOpacity={0.7}><Text style={styles.formLink}>Changer</Text></TouchableOpacity>
      </View>
      <TouchableOpacity accessibilityRole="button" style={styles.projPickBtn} activeOpacity={0.8} onPress={() => setFtPicker(true)}>
        <Ionicons name="business-outline" size={18} color={BRAND} />
        <Text style={styles.projPickTxt}>{selFunder.label}</Text>
      </TouchableOpacity>

      <View style={{ height: 18 }} />
      <Text style={styles.fLabel}>Statut</Text>
      <Segmented options={[{ value: 'draft', label: 'Brouillon' }, { value: 'submitted', label: 'Déposé' }, { value: 'granted', label: 'Accordé' }]} value={status} onChange={setStatus} />
      <View style={{ height: 14 }} />

      <Field label="Montant demandé (€)" value={f.amount_requested} onChangeText={set('amount_requested')} keyboardType="decimal-pad" />
      {status === 'granted' && <Field label="Montant accordé (€)" value={f.amount_granted} onChangeText={set('amount_granted')} keyboardType="decimal-pad" />}
      <Field label="Date limite de dépôt" value={f.deadline_apply} onChangeText={set('deadline_apply')} placeholder="AAAA-MM-JJ" autoCapitalize="none" hint="Format AAAA-MM-JJ (optionnel)." />

      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Projet lié</Text>
        {projs.length > 0 && <TouchableOpacity accessibilityRole="button" onPress={() => setProjPicker(true)} activeOpacity={0.7}><Text style={styles.formLink}>{selProj ? 'Changer' : 'Choisir'}</Text></TouchableOpacity>}
      </View>
      {selProj ? (
        <View style={styles.pickedClient}>
          <View style={{ flex: 1 }}><Text style={styles.pickedName}>{selProj.name}</Text>{!!selProj.folder && <Text style={styles.projPersonRole}>{selProj.folder}</Text>}</View>
          <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retirer" onPress={() => setProjectId(0)} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="close-circle" size={22} color="#CBD5E1" /></TouchableOpacity>
        </View>
      ) : (
        <TouchableOpacity accessibilityRole="button" style={[styles.projPickBtn, !projs.length && { opacity: 0.55 }]} activeOpacity={0.8} disabled={!projs.length} onPress={() => setProjPicker(true)}>
          <Ionicons name="folder-outline" size={18} color={BRAND} />
          <Text style={styles.projPickTxt}>{projs.length ? 'Associer à un projet (optionnel)' : 'Aucun projet'}</Text>
        </TouchableOpacity>
      )}

      <View style={{ height: 18 }} />
      <Text style={styles.formCardTitle}>Étapes du dossier</Text>
      {steps.map((s, i) => (
        <View key={i} style={styles.stepEditRow}>
          <Text style={styles.stepEditIdx}>{i + 1}</Text>
          <TextInput style={[styles.fInput, { flex: 1 }]} value={s} onChangeText={(v) => setStep(i, v)} placeholder={'Étape ' + (i + 1)} placeholderTextColor="#B6C0CC" accessibilityLabel={'Étape ' + (i + 1)} />
          {steps.length > 1 && (
            <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retirer l'étape" onPress={() => rmStep(i)} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }} style={{ marginLeft: 8 }}>
              <Ionicons name="close-circle" size={22} color="#CBD5E1" />
            </TouchableOpacity>
          )}
        </View>
      ))}
      <TouchableOpacity accessibilityRole="button" style={[styles.addLineBtn, { marginTop: 4 }]} onPress={addStep} activeOpacity={0.8}>
        <Ionicons name="add" size={18} color={BRAND} /><Text style={styles.addLineTxt}>Ajouter une étape</Text>
      </TouchableOpacity>

      <View style={{ height: 18 }} />
      <Field label="Description / objet" value={f.description} onChangeText={set('description')} multiline numberOfLines={3} style={[styles.fInput, { height: 90, textAlignVertical: 'top' }]} />

      <SheetPicker visible={ftPicker} title="Type de financeur" onClose={() => setFtPicker(false)} selected={funderType}
        onPick={setFunderType} options={GRANT_FUNDERS} />
      <SheetPicker visible={projPicker} title="Projet lié" onClose={() => setProjPicker(false)} selected={projectId}
        onPick={setProjectId} options={projs.map((p) => ({ value: p.id, label: p.name, sub: p.folder }))} />
    </FormShell>
  );
}

function BillingForm({ mode, edit, onBack, onSubmit, submitting, error, clients }) {
  const isQuote = mode === 'quote';
  const ei = edit && edit.invoice ? edit.invoice : null;
  const [client, setClient] = useState(ei
    ? { id: ei.client_id || 0, client_type: 'company', display_name: ei.client || '', email: ei.client_email || '', phone: '', address_city: '' }
    : { id: 0, client_type: 'company', display_name: '', email: '', phone: '', address_city: '' });
  const [lines, setLines] = useState(edit && edit.lines && edit.lines.length
    ? edit.lines.map((l) => ({ designation: l.label || '', quantity: String(l.qty ?? 1), unit_price_ht: String(l.unit ?? ''), vat_rate: l.vat != null ? String(l.vat) : '' }))
    : [{ designation: '', quantity: '1', unit_price_ht: '', vat_rate: '20' }]);
  const [status, setStatus] = useState(ei ? (ei.status === 'draft' ? 'draft' : (isQuote ? 'sent' : 'pending')) : (isQuote ? 'draft' : 'pending'));
  const [dueDays, setDueDays] = useState(ei ? String((isQuote ? ei.validity_days : ei.due_days) || 30) : '30');
  const [pickerOpen, setPickerOpen] = useState(false);

  const setC = (k) => (v) => setClient((s) => ({ ...s, [k]: v, id: 0 }));
  const setLine = (i, k, v) => setLines((s) => s.map((l, j) => (j === i ? { ...l, [k]: v } : l)));
  const addLine = () => setLines((s) => [...s, { designation: '', quantity: '1', unit_price_ht: '', vat_rate: '20' }]);
  const rmLine = (i) => setLines((s) => (s.length > 1 ? s.filter((_, j) => j !== i) : s));
  const pickClient = (c) => { setClient({ id: c.id, display_name: c.name, email: c.email, client_type: c.type || 'company', phone: '', address_city: c.city || '' }); setPickerOpen(false); };

  const t = computeTotals(lines);
  const submit = () => {
    const payload = { lines, status };
    if (isQuote) payload.validity_days = parseInt(dueDays, 10) || 30;
    else payload.due_days = parseInt(dueDays, 10) || 30;
    if (client.id > 0) payload.client_id = client.id;
    else payload.client = { client_type: client.client_type, display_name: client.display_name, email: client.email, phone: client.phone, address_city: client.address_city };
    onSubmit(payload);
  };

  return (
    <FormShell title={ei ? (isQuote ? 'Modifier le devis' : 'Modifier la facture') : (isQuote ? 'Nouveau devis' : 'Nouvelle facture')} onBack={onBack} onSubmit={submit}
      submitLabel={ei ? 'Enregistrer les modifications' : (isQuote ? 'Créer le devis' : 'Créer la facture')} submitting={submitting} error={error}>

      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Client</Text>
        {clients && clients.length > 0 && (
          <TouchableOpacity accessibilityRole="button" onPress={() => setPickerOpen(true)} activeOpacity={0.7}><Text style={styles.formLink}>Choisir un client</Text></TouchableOpacity>
        )}
      </View>
      {client.id > 0 ? (
        <View style={styles.pickedClient}>
          <View style={{ flex: 1 }}>
            <Text style={styles.pickedName}>{client.display_name}</Text>
            <Text style={styles.pickedMail}>{client.email}</Text>
          </View>
          <TouchableOpacity accessibilityRole="button" accessibilityLabel="Fermer" onPress={() => setClient({ id: 0, client_type: 'company', display_name: '', email: '', phone: '', address_city: '' })} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
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
              <TouchableOpacity accessibilityRole="button" accessibilityLabel="Supprimer" onPress={() => rmLine(i)} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}>
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
      <TouchableOpacity accessibilityRole="button" style={styles.addLineBtn} onPress={addLine} activeOpacity={0.8}>
        <Ionicons name="add" size={18} color={BRAND} /><Text style={styles.addLineTxt}>Ajouter une ligne</Text>
      </TouchableOpacity>

      <View style={styles.totalsBox}>
        <View style={styles.dCardRow}><Text style={styles.dMuted}>Total HT</Text><Text style={styles.dMuted}>{fmtEuro(t.ht)}</Text></View>
        <View style={[styles.dCardRow, { marginTop: 4 }]}><Text style={styles.dMuted}>TVA</Text><Text style={styles.dMuted}>{fmtEuro(t.vat)}</Text></View>
        <View style={[styles.dCardRow, { marginTop: 8 }]}><Text style={styles.dCardLabel}>Total TTC</Text><Text style={styles.dTotal}>{fmtEuro(t.ttc)}</Text></View>
      </View>

      <View style={{ marginTop: 16 }}><Field label={isQuote ? 'Validité (jours)' : 'Échéance (jours)'} value={dueDays} onChangeText={setDueDays} keyboardType="number-pad" /></View>

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
                <TouchableOpacity accessibilityRole="button" key={c.id} style={styles.qaRow} activeOpacity={0.7} onPress={() => pickClient(c)}>
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

const PROJ_REF_ROLES = ['admin', 'coordinator', 'referent'];
function ProjectForm({ onBack, onSubmit, submitting, error, folders, members }) {
  const [name, setName] = useState('');
  const [folderId, setFolderId] = useState(0);
  const [newFolder, setNewFolder] = useState('');
  const [pickerOpen, setPickerOpen] = useState(false);
  const [steps, setSteps] = useState(['Préparation', 'Organisation', 'Réalisation', 'Bilan']);
  const [description, setDescription] = useState('');
  const [budget, setBudget] = useState('');
  const [referentId, setReferentId] = useState(0);
  const [teamIds, setTeamIds] = useState([]);
  const [refPickerOpen, setRefPickerOpen] = useState(false);
  const [teamPickerOpen, setTeamPickerOpen] = useState(false);

  const allMembers = members || [];
  const referents = allMembers.filter((m) => PROJ_REF_ROLES.includes(m.role));
  const selReferent = allMembers.find((m) => m.id === referentId);
  const toggleTeam = (id) => setTeamIds((t) => (t.includes(id) ? t.filter((x) => x !== id) : [...t, id]));

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
      referent_id: referentId || 0,
      member_ids: teamIds,
    };
    if (folderId > 0) payload.folder_id = folderId; else payload.new_folder = newFolder;
    onSubmit(payload);
  };

  return (
    <FormShell title="Nouveau projet" onBack={onBack} onSubmit={submit} submitLabel="Créer le projet" submitting={submitting} error={error}>
      <Field label="Nom du projet *" value={name} onChangeText={setName} autoCapitalize="sentences" />

      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Dossier</Text>
        {folders && folders.length > 0 && (
          <TouchableOpacity accessibilityRole="button" onPress={() => setPickerOpen(true)} activeOpacity={0.7}><Text style={styles.formLink}>Choisir un dossier</Text></TouchableOpacity>
        )}
      </View>
      {selFolder ? (
        <View style={styles.pickedClient}>
          <View style={{ flex: 1 }}><Text style={styles.pickedName}>{selFolder.name}</Text></View>
          <TouchableOpacity accessibilityRole="button" accessibilityLabel="Fermer" onPress={() => setFolderId(0)} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="close-circle" size={22} color="#CBD5E1" /></TouchableOpacity>
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
            <TouchableOpacity accessibilityRole="button" accessibilityLabel="Fermer" onPress={() => rmStep(i)} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }} style={{ marginLeft: 8 }}>
              <Ionicons name="close-circle" size={22} color="#CBD5E1" />
            </TouchableOpacity>
          )}
        </View>
      ))}
      <TouchableOpacity accessibilityRole="button" style={[styles.addLineBtn, { marginTop: 4 }]} onPress={addStep} activeOpacity={0.8}>
        <Ionicons name="add" size={18} color={BRAND} /><Text style={styles.addLineTxt}>Ajouter une étape</Text>
      </TouchableOpacity>

      <View style={{ height: 18 }} />

      {/* Référent du projet */}
      <View style={styles.formCardHead}><Text style={styles.formCardTitle}>Référent du projet</Text>
        {referents.length > 0 && <TouchableOpacity accessibilityRole="button" onPress={() => setRefPickerOpen(true)} activeOpacity={0.7}><Text style={styles.formLink}>{selReferent ? 'Changer' : 'Choisir'}</Text></TouchableOpacity>}
      </View>
      {selReferent ? (
        <View style={styles.pickedClient}>
          <View style={[styles.projPersonAv, { backgroundColor: selReferent.color || BRAND }]}><Text style={styles.projPersonAvTxt}>{selReferent.initials}</Text></View>
          <View style={{ flex: 1 }}><Text style={styles.pickedName}>{selReferent.name}</Text><Text style={styles.projPersonRole}>{selReferent.role_label || 'Référent'}</Text></View>
          <TouchableOpacity accessibilityRole="button" accessibilityLabel="Fermer" onPress={() => setReferentId(0)} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="close-circle" size={22} color="#CBD5E1" /></TouchableOpacity>
        </View>
      ) : (
        <TouchableOpacity accessibilityRole="button" style={[styles.projPickBtn, !referents.length && { opacity: 0.55 }]} activeOpacity={0.8} disabled={!referents.length} onPress={() => setRefPickerOpen(true)}>
          <Ionicons name="star-outline" size={18} color={BRAND} />
          <Text style={styles.projPickTxt}>{referents.length ? 'Désigner un référent' : 'Aucun référent disponible'}</Text>
        </TouchableOpacity>
      )}

      {/* Équipe du projet */}
      <View style={[styles.formCardHead, { marginTop: 20 }]}><Text style={styles.formCardTitle}>Équipe du projet</Text>
        {allMembers.length > 0 && <TouchableOpacity accessibilityRole="button" onPress={() => setTeamPickerOpen(true)} activeOpacity={0.7}><Text style={styles.formLink}>Ajouter</Text></TouchableOpacity>}
      </View>
      {teamIds.length === 0 ? (
        <TouchableOpacity accessibilityRole="button" style={[styles.projPickBtn, !allMembers.length && { opacity: 0.55 }]} activeOpacity={0.8} disabled={!allMembers.length} onPress={() => setTeamPickerOpen(true)}>
          <Ionicons name="people-outline" size={18} color={BRAND} />
          <Text style={styles.projPickTxt}>{allMembers.length ? 'Ajouter des participants' : 'Aucun membre disponible'}</Text>
        </TouchableOpacity>
      ) : (
        <View style={styles.projTeamWrap}>
          {teamIds.map((id) => {
            const m = allMembers.find((x) => x.id === id);
            if (!m) return null;
            return (
              <TouchableOpacity accessibilityRole="button" key={id} style={styles.projTeamChip} activeOpacity={0.8} onPress={() => toggleTeam(id)}>
                <View style={[styles.projTeamAv, { backgroundColor: m.color || BRAND }]}><Text style={styles.projTeamAvTxt}>{m.initials}</Text></View>
                <Text style={styles.projTeamName}>{m.name}</Text>
                <Ionicons name="close" size={15} color="#94A3B8" />
              </TouchableOpacity>
            );
          })}
        </View>
      )}

      <View style={{ height: 18 }} />
      <Field label="Description" value={description} onChangeText={setDescription} multiline numberOfLines={3} style={[styles.fInput, { height: 90, textAlignVertical: 'top' }]} />
      <Field label="Budget prévu (€)" value={budget} onChangeText={setBudget} keyboardType="decimal-pad" />

      {/* Picker référent */}
      <Modal visible={refPickerOpen} transparent animationType="slide" onRequestClose={() => setRefPickerOpen(false)}>
        <Pressable style={styles.sheetBackdrop} onPress={() => setRefPickerOpen(false)}>
          <Pressable style={[styles.sheet, { maxHeight: '70%' }]}>
            <View style={styles.sheetHandle} />
            <Text style={styles.sheetTitle}>Référent du projet</Text>
            <ScrollView>
              {referents.map((m) => (
                <TouchableOpacity accessibilityRole="button" key={m.id} style={styles.qaRow} activeOpacity={0.7} onPress={() => { setReferentId(m.id); setRefPickerOpen(false); }}>
                  <View style={[styles.projPersonAv, { backgroundColor: m.color || BRAND, marginRight: 12 }]}><Text style={styles.projPersonAvTxt}>{m.initials}</Text></View>
                  <View style={{ flex: 1 }}><Text style={styles.qaLabel}>{m.name}</Text><Text style={styles.projPersonRole}>{m.role_label || m.role}</Text></View>
                  {referentId === m.id && <Ionicons name="checkmark-circle" size={22} color={BRAND} />}
                </TouchableOpacity>
              ))}
            </ScrollView>
          </Pressable>
        </Pressable>
      </Modal>

      {/* Picker équipe (multi) */}
      <Modal visible={teamPickerOpen} transparent animationType="slide" onRequestClose={() => setTeamPickerOpen(false)}>
        <Pressable style={styles.sheetBackdrop} onPress={() => setTeamPickerOpen(false)}>
          <Pressable style={[styles.sheet, { maxHeight: '75%' }]}>
            <View style={styles.sheetHandle} />
            <Text style={styles.sheetTitle}>Équipe du projet</Text>
            <ScrollView>
              {allMembers.map((m) => {
                const on = teamIds.includes(m.id);
                return (
                  <TouchableOpacity accessibilityRole="button" key={m.id} style={styles.qaRow} activeOpacity={0.7} onPress={() => toggleTeam(m.id)}>
                    <View style={[styles.projPersonAv, { backgroundColor: m.color || BRAND, marginRight: 12 }]}><Text style={styles.projPersonAvTxt}>{m.initials}</Text></View>
                    <View style={{ flex: 1 }}><Text style={styles.qaLabel}>{m.name}</Text><Text style={styles.projPersonRole}>{m.role_label || m.role}</Text></View>
                    <Ionicons name={on ? 'checkbox' : 'square-outline'} size={22} color={on ? BRAND : '#CBD5E1'} />
                  </TouchableOpacity>
                );
              })}
            </ScrollView>
            <TouchableOpacity accessibilityRole="button" style={styles.projTeamDone} activeOpacity={0.9} onPress={() => setTeamPickerOpen(false)}>
              <Text style={styles.projTeamDoneTxt}>Valider ({teamIds.length})</Text>
            </TouchableOpacity>
          </Pressable>
        </Pressable>
      </Modal>

      <Modal visible={pickerOpen} transparent animationType="slide" onRequestClose={() => setPickerOpen(false)}>
        <Pressable style={styles.sheetBackdrop} onPress={() => setPickerOpen(false)}>
          <Pressable style={[styles.sheet, { maxHeight: '70%' }]}>
            <View style={styles.sheetHandle} />
            <Text style={styles.sheetTitle}>Dossiers</Text>
            <ScrollView>
              {(folders || []).map((fo) => (
                <TouchableOpacity accessibilityRole="button" key={fo.id} style={styles.qaRow} activeOpacity={0.7} onPress={() => { setFolderId(fo.id); setPickerOpen(false); }}>
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
      <TouchableOpacity accessibilityRole="button" style={styles.selectRow} activeOpacity={0.8} onPress={() => setPickerOpen(true)}>
        <Text style={[styles.selectVal, !selProject ? { color: '#B6C0CC' } : null]}>{selProject ? selProject.name : 'Choisir un projet…'}</Text>
        <Ionicons name="chevron-down" size={18} color={MUTE} />
      </TouchableOpacity>

      <TouchableOpacity accessibilityRole="button" style={[styles.scanBtn, (!projectId || scanning) ? { opacity: 0.5 } : null]} activeOpacity={0.85}
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
          <TouchableOpacity accessibilityRole="button" key={c} style={[styles.catChip, category === c ? styles.catChipOn : null]} activeOpacity={0.8} onPress={() => setCategory(c)}>
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
                <TouchableOpacity accessibilityRole="button" key={p.id} style={styles.qaRow} activeOpacity={0.7} onPress={() => { setProjectId(p.id); setPickerOpen(false); }}>
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
function NativeAgenda({ data, loading, onRefresh, onOpen, onBack, onNew }) {
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
      <DetailHeader title="Agenda" onBack={onBack} onAction={onNew} actionIcon="add" />
      {!events ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : events.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="calendar-outline" size={44} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun événement à venir</Text>
          <TouchableOpacity accessibilityRole="button" style={[styles.listNewBtn, { marginTop: 16 }]} activeOpacity={0.85} onPress={onNew}>
            <Ionicons name="add-circle" size={19} color="#fff" /><Text style={styles.listNewTxt}>Nouvel événement</Text>
          </TouchableOpacity>
        </View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <TouchableOpacity accessibilityRole="button" style={styles.listNewBtn} activeOpacity={0.85} onPress={onNew}>
            <Ionicons name="add-circle" size={19} color="#fff" /><Text style={styles.listNewTxt}>Nouvel événement</Text>
          </TouchableOpacity>
          {groups.map((g) => (
            <View key={g.key} style={{ marginBottom: 18 }}>
              <Text style={styles.agDay}>{g.label}</Text>
              {g.items.map((e) => (
                <TouchableOpacity accessibilityRole="button" key={e.id} style={styles.agCard} activeOpacity={0.85} onPress={() => onOpen(e.id)}>
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
  if (data && data.allowed === false) return <GatedScreen title="Mes factures" message={data.message} onBack={onBack} />;
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
              <TouchableOpacity accessibilityRole="button" key={i} style={styles.invCard} activeOpacity={inv.pdf ? 0.85 : 1} onPress={() => inv.pdf && onWeb(inv.pdf)}>
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
            <TouchableOpacity accessibilityRole="button" key={c.id} style={styles.chanCard} activeOpacity={0.85} onPress={() => onOpen(c)}>
              <View style={[styles.chanIcon, { backgroundColor: (c.color || BRAND) + '22' }]}>
                <Ionicons name={CHAN_ICON[c.type] || 'chatbubbles'} size={20} color={c.color || BRAND} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={[styles.chanName, c.unread ? { fontWeight: '800' } : null]} numberOfLines={1}>{c.name}</Text>
                <Text style={[styles.chanSub, c.unread ? { color: BRAND, fontWeight: '600' } : null]}>{c.unread ? 'Nouveaux messages' : (c.count + ' message' + (c.count > 1 ? 's' : ''))}</Text>
              </View>
              {c.unread ? <View style={styles.chanNewPill}><Text style={styles.chanNewTxt}>Nouveau</Text></View> : null}
              <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
            </TouchableOpacity>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

function NativeChat({ channel, data, loading, sending, onBack, onSend, onRefresh, sendResult }) {
  const [text, setText] = useState('');
  const msgs = data ? (data.messages || []) : null;
  const scRef = useRef(null);
  const submit = () => { const t = text.trim(); if (!t || sending) return; onSend(channel.id, t); };
  // On ne vide le champ QUE si l'envoi a réussi : en cas d'échec (canal en lecture
  // seule, réseau…) l'utilisateur garde son message et peut réessayer.
  useEffect(() => { if (sendResult && sendResult.ok) setText(''); }, [sendResult]);
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
        <TouchableOpacity accessibilityRole="button" accessibilityLabel="Envoyer" style={[styles.composerBtn, (!text.trim() || sending) ? { opacity: 0.5 } : null]} onPress={submit} activeOpacity={0.85}>
          {sending ? <ActivityIndicator color="#fff" size="small" /> : <Ionicons name="send" size={18} color="#fff" />}
        </TouchableOpacity>
      </View>
    </KeyboardAvoidingView>
  );
}

/* ================================================================== */
/*  MENU « PLUS » (hub natif)                                           */
/* ================================================================== */
// admin: true  → visible uniquement pour les admins (mêmes autorisations que le site),
//                affiché avec un petit tag « Admin » pour qu'ils sachent qu'eux seuls le voient.
const MORE_GROUPS = [
  {
    title: 'Association',
    items: [
      { label: 'Adhérents', icon: 'people', nav: { screen: 'members' }, assoOnly: true },
      { label: 'Agenda', icon: 'calendar', nav: { screen: 'agenda' } },
      { label: 'Cotisations', icon: 'card', nav: { screen: 'cotisations' }, manage: true, assoOnly: true },
      { label: 'Subventions', icon: 'cash', nav: { screen: 'subventions' }, manage: true, assoOnly: true },
      { label: 'Assemblées', icon: 'clipboard', nav: { screen: 'assemblies' }, admin: true, assoOnly: true },
      { label: 'Émargement', icon: 'checkbox', nav: { screen: 'attendance' }, manage: true, assoOnly: true },
    ],
  },
  {
    title: 'Finances',
    admin: true,
    items: [
      { label: 'Factures', icon: 'receipt', nav: { tab: 'factures' }, admin: true },
      { label: 'Devis', icon: 'document-text', nav: { screen: 'devis' }, admin: true },
      { label: 'Clients', icon: 'briefcase', nav: { screen: 'clients' }, admin: true },
      { label: 'Statistiques', icon: 'stats-chart', nav: { screen: 'stats' }, admin: true },
    ],
  },
  {
    title: 'Communication',
    items: [
      { label: 'Copilote IA', icon: 'sparkles', nav: { web: '/mon-asso-copilote' }, admin: true },
      { label: 'Messages', icon: 'chatbubbles', nav: { screen: 'messages' }, badge: 'msg' },
      { label: 'Notifications', icon: 'notifications', nav: { screen: 'notifications' }, badge: 'notif' },
      { label: 'Communication', icon: 'mail', nav: { screen: 'broadcasts' }, admin: true },
      { label: 'Coach IA', icon: 'sparkles', nav: { screen: 'coach' }, admin: true },
    ],
  },
  {
    title: 'Compte',
    items: [
      { label: 'Paramètres', icon: 'settings', nav: { screen: 'settings' } },
      { label: 'Support', icon: 'help-buoy', nav: { screen: 'tickets' }, badge: 'support' },
    ],
  },
];

const FOUNDER_SHORTCUTS = [
  { label: 'Associations', icon: 'business', fk: 'associations' },
  { label: 'Abonnements', icon: 'card', fk: 'billing' },
  { label: 'Support', icon: 'chatbubbles', fk: 'support' },
  { label: 'Statistiques', icon: 'stats-chart', fk: 'stats' },
  { label: 'Blog SEO', icon: 'newspaper', fk: 'blog' },
  { label: 'Pilotage', icon: 'grid', fk: 'cockpit' },
];

function NativeMore({ orgName, initials, logo, isFounder, isAdmin, canManage, isTpe, counts, onNav, onLogout }) {
  const cnt = counts || {};
  return (
    <View style={styles.detailWrap}>
      <View style={styles.moreHeader}>
        <View style={styles.moreAvatar}>
          {logo ? <Image source={{ uri: logo }} style={styles.moreAvatarImg} resizeMode="contain" /> : <Text style={styles.moreAvatarTxt}>{initials || '·'}</Text>}
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.moreOrg} numberOfLines={1}>{orgName || 'Mon organisation'}</Text>
          <Text style={styles.moreSub}>Tous vos outils</Text>
        </View>
      </View>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 30 }} showsVerticalScrollIndicator={false}>
        {isFounder && (
          <View style={styles.founderBlock}>
            <TouchableOpacity accessibilityRole="button" style={styles.founderBanner} activeOpacity={0.9} onPress={() => onNav({ screen: 'founder' })}>
              <View style={styles.founderStar}><Ionicons name="star" size={20} color="#78350F" /></View>
              <View style={{ flex: 1 }}>
                <Text style={styles.founderTitle}>Espace Fondateur</Text>
                <Text style={styles.founderSub}>Piloter toute la plateforme Assokit</Text>
              </View>
              <Ionicons name="arrow-forward" size={18} color="#FCD34D" />
            </TouchableOpacity>
            <View style={styles.founderGrid}>
              {FOUNDER_SHORTCUTS.map((it) => (
                <TouchableOpacity accessibilityRole="button" key={it.label} style={styles.founderItem} activeOpacity={0.8} onPress={() => onNav({ founder: it.fk })}>
                  <View style={styles.founderItemIcon}><Ionicons name={it.icon} size={20} color="#B45309" /></View>
                  <Text style={styles.founderItemTxt} numberOfLines={1}>{it.label}</Text>
                </TouchableOpacity>
              ))}
            </View>
          </View>
        )}
        {MORE_GROUPS.map((g) => {
          // Masque les fonctions propres aux associations pour un profil TPE.
          // admin → admins seuls ; manage → admins ET coordinateurs (mêmes règles que le serveur)
          const items = g.items.filter((it) => (!it.admin || isAdmin) && (!it.manage || isAdmin || canManage) && (!it.assoOnly || !isTpe));
          if (items.length === 0) return null;
          return (
          <View key={g.title} style={{ marginBottom: 20 }}>
            <View style={styles.moreGroupHead}>
              <Text style={styles.moreGroupTitle}>{g.title}</Text>
              {g.admin && isAdmin ? <View style={styles.adminTag}><Ionicons name="shield-checkmark" size={10} color="#B45309" /><Text style={styles.adminTagTxt}>Admin</Text></View> : null}
            </View>
            <View style={styles.moreGrid}>
              {items.map((it) => {
                const n = it.badge ? (cnt[it.badge] || 0) : 0;
                return (
                  <TouchableOpacity accessibilityRole="button" key={it.label} style={styles.moreItem} activeOpacity={0.8} onPress={() => onNav(it.nav)}>
                    {it.admin && isAdmin && !g.admin ? <View style={styles.adminTagCorner}><Text style={styles.adminTagTxt}>Admin</Text></View> : null}
                    <View style={styles.moreItemIcon}>
                      <Ionicons name={it.icon} size={22} color={BRAND} />
                      {n > 0 && <View style={styles.moreBadge}><Text style={styles.moreBadgeTxt}>{n > 99 ? '99+' : n}</Text></View>}
                    </View>
                    <Text style={styles.moreItemTxt} numberOfLines={1}>{it.label}</Text>
                  </TouchableOpacity>
                );
              })}
            </View>
          </View>
          );
        })}
        <TouchableOpacity accessibilityRole="button" style={styles.logoutBtn} activeOpacity={0.85} onPress={onLogout}>
          <Ionicons name="log-out-outline" size={19} color="#DC2626" />
          <Text style={styles.logoutTxt}>Se déconnecter</Text>
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

/* ================================================================== */
/*  ESPACE FONDATEUR (cockpit natif)                                   */
/* ================================================================== */
const FC_MINI = [
  { key: 'orgs', color: '#059669', bg: '#ECFDF5', icon: 'business', label: 'Associations', fmt: (k) => String(k.orgs_total ?? 0), sub: (k) => (k.orgs_active ?? 0) + ' actives · ' + (k.orgs_trial ?? 0) + ' essai' },
  { key: 'users', color: '#7C3AED', bg: '#F5F3FF', icon: 'people', label: 'Utilisateurs', fmt: (k) => String(k.users ?? 0), sub: () => 'tous rôles' },
  { key: 'ia', color: '#2563EB', bg: '#EFF6FF', icon: 'sparkles', label: 'Générations IA', fmt: (k) => String(k.ia_nb ?? 0), sub: (k) => fmtEuro(k.ia_cost) + ' dépensés' },
];

const FC_TILES = [
  { key: 'associations', icon: 'business', color: '#059669', bg: '#ECFDF5', title: 'Associations', desc: 'Gérer · valider · suspendre' },
  { key: 'billing', icon: 'card', color: '#2563EB', bg: '#EFF6FF', title: 'Abonnements', desc: 'MRR · impayés · relances' },
  { key: 'plans', icon: 'pricetags', color: '#0D9488', bg: '#F0FDFA', title: 'Plans tarifaires', desc: 'Prix · quotas · options' },
  { key: 'support', icon: 'chatbubbles', color: '#7C3AED', bg: '#F5F3FF', title: 'Support', desc: 'Tickets & messages' },
  { key: 'contacts', icon: 'mail', color: '#DB2777', bg: '#FDF2F8', title: 'Contacts', desc: 'Demandes du site · prospects' },
  { key: 'stats', icon: 'stats-chart', color: '#0891B2', bg: '#ECFEFF', title: 'Statistiques', desc: 'Croissance · emails · SMS' },
  { key: 'projects', icon: 'folder-open', color: '#4F46E5', bg: '#EEF2FF', title: 'Projets', desc: 'Toutes les orgs' },
  { key: 'activity', icon: 'pulse', color: '#0284C7', bg: '#F0F9FF', title: 'Activité', desc: 'Journal de la plateforme' },
  { key: 'blog', icon: 'newspaper', color: '#D97706', bg: '#FFFBEB', title: 'Blog SEO', desc: 'Générer & programmer' },
  { key: 'annuaire', icon: 'map', color: '#0369A1', bg: '#F0F9FF', title: 'Annuaire France', desc: 'Assos par région · dept · catégorie' },
  { key: 'prospects', icon: 'rocket', color: '#DB2777', bg: '#FDF2F8', title: 'Prospection', desc: 'Emailing ciblé · relances' },
  { key: 'settings', icon: 'business', color: '#475569', bg: '#F1F5F9', title: 'Société', desc: 'Infos légales · TVA · IBAN' },
];

function NativeFounder({ data, loading, onRefresh, onBack, hasAsso, onGotoAsso, onLogout, onTile, onNotifs, notifCount }) {
  const k = (data && data.kpis) || {};
  const sig = (data && data.signals) || {};
  const orgs = (data && data.orgs) || [];
  const mo = (data && data.month) || {};
  const signals = [];
  if (sig.pending > 0) signals.push({ tone: '#B45309', bg: '#FEF3C7', icon: 'construct', t: sig.pending + ' validation' + (sig.pending > 1 ? 's' : '') + ' en attente', filter: 'pending' });
  if (sig.unpaid_nb > 0) signals.push({ tone: '#B91C1C', bg: '#FEE2E2', icon: 'cash', t: sig.unpaid_nb + ' impayé' + (sig.unpaid_nb > 1 ? 's' : '') + ' · ' + fmtEuro(sig.unpaid_total), filter: 'unpaid' });
  if (sig.expiring > 0) signals.push({ tone: '#6D28D9', bg: '#EDE9FE', icon: 'time', t: sig.expiring + ' essai' + (sig.expiring > 1 ? 's' : '') + ' expire' + (sig.expiring > 1 ? 'nt' : '') + ' sous 7j', filter: 'trial' });
  const ctcUnread = sig.contacts_unread || 0;
  if (ctcUnread > 0) signals.push({ tone: '#BE185D', bg: '#FCE7F3', icon: 'mail-unread', t: ctcUnread + ' nouveau' + (ctcUnread > 1 ? 'x' : '') + ' message' + (ctcUnread > 1 ? 's' : '') + ' de contact', screen: 'contacts', filter: 'all' });

  const active = k.orgs_active ?? 0, trial = k.orgs_trial ?? 0;
  const tot = Math.max(1, active + trial);

  return (
    <View style={[styles.fcWrap, Platform.OS === 'ios' && { top: -Constants.statusBarHeight }]}>
      <StatusBar barStyle="light-content" />
      <ScrollView style={styles.fcScroll} contentContainerStyle={{ paddingBottom: 34 }} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor="#059669" colors={['#059669']} />}>

        {/* Header premium vert + halo doré fondateur — le vert remonte jusqu'en haut (notch) */}
        <View style={styles.fcHeaderWrap}>
          <LinearGradient colors={['#12D3A0', '#0AA57E', '#0E7490']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}
            style={[styles.fcHeader, Platform.OS === 'ios' && { paddingTop: Constants.statusBarHeight + 10 }]}>
            <View style={styles.fcOrbGold} />
            <View style={styles.fcOrbDark} />
            <View>
              <View style={styles.fcTopRow}>
                <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retour" style={styles.fcBack} activeOpacity={0.8} onPress={onBack}>
                  <Ionicons name="chevron-back" size={19} color="#EAF2EE" />
                </TouchableOpacity>
                <Text style={styles.fcTopTitle}>Espace Fondateur</Text>
                {hasAsso ? (
                  <TouchableOpacity accessibilityRole="button" style={styles.fcAssoBtn} activeOpacity={0.85} onPress={onGotoAsso}>
                    <Ionicons name="business" size={13} color="#3A2A08" />
                    <Text style={styles.fcAssoTxt}>Mode Asso</Text>
                    <Ionicons name="arrow-forward" size={12} color="#3A2A08" />
                  </TouchableOpacity>
                ) : <View style={{ width: 40 }} />}
              </View>
              <View style={styles.fcHelloRow}>
                <Text style={styles.fcHello}>Bienvenue {data && data.first_name ? data.first_name : ''} 👋</Text>
                <View style={styles.fcSeal}><Text style={styles.fcSealTxt}><Ionicons name="construct" size={10} color="#3A2A08" /> FONDATEUR</Text></View>
              </View>
              <View style={styles.fcSubRow}>
                <View style={styles.fcLive} />
                <Text style={styles.fcSub}>Pilotage de la plateforme Assokit · en direct</Text>
              </View>
            </View>
          </LinearGradient>
        </View>

        {!data ? (
          <View style={[styles.homeLoader, { flex: 1, paddingTop: 50 }]}><ActivityIndicator size="large" color="#059669" /></View>
        ) : (
          <View style={{ paddingHorizontal: 16 }}>
            {/* MRR vedette — verre liquide */}
            <TouchableOpacity accessibilityRole="button" activeOpacity={0.9} onPress={() => onTile('billing', 'all')} style={styles.fcSpotShadow}>
              <BlurView intensity={40} tint="light" style={styles.fcSpot}>
                <View style={styles.fcSpotGloss} />
                <View style={styles.fcSpotTop}>
                  <View style={styles.fcSpotIc}><Ionicons name="cash" size={16} color="#B45309" /></View>
                  <Text style={styles.fcSpotLb}>Revenu mensuel récurrent</Text>
                  <View style={styles.fcSpotChip}><Text style={styles.fcSpotChipTxt}>{active} payantes</Text></View>
                </View>
                <Text style={styles.fcSpotVal}>{fmtEuro(k.mrr)}<Text style={styles.fcSpotUnit}> / mois</Text></Text>
                <View style={styles.fcDistTrack}>
                  <View style={[styles.fcDistFill, { flex: active, backgroundColor: '#059669' }]} />
                  <View style={[styles.fcDistFill, { flex: trial, backgroundColor: '#FBBF24' }]} />
                  <View style={{ flex: Math.max(0, tot - active - trial) }} />
                </View>
                <View style={styles.fcDistMeta}>
                  <Text style={styles.fcDistTxt}><Text style={{ color: '#059669', fontWeight: '800' }}>■</Text> {active} actives</Text>
                  <Text style={styles.fcDistTxt}><Text style={{ color: '#FBBF24', fontWeight: '800' }}>■</Text> {trial} essai{trial > 1 ? 's' : ''}</Text>
                </View>
              </BlurView>
            </TouchableOpacity>

            {/* Notifications + Créer */}
            <View style={styles.fcActions}>
              <TouchableOpacity accessibilityRole="button" style={styles.fcNotif} activeOpacity={0.85} onPress={onNotifs}>
                <Ionicons name="notifications" size={16} color="#334155" />
                <Text style={styles.fcNotifTxt}>Notifications</Text>
                {notifCount > 0 ? <View style={styles.fcNotifPill}><Text style={styles.fcNotifPillTxt}>{notifCount > 99 ? '99+' : notifCount}</Text></View> : null}
              </TouchableOpacity>
              <TouchableOpacity accessibilityRole="button" style={styles.fcCreate} activeOpacity={0.9} onPress={() => onTile('create')}>
                <Ionicons name="add" size={17} color="#3A2A08" />
                <Text style={styles.fcCreateTxt}>Créer une association</Text>
              </TouchableOpacity>
            </View>

            {/* mini KPIs */}
            <View style={styles.fcMiniRow}>
              {FC_MINI.map((m) => (
                <View key={m.key} style={styles.fcMini}>
                  <View style={[styles.fcMiniIc, { backgroundColor: m.bg }]}><Ionicons name={m.icon} size={15} color={m.color} /></View>
                  <Text style={styles.fcMiniVal}>{m.fmt(k)}</Text>
                  <Text style={styles.fcMiniLb}>{m.label}</Text>
                  <Text style={styles.fcMiniSub} numberOfLines={1}>{m.sub(k)}</Text>
                </View>
              ))}
            </View>

            {/* Ce mois-ci */}
            <View style={styles.fcMonthCard}>
              <Text style={styles.fcMonthTitle}><Ionicons name="calendar-outline" size={13} color="#334155" /> Ce mois-ci</Text>
              <View style={styles.fcMonthRow}>
                <View style={styles.fcMonthItem}><Text style={[styles.fcMonthVal, { color: '#059669' }]}>+{mo.new_orgs ?? 0}</Text><Text style={styles.fcMonthLb}>nouvelles assos</Text></View>
                <View style={styles.fcMonthSep} />
                <View style={styles.fcMonthItem}><Text style={[styles.fcMonthVal, { color: '#2563EB' }]}>+{mo.new_users ?? 0}</Text><Text style={styles.fcMonthLb}>utilisateurs</Text></View>
                <View style={styles.fcMonthSep} />
                <View style={styles.fcMonthItem}><Text style={[styles.fcMonthVal, { color: '#B45309' }]}>{fmtEuro(mo.ca_paid)}</Text><Text style={styles.fcMonthLb}>encaissé</Text></View>
              </View>
            </View>

            {/* Signaux à traiter */}
            {signals.length > 0 && (
              <>
                <View style={styles.fcSecRow}>
                  <Text style={styles.fcSec}>À TRAITER</Text>
                  <View style={styles.fcSecN}><Text style={styles.fcSecNTxt}>{signals.length}</Text></View>
                </View>
                {signals.map((s, i) => (
                  <TouchableOpacity accessibilityRole="button" key={i} style={styles.fcSignal} activeOpacity={0.85} onPress={() => onTile(s.screen || 'associations', s.filter)}>
                    <View style={[styles.fcSignalIc, { backgroundColor: s.bg }]}><Ionicons name={s.icon} size={16} color={s.tone} /></View>
                    <Text style={styles.fcSignalTxt} numberOfLines={2}>{s.t}</Text>
                    <Ionicons name="chevron-forward" size={16} color="#CBD5E1" />
                  </TouchableOpacity>
                ))}
              </>
            )}

            {/* Centre de contrôle */}
            <Text style={[styles.fcSec, { marginTop: 22, marginBottom: 12 }]}>CENTRE DE CONTRÔLE</Text>
            <View style={styles.fcTiles}>
              {FC_TILES.map((t) => {
                const tileBadge = t.key === 'contacts' ? ctcUnread : 0;
                return (
                <TouchableOpacity accessibilityRole="button" key={t.key} style={styles.fcTile} activeOpacity={0.88} onPress={() => onTile(t.key, t.key === 'billing' ? 'unpaid' : 'all')}>
                  <View style={[styles.fcTileIc, { backgroundColor: t.bg }]}><Ionicons name={t.icon} size={21} color={t.color} /></View>
                  {tileBadge > 0 ? <View style={styles.fcTileBadge}><Text style={styles.fcTileBadgeTxt}>{tileBadge > 99 ? '99+' : tileBadge}</Text></View> : null}
                  <Text style={styles.fcTileTitle}>{t.title}</Text>
                  <Text style={styles.fcTileDesc} numberOfLines={1}>{t.desc}</Text>
                </TouchableOpacity>
                );
              })}
            </View>

            {/* Dernières associations */}
            <Text style={[styles.fcSec, { marginTop: 22, marginBottom: 12 }]}>DERNIÈRES ASSOCIATIONS</Text>
            <View style={styles.fcPanel}>
              {orgs.map((o, i) => (
                <TouchableOpacity accessibilityRole="button" key={o.id} style={[styles.fcOrgRow, i > 0 ? styles.fcOrgBorder : null]} activeOpacity={0.8} onPress={() => onTile('associations', 'all')}>
                  <View style={styles.fcOrgAv}><Text style={styles.fcOrgAvTxt}>{(o.name || '?').slice(0, 2).toUpperCase()}</Text></View>
                  <View style={{ flex: 1, minWidth: 0 }}>
                    <Text style={styles.fcOrgName} numberOfLines={1}>{o.name}</Text>
                    <Text style={styles.fcOrgSub}>{o.plan} · {o.nb_users} util. · {o.created}</Text>
                  </View>
                  {o.pending ? <View style={[styles.fcChip, { backgroundColor: '#EDE9FE' }]}><Text style={[styles.fcChipTxt, { color: '#6D28D9' }]}>À valider</Text></View>
                    : <View style={[styles.fcChip, { backgroundColor: o.status === 'active' ? '#D1FAE5' : '#F1F5F9' }]}><Text style={[styles.fcChipTxt, { color: o.status === 'active' ? '#047857' : '#64748B' }]}>{o.status === 'active' ? 'Active' : (o.status === 'trial' ? 'Essai' : o.status)}</Text></View>}
                </TouchableOpacity>
              ))}
              <TouchableOpacity accessibilityRole="button" style={styles.fcSeeAll} activeOpacity={0.8} onPress={() => onTile('associations', 'all')}>
                <Text style={styles.fcSeeAllTxt}>Voir toutes les associations</Text>
                <Ionicons name="arrow-forward" size={15} color="#059669" />
              </TouchableOpacity>
            </View>

            {onLogout ? (
              <TouchableOpacity accessibilityRole="button" style={styles.fcLogout} activeOpacity={0.85} onPress={onLogout}>
                <Ionicons name="log-out-outline" size={19} color="#DC2626" />
                <Text style={styles.fcLogoutTxt}>Se déconnecter</Text>
              </TouchableOpacity>
            ) : null}
          </View>
        )}
      </ScrollView>
    </View>
  );
}

/* Écran natif : gestion des associations (Fondateur) — aucune page web */
const FC_ORG_FILTERS = [
  { key: 'all', label: 'Toutes' },
  { key: 'pending', label: 'À valider' },
  { key: 'unpaid', label: 'Impayés' },
  { key: 'trial', label: 'Essais' },
];
function NativeFounderOrgs({ data, loading, filter, onFilter, onRefresh, onBack, onAction, onOpen, busyId }) {
  const orgs = data ? (data.orgs || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Associations" onBack={onBack} />
      <View style={styles.fcFilters}>
        {FC_ORG_FILTERS.map((f) => (
          <TouchableOpacity accessibilityRole="button" key={f.key} style={[styles.fcFilter, filter === f.key && styles.fcFilterOn]} activeOpacity={0.8} onPress={() => onFilter(f.key)}>
            <Text style={[styles.fcFilterTxt, filter === f.key && styles.fcFilterTxtOn]}>{f.label}</Text>
          </TouchableOpacity>
        ))}
      </View>
      {!orgs ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : orgs.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="business-outline" size={42} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune association</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {orgs.map((o) => {
            const busy = busyId === o.id;
            return (
              <View key={o.id} style={styles.fcOrgCard}>
                <TouchableOpacity accessibilityRole="button" style={styles.fcOrgCardTop} activeOpacity={0.7} onPress={() => onOpen && onOpen(o.id)}>
                  <View style={styles.fcOrgAv}><Text style={styles.fcOrgAvTxt}>{o.initials}</Text></View>
                  <View style={{ flex: 1, minWidth: 0 }}>
                    <Text style={styles.fcOrgName} numberOfLines={1}>{o.name}</Text>
                    <Text style={styles.fcOrgSub}>{o.plan} · {o.nb_users} util. · {o.created}</Text>
                  </View>
                  {o.pending ? <View style={[styles.fcChip, { backgroundColor: '#EDE9FE' }]}><Text style={[styles.fcChipTxt, { color: '#6D28D9' }]}>À valider</Text></View>
                    : o.status === 'suspended' ? <View style={[styles.fcChip, { backgroundColor: '#FEE2E2' }]}><Text style={[styles.fcChipTxt, { color: '#B91C1C' }]}>Suspendue</Text></View>
                    : o.status === 'cancelled' ? <View style={[styles.fcChip, { backgroundColor: '#F1F5F9' }]}><Text style={[styles.fcChipTxt, { color: '#64748B' }]}>Résiliée</Text></View>
                    : <View style={[styles.fcChip, { backgroundColor: o.status === 'active' ? '#D1FAE5' : '#F1F5F9' }]}><Text style={[styles.fcChipTxt, { color: o.status === 'active' ? '#047857' : '#64748B' }]}>{o.status === 'active' ? 'Active' : (o.status === 'trial' ? 'Essai' : o.status)}</Text></View>}
                  <Ionicons name="chevron-forward" size={16} color="#CBD5E1" style={{ marginLeft: 4 }} />
                </TouchableOpacity>
                {o.unpaid_nb > 0 && <Text style={styles.fcOrgUnpaid}>⚠︎ {o.unpaid_nb} impayé{o.unpaid_nb > 1 ? 's' : ''} · {fmtEuro(o.unpaid_total)}</Text>}
                <View style={styles.fcOrgActions}>
                  {busy ? <ActivityIndicator size="small" color={BRAND} style={{ paddingVertical: 6 }} /> : o.pending ? (
                    <>
                      <TouchableOpacity accessibilityRole="button" style={[styles.fcAct, styles.fcActGo]} activeOpacity={0.85} onPress={() => onAction(o.id, 'validate')}><Ionicons name="checkmark" size={15} color="#fff" /><Text style={styles.fcActGoTxt}>Valider</Text></TouchableOpacity>
                      <TouchableOpacity accessibilityRole="button" style={[styles.fcAct, styles.fcActNo]} activeOpacity={0.85} onPress={() => onAction(o.id, 'reject')}><Text style={styles.fcActNoTxt}>Refuser</Text></TouchableOpacity>
                    </>
                  ) : o.status === 'suspended' ? (
                    <TouchableOpacity accessibilityRole="button" style={[styles.fcAct, styles.fcActGo]} activeOpacity={0.85} onPress={() => onAction(o.id, 'activate')}><Ionicons name="play" size={14} color="#fff" /><Text style={styles.fcActGoTxt}>Réactiver</Text></TouchableOpacity>
                  ) : (
                    <TouchableOpacity accessibilityRole="button" style={[styles.fcAct, styles.fcActNo]} activeOpacity={0.85} onPress={() => onAction(o.id, 'suspend')}><Ionicons name="pause" size={14} color="#B91C1C" /><Text style={styles.fcActNoTxt}>Suspendre</Text></TouchableOpacity>
                  )}
                </View>
              </View>
            );
          })}
        </ScrollView>
      )}
    </View>
  );
}

/* Fondateur — Fiche détaillée d'une association : éditer, changer de plan, résilier, note */
function NativeFounderOrgDetail({ data, loading, busy, onBack, onRefresh, onEdit, onAction }) {
  const o = data ? data.org : null;
  const plans = (data && data.plans) || [];
  const members = (data && data.members) || [];
  const canNote = !!(data && data.can_note);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [plan, setPlan] = useState('');
  const [note, setNote] = useState('');
  const loadedId = useRef(null);
  useEffect(() => {
    if (o && loadedId.current !== o.id) {
      loadedId.current = o.id;
      setName(o.name || ''); setEmail(o.billing_email || ''); setPlan(o.plan || ''); setNote(o.note || '');
    }
  }, [o]);
  if (!o) return <DetailLoading title="Association" onBack={onBack} />;
  const dirty = o && (name.trim() !== (o.name || '') || email.trim() !== (o.billing_email || '') || plan !== (o.plan || '') || note !== (o.note || ''));
  const canSave = name.trim().length > 1 && !busy && dirty;
  return (
    <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={0}>
      <DetailHeader title="Association" onBack={onBack} />
      <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 18, paddingBottom: 40 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>

        <View style={styles.odHead}>
          <View style={styles.fcOrgAv}><Text style={styles.fcOrgAvTxt}>{(o.name || '?').slice(0, 2).toUpperCase()}</Text></View>
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text style={styles.odTitle} numberOfLines={1}>{o.name}</Text>
            <Text style={styles.odMeta}>{o.status === 'active' ? 'Active' : o.status === 'trial' ? 'Essai' : o.status === 'suspended' ? 'Suspendue' : o.status === 'cancelled' ? 'Résiliée' : o.status} · {o.nb_users} membre{o.nb_users > 1 ? 's' : ''}{o.created ? ' · créée le ' + o.created : ''}{o.trial_ends ? ' · essai → ' + o.trial_ends : ''}</Text>
          </View>
        </View>

        <Text style={styles.blogLabel}>Nom de l'organisation</Text>
        <TextInput style={styles.blogInput} value={name} onChangeText={setName} placeholder="Nom" placeholderTextColor="#9AA7A1" />
        <Text style={styles.blogLabel}>Email de facturation</Text>
        <TextInput style={styles.blogInput} value={email} onChangeText={setEmail} placeholder="contact@asso.fr" placeholderTextColor="#9AA7A1" keyboardType="email-address" autoCapitalize="none" autoCorrect={false} />

        {plans.length > 0 && (
          <>
            <Text style={styles.blogLabel}>Formule</Text>
            <View style={styles.blogCats}>
              {plans.map((p) => (
                <TouchableOpacity accessibilityRole="button" key={p.slug} style={[styles.planChip, plan === p.slug && styles.planChipOn]} activeOpacity={0.85} onPress={() => setPlan(p.slug)}>
                  <Text style={[styles.planChipName, plan === p.slug && { color: '#fff' }]}>{p.name}</Text>
                </TouchableOpacity>
              ))}
            </View>
          </>
        )}

        {canNote && (
          <>
            <Text style={styles.blogLabel}>Note interne <Text style={{ color: '#9AA7A1', fontWeight: '400' }}>(privée)</Text></Text>
            <TextInput style={[styles.blogInput, { height: 90, textAlignVertical: 'top' }]} value={note} onChangeText={setNote} placeholder="Commentaires privés sur cette asso…" placeholderTextColor="#9AA7A1" multiline />
          </>
        )}

        <TouchableOpacity accessibilityRole="button" style={[styles.lgBtn, !canSave && styles.lgBtnOff]} activeOpacity={0.9} disabled={!canSave}
          onPress={() => onEdit({ org_id: o.id, action: 'edit', name: name.trim(), billing_email: email.trim(), plan, note })}>
          {busy ? <ActivityIndicator color="#fff" /> : <><Ionicons name="save" size={17} color="#fff" /><Text style={styles.lgBtnTxt}>Enregistrer</Text></>}
        </TouchableOpacity>

        {/* Actions rapides */}
        <View style={styles.odActions}>
          {o.status === 'suspended' ? (
            <TouchableOpacity accessibilityRole="button" style={[styles.odBtn, { backgroundColor: '#ECFDF5', borderColor: '#A7F3D0' }]} activeOpacity={0.85} onPress={() => onAction(o.id, 'activate')}>
              <Ionicons name="play" size={16} color="#047857" /><Text style={[styles.odBtnTxt, { color: '#047857' }]}>Réactiver</Text>
            </TouchableOpacity>
          ) : o.status !== 'cancelled' ? (
            <TouchableOpacity accessibilityRole="button" style={[styles.odBtn, { backgroundColor: '#FFFBEB', borderColor: '#FDE68A' }]} activeOpacity={0.85} onPress={() => onAction(o.id, 'suspend')}>
              <Ionicons name="pause" size={16} color="#B45309" /><Text style={[styles.odBtnTxt, { color: '#B45309' }]}>Suspendre</Text>
            </TouchableOpacity>
          ) : null}
          {o.status !== 'cancelled' && (
            <TouchableOpacity accessibilityRole="button" style={[styles.odBtn, { backgroundColor: '#FEF2F2', borderColor: '#FECACA' }]} activeOpacity={0.85}
              onPress={() => Alert.alert('Résilier ?', 'L\'association passera en statut « résiliée ». Confirmer ?', [{ text: 'Annuler', style: 'cancel' }, { text: 'Résilier', style: 'destructive', onPress: () => onAction(o.id, 'resiliate') }])}>
              <Ionicons name="close-circle" size={16} color="#B91C1C" /><Text style={[styles.odBtnTxt, { color: '#B91C1C' }]}>Résilier</Text>
            </TouchableOpacity>
          )}
        </View>

        {members.length > 0 && (
          <>
            <Text style={[styles.blogLabel, { marginTop: 22 }]}>Membres ({members.length})</Text>
            <View style={styles.odPanel}>
              {members.map((m, i) => (
                <View key={i} style={[styles.odMember, i > 0 && styles.odMemberBorder]}>
                  <View style={{ flex: 1, minWidth: 0 }}>
                    <Text style={styles.odMemberName} numberOfLines={1}>{m.name}</Text>
                    <Text style={styles.odMemberMail} numberOfLines={1}>{m.email}</Text>
                  </View>
                  {m.role === 'admin' && <View style={[styles.fcChip, { backgroundColor: '#EDE9FE' }]}><Text style={[styles.fcChipTxt, { color: '#6D28D9' }]}>Admin</Text></View>}
                </View>
              ))}
            </View>
          </>
        )}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

/* Fondateur — Gestion des plans tarifaires (natif) : liste + créer/éditer/supprimer */
const PLAN_QUOTAS = [
  { key: 'limit_adherents', label: 'Adhérents' }, { key: 'limit_users', label: 'Utilisateurs' },
  { key: 'limit_invoices_total', label: 'Factures' }, { key: 'limit_quotes_total', label: 'Devis' },
  { key: 'limit_contacts', label: 'Contacts' }, { key: 'limit_ai_text_per_month', label: 'IA texte/mois' },
  { key: 'limit_ai_image_per_month', label: 'IA image/mois' }, { key: 'limit_emails_per_month', label: 'Emails/mois' },
];
const PLAN_FEATURES = [
  { key: 'feature_recurring_invoices', label: 'Factures récurrentes' }, { key: 'feature_signature_quotes', label: 'Devis signés' },
  { key: 'feature_email_diffusion', label: 'Diffusion email' }, { key: 'feature_advanced_stats', label: 'Stats avancées' },
  { key: 'feature_priority_support', label: 'Support prioritaire' }, { key: 'feature_custom_domain', label: 'Domaine perso' },
  { key: 'feature_dedicated_support', label: 'Support dédié' },
];
function blankPlan() {
  const p = { id: 0, slug: '', name: '', tagline: '', price_eur: '', price_label: '', is_custom_quote: false, is_featured: false, is_visible: true, display_order: 0 };
  PLAN_QUOTAS.forEach((q) => { p[q.key] = ''; });
  PLAN_FEATURES.forEach((f) => { p[f.key] = false; });
  return p;
}
function NativeFounderPlans({ data, loading, busy, onRefresh, onBack, onSave, onDelete, onToggle }) {
  const plans = data ? (data.plans || []) : null;
  const [form, setForm] = useState(null); // null = liste ; objet = édition/création
  const setF = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  // Édition d'un plan existant
  const openEdit = (p) => {
    const f = { ...blankPlan(), ...p };
    PLAN_QUOTAS.forEach((q) => { f[q.key] = (p[q.key] === null || p[q.key] === undefined) ? '' : String(p[q.key]); });
    f.price_eur = p.price_eur != null ? String(p.price_eur) : '';
    setForm(f);
  };

  if (form) {
    const isNew = !form.id;
    const canSave = form.slug.trim().length > 0 && form.name.trim().length > 0 && !busy;
    return (
      <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={0}>
        <DetailHeader title={isNew ? 'Nouveau plan' : 'Modifier le plan'} onBack={() => setForm(null)} />
        <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 18, paddingBottom: 40 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <View style={{ flex: 1.3 }}><Text style={styles.blogLabel}>Nom</Text><TextInput style={styles.blogInput} value={form.name} onChangeText={(v) => setF('name', v)} placeholder="Ex : Association" placeholderTextColor="#9AA7A1" /></View>
            <View style={{ flex: 1 }}><Text style={styles.blogLabel}>Slug</Text><TextInput style={styles.blogInput} value={form.slug} onChangeText={(v) => setF('slug', v.toLowerCase().replace(/[^a-z0-9-]/g, ''))} placeholder="association" placeholderTextColor="#9AA7A1" autoCapitalize="none" /></View>
          </View>
          <Text style={styles.blogLabel}>Accroche</Text>
          <TextInput style={styles.blogInput} value={form.tagline} onChangeText={(v) => setF('tagline', v)} placeholder="Ex : Pour les assos qui grandissent" placeholderTextColor="#9AA7A1" />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <View style={{ flex: 1 }}><Text style={styles.blogLabel}>Prix (€/mois HT)</Text><TextInput style={styles.blogInput} value={form.price_eur} onChangeText={(v) => setF('price_eur', v.replace(/[^0-9.,]/g, '').replace(',', '.'))} placeholder="49.99" placeholderTextColor="#9AA7A1" keyboardType="decimal-pad" /></View>
            <View style={{ flex: 1 }}><Text style={styles.blogLabel}>Libellé prix</Text><TextInput style={styles.blogInput} value={form.price_label} onChangeText={(v) => setF('price_label', v)} placeholder="Sur devis…" placeholderTextColor="#9AA7A1" /></View>
          </View>

          <Text style={[styles.blogLabel, { marginTop: 16 }]}>Quotas <Text style={{ color: '#9AA7A1', fontWeight: '400' }}>(vide = illimité)</Text></Text>
          <View style={styles.plnGrid}>
            {PLAN_QUOTAS.map((q) => (
              <View key={q.key} style={styles.plnQuota}>
                <Text style={styles.plnQuotaLbl}>{q.label}</Text>
                <TextInput style={styles.plnQuotaInp} value={String(form[q.key] ?? '')} onChangeText={(v) => setF(q.key, v.replace(/[^0-9]/g, ''))} placeholder="∞" placeholderTextColor="#B8C4C0" keyboardType="number-pad" />
              </View>
            ))}
          </View>

          <Text style={[styles.blogLabel, { marginTop: 16 }]}>Fonctionnalités incluses</Text>
          <View style={styles.plnPanel}>
            {PLAN_FEATURES.map((ft, i) => (
              <View key={ft.key} style={[styles.plnFeat, i > 0 && styles.odMemberBorder]}>
                <Text style={styles.plnFeatLbl}>{ft.label}</Text>
                <Switch value={!!form[ft.key]} onValueChange={(v) => setF(ft.key, v)} trackColor={{ true: BRAND, false: '#CBD5E1' }} />
              </View>
            ))}
          </View>

          <View style={styles.plnPanel}>
            <View style={styles.plnFeat}><Text style={styles.plnFeatLbl}>Visible sur la page tarifs</Text><Switch value={!!form.is_visible} onValueChange={(v) => setF('is_visible', v)} trackColor={{ true: BRAND, false: '#CBD5E1' }} /></View>
            <View style={[styles.plnFeat, styles.odMemberBorder]}><Text style={styles.plnFeatLbl}>Plan mis en avant</Text><Switch value={!!form.is_featured} onValueChange={(v) => setF('is_featured', v)} trackColor={{ true: BRAND, false: '#CBD5E1' }} /></View>
            <View style={[styles.plnFeat, styles.odMemberBorder]}><Text style={styles.plnFeatLbl}>Sur devis (prix masqué)</Text><Switch value={!!form.is_custom_quote} onValueChange={(v) => setF('is_custom_quote', v)} trackColor={{ true: BRAND, false: '#CBD5E1' }} /></View>
          </View>

          <TouchableOpacity accessibilityRole="button" style={[styles.lgBtn, !canSave && styles.lgBtnOff]} activeOpacity={0.9} disabled={!canSave}
            onPress={() => {
              const payload = { action: isNew ? 'create' : 'update', plan_id: form.id || undefined, slug: form.slug.trim(), name: form.name.trim(), tagline: form.tagline.trim(), price_eur: parseFloat(form.price_eur) || 0, price_label: form.price_label.trim(), is_custom_quote: form.is_custom_quote ? 1 : 0, is_featured: form.is_featured ? 1 : 0, is_visible: form.is_visible ? 1 : 0, display_order: form.display_order || 0 };
              PLAN_QUOTAS.forEach((q) => { payload[q.key] = form[q.key] === '' ? '' : parseInt(form[q.key], 10); });
              PLAN_FEATURES.forEach((f) => { payload[f.key] = form[f.key] ? 1 : 0; });
              onSave(payload, () => setForm(null));
            }}>
            {busy ? <ActivityIndicator color="#fff" /> : <><Ionicons name="save" size={17} color="#fff" /><Text style={styles.lgBtnTxt}>{isNew ? 'Créer le plan' : 'Enregistrer'}</Text></>}
          </TouchableOpacity>
          {!isNew && (
            <TouchableOpacity accessibilityRole="button" style={{ alignSelf: 'center', paddingVertical: 14 }} activeOpacity={0.7}
              onPress={() => Alert.alert('Supprimer ce plan ?', 'Action définitive (impossible si des orgs l\'utilisent).', [{ text: 'Annuler', style: 'cancel' }, { text: 'Supprimer', style: 'destructive', onPress: () => onDelete(form.id, () => setForm(null)) }])}>
              <Text style={{ color: '#DC2626', fontWeight: '700', fontSize: 14 }}>Supprimer le plan</Text>
            </TouchableOpacity>
          )}
        </ScrollView>
      </KeyboardAvoidingView>
    );
  }

  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Plans tarifaires" onBack={onBack} />
      {!plans ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 28 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <TouchableOpacity accessibilityRole="button" style={[styles.projNewBtn, { alignSelf: 'flex-start', marginBottom: 14 }]} activeOpacity={0.85} onPress={() => setForm(blankPlan())}>
            <Ionicons name="add" size={18} color="#fff" /><Text style={styles.projNewTxt}>Nouveau plan</Text>
          </TouchableOpacity>
          {plans.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="pricetags-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun plan</Text></View>
          ) : plans.map((p) => (
            <TouchableOpacity accessibilityRole="button" key={p.id} style={styles.plnCard} activeOpacity={0.85} onPress={() => openEdit(p)}>
              <View style={{ flex: 1, minWidth: 0 }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                  <Text style={styles.plnName} numberOfLines={1}>{p.name}</Text>
                  {p.is_featured && <View style={[styles.fcChip, { backgroundColor: '#FEF3C7' }]}><Text style={[styles.fcChipTxt, { color: '#B45309' }]}>Mis en avant</Text></View>}
                  {!p.is_visible && <View style={[styles.fcChip, { backgroundColor: '#F1F5F9' }]}><Text style={[styles.fcChipTxt, { color: '#64748B' }]}>Masqué</Text></View>}
                </View>
                <Text style={styles.plnSub} numberOfLines={1}>{p.slug} · {p.adoption} org{p.adoption > 1 ? 's' : ''}{p.tagline ? ' · ' + p.tagline : ''}</Text>
              </View>
              <View style={{ alignItems: 'flex-end' }}>
                <Text style={styles.plnPrice}>{p.is_custom_quote ? 'Sur devis' : (p.price_eur > 0 ? fmtEuro(p.price_eur) : 'Gratuit')}</Text>
                <Ionicons name="chevron-forward" size={16} color="#CBD5E1" style={{ marginTop: 3 }} />
              </View>
            </TouchableOpacity>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

/* Fondateur — Vue globale des projets (lecture seule) */
const FC_PROJ_FILTERS = [{ key: '', label: 'Tous' }, { key: 'in_progress', label: 'En cours' }, { key: 'completed', label: 'Terminés' }, { key: 'planned', label: 'À venir' }];
function NativeFounderProjects({ data, loading, filter, onFilter, onRefresh, onBack }) {
  const list = data ? (data.projects || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Projets · toutes les orgs" onBack={onBack} />
      <View style={styles.fcFilters}>
        {FC_PROJ_FILTERS.map((f) => (
          <TouchableOpacity accessibilityRole="button" key={f.key} style={[styles.fcFilter, filter === f.key && styles.fcFilterOn]} activeOpacity={0.8} onPress={() => onFilter(f.key)}>
            <Text style={[styles.fcFilterTxt, filter === f.key && styles.fcFilterTxtOn]}>{f.label}</Text>
          </TouchableOpacity>
        ))}
      </View>
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="folder-open-outline" size={42} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun projet</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <Text style={styles.fdCountLine}>{data.count} projet{data.count > 1 ? 's' : ''}</Text>
          {list.map((p) => (
            <View key={p.id} style={styles.plnCard}>
              <View style={{ flex: 1, minWidth: 0 }}>
                <Text style={styles.plnName} numberOfLines={1}>{p.name}</Text>
                <Text style={styles.plnSub} numberOfLines={1}>{p.org} · {p.created}{p.budget > 0 ? ' · ' + fmtEuro(p.used) + '/' + fmtEuro(p.budget) : ''}</Text>
                <View style={styles.fdProgTrack}><View style={[styles.fdProgFill, { width: Math.max(3, Math.min(100, p.progress)) + '%' }]} /></View>
              </View>
              <Text style={styles.plnPrice}>{p.progress}%</Text>
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

/* Fondateur — Journal d'activité de la plateforme (lecture seule) */
function NativeFounderActivity({ data, loading, onRefresh, onBack }) {
  const list = data ? (data.activity || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Activité de la plateforme" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="pulse-outline" size={42} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune activité récente</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {list.map((a, i) => (
            <View key={i} style={styles.actRow}>
              <View style={[styles.actIc, { backgroundColor: a.color + '22' }]}><Text style={{ fontSize: 15 }}>{a.icon}</Text></View>
              <View style={{ flex: 1, minWidth: 0 }}>
                <Text style={styles.actLbl} numberOfLines={1}><Text style={{ color: a.color, fontWeight: '800' }}>{a.label}</Text>{a.org ? ' · ' + a.org : ''}</Text>
                <Text style={styles.actMeta} numberOfLines={1}>{[a.actor, a.detail, a.when].filter(Boolean).join(' · ')}</Text>
              </View>
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

/* Fondateur — Paramètres société (company_settings) */
const SETTINGS_SECTIONS = [
  { title: 'Identité légale', fields: [['legal_name', 'Raison sociale', 'default'], ['legal_form', 'Forme juridique', 'default'], ['siren', 'SIREN', 'default'], ['siret', 'SIRET', 'default'], ['ape_code', 'Code APE', 'default']] },
  { title: 'TVA', fields: [['vat_subject', 'Assujetti à la TVA', 'switch'], ['vat_number', 'N° TVA intracom.', 'default'], ['vat_rate', 'Taux TVA (%)', 'decimal']] },
  { title: 'Adresse', fields: [['address_street', 'Rue', 'default'], ['address_zip', 'Code postal', 'default'], ['address_city', 'Ville', 'default']] },
  { title: 'Contacts', fields: [['email_billing', 'Email facturation', 'email'], ['email_support', 'Email support', 'email'], ['phone', 'Téléphone', 'phone'], ['website', 'Site web', 'url']] },
  { title: 'Coordonnées bancaires', fields: [['iban', 'IBAN', 'default'], ['bic', 'BIC', 'default'], ['bank_name', 'Banque', 'default']] },
  { title: 'Représentant légal', fields: [['legal_rep_first_name', 'Prénom', 'default'], ['legal_rep_last_name', 'Nom', 'default'], ['legal_rep_role', 'Fonction', 'default']] },
];
function NativeFounderSettings({ data, loading, busy, onRefresh, onBack, onSave }) {
  const [form, setForm] = useState(null);
  const loaded = useRef(false);
  useEffect(() => {
    if (data && data.settings && !loaded.current) { loaded.current = true; setForm({ ...data.settings }); }
  }, [data]);
  const setF = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  if (!data || !form) return <DetailLoading title="Paramètres société" onBack={onBack} />;
  const kb = { email: 'email-address', phone: 'phone-pad', url: 'url', decimal: 'decimal-pad', default: 'default' };
  return (
    <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={0}>
      <DetailHeader title="Paramètres société" onBack={onBack} />
      <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 18, paddingBottom: 40 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        {SETTINGS_SECTIONS.map((sec) => (
          <View key={sec.title} style={{ marginBottom: 8 }}>
            <Text style={styles.setSec}>{sec.title}</Text>
            {sec.fields.map(([key, label, type]) => (
              type === 'switch' ? (
                <View key={key} style={styles.blogSwitchRow}>
                  <Text style={[styles.blogSwitchTitle, { flex: 1 }]}>{label}</Text>
                  <Switch value={!!form[key]} onValueChange={(v) => setF(key, v)} trackColor={{ true: BRAND, false: '#CBD5E1' }} />
                </View>
              ) : (
                <View key={key}>
                  <Text style={styles.blogLabel}>{label}</Text>
                  <TextInput style={styles.blogInput} value={String(form[key] ?? '')} onChangeText={(v) => setF(key, v)}
                    placeholder={label} placeholderTextColor="#9AA7A1" keyboardType={kb[type] || 'default'}
                    autoCapitalize={(type === 'email' || type === 'url') ? 'none' : 'sentences'} autoCorrect={false} />
                </View>
              )
            ))}
          </View>
        ))}
        <TouchableOpacity accessibilityRole="button" style={[styles.lgBtn, busy && styles.lgBtnOff]} activeOpacity={0.9} disabled={busy}
          onPress={() => { const p = { ...form }; p.vat_subject = form.vat_subject ? 1 : 0; onSave(p); }}>
          {busy ? <ActivityIndicator color="#fff" /> : <><Ionicons name="save" size={17} color="#fff" /><Text style={styles.lgBtnTxt}>Enregistrer</Text></>}
        </TouchableOpacity>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

/* Fondateur — Annuaire national des associations (natif) : Région → Département → Catégorie */
function NativeFounderDirectory({ data, loading, busy, nav, onBack, onRefresh, onOpenRegion, onOpenDept, onBackRoot, onBackRegion, onSetEmail, onToProspect }) {
  const view = data ? data.view : null;
  const catLabels = (data && data.categories) || {};

  const promptEmail = (a) => {
    Alert.prompt
      ? Alert.prompt('Email légitime', 'Adresse contact@ publiée par l\'association (base « intérêt légitime B2B », avec lien de désinscription).', [
          { text: 'Annuler', style: 'cancel' },
          { text: 'Enregistrer', onPress: (val) => { const e = (val || '').trim(); if (e) onSetEmail(a.id, e); } },
        ], 'plain-text', a.email || '')
      : Alert.alert('Ajouter un email', 'La saisie d\'email n\'est pas disponible sur cet appareil.');
  };

  const crumb = () => {
    if (view === 'list') return (nav.dept_name || 'Département') + (nav.category ? ' · ' + (catLabels[nav.category] || nav.category) : '');
    if (view === 'region') return nav.region || 'Région';
    return 'Annuaire France';
  };

  return (
    <View style={[styles.fcWrap, Platform.OS === 'ios' && { top: -Constants.statusBarHeight }]}>
      <StatusBar barStyle="light-content" />
      <View style={styles.fcHeaderWrap}>
        <LinearGradient colors={['#0369A1', '#075985', '#0C4A6E']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}
          style={[styles.fcHeader, Platform.OS === 'ios' && { paddingTop: Constants.statusBarHeight + 10 }]}>
          <View style={styles.fcTopRow}>
            <TouchableOpacity accessibilityRole="button" accessibilityLabel="Retour" style={styles.fcBack} activeOpacity={0.8}
              onPress={view === 'list' ? onBackRegion : view === 'region' ? onBackRoot : onBack}>
              <Ionicons name="chevron-back" size={19} color="#EAF2EE" />
            </TouchableOpacity>
            <Text style={styles.fcTopTitle} numberOfLines={1}>Annuaire France</Text>
            <TouchableOpacity accessibilityRole="button" accessibilityLabel="Actualiser" style={{ width: 40, alignItems: 'flex-end' }} activeOpacity={0.8} onPress={onRefresh}>
              <Ionicons name="refresh" size={18} color="#EAF2EE" />
            </TouchableOpacity>
          </View>
          <Text style={[styles.fcSub, { marginTop: 8 }]} numberOfLines={1}><Ionicons name="location-outline" size={12} color="#64748B" /> {crumb()}</Text>
        </LinearGradient>
      </View>

      {!data ? (
        <View style={[styles.homeLoader, { flex: 1, paddingTop: 50 }]}><ActivityIndicator size="large" color="#0369A1" /></View>
      ) : (
        <ScrollView style={styles.fcScroll} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor="#0369A1" colors={['#0369A1']} />}>

          {/* Vue racine : régions */}
          {view === 'root' && (
            <>
              <View style={styles.dirBanner}>
                <Text style={styles.dirBannerBig}>{(data.total || 0).toLocaleString('fr-FR')}</Text>
                <Text style={styles.dirBannerLbl}>associations · {data.with_email || 0} avec email</Text>
              </View>
              {(!data.regions || !data.regions.length) ? (
                <View style={styles.dirEmpty}>
                  <Ionicons name="cloud-download-outline" size={34} color="#94A3B8" />
                  <Text style={styles.dirEmptyT}>Annuaire vide</Text>
                  <Text style={styles.dirEmptyS}>Lance sur le serveur :{"\n"}php founder-annuaire-france.php</Text>
                </View>
              ) : data.regions.map((r) => (
                <TouchableOpacity accessibilityRole="button" key={r.region} style={styles.dirRow} activeOpacity={0.85} onPress={() => onOpenRegion(r.region)}>
                  <View style={[styles.dirIco, { backgroundColor: '#F0F9FF' }]}><Ionicons name="map" size={18} color="#0369A1" /></View>
                  <Text style={styles.dirRowT}>{r.region}</Text>
                  <View style={styles.dirCount}><Text style={styles.dirCountT}>{r.total.toLocaleString('fr-FR')}</Text></View>
                  <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
                </TouchableOpacity>
              ))}
            </>
          )}

          {/* Vue région : départements */}
          {view === 'region' && (
            (!data.depts || !data.depts.length) ? (
              <View style={styles.dirEmpty}><Text style={styles.dirEmptyS}>Aucun département indexé pour cette région.</Text></View>
            ) : data.depts.map((d) => (
              <View key={d.code} style={styles.dirDeptCard}>
                <TouchableOpacity accessibilityRole="button" style={styles.dirDeptHead} activeOpacity={0.85} onPress={() => onOpenDept(d.code, d.name, '')}>
                  <View style={[styles.dirIco, { backgroundColor: '#EFF6FF' }]}><Text style={styles.dirDeptCode}>{d.code}</Text></View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.dirRowT}>{d.name}</Text>
                    <Text style={styles.dirRowS}>{d.total.toLocaleString('fr-FR')} associations</Text>
                  </View>
                  <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
                </TouchableOpacity>
                <View style={styles.dirChips}>
                  {Object.entries(d.by_cat || {}).map(([c, n]) => (
                    <TouchableOpacity accessibilityRole="button" key={c} style={styles.dirChip} activeOpacity={0.8} onPress={() => onOpenDept(d.code, d.name, c)}>
                      <Text style={styles.dirChipT}>{catLabels[c] || c}</Text>
                      <Text style={styles.dirChipN}>{n}</Text>
                    </TouchableOpacity>
                  ))}
                </View>
              </View>
            ))
          )}

          {/* Vue liste : associations */}
          {view === 'list' && (
            <>
              <Text style={styles.dirListCount}>{(data.total || 0).toLocaleString('fr-FR')} associations</Text>
              {(!data.assos || !data.assos.length) ? (
                <View style={styles.dirEmpty}><Text style={styles.dirEmptyS}>Aucune association ici.</Text></View>
              ) : data.assos.map((a) => (
                <View key={a.id} style={styles.dirAsso}>
                  <Text style={styles.dirAssoName}>{a.org}</Text>
                  <Text style={styles.dirAssoMeta}>{a.cat_label} · {a.city} {a.zip}</Text>
                  {a.email ? (
                    <View style={styles.dirEmailRow}><Ionicons name="mail" size={13} color="#047857" /><Text style={styles.dirEmailTxt}>{a.email}</Text></View>
                  ) : null}
                  <View style={styles.dirAssoActions}>
                    <TouchableOpacity accessibilityRole="button" style={styles.dirActBtn} activeOpacity={0.85} disabled={busy} onPress={() => promptEmail(a)}>
                      <Ionicons name="create-outline" size={15} color="#0369A1" />
                      <Text style={styles.dirActTxt}>{a.email ? 'Modifier email' : 'Ajouter email'}</Text>
                    </TouchableOpacity>
                    {a.email ? (
                      <TouchableOpacity accessibilityRole="button" style={[styles.dirActBtn, styles.dirActBtnP]} activeOpacity={0.85} disabled={busy} onPress={() => onToProspect(a.id)}>
                        <Ionicons name="rocket" size={15} color="#fff" />
                        <Text style={[styles.dirActTxt, { color: '#fff' }]}>Prospecter</Text>
                      </TouchableOpacity>
                    ) : null}
                  </View>
                </View>
              ))}
            </>
          )}

          <View style={styles.dirLegal}>
            <Ionicons name="shield-checkmark-outline" size={15} color="#0369A1" />
            <Text style={styles.dirLegalTxt}>Données publiques (RNA/INSEE), sans email. N'ajoute que des adresses contact@ publiées, avec lien de désinscription (RGPD · intérêt légitime B2B).</Text>
          </View>
        </ScrollView>
      )}
    </View>
  );
}

/* Fondateur — Prospection conforme (natif) : import, séquences, suivi */
const PROSPECT_STATUS = {
  new: { label: 'Nouveau', color: '#64748B', bg: '#F1F5F9' },
  queued: { label: 'En file', color: '#B45309', bg: '#FEF3C7' },
  contacted: { label: 'Contacté', color: '#2563EB', bg: '#EFF6FF' },
  engaged: { label: 'Engagé', color: '#7C3AED', bg: '#F5F3FF' },
  replied: { label: 'A répondu', color: '#047857', bg: '#D1FAE5' },
  booked: { label: 'RDV pris', color: '#047857', bg: '#D1FAE5' },
  unsubscribed: { label: 'Désinscrit', color: '#94A3B8', bg: '#F1F5F9' },
  bounced: { label: 'Rejeté', color: '#B91C1C', bg: '#FEE2E2' },
};
function NativeFounderProspects({ data, loading, busy, onRefresh, onBack, onImport, onQueue, onStatus, onDelete }) {
  const list = data ? (data.prospects || []) : null;
  const st = (data && data.stats) || {};
  const [text, setText] = useState('');
  const [type, setType] = useState('asso');
  const [showImport, setShowImport] = useState(false);
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Prospection" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 30 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>

          {/* Bandeau conformité / état d'envoi */}
          <View style={[styles.prBanner, { backgroundColor: st.sending_enabled ? '#ECFDF5' : '#FFFBEB', borderColor: st.sending_enabled ? '#A7F3D0' : '#FDE68A' }]}>
            <Ionicons name={st.sending_enabled ? 'checkmark-circle' : 'pause-circle'} size={18} color={st.sending_enabled ? '#047857' : '#B45309'} />
            <Text style={[styles.prBannerTxt, { color: st.sending_enabled ? '#047857' : '#B45309' }]}>
              {st.sending_enabled ? `Envoi actif · ${st.sent_today || 0}/${st.daily_cap} aujourd'hui` : 'Envoi en pause (mode test) — à activer après domaine dédié + warm-up'}
            </Text>
          </View>

          {/* Stats */}
          <View style={styles.miniKpiRow}>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{st.total || 0}</Text><Text style={styles.miniKpiLbl}>Prospects</Text></View>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{(st.events && st.events.sent) || 0}</Text><Text style={styles.miniKpiLbl}>Emails</Text></View>
            <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: '#047857' }]}>{(st.by_status && (st.by_status.replied + st.by_status.booked)) || 0}</Text><Text style={styles.miniKpiLbl}>Réponses</Text></View>
          </View>

          {/* Actions */}
          <View style={{ flexDirection: 'row', gap: 10, marginBottom: 14 }}>
            <TouchableOpacity accessibilityRole="button" style={[styles.projNewBtn, { flex: 1, justifyContent: 'center' }]} activeOpacity={0.85} onPress={() => setShowImport((v) => !v)}>
              <Ionicons name="cloud-upload" size={17} color="#fff" /><Text style={styles.projNewTxt}>Importer</Text>
            </TouchableOpacity>
            <TouchableOpacity accessibilityRole="button" style={[styles.odBtn, { flex: 1, justifyContent: 'center', backgroundColor: '#EFF6FF', borderColor: '#BFDBFE' }]} activeOpacity={0.85}
              onPress={() => Alert.alert('Mettre en file ?', 'Les prospects « Nouveau » entreront dans la séquence de relance. (Aucun email tant que l\'envoi n\'est pas activé.)', [{ text: 'Annuler', style: 'cancel' }, { text: 'Mettre en file', onPress: onQueue }])}>
              <Ionicons name="play-forward" size={16} color="#2563EB" /><Text style={[styles.odBtnTxt, { color: '#2563EB' }]}>Lancer la séquence</Text>
            </TouchableOpacity>
          </View>

          {showImport && (
            <View style={styles.prImport}>
              <Text style={styles.blogLabel}>Cible</Text>
              <View style={{ flexDirection: 'row', gap: 8, marginBottom: 8 }}>
                <TouchableOpacity accessibilityRole="button" style={[styles.planChip, type === 'asso' && styles.planChipOn]} onPress={() => setType('asso')}><Text style={[styles.planChipName, type === 'asso' && { color: '#fff' }]}>Associations</Text></TouchableOpacity>
                <TouchableOpacity accessibilityRole="button" style={[styles.planChip, type === 'tpe' && styles.planChipOn]} onPress={() => setType('tpe')}><Text style={[styles.planChipName, type === 'tpe' && { color: '#fff' }]}>TPE / PME</Text></TouchableOpacity>
              </View>
              <Text style={styles.blogLabel}>Contacts <Text style={{ color: '#9AA7A1', fontWeight: '400' }}>(1 par ligne : email ou email;nom;organisation;ville)</Text></Text>
              <TextInput style={[styles.blogInput, { height: 120, textAlignVertical: 'top' }]} value={text} onChangeText={setText} placeholder={"contact@asso-exemple.fr\nemail;Nom;Organisation;Ville"} placeholderTextColor="#9AA7A1" multiline autoCapitalize="none" autoCorrect={false} />
              <Text style={styles.prHint}>⚖️ N'importez que des contacts que vous avez le droit de démarcher (B2B pertinent). Chaque email inclut un lien de désinscription.</Text>
              <TouchableOpacity accessibilityRole="button" style={[styles.lgBtn, (busy || text.trim().length < 5) && styles.lgBtnOff]} activeOpacity={0.9} disabled={busy || text.trim().length < 5}
                onPress={() => onImport(text.trim(), type, () => { setText(''); setShowImport(false); })}>
                {busy ? <ActivityIndicator color="#fff" /> : <><Ionicons name="add-circle" size={17} color="#fff" /><Text style={styles.lgBtnTxt}>Importer les contacts</Text></>}
              </TouchableOpacity>
            </View>
          )}

          {/* Liste */}
          {list.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="people-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun prospect — importez vos contacts</Text></View>
          ) : list.map((p) => {
            const s = PROSPECT_STATUS[p.status] || PROSPECT_STATUS.new;
            return (
              <View key={p.id} style={styles.plnCard}>
                <View style={{ flex: 1, minWidth: 0 }}>
                  <Text style={styles.plnName} numberOfLines={1}>{p.org || p.name || p.email}</Text>
                  <Text style={styles.plnSub} numberOfLines={1}>{p.email}{p.city ? ' · ' + p.city : ''} · {p.type === 'tpe' ? 'TPE' : 'Asso'}{p.step > 0 ? ' · étape ' + p.step : ''}</Text>
                </View>
                <View style={{ alignItems: 'flex-end', gap: 6 }}>
                  <View style={[styles.fcChip, { backgroundColor: s.bg }]}><Text style={[styles.fcChipTxt, { color: s.color }]}>{s.label}</Text></View>
                  <View style={{ flexDirection: 'row', gap: 10 }}>
                    <TouchableOpacity accessibilityRole="button" onPress={() => onStatus(p.id, 'booked')}><Ionicons name="calendar" size={17} color="#047857" /></TouchableOpacity>
                    <TouchableOpacity accessibilityRole="button" accessibilityLabel="Supprimer" onPress={() => Alert.alert('Supprimer ?', p.email, [{ text: 'Annuler', style: 'cancel' }, { text: 'Supprimer', style: 'destructive', onPress: () => onDelete(p.id) }])}><Ionicons name="trash-outline" size={17} color="#B91C1C" /></TouchableOpacity>
                  </View>
                </View>
              </View>
            );
          })}
        </ScrollView>
      )}
    </View>
  );
}

/* Fondateur — Paiements & Abonnements (natif) */
const FC_BILL_FILTERS = [{ key: 'all', label: 'Toutes' }, { key: 'unpaid', label: 'Impayés' }, { key: 'paid', label: 'Payées' }];
function NativeFounderBilling({ data, loading, filter, onFilter, onRefresh, onBack, onPay, busyId }) {
  const s = (data && data.summary) || {};
  const list = data ? (data.invoices || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Paiements" onBack={onBack} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 26 }} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <View style={styles.miniKpiRow}>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(s.mrr)}</Text><Text style={styles.miniKpiLbl}>MRR</Text></View>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(s.ca_paid)}</Text><Text style={styles.miniKpiLbl}>Encaissé</Text></View>
          <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: (s.unpaid_nb > 0) ? '#B91C1C' : INK }]}>{fmtEuro(s.unpaid_total)}</Text><Text style={styles.miniKpiLbl}>Impayés</Text></View>
        </View>
        <View style={styles.fcFilters2}>
          {FC_BILL_FILTERS.map((f) => (
            <TouchableOpacity accessibilityRole="button" key={f.key} style={[styles.fcFilter, filter === f.key && styles.fcFilterOn]} activeOpacity={0.8} onPress={() => onFilter(f.key)}>
              <Text style={[styles.fcFilterTxt, filter === f.key && styles.fcFilterTxtOn]}>{f.label}</Text>
            </TouchableOpacity>
          ))}
        </View>
        {!list ? (
          <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
        ) : list.length === 0 ? (
          <View style={styles.emptyBox}><Ionicons name="card-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune facture</Text></View>
        ) : list.map((inv) => {
          const km = INV_KIND[inv.status_kind] || INV_KIND.wait;
          const busy = busyId === inv.id;
          return (
            <View key={inv.id} style={styles.fcInvCard}>
              <View style={{ flexDirection: 'row', alignItems: 'center' }}>
                <View style={{ flex: 1, paddingRight: 10 }}>
                  <Text style={styles.fcOrgName} numberOfLines={1}>{inv.org}</Text>
                  <Text style={styles.fcOrgSub}>{inv.number} · {inv.date}</Text>
                </View>
                <View style={{ alignItems: 'flex-end' }}>
                  <Text style={styles.fcInvAmount}>{fmtEuro(inv.amount)}</Text>
                  <View style={[styles.fcChip, { backgroundColor: km.bg, marginTop: 4 }]}><Text style={[styles.fcChipTxt, { color: km.color }]}>{inv.status_label}</Text></View>
                </View>
              </View>
              {inv.can_pay && (
                <TouchableOpacity accessibilityRole="button" style={styles.fcPayBtn} activeOpacity={0.85} onPress={() => onPay(inv.id)} disabled={busy}>
                  {busy ? <ActivityIndicator size="small" color="#fff" /> : <><Ionicons name="checkmark-circle" size={16} color="#fff" /><Text style={styles.fcPayTxt}>Marquer payée</Text></>}
                </TouchableOpacity>
              )}
            </View>
          );
        })}
      </ScrollView>
    </View>
  );
}

/* Fondateur — Statistiques plateforme (natif) */
function NativeFounderStats({ data, loading, onBack, onRefresh }) {
  const o = (data && data.orgs) || {}, u = (data && data.users) || {}, r = (data && data.revenue) || {}, ia = (data && data.ia) || {};
  const curve = (data && data.curve) || [];
  const maxN = Math.max(1, ...curve.map((c) => c.n));
  const cards = [
    { icon: 'cash', color: '#059669', bg: '#ECFDF5', v: fmtEuro(r.mrr), l: 'MRR', s: 'récurrent' },
    { icon: 'trending-up', color: '#0891B2', bg: '#ECFEFF', v: fmtEuro(r.ca_paid), l: 'CA encaissé', s: fmtEuro(r.ca_paid_30) + ' /30j' },
    { icon: 'business', color: '#7C3AED', bg: '#F5F3FF', v: String(o.total ?? 0), l: 'Associations', s: (o.active ?? 0) + ' act. · ' + (o.trial ?? 0) + ' essai' },
    { icon: 'people', color: '#2563EB', bg: '#EFF6FF', v: String(u.total ?? 0), l: 'Utilisateurs', s: '+' + (u.new30 ?? 0) + ' /30j' },
    { icon: 'alert-circle', color: '#DC2626', bg: '#FEF2F2', v: fmtEuro(r.unpaid_total), l: 'Impayés', s: (r.unpaid_nb ?? 0) + ' facture' + ((r.unpaid_nb ?? 0) > 1 ? 's' : '') },
    { icon: 'sparkles', color: '#D97706', bg: '#FFFBEB', v: String(ia.nb ?? 0), l: 'Générations IA', s: fmtEuro(ia.cost) + ' dépensés' },
  ];
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Statistiques" onBack={onBack} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 26 }} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        {!data ? <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View> : (
          <>
            <View style={styles.fcStatGrid}>
              {cards.map((c, i) => (
                <View key={i} style={styles.fcStatCard}>
                  <View style={[styles.fcMiniIc, { backgroundColor: c.bg }]}><Ionicons name={c.icon} size={16} color={c.color} /></View>
                  <Text style={styles.fcStatVal}>{c.v}</Text>
                  <Text style={styles.fcStatLb}>{c.l}</Text>
                  <Text style={styles.fcStatSub} numberOfLines={1}>{c.s}</Text>
                </View>
              ))}
            </View>
            {curve.length > 0 && (
              <>
                <Text style={[styles.fcSec, { marginTop: 22, marginBottom: 12, color: '#64748B' }]}>NOUVELLES ASSOCIATIONS · 6 MOIS</Text>
                <View style={styles.fcBars}>
                  {curve.map((c, i) => (
                    <View key={i} style={styles.fcBarCol}>
                      <Text style={styles.fcBarVal}>{c.n}</Text>
                      <View style={styles.fcBarTrack}><View style={[styles.fcBarFill, { height: Math.max(4, Math.round((c.n / maxN) * 80)) }]} /></View>
                      <Text style={styles.fcBarLbl}>{(c.ym || '').slice(5)}</Text>
                    </View>
                  ))}
                </View>
              </>
            )}

            <PeriodStat title="Emails envoyés — plateforme" icon="mail" color="#7C3AED" bg="#F5F3FF" data={data.emails} />
            <PeriodStat title="SMS envoyés — plateforme" icon="chatbox" color="#059669" bg="#ECFDF5" data={data.sms} />
          </>
        )}
      </ScrollView>
    </View>
  );
}

function PeriodStat({ title, icon, color, bg, data }) {
  const d = data || {};
  const cells = [['Semaine', d.week], ['Mois', d.month], ['Trimestre', d.quarter], ['Année', d.year]];
  return (
    <View style={styles.periodCard}>
      <View style={styles.periodHead}>
        <View style={[styles.fcMiniIc, { backgroundColor: bg, marginBottom: 0 }]}><Ionicons name={icon} size={15} color={color} /></View>
        <Text style={styles.periodTitle}>{title}</Text>
      </View>
      <View style={styles.periodRow}>
        {cells.map(([lb, v], i) => (
          <View key={i} style={styles.periodCell}>
            <Text style={styles.periodCellLb}>{lb.toUpperCase()}</Text>
            <Text style={[styles.periodCellVal, { color }]}>{v ?? 0}</Text>
          </View>
        ))}
      </View>
    </View>
  );
}

/* Fondateur — Blog SEO auto (natif) + génération IA & programmation */
const BLOG_CATS = [
  { key: 'associations', label: '🏛️ Associations' },
  { key: 'tpe', label: '🛠️ TPE & indépendants' },
  { key: 'comptabilite', label: '📊 Comptabilité' },
  { key: 'juridique', label: '⚖️ Juridique' },
  { key: 'communication', label: '📣 Communication' },
  { key: 'gestion', label: '📋 Gestion' },
];
const BLOG_ART_FILTERS = [{ key: 'all', label: 'Tous' }, { key: 'published', label: 'Publiés' }, { key: 'draft', label: 'Brouillons' }];
const BLOG_QTY = [1, 5, 10, 15, 20];
function NativeFounderBlog({ data, loading, filter, onFilter, onBack, onRefresh, onWeb, onGenerate, onBulk, onProgram, onDeleteTopic, genBusy, genMsg, topicBusy, onClearMsg }) {
  const s = (data && data.stats) || {};
  const list = data ? (data.articles || []) : null;
  const queue = (data && data.queue) || [];
  const [open, setOpen] = useState(false);
  const [qty, setQty] = useState(1);
  const [subject, setSubject] = useState('');
  const [cat, setCat] = useState('associations');
  const [keywords, setKeywords] = useState('');
  const [publishNow, setPublishNow] = useState(false);
  const bulk = qty > 1;
  const canSubmit = subject.trim().length > (bulk ? 2 : 4);
  const close = () => { if (genBusy || topicBusy) return; setOpen(false); setQty(1); setSubject(''); setKeywords(''); setPublishNow(false); if (onClearMsg) onClearMsg(); };

  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Blog SEO" onBack={onBack} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 26 }} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <View style={styles.miniKpiRow}>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.total ?? 0}</Text><Text style={styles.miniKpiLbl}>Articles</Text></View>
          <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: '#047857' }]}>{s.published ?? 0}</Text><Text style={styles.miniKpiLbl}>Publiés</Text></View>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.queue ?? 0}</Text><Text style={styles.miniKpiLbl}>Programmés</Text></View>
        </View>

        <TouchableOpacity accessibilityRole="button" style={styles.blogGenBtn} activeOpacity={0.9} onPress={() => setOpen(true)}>
          <Ionicons name="sparkles" size={18} color="#fff" />
          <Text style={styles.blogGenTxt}>Générer un article IA</Text>
        </TouchableOpacity>

        {queue.length > 0 && (
          <>
            <Text style={[styles.fcSec, { marginTop: 22, marginBottom: 10, color: '#64748B' }]}>PROGRAMMÉS · FILE D'ATTENTE</Text>
            {queue.map((t) => (
              <View key={t.id} style={styles.blogQueueRow}>
                <View style={styles.blogQueueIc}><Ionicons name="time" size={15} color="#B45309" /></View>
                <View style={{ flex: 1, minWidth: 0 }}>
                  <Text style={styles.fcOrgName} numberOfLines={2}>{t.title}</Text>
                  <Text style={styles.fcOrgSub}>{[t.category, 'priorité ' + t.priority].filter(Boolean).join(' · ')}</Text>
                </View>
                <TouchableOpacity accessibilityRole="button" accessibilityLabel="Supprimer" hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }} onPress={() => onDeleteTopic(t.id)}>
                  <Ionicons name="trash-outline" size={18} color="#DC2626" />
                </TouchableOpacity>
              </View>
            ))}
          </>
        )}

        <Text style={[styles.fcSec, { marginTop: 22, marginBottom: 10, color: '#64748B' }]}>ARTICLES</Text>
        <View style={styles.fcFilters2}>
          {BLOG_ART_FILTERS.map((f) => (
            <TouchableOpacity accessibilityRole="button" key={f.key} style={[styles.fcFilter, filter === f.key && styles.fcFilterOn]} activeOpacity={0.8} onPress={() => onFilter(f.key)}>
              <Text style={[styles.fcFilterTxt, filter === f.key && styles.fcFilterTxtOn]}>{f.label}</Text>
            </TouchableOpacity>
          ))}
        </View>
        {!list ? (
          <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
        ) : list.length === 0 ? (
          <View style={styles.emptyBox}><Ionicons name="newspaper-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun article</Text></View>
        ) : list.map((a) => (
          <TouchableOpacity accessibilityRole="button" key={a.id} style={[styles.fcArtCard, { marginTop: 10 }]} activeOpacity={a.url ? 0.85 : 1} onPress={() => a.url && onWeb(a.url)}>
            <View style={[styles.fcArtIc, { backgroundColor: a.published ? '#ECFDF5' : '#F1F5F9' }]}>
              <Ionicons name={a.published ? 'newspaper' : 'document-text-outline'} size={18} color={a.published ? '#059669' : '#94A3B8'} />
            </View>
            <View style={{ flex: 1, minWidth: 0 }}>
              <Text style={styles.fcOrgName} numberOfLines={2}>{a.title}</Text>
              <Text style={styles.fcOrgSub} numberOfLines={1}>{[a.category, a.reading ? a.reading + ' min' : ''].filter(Boolean).join(' · ')}</Text>
              <Text style={styles.blogArtDate}><Ionicons name={a.published ? 'calendar-outline' : 'create-outline'} size={11} color="#94A3B8" /> {a.published ? ('Publié le ' + (a.pub_date || a.date)) : ('Créé le ' + a.date)}</Text>
            </View>
            <View style={[styles.fcChip, { backgroundColor: a.published ? '#D1FAE5' : '#FEF3C7' }]}>
              <Text style={[styles.fcChipTxt, { color: a.published ? '#047857' : '#B45309' }]}>{a.published ? 'Publié' : 'Brouillon'}</Text>
            </View>
          </TouchableOpacity>
        ))}
      </ScrollView>

      <Modal visible={open} transparent animationType="slide" onRequestClose={close}>
        <View style={styles.blogModalWrap}>
          <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
            <View style={styles.blogModal}>
              <View style={styles.blogModalHandle} />
              <View style={styles.blogModalHead}>
                <Text style={styles.blogModalTitle}><Ionicons name="sparkles-outline" size={17} color={INK} /> Génération IA</Text>
                <TouchableOpacity accessibilityRole="button" accessibilityLabel="Fermer" onPress={close} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}><Ionicons name="close" size={22} color="#94A3B8" /></TouchableOpacity>
              </View>

              {genBusy ? (
                <View style={styles.blogBusy}>
                  <ActivityIndicator size="large" color={BRAND} />
                  <Text style={styles.blogBusyTxt}>{bulk ? 'Recherche de ' + qty + ' sujets par l\'IA…' : 'Rédaction de l\'article par l\'IA…'}</Text>
                  <Text style={styles.blogBusySub}>{bulk ? 'Les articles seront ensuite rédigés automatiquement.' : 'Cela peut prendre jusqu\'à 2 minutes, ne ferme pas l\'app.'}</Text>
                </View>
              ) : genMsg && genMsg.ok ? (
                <View style={styles.blogBusy}>
                  <View style={styles.blogOkIc}><Ionicons name="checkmark" size={30} color="#fff" /></View>
                  {genMsg.bulk ? (
                    <>
                      <Text style={styles.blogBusyTxt}>{genMsg.added} article{genMsg.added > 1 ? 's' : ''} programmé{genMsg.added > 1 ? 's' : ''} !</Text>
                      <Text style={styles.blogBusySub}>Ils seront rédigés automatiquement par l'IA et apparaîtront en brouillon dans la liste.</Text>
                    </>
                  ) : (
                    <>
                      <Text style={styles.blogBusyTxt}>Article {genMsg.published ? 'publié' : 'créé (brouillon)'} !</Text>
                      <Text style={styles.blogBusySub} numberOfLines={2}>{genMsg.title}</Text>
                    </>
                  )}
                  <View style={{ flexDirection: 'row', gap: 10, marginTop: 16 }}>
                    {genMsg.url ? <TouchableOpacity accessibilityRole="button" style={styles.blogSecBtn} onPress={() => onWeb(genMsg.url)}><Text style={styles.blogSecTxt}>Voir</Text></TouchableOpacity> : null}
                    <TouchableOpacity accessibilityRole="button" style={styles.blogPrimBtn} onPress={close}><Text style={styles.blogPrimTxt}>Terminé</Text></TouchableOpacity>
                  </View>
                </View>
              ) : (
                <ScrollView keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
                  {genMsg && !genMsg.ok ? (
                    <View style={styles.lgError}><Ionicons name="alert-circle" size={16} color="#DC2626" /><Text style={styles.lgErrorTxt}>{genMsg.error}</Text></View>
                  ) : null}
                  <Text style={styles.blogLabel}>Nombre d'articles</Text>
                  <View style={styles.blogCats}>
                    {BLOG_QTY.map((q) => (
                      <TouchableOpacity accessibilityRole="button" key={q} style={[styles.blogQty, qty === q && styles.blogQtyOn]} activeOpacity={0.8} onPress={() => setQty(q)}>
                        <Text style={[styles.blogQtyTxt, qty === q && styles.blogQtyTxtOn]}>{q}</Text>
                      </TouchableOpacity>
                    ))}
                  </View>
                  <Text style={styles.blogLabel}>{bulk ? 'Thème (l\'IA trouvera les sujets)' : 'Sujet de l\'article'}</Text>
                  <TextInput style={styles.blogInput} value={subject} onChangeText={setSubject} placeholder={bulk ? 'Ex : financement et subventions des associations' : 'Ex : Comment déclarer une association loi 1901 en 2026'} placeholderTextColor="#9AA7A1" multiline />
                  <Text style={styles.blogLabel}>Catégorie{bulk ? ' (optionnel)' : ''}</Text>
                  <View style={styles.blogCats}>
                    {BLOG_CATS.map((c) => (
                      <TouchableOpacity accessibilityRole="button" key={c.key} style={[styles.blogCat, cat === c.key && styles.blogCatOn]} activeOpacity={0.8} onPress={() => setCat(c.key)}>
                        <Text style={[styles.blogCatTxt, cat === c.key && styles.blogCatTxtOn]}>{c.label}</Text>
                      </TouchableOpacity>
                    ))}
                  </View>
                  {!bulk && (
                    <>
                      <Text style={styles.blogLabel}>Mots-clés SEO <Text style={{ color: '#94A3B8', fontWeight: '400' }}>(optionnel)</Text></Text>
                      <TextInput style={styles.blogInput} value={keywords} onChangeText={setKeywords} placeholder="séparés par des virgules" placeholderTextColor="#9AA7A1" autoCapitalize="none" />
                      <View style={styles.blogSwitchRow}>
                        <View style={{ flex: 1 }}>
                          <Text style={styles.blogSwitchTitle}>Publier tout de suite</Text>
                          <Text style={styles.blogSwitchSub}>Sinon, l'article est créé en brouillon</Text>
                        </View>
                        <Switch value={publishNow} onValueChange={setPublishNow} trackColor={{ true: BRAND, false: '#CBD5E1' }} />
                      </View>
                    </>
                  )}

                  {bulk ? (
                    <>
                      <TouchableOpacity accessibilityRole="button" style={[styles.blogGenBtn, { marginTop: 18 }, !canSubmit && { opacity: 0.5 }]} activeOpacity={0.9}
                        disabled={!canSubmit} onPress={() => onBulk({ theme: subject.trim(), count: qty, category: cat })}>
                        <Ionicons name="sparkles" size={18} color="#fff" />
                        <Text style={styles.blogGenTxt}>Créer {qty} articles</Text>
                      </TouchableOpacity>
                      <Text style={styles.blogHint}>L'IA propose {qty} sujets sur ce thème et les met en file : le site rédige ensuite chaque article automatiquement (ils apparaissent en brouillon).</Text>
                    </>
                  ) : (
                    <>
                      <TouchableOpacity accessibilityRole="button" style={[styles.blogGenBtn, { marginTop: 18 }, !canSubmit && { opacity: 0.5 }]} activeOpacity={0.9}
                        disabled={!canSubmit} onPress={() => onGenerate({ topic_title: subject.trim(), category: cat, keywords: keywords.trim(), is_published: publishNow ? 1 : 0 })}>
                        <Ionicons name="sparkles" size={18} color="#fff" />
                        <Text style={styles.blogGenTxt}>Générer maintenant</Text>
                      </TouchableOpacity>
                      <TouchableOpacity accessibilityRole="button" style={[styles.blogProgBtn, !canSubmit && { opacity: 0.5 }]} activeOpacity={0.9}
                        disabled={!canSubmit || topicBusy} onPress={() => { onProgram({ topic_title: subject.trim(), category: cat, keywords: keywords.trim(), priority: 5 }); close(); }}>
                        {topicBusy ? <ActivityIndicator color={BRAND} /> : <><Ionicons name="time" size={17} color={BRAND} /><Text style={styles.blogProgTxt}>Programmer (généré auto plus tard)</Text></>}
                      </TouchableOpacity>
                      <Text style={styles.blogHint}>« Programmer » ajoute le sujet à la file : le site le rédige automatiquement ensuite.</Text>
                    </>
                  )}
                </ScrollView>
              )}
            </View>
          </KeyboardAvoidingView>
        </View>
      </Modal>
    </View>
  );
}

/* Fondateur — Support plateforme (natif) */
const FC_SUP_FILTERS = [{ key: 'open', label: 'Ouverts' }, { key: 'all', label: 'Tous' }];
function NativeFounderSupport({ data, loading, filter, onFilter, onBack, onRefresh, onOpen }) {
  const s = (data && data.summary) || {};
  const list = data ? (data.tickets || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Support" onBack={onBack} />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 26 }} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <View style={styles.miniKpiRow}>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.open ?? 0}</Text><Text style={styles.miniKpiLbl}>Ouverts</Text></View>
          <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: (s.unread > 0) ? '#DC2626' : INK }]}>{s.unread ?? 0}</Text><Text style={styles.miniKpiLbl}>Non lus</Text></View>
        </View>
        <View style={styles.fcFilters2}>
          {FC_SUP_FILTERS.map((f) => (
            <TouchableOpacity accessibilityRole="button" key={f.key} style={[styles.fcFilter, filter === f.key && styles.fcFilterOn]} activeOpacity={0.8} onPress={() => onFilter(f.key)}>
              <Text style={[styles.fcFilterTxt, filter === f.key && styles.fcFilterTxtOn]}>{f.label}</Text>
            </TouchableOpacity>
          ))}
        </View>
        {!list ? (
          <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
        ) : list.length === 0 ? (
          <View style={styles.emptyBox}><Ionicons name="chatbubbles-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun ticket</Text></View>
        ) : list.map((t) => {
          const km = INV_KIND[t.status_kind] || INV_KIND.wait;
          return (
            <TouchableOpacity accessibilityRole="button" key={t.id} style={styles.fcTicketCard} activeOpacity={0.85} onPress={() => onOpen && onOpen(t)}>
              <View style={{ flex: 1, minWidth: 0, paddingRight: 10 }}>
                <Text style={styles.fcOrgName} numberOfLines={1}>{t.title}</Text>
                <Text style={styles.fcOrgSub} numberOfLines={1}>{t.org} · {t.date}</Text>
              </View>
              {t.unread > 0 && <View style={styles.fcUnread}><Text style={styles.fcUnreadTxt}>{t.unread}</Text></View>}
              <View style={[styles.fcChip, { backgroundColor: km.bg }]}><Text style={[styles.fcChipTxt, { color: km.color }]}>{t.status_label}</Text></View>
            </TouchableOpacity>
          );
        })}
      </ScrollView>
    </View>
  );
}

/* Fondateur — Fil d'un ticket support + réponse native */
function NativeFounderSupportThread({ data, loading, onBack, onRefresh, onReply, replyBusy }) {
  const t = data ? data.ticket : null;
  const msgs = (data && data.messages) || [];
  const [body, setBody] = useState('');
  const send = () => { if (body.trim().length < 2 || replyBusy) return; onReply(t.id, body.trim()); setBody(''); };
  return (
    <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={0}>
      <DetailHeader title="Ticket support" onBack={onBack} />
      {!data || !t ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <>
          <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 16, paddingBottom: 16 }} showsVerticalScrollIndicator={false}
            keyboardShouldPersistTaps="handled"
            refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
            <View style={styles.supHead}>
              <Text style={styles.supTitle}>{t.title}</Text>
              <Text style={styles.supMeta}>{t.org} · {t.category} · {t.date}</Text>
            </View>
            {msgs.map((m) => (
              <View key={m.id} style={[styles.supMsgRow, m.side === 'support' ? styles.supMsgRight : styles.supMsgLeft]}>
                <View style={[styles.supBubble, m.note ? styles.supNote : (m.side === 'support' ? styles.supBubbleMe : styles.supBubbleOrg)]}>
                  {m.note ? <Text style={styles.supNoteLbl}>Note interne</Text> : null}
                  <Text style={[styles.supBody, m.side === 'support' && !m.note ? { color: '#fff' } : null]}>{m.body}</Text>
                  <Text style={[styles.supAt, m.side === 'support' && !m.note ? { color: 'rgba(255,255,255,0.7)' } : null]}>{m.at}</Text>
                </View>
              </View>
            ))}
          </ScrollView>
          {t.closed ? (
            <View style={styles.supClosed}><Text style={styles.supClosedTxt}>Ticket fermé</Text></View>
          ) : (
            <View style={styles.supInputBar}>
              <TextInput style={styles.supInput} value={body} onChangeText={setBody} placeholder="Répondre à l'association…" placeholderTextColor="#9AA7A1" multiline />
              <TouchableOpacity accessibilityRole="button" accessibilityLabel="Envoyer" style={[styles.supSend, (body.trim().length < 2 || replyBusy) && { opacity: 0.5 }]} onPress={send} disabled={body.trim().length < 2 || replyBusy}>
                {replyBusy ? <ActivityIndicator size="small" color="#fff" /> : <Ionicons name="send" size={18} color="#fff" />}
              </TouchableOpacity>
            </View>
          )}
        </>
      )}
    </KeyboardAvoidingView>
  );
}

/* Fondateur — Créer une association / TPE (natif) — parité complète avec le web */
const PAY_MODES = [
  { key: 'free_grant', label: 'Subvention gratuite', sub: 'Accès offert, aucun paiement' },
  { key: 'manual', label: 'Paiement manuel', sub: 'Encaissé hors plateforme' },
  { key: 'wire_transfer', label: 'Virement bancaire', sub: 'En attente du virement' },
  { key: 'stripe', label: 'Stripe (le client paie)', sub: 'Activation après paiement' },
];
const PERIODS = [
  { d: 14, label: '14 j' }, { d: 30, label: '30 j' }, { d: 40, label: '40 j' },
  { d: 90, label: '3 mois' }, { d: 180, label: '6 mois' }, { d: 365, label: '1 an' }, { d: 3650, label: 'Illimité' },
];
function NativeFounderCreateOrg({ plans, busy, result, error, onSubmit, onBack, onCopy, onDone }) {
  const [name, setName] = useState('');
  const [first, setFirst] = useState('');
  const [last, setLast] = useState('');
  const [email, setEmail] = useState('');
  const [planId, setPlanId] = useState(0);
  const [pass, setPass] = useState('');
  const [payMode, setPayMode] = useState('free_grant');
  const [periodDays, setPeriodDays] = useState(30);
  const [addonDomain, setAddonDomain] = useState(false);
  const [sendMail, setSendMail] = useState(true);
  const list = plans || [];
  const canSubmit = name.trim().length > 1 && first.trim().length > 0 && /\S+@\S+\.\S+/.test(email) && planId > 0 && !busy;

  if (result && result.ok) {
    return (
      <View style={styles.detailWrap}>
        <DetailHeader title="Organisation créée" onBack={onDone} />
        <ScrollView contentContainerStyle={{ padding: 18 }}>
          <View style={styles.blogBusy}>
            <View style={styles.blogOkIc}><Ionicons name="checkmark" size={30} color="#fff" /></View>
            <Text style={styles.blogBusyTxt}>{result.org_name} est créée !</Text>
            <Text style={styles.blogBusySub}>{result.email_sent ? 'Email de bienvenue envoyé.' : 'Transmets ces identifiants au responsable :'}</Text>
          </View>
          <View style={styles.credCard}>
            <Text style={styles.credLbl}>Email</Text><Text style={styles.credVal} selectable>{result.email}</Text>
            <View style={styles.credDivider} />
            <Text style={styles.credLbl}>Mot de passe</Text><Text style={styles.credVal} selectable>{result.password}</Text>
          </View>
          <TouchableOpacity accessibilityRole="button" style={styles.lgBtn} activeOpacity={0.9} onPress={() => onCopy(result.email + ' / ' + result.password)}>
            <Ionicons name="copy" size={17} color="#fff" /><Text style={styles.lgBtnTxt}>Copier les identifiants</Text>
          </TouchableOpacity>
          <TouchableOpacity accessibilityRole="button" style={{ alignSelf: 'center', paddingVertical: 14 }} onPress={onDone}><Text style={styles.lgForgot}>Terminé</Text></TouchableOpacity>
        </ScrollView>
      </View>
    );
  }

  return (
    <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <DetailHeader title="Nouvelle organisation" onBack={onBack} />
      <ScrollView contentContainerStyle={{ padding: 18, paddingBottom: 40 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
        {error ? <View style={styles.lgError}><Ionicons name="alert-circle" size={16} color="#DC2626" /><Text style={styles.lgErrorTxt}>{error}</Text></View> : null}
        <Text style={styles.blogLabel}>Nom de l'association / TPE</Text>
        <TextInput style={styles.blogInput} value={name} onChangeText={setName} placeholder="Ex : Les Amis du Parc" placeholderTextColor="#9AA7A1" />
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <View style={{ flex: 1 }}><Text style={styles.blogLabel}>Prénom</Text><TextInput style={styles.blogInput} value={first} onChangeText={setFirst} placeholder="Prénom" placeholderTextColor="#9AA7A1" /></View>
          <View style={{ flex: 1 }}><Text style={styles.blogLabel}>Nom</Text><TextInput style={styles.blogInput} value={last} onChangeText={setLast} placeholder="Nom" placeholderTextColor="#9AA7A1" /></View>
        </View>
        <Text style={styles.blogLabel}>Email du responsable</Text>
        <TextInput style={styles.blogInput} value={email} onChangeText={setEmail} placeholder="responsable@asso.fr" placeholderTextColor="#9AA7A1" keyboardType="email-address" autoCapitalize="none" autoCorrect={false} />
        <Text style={styles.blogLabel}>Mot de passe <Text style={{ color: '#9AA7A1', fontWeight: '400' }}>(optionnel)</Text></Text>
        <TextInput style={styles.blogInput} value={pass} onChangeText={setPass} placeholder="Laisser vide = généré automatiquement" placeholderTextColor="#9AA7A1" autoCapitalize="none" autoCorrect={false} />

        <Text style={styles.blogLabel}>Formule</Text>
        <View style={styles.blogCats}>
          {list.map((p) => (
            <TouchableOpacity accessibilityRole="button" key={p.id} style={[styles.planChip, planId === p.id && styles.planChipOn]} activeOpacity={0.85} onPress={() => setPlanId(p.id)}>
              <Text style={[styles.planChipName, planId === p.id && { color: '#fff' }]}>{p.name}</Text>
              <Text style={[styles.planChipPrice, planId === p.id && { color: 'rgba(255,255,255,0.85)' }]}>{p.is_trial ? 'Essai' : (p.price > 0 ? fmtEuro(p.price) + '/mois' : 'Gratuit')}</Text>
            </TouchableOpacity>
          ))}
        </View>

        <Text style={styles.blogLabel}>Mode de paiement</Text>
        <View style={{ gap: 8 }}>
          {PAY_MODES.map((m) => (
            <TouchableOpacity accessibilityRole="button" key={m.key} style={[styles.payRow, payMode === m.key && styles.payRowOn]} activeOpacity={0.85} onPress={() => setPayMode(m.key)}>
              <Ionicons name={payMode === m.key ? 'radio-button-on' : 'radio-button-off'} size={20} color={payMode === m.key ? BRAND : '#B8C4C0'} />
              <View style={{ flex: 1 }}>
                <Text style={[styles.payLbl, payMode === m.key && { color: BRAND }]}>{m.label}</Text>
                <Text style={styles.paySub}>{m.sub}</Text>
              </View>
            </TouchableOpacity>
          ))}
        </View>

        <Text style={styles.blogLabel}>Durée d'activation</Text>
        <View style={styles.blogCats}>
          {PERIODS.map((pr) => (
            <TouchableOpacity accessibilityRole="button" key={pr.d} style={[styles.planChip, periodDays === pr.d && styles.planChipOn]} activeOpacity={0.85} onPress={() => setPeriodDays(pr.d)}>
              <Text style={[styles.planChipName, periodDays === pr.d && { color: '#fff' }]}>{pr.label}</Text>
            </TouchableOpacity>
          ))}
        </View>

        <View style={styles.blogSwitchRow}>
          <View style={{ flex: 1 }}><Text style={styles.blogSwitchTitle}>Domaine personnalisé</Text><Text style={styles.blogSwitchSub}>Add-on marque blanche · +10 €/mois</Text></View>
          <Switch value={addonDomain} onValueChange={setAddonDomain} trackColor={{ true: BRAND, false: '#CBD5E1' }} />
        </View>
        <View style={styles.blogSwitchRow}>
          <View style={{ flex: 1 }}><Text style={styles.blogSwitchTitle}>Envoyer l'email de bienvenue</Text><Text style={styles.blogSwitchSub}>Avec les identifiants de connexion</Text></View>
          <Switch value={sendMail} onValueChange={setSendMail} trackColor={{ true: BRAND, false: '#CBD5E1' }} />
        </View>
        <TouchableOpacity accessibilityRole="button" style={[styles.lgBtn, !canSubmit && styles.lgBtnOff]} activeOpacity={0.9} disabled={!canSubmit}
          onPress={() => onSubmit({ org_name: name.trim(), first_name: first.trim(), last_name: last.trim(), billing_email: email.trim(), plan_id: planId, custom_password: pass.trim(), payment_mode: payMode, period_days: periodDays, with_addon_domain: addonDomain ? 1 : 0, send_welcome_email: sendMail ? 1 : 0 })}>
          {busy ? <ActivityIndicator color="#fff" /> : <><Ionicons name="add-circle" size={18} color="#fff" /><Text style={styles.lgBtnTxt}>Créer l'organisation</Text></>}
        </TouchableOpacity>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

/* Fondateur — Demandes de contact (prospects) : liste + réponse par email, natif */
function NativeFounderContacts({ data, loading, onBack, onRefresh, onOpen }) {
  const list = data ? (data.contacts || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Demandes de contact" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="mail-outline" size={42} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune demande</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {list.map((c) => (
            <TouchableOpacity accessibilityRole="button" key={c.id} style={styles.ctcCard} activeOpacity={0.85} onPress={() => onOpen(c)}>
              <View style={styles.ctcTop}>
                <View style={[styles.ctcAv, { backgroundColor: c.is_new ? '#EFF6FF' : '#F1F5F9' }]}><Ionicons name="person" size={17} color={c.is_new ? '#2563EB' : '#94A3B8'} /></View>
                <View style={{ flex: 1, minWidth: 0 }}>
                  <Text style={styles.fcOrgName} numberOfLines={1}>{c.name}{c.is_new ? '  🔵' : ''}</Text>
                  <Text style={styles.fcOrgSub} numberOfLines={1}>{[c.org, c.date].filter(Boolean).join(' · ')}</Text>
                </View>
                {c.replied ? <View style={[styles.fcChip, { backgroundColor: '#D1FAE5' }]}><Text style={[styles.fcChipTxt, { color: '#047857' }]}>Répondu</Text></View> : null}
              </View>
              {c.subject ? <Text style={styles.ctcSubject} numberOfLines={1}>{c.subject}</Text> : null}
              <Text style={styles.ctcMsg} numberOfLines={2}>{c.message}</Text>
            </TouchableOpacity>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

/* Fondateur — Fil d'échange avec un prospect (double-sens) : messages + réponse email */
function NativeFounderContactThread({ data, loading, onBack, onRefresh, onReply, replyBusy }) {
  const c = data ? data.contact : null;
  const msgs = (data && data.messages) || [];
  const [body, setBody] = useState('');
  const send = () => { if (body.trim().length < 2 || replyBusy) return; onReply(c.id, body.trim()); setBody(''); };
  return (
    <KeyboardAvoidingView style={styles.detailWrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={0}>
      <DetailHeader title="Prospect" onBack={onBack} />
      {!data || !c ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <>
          <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 16, paddingBottom: 16 }} showsVerticalScrollIndicator={false}
            keyboardShouldPersistTaps="handled"
            refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
            <View style={styles.supHead}>
              <Text style={styles.supTitle}>{c.name}</Text>
              <Text style={styles.supMeta}>{c.email}{c.org ? ' · ' + c.org : ''}{c.subject ? ' · ' + c.subject : ''}</Text>
            </View>
            {msgs.map((m, i) => (
              <View key={i} style={[styles.supMsgRow, m.side === 'out' ? styles.supMsgRight : styles.supMsgLeft]}>
                <View style={[styles.supBubble, m.side === 'out' ? styles.supBubbleMe : styles.supBubbleOrg]}>
                  <Text style={[styles.supBody, m.side === 'out' ? { color: '#fff' } : null]}>{m.body}</Text>
                  <Text style={[styles.supAt, m.side === 'out' ? { color: 'rgba(255,255,255,0.7)' } : null]}>{m.at}</Text>
                </View>
              </View>
            ))}
          </ScrollView>
          <View style={styles.supInputBar}>
            <TextInput style={styles.supInput} value={body} onChangeText={setBody} placeholder="Répondre par email…" placeholderTextColor="#9AA7A1" multiline />
            <TouchableOpacity accessibilityRole="button" accessibilityLabel="Envoyer" style={[styles.supSend, (body.trim().length < 2 || replyBusy) && { opacity: 0.5 }]} onPress={send} disabled={body.trim().length < 2 || replyBusy}>
              {replyBusy ? <ActivityIndicator size="small" color="#fff" /> : <Ionicons name="send" size={18} color="#fff" />}
            </TouchableOpacity>
          </View>
        </>
      )}
    </KeyboardAvoidingView>
  );
}

/* ================================================================== */
/*  DEVIS / STATS / NOTIFS / COTISATIONS / SUBVENTIONS (natifs)        */
/* ================================================================== */
function NativeQuotes({ data, loading, onRefresh, onOpen, onNew, onBack }) {
  if (data && data.allowed === false) return <GatedScreen title="Devis" message={data.message} onBack={onBack} />;
  const list = data ? (data.quotes || []) : null;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Devis" onBack={onBack} />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <TouchableOpacity accessibilityRole="button" style={[styles.projNewBtn, { alignSelf: 'flex-start', marginBottom: 14 }, !onNew && { display: 'none' }]} onPress={onNew} activeOpacity={0.85}>
            <Ionicons name="add" size={18} color="#fff" /><Text style={styles.projNewTxt}>Nouveau devis</Text>
          </TouchableOpacity>
          {list.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="document-text-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucun devis</Text></View>
          ) : list.map((q) => {
            const km = INV_KIND[q.status_kind] || INV_KIND.wait;
            return (
              <TouchableOpacity accessibilityRole="button" key={q.id} style={styles.invCard} activeOpacity={0.85} onPress={() => onOpen(q.id)}>
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
          <TouchableOpacity accessibilityRole="button" style={[styles.cockpitBtn, cockpitLoading ? { opacity: 0.7 } : null]} activeOpacity={0.85} onPress={cockpitLoading ? undefined : onCockpit}>
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

function NativeNotifications({ data, loading, onRefresh, onPress, onMarkAllRead, onBack }) {
  const list = data ? (data.items || []) : null;
  const unread = data ? (data.unread || 0) : 0;
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Notifications" onBack={onBack} />
      {unread > 0 ? (
        <TouchableOpacity accessibilityRole="button" style={styles.markAllBtn} activeOpacity={0.8} onPress={onMarkAllRead}>
          <Ionicons name="checkmark-done" size={16} color={BRAND} />
          <Text style={styles.markAllTxt}>Tout marquer comme lu ({unread})</Text>
        </TouchableOpacity>
      ) : null}
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}><Ionicons name="notifications-off-outline" size={44} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune notification</Text></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          {list.map((n) => (
            <TouchableOpacity accessibilityRole="button" key={n.id} style={[styles.notifCard, !n.read ? styles.notifUnread : null]} activeOpacity={0.85} onPress={() => onPress(n)}>
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

function NativeCotisations({ data, loading, onRefresh, onBack, onNew, onNewCampaign, canManage, onOpen }) {
  const list = data ? (data.campaigns || []) : null;
  const s = (data && data.stats) || {};
  const hasCampaigns = !!(list && list.length);
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Cotisations" onBack={onBack} onAction={canManage ? (hasCampaigns ? onNew : onNewCampaign) : null} actionIcon="add" />
      {!list ? (
        <View style={styles.homeLoader}><ActivityIndicator size="large" color={BRAND} /></View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 24 }} showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
          <View style={styles.miniKpiRow}>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro(s.total)}</Text><Text style={styles.miniKpiLbl}>Encaissé</Text></View>
            <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{s.active || 0}</Text><Text style={styles.miniKpiLbl}>Campagnes actives</Text></View>
          </View>
          {canManage && hasCampaigns && (
            <TouchableOpacity accessibilityRole="button" style={styles.listNewBtn} activeOpacity={0.85} onPress={onNew}>
              <Ionicons name="add-circle" size={19} color="#fff" /><Text style={styles.listNewTxt}>Enregistrer un paiement</Text>
            </TouchableOpacity>
          )}
          {canManage && hasCampaigns && (
            <TouchableOpacity accessibilityRole="button" style={styles.dWebBtn} activeOpacity={0.85} onPress={onNewCampaign}>
              <Ionicons name="pricetags-outline" size={18} color={BRAND} /><Text style={styles.dWebBtnTxt}>Nouvelle campagne</Text>
            </TouchableOpacity>
          )}
          {list.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="card-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune campagne</Text>
              <Text style={styles.emptySub}>Créez votre première campagne pour commencer à encaisser les cotisations.</Text>
              {canManage && (
                <TouchableOpacity accessibilityRole="button" style={[styles.listNewBtn, { marginTop: 16, paddingHorizontal: 20 }]} activeOpacity={0.85} onPress={onNewCampaign}>
                  <Ionicons name="add-circle" size={19} color="#fff" /><Text style={styles.listNewTxt}>Créer une campagne</Text>
                </TouchableOpacity>
              )}
            </View>
          ) : list.map((c) => (
            <TouchableOpacity accessibilityRole="button" key={c.id} style={styles.projCard} activeOpacity={onOpen ? 0.85 : 1} onPress={onOpen ? () => onOpen(c.id) : undefined}>
              <View style={styles.projCardTop}>
                <View style={{ flex: 1, paddingRight: 10 }}>
                  <Text style={styles.projName} numberOfLines={1}>{c.name}</Text>
                  <Text style={styles.projFolder}>{c.year} · {c.paid} payé{c.paid > 1 ? 's' : ''} · {c.pending} en attente</Text>
                </View>
                <View style={[styles.projChip, { backgroundColor: c.active ? '#D1FAE5' : '#F1F5F9' }]}>
                  <Text style={[styles.projChipTxt, { color: c.active ? '#065F46' : '#64748B' }]}>{c.active ? 'Active' : 'Clôturée'}</Text>
                </View>
              </View>
              <View style={[styles.dCardRow, { marginTop: 10 }]}>
                <Text style={[styles.dTotal, { fontSize: 18 }]}>{fmtEuro(c.total)}</Text>
                {onOpen ? <Ionicons name="chevron-forward" size={18} color="#94A3B8" /> : null}
              </View>
            </TouchableOpacity>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

function NativeGrants({ data, loading, onRefresh, onBack, onNew, canManage, onOpen }) {
  const list = data ? (data.grants || []) : null;
  const s = (data && data.stats) || {};
  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Subventions" onBack={onBack} onAction={canManage ? onNew : null} actionIcon="add" />
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
          {canManage && (
            <TouchableOpacity accessibilityRole="button" style={styles.listNewBtn} activeOpacity={0.85} onPress={onNew}>
              <Ionicons name="add-circle" size={19} color="#fff" /><Text style={styles.listNewTxt}>Nouvelle demande</Text>
            </TouchableOpacity>
          )}
          {list.length === 0 ? (
            <View style={styles.emptyBox}><Ionicons name="cash-outline" size={40} color="#CBD5E1" /><Text style={styles.emptyTxt}>Aucune subvention</Text></View>
          ) : list.map((g) => {
            const km = INV_KIND[g.status_kind] || INV_KIND.wait;
            return (
              <TouchableOpacity accessibilityRole="button" key={g.id} style={styles.projCard} activeOpacity={onOpen ? 0.85 : 1} onPress={onOpen ? () => onOpen(g.id) : undefined}>
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
              </TouchableOpacity>
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
        <TouchableOpacity accessibilityRole="button" style={styles.dWebBtn} activeOpacity={0.85} onPress={() => onWeb('/evenement/' + e.id)}>
          <Text style={styles.dWebBtnTxt}>Ouvrir la fiche complète</Text>
          <Ionicons name="open-outline" size={18} color={BRAND} />
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

/* ── Détail d'une campagne de cotisation (natif) ─────────────────────── */
function NativeCotisationDetail({ entry, onBack, onRefresh, onAction, onNewPayment, busy }) {
  const d = entry.data;
  if (d && d.ok === false) return <DetailError title="Campagne" onBack={onBack} onRetry={onRefresh} />;
  if (!d || !d.campaign) return <DetailLoading title="Campagne" onBack={onBack} />;
  const c = d.campaign;
  const s = d.stats || {};
  const tiers = d.tiers || [];
  const payments = d.payments || [];
  const canManage = d.can_manage !== false;

  const confirmAct = (p, act) => {
    const isCancel = act === 'cancel';
    Alert.alert(
      isCancel ? 'Annuler le paiement' : 'Encaissement',
      isCancel
        ? 'Annuler le paiement de ' + p.name + ' ? Il ne sera plus compté dans les encaissements.'
        : 'Confirmer la réception de ' + fmtEuro2(p.amount) + ' de la part de ' + p.name + ' ?',
      [
        { text: 'Retour', style: 'cancel' },
        { text: isCancel ? 'Annuler le paiement' : 'Confirmer', style: isCancel ? 'destructive' : 'default', onPress: () => onAction(p.id, act) },
      ]
    );
  };

  return (
    <View style={styles.detailWrap}>
      <DetailHeader title={c.name} onBack={onBack} onAction={canManage ? onNewPayment : null} actionIcon="add" />
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 30 }} showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!entry.loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <View style={[styles.projChip, { backgroundColor: c.active ? '#D1FAE5' : '#F1F5F9', alignSelf: 'flex-start' }]}>
          <Text style={[styles.projChipTxt, { color: c.active ? '#065F46' : '#64748B' }]}>{c.active ? 'Campagne active' : 'Clôturée'}{c.year ? ' · ' + c.year : ''}</Text>
        </View>
        {!!c.closes_at && <Text style={[styles.dMuted, { marginTop: 10 }]}>Clôture le {c.closes_at}</Text>}
        {!!c.description && <DescBlock label="Description" text={c.description} tint="#2563EB" />}

        <View style={[styles.miniKpiRow, { marginTop: 16 }]}>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{fmtEuro2(s.amount_paid)}</Text><Text style={styles.miniKpiLbl}>Encaissé · {s.count_paid || 0}</Text></View>
          <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: '#B45309' }]}>{fmtEuro2(s.amount_pending)}</Text><Text style={styles.miniKpiLbl}>En attente · {s.count_pending || 0}</Text></View>
        </View>

        {tiers.length > 0 && (
          <>
            <Text style={styles.dSection}>Tarifs proposés</Text>
            {tiers.map((t) => (
              <View key={t.id} style={styles.dInfoRow}>
                <Text style={styles.dLabel}>{t.name}</Text>
                <Text style={styles.dValue}>{fmtEuro2(t.amount)}</Text>
              </View>
            ))}
          </>
        )}

        <Text style={styles.dSection}>Paiements ({payments.length})</Text>
        {payments.length === 0 ? (
          <Text style={styles.dMuted}>Aucun paiement enregistré pour le moment.</Text>
        ) : payments.map((p) => (
          <View key={p.id} style={styles.ckPayRow}>
            <View style={{ flex: 1 }}>
              <Text style={styles.ckPayName} numberOfLines={1}>{p.name}</Text>
              <Text style={styles.ckPaySub} numberOfLines={1}>
                {[p.method_label, p.tier, p.paid_at || p.created_at].filter(Boolean).join(' · ')}
              </Text>
              <View style={[styles.projChip, { backgroundColor: p.status_bg, alignSelf: 'flex-start', marginTop: 6 }]}>
                <Text style={[styles.projChipTxt, { color: p.status_color }]}>{p.status_label}</Text>
              </View>
            </View>
            <Text style={styles.ckPayAmt}>{fmtEuro2(p.amount)}</Text>
            {canManage && p.status === 'pending' && (
              <TouchableOpacity accessibilityRole="button" accessibilityLabel="Marquer encaissé" style={styles.payAct} activeOpacity={0.8}
                disabled={!!busy} onPress={() => confirmAct(p, 'mark_paid')}>
                {busy === p.id ? <ActivityIndicator size="small" color="#065F46" /> : <Ionicons name="checkmark" size={20} color="#065F46" />}
              </TouchableOpacity>
            )}
            {d.is_admin && p.status !== 'cancelled' && (
              <TouchableOpacity accessibilityRole="button" accessibilityLabel="Annuler le paiement" style={[styles.payAct, { backgroundColor: '#FEF2F2', borderColor: '#FECACA' }]}
                activeOpacity={0.8} disabled={!!busy} onPress={() => confirmAct(p, 'cancel')}>
                {busy === p.id ? <ActivityIndicator size="small" color="#991B1B" /> : <Ionicons name="close" size={19} color="#991B1B" />}
              </TouchableOpacity>
            )}
          </View>
        ))}

        {canManage && (
          <TouchableOpacity accessibilityRole="button" style={styles.dPrimaryBtn} activeOpacity={0.85} onPress={onNewPayment}>
            <Ionicons name="add-circle" size={18} color="#fff" />
            <Text style={styles.dPrimaryBtnTxt}>Enregistrer un paiement</Text>
          </TouchableOpacity>
        )}
      </ScrollView>
    </View>
  );
}

/* ── Détail d'une subvention (natif) ─────────────────────────────────── */
const GRANT_STATUSES = [
  { value: 'draft', label: 'Brouillon' }, { value: 'submitted', label: 'Déposé' },
  { value: 'in_review', label: 'Instruction' }, { value: 'granted', label: 'Accordé' },
  { value: 'rejected', label: 'Refusé' }, { value: 'reported', label: 'Bilan rendu' },
];
function NativeGrantDetail({ entry, onBack, onRefresh, onAction, busy }) {
  const [stepTitle, setStepTitle] = useState('');
  const [statusOpen, setStatusOpen] = useState(false);
  const [grantedOpen, setGrantedOpen] = useState(false);
  const [grantedAmt, setGrantedAmt] = useState('');
  const d = entry.data;
  if (d && d.ok === false) return <DetailError title="Subvention" onBack={onBack} onRetry={onRefresh} />;
  if (!d || !d.grant) return <DetailLoading title="Subvention" onBack={onBack} />;
  const g = d.grant;
  const steps = d.steps || [];
  const activity = d.activity || [];
  const canManage = d.can_manage !== false;
  const km = INV_KIND[g.status_kind] || INV_KIND.wait;
  const doneCount = steps.filter((s) => s.done).length;

  const addStep = () => {
    const t = stepTitle.trim();
    if (!t) return;
    setStepTitle('');
    onAction('add_step', { title: t });
  };

  return (
    <View style={styles.detailWrap}>
      <DetailHeader title="Subvention" onBack={onBack} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 30 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={!!entry.loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}>
        <Text style={styles.dBigTitle}>{g.name}</Text>
        <Text style={[styles.dMuted, { marginTop: 4 }]}>{[g.funder, g.funder_type].filter(Boolean).join(' · ')}</Text>
        <View style={[styles.projChip, { backgroundColor: km.bg, alignSelf: 'flex-start', marginTop: 12 }]}>
          <Text style={[styles.projChipTxt, { color: km.color }]}>{g.status_label}</Text>
        </View>

        <View style={[styles.miniKpiRow, { marginTop: 16 }]}>
          <View style={styles.miniKpi}><Text style={styles.miniKpiVal}>{g.requested != null ? fmtEuro(g.requested) : '—'}</Text><Text style={styles.miniKpiLbl}>Demandé</Text></View>
          <View style={styles.miniKpi}><Text style={[styles.miniKpiVal, { color: g.granted ? BRAND : INK }]}>{g.granted != null ? fmtEuro(g.granted) : '—'}</Text><Text style={styles.miniKpiLbl}>Accordé</Text></View>
        </View>

        <Text style={styles.dSection}>Dossier</Text>
        {!!g.deadline && <View style={styles.dInfoRow}><Text style={styles.dLabel}>Date limite de dépôt</Text><Text style={styles.dValue}>{g.deadline}</Text></View>}
        {!!g.submitted_at && <View style={styles.dInfoRow}><Text style={styles.dLabel}>Déposé le</Text><Text style={styles.dValue}>{g.submitted_at}</Text></View>}
        {!!g.decision_at && <View style={styles.dInfoRow}><Text style={styles.dLabel}>Décision</Text><Text style={styles.dValue}>{g.decision_at}</Text></View>}
        {!!g.deadline_report && <View style={styles.dInfoRow}><Text style={styles.dLabel}>Bilan attendu</Text><Text style={styles.dValue}>{g.deadline_report}</Text></View>}
        {!!g.project && <View style={styles.dInfoRow}><Text style={styles.dLabel}>Projet lié</Text><Text style={styles.dValue}>{g.project}</Text></View>}
        {!!g.cerfa && <View style={styles.dInfoRow}><Text style={styles.dLabel}>CERFA</Text><Text style={styles.dValue}>{g.cerfa}</Text></View>}
        {!!g.reference && <View style={styles.dInfoRow}><Text style={styles.dLabel}>Référence</Text><Text style={styles.dValue}>{g.reference}</Text></View>}
        {!!g.last_relance && <View style={styles.dInfoRow}><Text style={styles.dLabel}>Dernière relance</Text><Text style={styles.dValue}>{g.last_relance}</Text></View>}
        {!!g.description && <DescBlock label="Objet" text={g.description} tint="#2563EB" />}

        <Text style={styles.dSection}>Étapes {steps.length > 0 ? '(' + doneCount + '/' + steps.length + ')' : ''}</Text>
        {steps.length === 0 && <Text style={styles.dMuted}>Aucune étape. Ajoutez votre checklist ci-dessous.</Text>}
        {steps.map((s) => (
          <TouchableOpacity accessibilityRole="checkbox" accessibilityState={{ checked: !!s.done }} key={s.id} style={styles.grStepRow}
            activeOpacity={canManage ? 0.7 : 1} disabled={!canManage || !!busy} onPress={() => onAction('toggle_step', { step_id: s.id })}>
            <Ionicons name={s.done ? 'checkbox' : 'square-outline'} size={23} color={s.done ? BRAND : '#CBD5E1'} />
            <Text style={[styles.grStepTxt, s.done && styles.grStepTxtDone]}>{s.title}</Text>
            {!!s.done_at && <Text style={styles.ckPaySub}>{s.done_at}</Text>}
          </TouchableOpacity>
        ))}
        {canManage && (
          <View style={[styles.formRow2, { marginTop: 12, alignItems: 'center' }]}>
            <TextInput style={[styles.fInput, { flex: 1 }]} value={stepTitle} onChangeText={setStepTitle}
              placeholder="Ajouter une étape…" placeholderTextColor="#B6C0CC" accessibilityLabel="Nouvelle étape" onSubmitEditing={addStep} returnKeyType="done" />
            <TouchableOpacity accessibilityRole="button" accessibilityLabel="Ajouter l'étape" style={[styles.payAct, { marginLeft: 8 }]}
              activeOpacity={0.8} disabled={!stepTitle.trim() || !!busy} onPress={addStep}>
              <Ionicons name="add" size={22} color="#065F46" />
            </TouchableOpacity>
          </View>
        )}

        {canManage && !g.archived && (
          <>
            <Text style={styles.dSection}>Actions</Text>
            <TouchableOpacity accessibilityRole="button" style={[styles.dActBtn, { marginTop: 0 }, busy ? { opacity: 0.6 } : null]} activeOpacity={0.85}
              disabled={!!busy} onPress={() => setStatusOpen(true)}>
              <Ionicons name="flag" size={18} color="#065F46" />
              <Text style={styles.dActBtnTxt}>Changer le statut</Text>
            </TouchableOpacity>
            <TouchableOpacity accessibilityRole="button" style={[styles.dActBtn, busy ? { opacity: 0.6 } : null]} activeOpacity={0.85}
              disabled={!!busy} onPress={() => Alert.alert('Relance', 'Enregistrer une relance envoyée au financeur ' + (g.contact_email ? '(' + g.contact_email + ')' : '') + ' ?', [
                { text: 'Retour', style: 'cancel' },
                { text: 'Enregistrer', onPress: () => onAction('log_relance') },
              ])}>
              {busy === 'log_relance' ? <ActivityIndicator color="#065F46" /> : <Ionicons name="megaphone" size={18} color="#065F46" />}
              <Text style={styles.dActBtnTxt}>Journaliser une relance</Text>
            </TouchableOpacity>
            {d.is_admin && (
              <TouchableOpacity accessibilityRole="button" style={[styles.dDangerBtn, busy ? { opacity: 0.6 } : null]} activeOpacity={0.85}
                disabled={!!busy} onPress={() => Alert.alert('Archiver', 'Archiver ce dossier ? Il n\'apparaîtra plus dans les demandes en cours.', [
                  { text: 'Retour', style: 'cancel' },
                  { text: 'Archiver', style: 'destructive', onPress: () => onAction('archive') },
                ])}>
                <Ionicons name="archive-outline" size={18} color="#991B1B" />
                <Text style={styles.dDangerBtnTxt}>Archiver le dossier</Text>
              </TouchableOpacity>
            )}
          </>
        )}

        {(!!g.contact_email || !!g.contact_phone) && (
          <>
            <Text style={styles.dSection}>Contact financeur</Text>
            {!!g.contact_name && <View style={styles.dInfoRow}><Text style={styles.dLabel}>Nom</Text><Text style={styles.dValue}>{g.contact_name}</Text></View>}
            {!!g.contact_email && <InfoRow icon="mail" label="Email" value={g.contact_email} onPress={() => Linking.openURL('mailto:' + g.contact_email)} />}
            {!!g.contact_phone && <InfoRow icon="call" label="Téléphone" value={g.contact_phone} onPress={() => Linking.openURL('tel:' + String(g.contact_phone).replace(/\s+/g, ''))} />}
          </>
        )}

        {activity.length > 0 && (
          <>
            <Text style={styles.dSection}>Journal</Text>
            {activity.map((a, i) => (
              <View key={i} style={styles.ckPayRow}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.ckPaySub}>{a.label}</Text>
                  <Text style={styles.ckPaySub}>{[a.who, a.when].filter(Boolean).join(' · ')}</Text>
                </View>
              </View>
            ))}
          </>
        )}

        {/* Passer à « Accordé » demande le montant obtenu : sans lui la KPI resterait vide. */}
        <SheetPicker visible={statusOpen} title="Statut du dossier" onClose={() => setStatusOpen(false)} selected={g.status}
          onPick={(v) => {
            if (v === 'granted') { setGrantedAmt(g.granted != null ? String(g.granted) : (g.requested != null ? String(g.requested) : '')); setGrantedOpen(true); return; }
            onAction('set_status', { status: v });
          }} options={GRANT_STATUSES} />

        <Modal visible={grantedOpen} transparent animationType="slide" onRequestClose={() => setGrantedOpen(false)}>
          <Pressable style={styles.sheetBackdrop} onPress={() => setGrantedOpen(false)}>
            <Pressable style={styles.sheet}>
              <View style={styles.sheetHandle} />
              <Text style={styles.sheetTitle}>Subvention accordée</Text>
              <Text style={[styles.dMuted, { marginBottom: 14 }]}>Quel montant le financeur a-t-il accordé ?</Text>
              <TextInput style={styles.fInput} value={grantedAmt} onChangeText={setGrantedAmt} keyboardType="decimal-pad"
                placeholder="Montant en €" placeholderTextColor="#B6C0CC" accessibilityLabel="Montant accordé" autoFocus />
              <TouchableOpacity accessibilityRole="button" style={styles.dPrimaryBtn} activeOpacity={0.85}
                onPress={() => { setGrantedOpen(false); onAction('set_status', { status: 'granted', amount_granted: grantedAmt }); }}>
                <Ionicons name="checkmark-circle" size={18} color="#fff" />
                <Text style={styles.dPrimaryBtnTxt}>Enregistrer</Text>
              </TouchableOpacity>
              <TouchableOpacity accessibilityRole="button" style={{ paddingVertical: 14, alignItems: 'center' }} activeOpacity={0.7}
                onPress={() => { setGrantedOpen(false); onAction('set_status', { status: 'granted' }); }}>
                <Text style={styles.formLink}>Sans préciser le montant</Text>
              </TouchableOpacity>
            </Pressable>
          </Pressable>
        </Modal>
      </ScrollView>
      </KeyboardAvoidingView>
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
        <TouchableOpacity accessibilityRole="button" style={[styles.dPrimaryBtn, generating ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={generating ? undefined : onGenerate}>
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
              <TouchableOpacity accessibilityRole="button" style={[styles.scanBtn, { flex: 1 }, logoBusy ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={logoBusy ? undefined : onLogo}>
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
        <TouchableOpacity accessibilityRole="button" style={[styles.dPrimaryBtn, saving ? { opacity: 0.6 } : null]} activeOpacity={0.85} onPress={saving ? undefined : () => onSave(f)}>
          {saving ? <ActivityIndicator color="#fff" /> : <Ionicons name="checkmark-circle" size={18} color="#fff" />}
          <Text style={styles.dPrimaryBtnTxt}>{saving ? 'Enregistrement…' : 'Enregistrer'}</Text>
        </TouchableOpacity>

        <TouchableOpacity accessibilityRole="button" style={styles.settingsRow} activeOpacity={0.7} onPress={() => onWeb('/parametres?tab=securite')}>
          <Ionicons name="lock-closed-outline" size={20} color="#475569" />
          <Text style={styles.settingsRowTxt}>Sécurité & mot de passe</Text>
          <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
        </TouchableOpacity>
        {org.is_admin && (
          <TouchableOpacity accessibilityRole="button" style={styles.settingsRow} activeOpacity={0.7} onPress={() => onWeb('/parametres?tab=organisation')}>
            <Ionicons name="business-outline" size={20} color="#475569" />
            <Text style={styles.settingsRowTxt}>Infos de l'organisation</Text>
            <Ionicons name="chevron-forward" size={18} color="#CBD5E1" />
          </TouchableOpacity>
        )}

        <TouchableOpacity accessibilityRole="button" style={styles.logoutBtn} activeOpacity={0.85} onPress={onLogout}>
          <Ionicons name="log-out-outline" size={19} color="#DC2626" /><Text style={styles.logoutTxt}>Se déconnecter</Text>
        </TouchableOpacity>
        {a.can_delete && (
          <TouchableOpacity accessibilityRole="button" style={{ marginTop: 14, alignItems: 'center' }} activeOpacity={0.7} onPress={onDelete}>
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
  const founderInit = useRef(false);
  const autoLoginTried = useRef(false);
  const pendingCreds = useRef(null);
  const pendingLogin = useRef(null);   // identifiants à soumettre dès que /connexion est chargée
  const lastLoadAlert = useRef(0);      // anti-spam des alertes « chargement impossible »
  const logoutTimer = useRef(null);
  const actTimer = useRef(null);
  const finishLogoutRef = useRef(null);
  const loginAttempted = useRef(false);
  const authedRef = useRef(false);
  const pendingNotifLink = useRef(null); // lien d'une notification tapee, consomme une fois authentifie
  const lastUrl = useRef('');
  const [loginBusy, setLoginBusy] = useState(false);
  const [loginErr, setLoginErr] = useState('');
  const [pdfBusy, setPdfBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [canGoBack, setCanGoBack] = useState(false);
  const [active, setActive] = useState('accueil');
  const [quickOpen, setQuickOpen] = useState(false);
  const [authed, setAuthed] = useState(false);
  const [kpi, setKpi] = useState(null);
  const [kpiLoading, setKpiLoading] = useState(false);
  const [kpiError, setKpiError] = useState(false);
  const [projects, setProjects] = useState(null);
  const [projLoading, setProjLoading] = useState(false);
  const [people, setPeople] = useState(null);
  const [peopleLoading, setPeopleLoading] = useState(false);
  const [invoices, setInvoices] = useState(null);
  const [invLoading, setInvLoading] = useState(false);
  const [webMode, setWebMode] = useState(startPath === '/signup');
  const [stack, setStack] = useState([]); // pile de fiches détail natives
  const [form, setForm] = useState(null); // { type } formulaire natif ouvert
  const [formErr, setFormErr] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [csrf, setCsrf] = useState('');
  const [pickClients, setPickClients] = useState([]);
  const [folders, setFolders] = useState([]);
  const [projMembers, setProjMembers] = useState([]);
  const [actBusy, setActBusy] = useState(null);
  const [pickCampaigns, setPickCampaigns] = useState([]);
  const [pickMembers, setPickMembers] = useState([]);
  const [pickProjects, setPickProjects] = useState([]);
  const [scanData, setScanData] = useState(null);
  const [scanning, setScanning] = useState(false);
  const [expenseProject, setExpenseProject] = useState(0);
  const [paymentCampaign, setPaymentCampaign] = useState(0);
  const [menuScreen, setMenuScreen] = useState(null); // null=hub | 'agenda' | 'messages' | 'subinvoices'
  const [events, setEvents] = useState(null);
  const [eventsLoading, setEventsLoading] = useState(false);
  const [channels, setChannels] = useState(null);
  const [channelsLoading, setChannelsLoading] = useState(false);
  const [openChannel, setOpenChannel] = useState(null);
  const [chanMsgs, setChanMsgs] = useState(null);
  const [chanLoading, setChanLoading] = useState(false);
  const [sendingMsg, setSendingMsg] = useState(false);
  const [msgSendResult, setMsgSendResult] = useState(null); // {ok, seq} — signale à NativeChat le résultat d'envoi
  const msgSendSeq = useRef(0);
  const [subInv, setSubInv] = useState(null);
  const [subInvLoading, setSubInvLoading] = useState(false);
  const [quotes, setQuotes] = useState(null);
  const [quotesLoading, setQuotesLoading] = useState(false);
  const [stats, setStats] = useState(null);
  const [statsLoading, setStatsLoading] = useState(false);
  const [notifs, setNotifs] = useState(null);
  const [founderData, setFounderData] = useState(null);
  const [founderLoading, setFounderLoading] = useState(false);
  const [fdOrgs, setFdOrgs] = useState(null);
  const [fdOrgsLoading, setFdOrgsLoading] = useState(false);
  const [fdOrgsFilter, setFdOrgsFilter] = useState('all');
  const [fdOrgDetail, setFdOrgDetail] = useState(null);
  const [fdOrgDetailLoading, setFdOrgDetailLoading] = useState(false);
  const [fdOrgEditBusy, setFdOrgEditBusy] = useState(false);
  const fdOrgDetailIdRef = useRef(0);
  const [fdPlansM, setFdPlansM] = useState(null);
  const [fdPlansMLoading, setFdPlansMLoading] = useState(false);
  const [fdPlansMBusy, setFdPlansMBusy] = useState(false);
  const fdPlanCloseRef = useRef(null);
  const [fdProjects, setFdProjects] = useState(null);
  const [fdProjectsLoading, setFdProjectsLoading] = useState(false);
  const [fdProjFilter, setFdProjFilter] = useState('');
  const [fdActivity, setFdActivity] = useState(null);
  const [fdActivityLoading, setFdActivityLoading] = useState(false);
  const [fdSettings, setFdSettings] = useState(null);
  const [fdSettingsLoading, setFdSettingsLoading] = useState(false);
  const [fdSettingsBusy, setFdSettingsBusy] = useState(false);
  const [fdProspects, setFdProspects] = useState(null);
  const [fdProspectsLoading, setFdProspectsLoading] = useState(false);
  const [fdProspectsBusy, setFdProspectsBusy] = useState(false);
  const fdProspectCloseRef = useRef(null);
  const [fdDir, setFdDir] = useState(null);
  const [fdDirLoading, setFdDirLoading] = useState(false);
  const [fdDirBusy, setFdDirBusy] = useState(false);
  const [fdDirNav, setFdDirNav] = useState({ region: '', dept: '', dept_name: '', category: '', page: 1 });
  const [fdBusyId, setFdBusyId] = useState(0);
  const [fdBilling, setFdBilling] = useState(null);
  const [fdBillingLoading, setFdBillingLoading] = useState(false);
  const [fdBillingFilter, setFdBillingFilter] = useState('all');
  const [fdBillBusy, setFdBillBusy] = useState(0);
  const [fdStats, setFdStats] = useState(null);
  const [fdStatsLoading, setFdStatsLoading] = useState(false);
  const [fdBlog, setFdBlog] = useState(null);
  const [fdBlogLoading, setFdBlogLoading] = useState(false);
  const [blogGenBusy, setBlogGenBusy] = useState(false);
  const [blogGenMsg, setBlogGenMsg] = useState(null);
  const [blogTopicBusy, setBlogTopicBusy] = useState(false);
  const [fdBlogFilter, setFdBlogFilter] = useState('all');
  const [fdSupport, setFdSupport] = useState(null);
  const [fdSupportLoading, setFdSupportLoading] = useState(false);
  const [fdSupportFilter, setFdSupportFilter] = useState('open');
  const [fdTicket, setFdTicket] = useState(null);
  const [fdTicketLoading, setFdTicketLoading] = useState(false);
  const [fdReplyBusy, setFdReplyBusy] = useState(false);
  const [fdPlans, setFdPlans] = useState(null);
  const [fdCreateBusy, setFdCreateBusy] = useState(false);
  const [fdCreateResult, setFdCreateResult] = useState(null);
  const [fdCreateErr, setFdCreateErr] = useState('');
  const [fdContacts, setFdContacts] = useState(null);
  const [fdContactsLoading, setFdContactsLoading] = useState(false);
  const [fdCtcReplyBusy, setFdCtcReplyBusy] = useState(false);
  const [fdCtcThread, setFdCtcThread] = useState(null);
  const [fdCtcThreadLoading, setFdCtcThreadLoading] = useState(false);
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
  const [loggingOut, setLoggingOut] = useState(false);

  const profile = (kpi && kpi.profile) === 'tpe' ? 'tpe' : 'asso';
  const isTpe = profile === 'tpe';
  const isFounder = !!(kpi && kpi.is_founder);
  const TABS = tabsFor(profile);
  // Rôles (mêmes règles que le serveur, qui renvoie 403 sinon) : on n'affiche pas un bouton
  // qui échouerait après avoir rempli tout le formulaire.
  const isAdminOrg = !!kpi && (kpi.role === 'admin' || !!kpi.is_founder || !!kpi.is_super_admin);
  const canManageOrg = isAdminOrg || (!!kpi && kpi.role === 'coordinator');
  const canCreateProjects = !kpi || kpi.can_create_projects !== false; // inconnu (ancien serveur) → on laisse
  const QUICK_ACTIONS = (isTpe ? QUICK_ACTIONS_TPE : QUICK_ACTIONS_ASSO).filter((a) => {
    if (a.form === 'invoice' || a.form === 'client') return isAdminOrg;
    if (a.form === 'member' || a.form === 'quote') return canManageOrg;
    if (a.form === 'project') return canCreateProjects;
    return true;
  });

  const inject = useCallback((js) => {
    if (webRef.current) webRef.current.injectJavaScript(js);
  }, []);

  const fetchKpis = useCallback(() => {
    setKpiLoading(true);
    setKpiError(false);
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
    payment:  '/api/app-create-cotisation-payment.php',
    campaign: '/api/app-create-campaign.php',
    event:   '/api/app-create-event.php',
    grant:   '/api/app-create-grant.php',
  };

  const openForm = useCallback((type, preId = 0, editData = null) => {
    setQuickOpen(false);
    // Un paiement ouvert depuis une fiche campagne garde la fiche dessous : fermer le
    // formulaire (ou revenir en arrière) ramène sur la campagne, pas sur la liste.
    if (!(type === 'payment' && preId)) clearDetail();
    setWebMode(false);
    setFormErr('');
    setForm({ type, edit: editData });
    inject(FETCH_CSRF_JS);
    if (type === 'invoice' || type === 'quote') inject(fetchJS('/api/app-clients.php', '__akpick'));
    if (type === 'project') { inject(fetchJS('/api/app-folders.php', '__akfolders')); inject(fetchJS('/api/app-members.php', '__akprojmembers')); }
    if (type === 'payment') {
      setPaymentCampaign(preId || 0);   // ouvert depuis une fiche campagne : elle est pré-sélectionnée
      inject(fetchJS('/api/app-cotisations.php', '__akpickcampaigns'));
      inject(fetchJS('/api/app-members.php', '__akpickmembers'));
    }
    if (type === 'event' || type === 'grant') { inject(fetchJS('/api/app-projects.php', '__akpickprojects')); }
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
      const opts = { mediaTypes: ['images'], base64: true, quality: 0.5 };
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
      // MIME réel (une image galerie PNG/HEIC ne doit pas être étiquetée JPEG)
      const asset = res.assets[0];
      const scanMime = (asset.mimeType && /^image\//.test(asset.mimeType)) ? asset.mimeType : 'image/jpeg';
      inject(scanJS(asset.base64, scanMime, projectId, csrf));
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

  // --- Actions natives sur une fiche (envoi, encaissement, conversion, étapes) ---
  // `busy` porte l'action en cours (ou l'id de la ligne) pour l'indicateur de chargement.
  const runAction = useCallback((endpoint, payload, busyKey) => {
    if (!csrf) { Alert.alert('Un instant', 'Session en préparation, réessayez dans un instant.'); inject(FETCH_CSRF_JS); return; }
    setActBusy(busyKey);
    inject(postJS(endpoint, { ...payload, csrf }, '__akact'));
    // Filet de sécurité : si la réponse n'arrive jamais (WebView rechargée en plein vol),
    // le bouton se débloque au lieu de rester en chargement indéfiniment.
    if (actTimer.current) clearTimeout(actTimer.current);
    actTimer.current = setTimeout(() => {
      setActBusy((b) => {
        if (b === null) return b;
        Alert.alert('Action interrompue', 'Aucune réponse du serveur. Vérifie ta connexion et réessaie.');
        return null;
      });
    }, 20000);
  }, [csrf, inject]);

  // --- Écrans du menu « Plus » (natifs) ------------------------------
  const fetchEvents = useCallback(() => { setEventsLoading(true); inject(fetchJS('/api/app-events.php', '__akevents')); }, [inject]);
  const fetchChannels = useCallback(() => { setChannelsLoading(true); inject(fetchJS('/api/app-channels.php', '__akchannels')); }, [inject]);
  const fetchSubInv = useCallback(() => { setSubInvLoading(true); inject(fetchJS('/api/app-subscription-invoices.php', '__aksubinv')); }, [inject]);
  const fetchChanMsgs = useCallback((chId) => { setChanLoading(true); inject(fetchJS('/api/app-channel-messages.php?channel_id=' + chId, '__akchanmsgs')); }, [inject]);
  const fetchQuotes = useCallback(() => { setQuotesLoading(true); inject(fetchJS('/api/app-quotes.php', '__akquotes')); }, [inject]);
  const fetchStats = useCallback(() => { setStatsLoading(true); inject(fetchJS('/api/app-stats.php', '__akstats')); }, [inject]);
  const fetchNotifs = useCallback(() => { setNotifsLoading(true); inject(fetchJS('/api/app-notifications.php', '__aknotifs')); }, [inject]);
  const fetchFounder = useCallback(() => { setFounderLoading(true); inject(fetchJS('/api/app-founder.php', '__akfounder')); }, [inject]);
  const fetchFdOrgs = useCallback((filter) => {
    setFdOrgsLoading(true);
    inject(fetchJS('/api/app-founder-orgs.php?filter=' + encodeURIComponent(filter || 'all'), '__akfdorgs'));
  }, [inject]);
  const openFdOrgs = useCallback((filter) => {
    const f = filter || 'all';
    setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null);
    setFdOrgs(null); setFdOrgsFilter(f); setMenuScreen('fdorgs'); fetchFdOrgs(f);
  }, [clearDetail, closeForm, fetchFdOrgs]);
  const doFounderAction = useCallback((orgId, action) => {
    if (!csrf) { inject(FETCH_CSRF_JS); return; }
    setFdBusyId(orgId);
    inject(postJS('/api/app-founder-action.php', { org_id: orgId, action, csrf }, '__akfdaction'));
  }, [csrf, inject]);
  const fetchFdOrgDetail = useCallback((id) => { setFdOrgDetailLoading(true); inject(fetchJS('/api/app-founder-org-detail.php?id=' + id, '__akfdorgdet')); }, [inject]);
  const openFdOrgDetail = useCallback((id) => {
    setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null);
    fdOrgDetailIdRef.current = id; setFdOrgDetail(null); setMenuScreen('fdorgdetail'); fetchFdOrgDetail(id);
  }, [clearDetail, closeForm, fetchFdOrgDetail]);
  const doOrgEdit = useCallback((payload) => {
    if (!csrf) { inject(FETCH_CSRF_JS); return; }
    setFdOrgEditBusy(true);
    inject(postJS('/api/app-founder-action.php', { ...payload, csrf }, '__akfdorgedit'));
  }, [csrf, inject]);
  // Fondateur : gestion des plans tarifaires
  const fetchFdPlansM = useCallback(() => { setFdPlansMLoading(true); inject(fetchJS('/api/app-founder-plans.php', '__akfdplansm')); }, [inject]);
  const openFdPlansM = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdPlansM(null); setMenuScreen('fdplans'); fetchFdPlansM(); }, [clearDetail, closeForm, fetchFdPlansM]);
  const doPlanSave = useCallback((payload, cb) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } fdPlanCloseRef.current = cb || null; setFdPlansMBusy(true); inject(postJS('/api/app-founder-plans.php', { ...payload, csrf }, '__akfdplanact')); }, [csrf, inject]);
  const doPlanDelete = useCallback((id, cb) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } fdPlanCloseRef.current = cb || null; setFdPlansMBusy(true); inject(postJS('/api/app-founder-plans.php', { action: 'delete', plan_id: id, csrf }, '__akfdplanact')); }, [csrf, inject]);
  // Fondateur : projets globaux + activité (lecture seule)
  const fetchFdProjects = useCallback((f) => { setFdProjectsLoading(true); inject(fetchJS('/api/app-founder-projects.php?status=' + encodeURIComponent(f || ''), '__akfdproj')); }, [inject]);
  const openFdProjects = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdProjects(null); setFdProjFilter(''); setMenuScreen('fdprojects'); fetchFdProjects(''); }, [clearDetail, closeForm, fetchFdProjects]);
  const fetchFdActivity = useCallback(() => { setFdActivityLoading(true); inject(fetchJS('/api/app-founder-activity.php', '__akfdactiv')); }, [inject]);
  const openFdActivity = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdActivity(null); setMenuScreen('fdactivity'); fetchFdActivity(); }, [clearDetail, closeForm, fetchFdActivity]);
  const fetchFdSettings = useCallback(() => { setFdSettingsLoading(true); inject(fetchJS('/api/app-founder-settings.php', '__akfdset')); }, [inject]);
  const openFdSettings = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdSettings(null); setMenuScreen('fdsettings'); fetchFdSettings(); }, [clearDetail, closeForm, fetchFdSettings]);
  const doSaveSettings = useCallback((payload) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setFdSettingsBusy(true); inject(postJS('/api/app-founder-settings.php', { ...payload, csrf }, '__akfdsetsave')); }, [csrf, inject]);
  // Fondateur : prospection
  const fetchFdProspects = useCallback(() => { setFdProspectsLoading(true); inject(fetchJS('/api/app-founder-prospects.php', '__akfdpros')); }, [inject]);
  const openFdProspects = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdProspects(null); setMenuScreen('fdprospects'); fetchFdProspects(); }, [clearDetail, closeForm, fetchFdProspects]);
  const doProspectImport = useCallback((text, type, cb) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } fdProspectCloseRef.current = cb || null; setFdProspectsBusy(true); inject(postJS('/api/app-founder-prospects.php', { action: 'import', text, type, csrf }, '__akfdprosact')); }, [csrf, inject]);
  const doProspectQueue = useCallback(() => { if (!csrf) { inject(FETCH_CSRF_JS); return; } inject(postJS('/api/app-founder-prospects.php', { action: 'queue', csrf }, '__akfdprosact')); }, [csrf, inject]);
  const doProspectStatus = useCallback((id, status) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } inject(postJS('/api/app-founder-prospects.php', { action: 'status', id, status, csrf }, '__akfdprosact')); }, [csrf, inject]);
  const doProspectDelete = useCallback((id) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } inject(postJS('/api/app-founder-prospects.php', { action: 'delete', id, csrf }, '__akfdprosact')); }, [csrf, inject]);

  // --- Annuaire national des associations (Fondateur) ---
  const fetchFdDir = useCallback((params) => {
    setFdDirLoading(true);
    const qs = params && Object.keys(params).length
      ? '?' + Object.entries(params).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&')
      : '';
    inject(fetchJS('/api/app-founder-directory.php' + qs, '__akfddir'));
  }, [inject]);
  const openFdDirectory = useCallback(() => {
    setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null);
    setFdDir(null); setFdDirNav({ region: '', dept: '', dept_name: '', category: '', page: 1 });
    setMenuScreen('fddir'); fetchFdDir({});
  }, [clearDetail, closeForm, fetchFdDir]);
  const dirOpenRegion = useCallback((region) => { setFdDirNav({ region, dept: '', dept_name: '', category: '', page: 1 }); setFdDir(null); fetchFdDir({ region }); }, [fetchFdDir]);
  const dirOpenDept = useCallback((dept, dept_name, category) => { setFdDirNav((n) => ({ ...n, dept, dept_name, category: category || '', page: 1 })); setFdDir(null); fetchFdDir({ dept, category: category || '', page: 1 }); }, [fetchFdDir]);
  const dirBackRoot = useCallback(() => { setFdDirNav({ region: '', dept: '', dept_name: '', category: '', page: 1 }); setFdDir(null); fetchFdDir({}); }, [fetchFdDir]);
  const dirBackRegion = useCallback(() => { setFdDirNav((n) => ({ ...n, dept: '', dept_name: '', category: '', page: 1 })); setFdDir(null); fetchFdDir({ region: fdDirNav.region }); }, [fetchFdDir, fdDirNav.region]);
  const doDirSetEmail = useCallback((id, email) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setFdDirBusy(true); inject(postJS('/api/app-founder-directory.php', { action: 'set_email', id, email, csrf }, '__akfddiract')); }, [csrf, inject]);
  const doDirToProspect = useCallback((id) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setFdDirBusy(true); inject(postJS('/api/app-founder-directory.php', { action: 'to_prospect', id, csrf }, '__akfddiract')); }, [csrf, inject]);
  // Fondateur : pages natives dédiées (paiements, stats, blog, support)
  const fetchFdBilling = useCallback((filter) => { setFdBillingLoading(true); inject(fetchJS('/api/app-founder-billing.php?filter=' + encodeURIComponent(filter || 'all'), '__akfdbill')); }, [inject]);
  const openFdBilling = useCallback((filter) => { const f = filter || 'all'; setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdBilling(null); setFdBillingFilter(f); setMenuScreen('fdbilling'); fetchFdBilling(f); }, [clearDetail, closeForm, fetchFdBilling]);
  const doFounderPay = useCallback((invId) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setFdBillBusy(invId); inject(postJS('/api/app-founder-billing-action.php', { invoice_id: invId, action: 'mark_paid', csrf }, '__akfdpay')); }, [csrf, inject]);
  const fetchFdStats = useCallback(() => { setFdStatsLoading(true); inject(fetchJS('/api/app-founder-stats.php', '__akfdstats')); }, [inject]);
  const openFdStats = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdStats(null); setMenuScreen('fdstats'); fetchFdStats(); }, [clearDetail, closeForm, fetchFdStats]);
  const fetchFdBlog = useCallback((filter) => { setFdBlogLoading(true); inject(fetchJS('/api/app-founder-blog.php?filter=' + encodeURIComponent(filter || 'all'), '__akfdblog')); }, [inject]);
  const openFdBlog = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdBlog(null); setBlogGenMsg(null); setFdBlogFilter('all'); setMenuScreen('fdblog'); fetchFdBlog('all'); }, [clearDetail, closeForm, fetchFdBlog]);
  const doBlogGenerate = useCallback((payload) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setBlogGenBusy(true); setBlogGenMsg(null); inject(postJS('/api/app-founder-blog-generate.php', { ...payload, csrf }, '__akfdbloggen')); }, [csrf, inject]);
  const doBlogBulk = useCallback((payload) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setBlogGenBusy(true); setBlogGenMsg(null); inject(postJS('/api/app-founder-blog-bulk.php', { ...payload, csrf }, '__akfdblogbulk')); }, [csrf, inject]);
  const doBlogProgram = useCallback((payload) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setBlogTopicBusy(true); inject(postJS('/api/app-founder-blog-topic.php', { action: 'add', ...payload, csrf }, '__akfdblogtopic')); }, [csrf, inject]);
  const doBlogDeleteTopic = useCallback((id) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } inject(postJS('/api/app-founder-blog-topic.php', { action: 'delete', id, csrf }, '__akfdblogtopic')); }, [csrf, inject]);
  const fetchFdSupport = useCallback((filter) => { setFdSupportLoading(true); inject(fetchJS('/api/app-founder-support.php?filter=' + encodeURIComponent(filter || 'open'), '__akfdsup')); }, [inject]);
  const openFdSupport = useCallback((filter) => { const f = filter || 'open'; setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdSupport(null); setFdSupportFilter(f); setMenuScreen('fdsupport'); fetchFdSupport(f); }, [clearDetail, closeForm, fetchFdSupport]);
  const fetchFdThread = useCallback((id) => { setFdTicketLoading(true); inject(fetchJS('/api/app-founder-support-thread.php?id=' + id, '__akfdthread')); }, [inject]);
  const openFdThread = useCallback((ticket) => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setFdTicket(null); setMenuScreen('fdthread'); fetchFdThread(ticket.id); }, [clearDetail, closeForm, fetchFdThread]);
  const doSupportReply = useCallback((ticketId, body) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setFdReplyBusy(true); inject(postJS('/api/app-founder-support-reply.php', { ticket_id: ticketId, body, csrf }, '__akfdreply')); }, [csrf, inject]);
  const fetchFdPlans = useCallback(() => { inject(fetchJS('/api/app-founder-create-org.php', '__akfdplans')); }, [inject]);
  const openFdCreateOrg = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdCreateResult(null); setFdCreateErr(''); setMenuScreen('fdcreateorg'); if (!fdPlans) fetchFdPlans(); }, [clearDetail, closeForm, fetchFdPlans, fdPlans]);
  const doCreateOrg = useCallback((payload) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setFdCreateBusy(true); setFdCreateErr(''); inject(postJS('/api/app-founder-create-org.php', { ...payload, csrf }, '__akfdcreate')); }, [csrf, inject]);
  const fetchFdContacts = useCallback(() => { setFdContactsLoading(true); inject(fetchJS('/api/app-founder-contacts.php', '__akfdcontacts')); }, [inject]);
  const openFdContacts = useCallback(() => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setOpenChannel(null); setFdContacts(null); setMenuScreen('fdcontacts'); fetchFdContacts(); }, [clearDetail, closeForm, fetchFdContacts]);
  const fetchFdCtcThread = useCallback((id) => { setFdCtcThreadLoading(true); inject(fetchJS('/api/app-founder-contact-thread.php?id=' + id, '__akfdctcthread')); }, [inject]);
  const openFdCtcThread = useCallback((contact) => { setActive('menu'); setWebMode(false); clearDetail(); closeForm(); setFdCtcThread(null); setMenuScreen('fdctcthread'); fetchFdCtcThread(contact.id); }, [clearDetail, closeForm, fetchFdCtcThread]);
  const doContactReply = useCallback((contactId, body) => { if (!csrf) { inject(FETCH_CSRF_JS); return; } setFdCtcReplyBusy(true); inject(postJS('/api/app-founder-contact-reply.php', { contact_id: contactId, body, csrf }, '__akfdctcreply')); }, [csrf, inject]);
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
    else if (screen === 'notifications') { fetchNotifs(); inject(FETCH_CSRF_JS); }
    else if (screen === 'founder') { setFounderData(null); fetchFounder(); }
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
  }, [clearDetail, closeForm, fetchEvents, fetchChannels, fetchSubInv, fetchQuotes, fetchStats, fetchNotifs, fetchFounder, fetchCoti, fetchGrants, fetchPeople, fetchAssemblies, fetchAttendance, fetchBroadcasts, fetchTickets, fetchCoach, fetchAccount, inject]);

  const onFounderTile = useCallback((key, filter) => {
    if (key === 'associations') openFdOrgs(filter || 'all');
    else if (key === 'create') openFdCreateOrg();
    else if (key === 'billing') openFdBilling(filter || 'all');
    else if (key === 'plans') openFdPlansM();
    else if (key === 'projects') openFdProjects();
    else if (key === 'activity') openFdActivity();
    else if (key === 'settings') openFdSettings();
    else if (key === 'prospects') openFdProspects();
    else if (key === 'annuaire') openFdDirectory();
    else if (key === 'support') openFdSupport('open');
    else if (key === 'stats') openFdStats();
    else if (key === 'blog') openFdBlog();
    else if (key === 'contacts') openFdContacts();
  }, [openFdOrgs, openFdCreateOrg, openFdBilling, openFdPlansM, openFdProjects, openFdActivity, openFdSettings, openFdProspects, openFdSupport, openFdStats, openFdBlog, openFdContacts]);

  const onAnalyzeInvoices = useCallback(() => { setInvAILoading(true); inject(fetchJS('/api/app-invoices-ai.php', '__akinvai')); }, [inject]);
  const onCockpit = useCallback(() => { setStatsCockpitLoading(true); inject(fetchJS('/api/app-stats-ai.php', '__akstatsai')); }, [inject]);
  const backToHub = useCallback(() => { clearDetail(); closeForm(); setWebMode(false); setActive('menu'); setMenuScreen(null); }, [clearDetail, closeForm]);

  const openChannelFn = useCallback((c) => {
    setOpenChannel(c); setChanMsgs(null); fetchChanMsgs(c.id);
    // Ouvrir un canal = accuser réception des messages internes → on efface le badge Messages
    setKpi((k) => k ? { ...k, notif_unread: Math.max(0, (k.notif_unread || 0) - (k.msg_unread || 0)), msg_unread: 0 } : k);
    if (csrf) inject(postJS('/api/app-notif-read.php', { type: 'message', csrf }, '__aknotifread'));
  }, [fetchChanMsgs, csrf, inject]);

  // --- Notifications : navigation 100% native + marquer lu ---
  const routeNotif = useCallback((link) => {
    const l = (link || '').toLowerCase();
    if (!l) return;
    if (l.indexOf('support') !== -1) { openMenuScreen('tickets'); return; }
    if (l.indexOf('/messages') !== -1) { openMenuScreen('messages'); return; }
    if (l.indexOf('/projet') !== -1) { setMenuScreen(null); setActive('projets'); setWebMode(false); return; }
    // par défaut : on reste dans l'app (jamais de bascule web)
  }, [openMenuScreen]);

  // Tap sur une notification push : router vers le bon ecran (le token etait enregistre
  // mais aucun listener ne consommait la reponse -> le tap n'ouvrait rien).
  useEffect(() => {
    const consume = (resp) => {
      const link = resp?.notification?.request?.content?.data?.link
        || resp?.notification?.request?.content?.data?.url || '';
      if (!link) return;
      if (authedRef.current) routeNotif(link);
      else pendingNotifLink.current = link; // sera consomme une fois authentifie
    };
    const sub = Notifications.addNotificationResponseReceivedListener(consume);
    // App demarree a froid par un tap sur notification :
    Notifications.getLastNotificationResponseAsync().then((r) => { if (r) consume(r); }).catch(() => {});
    return () => { try { sub.remove(); } catch (e) {} };
  }, [routeNotif]);

  // Des que la session est prete, on consomme un eventuel lien de notification en attente.
  useEffect(() => {
    if (authed && pendingNotifLink.current) {
      const link = pendingNotifLink.current;
      pendingNotifLink.current = null;
      routeNotif(link);
    }
  }, [authed, routeNotif]);

  const onNotifPress = useCallback((n) => {
    if (!n) return;
    if (!n.read) {
      setNotifs((prev) => prev ? { ...prev, unread: Math.max(0, (prev.unread || 0) - 1), items: (prev.items || []).map((it) => it.id === n.id ? { ...it, read: true } : it) } : prev);
      const isSupport = ((n.link || '').toLowerCase().indexOf('support') !== -1) || n.icon === 'help-buoy';
      setKpi((k) => k ? { ...k, notif_unread: Math.max(0, (k.notif_unread || 0) - 1), support_unread: isSupport ? Math.max(0, (k.support_unread || 0) - 1) : k.support_unread, msg_unread: !isSupport ? Math.max(0, (k.msg_unread || 0) - 1) : k.msg_unread } : k);
      if (csrf) inject(postJS('/api/app-notif-read.php', { id: n.id, csrf }, '__aknotifread'));
    }
    routeNotif(n.link);
  }, [csrf, inject, routeNotif]);

  const onMarkAllRead = useCallback(() => {
    setNotifs((prev) => prev ? { ...prev, unread: 0, items: (prev.items || []).map((it) => ({ ...it, read: true })) } : prev);
    setKpi((k) => k ? { ...k, notif_unread: 0, msg_unread: 0, support_unread: 0 } : k);
    if (csrf) inject(postJS('/api/app-notif-read.php', { all: true, csrf }, '__aknotifread'));
  }, [csrf, inject]);

  const sendMessage = useCallback((chId, content) => {
    if (!csrf) { inject(FETCH_CSRF_JS); return; }
    setSendingMsg(true);
    inject(postJS('/api/app-send-message.php', { channel_id: chId, content, csrf }, '__akmsgsent'));
  }, [csrf, inject]);

  const onMoreNav = useCallback((nav) => {
    if (!nav) return;
    if (nav.founder) { if (nav.founder === 'cockpit') openMenuScreen('founder'); else onFounderTile(nav.founder); return; }
    if (nav.screen) { openMenuScreen(nav.screen); return; }
    if (nav.tab) {
      clearDetail(); closeForm(); setMenuScreen(null);
      if (nav.tab === 'factures') { setActive('factures'); setWebMode(false); return; }
      if (nav.tab === 'people') { setActive('people'); setWebMode(false); return; }
      setActive(nav.tab); setWebMode(false); return;
    }
    if (nav.web) { clearDetail(); closeForm(); setWebMode(true); inject(gotoJS(nav.web)); }
  }, [openMenuScreen, onFounderTile, clearDetail, closeForm, inject]);

  const saveAccount = useCallback((f) => {
    if (!csrf) { setSettingsErr('Session en préparation, réessayez.'); inject(FETCH_CSRF_JS); return; }
    setSettingsErr(''); setSettingsBusy(true);
    inject(postJS('/api/app-update-account.php', { ...f, csrf }, '__akaccountsaved'));
  }, [csrf, inject]);

  const uploadLogo = useCallback(async () => {
    try {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!perm.granted) { setSettingsErr('Autorisez l\'accès aux photos.'); return; }
      const res = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], base64: true, quality: 0.7 });
      if (res.canceled || !res.assets || !res.assets[0] || !res.assets[0].base64) return;
      if (!csrf) { inject(FETCH_CSRF_JS); return; }
      setLogoBusy(true);
      inject(postJS('/api/app-upload-logo.php', { image: res.assets[0].base64, mime: (res.assets[0].mimeType && /^image\//.test(res.assets[0].mimeType)) ? res.assets[0].mimeType : 'image/jpeg', csrf }, '__aklogosaved'));
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
    const editing = form && form.edit && (type === 'invoice' || type === 'quote');
    const endpoint = editing
      ? (type === 'quote' ? '/api/app-update-quote.php' : '/api/app-update-invoice.php')
      : CREATE_ENDPOINTS[type];
    const extra = editing
      ? (type === 'quote' ? { quote_id: form.edit.invoice.id } : { invoice_id: form.edit.invoice.id })
      : {};
    inject(postJS(endpoint, { ...data, ...extra, csrf }));
  }, [csrf, inject, form]);

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

  // Le Fondateur atterrit directement sur son cockpit natif (une seule fois au lancement)
  useEffect(() => {
    if (authed && kpi && kpi.is_founder && !founderInit.current) {
      founderInit.current = true;
      openMenuScreen('founder');
    }
  }, [authed, kpi, openMenuScreen]);

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
      if (form) { if (submitting) return true; closeForm(); return true; } // pas de fermeture pendant l'envoi (double création)
      if (stack.length) { popDetail(); return true; }
      if (webMode && canGoBack && webRef.current) { webRef.current.goBack(); return true; }
      if (webMode) { setWebMode(false); return true; }
      if (active === 'menu' && openChannel) { setOpenChannel(null); return true; }
      // Écrans du cockpit Fondateur (fd…) : retour vers le cockpit, comme les boutons de l'interface
      if (active === 'menu' && menuScreen) { setMenuScreen(String(menuScreen).indexOf('fd') === 0 ? 'founder' : null); return true; }
      if (active !== 'accueil') { setActive('accueil'); return true; }
      onExitToWelcome();
      return true;
    };
    const sub = BackHandler.addEventListener('hardwareBackPress', onBack);
    return () => sub.remove();
    // eslint-disable-next-line
  }, [canGoBack, quickOpen, active, webMode, stack.length, form, submitting, menuScreen, openChannel, popDetail, closeForm, onExitToWelcome]);

  const onNav = (nav) => {
    setCanGoBack(nav.canGoBack);
    const u = nav.url || '';
    lastUrl.current = u;
    const isLogin = /\/(connexion|signup|deconnexion|login|mot-de-passe|verifier-email)/.test(u);
    if (isLogin) {
      setAuthed(false);
      // Retour sur /connexion après une tentative native = identifiants refusés
      if (loginAttempted.current && /\/connexion/.test(u)) {
        loginAttempted.current = false;
        setLoginBusy(false);
        setLoginErr('Email ou mot de passe incorrect.');
      }
    } else if (isAssokitUrl(u)) {
      setAuthed(true);
      loginAttempted.current = false;
      setLoginBusy(false);
      setLoginErr('');
    }
  };

  // Connexion native : remplit + soumet le formulaire web (même session/cookies)
  const submitLogin = useCallback((email, password) => {
    pendingCreds.current = { email, password };
    loginAttempted.current = true;
    setLoginErr('');
    setLoginBusy(true);
    // Le formulaire web doit être chargé pour que l'injection trouve les champs : si la WebView
    // charge encore (ou n'est pas sur /connexion), on mémorise et on soumet dans onLoadEnd.
    const onLoginPage = /\/connexion/.test(lastUrl.current || '');
    if (loading || !onLoginPage) {
      pendingLogin.current = { email, password };
      if (!onLoginPage) inject(gotoJS('/connexion'));
    } else {
      inject(autoLoginJS(email, password));
    }
    setTimeout(() => {
      if (!authedRef.current && loginAttempted.current) {
        loginAttempted.current = false;
        pendingLogin.current = null;
        setLoginBusy(false);
        setLoginErr('Connexion impossible. Vérifie ta connexion et réessaie.');
      }
    }, 12000);
  }, [inject, loading]);

  useEffect(() => { authedRef.current = authed; if (authed) { setLoginBusy(false); setLoginErr(''); } }, [authed]);

  const onMessage = (e) => {
    try {
      const msg = JSON.parse(e.nativeEvent.data);
      // Session serveur perdue (401 'auth' sur n'importe quel appel) : on ne laisse pas l'app « vide »,
      // on repasse sur l'écran de connexion (l'auto-login biométrique se relance).
      const authLost = msg && typeof msg === 'object' && Object.keys(msg).some((k) => k.indexOf('__ak') === 0 && msg[k] && msg[k].ok === false && msg[k].error === 'auth');
      if (authLost) {
        setCsrf(''); setAuthed(false); setWebMode(false); setLoggingOut(false);
        autoLoginTried.current = false;
        inject(gotoJS('/connexion'));
        return;
      }
      // Échec de chargement d'un écran de données (réseau/serveur) : on prévient (au plus une fois / 5 s)
      // au lieu d'afficher silencieusement un écran vide ; le tirer-pour-rafraîchir relance.
      const DATA_KEYS = ['__akevents', '__akchannels', '__akchanmsgs', '__aksubinv', '__akquotes', '__akstats', '__aknotifs', '__akfounder', '__akfdorgs', '__akfdorgdet', '__akfdproj', '__akfdactiv', '__akfdpros', '__akfddir', '__akfdset', '__akfdplansm', '__akfdbill', '__akfdstats', '__akfdblog', '__akfdsup', '__akfdthread', '__akfdcontacts', '__akfdctcthread', '__akfdplans', '__akcoti', '__akgrants', '__akassemblies', '__akattendance', '__akbroadcasts', '__aktickets', '__akcoach', '__akaccount'];
      const failedKey = DATA_KEYS.find((k) => msg[k] && msg[k].ok === false);
      if (failedKey && Date.now() - (lastLoadAlert.current || 0) > 5000) {
        lastLoadAlert.current = Date.now();
        Alert.alert('Chargement impossible', (msg[failedKey].message) || 'Vérifie ta connexion, puis tire vers le bas pour rafraîchir.');
      }
      // Les fetch injectes renvoient { ok:false } en cas d'echec reseau/session.
      // On NE stocke PAS cet objet comme donnee (sinon ecrans a "0 EUR" trompeurs) :
      // on coupe le loader, on marque l'erreur, et on conserve d'eventuelles donnees deja chargees.
      if (msg && msg.__akkpi) {
        if (msg.__akkpi.ok === false) { setKpiLoading(false); setKpiError(true); }
        else { setKpi(msg.__akkpi); setKpiError(false); setKpiLoading(false); }
      }
      if (msg && msg.__akprojects) {
        if (msg.__akprojects.ok === false) { setProjLoading(false); }
        else { setProjects(msg.__akprojects); setProjLoading(false); }
      }
      if (msg && msg.__akmembers) {
        if (msg.__akmembers.ok === false) { setPeopleLoading(false); }
        else { setPeople(msg.__akmembers); setPeopleLoading(false); }
      }
      if (msg && msg.__akclients) {
        if (msg.__akclients.ok === false) { setPeopleLoading(false); }
        else { setPeople(msg.__akclients); setPeopleLoading(false); }
      }
      if (msg && msg.__akinvoices) {
        if (msg.__akinvoices.ok === false) { setInvLoading(false); }
        else { setInvoices(msg.__akinvoices); setInvLoading(false); }
      }
      if (msg && msg.__akdetail) {
        setStack((s) => {
          if (!s.length) return s;
          const cp = s.slice();
          const top = cp[cp.length - 1];
          const dd = msg.__akdetail;
          // Réponse périmée (l'utilisateur a ouvert une autre fiche entre-temps) : on l'ignore
          const ent = dd && dd.ok !== false ? (dd.invoice || dd.project || dd.member || dd.client || dd.event || dd.campaign || dd.grant || null) : null;
          if (ent && ent.id != null && top.id != null && String(ent.id) !== String(top.id)) return s;
          cp[cp.length - 1] = { ...top, data: dd, loading: false };
          return cp;
        });
      }
      if (msg && msg.__akcsrf && msg.__akcsrf.ok) setCsrf(msg.__akcsrf.csrf);
      if (msg && msg.__akpick && msg.__akpick.ok) setPickClients(msg.__akpick.clients || []);
      if (msg && msg.__akfolders && msg.__akfolders.ok) setFolders(msg.__akfolders.folders || []);
      if (msg && msg.__akprojmembers && msg.__akprojmembers.ok) setProjMembers(msg.__akprojmembers.members || []);
      if (msg && msg.__akpickcampaigns && msg.__akpickcampaigns.ok) setPickCampaigns(msg.__akpickcampaigns.campaigns || []);
      if (msg && msg.__akpickmembers && msg.__akpickmembers.ok) setPickMembers(msg.__akpickmembers.members || []);
      if (msg && msg.__akpickprojects && msg.__akpickprojects.ok) setPickProjects(msg.__akpickprojects.projects || []);
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
      if (msg && msg.__akfounder) { setFounderData(msg.__akfounder); setFounderLoading(false); }
      if (msg && msg.__akfdorgs) { setFdOrgs(msg.__akfdorgs); setFdOrgsLoading(false); }
      if (msg && msg.__akfdaction) {
        setFdBusyId(0);
        if (msg.__akfdaction.ok) { fetchFdOrgs(fdOrgsFilter); fetchFounder(); }
        else { Alert.alert('Action impossible', 'Réessaie dans un instant.'); }
      }
      if (msg && msg.__akfdorgdet) { setFdOrgDetailLoading(false); if (msg.__akfdorgdet.ok === false) setMenuScreen('founder'); else setFdOrgDetail(msg.__akfdorgdet); }
      if (msg && msg.__akfdorgedit) {
        setFdOrgEditBusy(false);
        if (msg.__akfdorgedit.ok) {
          if (fdOrgDetailIdRef.current) fetchFdOrgDetail(fdOrgDetailIdRef.current);
          fetchFdOrgs(fdOrgsFilter); fetchFounder();
        } else { Alert.alert('Action impossible', msg.__akfdorgedit.message || 'Réessaie dans un instant.'); }
      }
      if (msg && msg.__akfdproj) { setFdProjects(msg.__akfdproj); setFdProjectsLoading(false); }
      if (msg && msg.__akfdactiv) { setFdActivity(msg.__akfdactiv); setFdActivityLoading(false); }
      if (msg && msg.__akfdpros) { setFdProspects(msg.__akfdpros); setFdProspectsLoading(false); }
      if (msg && msg.__akfdprosact) {
        setFdProspectsBusy(false);
        const r = msg.__akfdprosact;
        if (r && r.ok) {
          fetchFdProspects();
          if (fdProspectCloseRef.current) { fdProspectCloseRef.current(); fdProspectCloseRef.current = null; }
          if (r.added !== undefined) Alert.alert('Import terminé', r.added + ' ajouté(s), ' + r.skipped + ' ignoré(s) (doublons/invalides).');
        } else { Alert.alert('Opération impossible', (r && r.message) || 'Réessaie.'); }
      }
      if (msg && msg.__akfddir) { setFdDir(msg.__akfddir); setFdDirLoading(false); }
      if (msg && msg.__akfddiract) {
        setFdDirBusy(false);
        const r = msg.__akfddiract;
        if (r && r.ok) {
          if (r.promoted !== undefined) Alert.alert(r.promoted ? 'Ajouté à la prospection' : 'Déjà présent', r.promoted ? 'L\'association est dans la file de prospection conforme.' : 'Cette association était déjà dans la prospection.');
          // rafraîchit la liste courante
          if (fdDirNav.dept) fetchFdDir({ dept: fdDirNav.dept, category: fdDirNav.category || '', page: fdDirNav.page || 1 });
        } else { Alert.alert('Opération impossible', (r && r.message) || 'Réessaie.'); }
      }
      if (msg && msg.__akfdset) { setFdSettingsLoading(false); if (msg.__akfdset.ok === false) setMenuScreen('founder'); else setFdSettings(msg.__akfdset); }
      if (msg && msg.__akfdsetsave) {
        setFdSettingsBusy(false);
        if (msg.__akfdsetsave.ok) Alert.alert('Enregistré', 'Paramètres société mis à jour.');
        else Alert.alert('Erreur', msg.__akfdsetsave.message || 'Enregistrement impossible.');
      }
      if (msg && msg.__akfdplansm) { setFdPlansM(msg.__akfdplansm); setFdPlansMLoading(false); }
      if (msg && msg.__akfdplanact) {
        setFdPlansMBusy(false);
        const r = msg.__akfdplanact;
        if (r && r.ok) {
          if (r.plans) setFdPlansM({ ok: true, plans: r.plans });
          if (fdPlanCloseRef.current) { fdPlanCloseRef.current(); fdPlanCloseRef.current = null; }
        } else { Alert.alert('Opération impossible', (r && r.message) || 'Réessaie dans un instant.'); }
      }
      if (msg && msg.__akfdbill) { setFdBilling(msg.__akfdbill); setFdBillingLoading(false); }
      if (msg && msg.__akfdpay) {
        setFdBillBusy(0);
        if (msg.__akfdpay.ok) { fetchFdBilling(fdBillingFilter); fetchFounder(); }
        else { Alert.alert('Action impossible', 'Réessaie dans un instant.'); }
      }
      if (msg && msg.__akfdstats) { setFdStats(msg.__akfdstats); setFdStatsLoading(false); }
      if (msg && msg.__akfdblog) { setFdBlog(msg.__akfdblog); setFdBlogLoading(false); }
      if (msg && msg.__akfdbloggen) {
        setBlogGenBusy(false);
        const r = msg.__akfdbloggen;
        if (r && r.ok) { setBlogGenMsg({ ok: true, title: r.title, url: r.url, published: r.published }); fetchFdBlog(fdBlogFilter); }
        else { setBlogGenMsg({ ok: false, error: (r && r.message) || 'Génération impossible.' }); }
      }
      if (msg && msg.__akfdblogbulk) {
        setBlogGenBusy(false);
        const r = msg.__akfdblogbulk;
        if (r && r.ok) { setBlogGenMsg({ ok: true, bulk: true, added: r.added, requested: r.requested }); fetchFdBlog(fdBlogFilter); }
        else { setBlogGenMsg({ ok: false, error: (r && r.message) || 'Génération impossible.' }); }
      }
      if (msg && msg.__akfdblogtopic) {
        setBlogTopicBusy(false);
        const r = msg.__akfdblogtopic;
        if (r && r.ok) { fetchFdBlog(fdBlogFilter); }
        else { Alert.alert('Programmation', (r && r.message) || 'Action impossible.'); }
      }
      if (msg && msg.__akfdsup) { setFdSupport(msg.__akfdsup); setFdSupportLoading(false); }
      if (msg && msg.__akfdthread) { setFdTicketLoading(false); if (msg.__akfdthread.ok === false) setMenuScreen('founder'); else setFdTicket(msg.__akfdthread); }
      if (msg && msg.__akfdreply) {
        setFdReplyBusy(false);
        const r = msg.__akfdreply;
        if (r && r.ok) { fetchFdThread(r.ticket_id); }
        else { Alert.alert('Support', (r && r.message) || 'Envoi impossible.'); }
      }
      if (msg && msg.__akfdcontacts) { setFdContacts(msg.__akfdcontacts); setFdContactsLoading(false); }
      if (msg && msg.__akfdctcthread) { setFdCtcThreadLoading(false); if (msg.__akfdctcthread.ok === false) setMenuScreen('founder'); else setFdCtcThread(msg.__akfdctcthread); }
      if (msg && msg.__akfdctcreply) {
        setFdCtcReplyBusy(false);
        const r = msg.__akfdctcreply;
        if (r && r.ok) { fetchFdCtcThread(r.contact_id); }
        else { Alert.alert('Contact', (r && r.message) || 'Envoi impossible.'); }
      }
      if (msg && msg.__akfdplans) {
        setFdPlans((msg.__akfdplans && msg.__akfdplans.plans) || []);
        if (msg.__akfdplans.ok === false) setFdCreateErr('Plans indisponibles : tire pour rafraîchir puis réessaie.');
      }
      if (msg && msg.__akfdcreate) {
        setFdCreateBusy(false);
        const r = msg.__akfdcreate;
        if (r && r.ok) { setFdCreateResult(r); fetchFounder(); }
        else { setFdCreateErr((r && r.message) || 'Création impossible.'); }
      }
      if (msg && msg.__aknotifread && msg.__aknotifread.ok) {
        const r = msg.__aknotifread;
        setKpi((k) => k ? { ...k, notif_unread: r.notif_unread, msg_unread: r.msg_unread, support_unread: r.support_unread } : k);
      }
      if (msg && msg.__akcoti) { setCoti(msg.__akcoti); setCotiLoading(false); }
      if (msg && msg.__akgrants) { setGrantsData(msg.__akgrants); setGrantsLoading(false); }
      if (msg && msg.__akassemblies) { setAssemblies(msg.__akassemblies); setSecLoading(false); }
      if (msg && msg.__akattendance) { setAttendance(msg.__akattendance); setSecLoading(false); }
      if (msg && msg.__akbroadcasts) { setBroadcasts(msg.__akbroadcasts); setSecLoading(false); }
      if (msg && msg.__aktickets) { setTickets(msg.__aktickets); setSecLoading(false); }
      if (msg && msg.__akcoach) { setCoach(msg.__akcoach); setSecLoading(false); }
      if (msg && msg.__akcreds && msg.__akcreds.email && msg.__akcreds.password) { pendingCreds.current = msg.__akcreds; }
      if (msg && msg.__aklogout) { if (finishLogoutRef.current) finishLogoutRef.current(); }
      if (msg && msg.__akact) {
        if (actTimer.current) { clearTimeout(actTimer.current); actTimer.current = null; }
        setActBusy(null);
        const r = msg.__akact;
        if (r.ok) {
          fetchKpis();
          const t = detailTopRef.current && detailTopRef.current.type;
          if (t === 'invoice') fetchInvoices();
          else if (t === 'quote') fetchQuotes();
          else if (t === 'cotisation') fetchCoti();
          else if (t === 'grant') fetchGrants();
          if (r.message) Alert.alert('C\'est fait ✅', r.message);
          if (r.invoice_id) {
            // Devis converti : on empile la facture créée. On NE rafraîchit PAS le devis en même
            // temps, sinon deux réponses de détail se croisent sur le sommet de pile.
            fetchInvoices(); fetchQuotes();
            pushDetail('invoice', r.invoice_id);
          } else if (r.archived) {
            popDetail();               // dossier archivé : il quitte la liste, on ferme la fiche
          } else {
            refreshDetail();           // la fiche se recharge avec le nouvel état
          }
        } else {
          Alert.alert('Action impossible', r.message || 'Réessayez dans un instant.');
        }
      }
      if (msg && msg.__akpdf) {
        setPdfBusy(false);
        if (msg.__akpdf.ok && msg.__akpdf.data) sharePdfData(msg.__akpdf.data);
        else Alert.alert('PDF', 'Impossible de récupérer le document.');
      }
      if (msg && msg.__akinvai) { setInvAI(msg.__akinvai && msg.__akinvai.ok ? (msg.__akinvai.analysis || '') : 'Analyse indisponible.'); setInvAILoading(false); }
      if (msg && msg.__akstatsai) { setStatsCockpit(msg.__akstatsai && msg.__akstatsai.ok ? (msg.__akstatsai.cockpit || null) : null); setStatsCockpitLoading(false); }
      if (msg && msg.__akaccount) {
        // Échec de chargement : on ne laisse pas l'écran Compte en chargement infini → retour au menu (alerte déjà affichée)
        if (msg.__akaccount.ok === false) { setMenuScreen(null); } else setAccount(msg.__akaccount);
      }
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
        const r = msg.__akmsgsent;
        setMsgSendResult({ ok: !!r.ok, seq: (msgSendSeq.current += 1) });
        if (r.ok) {
          if (openChannel) fetchChanMsgs(openChannel.id);
        } else {
          Alert.alert('Message non envoyé', r.message || 'Réessayez dans un instant. Votre message a été conservé.');
        }
      }
      if (msg && msg.__akwrite) {
        setSubmitting(false);
        const w = msg.__akwrite;
        if (w.ok) {
          const created = form && form.type;
          const editId = (form && form.edit) ? form.edit.invoice.id : 0;
          closeForm();
          Alert.alert('C\'est fait ✅', w.message || 'Enregistré avec succès.');
          // Rafraîchir les données concernées
          fetchKpis();
          if (created === 'member') fetchPeople(false);
          else if (created === 'client') fetchPeople(true);
          else if (created === 'invoice') { fetchInvoices(); if (editId) pushDetail('invoice', editId); }
          else if (created === 'quote') { fetchQuotes(); if (editId) pushDetail('quote', editId); }
          else if (created === 'project') fetchProjects();
          else if (created === 'expense') { fetchProjects(); if (expenseProject) pushDetail('project', expenseProject); }
          else if (created === 'payment') {
            fetchCoti(); setActive('menu'); setMenuScreen('cotisations');
            // Saisi depuis une fiche campagne : on y retourne, à jour
            if (paymentCampaign) pushDetail('cotisation', paymentCampaign);
          }
          else if (created === 'campaign') {
            fetchCoti(); setActive('menu'); setMenuScreen('cotisations');
            if (w.id) pushDetail('cotisation', w.id);   // on ouvre la campagne créée
          }
          else if (created === 'event') { fetchEvents(); setActive('menu'); setMenuScreen('agenda'); }
          else if (created === 'grant') {
            fetchGrants(); setActive('menu'); setMenuScreen('subventions');
            if (w.id) pushDetail('grant', w.id);   // on ouvre le dossier créé (étapes prêtes à cocher)
          }
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
    if (a.screen) { setQuickOpen(false); clearDetail(); openMenuScreen(a.screen); return; }
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
    if (path === '/mon-asso-devis') { openMenuScreen('devis'); return; }
    if (path === '/mon-asso-stats') { openMenuScreen('stats'); return; }
    if (path === '/agenda') { openMenuScreen('agenda'); return; }
    if (path === '/messages') { openMenuScreen('messages'); return; }
    setWebMode(true);
    inject(gotoJS(path));
  };

  // Ouvre une page web depuis une fiche détail (PDF, édition, mailto…)
  const openWeb = (path) => {
    // mailto:/tel: -> deleguer a l'OS (la WebView Android ne sait pas les ouvrir)
    if (/^(mailto:|tel:)/.test(path)) {
      Linking.openURL(path).catch(() => {});
      return;
    }
    // Lien externe (hors assokit.fr) -> ouvrir dans le navigateur systeme
    if (/^https?:\/\//.test(path) && !isAssokitUrl(path)) {
      Linking.openURL(path).catch(() => {});
      return;
    }
    clearDetail();
    closeForm();
    setMenuScreen(null);
    setWebMode(true);
    if (/^https?:\/\//.test(path)) {
      inject("(function(){ try { window.location.href=" + JSON.stringify(path) + "; } catch(e){} })(); true;");
    } else {
      inject(gotoJS(path));
    }
  };

  // Déconnexion propre : on ferme la session côté serveur en arrière-plan,
  // on efface TOUT (identifiants mémorisés + état auto-login) puis on revient
  // à l'écran d'accueil natif (jamais le site à l'écran, jamais de re-connexion auto).
  const doLogout = useCallback(() => {
    // Empêche tout ré-atterrissage automatique (fondateur / auto-login) pendant le démontage
    autoLoginTried.current = true;
    founderInit.current = true;
    // IMPORTANT : on NE bascule PAS authed=false ici. Sinon tous les écrans natifs
    // disparaissent et la WebView (qui charge /deconnexion.php) apparaît en clair
    // → c'est ce "flash de page web fondateur" que tu voyais. On couvre plutôt tout
    // avec un voile natif "Déconnexion…" pendant qu'on ferme la session côté serveur.
    setLoggingOut(true);
    setMenuScreen(null); setOpenChannel(null); clearDetail(); closeForm(); setWebMode(false);
    // Déconnexion serveur via fetch (même session) : on attend la réponse (ou 3 s max) AVANT le
    // démontage, sinon le cookie pouvait rester valide → auto-connexion sans mot de passe.
    inject(fetchJS('/deconnexion.php', '__aklogout'));
    if (logoutTimer.current) clearTimeout(logoutTimer.current);
    logoutTimer.current = setTimeout(() => finishLogoutRef.current && finishLogoutRef.current(), 3000);
  }, [inject, clearDetail, closeForm]);

  // Teardown complet côté racine : efface SecureStore + autoCreds + retour Welcome.
  // (onLogout fait clearCreds + setAutoCreds(null) + setPath(null) ; fallback si absent)
  const finishLogout = useCallback(() => {
    if (logoutTimer.current) { clearTimeout(logoutTimer.current); logoutTimer.current = null; }
    if (onLogout) onLogout();
    else { if (onClearCreds) onClearCreds(); if (onExitToWelcome) onExitToWelcome(); }
  }, [onLogout, onClearCreds, onExitToWelcome]);
  useEffect(() => { finishLogoutRef.current = finishLogout; }, [finishLogout]);

  const openProject = (id) => pushDetail('project', id);
  const openInvoice = (id) => pushDetail('invoice', id);
  const openPerson = (id) => pushDetail(isTpe ? 'client' : 'member', id);

  const detailTop = stack.length ? stack[stack.length - 1] : null;
  const detailTopRef = useRef(null);
  useEffect(() => { detailTopRef.current = detailTop; }, [detailTop]);
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
      <StatusBar barStyle="dark-content" backgroundColor={showHome ? '#EAF7F1' : '#fff'} />
      <View style={styles.webWrap}>
        <WebView
          ref={webRef}
          source={{ uri: BASE + startPath }}
          onLoadStart={() => setLoading(true)}
          onLoadEnd={() => {
            setLoading(false);
            const u = lastUrl.current || '';
            if (/\/(connexion|login|signup|mot-de-passe|verifier-email)/.test(u)) {
              inject(LOGIN_CSS);
            }
            if (/\/(connexion|login)/.test(u)) {
              inject(CAPTURE_CREDS_JS);
              if (pendingLogin.current) {
                const c = pendingLogin.current; pendingLogin.current = null;
                inject(autoLoginJS(c.email, c.password));
              } else if (autoCreds && !autoLoginTried.current) { autoLoginTried.current = true; inject(autoLoginJS(autoCreds.email, autoCreds.password)); }
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
          originWhitelist={['https://*']}
          onShouldStartLoadWithRequest={(req) => {
            const u = (req && req.url) || '';
            // Schemas non-http (mailto:, tel:, geo:…) -> deleguer a l'OS
            if (/^(mailto:|tel:|sms:|geo:|maps:)/i.test(u)) { Linking.openURL(u).catch(() => {}); return false; }
            // Lien externe (hors assokit.fr) -> navigateur systeme, pas dans la WebView applicative
            if (/^https?:\/\//i.test(u) && !isAssokitUrl(u)) { Linking.openURL(u).catch(() => {}); return false; }
            // Conformité stores (Apple 3.1.1 / Google) : on ne charge JAMAIS une page
            // de tarifs / d'abonnement Assokit dans l'app -> redirection vers le dashboard.
            if (/\/(tarifs|mon-asso-plan|mon-asso-abonnement|mon-asso-annuler-abonnement)(\b|\/|\?|$)/i.test(u)) {
              inject(gotoJS('/dashboard'));
              return false;
            }
            return true;
          }}
          setSupportMultipleWindows={false}
          applicationNameForUserAgent="AssokitApp/1.0"
          injectedJavaScript={APP_ONLY_CSS}
          style={styles.web}
        />
        {showHome && (
          <View style={styles.homeOverlay}>
            <NativeHome data={kpi} loading={kpiLoading} onRefresh={fetchKpis} onGoto={onGoto} profile={profile} error={kpiError} />
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
            <NativeInvoices data={invoices} loading={invLoading} onRefresh={fetchInvoices} onOpen={openInvoice} onNew={isAdminOrg ? () => openForm('invoice') : undefined}
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
              onNew={(isTpe ? isAdminOrg : canManageOrg) ? () => openForm(isTpe ? 'client' : 'member') : undefined}
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
              <BillingForm mode={form.type} edit={form.edit} onBack={closeForm} onSubmit={(d) => submitForm(form.type, d)} submitting={submitting} error={formErr} clients={pickClients} />
            )}
            {form.type === 'project' && (
              <ProjectForm onBack={closeForm} onSubmit={(d) => submitForm('project', d)} submitting={submitting} error={formErr} folders={folders} members={projMembers} />
            )}
            {form.type === 'expense' && (
              <ExpenseForm onBack={closeForm} onSubmit={(d) => submitForm('expense', d)} submitting={submitting} error={formErr}
                projects={(projects && projects.projects) || []} preProject={expenseProject}
                scanData={scanData} scanning={scanning} onScan={pickAndScan} />
            )}
            {form.type === 'campaign' && (
              <CampaignForm onBack={closeForm} onSubmit={(d) => submitForm('campaign', d)} submitting={submitting} error={formErr} />
            )}
            {form.type === 'payment' && (
              <CotisationPaymentForm onBack={closeForm} onSubmit={(d) => submitForm('payment', d)} submitting={submitting} error={formErr}
                campaigns={pickCampaigns} members={pickMembers} preCampaign={paymentCampaign} />
            )}
            {form.type === 'event' && (
              <EventForm onBack={closeForm} onSubmit={(d) => submitForm('event', d)} submitting={submitting} error={formErr} projects={pickProjects} />
            )}
            {form.type === 'grant' && (
              <GrantForm onBack={closeForm} onSubmit={(d) => submitForm('grant', d)} submitting={submitting} error={formErr} projects={pickProjects} />
            )}
          </View>
        )}
        {showMenu && (
          <View style={styles.homeOverlay}>
            {menuScreen === 'agenda' ? (
              <NativeAgenda data={events} loading={eventsLoading} onRefresh={fetchEvents} onOpen={(id) => pushDetail('event', id)} onNew={() => openForm('event')} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'subinvoices' ? (
              <NativeSubInvoices data={subInv} loading={subInvLoading} onRefresh={fetchSubInv} onBack={() => setMenuScreen(null)} onWeb={openWeb} />
            ) : menuScreen === 'devis' ? (
              <NativeQuotes data={quotes} loading={quotesLoading} onRefresh={fetchQuotes} onOpen={(id) => pushDetail('quote', id)} onNew={canManageOrg ? () => openForm('quote') : undefined} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'stats' ? (
              <NativeStats data={stats} loading={statsLoading} onRefresh={fetchStats} onBack={() => setMenuScreen(null)}
                cockpit={statsCockpit} cockpitLoading={statsCockpitLoading} onCockpit={onCockpit} />
            ) : menuScreen === 'members' ? (
              <NativePeople mode="members" data={people} loading={peopleLoading} onRefresh={() => fetchPeople(false)}
                onOpen={(id) => pushDetail('member', id)} onNew={canManageOrg ? () => openForm('member') : undefined} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'clients' ? (
              <NativePeople mode="clients" data={people} loading={peopleLoading} onRefresh={() => fetchPeople(true)}
                onOpen={(id) => pushDetail('client', id)} onNew={isAdminOrg ? () => openForm('client') : undefined} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'notifications' ? (
              <NativeNotifications data={notifs} loading={notifsLoading} onRefresh={fetchNotifs} onPress={onNotifPress} onMarkAllRead={onMarkAllRead} onBack={() => setMenuScreen(null)} />
            ) : menuScreen === 'cotisations' ? (
              <NativeCotisations data={coti} loading={cotiLoading} onRefresh={fetchCoti} onBack={() => setMenuScreen(null)}
                onNew={() => openForm('payment')} onNewCampaign={() => openForm('campaign')} canManage={canManageOrg} onOpen={(id) => pushDetail('cotisation', id)} />
            ) : menuScreen === 'subventions' ? (
              <NativeGrants data={grantsData} loading={grantsLoading} onRefresh={fetchGrants} onBack={() => setMenuScreen(null)}
                onNew={() => openForm('grant')} canManage={canManageOrg} onOpen={(id) => pushDetail('grant', id)} />
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
                onLogout={doLogout} />
            ) : menuScreen === 'messages' ? (
              openChannel ? (
                <NativeChat channel={openChannel} data={chanMsgs} loading={chanLoading} sending={sendingMsg} sendResult={msgSendResult}
                  onBack={() => { setOpenChannel(null); }} onSend={sendMessage} onRefresh={() => fetchChanMsgs(openChannel.id)} />
              ) : (
                <NativeChannels data={channels} loading={channelsLoading} onRefresh={fetchChannels} onOpen={openChannelFn} onBack={() => setMenuScreen(null)} />
              )
            ) : menuScreen === 'founder' ? (
              <NativeFounder data={founderData} loading={founderLoading} onRefresh={fetchFounder}
                onBack={() => { setMenuScreen(null); setActive('accueil'); }}
                hasAsso={!!(kpi && kpi.org_name)} onGotoAsso={() => { setMenuScreen(null); setActive('accueil'); }}
                onLogout={doLogout} onTile={onFounderTile} onNotifs={() => openMenuScreen('notifications')}
                notifCount={(kpi && kpi.notif_unread) || 0} />
            ) : menuScreen === 'fdorgs' ? (
              <NativeFounderOrgs data={fdOrgs} loading={fdOrgsLoading} filter={fdOrgsFilter}
                onFilter={(f) => { setFdOrgs(null); setFdOrgsFilter(f); fetchFdOrgs(f); }}
                onRefresh={() => fetchFdOrgs(fdOrgsFilter)} onBack={() => openMenuScreen('founder')}
                onAction={doFounderAction} onOpen={openFdOrgDetail} busyId={fdBusyId} />
            ) : menuScreen === 'fdorgdetail' ? (
              <NativeFounderOrgDetail data={fdOrgDetail} loading={fdOrgDetailLoading} busy={fdOrgEditBusy}
                onBack={() => openFdOrgs(fdOrgsFilter)} onRefresh={() => fdOrgDetailIdRef.current && fetchFdOrgDetail(fdOrgDetailIdRef.current)}
                onEdit={doOrgEdit} onAction={(id, action) => doOrgEdit({ org_id: id, action })} />
            ) : menuScreen === 'fdbilling' ? (
              <NativeFounderBilling data={fdBilling} loading={fdBillingLoading} filter={fdBillingFilter}
                onFilter={(f) => { setFdBilling(null); setFdBillingFilter(f); fetchFdBilling(f); }}
                onRefresh={() => fetchFdBilling(fdBillingFilter)} onBack={() => openMenuScreen('founder')}
                onPay={doFounderPay} busyId={fdBillBusy} />
            ) : menuScreen === 'fdstats' ? (
              <NativeFounderStats data={fdStats} loading={fdStatsLoading} onRefresh={fetchFdStats} onBack={() => openMenuScreen('founder')} />
            ) : menuScreen === 'fdblog' ? (
              <NativeFounderBlog data={fdBlog} loading={fdBlogLoading} onRefresh={() => fetchFdBlog(fdBlogFilter)} onBack={() => openMenuScreen('founder')} onWeb={openWeb}
                filter={fdBlogFilter} onFilter={(f) => { setFdBlogFilter(f); setFdBlog(null); fetchFdBlog(f); }}
                onGenerate={doBlogGenerate} onBulk={doBlogBulk} onProgram={doBlogProgram} onDeleteTopic={doBlogDeleteTopic}
                genBusy={blogGenBusy} genMsg={blogGenMsg} topicBusy={blogTopicBusy} onClearMsg={() => setBlogGenMsg(null)} />
            ) : menuScreen === 'fdsupport' ? (
              <NativeFounderSupport data={fdSupport} loading={fdSupportLoading} filter={fdSupportFilter}
                onFilter={(f) => { setFdSupport(null); setFdSupportFilter(f); fetchFdSupport(f); }}
                onRefresh={() => fetchFdSupport(fdSupportFilter)} onBack={() => openMenuScreen('founder')}
                onOpen={(t) => openFdThread(t)} />
            ) : menuScreen === 'fdthread' ? (
              <NativeFounderSupportThread data={fdTicket} loading={fdTicketLoading} onRefresh={() => fdTicket && fetchFdThread(fdTicket.ticket.id)}
                onBack={() => openFdSupport(fdSupportFilter)} onReply={doSupportReply} replyBusy={fdReplyBusy} />
            ) : menuScreen === 'fdplans' ? (
              <NativeFounderPlans data={fdPlansM} loading={fdPlansMLoading} busy={fdPlansMBusy}
                onRefresh={fetchFdPlansM} onBack={() => openMenuScreen('founder')}
                onSave={doPlanSave} onDelete={doPlanDelete} />
            ) : menuScreen === 'fdprojects' ? (
              <NativeFounderProjects data={fdProjects} loading={fdProjectsLoading} filter={fdProjFilter}
                onFilter={(f) => { setFdProjects(null); setFdProjFilter(f); fetchFdProjects(f); }}
                onRefresh={() => fetchFdProjects(fdProjFilter)} onBack={() => openMenuScreen('founder')} />
            ) : menuScreen === 'fdactivity' ? (
              <NativeFounderActivity data={fdActivity} loading={fdActivityLoading}
                onRefresh={fetchFdActivity} onBack={() => openMenuScreen('founder')} />
            ) : menuScreen === 'fdsettings' ? (
              <NativeFounderSettings data={fdSettings} loading={fdSettingsLoading} busy={fdSettingsBusy}
                onRefresh={fetchFdSettings} onBack={() => openMenuScreen('founder')} onSave={doSaveSettings} />
            ) : menuScreen === 'fdprospects' ? (
              <NativeFounderProspects data={fdProspects} loading={fdProspectsLoading} busy={fdProspectsBusy}
                onRefresh={fetchFdProspects} onBack={() => openMenuScreen('founder')}
                onImport={doProspectImport} onQueue={doProspectQueue} onStatus={doProspectStatus} onDelete={doProspectDelete} />
            ) : menuScreen === 'fddir' ? (
              <NativeFounderDirectory data={fdDir} loading={fdDirLoading} busy={fdDirBusy} nav={fdDirNav}
                onBack={() => openMenuScreen('founder')} onRefresh={() => {
                  if (fdDirNav.dept) fetchFdDir({ dept: fdDirNav.dept, category: fdDirNav.category || '', page: fdDirNav.page || 1 });
                  else if (fdDirNav.region) fetchFdDir({ region: fdDirNav.region });
                  else fetchFdDir({});
                }}
                onOpenRegion={dirOpenRegion} onOpenDept={dirOpenDept} onBackRoot={dirBackRoot} onBackRegion={dirBackRegion}
                onSetEmail={doDirSetEmail} onToProspect={doDirToProspect} />
            ) : menuScreen === 'fdcreateorg' ? (
              <NativeFounderCreateOrg plans={fdPlans} busy={fdCreateBusy} result={fdCreateResult} error={fdCreateErr}
                onSubmit={doCreateOrg} onBack={() => openMenuScreen('founder')}
                onCopy={(txt) => Alert.alert('Identifiants', txt + '\n\n(Maintiens appuyé sur le texte pour le copier.)')}
                onDone={() => { setFdCreateResult(null); openFdOrgs('all'); }} />
            ) : menuScreen === 'fdcontacts' ? (
              <NativeFounderContacts data={fdContacts} loading={fdContactsLoading} onRefresh={fetchFdContacts} onBack={() => openMenuScreen('founder')} onOpen={openFdCtcThread} />
            ) : menuScreen === 'fdctcthread' ? (
              <NativeFounderContactThread data={fdCtcThread} loading={fdCtcThreadLoading} onRefresh={() => fdCtcThread && fetchFdCtcThread(fdCtcThread.contact.id)}
                onBack={openFdContacts} onReply={doContactReply} replyBusy={fdCtcReplyBusy} />
            ) : (
              <NativeMore
                orgName={kpi && kpi.org_name}
                initials={kpi && kpi.org_initials}
                logo={kpi && kpi.org_logo}
                canManage={canManageOrg}
                isFounder={!!(kpi && kpi.is_founder)}
                isAdmin={!!(kpi && (kpi.role === 'admin' || kpi.is_founder))}
                isTpe={isTpe}
                counts={{ msg: kpi && kpi.msg_unread, support: kpi && kpi.support_unread, notif: kpi && kpi.notif_unread }}
                onNav={onMoreNav}
                onLogout={doLogout}
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
              <NativeInvoiceDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onWeb={openWeb} busy={actBusy}
                onEdit={(dd, q) => openForm(q ? 'quote' : 'invoice', 0, { invoice: dd.invoice, lines: dd.lines })}
                onAction={isAdminOrg ? (act) => runAction('/api/app-invoice-action.php', { invoice_id: detailTop.id, action: act }, act) : null} />
            )}
            {detailTop.type === 'quote' && (
              <NativeInvoiceDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onWeb={openWeb} busy={actBusy}
                onEdit={(dd, q) => openForm(q ? 'quote' : 'invoice', 0, { invoice: dd.invoice, lines: dd.lines })}
                onAction={canManageOrg ? (act) => runAction('/api/app-quote-action.php', { quote_id: detailTop.id, action: act }, act) : null} />
            )}
            {detailTop.type === 'event' && (
              <NativeEventDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} onWeb={openWeb} />
            )}
            {detailTop.type === 'cotisation' && (
              <NativeCotisationDetail entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} busy={actBusy}
                onNewPayment={() => openForm('payment', detailTop.id)}
                onAction={(paymentId, act) => runAction('/api/app-cotisation-action.php', { payment_id: paymentId, action: act }, paymentId)} />
            )}
            {detailTop.type === 'grant' && (
              <NativeGrantDetail key={detailTop.id} entry={detailTop} onBack={popDetail} onRefresh={refreshDetail} busy={actBusy}
                onAction={(act, extra) => runAction('/api/app-grant-action.php', { grant_id: detailTop.id, action: act, ...(extra || {}) }, act)} />
            )}
          </View>
        )}
        {loading && showWeb && (
          <View style={styles.loader} pointerEvents="none">
            <ActivityIndicator size="large" color={BRAND} />
          </View>
        )}
        {showWeb && webMode && (
          <TouchableOpacity accessibilityRole="button" style={styles.floatBack} activeOpacity={0.85}
            onPress={() => { if (canGoBack && webRef.current) webRef.current.goBack(); else { setWebMode(false); setMenuScreen(null); } }}>
            <Ionicons name="chevron-back" size={20} color={INK} />
            <Text style={styles.floatBackTxt}>Retour</Text>
          </TouchableOpacity>
        )}
        {!authed && !webMode && (
          <View style={styles.homeOverlay}>
            <NativeLogin
              onSubmit={submitLogin}
              busy={loginBusy}
              error={loginErr}
              onBack={onExitToWelcome}
              onForgot={() => { setLoginErr(''); openWeb('/mot-de-passe-oublie'); }}
              onDemo={() => { setLoginErr(''); openWeb('/signup'); }}
              hasFaceId={!!autoCreds}
              onFaceId={() => autoCreds && submitLogin(autoCreds.email, autoCreds.password)}
            />
          </View>
        )}
      </View>

      {authed && isFounder && !['founder', 'fdorgs', 'fdorgdetail', 'fdbilling', 'fdplans', 'fdprojects', 'fdactivity', 'fdsettings', 'fdprospects', 'fddir', 'fdstats', 'fdblog', 'fdsupport', 'fdthread', 'fdcreateorg', 'fdcontacts', 'fdctcthread'].includes(menuScreen) && !webMode && (
        <TouchableOpacity accessibilityRole="button"
          style={styles.founderStrip}
          activeOpacity={0.9}
          onPress={() => { setOpenChannel(null); openMenuScreen('founder'); }}
        >
          <View style={styles.founderStripStar}><Ionicons name="star" size={15} color="#78350F" /></View>
          <Text style={styles.founderStripTxt}>Mode Fondateur</Text>
          <Text style={styles.founderStripHint}>Revenir au pilotage</Text>
          <Ionicons name="chevron-forward" size={16} color="#FCD34D" />
        </TouchableOpacity>
      )}

      {authed && !['fdctcthread', 'fdthread', 'fdorgdetail', 'fdplans', 'fdsettings', 'fdprospects', 'fddir'].includes(menuScreen) && (
      <View style={styles.tabBar}>
        <BlurView intensity={26} tint="light" style={styles.tabBlur} pointerEvents="none" />
        {TABS.map((tab) => {
          if (tab.key === 'add') {
            return (
              <TouchableOpacity key={tab.key} style={styles.fabWrap} onPress={() => goTab(tab)} activeOpacity={0.85}
                accessibilityRole="button" accessibilityLabel="Créer">
                <View style={styles.fab}>
                  <Ionicons name="add" size={30} color="#fff" />
                </View>
              </TouchableOpacity>
            );
          }
          const isActive = active === tab.key;
          const tabBadge = tab.key === 'menu' ? (kpi && kpi.notif_unread) || 0 : 0;
          return (
            <TouchableOpacity key={tab.key} style={styles.tab} onPress={() => goTab(tab)} activeOpacity={0.7}
              accessibilityRole="tab" accessibilityState={{ selected: isActive }}
              accessibilityLabel={tab.label + (tabBadge > 0 ? ', ' + tabBadge + ' non lus' : '')}>
              <View>
                <Ionicons name={isActive ? tab.icon : tab.icon + '-outline'} size={23} color={isActive ? BRAND : MUTE} />
                {tabBadge > 0 && <View style={styles.tabBadge}><Text style={styles.tabBadgeTxt}>{tabBadge > 99 ? '99+' : tabBadge}</Text></View>}
              </View>
              <Text style={[styles.tabLabel, { color: isActive ? BRAND : MUTE }]}>{tab.label}</Text>
            </TouchableOpacity>
          );
        })}
      </View>
      )}

      <Modal visible={quickOpen} transparent animationType="fade" onRequestClose={() => setQuickOpen(false)}>
        <Pressable style={styles.sheetBackdrop} onPress={() => setQuickOpen(false)}>
          <Pressable style={styles.sheet}>
            <View style={styles.sheetHandle} />
            <Text style={styles.sheetTitle}>Créer</Text>
            {QUICK_ACTIONS.map((a) => (
              <TouchableOpacity accessibilityRole="button" key={a.label} style={styles.qaRow} onPress={() => onQuick(a)} activeOpacity={0.7}>
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

      {loggingOut && (
        <View style={styles.logoutVeil} pointerEvents="auto">
          <ActivityIndicator size="large" color="#fff" />
          <Text style={styles.logoutVeilTxt}>Déconnexion…</Text>
        </View>
      )}
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
          // Fail-closed : on n'auto-connecte que si la biometrie reussit,
          // ou si l'appareil n'a pas de biometrie (SecureStore reste protege
          // par le verrou d'appareil). Toute erreur => on refuse l'auto-login.
          let ok = false;
          try {
            const hasHw = await LocalAuthentication.hasHardwareAsync();
            const enrolled = await LocalAuthentication.isEnrolledAsync();
            if (hasHw && enrolled) {
              const r = await LocalAuthentication.authenticateAsync({ promptMessage: 'Déverrouiller Assokit', fallbackLabel: 'Code', cancelLabel: 'Annuler' });
              ok = !!r.success;
            } else {
              ok = true;
            }
          } catch (e) { ok = false; }
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
  // Android edge-to-edge (SDK 57) : SafeAreaView natif n'inset pas → on compense la barre de statut
  safe: { flex: 1, backgroundColor: '#fff', paddingTop: Platform.OS === 'android' ? (StatusBar.currentHeight || 24) : 0 },
  webWrap: { flex: 1, backgroundColor: '#fff' },
  logoutVeil: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: '#059669', alignItems: 'center', justifyContent: 'center', zIndex: 999, elevation: 999 },
  logoutVeilTxt: { color: '#fff', fontSize: 15, fontWeight: '700', marginTop: 14, letterSpacing: 0.3 },
  web: { flex: 1, backgroundColor: '#ffffff' },
  loader: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(255,255,255,0.65)' },
  homeOverlay: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: '#F4F6FA' },

  /* Accueil natif */
  homeAurora: { flex: 1, backgroundColor: '#EEF3FA' },
  auroraOrbA: { position: 'absolute', top: 120, right: -70, width: 240, height: 240, borderRadius: 120, backgroundColor: 'rgba(45,212,191,0.20)' },
  auroraOrbB: { position: 'absolute', bottom: 40, left: -80, width: 260, height: 260, borderRadius: 130, backgroundColor: 'rgba(129,140,248,0.18)' },
  homeScroll: { flex: 1, backgroundColor: 'transparent' },
  homeContent: { paddingBottom: 28 },
  hHeaderWrap: { borderBottomLeftRadius: 30, borderBottomRightRadius: 30, overflow: 'hidden', shadowColor: '#1E293B', shadowOpacity: 0.10, shadowRadius: 20, shadowOffset: { width: 0, height: 10 }, elevation: 6 },
  hHeader: { paddingTop: 28, paddingBottom: 54, paddingHorizontal: 22, position: 'relative', overflow: 'hidden', backgroundColor: 'rgba(255,255,255,0.5)', borderBottomWidth: 1, borderBottomColor: 'rgba(255,255,255,0.6)' },
  hOrb1: { position: 'absolute', top: -60, right: -40, width: 190, height: 190, borderRadius: 95, backgroundColor: 'rgba(45,212,191,0.18)' },
  hOrb2: { position: 'absolute', bottom: -70, left: -50, width: 170, height: 170, borderRadius: 85, backgroundColor: 'rgba(129,140,248,0.20)' },
  hHeaderRow: { flexDirection: 'row', alignItems: 'center' },
  hHello: { color: '#475569', fontSize: 16, fontWeight: '500' },
  hName: { color: '#0F172A', fontSize: 27, fontWeight: '800', letterSpacing: -0.5, marginTop: 2 },
  hOrgPill: { flexDirection: 'row', alignItems: 'center', gap: 7, marginTop: 10, alignSelf: 'flex-start', backgroundColor: 'rgba(255,255,255,0.62)', borderRadius: 999, paddingVertical: 5, paddingHorizontal: 11, borderWidth: 1, borderColor: 'rgba(15,23,42,0.06)', maxWidth: '92%' },
  hOrgDot: { width: 7, height: 7, borderRadius: 4, backgroundColor: '#059669' },
  hOrg: { color: '#0F172A', fontSize: 13, fontWeight: '600', flexShrink: 1 },
  hAvatar: { width: 54, height: 54, borderRadius: 17, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: 'rgba(15,23,42,0.06)', shadowColor: '#0F172A', shadowOpacity: 0.12, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 4 },
  hAvatarTxt: { color: '#059669', fontSize: 18, fontWeight: '800' },
  hAvatarImg: { width: 42, height: 42, borderRadius: 11 },

  homeLoader: { paddingTop: 60, alignItems: 'center' },
  homeLoaderTxt: { color: MUTE, marginTop: 12, fontSize: 14 },

  /* Carte vedette — verre liquide */
  spotShadow: { marginHorizontal: 16, marginTop: -34, borderRadius: 24, shadowColor: '#025138', shadowOpacity: 0.20, shadowRadius: 24, shadowOffset: { width: 0, height: 14 }, elevation: 9 },
  spotCard: { borderRadius: 24, padding: 18, overflow: 'hidden', backgroundColor: 'rgba(255,255,255,0.72)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.9)' },
  spotGloss: { position: 'absolute', top: 0, left: 0, right: 0, height: 46, backgroundColor: 'rgba(255,255,255,0.35)' },
  spotTopRow: { flexDirection: 'row', alignItems: 'center', gap: 9 },
  spotIconWrap: { width: 30, height: 30, borderRadius: 10, backgroundColor: '#D1FAE5', alignItems: 'center', justifyContent: 'center' },
  spotLabel: { flex: 1, fontSize: 12.5, fontWeight: '700', color: '#065F46', letterSpacing: 0.2 },
  spotChip: { backgroundColor: 'rgba(5,150,105,0.12)', borderRadius: 999, paddingHorizontal: 9, paddingVertical: 4 },
  spotChipTxt: { fontSize: 10.5, fontWeight: '700', color: '#047857' },
  spotValue: { fontSize: 34, fontWeight: '800', color: '#052E23', letterSpacing: -1, marginTop: 12 },
  spotBarTrack: { height: 9, borderRadius: 6, backgroundColor: 'rgba(6,95,70,0.10)', marginTop: 14, overflow: 'hidden' },
  spotBarFill: { height: 9, borderRadius: 6 },
  spotBarMeta: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 8 },
  spotBarPct: { fontSize: 13, fontWeight: '800', color: '#047857' },
  spotBarLabel: { fontSize: 12, fontWeight: '500', color: '#64748B' },

  kpiGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', paddingHorizontal: 16, marginTop: 18 },
  kpiShadow: { width: '48%', marginBottom: 14, borderRadius: 24, shadowColor: '#0B3B2A', shadowOpacity: 0.09, shadowRadius: 22, shadowOffset: { width: 0, height: 12 }, elevation: 4 },
  kpiCard: { borderRadius: 24, padding: 16, overflow: 'hidden', backgroundColor: 'rgba(255,255,255,0.86)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.95)' },
  kpiGloss: { position: 'absolute', top: 0, left: 0, right: 0, height: 40, backgroundColor: 'rgba(255,255,255,0.45)' },
  kpiTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  kpiIcon: { width: 42, height: 42, borderRadius: 13, alignItems: 'center', justifyContent: 'center', shadowColor: '#0B3B2A', shadowOpacity: 0.08, shadowRadius: 6, shadowOffset: { width: 0, height: 3 }, elevation: 2 },
  kpiDot: { width: 8, height: 8, borderRadius: 5 },
  kpiValue: { fontSize: 30, fontWeight: '800', color: INK, marginTop: 14, letterSpacing: -0.5 },
  kpiLabel: { fontSize: 14, fontWeight: '700', color: '#1E293B', marginTop: 2 },
  kpiSub: { fontSize: 12, color: '#64748B', marginTop: 3, fontWeight: '500' },

  sectionTitle: { fontSize: 12, fontWeight: '700', color: '#94A3B8', marginTop: 14, marginBottom: 12, marginHorizontal: 20, textTransform: 'uppercase', letterSpacing: 0.7 },
  shortcuts: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', paddingHorizontal: 16 },
  shortcut: { width: '48%', backgroundColor: 'rgba(255,255,255,0.72)', borderRadius: 18, paddingVertical: 15, paddingHorizontal: 13, marginBottom: 12, flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: 'rgba(255,255,255,0.9)', shadowColor: '#0B3B2A', shadowOpacity: 0.06, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: 2 },
  shortcutIcon: { width: 38, height: 38, borderRadius: 11, backgroundColor: 'rgba(236,253,245,0.9)', alignItems: 'center', justifyContent: 'center', marginRight: 10, borderWidth: 1, borderColor: '#D1FAE5' },
  shortcutTxt: { flex: 1, fontSize: 14, fontWeight: '600', color: INK },

  openFullShadow: { marginTop: 10, marginHorizontal: 16, borderRadius: 16, shadowColor: '#047857', shadowOpacity: 0.32, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: 4 },
  openFull: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 16, borderRadius: 16 },
  openFullTxt: { fontSize: 15, fontWeight: '750', color: '#fff' },

  /* Projets natifs */
  projWrap: { flex: 1, backgroundColor: '#EEF3FA' },
  projHeader: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 20, paddingTop: 18, paddingBottom: 12 },
  projTitle: { fontSize: 26, fontWeight: '800', color: INK, letterSpacing: -0.4 },
  projSub: { fontSize: 13.5, color: MUTE, marginTop: 2 },
  projNewBtn: { flexDirection: 'row', alignItems: 'center', gap: 5, backgroundColor: BRAND, paddingVertical: 10, paddingHorizontal: 14, borderRadius: 12, shadowColor: BRAND, shadowOpacity: 0.3, shadowRadius: 8, shadowOffset: { width: 0, height: 4 }, elevation: 4 },
  projNewTxt: { color: '#fff', fontSize: 14, fontWeight: '700' },
  projCard: { backgroundColor: 'rgba(255,255,255,0.82)', borderRadius: 20, padding: 16, paddingLeft: 20, marginBottom: 12, borderWidth: 1, borderColor: 'rgba(255,255,255,0.9)', shadowColor: '#0B3B2A', shadowOpacity: 0.08, shadowRadius: 18, shadowOffset: { width: 0, height: 9 }, elevation: 3, position: 'relative', overflow: 'hidden' },
  projAccent: { position: 'absolute', top: 0, bottom: 0, left: 0, width: 4 },
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
  detailWrap: { flex: 1, backgroundColor: '#F2F5FB' },
  dHeader: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12, paddingTop: 10, paddingBottom: 12, backgroundColor: 'rgba(255,255,255,0.92)', borderBottomWidth: 1, borderBottomColor: 'rgba(226,232,240,0.8)', shadowColor: '#0B3B2A', shadowOpacity: 0.04, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 2, zIndex: 2 },
  dBack: { width: 38, height: 38, borderRadius: 12, backgroundColor: '#F3F6F5', alignItems: 'center', justifyContent: 'center' },
  dHeadAction: { width: 38, height: 38, borderRadius: 12, backgroundColor: BRAND, alignItems: 'center', justifyContent: 'center', shadowColor: BRAND, shadowOpacity: 0.3, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 3 },
  dTitle: { flex: 1, textAlign: 'center', fontSize: 17, fontWeight: '750', color: INK, letterSpacing: -0.2 },
  detailContent: { padding: 18, paddingBottom: 40 },
  dName: { fontSize: 24, fontWeight: '800', color: INK, letterSpacing: -0.4 },
  dFolder: { fontSize: 14, color: MUTE, marginTop: 4 },
  dCard: { backgroundColor: '#fff', borderRadius: 18, padding: 16, marginTop: 14, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  dCardRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  dBigTitle: { fontSize: 22, fontWeight: '800', color: INK, letterSpacing: -0.3, lineHeight: 28 },
  dInfoRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 12, borderTopWidth: 1, borderTopColor: '#F1F5F9' },
  dLabel: { fontSize: 13.5, color: '#64748B', fontWeight: '600', flex: 1, paddingRight: 12 },
  dValue: { fontSize: 14, color: INK, fontWeight: '700', textAlign: 'right', flexShrink: 1 },
  dCardLabel: { fontSize: 14, fontWeight: '600', color: '#334155' },
  dCardStrong: { fontSize: 14.5, fontWeight: '800', color: INK },
  dSteps: { fontSize: 12.5, color: MUTE, marginTop: 8 },
  dSection: { fontSize: 12, fontWeight: '800', color: '#059669', marginTop: 24, marginBottom: 10, textTransform: 'uppercase', letterSpacing: 0.7 },
  dLockCard: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: '#F1F5F9', borderRadius: 16, padding: 16, marginTop: 18, borderWidth: 1, borderColor: '#E2E8F0' },
  dLockTxt: { flex: 1, fontSize: 13, lineHeight: 19, color: '#64748B', fontWeight: '500' },
  dText: { fontSize: 14.5, color: '#334155', lineHeight: 21, marginTop: 8 },
  /* Bloc description riche */
  dInfoCard: { backgroundColor: '#fff', borderRadius: 18, padding: 18, marginTop: 14, borderWidth: 1, borderColor: 'rgba(255,255,255,0.9)', shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 14, shadowOffset: { width: 0, height: 7 }, elevation: 2 },
  dEyebrow: { fontSize: 11.5, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 0.8, marginBottom: 12 },
  dPara: { fontSize: 15, color: '#334155', lineHeight: 23, marginBottom: 4 },
  dBullet: { flexDirection: 'row', alignItems: 'flex-start', marginBottom: 11 },
  dBulletDot: { width: 7, height: 7, borderRadius: 4, marginTop: 8, marginRight: 12 },
  dBulletTxt: { flex: 1, fontSize: 14.5, color: '#334155', lineHeight: 22 },
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
  dActBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 12, paddingVertical: 15, borderRadius: 16, borderWidth: 1.5, borderColor: '#A7F3D0', backgroundColor: '#ECFDF5' },
  dActBtnTxt: { fontSize: 15, fontWeight: '800', color: '#065F46' },
  dDangerBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 12, paddingVertical: 14, borderRadius: 16, borderWidth: 1.5, borderColor: '#FECACA', backgroundColor: '#FEF2F2' },
  dDangerBtnTxt: { fontSize: 14.5, fontWeight: '700', color: '#991B1B' },
  ckPayRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 13, borderTopWidth: 1, borderTopColor: '#F1F5F9' },
  ckPayName: { fontSize: 14.5, fontWeight: '700', color: INK },
  ckPaySub: { fontSize: 12, color: '#94A3B8', marginTop: 2 },
  ckPayAmt: { fontSize: 15, fontWeight: '800', color: INK, fontVariant: ['tabular-nums'] },
  payAct: { width: 38, height: 38, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: '#ECFDF5', borderWidth: 1, borderColor: '#A7F3D0' },
  grStepRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 13, borderTopWidth: 1, borderTopColor: '#F1F5F9' },
  grStepTxt: { flex: 1, fontSize: 14.5, color: INK, fontWeight: '600' },
  grStepTxtDone: { color: '#94A3B8', textDecorationLine: 'line-through', fontWeight: '500' },
  tierChip: { paddingHorizontal: 12, paddingVertical: 9, borderRadius: 11, borderWidth: 1.5, borderColor: '#E2E8F0', backgroundColor: '#fff', marginRight: 8, marginBottom: 8 },
  tierChipOn: { borderColor: BRAND, backgroundColor: '#ECFDF5' },
  tierChipTxt: { fontSize: 13, fontWeight: '700', color: '#64748B' },
  tierChipTxtOn: { color: BRAND },

  /* Formulaires natifs */
  formContent: { padding: 18, paddingBottom: 30 },
  formFooter: { padding: 14, paddingBottom: Platform.OS === 'ios' ? 26 : 24, backgroundColor: '#fff', borderTopWidth: 1, borderTopColor: '#EEF2F6' },
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
  pickedClient: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: '#F0FDF9', borderWidth: 1, borderColor: '#D1FAE5', borderRadius: 12, padding: 14 },
  pickedName: { fontSize: 15, fontWeight: '700', color: INK },
  projPickBtn: { flexDirection: 'row', alignItems: 'center', gap: 9, backgroundColor: '#F8FAFC', borderWidth: 1, borderColor: '#E2E8F0', borderRadius: 12, paddingVertical: 14, paddingHorizontal: 15 },
  projPickTxt: { fontSize: 14.5, fontWeight: '600', color: '#64748B' },
  projPersonAv: { width: 38, height: 38, borderRadius: 11, alignItems: 'center', justifyContent: 'center' },
  projPersonAvTxt: { color: '#fff', fontSize: 13, fontWeight: '800' },
  projPersonRole: { fontSize: 12, color: '#94A3B8', marginTop: 1 },
  projTeamWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  projTeamChip: { flexDirection: 'row', alignItems: 'center', gap: 7, backgroundColor: '#F0FDF9', borderWidth: 1, borderColor: '#D1FAE5', borderRadius: 999, paddingLeft: 4, paddingRight: 11, paddingVertical: 4 },
  projTeamAv: { width: 26, height: 26, borderRadius: 13, alignItems: 'center', justifyContent: 'center' },
  projTeamAvTxt: { color: '#fff', fontSize: 10.5, fontWeight: '800' },
  projTeamName: { fontSize: 13, fontWeight: '700', color: INK },
  projTeamDone: { backgroundColor: BRAND, borderRadius: 13, paddingVertical: 15, alignItems: 'center', marginTop: 10 },
  projTeamDoneTxt: { color: '#fff', fontSize: 15, fontWeight: '800' },
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
  chanCard: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', borderRadius: 15, padding: 14, marginBottom: 10, borderWidth: 1, borderColor: '#EFF3F1', shadowColor: '#0B3B2A', shadowOpacity: 0.05, shadowRadius: 12, shadowOffset: { width: 0, height: 5 }, elevation: 2 },
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
  moreAvatar: { width: 54, height: 54, borderRadius: 17, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center', marginRight: 14, borderWidth: 1, borderColor: 'rgba(15,23,42,0.06)', shadowColor: '#0F172A', shadowOpacity: 0.10, shadowRadius: 10, shadowOffset: { width: 0, height: 5 }, elevation: 3 },
  moreAvatarImg: { width: 42, height: 42, borderRadius: 11 },
  moreAvatarTxt: { fontSize: 19, fontWeight: '800', color: BRAND },
  moreOrg: { fontSize: 18, fontWeight: '800', color: INK },
  moreSub: { fontSize: 13, color: MUTE, marginTop: 2 },
  moreGroupTitle: { fontSize: 11, fontWeight: '700', color: '#94A3B8', marginLeft: 2, textTransform: 'uppercase', letterSpacing: 0.6 },
  moreGroupHead: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 10 },
  adminTag: { flexDirection: 'row', alignItems: 'center', gap: 3, backgroundColor: '#FEF3C7', borderColor: '#FDE68A', borderWidth: 1, borderRadius: 999, paddingHorizontal: 7, paddingVertical: 2 },
  adminTagCorner: { position: 'absolute', top: 6, right: 6, backgroundColor: '#FEF3C7', borderColor: '#FDE68A', borderWidth: 1, borderRadius: 6, paddingHorizontal: 5, paddingVertical: 1, zIndex: 2 },
  adminTagTxt: { fontSize: 9, fontWeight: '800', color: '#B45309', letterSpacing: 0.2 },
  moreGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  moreItem: { width: '31%', backgroundColor: '#fff', borderRadius: 16, paddingVertical: 16, paddingHorizontal: 6, alignItems: 'center', borderWidth: 1, borderColor: '#EFF3F1', shadowColor: '#0B3B2A', shadowOpacity: 0.05, shadowRadius: 12, shadowOffset: { width: 0, height: 5 }, elevation: 2 },
  moreItemIcon: { width: 44, height: 44, borderRadius: 13, backgroundColor: '#ECFDF5', alignItems: 'center', justifyContent: 'center', marginBottom: 8, borderWidth: 1, borderColor: '#D1FAE5' },
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
  markAllBtn: { flexDirection: 'row', alignItems: 'center', gap: 7, alignSelf: 'flex-end', marginHorizontal: 16, marginTop: 4, marginBottom: -4, paddingVertical: 8, paddingHorizontal: 12, borderRadius: 10, backgroundColor: '#ECFDF5', borderWidth: 1, borderColor: '#A7F3D0' },
  markAllTxt: { fontSize: 12.5, fontWeight: '700', color: BRAND },
  chanNewPill: { backgroundColor: BRAND, borderRadius: 999, paddingHorizontal: 9, paddingVertical: 3, marginRight: 4 },
  chanNewTxt: { color: '#fff', fontSize: 10.5, fontWeight: '800' },
  moreBadge: { position: 'absolute', top: -5, right: -8, minWidth: 18, height: 18, borderRadius: 9, backgroundColor: '#EF4444', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4, borderWidth: 2, borderColor: '#fff' },
  moreBadgeTxt: { color: '#fff', fontSize: 10, fontWeight: '800' },
  tabBadge: { position: 'absolute', top: -6, right: -10, minWidth: 17, height: 17, borderRadius: 9, backgroundColor: '#EF4444', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4, borderWidth: 1.5, borderColor: '#fff' },
  tabBadgeTxt: { color: '#fff', fontSize: 9.5, fontWeight: '800' },
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
  notifCard: { flexDirection: 'row', alignItems: 'flex-start', backgroundColor: '#fff', borderRadius: 15, padding: 14, marginBottom: 9, borderWidth: 1, borderColor: '#EFF3F1', shadowColor: '#0B3B2A', shadowOpacity: 0.045, shadowRadius: 12, shadowOffset: { width: 0, height: 5 }, elevation: 1 },
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
  emptySub: { color: '#94A3B8', fontSize: 13, marginTop: 8, textAlign: 'center', lineHeight: 19 },
  formRow2: { flexDirection: 'row', alignItems: 'flex-start' },
  listNewBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: BRAND, borderRadius: 14, paddingVertical: 13, marginBottom: 16, shadowColor: BRAND, shadowOpacity: 0.25, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 3 },
  listNewTxt: { color: '#fff', fontSize: 15, fontWeight: '700' },
  emptyBtn: { marginTop: 18, backgroundColor: BRAND, paddingVertical: 12, paddingHorizontal: 22, borderRadius: 12 },
  emptyBtnTxt: { color: '#fff', fontSize: 14.5, fontWeight: '700' },

  /* Tab bar */
  tabBar: { flexDirection: 'row', backgroundColor: 'rgba(255,255,255,0.55)', marginHorizontal: 12, marginBottom: Platform.OS === 'ios' ? 6 : 22, borderRadius: 30, paddingBottom: Platform.OS === 'ios' ? 14 : 10, paddingTop: 10, paddingHorizontal: 6, alignItems: 'flex-end', shadowColor: '#0F172A', shadowOpacity: 0.15, shadowRadius: 24, shadowOffset: { width: 0, height: 12 }, elevation: 18 },
  tabBlur: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, borderRadius: 30, overflow: 'hidden', backgroundColor: 'rgba(255,255,255,0.42)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.9)' },
  tab: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3 },
  tabLabel: { fontSize: 11, fontWeight: '600' },
  fabWrap: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  fab: { width: 56, height: 56, borderRadius: 28, backgroundColor: BRAND, alignItems: 'center', justifyContent: 'center', marginTop: -26, shadowColor: BRAND, shadowOpacity: 0.4, shadowRadius: 10, shadowOffset: { width: 0, height: 6 }, elevation: 8, borderWidth: 4, borderColor: '#fff' },
  founderStrip: { flexDirection: 'row', alignItems: 'center', gap: 9, backgroundColor: '#1F1804', borderTopWidth: 1, borderTopColor: 'rgba(252,211,77,0.35)', paddingHorizontal: 16, paddingVertical: 11 },
  founderStripStar: { width: 26, height: 26, borderRadius: 8, backgroundColor: '#FCD34D', alignItems: 'center', justifyContent: 'center' },
  founderStripTxt: { fontSize: 13.5, fontWeight: '800', color: '#FCD34D', letterSpacing: 0.2 },
  founderStripHint: { flex: 1, fontSize: 11.5, color: '#B8A76E', marginLeft: 2 },
  fdWrap: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: '#0B0F0D' },
  fdTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 14, paddingVertical: 10 },
  fdBack: { width: 36, height: 36, borderRadius: 11, backgroundColor: '#18211C', alignItems: 'center', justifyContent: 'center' },
  fdTopTitle: { fontSize: 15, fontWeight: '700', color: '#EAF2EE' },
  fdAssoBtn: { flexDirection: 'row', alignItems: 'center', gap: 5, backgroundColor: '#FCD34D', borderRadius: 999, paddingHorizontal: 12, paddingVertical: 8 },
  fdAssoTxt: { fontSize: 12, fontWeight: '800', color: '#0B0F0D', letterSpacing: 0.2 },
  fdHelloRow: { flexDirection: 'row', alignItems: 'center', gap: 10, flexWrap: 'wrap' },
  fdHello: { fontSize: 24, fontWeight: '800', color: '#EAF2EE', letterSpacing: -0.5 },
  fdSeal: { backgroundColor: '#FCD34D', borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  fdSealTxt: { fontSize: 10.5, fontWeight: '800', color: '#3A2A08' },
  fdHelloSub: { fontSize: 12.5, color: '#8A9A92', marginTop: 6, marginBottom: 16 },
  fdKpis: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  fdKpi: { width: '47.5%', flexGrow: 1, backgroundColor: '#131A16', borderRadius: 16, borderWidth: 1, borderColor: 'rgba(255,255,255,0.07)', padding: 15, overflow: 'hidden' },
  fdKpiGlow: { position: 'absolute', top: 0, left: 0, right: 0, height: 3 },
  fdKpiTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  fdKpiLabel: { fontSize: 10.5, fontWeight: '700', letterSpacing: 0.4, color: '#8A9A92', textTransform: 'uppercase' },
  fdKpiIcon: { width: 28, height: 28, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
  fdKpiVal: { fontSize: 25, fontWeight: '800', letterSpacing: -0.8, marginTop: 10 },
  fdKpiSub: { fontSize: 11, color: '#8A9A92', marginTop: 5 },
  fdPanel: { backgroundColor: '#131A16', borderRadius: 16, borderWidth: 1, borderColor: 'rgba(255,255,255,0.07)', padding: 6, marginTop: 8 },
  fdPanelTitle: { fontSize: 12.5, fontWeight: '700', color: '#EAF2EE', paddingHorizontal: 10, paddingTop: 10, paddingBottom: 6 },
  fdSignal: { flexDirection: 'row', alignItems: 'center', gap: 11, paddingVertical: 11, paddingHorizontal: 10 },
  fdSignalIcon: { width: 32, height: 32, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
  fdSignalTxt: { flex: 1, fontSize: 13, fontWeight: '600', color: '#EAF2EE' },
  fdSectionTitle: { fontSize: 14, fontWeight: '750', color: '#EAF2EE', marginTop: 22, marginBottom: 10 },
  fdOrgRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12, paddingHorizontal: 10 },
  fdOrgBorder: { borderTopWidth: 1, borderTopColor: 'rgba(255,255,255,0.05)' },
  fdOrgName: { fontSize: 14, fontWeight: '650', color: '#EAF2EE' },
  fdOrgSub: { fontSize: 11.5, color: '#8A9A92', marginTop: 2 },
  fdChip: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  fdChipTxt: { fontSize: 11, fontWeight: '700' },
  fdShortcuts: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  fdShortcut: { width: '30%', flexGrow: 1, backgroundColor: '#131A16', borderRadius: 14, borderWidth: 1, borderColor: 'rgba(252,211,77,0.18)', paddingVertical: 15, alignItems: 'center' },
  fdShortcutIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: 'rgba(252,211,77,0.12)', alignItems: 'center', justifyContent: 'center', marginBottom: 8 },
  fdShortcutTxt: { fontSize: 11.5, fontWeight: '700', color: '#EAF2EE', textAlign: 'center' },
  fdLogout: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 9, marginTop: 24, paddingVertical: 15, borderRadius: 14, backgroundColor: 'rgba(220,38,38,0.10)', borderWidth: 1, borderColor: 'rgba(248,113,113,0.28)' },
  fdLogoutTxt: { fontSize: 15, fontWeight: '700', color: '#FCA5A5' },

  /* ===== Cockpit Fondateur — clair & premium ===== */
  fcWrap: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: '#EEF3FA' },
  fcScroll: { flex: 1, backgroundColor: '#EEF3FA' },
  // Annuaire national (fondateur)
  dirBanner: { backgroundColor: '#0369A1', borderRadius: 18, padding: 18, marginBottom: 16, alignItems: 'center' },
  dirBannerBig: { color: '#fff', fontSize: 30, fontWeight: '800' },
  dirBannerLbl: { color: '#BAE6FD', fontSize: 13, marginTop: 2, fontWeight: '600' },
  dirRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#fff', borderRadius: 14, padding: 14, marginBottom: 10, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 2 },
  dirIco: { width: 38, height: 38, borderRadius: 11, alignItems: 'center', justifyContent: 'center', marginRight: 12 },
  dirRowT: { flex: 1, fontSize: 15, fontWeight: '700', color: '#0F172A' },
  dirRowS: { fontSize: 12, color: '#64748B', marginTop: 2 },
  dirCount: { backgroundColor: '#F0F9FF', borderRadius: 20, paddingHorizontal: 11, paddingVertical: 4, marginRight: 6 },
  dirCountT: { color: '#0369A1', fontWeight: '800', fontSize: 13 },
  dirDeptCard: { backgroundColor: '#fff', borderRadius: 14, padding: 12, marginBottom: 12, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 2 },
  dirDeptHead: { flexDirection: 'row', alignItems: 'center' },
  dirDeptCode: { color: '#2563EB', fontWeight: '800', fontSize: 14 },
  dirChips: { flexDirection: 'row', flexWrap: 'wrap', gap: 7, marginTop: 11 },
  dirChip: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: '#F1F5F9', borderRadius: 20, paddingHorizontal: 11, paddingVertical: 6 },
  dirChipT: { fontSize: 12, color: '#334155', fontWeight: '600' },
  dirChipN: { fontSize: 11, color: '#0369A1', fontWeight: '800' },
  dirListCount: { fontSize: 13, color: '#64748B', fontWeight: '700', marginBottom: 10 },
  dirAsso: { backgroundColor: '#fff', borderRadius: 14, padding: 14, marginBottom: 10, shadowColor: '#0F172A', shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 2 },
  dirAssoName: { fontSize: 14.5, fontWeight: '700', color: '#0F172A' },
  dirAssoMeta: { fontSize: 12, color: '#64748B', marginTop: 3 },
  dirEmailRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 7 },
  dirEmailTxt: { fontSize: 12.5, color: '#047857', fontWeight: '600' },
  dirAssoActions: { flexDirection: 'row', gap: 9, marginTop: 12 },
  dirActBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: '#F0F9FF', borderRadius: 10, paddingHorizontal: 12, paddingVertical: 8 },
  dirActBtnP: { backgroundColor: '#0369A1' },
  dirActTxt: { fontSize: 12.5, fontWeight: '700', color: '#0369A1' },
  dirEmpty: { alignItems: 'center', paddingVertical: 44, gap: 8 },
  dirEmptyT: { fontSize: 16, fontWeight: '700', color: '#334155' },
  dirEmptyS: { fontSize: 13, color: '#64748B', textAlign: 'center', lineHeight: 19 },
  dirLegal: { flexDirection: 'row', gap: 9, backgroundColor: '#EFF6FF', borderRadius: 12, padding: 13, marginTop: 16, alignItems: 'flex-start' },
  dirLegalTxt: { flex: 1, fontSize: 11.5, color: '#475569', lineHeight: 17 },
  fcHeaderWrap: { borderBottomLeftRadius: 30, borderBottomRightRadius: 30, overflow: 'hidden', shadowColor: '#047857', shadowOpacity: 0.3, shadowRadius: 22, shadowOffset: { width: 0, height: 12 }, elevation: 8 },
  fcHeader: { paddingTop: 8, paddingHorizontal: 16, paddingBottom: 52, position: 'relative', overflow: 'hidden' },
  fcOrbGold: { position: 'absolute', top: -50, right: -30, width: 180, height: 180, borderRadius: 90, backgroundColor: 'rgba(252,211,77,0.18)' },
  fcOrbDark: { position: 'absolute', bottom: -70, left: -50, width: 170, height: 170, borderRadius: 85, backgroundColor: 'rgba(2,49,34,0.4)' },
  fcTopRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 },
  fcBack: { width: 34, height: 34, borderRadius: 11, backgroundColor: 'rgba(255,255,255,0.16)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.2)', alignItems: 'center', justifyContent: 'center' },
  fcTopTitle: { fontSize: 13, fontWeight: '800', color: '#EAF2EE', letterSpacing: 0.3 },
  fcAssoBtn: { flexDirection: 'row', alignItems: 'center', gap: 5, backgroundColor: '#FCD34D', borderRadius: 999, paddingHorizontal: 11, paddingVertical: 7 },
  fcAssoTxt: { fontSize: 11.5, fontWeight: '900', color: '#3A2A08' },
  fcHelloRow: { flexDirection: 'row', alignItems: 'center', gap: 9, flexWrap: 'wrap' },
  fcHello: { fontSize: 24, fontWeight: '800', color: '#fff', letterSpacing: -0.5 },
  fcSeal: { backgroundColor: '#FCD34D', borderRadius: 999, paddingHorizontal: 9, paddingVertical: 4 },
  fcSealTxt: { fontSize: 10, fontWeight: '900', color: '#3A2A08' },
  fcSubRow: { flexDirection: 'row', alignItems: 'center', gap: 7, marginTop: 8 },
  fcLive: { width: 7, height: 7, borderRadius: 4, backgroundColor: '#6EE7B7' },
  fcSub: { fontSize: 12, color: 'rgba(255,255,255,0.9)', fontWeight: '500' },

  fcSpotShadow: { marginTop: -34, borderRadius: 22, shadowColor: '#025138', shadowOpacity: 0.22, shadowRadius: 22, shadowOffset: { width: 0, height: 14 }, elevation: 9 },
  fcSpot: { borderRadius: 22, padding: 17, overflow: 'hidden', backgroundColor: 'rgba(255,255,255,0.78)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.9)' },
  fcSpotGloss: { position: 'absolute', top: 0, left: 0, right: 0, height: 44, backgroundColor: 'rgba(255,255,255,0.35)' },
  fcSpotTop: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  fcSpotIc: { width: 30, height: 30, borderRadius: 10, backgroundColor: '#FEF3C7', alignItems: 'center', justifyContent: 'center' },
  fcSpotLb: { flex: 1, fontSize: 12, fontWeight: '800', color: '#7C5410', letterSpacing: 0.2 },
  fcSpotChip: { backgroundColor: 'rgba(5,150,105,0.12)', borderRadius: 999, paddingHorizontal: 9, paddingVertical: 4 },
  fcSpotChipTxt: { fontSize: 10.5, fontWeight: '800', color: '#047857' },
  fcSpotVal: { fontSize: 33, fontWeight: '800', color: '#052E23', letterSpacing: -1, marginTop: 11 },
  fcSpotUnit: { fontSize: 16, fontWeight: '700', color: '#7C8A83' },
  fcDistTrack: { flexDirection: 'row', height: 9, borderRadius: 6, backgroundColor: 'rgba(6,95,70,0.1)', marginTop: 14, overflow: 'hidden' },
  fcDistFill: { height: 9 },
  fcDistMeta: { flexDirection: 'row', gap: 16, marginTop: 8 },
  fcDistTxt: { fontSize: 11.5, color: '#475569', fontWeight: '600' },

  fcActions: { flexDirection: 'row', gap: 10, marginTop: 14 },
  fcNotif: { flexDirection: 'row', alignItems: 'center', gap: 7, backgroundColor: '#fff', borderRadius: 14, paddingHorizontal: 13, paddingVertical: 13, borderWidth: 1, borderColor: '#E7EDEA' },
  fcNotifTxt: { fontSize: 13, fontWeight: '700', color: '#334155' },
  fcNotifPill: { backgroundColor: '#EF4444', borderRadius: 999, paddingHorizontal: 6, paddingVertical: 1, minWidth: 18, alignItems: 'center' },
  fcNotifPillTxt: { color: '#fff', fontSize: 10, fontWeight: '900' },
  fcCreate: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, backgroundColor: '#FCD34D', borderRadius: 14, paddingVertical: 13, shadowColor: '#B45309', shadowOpacity: 0.25, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 3 },
  fcCreateTxt: { fontSize: 13.5, fontWeight: '900', color: '#3A2A08' },

  fcMiniRow: { flexDirection: 'row', gap: 10, marginTop: 12 },
  fcMini: { flex: 1, backgroundColor: '#fff', borderRadius: 17, padding: 13, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.05, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 2 },
  fcMiniIc: { width: 30, height: 30, borderRadius: 9, alignItems: 'center', justifyContent: 'center', marginBottom: 9 },
  fcMiniVal: { fontSize: 21, fontWeight: '800', color: INK, letterSpacing: -0.5 },
  fcMiniLb: { fontSize: 11.5, fontWeight: '700', color: '#334155', marginTop: 1 },
  fcMiniSub: { fontSize: 9.5, color: '#8A9A92', marginTop: 2 },
  fcMonthCard: { backgroundColor: '#fff', borderRadius: 17, padding: 15, marginTop: 12, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.05, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 2 },
  fcMonthTitle: { fontSize: 12.5, fontWeight: '800', color: '#334155', marginBottom: 12 },
  fcMonthRow: { flexDirection: 'row', alignItems: 'center' },
  fcMonthItem: { flex: 1, alignItems: 'center' },
  fcMonthVal: { fontSize: 20, fontWeight: '800', letterSpacing: -0.5 },
  fcMonthLb: { fontSize: 10, color: '#8A9A92', marginTop: 2, fontWeight: '600', textAlign: 'center' },
  fcMonthSep: { width: 1, height: 30, backgroundColor: '#EEF2F1' },

  fcSecRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 22, marginBottom: 10 },
  fcSec: { fontSize: 11.5, fontWeight: '800', color: '#8A9A92', letterSpacing: 0.7 },
  fcSecN: { backgroundColor: '#FEE2E2', borderRadius: 999, paddingHorizontal: 7, paddingVertical: 1 },
  fcSecNTxt: { fontSize: 10, fontWeight: '900', color: '#DC2626' },
  fcSignal: { flexDirection: 'row', alignItems: 'center', gap: 11, backgroundColor: '#fff', borderRadius: 14, padding: 12, marginBottom: 8, borderWidth: 1, borderColor: '#E7EDEA' },
  fcSignalIc: { width: 34, height: 34, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  fcSignalTxt: { flex: 1, fontSize: 13, fontWeight: '700', color: '#1E293B' },

  fcTiles: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  fcTile: { width: '48%', backgroundColor: '#fff', borderRadius: 18, padding: 15, marginBottom: 12, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.06, shadowRadius: 14, shadowOffset: { width: 0, height: 7 }, elevation: 2 },
  fcTileIc: { width: 44, height: 44, borderRadius: 13, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  fcTileBadge: { position: 'absolute', top: 12, right: 12, minWidth: 20, height: 20, borderRadius: 10, backgroundColor: '#EF4444', alignItems: 'center', justifyContent: 'center', paddingHorizontal: 5, borderWidth: 1.5, borderColor: '#fff' },
  fcTileBadgeTxt: { color: '#fff', fontSize: 11, fontWeight: '800' },
  fcTileTitle: { fontSize: 14.5, fontWeight: '800', color: INK },
  fcTileDesc: { fontSize: 11, color: '#8A9A92', marginTop: 2 },

  fcPanel: { backgroundColor: '#fff', borderRadius: 18, padding: 6, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.05, shadowRadius: 14, shadowOffset: { width: 0, height: 7 }, elevation: 2 },
  fcOrgRow: { flexDirection: 'row', alignItems: 'center', gap: 11, paddingVertical: 11, paddingHorizontal: 10 },
  fcOrgBorder: { borderTopWidth: 1, borderTopColor: '#F1F5F4' },
  fcOrgAv: { width: 36, height: 36, borderRadius: 11, backgroundColor: '#ECFDF5', alignItems: 'center', justifyContent: 'center' },
  fcOrgAvTxt: { fontSize: 12.5, fontWeight: '800', color: '#047857' },
  fcOrgName: { fontSize: 14, fontWeight: '800', color: INK },
  fcOrgSub: { fontSize: 11, color: '#8A9A92', marginTop: 1 },
  fcChip: { borderRadius: 999, paddingHorizontal: 9, paddingVertical: 4 },
  fcChipTxt: { fontSize: 10.5, fontWeight: '800' },
  fcSeeAll: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, paddingVertical: 12, marginTop: 2 },
  fcSeeAllTxt: { fontSize: 13, fontWeight: '800', color: '#059669' },
  fcLogout: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 9, marginTop: 22, paddingVertical: 15, borderRadius: 14, backgroundColor: '#FEF2F2', borderWidth: 1, borderColor: '#FECACA' },
  fcLogoutTxt: { fontSize: 15, fontWeight: '800', color: '#DC2626' },

  /* Associations natives — filtres + actions */
  fcFilters: { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingTop: 10, paddingBottom: 4, flexWrap: 'wrap' },
  fcFilter: { backgroundColor: '#fff', borderRadius: 999, paddingHorizontal: 14, paddingVertical: 8, borderWidth: 1, borderColor: '#E2E8F0' },
  fcFilterOn: { backgroundColor: '#059669', borderColor: '#059669' },
  fcFilterTxt: { fontSize: 12.5, fontWeight: '700', color: '#64748B' },
  fcFilterTxtOn: { color: '#fff' },
  fcOrgCard: { backgroundColor: '#fff', borderRadius: 16, padding: 14, marginBottom: 12, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.05, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 2 },
  fcOrgCardTop: { flexDirection: 'row', alignItems: 'center', gap: 11 },
  fcOrgUnpaid: { fontSize: 11.5, fontWeight: '700', color: '#B91C1C', marginTop: 10 },
  fcOrgActions: { flexDirection: 'row', gap: 9, marginTop: 12 },
  fcAct: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, borderRadius: 11, paddingVertical: 10, paddingHorizontal: 16 },
  fcActGo: { flex: 1, backgroundColor: '#059669' },
  fcActGoTxt: { fontSize: 13, fontWeight: '800', color: '#fff' },
  fcActNo: { flex: 1, backgroundColor: '#FEF2F2', borderWidth: 1, borderColor: '#FECACA' },
  fcActNoTxt: { fontSize: 13, fontWeight: '800', color: '#B91C1C' },
  fcBlogTile: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: '#fff', borderRadius: 18, padding: 15, marginTop: 2, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.06, shadowRadius: 14, shadowOffset: { width: 0, height: 7 }, elevation: 2 },
  fcBlogIc: { width: 44, height: 44, borderRadius: 13, backgroundColor: '#FFFBEB', alignItems: 'center', justifyContent: 'center' },

  /* Fondateur — pages natives (paiements / stats / blog / support) */
  fcFilters2: { flexDirection: 'row', gap: 8, marginTop: 14, marginBottom: 6, flexWrap: 'wrap' },
  fcInvCard: { backgroundColor: '#fff', borderRadius: 15, padding: 14, marginTop: 11, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.04, shadowRadius: 10, shadowOffset: { width: 0, height: 5 }, elevation: 1 },
  fcInvAmount: { fontSize: 16, fontWeight: '800', color: INK },
  fcPayBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 7, backgroundColor: '#059669', borderRadius: 11, paddingVertical: 11, marginTop: 12 },
  fcPayTxt: { color: '#fff', fontSize: 13.5, fontWeight: '800' },
  fcStatGrid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  fcStatCard: { width: '48%', backgroundColor: '#fff', borderRadius: 16, padding: 14, marginBottom: 12, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.05, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 2 },
  fcStatVal: { fontSize: 22, fontWeight: '800', color: INK, marginTop: 11, letterSpacing: -0.5 },
  fcStatLb: { fontSize: 12.5, fontWeight: '700', color: '#334155', marginTop: 2 },
  fcStatSub: { fontSize: 10.5, color: '#8A9A92', marginTop: 2 },
  fcBars: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', backgroundColor: '#fff', borderRadius: 16, padding: 16, borderWidth: 1, borderColor: '#E7EDEA' },
  fcBarCol: { flex: 1, alignItems: 'center', gap: 6 },
  fcBarVal: { fontSize: 11, fontWeight: '800', color: '#334155' },
  fcBarTrack: { height: 80, justifyContent: 'flex-end' },
  fcBarFill: { width: 18, borderRadius: 6, backgroundColor: '#059669' },
  fcBarLbl: { fontSize: 9.5, color: '#94A3B8', fontWeight: '600' },
  fcArtCard: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: '#fff', borderRadius: 15, padding: 13, marginTop: 11, borderWidth: 1, borderColor: '#E7EDEA' },
  fcArtIc: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  fcTicketCard: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#fff', borderRadius: 15, padding: 14, marginTop: 11, borderWidth: 1, borderColor: '#E7EDEA' },
  fcUnread: { backgroundColor: '#EF4444', borderRadius: 999, minWidth: 20, paddingHorizontal: 6, paddingVertical: 1, alignItems: 'center' },
  fcUnreadTxt: { color: '#fff', fontSize: 10.5, fontWeight: '900' },

  /* Blog — génération IA & programmation */
  blogGenBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: '#7C3AED', borderRadius: 14, paddingVertical: 15, marginTop: 16, shadowColor: '#6D28D9', shadowOpacity: 0.3, shadowRadius: 14, shadowOffset: { width: 0, height: 7 }, elevation: 3 },
  blogGenTxt: { color: '#fff', fontSize: 15, fontWeight: '800' },
  blogQueueRow: { flexDirection: 'row', alignItems: 'center', gap: 11, backgroundColor: '#fff', borderRadius: 14, padding: 13, marginBottom: 9, borderWidth: 1, borderColor: '#F1E4C7' },
  blogQueueIc: { width: 34, height: 34, borderRadius: 10, backgroundColor: '#FEF3C7', alignItems: 'center', justifyContent: 'center' },
  blogModalWrap: { flex: 1, backgroundColor: 'rgba(15,23,42,0.45)', justifyContent: 'flex-end' },
  blogModal: { backgroundColor: '#fff', borderTopLeftRadius: 26, borderTopRightRadius: 26, padding: 20, paddingBottom: 30, maxHeight: '90%' },
  blogModalHandle: { width: 42, height: 5, borderRadius: 3, backgroundColor: '#E2E8F0', alignSelf: 'center', marginBottom: 14 },
  blogModalHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 },
  blogModalTitle: { fontSize: 19, fontWeight: '800', color: INK },
  blogBusy: { alignItems: 'center', paddingVertical: 30 },
  blogBusyTxt: { fontSize: 16, fontWeight: '800', color: INK, marginTop: 16, textAlign: 'center' },
  blogBusySub: { fontSize: 13, color: '#64748B', marginTop: 6, textAlign: 'center', paddingHorizontal: 20, lineHeight: 18 },
  blogOkIc: { width: 56, height: 56, borderRadius: 28, backgroundColor: '#059669', alignItems: 'center', justifyContent: 'center' },
  blogSecBtn: { paddingVertical: 13, paddingHorizontal: 22, borderRadius: 12, borderWidth: 1, borderColor: '#E2E8F0' },
  blogSecTxt: { fontSize: 15, fontWeight: '700', color: '#334155' },
  blogPrimBtn: { paddingVertical: 13, paddingHorizontal: 28, borderRadius: 12, backgroundColor: BRAND },
  blogPrimTxt: { fontSize: 15, fontWeight: '800', color: '#fff' },
  blogLabel: { fontSize: 14, fontWeight: '700', color: '#334155', marginTop: 12, marginBottom: 8 },
  blogInput: { backgroundColor: '#F8FAFC', borderWidth: 1, borderColor: '#E2E8F0', borderRadius: 13, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, color: INK, minHeight: 48 },
  blogCats: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  blogCat: { backgroundColor: '#F1F5F9', borderRadius: 999, paddingHorizontal: 13, paddingVertical: 9, borderWidth: 1, borderColor: '#E2E8F0' },
  blogCatOn: { backgroundColor: '#EDE9FE', borderColor: '#7C3AED' },
  blogCatTxt: { fontSize: 12.5, fontWeight: '600', color: '#64748B' },
  blogCatTxtOn: { color: '#6D28D9', fontWeight: '800' },
  blogSwitchRow: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 18, backgroundColor: '#F8FAFC', borderRadius: 13, padding: 14, borderWidth: 1, borderColor: '#E7EDEA' },
  blogSwitchTitle: { fontSize: 14.5, fontWeight: '700', color: INK },
  blogSwitchSub: { fontSize: 12, color: '#94A3B8', marginTop: 2 },
  blogProgBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, borderRadius: 14, paddingVertical: 14, marginTop: 10, borderWidth: 1.5, borderColor: '#D1FAE5', backgroundColor: '#F0FDF9' },
  blogProgTxt: { color: BRAND, fontSize: 14.5, fontWeight: '800' },
  blogHint: { fontSize: 12, color: '#94A3B8', marginTop: 12, lineHeight: 17, textAlign: 'center' },
  blogArtDate: { fontSize: 11, color: '#94A3B8', marginTop: 4, fontWeight: '600' },
  blogQty: { minWidth: 50, alignItems: 'center', backgroundColor: '#F1F5F9', borderRadius: 12, paddingVertical: 11, paddingHorizontal: 14, borderWidth: 1.5, borderColor: '#E2E8F0' },
  blogQtyOn: { backgroundColor: '#7C3AED', borderColor: '#7C3AED' },
  blogQtyTxt: { fontSize: 16, fontWeight: '800', color: '#64748B' },
  blogQtyTxtOn: { color: '#fff' },

  /* Créer org — plans + identifiants */
  planChip: { backgroundColor: '#F8FAFC', borderRadius: 14, paddingHorizontal: 14, paddingVertical: 11, borderWidth: 1.5, borderColor: '#E2E8F0' },
  planChipOn: { backgroundColor: BRAND, borderColor: BRAND },
  planChipName: { fontSize: 13.5, fontWeight: '800', color: INK },
  planChipPrice: { fontSize: 11, color: '#64748B', marginTop: 1 },
  payRow: { flexDirection: 'row', alignItems: 'center', gap: 11, backgroundColor: '#F8FAFC', borderRadius: 13, padding: 13, borderWidth: 1.5, borderColor: '#E2E8F0' },
  payRowOn: { backgroundColor: '#ECFDF5', borderColor: BRAND },
  payLbl: { fontSize: 14, fontWeight: '700', color: INK },
  paySub: { fontSize: 12, color: '#64748B', marginTop: 1 },
  odHead: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 8 },
  odTitle: { fontSize: 19, fontWeight: '800', color: INK },
  odMeta: { fontSize: 12.5, color: '#64748B', marginTop: 2 },
  odActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 14 },
  odBtn: { flexDirection: 'row', alignItems: 'center', gap: 7, paddingVertical: 11, paddingHorizontal: 16, borderRadius: 12, borderWidth: 1.5 },
  odBtnTxt: { fontSize: 13.5, fontWeight: '700' },
  odPanel: { backgroundColor: '#fff', borderRadius: 14, borderWidth: 1, borderColor: '#E7EDEA', paddingHorizontal: 14 },
  odMember: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 12 },
  odMemberBorder: { borderTopWidth: 1, borderTopColor: '#F1F5F9' },
  odMemberName: { fontSize: 14, fontWeight: '700', color: INK },
  odMemberMail: { fontSize: 12, color: '#94A3B8', marginTop: 1 },
  plnCard: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#fff', borderRadius: 14, padding: 15, marginBottom: 10, borderWidth: 1, borderColor: '#E7EDEA' },
  plnName: { fontSize: 15, fontWeight: '800', color: INK },
  plnSub: { fontSize: 12, color: '#94A3B8', marginTop: 2 },
  plnPrice: { fontSize: 14, fontWeight: '800', color: BRAND },
  plnGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  plnQuota: { width: '48%', backgroundColor: '#F8FAFC', borderRadius: 11, padding: 10, borderWidth: 1, borderColor: '#E7EDEA' },
  plnQuotaLbl: { fontSize: 11.5, color: '#64748B', marginBottom: 4 },
  plnQuotaInp: { fontSize: 15, fontWeight: '700', color: INK, padding: 0 },
  plnPanel: { backgroundColor: '#fff', borderRadius: 14, borderWidth: 1, borderColor: '#E7EDEA', paddingHorizontal: 14, marginTop: 10 },
  plnFeat: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 11 },
  plnFeatLbl: { fontSize: 14, color: INK, flex: 1 },
  fdCountLine: { fontSize: 12.5, color: '#94A3B8', marginBottom: 10, fontWeight: '600' },
  fdProgTrack: { height: 5, borderRadius: 3, backgroundColor: '#EEF2F6', marginTop: 8, overflow: 'hidden' },
  fdProgFill: { height: '100%', borderRadius: 3, backgroundColor: BRAND },
  actRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 11, borderBottomWidth: 1, borderBottomColor: '#F1F5F9' },
  actIc: { width: 36, height: 36, borderRadius: 11, alignItems: 'center', justifyContent: 'center' },
  actLbl: { fontSize: 14, color: INK, fontWeight: '600' },
  actMeta: { fontSize: 12, color: '#94A3B8', marginTop: 2 },
  setSec: { fontSize: 12, fontWeight: '800', color: '#94A3B8', letterSpacing: 0.6, textTransform: 'uppercase', marginTop: 16, marginBottom: 4 },
  prBanner: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderRadius: 12, padding: 12, marginBottom: 14 },
  prBannerTxt: { flex: 1, fontSize: 12.5, fontWeight: '600', lineHeight: 17 },
  prImport: { backgroundColor: '#fff', borderRadius: 14, borderWidth: 1, borderColor: '#E7EDEA', padding: 14, marginBottom: 16 },
  prHint: { fontSize: 11.5, color: '#94A3B8', lineHeight: 16, marginTop: 6, marginBottom: 10 },
  credCard: { backgroundColor: '#F8FAFC', borderRadius: 16, padding: 16, marginTop: 16, borderWidth: 1, borderColor: '#E2E8F0' },
  credLbl: { fontSize: 12, fontWeight: '700', color: '#94A3B8', textTransform: 'uppercase', letterSpacing: 0.4 },
  credVal: { fontSize: 16, fontWeight: '800', color: INK, marginTop: 3 },
  credDivider: { height: 1, backgroundColor: '#E7EDEA', marginVertical: 12 },

  /* Support thread */
  supHead: { marginBottom: 14 },
  supTitle: { fontSize: 17, fontWeight: '800', color: INK },
  supMeta: { fontSize: 12, color: '#94A3B8', marginTop: 3 },
  supMsgRow: { marginBottom: 10, flexDirection: 'row' },
  supMsgLeft: { justifyContent: 'flex-start' },
  supMsgRight: { justifyContent: 'flex-end' },
  supBubble: { maxWidth: '82%', borderRadius: 16, padding: 12 },
  supBubbleMe: { backgroundColor: BRAND, borderBottomRightRadius: 5 },
  supBubbleOrg: { backgroundColor: '#F1F5F9', borderBottomLeftRadius: 5 },
  supNote: { backgroundColor: '#FEF3C7', borderWidth: 1, borderColor: '#FDE68A' },
  supNoteLbl: { fontSize: 10, fontWeight: '800', color: '#B45309', marginBottom: 3, textTransform: 'uppercase' },
  supBody: { fontSize: 14.5, color: '#1E293B', lineHeight: 20 },
  supAt: { fontSize: 10.5, color: '#94A3B8', marginTop: 5, alignSelf: 'flex-end' },
  supClosed: { padding: 16, alignItems: 'center', borderTopWidth: 1, borderTopColor: '#EEF2F1' },
  supClosedTxt: { fontSize: 13, color: '#94A3B8', fontWeight: '600' },
  supInputBar: { flexDirection: 'row', alignItems: 'flex-end', gap: 10, padding: 12, borderTopWidth: 1, borderTopColor: '#EEF2F1', backgroundColor: '#fff' },
  supInput: { flex: 1, maxHeight: 110, backgroundColor: '#F1F5F9', borderRadius: 20, paddingHorizontal: 16, paddingVertical: 11, fontSize: 15, color: INK },
  supSend: { width: 44, height: 44, borderRadius: 22, backgroundColor: BRAND, alignItems: 'center', justifyContent: 'center' },

  /* Contacts / prospects */
  ctcCard: { backgroundColor: '#fff', borderRadius: 15, padding: 14, marginBottom: 11, borderWidth: 1, borderColor: '#E7EDEA' },
  ctcTop: { flexDirection: 'row', alignItems: 'center', gap: 11 },
  ctcAv: { width: 38, height: 38, borderRadius: 11, alignItems: 'center', justifyContent: 'center' },
  ctcSubject: { fontSize: 13, fontWeight: '700', color: '#334155', marginTop: 10 },
  ctcMsg: { fontSize: 13, color: '#64748B', marginTop: 4, lineHeight: 18 },
  ctcDetailMeta: { fontSize: 13, color: '#64748B', marginTop: 2 },
  ctcDetailSubject: { fontSize: 15, fontWeight: '800', color: INK, marginTop: 12 },
  ctcDetailBox: { backgroundColor: '#F8FAFC', borderRadius: 13, padding: 14, marginTop: 10, borderWidth: 1, borderColor: '#EEF2F1' },
  ctcDetailMsg: { fontSize: 14.5, color: '#1E293B', lineHeight: 21 },

  /* Emails / SMS — périodes */
  periodCard: { backgroundColor: '#fff', borderRadius: 17, padding: 15, marginTop: 16, borderWidth: 1, borderColor: '#E7EDEA', shadowColor: '#0B3B2A', shadowOpacity: 0.05, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 2 },
  periodHead: { flexDirection: 'row', alignItems: 'center', gap: 9, marginBottom: 13 },
  periodTitle: { fontSize: 13.5, fontWeight: '800', color: INK },
  periodRow: { flexDirection: 'row', gap: 8 },
  periodCell: { flex: 1, backgroundColor: '#F8FAFC', borderRadius: 12, paddingVertical: 12, alignItems: 'center', borderWidth: 1, borderColor: '#EEF2F1' },
  periodCellLb: { fontSize: 9, fontWeight: '700', color: '#94A3B8', letterSpacing: 0.4 },
  periodCellVal: { fontSize: 22, fontWeight: '800', marginTop: 5, letterSpacing: -0.5 },

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
  wSafe: { flex: 1, paddingHorizontal: 24, justifyContent: 'space-between', paddingTop: Platform.OS === 'android' ? (StatusBar.currentHeight || 24) : 0, paddingBottom: Platform.OS === 'android' ? 16 : 0 },
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

  /* ===== Connexion native ===== */
  lgWrap: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: '#EAF6F1', overflow: 'hidden' },
  lgBlob: { position: 'absolute', borderRadius: 300 },
  lgBlob1: { width: 320, height: 320, backgroundColor: 'rgba(12,203,143,0.12)', top: -120, right: -90 },
  lgBlob2: { width: 300, height: 300, backgroundColor: 'rgba(5,150,105,0.10)', bottom: -110, left: -100 },
  lgBack: { flexDirection: 'row', alignItems: 'center', gap: 3, alignSelf: 'flex-start', backgroundColor: 'rgba(255,255,255,0.8)', borderRadius: 999, paddingVertical: 8, paddingHorizontal: 14, marginTop: 6, marginLeft: 16 },
  lgBackTxt: { fontSize: 15, fontWeight: '700', color: '#0F172A' },
  lgScroll: { flexGrow: 1, justifyContent: 'center', padding: 20, paddingBottom: 40 },
  lgCard: { backgroundColor: '#fff', borderRadius: 26, padding: 24, shadowColor: '#0B3B2A', shadowOpacity: 0.1, shadowRadius: 30, shadowOffset: { width: 0, height: 16 }, elevation: 6, borderWidth: 1, borderColor: 'rgba(255,255,255,0.9)' },
  lgBrandRow: { alignItems: 'center' },
  lgBrand: { fontSize: 27, fontWeight: '800', color: '#111827', letterSpacing: -0.5 },
  lgBrandKit: { color: '#059669' },
  lgBrandDot: { color: '#059669' },
  lgTagline: { fontSize: 13.5, color: '#94A3B8', textAlign: 'center', marginTop: 4, marginBottom: 22 },
  lgTitle: { fontSize: 30, fontWeight: '800', color: '#0F172A', letterSpacing: -0.6 },
  lgSub: { fontSize: 14.5, color: '#64748B', marginTop: 4, marginBottom: 22 },
  lgLabel: { fontSize: 14, fontWeight: '700', color: '#334155', marginBottom: 8, marginTop: 4 },
  lgInput: { backgroundColor: '#F8FAFC', borderWidth: 1, borderColor: '#E2E8F0', borderRadius: 14, paddingHorizontal: 15, paddingVertical: 14, fontSize: 15.5, color: '#0F172A', marginBottom: 16 },
  lgPassRow: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#F8FAFC', borderWidth: 1, borderColor: '#E2E8F0', borderRadius: 14, paddingHorizontal: 15, marginBottom: 6 },
  lgPassInput: { flex: 1, paddingVertical: 14, fontSize: 15.5, color: '#0F172A' },
  lgError: { flexDirection: 'row', alignItems: 'center', gap: 7, backgroundColor: '#FEF2F2', borderRadius: 11, padding: 11, marginTop: 12, borderWidth: 1, borderColor: '#FECACA' },
  lgErrorTxt: { flex: 1, fontSize: 13, color: '#B91C1C', fontWeight: '600' },
  lgBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: '#059669', borderRadius: 14, paddingVertical: 16, marginTop: 18, shadowColor: '#047857', shadowOpacity: 0.3, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: 4 },
  lgBtnOff: { opacity: 0.55 },
  lgBtnTxt: { color: '#fff', fontSize: 16, fontWeight: '800' },
  lgForgot: { color: '#059669', fontSize: 14, fontWeight: '700', marginTop: 12 },
  lgFace: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 6, paddingVertical: 13, borderRadius: 13, borderWidth: 1, borderColor: '#D1FAE5', backgroundColor: '#F0FDF9' },
  lgFaceTxt: { color: '#059669', fontSize: 14.5, fontWeight: '700' },
  lgDivider: { height: 1, backgroundColor: '#EEF2F1', marginVertical: 18 },
  lgFooter: { fontSize: 13.5, color: '#64748B', textAlign: 'center' },
  lgLink: { color: '#059669', fontWeight: '800' },
  lgHosted: { fontSize: 12.5, color: '#64748B', textAlign: 'center', marginTop: 18 },
});
