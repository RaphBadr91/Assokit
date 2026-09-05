/**
 * Configuration Metro.
 *
 * Sur la plateforme « web » uniquement (prévisualisation dans le navigateur),
 * certains modules natifs sont remplacés par des doublures situées dans
 * `preview/`. Les builds iOS et Android ne passent jamais par cette branche :
 * la substitution est conditionnée à `platform === 'web'`.
 */
const { getDefaultConfig } = require('expo/metro-config');
const path = require('path');

const config = getDefaultConfig(__dirname);

const DOUBLURES_WEB = {
  'react-native-webview': path.resolve(__dirname, 'preview/react-native-webview.js'),
};

config.resolver.resolveRequest = (context, moduleName, platform) => {
  if (platform === 'web' && DOUBLURES_WEB[moduleName]) {
    return { type: 'sourceFile', filePath: DOUBLURES_WEB[moduleName] };
  }
  return context.resolveRequest(context, moduleName, platform);
};

module.exports = config;
