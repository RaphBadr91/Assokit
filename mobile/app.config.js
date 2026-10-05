// Complète app.json au moment de la compilation.
//
// Notifications push Android : Google les fait transiter par Firebase (FCM),
// qui exige le fichier google-services.json du projet Firebase. Tant qu'il
// n'est pas dans ce dossier, le build Android se fait quand même — seules les
// notifications push Android restent inactives (iOS n'est pas concerné).
const fs = require('fs');
const path = require('path');

module.exports = ({ config }) => {
  const gs = path.join(__dirname, 'google-services.json');
  if (fs.existsSync(gs)) {
    config.android = { ...config.android, googleServicesFile: './google-services.json' };
  }
  return config;
};
