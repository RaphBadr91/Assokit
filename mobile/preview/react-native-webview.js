/**
 * Faux `react-native-webview` pour la prévisualisation web uniquement.
 *
 * L'app est hybride : les écrans natifs récupèrent leurs données en injectant
 * du JavaScript dans la WebView, qui répond via `onMessage`. Sans WebView, tous
 * les écrans resteraient bloqués sur leur état de chargement.
 *
 * Ce module rejoue ce contrat : il lit le `fetch(...)` de la chaîne injectée,
 * en extrait le chemin d'API et la clé de réponse (`__akkpi`, `__akwrite`, …),
 * puis renvoie la donnée de démonstration correspondante après un court délai
 * — assez pour que les squelettes de chargement soient visibles.
 *
 * Aucune requête réseau n'est émise. Ce fichier n'est jamais embarqué dans le
 * binaire iOS/Android : il n'est substitué que pour la plateforme « web ».
 */
import React, { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { mockResponse } from './mock-api';

const LATENCE_MS = 260;

function lireEndpoint(js) {
  const m = /fetch\(\s*'([^']+)'/.exec(js);
  return m ? m[1] : null;
}

function lireCle(js) {
  // GET  : JSON.stringify({ __akkpi: d })
  // POST : var o={}; o["__akwrite"]=d;
  const get = /\{\s*(__ak\w+)\s*:\s*d\s*\}/.exec(js);
  if (get) return get[1];
  const post = /o\[\s*"(__ak\w+)"\s*\]/.exec(js);
  return post ? post[1] : null;
}

export const WebView = forwardRef(function WebViewShim(props, ref) {
  // Les gestionnaires sont des fonctions fléchées recréées à chaque rendu :
  // les mettre en dépendance d'un effet le relancerait en boucle. On passe donc
  // par une référence, et les effets ne s'exécutent qu'au montage.
  const propsRef = useRef(props);
  propsRef.current = props;

  // L'app déduit l'état « connecté » de la navigation de la WebView. Sans cette
  // simulation, la prévisualisation resterait bloquée sur l'écran public.
  // Les deux appels sont espacés : `onLoadEnd` déclenche le chargement des KPI
  // et doit voir `authed` déjà passé à vrai, donc après un nouveau rendu.
  useEffect(() => {
    const t1 = setTimeout(() => {
      const f = propsRef.current.onNavigationStateChange;
      if (typeof f === 'function') f({ url: 'https://assokit.fr/dashboard', canGoBack: false });
    }, 100);
    const t2 = setTimeout(() => {
      const f = propsRef.current.onLoadEnd;
      if (typeof f === 'function') f();
    }, 400);
    return () => { clearTimeout(t1); clearTimeout(t2); };
  }, []);

  useImperativeHandle(ref, () => ({
    injectJavaScript(js) {
      const source = String(js || '');
      const endpoint = lireEndpoint(source);
      const cle = lireCle(source);
      // Injections sans fetch (feuille de style de connexion, navigation…) :
      // rien à répondre, on les ignore silencieusement comme le ferait la vraie page.
      if (!endpoint || !cle) return;
      const isPost = /method\s*:\s*'POST'/.test(source);
      setTimeout(() => {
        const f = propsRef.current.onMessage;
        if (typeof f !== 'function') return;
        const charge = {};
        charge[cle] = mockResponse(endpoint, isPost);
        f({ nativeEvent: { data: JSON.stringify(charge) } });
      }, LATENCE_MS);
    },
    goBack() {},
    reload() {},
    stopLoading() {},
  }), []);

  return (
    <View style={styles.wrap}>
      <Text style={styles.title}>Page web du site</Text>
      <Text style={styles.body}>
        Cet écran affiche assokit.fr dans l’app réelle. La prévisualisation étant
        hors ligne, seuls les écrans natifs sont rendus ici.
      </Text>
    </View>
  );
});

const styles = StyleSheet.create({
  wrap: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32, backgroundColor: '#F4F8F6' },
  title: { fontSize: 15, fontWeight: '700', color: '#0B1A13', marginBottom: 8 },
  body: { fontSize: 13, lineHeight: 19, color: '#5F6D66', textAlign: 'center' },
});

export default WebView;
