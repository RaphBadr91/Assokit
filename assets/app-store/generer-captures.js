/**
 * generer-captures.js — Les 10 planches App Store, captures comprises.
 * ------------------------------------------------------------------
 * Prérequis, une seule fois :
 *
 *     cd mobile
 *     npm install
 *     npx expo export --platform web --output-dir dist-web
 *
 * Puis, depuis la racine du dépôt :
 *
 *     node assets/app-store/generer-captures.js
 *
 * Sortie :
 *     assets/app-store/capture-01.jpg … capture-10.jpg   planches 1290 × 2796
 *     assets/app-store/brut/ecran-01.png … ecran-10.png  écrans seuls, 1290 × 2796
 *
 * ⚠️ Ces fichiers sont au format d'Apple. Google Play refuse le 1290 × 2796
 * (2,167:1, au-delà du double autorisé entre les deux dimensions) : les planches
 * Play sont produites par `generer-play-captures.js`, en 1080 × 1920.
 *
 * ── Ce qui est capturé, exactement ────────────────────────────────
 * Le vrai code de l'app — le même App.js que le binaire iOS — compilé pour le
 * navigateur par Expo, avec les données de démonstration de
 * `mobile/preview/mock-api.js`. Ce ne sont donc PAS des maquettes redessinées :
 * mise en page, couleurs, typographie et navigation sont celles qui seront
 * livrées. Ce qui diffère d'un iPhone : le moteur de rendu est celui de
 * Chromium (flou, ombres et lissage des polices varient un peu), et la zone de
 * statut est dessinée ici plutôt que par iOS.
 *
 * Apple demande que les captures montrent l'app telle qu'elle est. Regardez-les
 * avant de les téléverser, et refaites-les depuis TestFlight si un écran a
 * changé depuis.
 * ------------------------------------------------------------------
 */

const fs = require('fs');
const path = require('path');
const { L, H, ECRANS, page, chargerPlaywright } = require('./gabarit');
const { servir, contexteTelephone, capturerEcran } = require('./capture-app');

const ICI = __dirname;
const BRUT = path.join(ICI, 'brut');

(async () => {
  const fontFile = path.join(ICI, 'geist-variable.woff2');
  if (!fs.existsSync(fontFile)) { console.error('Police absente : ' + fontFile); process.exit(1); }
  const fontB64 = fs.readFileSync(fontFile).toString('base64');

  fs.mkdirSync(BRUT, { recursive: true });

  const { base, fermer } = await servir();
  const { chromium } = chargerPlaywright();
  const navigateur = await chromium.launch({
    executablePath: process.env.PW_CHROME || undefined,
    args: ['--no-sandbox', '--font-render-hinting=none'],
  });

  const ctxApp = await contexteTelephone(navigateur);
  const ctxPlanche = await navigateur.newContext({ viewport: { width: L, height: H }, deviceScaleFactor: 1 });
  const planche = await ctxPlanche.newPage();

  let echecs = 0;
  for (let i = 0; i < ECRANS.length; i++) {
    const num = String(i + 1).padStart(2, '0');
    const ecran = ECRANS[i];

    let capture = null;
    try {
      capture = await capturerEcran(ctxApp, base, ecran, path.join(BRUT, `ecran-${num}.png`));
    } catch (e) {
      echecs++;
      console.log(`  ✗ ${num} ${ecran.nom} — capture impossible : ${String(e.message || e).split('\n')[0]}`);
      console.log('     → planche rendue vide, à remplir à la main.');
    }

    await planche.setContent(page(ecran, fontB64, capture), { waitUntil: 'load' });
    await planche.evaluate(() => document.fonts.ready.then(() => true));
    const fichier = path.join(ICI, `capture-${num}.jpg`);
    await planche.screenshot({ path: fichier, type: 'jpeg', quality: 92 });
    if (capture) {
      console.log(`  ✓ capture-${num}.jpg  ${ecran.nom}  (${Math.round(fs.statSync(fichier).size / 1024)} Ko)`);
    }
  }

  await navigateur.close();
  await fermer();

  console.log(`\n${ECRANS.length - echecs}/${ECRANS.length} planches remplies, en ${L} × ${H}.`);
  console.log('Pour Google Play : node assets/app-store/generer-play-captures.js');
  if (echecs) process.exitCode = 1;
})();
