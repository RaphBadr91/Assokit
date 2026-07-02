# 🌙 Cron de reset DEMO automatique chaque nuit

## 🎯 Ce que ça fait

À **minuit chaque nuit**, le cron va :

1. ✅ Vérifier que les 6 fichiers SQL existent dans `/demo-sql/`
2. 📥 Les exécuter **dans l'ordre** (1 → 2 → 3 → 4 → 5 → 6)
3. 📊 Logger le détail (combien de requêtes par fichier, erreurs)
4. ✔️ Faire des vérifs finales (orgs, projets, factures...)

→ Le matin, **toutes les démos sont fraîches** avec leurs données complètes.

---

## 🟢 Installation en 4 étapes

### Étape 1 : Créer le dossier `demo-sql/` sur le serveur

Via cPanel → Gestionnaire de fichiers → `public_html/`
→ Bouton **"+ Dossier"** → nom : `demo-sql`

Tu devrais avoir : `public_html/demo-sql/`

### Étape 2 : Uploader les 6 fichiers SQL dans ce dossier

Tu dois mettre **exactement** ces 6 fichiers dans `public_html/demo-sql/` :

| # | Fichier | Source | Taille |
|---|---|---|---|
| 1 | `01-demo-seed-v2.sql` | sprint3-paquet1bis.zip | ~50 Ko |
| 2 | `02-demo-data-massive.sql` | sprint3-paquet2.zip | ~1.1 Mo |
| 3 | `03-demo-enrichissement.sql` | sprint3-enrichissement.zip | ~74 Ko |
| 4 | `04-demo-communications.sql` | sprint4-communications.zip | ~92 Ko |
| 5 | `05-demo-bilans-ia.sql` | sprint5-bilans-ia.zip | ~175 Ko |
| 6 | `06-demo-project-invoices.sql` | sprint6-factures-projets.zip | ~94 Ko |

⚠️ **Les noms doivent être EXACTS** (sinon le cron ne les trouvera pas).

### Étape 3 : Remplacer `cron-demo-reset.php`

Upload **`cron-demo-reset.php`** (ce ZIP) → écrase l'ancienne version dans `public_html/`

### Étape 4 : Tester avant minuit

Va sur :
```
https://assokit.fr/cron-demo-reset.php?token=AssokitDemoReset2026Secret
```

Tu devrais voir le log s'afficher en temps réel :
```
[2026-05-06 11:30:01] ===== RESET DEMO START =====
[2026-05-06 11:30:01] ✅ Tous les fichiers SQL trouvés (6 fichiers)
[2026-05-06 11:30:02] ───────────────────────────────────────
[2026-05-06 11:30:02] 📄 Fichier 1/6 : 01-demo-seed-v2.sql
[2026-05-06 11:30:02]    Taille : 48.2 Ko
[2026-05-06 11:30:02]    Nombre de requêtes : 47
[2026-05-06 11:30:03]    ✅ 47 requêtes OK · 0 erreurs
... etc pour les 6 fichiers
[2026-05-06 11:30:45] 📊 BILAN FINAL
[2026-05-06 11:30:45]    Total : 3127 requêtes exécutées · 0 erreurs
[2026-05-06 11:30:45]    🏢 Organisations DEMO : 4
[2026-05-06 11:30:45]    👥 Comptes DEMO : 425
[2026-05-06 11:30:45]    📂 Projets DEMO : 170
[2026-05-06 11:30:45]    💰 Factures DEMO : 630
[2026-05-06 11:30:45]    🧾 Factures projet DEMO : 1380
[2026-05-06 11:30:45]    🤖 Documents IA générés : 510
[2026-05-06 11:30:45] ===== RESET DEMO END =====
```

✅ Si tu vois ce log, **le cron est prêt**.

---

## ⏰ Configurer le cron O2switch

### Si pas encore fait

cPanel → **Cron Jobs** (ou "Tâches Cron")

Ajoute une tâche :
- **Minute** : `0`
- **Heure** : `0`
- **Jour mois** : `*`
- **Mois** : `*`
- **Jour semaine** : `*`
- **Commande** : 
  ```
  /usr/local/bin/php /home/pura7044/public_html/cron-demo-reset.php
  ```

→ Le cron se lance **chaque nuit à minuit**.

### Si tu préfères tester en URL (sans le mode CLI)

Tu peux aussi mettre cette commande comme cron :
```
curl -s "https://assokit.fr/cron-demo-reset.php?token=AssokitDemoReset2026Secret" > /dev/null
```

---

## 📋 Vérifier les logs

Le cron écrit dans `public_html/cron-demo-reset.log`.

Pour voir les derniers logs :
```bash
tail -100 ~/public_html/cron-demo-reset.log
```

Ou via cPanel → ouvre le fichier `cron-demo-reset.log` directement.

---

## 🔧 Modifications par rapport à l'ancienne version

| Avant | Après |
|---|---|
| Charge 1 seul fichier `01-demo-seed.sql` | Charge **6 fichiers** dans l'ordre |
| Logs basiques | Logs détaillés par fichier (taille, requêtes, erreurs) |
| Vérif uniquement orgs+users | Vérif orgs, users, projets, factures, factures projet, IA |
| Hard-codé | Liste configurable au début du fichier |

---

## ⚠️ Sécurité importante

Le token actuel est `AssokitDemoReset2026Secret`. **Garde-le secret** :
- Ne le mets PAS sur Github
- Ne le mets PAS dans un repo public
- N'envoie PAS l'URL avec le token à des inconnus

Quelqu'un avec le token peut **réinitialiser ta démo**.

---

## 🆘 Si ça plante

Le test manuel (Étape 4) te dit exactement ce qui plante. Les erreurs typiques :

### "SQL files missing"
→ Vérifie que tes 6 fichiers sont bien dans `public_html/demo-sql/` avec les **noms exacts**.

### "Erreur requête #X"
→ Le SQL a un problème. Le log te dit quelle ligne, dans quel fichier.

### "Forbidden"
→ Le token est mauvais. Vérifie que tu utilises bien `AssokitDemoReset2026Secret`.

### Le cron ne se déclenche pas à minuit
→ Vérifie dans cPanel → Cron Jobs que la tâche est bien créée.
→ Vérifie le log d'erreur cPanel pour voir si le cron s'exécute mais plante.

---

## 🎯 Recap action

1. ✅ Créer dossier `public_html/demo-sql/`
2. ✅ Upload les 6 SQL dans ce dossier
3. ✅ Remplacer `cron-demo-reset.php` (cette nouvelle version)
4. ✅ Tester via URL avec le token
5. ✅ Vérifier que le cron O2switch est configuré

**Une fois fait → ta démo est immortelle 🛡️**
