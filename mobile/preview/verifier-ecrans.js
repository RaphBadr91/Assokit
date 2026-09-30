/**
 * verifier-ecrans.js — Passe tous les écrans natifs au banc d'essai.
 * ------------------------------------------------------------------
 * Prérequis :
 *     npx expo export --platform web --output-dir dist-web
 *
 * Usage, depuis la racine du dépôt :
 *     node mobile/preview/verifier-ecrans.js            # les trois régimes
 *     node mobile/preview/verifier-ecrans.js normal     # un seul régime
 *
 * ── Ce que ça vérifie ─────────────────────────────────────────────
 * Chaque écran est ouvert sous trois régimes de réponse serveur :
 *
 *   normal — les données de démonstration ;
 *   vide   — le serveur répond « ok » sans rien d'autre : compte neuf,
 *            association sans données, réponse tronquée ;
 *   erreur — le serveur répond en échec : panne, session expirée, hors ligne.
 *
 * Un écran DOIT rester debout dans les trois cas. Afficher « aucune donnée » ou
 * un message d'erreur est un succès ; se vider, blanchir ou lever une exception
 * est un échec — et c'est exactement ce qu'un examinateur de store finit par
 * croiser, parce qu'il ouvre l'app sur un compte qu'il vient de recevoir.
 *
 * Le script rend un code de sortie non nul dès qu'un écran tombe : il peut
 * garder une chaîne d'intégration.
 * ------------------------------------------------------------------
 */

const path = require('path');
const { servir, contexteTelephone, toucher, attendrePictogrammes } = require(
  path.resolve(__dirname, '../../assets/app-store/capture-app'),
);
const { chargerPlaywright } = require(path.resolve(__dirname, '../../assets/app-store/gabarit'));

const REGIMES = ['normal', 'vide', 'erreur'];

/** Les écrans, tels qu'on les atteint au doigt. */
const ECRANS = [
  { nom: 'Accueil', taps: [] },
  { nom: 'Projets', taps: ['Projets'] },
  { nom: 'Membres', taps: ['Membres'] },
  { nom: 'Menu Plus', taps: ['Plus'] },
  { nom: 'Adhérents', taps: ['Plus', 'Adhérents'] },
  { nom: 'Agenda', taps: ['Plus', 'Agenda'] },
  { nom: 'Cotisations', taps: ['Plus', 'Cotisations'] },
  { nom: 'Subventions', taps: ['Plus', 'Subventions'] },
  { nom: 'Assemblées', taps: ['Plus', 'Assemblées'] },
  { nom: 'Émargement', taps: ['Plus', 'Émargement'] },
  { nom: 'Factures', taps: ['Plus', 'Factures'] },
  { nom: 'Devis', taps: ['Plus', 'Devis'] },
  { nom: 'Clients', taps: ['Plus', 'Clients'] },
  { nom: 'Statistiques', taps: ['Plus', 'Statistiques'] },
  { nom: 'Messages', taps: ['Plus', 'Messages'] },
  { nom: 'Notifications', taps: ['Plus', 'Notifications'] },
  { nom: 'Communication', taps: ['Plus', 'Communication'] },
  { nom: 'Coach IA', taps: ['Plus', 'Coach IA'] },
  { nom: 'Paramètres', taps: ['Plus', 'Paramètres'] },
  { nom: 'Support', taps: ['Plus', 'Support'] },
  // Fiches : on ouvre la première ligne de la liste. En régime « vide » ou
  // « erreur » la liste n'a pas de ligne — l'écran de départ fait alors foi.
  { nom: 'Fiche projet', taps: ['Projets', 'Festival de quartier 2026'], sautSiVide: true },
  { nom: 'Fiche adhérent', taps: ['Membres', 'Haoua Ali'], sautSiVide: true },
  // Formulaires : atteints par le bouton de création, présent quel que soit le régime.
  { nom: 'Formulaire adhérent', taps: ['Membres', 'Inviter'] },
  { nom: 'Formulaire projet', taps: ['Projets', 'Nouveau'] },
];

