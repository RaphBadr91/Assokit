/**
 * generer-captures.js — Rend les 10 planches App Store, CAPTURES COMPRISES.
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
 * Les planches vont dans App Store Connect ; les écrans seuls servent pour
 * Google Play, qui n'attend pas d'habillage marketing.
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
const http = require('http');
const { ECRANS, page, chargerPlaywright } = require('./gabarit');

const ICI = __dirname;
const RACINE = path.resolve(ICI, '../..');
const MOBILE = path.join(RACINE, 'mobile');
const DIST = path.join(MOBILE, 'dist-web');
const BRUT = path.join(ICI, 'brut');

// Taille logique de l'iPhone 15/16 Pro Max. En densité 3, la capture sort
// exactement en 1290 × 2796 — le format qu'attend App Store Connect.
const ECRAN = { largeur: 430, hauteur: 932, densite: 3 };
const BARRE = 47; // hauteur de la zone de statut, en points

/* ── Le serveur qui sert l'app ────────────────────────────────────── */

function cheminBundle() {
  const dir = path.join(DIST, '_expo/static/js/web');
  if (!fs.existsSync(dir)) {
    console.error('Export web introuvable : ' + DIST);
    console.error('Lancez d’abord :  cd mobile && npx expo export --platform web --output-dir dist-web');
    process.exit(1);
  }
  const f = fs.readdirSync(dir).find((n) => n.endsWith('.js'));
  if (!f) { console.error('Aucun bundle .js dans ' + dir); process.exit(1); }
  return '/_expo/static/js/web/' + f;
}

const TYPES = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json', '.ttf': 'font/ttf', '.otf': 'font/otf',
  '.woff': 'font/woff', '.woff2': 'font/woff2', '.png': 'image/png',
  '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.css': 'text/css; charset=utf-8',
};

/**
 * Sert l'export sur 127.0.0.1, et la coque à `/coque.html?barre=…`.
 *
 * Servir en http:// plutôt qu'ouvrir un fichier en file:// n'est pas un détail :
 * en file://, le chargeur de polices d'Expo échoue, et les pictogrammes montés
 * pendant cet échec restent vides pour toujours — c'est ainsi qu'une capture
 * partait avec une icône manquante sur la première carte. En http://, l'app
 * charge ses polices comme elle le fait sur l'appareil.
 */
function servir(bundleUrl) {
  const serveur = http.createServer((req, res) => {
    const [chemin, requete] = req.url.split('?');
    const url = decodeURIComponent(chemin);

    if (url === '/coque.html') {
      const barre = new URLSearchParams(requete || '').get('barre') || 'blanche';
      res.writeHead(200, { 'Content-Type': TYPES['.html'] });
      res.end(coque(bundleUrl, barre));
      return;
    }
    // path.join normalise « .. » : rien hors de l'export ne peut être servi.
    const fichier = path.join(DIST, url);
    if (!fichier.startsWith(DIST) || !fs.existsSync(fichier) || fs.statSync(fichier).isDirectory()) {
      res.writeHead(404); res.end('introuvable'); return;
    }
    res.writeHead(200, { 'Content-Type': TYPES[path.extname(fichier)] || 'application/octet-stream' });
    fs.createReadStream(fichier).pipe(res);
  });
  return new Promise((ok) => serveur.listen(0, '127.0.0.1', () => ok({ serveur, port: serveur.address().port })));
}

/**
 * Coque HTML : une fenêtre de la taille d'un iPhone, une zone de statut
 * dessinée, et l'app en dessous.
 *
 * `barre` reprend ce que fait l'app sur l'appareil :
 *   accueil — l'app peint la zone de statut en vert foncé (HEAD_GRAD[0]) ;
 *   blanche — tous les autres écrans natifs la repassent en blanc ;
 *   fondu   — l'écran d'accueil public s'étend sous la barre, qu'on superpose.
 */
function coque(bundleUrl, barre) {
  const fondu = barre === 'fondu';
  const fond = barre === 'accueil' ? '#0B3B2A' : '#FFFFFF';
  const encre = barre === 'blanche' ? '#0B1A13' : '#FFFFFF';
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { width: ${ECRAN.largeur}px; height: ${ECRAN.hauteur}px; overflow: hidden; background: ${fond}; }
  #scene { position: relative; width: ${ECRAN.largeur}px; height: ${ECRAN.hauteur}px; overflow: hidden; background: ${fond}; }
  #barre {
    position: absolute; top: 0; left: 0; right: 0; height: ${BARRE}px; z-index: 50;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 30px 0 34px; pointer-events: none;
    color: ${encre}; font: 600 16px/1 -apple-system, "SF Pro Text", "Segoe UI", sans-serif;
    font-variant-numeric: tabular-nums; letter-spacing: 0.01em;
    background: ${fondu ? 'transparent' : fond};
  }
  .glyphes { display: flex; align-items: center; gap: 7px; }
  .sig { display: flex; align-items: flex-end; gap: 2px; height: 11px; }
  .sig i { width: 3px; border-radius: 1px; background: ${encre}; }
  .sig i:nth-child(1) { height: 4px; } .sig i:nth-child(2) { height: 6px; }
  .sig i:nth-child(3) { height: 9px; } .sig i:nth-child(4) { height: 11px; }
  .wifi { width: 16px; height: 12px; position: relative; }
  .wifi::before {
    content: ''; position: absolute; inset: 0;
    border: 2.4px solid ${encre}; border-radius: 50%;
    clip-path: polygon(0 0, 100% 0, 100% 52%, 0 52%);
  }
  .wifi::after {
    content: ''; position: absolute; left: 50%; bottom: 0; width: 4px; height: 4px;
    transform: translateX(-50%); background: ${encre}; border-radius: 50%;
  }
  .bat { width: 24px; height: 12px; border: 1.5px solid ${encre}; border-radius: 3.5px; position: relative; opacity: .95; }
  .bat::after { content: ''; position: absolute; inset: 1.7px; right: 5px; background: ${encre}; border-radius: 1.5px; }
  .bat::before { content: ''; position: absolute; right: -3.5px; top: 3.5px; width: 2px; height: 5px; background: ${encre}; border-radius: 0 2px 2px 0; opacity: .6; }

  /* L'app se monte dans #root. Hors « fondu », on la cale sous la zone de
     statut, exactement comme le fait la SafeAreaView sur l'appareil. */
  #root {
    position: absolute; top: ${fondu ? 0 : BARRE}px; left: 0; right: 0; bottom: 0;
    display: flex; flex-direction: column;
  }
