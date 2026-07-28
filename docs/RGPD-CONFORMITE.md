# 🔐 Conformité RGPD — Assokit

Document de conformité (registre des traitements, durées de conservation, droits,
DPO). **À faire valider par un juriste / DPO.** Base : RGPD (UE 2016/679) + Loi
Informatique et Libertés.

---

## 1. Registre des activités de traitement (RGPD art. 30)

Responsable de traitement : **[Raison sociale Assokit]**, [adresse], [SIREN].
Contact / DPO : **[à désigner]** — dpo@assokit.fr (à créer).

| # | Traitement | Finalité | Base légale | Personnes | Données | Destinataires | Durée |
|---|---|---|---|---|---|---|---|
| 1 | **Comptes utilisateurs** | Fournir le service, authentification | Exécution du contrat | Utilisateurs (dirigeants asso/TPE) | Nom, email, téléphone, mot de passe (haché), logs de connexion | Interne, hébergeur (O2switch) | Durée du contrat + 3 ans |
| 2 | **Gestion des adhérents** (pour le compte des assos clientes) | Permettre à l'asso de gérer ses membres | Sous-traitance (asso = responsable) | Adhérents des assos | Identité, coordonnées, cotisations | L'association cliente | Selon l'asso, défaut 3 ans après fin d'adhésion |
| 3 | **Facturation / comptabilité** | Émettre et archiver les factures | Obligation légale (Code de commerce) | Clients des utilisateurs, utilisateurs | Identité, adresse, SIREN, montants | Interne, expert-comptable, DGFiP | **10 ans** (obligation comptable) |
| 4 | **Paiements en ligne** | Encaisser les abonnements | Exécution du contrat | Utilisateurs payants | Données de paiement (via **Stripe**, non stockées chez nous) | Stripe (sous-traitant) | Selon Stripe / 13 mois pour preuves |
| 5 | **Prospection commerciale** | Démarcher de nouveaux clients B2B | Intérêt légitime (opt-out) | Prospects pro (assos/TPE) | Email pro, nom structure, ville | Interne, Resend (envoi) | 3 ans sans contact / jusqu'à opposition |
| 6 | **Emails transactionnels & support** | Communiquer avec les utilisateurs | Exécution du contrat | Utilisateurs | Email, contenu des messages | Resend (sous-traitant) | Durée du contrat + 1 an |
| 7 | **Notifications push (mobile)** | Alerter dans l'app | Consentement | Utilisateurs de l'app | Jeton d'appareil | Expo/Apple/Google | Jusqu'à désinstallation |
| 8 | **Journaux techniques / sécurité** | Sécurité, débogage | Intérêt légitime | Utilisateurs, visiteurs | IP, user-agent, horodatage, actions | Interne | 12 mois |

> Complète les `[...]` avec les infos légales d'Assokit et fais relire par un juriste.

---

## 2. Durées de conservation (politique)

| Donnée | Durée active | Puis |
|---|---|---|
| Compte utilisateur | Durée du contrat | Anonymisation à J+3 ans d'inactivité |
| Factures / pièces comptables | 10 ans | Suppression |
| Prospects non convertis | 3 ans sans interaction | Suppression |
| Prospects désinscrits | — | Conserver l'email en **liste d'exclusion** (hachée) pour ne plus jamais recontacter |
| Logs techniques | 12 mois | Suppression |
| Événements de tracking prospection | 24 mois | Suppression |
| Données de compte supprimé (RGPD) | Immédiat | Anonymisation (déjà en place : `app-delete-account.php`) |

Mécanisme technique : voir `cron-rgpd-purge.php` (**désactivé par défaut** ; à réviser
puis activer après validation juridique).

---

## 3. Droits des personnes (art. 15-22)

| Droit | Comment il est assuré |
|---|---|
| **Accès / portabilité** | Export des données depuis l'app + sur demande à dpo@assokit.fr |
| **Rectification** | Modifiable dans les paramètres du compte |
| **Effacement** | Bouton « Supprimer mon compte (RGPD) » in-app (déjà implémenté) |
| **Opposition (prospection)** | Lien de désinscription dans chaque email (déjà en place) |
| **Limitation** | Sur demande, gel du compte |

Délai de réponse : **1 mois** maximum.

---

## 4. Sous-traitants (à contractualiser — art. 28)

Signer un **accord de traitement des données (DPA)** avec chacun :
- **O2switch** (hébergement — France 🇫🇷)
- **Stripe** (paiement)
- **Resend** (emails)
- **Expo / Apple / Google** (notifications push, stores)
- **Anthropic** (si IA Claude utilisée sur des données personnelles → vérifier le DPA)

Tenir à jour la **liste des sous-traitants** et vérifier leurs garanties (localisation, DPA, transferts hors UE avec clauses types).

---

## 5. À formaliser (checklist)

- [ ] Compléter ce registre avec les infos légales d'Assokit
- [ ] **Désigner un DPO** (interne ou externe) + créer **dpo@assokit.fr**
- [ ] Signer les **DPA** avec chaque sous-traitant
- [ ] Publier / mettre à jour la **politique de confidentialité** (`/confidentialite`)
- [ ] Bandeau **cookies** conforme (consentement) si traceurs non essentiels
- [ ] Procédure de **notification de violation** (72 h à la CNIL)
- [ ] Analyse d'impact (**AIPD/DPIA**) si traitement à risque élevé
- [ ] Registre tenu à jour et disponible sur demande de la CNIL

---

⚠️ Ce document est une **base de travail sérieuse**, pas un avis juridique. Fais-le
**valider et compléter par un DPO / avocat spécialisé** avant tout dépôt officiel.
