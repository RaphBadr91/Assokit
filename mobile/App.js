import React, { useRef, useState, useEffect } from 'react';
import {
  SafeAreaView,
  StyleSheet,
  View,
  ActivityIndicator,
  BackHandler,
  Platform,
  StatusBar,
} from 'react-native';
import { WebView } from 'react-native-webview';
import * as Notifications from 'expo-notifications';
import Constants from 'expo-constants';

const SITE_URL = 'https://assokit.fr';
const BRAND = '#059669';

// Comment afficher une notif quand l'app est au premier plan.
// (On fournit les anciens ET nouveaux champs pour rester compatible
//  quelle que soit la version du SDK Expo.)
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
  }),
});

export default function App() {
  const webRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [canGoBack, setCanGoBack] = useState(false);

  // Bouton retour Android -> revenir dans l'historique de la WebView
  useEffect(() => {
    if (Platform.OS !== 'android') return;
    const onBack = () => {
      if (canGoBack && webRef.current) {
        webRef.current.goBack();
        return true;
      }
      return false;
    };
    const sub = BackHandler.addEventListener('hardwareBackPress', onBack);
    return () => sub.remove();
  }, [canGoBack]);

  // Notifications push : demande la permission et recupere le token
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
        // projectId requis par les versions recentes du SDK (lu depuis app.json / EAS)
        const projectId =
          Constants?.expoConfig?.extra?.eas?.projectId ??
          Constants?.easConfig?.projectId;
        const token = (
          await Notifications.getExpoPushTokenAsync(
            projectId ? { projectId } : undefined
          )
        ).data;
        // Token a envoyer au serveur plus tard (integration push cote serveur)
        console.log('Expo push token:', token);
      } catch (e) {
        // silencieux : l'app fonctionne meme sans notifications
      }
    })();
  }, []);

  return (
    <SafeAreaView style={styles.safe}>
      <StatusBar barStyle="light-content" backgroundColor={BRAND} />
      <WebView
        ref={webRef}
        source={{ uri: SITE_URL }}
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

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: BRAND },
  web: { flex: 1, backgroundColor: '#ffffff' },
  loader: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    bottom: 0,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.65)',
  },
});
