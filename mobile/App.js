import React, { useRef, useState, useEffect, useCallback } from 'react';
import {
  SafeAreaView,
  StyleSheet,
  View,
  Text,
  TouchableOpacity,
  ActivityIndicator,
  BackHandler,
  Platform,
  StatusBar,
  Modal,
  Pressable,
  ScrollView,
  RefreshControl,
} from 'react-native';
import { WebView } from 'react-native-webview';
import { LinearGradient } from 'expo-linear-gradient';
import { BlurView } from 'expo-blur';
import { Ionicons } from '@expo/vector-icons';
import * as Notifications from 'expo-notifications';
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

function gotoJS(path) {
  return "(function(){ try { window.location.href='" + BASE + path + "'; } catch(e){} })(); true;";
}

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

const TABS = [
  { key: 'accueil', label: 'Accueil', icon: 'home', path: '/dashboard' },
  { key: 'projets', label: 'Projets', icon: 'folder', path: '/projets' },
  { key: 'add', label: '', icon: 'add', path: null },
  { key: 'adherents', label: 'Membres', icon: 'people', path: '/adherents' },
  { key: 'menu', label: 'Plus', icon: 'grid', path: null },
];

const QUICK_ACTIONS = [
  { label: 'Nouveau projet', icon: 'add-circle', color: '#059669', path: '/nouveau-projet' },
  { label: 'Nouvelle facture', icon: 'document-text', color: '#2563EB', path: '/mon-asso-facture-new' },
  { label: 'Nouvel adhérent', icon: 'person-add', color: '#D97706', path: '/adherents' },
  { label: 'Nouveau message', icon: 'chatbubble-ellipses', color: '#7C3AED', path: '/messages' },
];

const SHORTCUTS = [
  { label: 'Projets', icon: 'folder-open', path: '/projets' },
  { label: 'Factures', icon: 'receipt', path: '/mon-asso-factures' },
  { label: 'Agenda', icon: 'calendar', path: '/agenda' },
  { label: 'Messages', icon: 'chatbubbles', path: '/messages' },
];

