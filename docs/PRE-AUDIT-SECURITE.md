# 🛡️ Dossier de pré-audit sécurité (PASSI / ISO 27001)

But : arriver **prêt** devant l'auditeur externe (PASSI qualifié ANSSI ou certificateur
ISO 27001) exigé pour l'agrément PDP. Ce document liste les **contrôles** et les
**preuves** à réunir. Il **ne remplace pas** l'audit — il le prépare.

Statut : ✅ en place · 🟡 partiel · 🟠 à faire

---

## 1. Gouvernance & organisation

| Contrôle | Statut | Preuve à fournir |
|---|---|---|
| Politique de sécurité (PSSI) écrite | 🟠 | Document PSSI signé par la direction |
| Responsable sécurité désigné (RSSI/DPO) | 🟠 | Lettre de désignation |
| Inventaire des actifs (serveurs, bases, services) | 🟠 | Tableau des actifs |
| Analyse de risques (EBIOS RM recommandé) | 🟠 | Rapport d'analyse de risques |
| Procédure de gestion des incidents | 🟠 | Procédure écrite + registre |

## 2. Contrôle d'accès

| Contrôle | Statut | Preuve |
|---|---|---|
| Séparation des rôles (fondateur / super-admin / admin org / membre) | ✅ | Code : `sa-permissions.php`, `_app-founder.php` |
| Mots de passe hachés (bcrypt) | ✅ | Code : `password_hash(...PASSWORD_BCRYPT)` |
| **MFA sur les accès d'administration** | 🟠 | À ajouter (super-admin / fondateur) |
| Principe du moindre privilège (compte SQL applicatif) | 🟡 | Vérifier les droits du user MySQL (pas de DROP/GRANT) |
| Révocation des accès (départ, rotation) | 🟠 | Procédure |
| Réauthentification pour actions sensibles | 🟡 | Existe (cockpit CRON « réauth 15 min ») — étendre |

## 3. Chiffrement & secrets

| Contrôle | Statut | Preuve |
|---|---|---|
| HTTPS/TLS 1.2+ forcé partout | 🟡 | Config serveur + HSTS à vérifier |
| Chiffrement au repos des données sensibles | 🟠 | Chiffrement disque / champs sensibles |
| Secrets hors code (env/coffre) + rotation | 🟡 | `config.php` hors dépôt ✅ ; formaliser rotation |
| Jetons signés (HMAC) pour liens publics | ✅ | Code : `ak_prospect_token`, tokens factures (UUID) |

## 4. Sécurité applicative (audit de code interne réalisé ✅)

| Contrôle | Statut | Preuve |
|---|---|---|
| Requêtes préparées (anti-injection SQL) | ✅ | Audit interne : PDO paramétré partout |
| Échappement des sorties (anti-XSS) | ✅ | `htmlspecialchars` systématique |
| Protection CSRF sur les POST sensibles | ✅ | `hash_equals($_SESSION['csrf_token'])` |
| Contrôle d'accès serveur (pas côté client) | ✅ | Endpoints `app-founder-*` gardés |
| XML sécurisé (Factur-X) | ✅ | Échappement `ENT_XML1`, pas de parsing non fiable |
| Confidentialité des documents (noms non devinables) | ✅ | Correctif factures/devis + `-Indexes` |
| Endpoint CRON non exposé | ✅ | Correctif fail-closed `cron-prospect-send.php` |
| Revue de code sécurité périodique | 🟡 | Rejouer l'audit à chaque release majeure |

## 5. Journalisation & traçabilité

| Contrôle | Statut | Preuve |
|---|---|---|
| Journal des accès sensibles | 🟡 | `assokit_activity_log`, `access-logs` — à compléter/protéger |
| Horodatage fiable | 🟡 | Serveur NTP |
| Intégrité des journaux (non-répudiation) | 🟠 | Journaux en append-only / export signé |
| Conservation 12 mois | ✅ | Politique + `cron-rgpd-purge.php` |

## 6. Disponibilité & continuité

| Contrôle | Statut | Preuve |
|---|---|---|
| Sauvegardes régulières chiffrées | 🟡 | Vérifier config O2switch + test de restauration |
| Plan de reprise (PRA/PCA) | 🟠 | Document + test |
| Supervision / alerting | 🟠 | Monitoring uptime + erreurs |

## 7. Sous-traitance & hébergement

| Contrôle | Statut | Preuve |
|---|---|---|
| Hébergement UE (France) | ✅ | O2switch |
| DPA signés (O2switch, Stripe, Resend…) | 🟠 | Contrats (voir RGPD-CONFORMITE.md §4) |
| Transferts hors UE encadrés | 🟡 | Clauses types si applicable |

---

## Plan d'action recommandé (ordre)

1. **Désigner** un RSSI/DPO + rédiger la **PSSI** et l'**analyse de risques** (EBIOS RM).
2. **Durcir le technique** : MFA admin, HTTPS/HSTS, sauvegardes testées, journaux protégés.
3. **Contractualiser** les DPA sous-traitants.
4. **Choisir la voie** d'attestation : **PASSI** (souvent plus rapide) ou **ISO 27001**.
5. **Pré-audit à blanc** interne avec ce document.
6. **Mandater le cabinet** externe → rapport d'audit → dépôt DGFiP.

> Le rapport de l'audit de code interne (branche courante) constitue une **preuve**
> pour la section 4. Conserve-le dans ton dossier.

---

⚠️ Seul un **auditeur PASSI qualifié ANSSI** ou un **organisme certificateur ISO 27001
accrédité** peut délivrer l'attestation attendue par la DGFiP. Ce dossier maximise tes
chances de la réussir du premier coup.
