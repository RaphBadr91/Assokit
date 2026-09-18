/**
 * generer-maquettes.js — Rend les 10 planches VIDES en JPEG.
 * ------------------------------------------------------------------
 * Usage :
 *     node assets/app-store/generer-maquettes.js
 *
 * Sortie : assets/app-store/maquette-01.jpg … maquette-10.jpg (1290 × 2796).
 *
 * Ces planches attendent qu'on y dépose une capture à la main (Figma, ou
 * n'importe quel éditeur d'images). Pour des planches DÉJÀ remplies, prises
 * automatiquement dans l'app, voir `generer-captures.js`.
 *
 * Le gabarit — cotes, couleurs, textes — vit dans `gabarit.js`, partagé par
 * les deux scripts : c'est ce qui les empêche de diverger.
 *
 * La police Geist est incorporée en base64 : le rendu ne dépend d'aucun
 * accès réseau ni d'une police installée sur la machine. C'est une fonte
 * variable, donc les graisses sont réelles — pas de faux gras.
 * ------------------------------------------------------------------
 */

const fs = require('fs');
const path = require('path');
const { L, H, ECRANS, page, chargerPlaywright } = require('./gabarit');

const ICI = __dirname;
const SORTIE = ICI;

(async () => {
  const fontFile = path.join(ICI, 'geist-variable.woff2');
  if (!fs.existsSync(fontFile)) {
    console.error('Police absente : ' + fontFile);
    process.exit(1);
  }
  const fontB64 = fs.readFileSync(fontFile).toString('base64');

  const { chromium } = chargerPlaywright();
  const navigateur = await chromium.launch({
    executablePath: process.env.PW_CHROME || undefined,
    args: ['--no-sandbox', '--font-render-hinting=none'],
  });
  const ctx = await navigateur.newContext({
    viewport: { width: L, height: H },
    deviceScaleFactor: 1,
  });
  const onglet = await ctx.newPage();

  for (let i = 0; i < ECRANS.length; i++) {
    const num = String(i + 1).padStart(2, '0');
    await onglet.setContent(page(ECRANS[i], fontB64), { waitUntil: 'load' });
    await onglet.evaluate(() => document.fonts.ready);
    const fichier = path.join(SORTIE, `maquette-${num}.jpg`);
    await onglet.screenshot({ path: fichier, type: 'jpeg', quality: 92 });
    const ko = Math.round(fs.statSync(fichier).size / 1024);
    console.log(`  maquette-${num}.jpg  ${ECRANS[i].nom}  (${ko} Ko)`);
  }

  await navigateur.close();
  console.log('\n10 maquettes rendues en 1290 × 2796.');
})();
