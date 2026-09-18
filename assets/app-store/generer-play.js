/**
 * generer-play.js — Le visuel de présentation Google Play (feature graphic).
 * ------------------------------------------------------------------
 * Usage, depuis la racine du dépôt :
 *
 *     node assets/app-store/generer-play.js
 *
 * Sortie :
 *   assets/app-store/play-feature-graphic.jpg  1024 × 500, obligatoire
 *   assets/app-store/play-icone-512.png        512 × 512, obligatoire
 *
 * 1024 × 500 est le seul format que Play accepte pour le visuel ; 512 × 512 le
 * seul pour l'icône. L'icône est réduite depuis `mobile/assets/icon.png`, la
 * même que celle du binaire — les deux fiches montrent ainsi la même image.
 *
 * Play l'affiche parfois très réduit, et parfois recadré sur sa moitié centrale
 * quand il sert de bandeau. D'où deux règles tenues ici : aucun texte sous
 * 34 px, et rien d'essentiel en dehors de la bande centrale.
 *
 * Couleurs, dégradé et symbole viennent de `gabarit.js`, comme les planches
 * App Store : les deux fiches se ressemblent.
 * ------------------------------------------------------------------
 */

const fs = require('fs');
const path = require('path');
const { COULEURS, SYMBOLE, chargerPlaywright } = require('./gabarit');

const ICI = __dirname;
const L = 1024, H = 500;

function page(fontB64) {
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8">
<style>
  @font-face {
    font-family: 'Geist';
    src: url(data:font/woff2;base64,${fontB64}) format('woff2');
    font-weight: 100 900; font-style: normal;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { width: ${L}px; height: ${H}px; overflow: hidden; }
  body {
    font-family: 'Geist', system-ui, sans-serif;
    -webkit-font-smoothing: antialiased;
    background: linear-gradient(115deg,
      ${COULEURS.fondHaut} 0%, ${COULEURS.fondMilieu} 52%, ${COULEURS.fondBas} 100%);
    display: flex; align-items: center; justify-content: center;
  }
  /* Deux halos, repris de l'en-tête de l'app : ils évitent l'aplat de dégradé. */
  .halo { position: absolute; border-radius: 50%; pointer-events: none; }
  .halo-1 { width: 620px; height: 620px; right: -170px; top: -230px; background: rgba(255,255,255,0.09); }
  .halo-2 { width: 420px; height: 420px; left: -130px; bottom: -190px; background: rgba(255,255,255,0.06); }

  .bloc { position: relative; display: flex; align-items: center; gap: 34px; padding: 0 60px; }
  .symbole { line-height: 0; flex: none; }
  .titre { font-weight: 700; font-size: 82px; line-height: 88px; letter-spacing: -0.03em; color: #fff; }
  .sous { margin-top: 12px; font-weight: 500; font-size: 36px; line-height: 46px; color: rgba(255,255,255,0.80); }
</style></head><body>
  <div class="halo halo-1"></div>
  <div class="halo halo-2"></div>
  <div class="bloc">
    <div class="symbole">${SYMBOLE.replace('width="84" height="84"', 'width="132" height="132"')}</div>
    <div>
      <div class="titre">Assokit</div>
      <div class="sous">Adhérents, cotisations, factures —<br>votre association en une application</div>
    </div>
  </div>
</body></html>`;
}

(async () => {
  const fontFile = path.join(ICI, 'geist-variable.woff2');
  if (!fs.existsSync(fontFile)) { console.error('Police absente : ' + fontFile); process.exit(1); }
  const fontB64 = fs.readFileSync(fontFile).toString('base64');

  const { chromium } = chargerPlaywright();
  const navigateur = await chromium.launch({
    executablePath: process.env.PW_CHROME || undefined,
    args: ['--no-sandbox', '--font-render-hinting=none'],
  });
  const onglet = await (await navigateur.newContext({
    viewport: { width: L, height: H }, deviceScaleFactor: 1,
  })).newPage();

  await onglet.setContent(page(fontB64), { waitUntil: 'load' });
  await onglet.evaluate(() => document.fonts.ready.then(() => true));

  const fichier = path.join(ICI, 'play-feature-graphic.jpg');
  await onglet.screenshot({ path: fichier, type: 'jpeg', quality: 94 });
  console.log(`  play-feature-graphic.jpg  ${L} × ${H}  (${Math.round(fs.statSync(fichier).size / 1024)} Ko)`);

  // L'icône du binaire, réduite à la taille qu'exige Play. Play refuse la
  // transparence sur ce champ : on pose l'image sur le vert de la marque.
  const source = path.join(ICI, '../../mobile/assets/icon.png');
  if (fs.existsSync(source)) {
    const icone = await (await navigateur.newContext({
      viewport: { width: 512, height: 512 }, deviceScaleFactor: 1,
    })).newPage();
    await icone.setContent(
      `<!doctype html><meta charset="utf-8">
       <style>*{margin:0;padding:0}html,body{width:512px;height:512px;overflow:hidden;background:#059669}
       img{width:512px;height:512px;display:block;image-rendering:auto}</style>
       <img src="data:image/png;base64,${fs.readFileSync(source).toString('base64')}">`,
      { waitUntil: 'load' },
    );
    const f2 = path.join(ICI, 'play-icone-512.png');
    await icone.screenshot({ path: f2, type: 'png' });
    console.log(`  play-icone-512.png        512 × 512  (${Math.round(fs.statSync(f2).size / 1024)} Ko)`);
  } else {
    console.log('  (icône source introuvable : ' + source + ')');
  }

  await navigateur.close();
})();
