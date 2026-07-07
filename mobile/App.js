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
} from 'react-native';
import { WebView } from 'react-native-webview';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import * as Notifications from 'expo-notifications';
import Constants from 'expo-constants';

const BASE = 'https://assokit.fr';
const BRAND = '#059669';
const INK = '#0F172A';
const MUTE = '#94A3B8';

// CSS injecte UNIQUEMENT dans l'app (le site web n'est jamais modifie) :
// masque les bandeaux ET le header mobile web (remplace par la nav native).
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

// Ouvre la sidebar web existante (pour l'onglet "Plus")
const OPEN_MENU_JS = `
(function(){ try {
  var sb = document.getElementById('sidebar');
  var ov = document.getElementById('overlay') || document.querySelector('.sb-overlay');
  if (sb) sb.classList.add('open');
  if (ov) ov.classList.add('active');
} catch(e){} })();
true;
`;

function gotoJS(path) {
  return "(function(){ try { window.location.href='" + BASE + path + "'; } catch(e){} })(); true;";
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
  'Projets, adhérents & cotisations',
  'Factures & reçus fiscaux',
  'Comptabilité analytique incluse',
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

/* ------------------------------------------------------------------ */
/*  Ecran d'accueil NATIF                                               */
/* ------------------------------------------------------------------ */
function WelcomeScreen({ onLogin, onSignup }) {
  return (
    <LinearGradient
      colors={['#07A873', '#059669', '#046B50']}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={styles.wBg}
    >
      <StatusBar barStyle="light-content" backgroundColor="#07A873" />
      <SafeAreaView style={styles.wSafe}>
        <View style={styles.wTop}>
          <View style={styles.logoTile}>
            <View style={styles.logoDot} />
          </View>
          <Text style={styles.brand}>Assokit</Text>
          <Text style={styles.tagline}>L'art de mener vos projets</Text>
        </View>

        <View style={styles.card}>
          {FEATURES.map((f, i) => (
            <View
              key={i}
              style={[styles.featureRow, i === FEATURES.length - 1 && styles.featureLast]}
            >
              <View style={styles.check}>
                <Ionicons name="checkmark" size={16} color={BRAND} />
              </View>
              <Text style={styles.featureTxt}>{f}</Text>
            </View>
          ))}
        </View>

        <View style={styles.actions}>
          <TouchableOpacity style={styles.btnPrimary} onPress={onLogin} activeOpacity={0.85}>
            <Text style={styles.btnPrimaryTxt}>Se connecter</Text>
          </TouchableOpacity>
          <TouchableOpacity style={styles.btnGhost} onPress={onSignup} activeOpacity={0.7}>
            <Text style={styles.btnGhostTxt}>Créer ma démo</Text>
          </TouchableOpacity>
          <Text style={styles.wFooter}>🇫🇷 Hébergé en France · Conforme RGPD</Text>
        </View>
      </SafeAreaView>
    </LinearGradient>
  );
}

/* ------------------------------------------------------------------ */
/*  Application (WebView + nav native)                                 */
/* ------------------------------------------------------------------ */
function AppShell({ startPath, onExitToWelcome }) {
  const webRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [canGoBack, setCanGoBack] = useState(false);
  const [active, setActive] = useState('accueil');
  const [quickOpen, setQuickOpen] = useState(false);

  const inject = useCallback((js) => {
    if (webRef.current) webRef.current.injectJavaScript(js);
  }, []);

  useEffect(() => {
    if (Platform.OS !== 'android') return;
    const onBack = () => {
      if (quickOpen) { setQuickOpen(false); return true; }
      if (canGoBack && webRef.current) { webRef.current.goBack(); return true; }
      onExitToWelcome();
      return true;
    };
    const sub = BackHandler.addEventListener('hardwareBackPress', onBack);
    return () => sub.remove();
  }, [canGoBack, quickOpen, onExitToWelcome]);

  const onTab = (tab) => {
    if (tab.key === 'add') { setQuickOpen(true); return; }
    if (tab.key === 'menu') { inject(OPEN_MENU_JS); setActive('menu'); return; }
    setActive(tab.key);
    inject(gotoJS(tab.path));
  };

  const onQuick = (a) => {
    setQuickOpen(false);
    inject(gotoJS(a.path));
  };

  return (
    <SafeAreaView style={styles.safe}>
      <StatusBar barStyle="light-content" backgroundColor={BRAND} />
      <View style={styles.webWrap}>
        <WebView
          ref={webRef}
          source={{ uri: BASE + startPath }}
          onLoadStart={() => setLoading(true)}
          onLoadEnd={() => setLoading(false)}
          onNavigationStateChange={(nav) => setCanGoBack(nav.canGoBack)}
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
        {loading && (
          <View style={styles.loader} pointerEvents="none">
            <ActivityIndicator size="large" color={BRAND} />
          </View>
        )}
      </View>

      {/* Barre d'onglets native */}
      <View style={styles.tabBar}>
        {TABS.map((tab) => {
          if (tab.key === 'add') {
            return (
              <TouchableOpacity key={tab.key} style={styles.fabWrap} onPress={() => onTab(tab)} activeOpacity={0.85}>
                <View style={styles.fab}>
                  <Ionicons name="add" size={30} color="#fff" />
                </View>
              </TouchableOpacity>
            );
          }
          const isActive = active === tab.key;
          return (
            <TouchableOpacity key={tab.key} style={styles.tab} onPress={() => onTab(tab)} activeOpacity={0.7}>
              <Ionicons
                name={isActive ? tab.icon : (tab.icon + '-outline')}
                size={23}
                color={isActive ? BRAND : MUTE}
              />
              <Text style={[styles.tabLabel, { color: isActive ? BRAND : MUTE }]}>{tab.label}</Text>
            </TouchableOpacity>
          );
        })}
      </View>

      {/* Feuille d'actions rapides */}
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
        const token = (
          await Notifications.getExpoPushTokenAsync(
            projectId ? { projectId } : undefined
          )
        ).data;
        console.log('Expo push token:', token);
      } catch (e) {}
    })();
  }, []);

  if (!path) {
    return (
      <WelcomeScreen
        onLogin={() => setPath('/connexion')}
        onSignup={() => setPath('/signup')}
      />
    );
  }
  return <AppShell startPath={path} onExitToWelcome={() => setPath(null)} />;
}