/* ================================================================== */
/*  ECRAN D'ACCUEIL (marketing) natif                                  */
/* ================================================================== */
function WelcomeScreen({ onLogin, onSignup }) {
  return (
    <View style={styles.wBg}>
      <LinearGradient
        colors={['#0AA173', '#059669', '#03583F']}
        start={{ x: 0.1, y: 0 }}
        end={{ x: 0.9, y: 1 }}
        style={StyleSheet.absoluteFill}
      />
      <View style={[styles.blob, styles.blob1]} />
      <View style={[styles.blob, styles.blob2]} />
      <View style={[styles.blob, styles.blob3]} />
      <StatusBar barStyle="light-content" backgroundColor="transparent" translucent />
      <SafeAreaView style={styles.wSafe}>
        <View style={styles.wTop}>
          <View style={styles.logoHalo}>
            <View style={styles.logoTile}>
              <View style={styles.logoDot} />
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
function NativeHome({ data, loading, onRefresh, onGoto }) {
  const k = (data && data.kpis) || {};
  const cards = [
    { icon: 'folder', color: '#059669', bg: '#ECFDF5', label: 'Projets actifs', value: String(k.projets_actifs ?? 0), sub: 'en cours', path: '/projets' },
    { icon: 'people', color: '#2563EB', bg: '#EFF6FF', label: 'Membres', value: String(k.membres ?? 0), sub: (k.membres_nouveaux > 0 ? '+' + k.membres_nouveaux + ' en 30j' : 'actifs'), path: '/adherents' },
    { icon: 'calendar', color: '#D97706', bg: '#FFFBEB', label: 'Événements', value: String(k.evenements ?? 0), sub: 'à venir', path: '/agenda' },
    { icon: 'wallet', color: '#7C3AED', bg: '#F5F3FF', label: 'Budget engagé', value: fmtEuro(k.budget_used), sub: 'sur ' + fmtEuro(k.budget_planned), path: '/projets' },
  ];

  return (
    <ScrollView
      style={styles.homeScroll}
      contentContainerStyle={styles.homeContent}
      showsVerticalScrollIndicator={false}
      refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}
    >
      <LinearGradient
        colors={['#07A873', '#047857']}
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
            <Text style={styles.hAvatarTxt}>{(data && data.org_initials) || '·'}</Text>
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
            {SHORTCUTS.map((s) => (
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
/*  SHELL (WebView + nav native + accueil natif)                       */
/* ================================================================== */
function AppShell({ startPath, onExitToWelcome }) {
  const webRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [canGoBack, setCanGoBack] = useState(false);
  const [active, setActive] = useState('accueil');
  const [quickOpen, setQuickOpen] = useState(false);
  const [authed, setAuthed] = useState(false);
  const [kpi, setKpi] = useState(null);
  const [kpiLoading, setKpiLoading] = useState(false);

  const inject = useCallback((js) => {
    if (webRef.current) webRef.current.injectJavaScript(js);
  }, []);

  const fetchKpis = useCallback(() => {
    setKpiLoading(true);
    inject(FETCH_KPIS_JS);
  }, [inject]);

  useEffect(() => {
    if (active === 'accueil' && authed) fetchKpis();
  }, [active, authed, fetchKpis]);

  useEffect(() => {
    if (Platform.OS !== 'android') return;
    const onBack = () => {
      if (quickOpen) { setQuickOpen(false); return true; }
      if (active !== 'accueil') { goTab(TABS[0]); return true; }
      if (canGoBack && webRef.current) { webRef.current.goBack(); return true; }
      onExitToWelcome();
      return true;
    };
    const sub = BackHandler.addEventListener('hardwareBackPress', onBack);
    return () => sub.remove();
    // eslint-disable-next-line
  }, [canGoBack, quickOpen, active, onExitToWelcome]);

  const onNav = (nav) => {
    setCanGoBack(nav.canGoBack);
    const u = nav.url || '';
    if (/\/(connexion|signup|deconnexion|login|mot-de-passe|verifier-email)/.test(u)) setAuthed(false);
    else if (u.indexOf('assokit.fr') !== -1) setAuthed(true);
  };

  const onMessage = (e) => {
    try {
      const msg = JSON.parse(e.nativeEvent.data);
      if (msg && msg.__akkpi) { setKpi(msg.__akkpi); setKpiLoading(false); }
    } catch (err) {}
  };

  const goTab = (tab) => {
    if (tab.key === 'add') { setQuickOpen(true); return; }
    if (tab.key === 'menu') { setActive('menu'); inject(OPEN_MENU_JS); return; }
    setActive(tab.key);
    if (tab.path) inject(gotoJS(tab.path));
  };

  const onQuick = (a) => {
    setQuickOpen(false);
    setActive('projets');
    inject(gotoJS(a.path));
  };

  const gotoFromHome = (path) => {
    setActive('projets');
    inject(gotoJS(path));
  };

  const showHome = active === 'accueil' && authed;

  return (
    <SafeAreaView style={styles.safe}>
      <StatusBar barStyle={showHome ? 'light-content' : 'dark-content'} backgroundColor={showHome ? '#07A873' : '#fff'} />
      <View style={styles.webWrap}>
        <WebView
          ref={webRef}
          source={{ uri: BASE + startPath }}
          onLoadStart={() => setLoading(true)}
          onLoadEnd={() => { setLoading(false); if (active === 'accueil' && authed) fetchKpis(); }}
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
            <NativeHome data={kpi} loading={kpiLoading} onRefresh={fetchKpis} onGoto={gotoFromHome} />
          </View>
        )}
        {loading && !showHome && (
          <View style={styles.loader} pointerEvents="none">
            <ActivityIndicator size="large" color={BRAND} />
          </View>
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

export default function App() {
  const [path, setPath] = useState(null);

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
        console.log('Expo push token:', token);
      } catch (e) {}
    })();
  }, []);

  if (!path) {
    return <WelcomeScreen onLogin={() => setPath('/connexion')} onSignup={() => setPath('/signup')} />;
  }
  return <AppShell startPath={path} onExitToWelcome={() => setPath(null)} />;
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
  blob1: { width: 340, height: 340, top: -90, right: -90, backgroundColor: 'rgba(255,255,255,0.15)' },
  blob2: { width: 300, height: 300, bottom: 40, left: -100, backgroundColor: 'rgba(6,214,160,0.30)' },
  blob3: { width: 220, height: 220, top: 220, right: 140, backgroundColor: 'rgba(252,211,77,0.16)' },
  wSafe: { flex: 1, paddingHorizontal: 24, justifyContent: 'space-between' },
  wTop: { alignItems: 'center', marginTop: 40 },
  logoHalo: { width: 108, height: 108, borderRadius: 30, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(255,255,255,0.14)', marginBottom: 20 },
  logoTile: { width: 80, height: 80, borderRadius: 22, backgroundColor: '#fff', shadowColor: '#000', shadowOpacity: 0.22, shadowRadius: 22, shadowOffset: { width: 0, height: 12 }, elevation: 10 },
  logoDot: { position: 'absolute', right: 17, bottom: 17, width: 19, height: 19, borderRadius: 10, backgroundColor: BRAND },
  brand: { color: '#fff', fontSize: 42, fontWeight: '800', letterSpacing: -0.6 },
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
