/**
 * capture-app.js — Faire tourner l'app dans un navigateur, et la photographier.
 * ------------------------------------------------------------------
 * Partagé par `generer-captures.js` (planches App Store) et
 * `generer-play-captures.js` (planches Google Play) : les deux fiches montrent
 * ainsi rigoureusement les mêmes écrans, pris de la même façon.
 *
 * Prérequis : l'export web, produit par
 *     cd mobile && npx expo export --platform web --output-dir dist-web
 *
 * Ce qui est capturé : le vrai code de l'app — le même App.js que les binaires
 * iOS et Android — compilé pour le navigateur par Expo, et rempli avec les
 * données de démonstration de `mobile/preview/mock-api.js`.
 * ------------------------------------------------------------------
 */

const fs = require('fs');
const path = require('path');
const http = require('http');

const ICI = __dirname;
const DIST = path.resolve(ICI, '../../mobile/dist-web');

// Taille logique d'un grand téléphone. En densité 3, la capture sort en
// 1290 × 2796 — la taille qu'attend App Store Connect, et la source qu'on
// redimensionne pour Play.
const ECRAN = { largeur: 430, hauteur: 932, densite: 3 };
const BARRE = 47; // hauteur de la zone de statut, en points

const TYPES = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json', '.ttf': 'font/ttf', '.otf': 'font/otf',
  '.woff': 'font/woff', '.woff2': 'font/woff2', '.png': 'image/png',
  '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.css': 'text/css; charset=utf-8',
};

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

/**
 * Coque HTML : une fenêtre de la taille d'un téléphone, une zone de statut
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

/**
 * Sert l'export sur 127.0.0.1, et la coque à `/coque.html?barre=…`.
 *
 * Servir en http:// plutôt qu'ouvrir un fichier en file:// n'est pas un détail :
 * en file://, le chargeur de polices d'Expo échoue, et les pictogrammes montés
 * pendant cet échec restent vides pour toujours — c'est ainsi qu'une capture
 * partait avec une icône manquante sur la première carte. En http://, l'app
 * charge ses polices comme elle le fait sur l'appareil.
 */
function servir() {
  const bundleUrl = cheminBundle();
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
  return new Promise((ok) => serveur.listen(0, '127.0.0.1', () => ok({
    serveur,
    base: `http://127.0.0.1:${serveur.address().port}`,
    fermer: () => new Promise((f) => serveur.close(f)),
  })));
}

/** Contexte de navigateur réglé comme un grand téléphone. */
function contexteTelephone(navigateur) {
  return navigateur.newContext({
    viewport: { width: ECRAN.largeur, height: ECRAN.hauteur },
    deviceScaleFactor: ECRAN.densite,
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    reducedMotion: 'reduce', // pas d'animation figée à mi-course dans la capture
  });
}

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

/**
 * Ouvre l'app, navigue jusqu'à l'écran décrit par `ecran.nav`, et le
 * photographie en 1290 × 2796.
 *
 * @returns {Promise<string>} l'image en data:image/png;base64,…
 */
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

    const image = await onglet.screenshot(fichierBrut ? { path: fichierBrut, type: 'png' } : { type: 'png' });
    return 'data:image/png;base64,' + image.toString('base64');
  } finally {
    await onglet.close();
  }
}

module.exports = { ECRAN, BARRE, DIST, servir, contexteTelephone, capturerEcran, toucher, attendrePictogrammes };