const styles = StyleSheet.create({
  /* Shell / WebView */
  safe: { flex: 1, backgroundColor: '#fff' },
  webWrap: { flex: 1, backgroundColor: '#fff' },
  web: { flex: 1, backgroundColor: '#ffffff' },
  loader: {
    position: 'absolute',
    top: 0, left: 0, right: 0, bottom: 0,
    alignItems: 'center', justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.65)',
  },

  /* Tab bar */
  tabBar: {
    flexDirection: 'row',
    backgroundColor: '#fff',
    borderTopWidth: 1,
    borderTopColor: '#EEF2F6',
    paddingBottom: Platform.OS === 'ios' ? 22 : 10,
    paddingTop: 8,
    alignItems: 'flex-end',
    shadowColor: '#0F172A',
    shadowOpacity: 0.06,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: -3 },
    elevation: 12,
  },
  tab: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3 },
  tabLabel: { fontSize: 11, fontWeight: '600' },
  fabWrap: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  fab: {
    width: 56, height: 56, borderRadius: 28, backgroundColor: BRAND,
    alignItems: 'center', justifyContent: 'center', marginTop: -26,
    shadowColor: BRAND, shadowOpacity: 0.4, shadowRadius: 10,
    shadowOffset: { width: 0, height: 6 }, elevation: 8,
    borderWidth: 4, borderColor: '#fff',
  },

  /* Quick actions sheet */
  sheetBackdrop: { flex: 1, backgroundColor: 'rgba(15,23,42,0.45)', justifyContent: 'flex-end' },
  sheet: {
    backgroundColor: '#fff', borderTopLeftRadius: 24, borderTopRightRadius: 24,
    paddingHorizontal: 18, paddingTop: 10, paddingBottom: 34,
  },
  sheetHandle: {
    alignSelf: 'center', width: 40, height: 5, borderRadius: 3,
    backgroundColor: '#E2E8F0', marginBottom: 12,
  },
  sheetTitle: { fontSize: 18, fontWeight: '700', color: INK, marginBottom: 8, marginLeft: 4 },
  qaRow: { flexDirection: 'row', alignItems: 'center', paddingVertical: 13 },
  qaIcon: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center', marginRight: 14 },
  qaLabel: { flex: 1, fontSize: 16, fontWeight: '600', color: INK },

  /* Welcome */
  wBg: { flex: 1 },
  wSafe: { flex: 1, paddingHorizontal: 28, justifyContent: 'space-between' },
  wTop: { alignItems: 'center', marginTop: 48 },
  logoTile: {
    width: 78, height: 78, borderRadius: 22, backgroundColor: '#fff',
    shadowColor: '#000', shadowOpacity: 0.18, shadowRadius: 18,
    shadowOffset: { width: 0, height: 10 }, elevation: 8, marginBottom: 20,
  },
  logoDot: {
    position: 'absolute', right: 17, bottom: 17,
    width: 19, height: 19, borderRadius: 10, backgroundColor: BRAND,
  },
  brand: { color: '#fff', fontSize: 40, fontWeight: '700', letterSpacing: -0.5 },
  tagline: { color: 'rgba(255,255,255,0.9)', fontSize: 16, marginTop: 8 },
  card: {
    backgroundColor: 'rgba(255,255,255,0.12)', borderRadius: 20, padding: 8,
    marginVertical: 10, borderWidth: 1, borderColor: 'rgba(255,255,255,0.18)',
  },
  featureRow: {
    flexDirection: 'row', alignItems: 'center', paddingVertical: 14, paddingHorizontal: 14,
    borderBottomWidth: 1, borderBottomColor: 'rgba(255,255,255,0.14)',
  },
  featureLast: { borderBottomWidth: 0 },
  check: {
    width: 26, height: 26, borderRadius: 13, backgroundColor: '#fff',
    alignItems: 'center', justifyContent: 'center', marginRight: 14,
  },
  featureTxt: { color: '#fff', fontSize: 15.5, fontWeight: '500', flex: 1 },
  actions: { marginBottom: 22 },
  btnPrimary: {
    backgroundColor: '#fff', borderRadius: 15, paddingVertical: 17, alignItems: 'center',
    shadowColor: '#000', shadowOpacity: 0.12, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 4,
  },
  btnPrimaryTxt: { color: '#047857', fontSize: 17, fontWeight: '700' },
  btnGhost: {
    marginTop: 12, borderRadius: 15, paddingVertical: 16, alignItems: 'center',
    borderWidth: 1.5, borderColor: 'rgba(255,255,255,0.55)',
  },
  btnGhostTxt: { color: '#fff', fontSize: 16, fontWeight: '600' },
  wFooter: { color: 'rgba(255,255,255,0.85)', fontSize: 12.5, textAlign: 'center', marginTop: 18 },
});
