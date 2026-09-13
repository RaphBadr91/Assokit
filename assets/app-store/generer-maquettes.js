/**
 * generer-maquettes.js — Rend les 10 maquettes de capture App Store en JPEG.
 * ------------------------------------------------------------------
 * Les cotes reproduisent exactement le fichier Figma
 * « Assokit — Captures App Store » : même gabarit, même typographie, mêmes
 * couleurs. Modifier l'un sans l'autre les fera diverger.
 *
 * Usage :
 *     node assets/app-store/generer-maquettes.js
 *
 * Sortie : assets/app-store/maquette-01.jpg … maquette-10.jpg (1290 × 2796).
 *
 * La police Geist est incorporée en base64 : le rendu ne dépend d'aucun
 * accès réseau ni d'une police installée sur la machine. C'est une fonte
 * variable, donc les graisses sont réelles — pas de faux gras.
 * ------------------------------------------------------------------
 */

const fs = require('fs');
const path = require('path');

const ICI = __dirname;
const SORTIE = ICI;

// ---- Gabarit (identique au fichier Figma) ---------------------------------
const L = 1290, H = 2796;
const MARGE = 100;
const BLOC = { x: MARGE, y: 300, largeur: 1090, ecart: 30 };
const COQUE = { x: 189, y: 730, largeur: 912, hauteur: 1944, rayon: 96, bord: 14 };
const SLOT = { largeur: 884, hauteur: 1916, rayon: 82 };

const COULEURS = {
  fondHaut: '#0B3B2A',
  fondMilieu: '#0E7A5A',
  fondBas: '#12B886',
  coque: '#0B1A13',
  slot: '#E9F0EC',
  slotBord: '#A9BDB3',
  encreClaire: '#5F6D66',
};

// ---- Les dix écrans -------------------------------------------------------
const ECRANS = [
  { nom: 'Tableau de bord', titre: 'Toute votre association,\nd’un coup d’œil',
    sous: 'Adhérents, cotisations, factures et agenda réunis dans une seule application.' },
  { nom: 'Adhérents', titre: 'Vos adhérents,\ntoujours à jour',
    sous: 'Le fichier complet, consultable partout. Fini le tableur qui circule par e-mail.' },
  { nom: 'Cotisations', titre: 'Qui a payé,\nqui reste à relancer',
    sous: 'Campagnes de cotisation, paiements enregistrés, relances envoyées sans y penser.' },
  { nom: 'Facturation', titre: 'Devis et factures\nen trois gestes',
    sous: 'Créez, envoyez, suivez les impayés. Vos documents portent votre logo.' },
  { nom: 'Projets', titre: 'Vos projets,\nétape par étape',
    sous: 'Budget, participants, échéances. Chacun voit ce qu’il a à faire.' },
  { nom: 'Agenda', titre: 'Réunions, événements,\nassemblées générales',
    sous: 'Convocations, émargement numérique et comptes rendus au même endroit.' },
  { nom: 'Subventions', titre: 'Plus jamais une\ndate limite oubliée',
    sous: 'Assokit suit vos demandes de subvention, leurs dépôts et leurs bilans.' },
  { nom: 'Communication', titre: 'Un canal par projet,\nrien ne se perd',
    sous: 'Les discussions restent près du travail, pas noyées dans un groupe de messagerie.' },
  { nom: 'Codes QR', titre: 'Vos coordonnées\ndans leur téléphone',
    sous: 'Un code QR sur votre stand remplace les cartes de visite.' },
  { nom: 'Fait en France', titre: 'Hébergé en France,\npensé pour la loi 1901',
    sous: 'Mentions légales, reçus fiscaux, facturation conforme. Fait ici.' },
];