</style></head><body>
  <div id="scene">
    <div id="barre">
      <span>9:41</span>
      <span class="glyphes">
        <span class="sig"><i></i><i></i><i></i><i></i></span>
        <span class="wifi"></span>
        <span class="bat"></span>
      </span>
    </div>
    <div id="root"></div>
  </div>
  <script src="${bundleUrl}"></script>
</body></html>`;
}

/* ── Navigation dans l'app ────────────────────────────────────────── */

/**
 * Touche un libellé. On prend la DERNIÈRE occurrence : la WebView simulée
 * reste montée sous les écrans natifs, et son texte précède le leur dans le DOM.
 */
async function toucher(onglet, libelle) {
  await onglet.getByText(libelle, { exact: true }).last().click({ timeout: 8000 });
}

/** Les pictogrammes sont posés par une police : tant qu'elle charge, ils sont vides. */
async function attendrePictogrammes(onglet) {
  await onglet.evaluate(() => document.fonts.ready.then(() => true));
  // Échouer ici plutôt que livrer une planche aux pictogrammes manquants : c'est
  // exactement ce qui arrivait quand la page était ouverte en file://.
  await onglet.waitForFunction(
    () => [...document.fonts].some((f) => f.family.toLowerCase() === 'ionicons' && f.status === 'loaded'),
    null,
    { timeout: 15000 },
  );
}

async function capturerEcran(ctx, base, ecran, fichierBrut) {
  const onglet = await ctx.newPage();
  const nav = ecran.nav || {};
  try {
    await onglet.goto(`${base}/coque.html?barre=${nav.barre || 'blanche'}`, { waitUntil: 'load' });
    await attendrePictogrammes(onglet);
    await onglet.waitForTimeout(1400);

    if (nav.entrer !== false) {
      // L'écran d'accueil public précède l'app : on le passe.
      await toucher(onglet, 'Se connecter');
      await onglet.waitForTimeout(2600); // la WebView simulée authentifie, puis les données arrivent
    }
    for (const libelle of (nav.taps || [])) {
      await toucher(onglet, libelle);
      await onglet.waitForTimeout(1500);
    }
    await onglet.waitForTimeout(nav.attente || 900);

    const image = await onglet.screenshot({ path: fichierBrut, type: 'png' });
    return 'data:image/png;base64,' + image.toString('base64');
  } finally {
    await onglet.close();
  }
}

/* ── Programme ────────────────────────────────────────────────────── */

(async () => {
  const fontFile = path.join(ICI, 'geist-variable.woff2');
  if (!fs.existsSync(fontFile)) { console.error('Police absente : ' + fontFile); process.exit(1); }
  const fontB64 = fs.readFileSync(fontFile).toString('base64');

  fs.mkdirSync(BRUT, { recursive: true });

  const { serveur, port } = await servir(cheminBundle());
  const base = `http://127.0.0.1:${port}`;

  const { chromium } = chargerPlaywright();
  const navigateur = await chromium.launch({
    executablePath: process.env.PW_CHROME || undefined,
    args: ['--no-sandbox', '--font-render-hinting=none'],
  });

  const ctxApp = await navigateur.newContext({
    viewport: { width: ECRAN.largeur, height: ECRAN.hauteur },
    deviceScaleFactor: ECRAN.densite,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    reducedMotion: 'reduce', // pas d'animation figée à mi-course dans la capture
  });
  const ctxPlanche = await navigateur.newContext({
    viewport: { width: 1290, height: 2796 },
    deviceScaleFactor: 1,
  });
  const planche = await ctxPlanche.newPage();

  let echecs = 0;
  for (let i = 0; i < ECRANS.length; i++) {
    const num = String(i + 1).padStart(2, '0');
    const ecran = ECRANS[i];
    const fichierBrut = path.join(BRUT, `ecran-${num}.png`);

    let capture = null;
    try {
      capture = await capturerEcran(ctxApp, base, ecran, fichierBrut);
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
      const ko = Math.round(fs.statSync(fichier).size / 1024);
      console.log(`  ✓ capture-${num}.jpg  ${ecran.nom}  (${ko} Ko)`);
    }
  }

  await navigateur.close();
  await new Promise((ok) => serveur.close(ok));

  console.log(`\n${ECRANS.length - echecs}/${ECRANS.length} planches remplies, en 1290 × 2796.`);
  console.log('Écrans seuls (Google Play) : assets/app-store/brut/');
  if (echecs) process.exitCode = 1;
})();
