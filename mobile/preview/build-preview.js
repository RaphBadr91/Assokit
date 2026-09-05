/**
 * Assemble la prévisualisation web en UN seul fichier HTML autonome.
 *
 *   node preview/build-preview.js
 *
 * Prérequis : `npx expo export --platform web --output-dir dist-web`
 *
 * Le fichier produit n'a aucune dépendance réseau : le bundle JavaScript et la
 * police d'icônes Ionicons y sont intégrés. Il peut donc être ouvert depuis
 * n'importe où, y compris hors ligne.
 */
const fs = require('fs');
const path = require('path');

const RACINE = path.resolve(__dirname, '..');
const DIST = path.join(RACINE, 'dist-web');
const SORTIE = process.argv[2] || path.join(RACINE, 'preview', 'assokit-preview.html');

function trouverBundle() {
  const dir = path.join(DIST, '_expo/static/js/web');
  const f = fs.readdirSync(dir).find((n) => n.endsWith('.js'));
  if (!f) throw new Error('Bundle introuvable. Lancez `npx expo export --platform web --output-dir dist-web`.');
  return path.join(dir, f);
}

function trouverPolice() {
  const dir = path.join(DIST, 'assets/node_modules/@expo/vector-icons/build/vendor/react-native-vector-icons/Fonts');
  const f = fs.readdirSync(dir).find((n) => n.startsWith('Ionicons.'));
  if (!f) throw new Error('Police Ionicons introuvable dans l’export.');
  return { chemin: path.join(dir, f), relatif: 'assets/node_modules/@expo/vector-icons/build/vendor/react-native-vector-icons/Fonts/' + f };
}

const bundlePath = trouverBundle();
const police = trouverPolice();

let bundle = fs.readFileSync(bundlePath, 'utf8');
const dataUri = 'data:font/ttf;base64,' + fs.readFileSync(police.chemin).toString('base64');

// Le bundle référence la police par son chemin relatif : on y substitue la
// version intégrée, sinon l'aperçu afficherait des carrés à la place des icônes.
const avant = bundle.length;
bundle = bundle.split(police.relatif).join(dataUri);
if (bundle.length === avant) {
  throw new Error('Le chemin de la police n’a pas été trouvé dans le bundle : ' + police.relatif);
}

/* Le fichier produit est ouvert aussi bien via https:// que via file://. Sans
   déclaration de charset, un navigateur en file:// retombe sur windows-1252 et
   massacre les accents. On échappe donc tout caractère non-ASCII de la coque en
   entités numériques ; le bundle, lui, est laissé intact (c'est du JavaScript,
   les entités n'y seraient pas décodées — il n'utilise que des échappements \\u). */
function echapperNonAscii(s) {
  return Array.from(s).map((c) => (c.codePointAt(0) > 127 ? '&#' + c.codePointAt(0) + ';' : c)).join('');
}