// ---- Symbole de marque, repris du kit (assets/brand/svg) ------------------
const SYMBOLE = `<svg viewBox="0 0 100 100" width="84" height="84" aria-hidden="true">
  <path fill="#FFFFFF" fill-rule="evenodd" d="M0 22A22 22 0 0 1 22 0H78A22 22 0 0 1 100 22V78A22 22 0 0 1 78 100H22A22 22 0 0 1 0 78Z M53.37 68.31a14.94 14.94 0 1 0 29.88 0a14.94 14.94 0 1 0 -29.88 0Z"/>
</svg>`;

const echap = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function page(ecran, fontB64) {
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8">
<style>
  @font-face {
    font-family: 'Geist';
    src: url(data:font/woff2;base64,${fontB64}) format('woff2');
    font-weight: 100 900;
    font-style: normal;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { width: ${L}px; height: ${H}px; overflow: hidden; }
  body {
    font-family: 'Geist', system-ui, sans-serif;
    background: linear-gradient(180deg,
      ${COULEURS.fondHaut} 0%, ${COULEURS.fondMilieu} 48%, ${COULEURS.fondBas} 100%);
    -webkit-font-smoothing: antialiased;
  }
  .marque { position: absolute; left: ${MARGE}px; top: 150px; line-height: 0; }
  .bloc   { position: absolute; left: ${BLOC.x}px; top: ${BLOC.y}px; width: ${BLOC.largeur}px; }
  .titre {
    font-weight: 700; font-size: 94px; line-height: 102px;
    letter-spacing: -0.025em; color: #fff; white-space: pre-line;
  }
  .sous {
    margin-top: ${BLOC.ecart}px;
    font-weight: 500; font-size: 42px; line-height: 58px;
    color: rgba(255,255,255,0.74);
  }
  .coque {
    position: absolute; left: ${COQUE.x}px; top: ${COQUE.y}px;
    width: ${COQUE.largeur}px; height: ${COQUE.hauteur}px;
    background: ${COULEURS.coque}; border-radius: ${COQUE.rayon}px;
    box-shadow: 0 40px 90px rgba(3, 23, 15, 0.55);
  }
  .slot {
    position: absolute; left: ${COQUE.bord}px; top: ${COQUE.bord}px;
    width: ${SLOT.largeur}px; height: ${SLOT.hauteur}px;
    background: ${COULEURS.slot}; border-radius: ${SLOT.rayon}px;
    display: flex; align-items: center; justify-content: center;
  }
  .slot svg.cadre { position: absolute; inset: 0; }
  .consigne { text-align: center; color: ${COULEURS.encreClaire}; position: relative; }
  .consigne .l1 { font-weight: 600; font-size: 38px; }
  .consigne .l2 {
    margin-top: 14px; font-weight: 400; font-size: 30px; line-height: 42px;
    color: rgba(95, 109, 102, 0.75); white-space: pre-line;
  }
</style></head><body>
  <div class="marque">${SYMBOLE}</div>
  <div class="bloc">
    <div class="titre">${echap(ecran.titre)}</div>
    <div class="sous">${echap(ecran.sous)}</div>
  </div>
  <div class="coque"><div class="slot">
    <svg class="cadre" width="${SLOT.largeur}" height="${SLOT.hauteur}">
      <rect x="1.5" y="1.5" width="${SLOT.largeur - 3}" height="${SLOT.hauteur - 3}"
            rx="${SLOT.rayon - 1.5}" ry="${SLOT.rayon - 1.5}"
            fill="none" stroke="${COULEURS.slotBord}" stroke-width="3" stroke-dasharray="18 14"/>
    </svg>
    <div class="consigne">
      <div class="l1">Déposez ici votre capture d’écran</div>
      <div class="l2">1290 × 2796 px
iPhone 15/16 Pro Max</div>
    </div>
  </div></div>
</body></html>`;
}

(async () => {
  const fontFile = path.join(ICI, 'geist-variable.woff2');
  if (!fs.existsSync(fontFile)) {
    console.error('Police absente : ' + fontFile);
    process.exit(1);
  }
  const fontB64 = fs.readFileSync(fontFile).toString('base64');

  const { chromium } = require(process.env.PW_MODULE || 'playwright');
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
