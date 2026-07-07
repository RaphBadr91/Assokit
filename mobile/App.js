import React, { useRef, useState, useEffect } from 'react';
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
} from 'react-native';
import { WebView } from 'react-native-webview';
import { LinearGradient } from 'expo-linear-gradient';
import * as Notifications from 'expo-notifications';
import Constants from 'expo-constants';

const BASE = 'https://assokit.fr';
const BRAND = '#059669';

// CSS injecte UNIQUEMENT dans l'app (le site web n'est jamais modifie).
const APP_ONLY_CSS = `
(function(){ try {
  if (!document.getElementById('ak-app-only-css')) {
    var s = document.createElement('style');
    s.id = 'ak-app-only-css';
    s.textContent = '.ak-trial-banner{display:none!important}#ak-pwa-banner{display:none!important}';
    (document.head || document.documentElement).appendChild(s);
  }
} catch(e){} })();
true;
`;

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

/* ------------------------------------------------------------------ */
/*  Ecran d'accueil NATIF (premiere impression pro)                    */
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
                <Text style={styles.checkTxt}>✓</Text>
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
/*  WebView de l'application                                           */
/* ------------------------------------------------------------------ */
function AppWebView({ startPath, onExitToWelcome }) {
  const webRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [canGoBack, setCanGoBack] = useState(false);

  useEffect(() => {
    if (Platform.OS !== 'android') return;
    const onBack = () => {
      if (canGoBack && webRef.current) {
        webRef.current.goBack();
        return true;
      }
      onExitToWelcome();
      return true;
    };
    const sub = BackHandler.addEventListener('hardwareBackPress', onBack);
    return () => sub.remove();
  }, [canGoBack, onExitToWelcome]);

  return (
    <SafeAreaView style={styles.safe}>
      <StatusBar barStyle="light-content" backgroundColor={BRAND} />
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
    </SafeAreaView>
  );
}

export default function App() {
  const [path, setPath] = useState(null);

  // Notifications push : permission + token (une fois)
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
  return <AppWebView startPath={path} onExitToWelcome={() => setPath(null)} />;
}

const styles = StyleSheet.create({
  /* WebView */
  safe: { flex: 1, backgroundColor: BRAND },
  web: { flex: 1, backgroundColor: '#ffffff' },
  loader: {
    position: 'absolute',
    top: 0, left: 0, right: 0, bottom: 0,
    alignItems: 'center', justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.65)',
  },

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
  brand: {
    color: '#fff', fontSize: 40, fontWeight: '700',
    letterSpacing: -0.5,
  },
  tagline: {
    color: 'rgba(255,255,255,0.9)', fontSize: 16, marginTop: 8,
    fontWeight: '400',
  },

  card: {
    backgroundColor: 'rgba(255,255,255,0.12)',
    borderRadius: 20, padding: 8, marginVertical: 10,
    borderWidth: 1, borderColor: 'rgba(255,255,255,0.18)',
  },
  featureRow: {
    flexDirection: 'row', alignItems: 'center',
    paddingVertical: 14, paddingHorizontal: 14,
    borderBottomWidth: 1, borderBottomColor: 'rgba(255,255,255,0.14)',
  },
  featureLast: { borderBottomWidth: 0 },
  check: {
    width: 26, height: 26, borderRadius: 13, backgroundColor: '#fff',
    alignItems: 'center', justifyContent: 'center', marginRight: 14,
  },
  checkTxt: { color: BRAND, fontSize: 15, fontWeight: '800', lineHeight: 18 },
  featureTxt: { color: '#fff', fontSize: 15.5, fontWeight: '500', flex: 1 },

  actions: { marginBottom: 22 },
  btnPrimary: {
    backgroundColor: '#fff', borderRadius: 15, paddingVertical: 17,
    alignItems: 'center', shadowColor: '#000', shadowOpacity: 0.12,
    shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 4,
  },
  btnPrimaryTxt: { color: '#047857', fontSize: 17, fontWeight: '700' },
  btnGhost: {
    marginTop: 12, borderRadius: 15, paddingVertical: 16, alignItems: 'center',
    borderWidth: 1.5, borderColor: 'rgba(255,255,255,0.55)',
  },
  btnGhostTxt: { color: '#fff', fontSize: 16, fontWeight: '600' },
  wFooter: {
    color: 'rgba(255,255,255,0.85)', fontSize: 12.5, textAlign: 'center',
    marginTop: 18,
  },
});
