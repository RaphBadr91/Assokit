/**
 * generer-play-captures.js — Les captures de la fiche Google Play.
 * ------------------------------------------------------------------
 * Prérequis, une seule fois :
 *
 *     cd mobile
 *     npm install
 *     npx expo export --platform web --output-dir dist-web
 *
 * Puis, depuis la racine du dépôt :
 *
 *     node assets/app-store/generer-play-captures.js
 *
 * Sortie : assets/app-store/play/capture-01.jpg … capture-08.jpg, en 1080 × 1920.
 *
 * ── Pourquoi un jeu séparé de celui d'Apple ───────────────────────
 * Play demande des captures de téléphone en **9:16, au moins 1080 × 1920**, et
 * refuse qu'une dimension dépasse le double de l'autre. Les planches App Store
 * font 1290 × 2796, soit 2,167:1 : elles seraient rejetées au téléversement.
 * Play en exige aussi **quatre au minimum** (Apple trois), et en accepte huit au
 * plus — d'où les huit écrans les plus parlants, pris dans le même ordre.
 *
 * La capture de l'app est rigoureusement la même que pour Apple : c'est la
 * planche qui change de proportions, jamais l'écran montré.
 * ------------------------------------------------------------------
 */

const fs = require('fs');
const path = require('path');
const { PLAY, ECRANS, pagePlay, chargerPlaywright } = require('./gabarit');
const { servir, contexteTelephone, capturerEcran } = require('./capture-app');

const ICI = __dirname;
const SORTIE = path.join(ICI, 'play');

// Les huit premières planches : celles qui montrent une fonction. On laisse de
// côté le menu et l'écran d'accueil public, qui ferment bien une fiche App Store
// mais n'apportent rien dans un carrousel limité à huit.
const RETENUS = ECRANS.slice(0, 8);

(async () => {
  const fontFile = path.join(ICI, 'geist-variable.woff2');
  if (!fs.existsSync(fontFile)) { console.error('Police absente : ' + fontFile); process.exit(1); }
  const fontB64 = fs.readFileSync(fontFile).toString('base64');

  fs.mkdirSync(SORTIE, { recursive: true });

  const { base, fermer } = await servir();
  const { chromium } = chargerPlaywright();
  const navigateur = await chromium.launch({
    executablePath: process.env.PW_CHROME || undefined,
    args: ['--no-sandbox', '--font-render-hinting=none'],
  });

  const ctxApp = await contexteTelephone(navigateur);
  const ctxPlanche = await navigateur.newContext({
    viewport: { width: PLAY.L, height: PLAY.H }, deviceScaleFactor: 1,
  });
  const planche = await ctxPlanche.newPage();

  let echecs = 0;
  for (let i = 0; i < RETENUS.length; i++) {
    const num = String(i + 1).padStart(2, '0');
    const ecran = RETENUS[i];

    let capture = null;
    try {
      capture = await capturerEcran(ctxApp, base, ecran, null);
    } catch (e) {
      echecs++;
      console.log(`  ✗ ${num} ${ecran.nom} — capture impossible : ${String(e.message || e).split('\n')[0]}`);
      continue; // une planche Play vide n'a pas d'usage : Play en exige quatre pleines
    }

    await planche.setContent(pagePlay(ecran, fontB64, capture), { waitUntil: 'load' });
    await planche.evaluate(() => document.fonts.ready.then(() => true));
    const fichier = path.join(SORTIE, `capture-${num}.jpg`);
    await planche.screenshot({ path: fichier, type: 'jpeg', quality: 92 });
    console.log(`  ✓ play/capture-${num}.jpg  ${ecran.nom}  (${Math.round(fs.statSync(fichier).size / 1024)} Ko)`);
  }

  await navigateur.close();
  await fermer();

  const rendues = RETENUS.length - echecs;
  console.log(`\n${rendues}/${RETENUS.length} planches rendues, en ${PLAY.L} × ${PLAY.H} (9:16).`);
  if (rendues < 4) {
    console.error('⚠️ Play en exige quatre au minimum : la fiche ne passera pas en l’état.');
    process.exitCode = 1;
  } else if (echecs) {
    process.exitCode = 1;
  }
})();