const coque = `<title>Assokit en main</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style>
  /* Palette : neutres teintés de vert plutôt que gris purs, pour que le cadre
     appartienne au même monde que l'app qu'il présente. */
  :root {
    --ground: #E9EFEC; --panel: #FFFFFF; --ink: #0B1A13; --mute: #5F6D66;
    --hair: #D7E2DC; --accent: #047857; --accent-soft: #DCF2E8;
    --w: 390px; --h: 844px; --radius: 44px; --safe-top: 47px; --zoom: 1;
    --display: "Bricolage Grotesque", Georgia, serif;
    --body: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    --mono: "IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, monospace;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      --ground: #0A120F; --panel: #121C18; --ink: #E8EFEB; --mute: #93A29B;
      --hair: #202C27; --accent: #34D399; --accent-soft: #10251C;
    }
  }
  :root[data-theme="dark"] {
    --ground: #0A120F; --panel: #121C18; --ink: #E8EFEB; --mute: #93A29B;
    --hair: #202C27; --accent: #34D399; --accent-soft: #10251C;
  }

  body { background: var(--ground); color: var(--ink); font-family: var(--body); font-size: 14px; line-height: 1.55; }
  .wrap { max-width: 1180px; margin: 0 auto; padding: 40px 24px 56px;
          display: grid; grid-template-columns: minmax(280px, 360px) 1fr; gap: 56px; align-items: start; }
  @media (max-width: 900px) { .wrap { grid-template-columns: 1fr; gap: 32px; padding: 28px 18px 40px; } }

  .eyebrow { font-family: var(--mono); font-size: 11px; letter-spacing: .14em; text-transform: uppercase;
             color: var(--accent); margin: 0 0 10px; }
  h1 { font-family: var(--display); font-weight: 700; font-size: clamp(30px, 4vw, 40px); line-height: 1.05;
       letter-spacing: -0.02em; margin: 0 0 14px; text-wrap: balance; }
  .lede { color: var(--mute); margin: 0 0 28px; max-width: 46ch; }

  .controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 32px; }
  .switch { display: inline-flex; background: var(--panel); border: 1px solid var(--hair); border-radius: 10px; padding: 3px; }
  .switch button { appearance: none; border: 0; background: transparent; color: var(--mute); cursor: pointer;
                   font-family: var(--body); font-size: 13px; font-weight: 600; padding: 7px 14px; border-radius: 7px; }
  .switch button[aria-pressed="true"] { background: var(--accent); color: #fff; }
  .switch button:focus-visible, .switch button:hover { color: var(--ink); }
  .switch button[aria-pressed="true"]:hover { color: #fff; }
  .readout { font-family: var(--mono); font-size: 12px; color: var(--mute); font-variant-numeric: tabular-nums; }

  /* Légende de fidélité : deux registres, parce que la distinction entre ce qui
     est réel et ce qui est simulé est l'information la plus utile de la page. */
  .key { border-top: 1px solid var(--hair); padding-top: 22px; }
  .key h2 { font-family: var(--mono); font-size: 11px; letter-spacing: .12em; text-transform: uppercase;
            color: var(--mute); font-weight: 500; margin: 0 0 12px; }
  .key + .key { margin-top: 26px; }
  .key ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 11px; }
  .key li { display: grid; grid-template-columns: 14px 1fr; gap: 10px; font-size: 13px; color: var(--mute); }
  .key li b { color: var(--ink); font-weight: 600; }
  .mark { width: 14px; height: 14px; border-radius: 4px; margin-top: 4px; }
  .mark.real { background: var(--accent-soft); border: 1.5px solid var(--accent); }
  .mark.sim { background: transparent; border: 1.5px dashed var(--hair); }

  .stage { display: flex; justify-content: center; }
  .device { transform: scale(var(--zoom)); transform-origin: top center; }
  .bezel { position: relative; width: calc(var(--w) + 24px); border-radius: var(--radius); padding: 12px;
           background: #14201B; box-shadow: 0 34px 64px -28px rgba(6, 40, 28, .6), 0 0 0 1px rgba(255,255,255,.07) inset; }
  .screen { position: relative; width: var(--w); height: var(--h); overflow: hidden;
            border-radius: calc(var(--radius) - 12px); background: #0B3B2A; }
  .statusbar { position: absolute; top: 0; left: 0; right: 0; height: var(--safe-top); z-index: 4;
               display: flex; align-items: center; justify-content: space-between;
               padding: 0 26px; color: #fff; font-size: 13px; font-weight: 600; pointer-events: none;
               font-family: var(--body); font-variant-numeric: tabular-nums; }
  .glyphs { display: flex; gap: 7px; align-items: center; opacity: .95; }
  .sig { display: flex; align-items: flex-end; gap: 2px; height: 11px; }
  .sig i { width: 3px; background: #fff; border-radius: 1px; }
  .sig i:nth-child(1) { height: 4px; } .sig i:nth-child(2) { height: 6px; }
  .sig i:nth-child(3) { height: 8px; } .sig i:nth-child(4) { height: 11px; }
  .bat { width: 22px; height: 11px; border: 1.4px solid #fff; border-radius: 3px; position: relative; }
  .bat::after { content: ''; position: absolute; inset: 1.6px; right: 6px; background: #fff; border-radius: 1px; }
  .bat::before { content: ''; position: absolute; right: -3px; top: 3px; width: 2px; height: 5px; background: #fff; border-radius: 0 2px 2px 0; }
  .notch { position: absolute; top: 0; left: 50%; transform: translateX(-50%); width: 124px; height: 30px;
           background: #14201B; border-radius: 0 0 18px 18px; z-index: 6; pointer-events: none; }
  .punch { position: absolute; top: 8px; left: 50%; transform: translateX(-50%); width: 11px; height: 11px;
           background: #14201B; border-radius: 50%; z-index: 6; pointer-events: none; }
  .hidden { display: none !important; }

  /* Le bundle Expo monte l'app dans #root : on le cale sous la zone de statut,
     comme le ferait la SafeAreaView sur l'appareil. */
  #root { position: absolute; top: var(--safe-top); left: 0; right: 0; bottom: 0;
          display: flex; flex-direction: column; }

  /* Police d'icônes intégrée. Le bundle en déclare une copie de son côté, mais
     il la charge aussi via son chargeur d'assets, qui échoue hors ligne : cette
     déclaration garantit que les icônes s'affichent quoi qu'il arrive. */
  @font-face { font-family: 'Ionicons'; src: url(${dataUri}) format('truetype'); font-display: block; }
</style>

<div class="wrap">
  <div class="rail">
    <p class="eyebrow">Application mobile</p>
    <h1>Assokit en main</h1>
    <p class="lede">
      Les écrans natifs de l’app, compilés pour le navigateur et remplis de données
      de démonstration. Naviguez, ouvrez une fiche, lancez une création : c’est le
      code réel qui tourne.
    </p>

    <div class="controls">
      <div class="switch" role="group" aria-label="Appareil">
        <button id="btn-ios" aria-pressed="true">iPhone 14</button>
        <button id="btn-android" aria-pressed="false">Pixel 7</button>
      </div>
      <span class="readout" id="readout">390 &#215; 844</span>
    </div>

    <section class="key">
      <h2>Fidèle à l’app</h2>
      <ul>
        <li><span class="mark real"></span><span><b>La mise en page et le style</b> — grilles, rayons, ombres, couleurs et typographie viennent du code livré.</span></li>
        <li><span class="mark real"></span><span><b>Les animations</b> — cascade d’ouverture, compteurs, appuis, barre de progression.</span></li>
        <li><span class="mark real"></span><span><b>La navigation</b> — onglets, fiches détail, feuille de création, retours.</span></li>
      </ul>
    </section>

    <section class="key">
      <h2>Simulé pour l’aperçu</h2>
      <ul>
        <li><span class="mark sim"></span><span><b>Les données</b> — inventées et non enregistrées : aucune connexion au serveur.</span></li>
        <li><span class="mark sim"></span><span><b>Le rendu système</b> — le moteur est celui du navigateur, pas iOS ni Android : flou, ombres natives et retour haptique diffèrent.</span></li>
        <li><span class="mark sim"></span><span><b>La barre de statut</b> — dessinée par le cadre, elle reste verte partout alors que l’app la repasse au blanc hors de l’accueil.</span></li>
        <li><span class="mark sim"></span><span><b>Les pages du site</b> — affichées en WebView dans l’app, remplacées ici par un message.</span></li>
      </ul>
    </section>
  </div>

  <div class="stage">
    <div class="device" id="device">
      <div class="bezel">
        <div class="screen">
          <div class="statusbar">
            <span id="clock">9:41</span>
            <span class="glyphs">
              <span class="sig"><i></i><i></i><i></i><i></i></span>
              <span class="bat"></span>
            </span>
          </div>
          <div class="notch" id="notch"></div>
          <div class="punch hidden" id="punch"></div>
          <div id="root"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var TAILLES = {
    ios: { w: 390, h: 844, radius: 44, safe: 47 },
    android: { w: 412, h: 915, radius: 30, safe: 28 },
  };
  var courant = 'ios';
  var bIos = document.getElementById('btn-ios');
  var bAnd = document.getElementById('btn-android');
  var notch = document.getElementById('notch');
  var punch = document.getElementById('punch');
  var readout = document.getElementById('readout');
  var horloge = document.getElementById('clock');

  // Le téléphone fait plus de 900 px de haut : on le réduit pour qu'il tienne
  // dans la fenêtre, sinon la page s'ouvrirait sur un écran coupé.
  function ajusterZoom() {
    var t = TAILLES[courant];
    var dispo = window.innerHeight - 96;
    var z = Math.min(1, dispo / (t.h + 24));
    if (window.innerWidth <= 900) z = Math.min(z, (window.innerWidth - 44) / (t.w + 24));
    document.documentElement.style.setProperty('--zoom', Math.max(0.45, z).toFixed(3));
  }

  function appliquer(nom) {
    courant = nom;
    var t = TAILLES[nom];
    var r = document.documentElement.style;
    r.setProperty('--w', t.w + 'px');
    r.setProperty('--h', t.h + 'px');
    r.setProperty('--radius', t.radius + 'px');
    r.setProperty('--safe-top', t.safe + 'px');
    bIos.setAttribute('aria-pressed', String(nom === 'ios'));
    bAnd.setAttribute('aria-pressed', String(nom === 'android'));
    notch.classList.toggle('hidden', nom !== 'ios');
    punch.classList.toggle('hidden', nom !== 'android');
    readout.textContent = t.w + ' \\u00D7 ' + t.h;
    ajusterZoom();
    // React Native Web recalcule ses dimensions sur resize : sans cet événement,
    // la mise en page resterait figée sur la taille précédente.
    window.dispatchEvent(new Event('resize'));
  }

  function majHeure() {
    var d = new Date();
    horloge.textContent = d.getHours() + ':' + String(d.getMinutes()).padStart(2, '0');
  }

  bIos.addEventListener('click', function () { appliquer('ios'); });
  bAnd.addEventListener('click', function () { appliquer('android'); });
  window.addEventListener('resize', ajusterZoom);
  majHeure();
  setInterval(majHeure, 30000);
  appliquer('ios');
})();
</script>
`;

const html = echapperNonAscii(coque) + '\n<script>\n' + bundle + '\n</script>\n';

fs.writeFileSync(SORTIE, html, 'utf8');
const mo = (Buffer.byteLength(html) / 1048576).toFixed(2);
console.log('Aperçu écrit : ' + SORTIE + ' (' + mo + ' Mo)');
