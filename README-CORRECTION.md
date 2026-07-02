# 🔧 CORRECTION — Sprint 3 Paquet 1 BIS

## ⚠️ Ce que ce package corrige

Tu m'as signalé que les prix étaient faux. Voici la **correction officielle** :

### ❌ Avant (erreur)
- Plan "ESSENTIEL" = Gratuit
- Plan "ASSOCIATION" = 19 €/mois
- Plan "ORGANISATION" = 49 €/mois

### ✅ Maintenant (correct)
| Slug BDD | Affichage | Prix |
|---|---|---|
| `essentiel` | **DÉMARRAGE** | Gratuit |
| `association` | **ASSOKIT** | **49,99 €/mois** |
| `organisation` | **SUR-MESURE** | **Sur devis** (full max) |

## 📊 Volumes corrigés des 4 organisations DEMO

| Org | Plan | Membres | Projets | Factures |
|---|---|---|---|---|
| 🤝 Solidarité Évry | **ASSOKIT** (49,99 €) | 50 | 20 | 40 |
| 💜 Espoir Corbeil | **ASSOKIT** (49,99 €) | 120 | 50 | 120 |
| 🏛️ Fraternité Paris 11e | **SUR-MESURE** ⭐ | 250 | 80 | 300 |
| 🏢 Atelier Studio Paris | **ASSOKIT** (49,99 €) | 4 | 20 | 170 |

## 🚀 Installation (2 min)

### 1. Re-import du SQL

cPanel → phpMyAdmin → base `pura7044_assokit`
- Onglet **Importer** → choisir `01-demo-seed-v2.sql` → Exécuter

Le script supprime les anciennes orgs DEMO et les recrée avec les bons prix.

### 2. Remplacer le sélecteur PHP

cPanel → Gestionnaire de fichiers → `public_html/`
- Upload + Extraire `sprint3-paquet1bis.zip`
- Remplace `demo-selector.php`

### 3. Test

`https://assokit.fr/connexion` → demo@assokit.fr / Demo2026!
→ Tu vois maintenant :
- 3 cards "ASSOKIT 49,99 €/mois"
- 1 card "SUR-MESURE Sur devis" (Paris 11e avec badge ⭐ FULL MAX)

## 📦 Prochain Paquet 2

Une fois ça validé, je te livre le **REMPLISSAGE MASSIF** :
- 424 adhérents avec noms français réalistes
- 170 projets dans dossiers thématiques
- 630 factures + lignes détaillées
- Devis en signature
- Clients donateurs/mécènes
- Channels avec messages
- Événements (assemblées, sorties, formations)

⏱ Estimation : ~1h pour générer tout ça proprement

