/**
 * gabarit.js — Le gabarit des planches App Store, en un seul endroit.
 * ------------------------------------------------------------------
 * Deux scripts s'en servent, et c'est la raison d'être de ce fichier :
 *
 *   generer-maquettes.js → planches VIDES, à remplir à la main (Figma).
 *   generer-captures.js  → planches REMPLIES, capture de l'app incluse.
 *
 * Tant qu'ils partagent ces cotes, les deux séries restent superposables.
 * Les valeurs reproduisent le fichier Figma « Assokit — Captures App Store » :
 * modifier l'un sans l'autre les fera diverger.
 * ------------------------------------------------------------------
 */

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

/**
 * Les dix écrans.
 *
 * `titre` / `sous` sont le discours de vente ; `nav` dit à
 * generer-captures.js comment atteindre l'écran dans l'app :
 *
 *   entrer    — faut-il passer l'écran d'accueil public (défaut : oui).
 *   taps      — suite de libellés à toucher, dans l'ordre.
 *   barre     — teinte de la zone de statut : 'accueil' (vert plein),
 *               'blanche' (en-tête clair) ou 'fondu' (l'app peint elle-même
 *               sous la barre : on la superpose).
 *   attente   — millisecondes à laisser au dernier écran pour se remplir.
 *
 * Chaque titre a été mesuré dans le navigateur : tous tiennent en deux lignes
 * sans déborder de la colonne de 1090 px.
 */
const ECRANS = [
  { nom: 'Tableau de bord', titre: 'Toute votre association,\nd’un coup d’œil',
    sous: 'Adhérents, cotisations, factures et agenda réunis dans une seule application.',
    nav: { taps: [], barre: 'accueil' } },

  { nom: 'Adhérents', titre: 'Vos adhérents,\ntoujours à jour',
    sous: 'Le fichier complet, consultable partout. Fini le tableur qui circule par e-mail.',
    nav: { taps: ['Membres'], barre: 'blanche' } },

  { nom: 'Cotisations', titre: 'Qui a payé,\nqui reste à relancer',
    sous: 'Campagnes de cotisation, paiements enregistrés, relances envoyées sans y penser.',
    nav: { taps: ['Plus', 'Cotisations'], barre: 'blanche' } },

  { nom: 'Facturation', titre: 'Devis et factures\nen trois gestes',
    sous: 'Créez, envoyez, suivez les impayés. Vos documents portent votre logo.',
    nav: { taps: ['Plus', 'Factures'], barre: 'blanche' } },

  { nom: 'Projets', titre: 'Vos projets,\nétape par étape',
    sous: 'Budget, participants, échéances. Chacun voit ce qu’il a à faire.',
    nav: { taps: ['Projets', 'Festival de quartier 2026'], barre: 'blanche', attente: 1600 } },

  { nom: 'Agenda', titre: 'Réunions, événements,\nassemblées générales',
    sous: 'Convocations, émargement numérique et comptes rendus au même endroit.',
    nav: { taps: ['Plus', 'Agenda'], barre: 'blanche' } },

  { nom: 'Subventions', titre: 'Plus jamais une\ndate limite oubliée',
    sous: 'Assokit suit vos demandes de subvention, leurs dépôts et leurs bilans.',
    nav: { taps: ['Plus', 'Subventions'], barre: 'blanche' } },

  { nom: 'Communication', titre: 'Un canal par projet,\nrien ne se perd',
    sous: 'Les discussions restent près du travail, pas noyées dans un groupe de messagerie.',
    nav: { taps: ['Plus', 'Messages'], barre: 'blanche' } },

  { nom: 'Tous vos outils', titre: 'Tout l’outillage,\nà une touche',
    sous: 'Adhérents, finances, communication, assemblées : le menu réunit chaque module.',
    nav: { taps: ['Plus'], barre: 'blanche' } },

  { nom: 'Fait en France', titre: 'Hébergé en France,\npensé pour la loi 1901',
    sous: 'Mentions légales, reçus fiscaux, facturation conforme. Fait ici.',
    nav: { entrer: false, taps: [], barre: 'fondu' } },
];

// ---- Symbole de marque, repris du kit (assets/brand/svg) ------------------
const SYMBOLE = `<svg viewBox="0 0 100 100" width="84" height="84" aria-hidden="true">
  <path fill="#FFFFFF" fill-rule="evenodd" d="M0 22A22 22 0 0 1 22 0H78A22 22 0 0 1 100 22V78A22 22 0 0 1 78 100H22A22 22 0 0 1 0 78Z M53.37 68.31a14.94 14.94 0 1 0 29.88 0a14.94 14.94 0 1 0 -29.88 0Z"/>
</svg>`;

const echap = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/**
 * Rend une planche.
 *
 * @param {object} ecran   entrée d'ECRANS
 * @param {string} fontB64 Geist variable, en base64
 * @param {string=} capture  image de l'écran (data:image/png;base64,…).
 *                           Absente → emplacement vide en pointillés.
 */
function page(ecran, fontB64, capture) {
  const contenuSlot = capture
    ? `<img class="capture" src="${capture}" alt="">`
    : `<svg class="cadre" width="${SLOT.largeur}" height="${SLOT.hauteur}">
      <rect x="1.5" y="1.5" width="${SLOT.largeur - 3}" height="${SLOT.hauteur - 3}"
            rx="${SLOT.rayon - 1.5}" ry="${SLOT.rayon - 1.5}"
            fill="none" stroke="${COULEURS.slotBord}" stroke-width="3" stroke-dasharray="18 14"/>
    </svg>
    <div class="consigne">
      <div class="l1">Déposez ici votre capture d’écran</div>
      <div class="l2">1290 × 2796 px
iPhone 15/16 Pro Max</div>
    </div>`;

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
    overflow: hidden;
    display: flex; align-items: center; justify-content: center;
  }
  /* La capture arrive en 1290 × 2796 (densité 3) et se pose dans les
     884 × 1916 du slot : même rapport, donc aucune déformation. */
  .capture { width: 100%; height: 100%; object-fit: cover; display: block; }
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
  <div class="coque"><div class="slot">${contenuSlot}</div></div>
</body></html>`;
}

/** Playwright : installé globalement, ou dans mobile/node_modules. */
function chargerPlaywright() {
  if (process.env.PW_MODULE) return require(process.env.PW_MODULE);
  try {
    return require('playwright');
  } catch (e) {
    return require(require('path').resolve(__dirname, '../../mobile/node_modules/playwright'));
  }
}

module.exports = { L, H, MARGE, BLOC, COQUE, SLOT, COULEURS, ECRANS, SYMBOLE, page, chargerPlaywright };
