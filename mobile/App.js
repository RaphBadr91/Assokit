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

function gotoJS(path) {
  return "(function(){ try { window.location.href='" + BASE + path + "'; } catch(e){} })(); true;";
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
  { label: 'Nouveau projet', icon: 'add-circle', color: '#059669', path: '/nouveau-projet' },
  { label: 'Nouvelle facture', icon: 'document-text', color: '#2563EB', path: '/mon-asso-facture-new' },
  { label: 'Nouvel adhérent', icon: 'person-add', color: '#D97706', path: '/adherents' },
  { label: 'Nouveau message', icon: 'chatbubble-ellipses', color: '#7C3AED', path: '/messages' },
];

const QUICK_ACTIONS_TPE = [
  { label: 'Nouvelle facture', icon: 'document-text', color: '#2563EB', path: '/mon-asso-facture-new' },
  { label: 'Nouveau devis', icon: 'create', color: '#059669', path: '/mon-asso-devis-new' },
  { label: 'Nouveau client', icon: 'person-add', color: '#D97706', path: '/mon-asso-clients' },
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
function NativeProjects({ data, loading, onRefresh, onOpen, onNew }) {
  const projects = (data && data.projects) || [];
  return (
    <View style={styles.projWrap}>
      <View style={styles.projHeader}>
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
function NativePeople({ mode, data, loading, onRefresh, onOpen, onNew }) {
  const isClients = mode === 'clients';
  const list = data ? (isClients ? (data.clients || []) : (data.members || [])) : null;
  const title = isClients ? 'Clients' : 'Membres';
  const newLabel = isClients ? 'Nouveau' : 'Inviter';

  return (
    <View style={styles.projWrap}>
      <View style={styles.projHeader}>
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
function NativeInvoices({ data, loading, onRefresh, onOpen, onNew }) {
  const list = data ? (data.invoices || []) : null;
  return (
    <View style={styles.projWrap}>
      <View style={styles.projHeader}>
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
      ) : list.length === 0 ? (
        <View style={styles.emptyBox}>
          <Ionicons name="receipt-outline" size={44} color="#CBD5E1" />
          <Text style={styles.emptyTxt}>Aucune facture</Text>
          <TouchableOpacity style={styles.emptyBtn} onPress={onNew} activeOpacity={0.85}>
            <Text style={styles.emptyBtnTxt}>Créer une facture</Text>
          </TouchableOpacity>
        </View>
      ) : (
        <ScrollView
          contentContainerStyle={{ paddingHorizontal: 16, paddingBottom: 20 }}
          showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={!!loading} onRefresh={onRefresh} tintColor={BRAND} colors={[BRAND]} />}
        >
          {list.map((inv) => {
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
  const [projects, setProjects] = useState(null);
  const [projLoading, setProjLoading] = useState(false);
  const [people, setPeople] = useState(null);
  const [peopleLoading, setPeopleLoading] = useState(false);
  const [invoices, setInvoices] = useState(null);
  const [invLoading, setInvLoading] = useState(false);
  const [webMode, setWebMode] = useState(false);

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

  useEffect(() => {
    if (webMode || !authed) return;
    if (active === 'accueil') fetchKpis();
    else if (active === 'projets') fetchProjects();
    else if (active === 'factures') fetchInvoices();
    else if (active === 'people') fetchPeople(isTpe);
  }, [active, authed, webMode, isTpe, fetchKpis, fetchProjects, fetchInvoices, fetchPeople]);

  useEffect(() => {
    if (Platform.OS !== 'android') return;
    const onBack = () => {
      if (quickOpen) { setQuickOpen(false); return true; }
      if (webMode && canGoBack && webRef.current) { webRef.current.goBack(); return true; }
      if (webMode) { setWebMode(false); return true; }
      if (active !== 'accueil') { setActive('accueil'); return true; }
      onExitToWelcome();
      return true;
    };
    const sub = BackHandler.addEventListener('hardwareBackPress', onBack);
    return () => sub.remove();
    // eslint-disable-next-line
  }, [canGoBack, quickOpen, active, webMode, onExitToWelcome]);

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
      if (msg && msg.__akprojects) { setProjects(msg.__akprojects); setProjLoading(false); }
      if (msg && msg.__akmembers) { setPeople(msg.__akmembers); setPeopleLoading(false); }
      if (msg && msg.__akclients) { setPeople(msg.__akclients); setPeopleLoading(false); }
      if (msg && msg.__akinvoices) { setInvoices(msg.__akinvoices); setInvLoading(false); }
    } catch (err) {}
  };

  const goTab = (tab) => {
    if (tab.key === 'add') { setQuickOpen(true); return; }
    if (tab.key === 'menu') { setActive('menu'); setWebMode(true); inject(OPEN_MENU_JS); return; }
    // Onglets natifs : accueil / projets / factures / people
    setActive(tab.key);
    setWebMode(false);
  };

  const onQuick = (a) => {
    setQuickOpen(false);
    setWebMode(true);
    inject(gotoJS(a.path));
  };

  const onGoto = (path) => {
    if (path === '/projets') { setActive('projets'); setWebMode(false); return; }
    if (path === '/adherents' && !isTpe) { setActive('people'); setWebMode(false); return; }
    if (path === '/mon-asso-clients' && isTpe) { setActive('people'); setWebMode(false); return; }
    if (path === '/mon-asso-factures') { setActive('factures'); setWebMode(false); return; }
    setWebMode(true);
    inject(gotoJS(path));
  };

  const openProject = (id) => {
    setWebMode(true);
    inject(gotoJS('/projet/' + id));
  };

  const openInvoice = (id) => {
    setWebMode(true);
    inject(gotoJS('/mon-asso-facture-edit?id=' + id));
  };

  const openPerson = (id) => {
    setWebMode(true);
    inject(gotoJS(isTpe ? ('/mon-asso-client-detail?id=' + id) : ('/adherent?id=' + id)));
  };

  const showHome = active === 'accueil' && authed && !webMode;
  const showProjects = active === 'projets' && authed && !webMode;
  const showInvoices = active === 'factures' && authed && !webMode;
  const showPeople = active === 'people' && authed && !webMode;
  const showWeb = !showHome && !showProjects && !showInvoices && !showPeople;

  return (
    <SafeAreaView style={styles.safe}>
      <StatusBar barStyle={showHome ? 'light-content' : 'dark-content'} backgroundColor={showHome ? '#07A873' : '#fff'} />
      <View style={styles.webWrap}>
        <WebView
          ref={webRef}
          source={{ uri: BASE + startPath }}
          onLoadStart={() => setLoading(true)}
          onLoadEnd={() => { setLoading(false); if (!webMode && authed && active === 'accueil') fetchKpis(); }}
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
            <NativeProjects data={projects} loading={projLoading} onRefresh={fetchProjects} onOpen={openProject} onNew={() => onGoto('/nouveau-projet')} />
          </View>
        )}
        {showInvoices && (
          <View style={styles.homeOverlay}>
            <NativeInvoices data={invoices} loading={invLoading} onRefresh={fetchInvoices} onOpen={openInvoice} onNew={() => onGoto('/mon-asso-facture-new')} />
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
              onNew={() => onGoto(isTpe ? '/mon-asso-clients' : '/adherents')}
            />
          </View>
        )}
        {loading && showWeb && (
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