/** Un écran est « tombé » si React a démonté l'arbre ou si rien n'est lisible. */
async function etat(onglet) {
  return onglet.evaluate(() => {
    const root = document.getElementById('root');
    const txt = (root && root.innerText ? root.innerText : '').trim();
    return {
      enfants: root ? root.childElementCount : 0,
      // Le texte de la fausse WebView est toujours présent sous les écrans
      // natifs : on ne le compte pas comme du contenu.
      utile: txt.replace(/Page web du site[\s\S]*?rendus ici\./, '').trim().length,
      extrait: txt.replace(/Page web du site[\s\S]*?rendus ici\./, '').trim().replace(/\s+/g, ' ').slice(0, 90),
    };
  });
}

(async () => {
  const demandes = process.argv.slice(2).filter((a) => REGIMES.includes(a));
  const regimes = demandes.length ? demandes : REGIMES;

  const { base, fermer } = await servir();
  const { chromium } = chargerPlaywright();
  const navigateur = await chromium.launch({
    executablePath: process.env.PW_CHROME || undefined,
    args: ['--no-sandbox'],
  });
  const ctx = await contexteTelephone(navigateur);

  const echecs = [];
  let total = 0;

  for (const regime of regimes) {
    console.log(`\n── régime « ${regime} » ───────────────────────────────`);
    for (const ecran of ECRANS) {
      if (regime !== 'normal' && ecran.sautSiVide) continue;
      total++;
      const onglet = await ctx.newPage();
      const incidents = [];
      onglet.on('pageerror', (e) => incidents.push('exception : ' + String(e.message || e).split('\n')[0]));
      onglet.on('console', (m) => {
        if (m.type() !== 'error') return;
        const t = m.text();
        // Le chargeur d'assets d'Expo bavarde sur les ressources absentes de
        // l'aperçu ; ce n'est pas un défaut de l'app.
        if (/Failed to load resource|favicon/.test(t)) return;
        incidents.push('console : ' + t.slice(0, 160));
      });

      let verdict = 'OK';
      let detail = '';
      try {
        await onglet.goto(`${base}/coque.html?epreuve=${regime}`, { waitUntil: 'load' });
        await attendrePictogrammes(onglet);
        await onglet.waitForTimeout(1200);
        await toucher(onglet, 'Se connecter', 6000);
        await onglet.waitForTimeout(2400);

        let atteint = true;
        for (const t of ecran.taps) {
          try {
            await toucher(onglet, t, 3000);
          } catch (e) {
            // Libellé absent. En régime dégradé c'est voulu : sans données, l'app
            // ignore le rôle et masque ce qu'elle ne peut pas autoriser. En
            // régime normal, en revanche, c'est un vrai problème.
            atteint = false;
            verdict = regime === 'normal' ? 'INATTEIGNABLE' : 'masqué';
            detail = `« ${t} » absent de l'écran`;
            break;
          }
          await onglet.waitForTimeout(1300);
        }
        await onglet.waitForTimeout(atteint ? 700 : 200);

        // Même si l'écran visé n'a pas été atteint, celui où l'on s'est arrêté
        // doit tenir debout : c'est lui que l'utilisateur a sous les yeux.
        const e = await etat(onglet);
        if (e.enfants === 0) { verdict = 'ÉCRAN VIDE'; detail = 'React a démonté l’arbre'; }
        else if (e.utile < 3) { verdict = 'ÉCRAN BLANC'; detail = 'aucun texte rendu'; }
        else if (atteint) detail = e.extrait;
      } catch (err) {
        verdict = 'NAVIGATION';
        detail = String(err.message || err).split('\n')[0].slice(0, 110);
      }

      if (incidents.length && verdict === 'OK') verdict = 'INCIDENT';
      const benin = verdict === 'OK' || verdict === 'masqué';
      console.log(`  ${benin ? '✓' : '✗'} ${ecran.nom.padEnd(22)} ${verdict.padEnd(13)} ${detail}`);
      for (const i of incidents) console.log(`      ↳ ${i}`);
      if (!benin) echecs.push({ regime, ecran: ecran.nom, verdict, detail, incidents });

      await onglet.close();
    }
  }

  await navigateur.close();
  await fermer();

  console.log(`\n${total - echecs.length}/${total} écrans debout.`);
  if (echecs.length) {
    console.log('\nÀ corriger :');
    for (const e of echecs) console.log(`  · [${e.regime}] ${e.ecran} — ${e.verdict} : ${e.detail}`);
    process.exitCode = 1;
  }
})();
