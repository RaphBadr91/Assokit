# Mise en service des paiements Stripe

Guide pas à pas pour activer le paiement des abonnements Assokit par carte bancaire, Apple Pay, Google Pay et prélèvement SEPA. Aucune compétence technique n’est nécessaire, sauf pour les deux commandes de l’étape 1 sur le serveur.

Prix vendu : formule **Assokit à 49,99 € HT par mois**, plus l’option **Domaine personnalisé à 10,00 € HT par mois**. Tant que RBPS est en franchise de TVA, ces montants sont prélevés tels quels (« TVA non applicable, art. 293 B du CGI »).

> **Noms des menus Stripe.** Le tableau de bord Stripe change régulièrement ses intitulés. Chaque étape donne le nom du menu **et** un lien direct : en cas de doute, suivez le lien. Pour passer du mode test au mode réel, utilisez l’interrupteur « Mode test » (ou « Environnement de test ») en haut à droite du tableau de bord.

---

## Ce qu’Assokit fait tout seul, et ce qui reste à faire à la main

**Assokit le fait pour vous** (bouton « Configurer Stripe automatiquement ») :

- le produit « Assokit » et son prix mensuel HT, le produit « Domaine personnalisé » et son prix ;
- le taux de TVA, le jour où vous activez la TVA ;
- le **webhook** (l’adresse à laquelle Stripe prévient Assokit de chaque paiement et de chaque carte enregistrée pendant un essai), abonné aux bons événements, avec son secret de signature ; recliquer sur le bouton après une mise à jour d’Assokit ajoute les nouveaux événements ;
- le **portail client** (la page Stripe où l’association change de carte, voit ses factures et peut résilier) ;
- l’enregistrement du domaine assokit.fr pour **Apple Pay et Google Pay** ;
- les **mentions légales des factures** Stripe, écrites sur chaque client : identité de RBPS (reprise de Paramètres société), mention de TVA, conditions de paiement, pénalités de retard ;
- au passage en mode réel : le nettoyage des essais faits en mode test ;
- quand **Stripe Connect** est activé sur le compte RBPS (section « Paiements reçus par les associations ») : le second webhook, celui des comptes Stripe des associations (`/stripe-connect-webhook`), avec son propre secret.

**Vous le faites une fois** : dans Assokit, Paramètres société (raison sociale, forme, SIREN, adresse de RBPS) ; dans Stripe, activation du compte RBPS, compte bancaire, libellé « ASSOKIT », e-mails aux clients, relances automatiques.

---

## Étape 1 — Mettre à jour le serveur (5 minutes)

Connectez-vous au serveur O2Switch (Terminal du cPanel), puis tapez :

```
cd ~/public_html
git pull
/usr/local/bin/php migrations/run.php 2026-10-28-stripe-mise-en-service.sql
/usr/local/bin/php migrations/run.php 2026-10-29-stripe-souscription-pendant-essai.sql
/usr/local/bin/php migrations/run.php 2026-10-30-paiements-en-ligne-associations.sql
```

Chacune des trois commandes `migrations/run.php` doit afficher « Terminé : … instruction(s) OK, 0 erreur(s) ». Vous pouvez relancer ces commandes sans risque : elles ne créent que ce qui manque et ne suppriment rien. La seconde permet à une association en essai de souscrire en gardant ses jours d’essai (premier prélèvement à la fin de l’essai) ; tant qu’elle n’est pas passée, « Configurer Stripe automatiquement » affiche ✕ « Base de données prête ». La troisième prépare les paiements en ligne reçus par les associations (section « Paiements reçus par les associations ») ; sans elle, les abonnements fonctionnent quand même (seule la ligne Stripe Connect affiche ⚠).

Vérifiez ensuite les **tâches planifiées** du cPanel (« Tâches Cron ») ; ces deux lignes doivent exister :

| Fréquence | Commande |
|---|---|
| toutes les 10 minutes | `/usr/local/bin/php /home/pura7044/public_html/cron-trial-check.php` |
| tous les jours à 8 h | `/usr/local/bin/php /home/pura7044/public_html/cron_subscriptions.php` |

La seconde envoie l’e-mail « Fonctionnalités bloquées » 15 jours après un paiement refusé. Elle fonctionne maintenant en ligne de commande, comme les autres.

---

## Étape 2 — Créer et activer le compte Stripe de RBPS

1. Créez le compte sur [stripe.com](https://stripe.com) avec une adresse e-mail de RBPS, puis activez la **double authentification** (Paramètres › Votre compte › Sécurité).
2. Cliquez sur **« Activer les paiements »** (bandeau en haut du tableau de bord, ou [dashboard.stripe.com/account/onboarding](https://dashboard.stripe.com/account/onboarding)) et renseignez :
   - le type d’entreprise et la raison sociale **exacte** de RBPS, le **SIREN**, l’adresse du siège ;
   - le représentant légal (pièce d’identité demandée par Stripe) ;
   - l’activité : « logiciel en ligne par abonnement pour associations et TPE », site **https://assokit.fr** (CGU, tarifs, mentions légales et contact doivent être accessibles) ;
   - le **compte bancaire (IBAN) professionnel** qui recevra les virements de Stripe.
3. **Paramètres › Détails publics** (ou « Informations publiques », [dashboard.stripe.com/settings/public](https://dashboard.stripe.com/settings/public)) :
   - nom public : **Assokit** ;
   - **libellé de relevé bancaire : ASSOKIT** (c’est ce que les associations verront sur leur relevé) ;
   - e-mail et téléphone du support, adresse du site.
4. **Paramètres › Image de marque** ([dashboard.stripe.com/settings/branding](https://dashboard.stripe.com/settings/branding)) : logo, icône et couleur verte Assokit (#059669). Ils apparaissent sur les factures, les reçus, la page de paiement des factures et le portail client.

---

## Étape 3 — Réglages Stripe à faire une fois (dans les deux modes)

Ces réglages se font **en mode test, puis de nouveau en mode réel** (Stripe garde des réglages séparés pour certains d’entre eux, notamment le portail client et les e-mails).

### 3.1 Factures (mentions légales)

**D’abord dans Assokit** : Pilotage › **Paramètres société** (https://assokit.fr/fondateur-cockpit/societe) : raison sociale **exacte** de RBPS, forme juridique, SIREN (ou SIRET), RCS s’il y a lieu, adresse du siège. Assokit écrit ces informations dans le **pied de facture de chaque client Stripe**, avec :
« TVA non applicable, art. 293 B du CGI » (tant que la TVA est désactivée), « Paiement à réception, par prélèvement automatique : pas d’escompte pour paiement anticipé », « pénalités de retard : trois fois le taux d’intérêt légal » et « indemnité forfaitaire de 40 € pour frais de recouvrement ».
Le pied posé sur un client **remplace** celui du compte Stripe (il ne s’y ajoute pas) : c’est pourquoi tout y figure. Après une modification des Paramètres société, recliquez sur « Configurer Stripe automatiquement » : la ligne « Mentions des factures » remet à jour tous les clients.

**Puis dans Stripe**, **Paramètres › Facturation › Factures** (modèle de facture, [dashboard.stripe.com/settings/billing/invoice](https://dashboard.stripe.com/settings/billing/invoice)) :

- **Numérotation** : choisissez la numérotation « au niveau du compte » (une seule suite pour tous les clients). Gardez un préfixe différent de « AK- », qui est celui des factures émises par Assokit pour les paiements par virement.
- **Langue** : français.
- **Pied de page par défaut** : vous pouvez y mettre les mêmes mentions (il ne sert que pour une facture faite à la main dans Stripe pour un client sans pied ; les clients créés par Assokit ont le leur).
- **Stripe Tax** (« Taxes », calcul automatique) : **inutile, ne l’activez pas**. Assokit calcule la TVA lui-même.

> À vérifier sur la première facture de test : le pied de la facture PDF doit montrer la raison sociale et le SIREN de RBPS. Si ce n’est pas le cas, prévenez le développeur.

### 3.2 E-mails envoyés aux clients

**Paramètres › E-mails clients** (ou « E-mails », [dashboard.stripe.com/settings/emails](https://dashboard.stripe.com/settings/emails)) — activez :

- **Paiements réussis** (reçu envoyé à chaque prélèvement) ;
- **Factures et avoirs** si proposé ;
- **Prélèvements SEPA** (notifications de prélèvement et de mandat : obligatoires pour le SEPA).

**Paramètres › Facturation › Abonnements et e-mails** (ou « Gestion des recouvrements », [dashboard.stripe.com/settings/billing/automatic](https://dashboard.stripe.com/settings/billing/automatic)) — activez :

- l’e-mail envoyé **quand un paiement par carte échoue**, avec le lien pour mettre à jour la carte ;
- l’e-mail envoyé **quand une banque demande une confirmation 3-D Secure** (lien de confirmation hébergé par Stripe) ;
- l’e-mail avant **l’expiration d’une carte**.

Assokit envoie de son côté : pour une souscription pendant l’essai, « Votre abonnement Assokit est souscrit » (aucun prélèvement aujourd’hui, date et montant du premier prélèvement), un rappel « Premier prélèvement … le JJ/MM/AAAA » 3 jours avant la fin de l’essai (pas de rappel en plus si elle a souscrit dans ces 3 jours : l’e-mail de confirmation donne déjà la date et le montant), et « Annulation de votre abonnement Assokit enregistrée » si l’association annule avant ; puis l’e-mail « Votre abonnement Assokit est actif » au premier paiement, l’e-mail « Paiement à régulariser » le jour d’un refus, « Fonctionnalités bloquées » 15 jours plus tard, la **confirmation de résiliation** (avec la date de fin d’accès) et « Votre abonnement Assokit est arrêté » si Stripe abandonne après ses relances. Vous recevez une alerte (e-mail + cloche du cockpit) à chaque nouvel abonnement, refus, résiliation, arrêt, remboursement ou litige.

### 3.3 Relances automatiques

Sur la même page (**Abonnements et e-mails** › « Gérer les paiements ayant échoué » / « Relances intelligentes ») :

- relances automatiques (« Smart Retries ») sur **3 semaines** : plus longtemps que les 15 jours de tolérance qu’Assokit annonce à l’association ;
- après la dernière tentative : **« Annuler l’abonnement »**, et laisser la facture « en souffrance » (pas « irrécouvrable »). L’association reçoit « Votre abonnement Assokit est arrêté », garde l’accès complet jusqu’à la fin de sa tolérance si elle n’est pas finie, puis passe au plan Démarrage (accès limité, données conservées) ; elle peut reprendre Assokit quand elle veut.
- **la dernière facture impayée** : elle n’est plus due (Assokit l’affiche « Annulée », sans lien de paiement). Annulez-la dans Stripe par un **avoir** : Facturation › Factures › la facture › « Créer un avoir » (montant total). Vous recevez une alerte « Abonnement Stripe arrêté » qui le rappelle.

### 3.4 Moyens de paiement

**Paramètres › Moyens de paiement** ([dashboard.stripe.com/settings/payment_methods](https://dashboard.stripe.com/settings/payment_methods)) :

- **activés** : Cartes, Apple Pay, Google Pay, **Prélèvement SEPA** (Stripe peut demander quelques informations pour le SEPA) ;
- **désactivés** : Klarna, Link et les autres moyens qui ne conviennent pas à un abonnement.

Si le prélèvement SEPA n’est pas activé, Assokit ne le propose simplement pas (la liste de contrôle l’indique).

> Le **portail client**, le **webhook** et les **domaines Apple Pay / Google Pay** n’ont **pas** à être réglés à la main : le bouton d’Assokit s’en charge.

### 3.5 Conditions générales

Relisez la page **https://assokit.fr/cgu** (version du 29/10/2026) avant d’activer les paiements réels : § 4 (moyens de paiement, mandat SEPA, souscription pendant l’essai avec premier prélèvement automatique à la fin de l’essai et annulation sans frais avant, renouvellement à la date anniversaire, paiement refusé, absence de droit de rétractation) et § 8 (résiliation). Faites-la valider par votre conseil si besoin. Comme le prévoit le § 12, prévenez par e-mail les associations déjà inscrites de la nouvelle version (Pilotage › Campagnes e-mail).

---

## Étape 4 — Brancher Assokit en mode test

1. Dans Stripe, mode test activé : **Développeurs › Clés API** ([dashboard.stripe.com/test/apikeys](https://dashboard.stripe.com/test/apikeys)). Copiez la **clé publiable** (`pk_test_…`) et la **clé secrète** (`sk_test_…`, bouton « Révéler la clé »).
2. Ouvrez **https://assokit.fr/fondateur-stripe-config** (Pilotage › tuile « Config Stripe » ; confirmez votre identité si le cockpit le demande).
3. Bloc **« 1. Clés API Stripe »** : collez les deux clés, cliquez sur **« Enregistrer les clés »**. Les clés ne sont jamais réaffichées ; un champ laissé vide garde la clé déjà enregistrée.
4. Bloc **« 2. Configurer Stripe automatiquement »** : cliquez sur le bouton. Une liste s’affiche :
   - ✓ : fait ;
   - ⚠ : facultatif ou à vérifier (par exemple le prélèvement SEPA pas encore activé) ;
   - ✕ : à corriger — la ligne 👉 dit quoi faire, puis recliquez sur le bouton.
   Vous pouvez recliquer autant de fois que vous voulez : rien n’est créé en double.
5. Bloc **« 3. Activer les paiements »** : indiquez d’abord les **associations de test** (leurs numéros, séparés par des virgules ; le numéro figure dans le cockpit, Associations › la fiche). Laissé vide, ce sont les associations du Fondateur. Puis cliquez sur **« Activer les paiements »**. En mode test, aucun vrai prélèvement n’a lieu, et **seules les associations de test** voient le paiement (avec un bandeau « MODE TEST ») : les vraies associations voient « Le paiement en ligne ouvre très bientôt », personne ne peut s’offrir un abonnement avec une carte de test.

---

## Étape 5 — Tout essayer en mode test

Créez une **association de test** avec une adresse e-mail à vous (page d’inscription du site) : elle démarre avec l’essai PRO de 14 jours. Ajoutez son numéro dans le bloc 3 (associations de test). Puis, connecté avec ce compte (dans un navigateur, pas dans l’application mobile, qui n’affiche jamais le paiement) : pour toutes les cartes de test, n’importe quelle date future, n’importe quel code à 3 chiffres.

1. **Souscrire pendant l’essai** (le cas le plus courant) : **Mon abonnement › « Passer à Assokit »**. La page dit « Aucun prélèvement aujourd’hui. Premier prélèvement de 49,99 € le JJ/MM/AAAA, à la fin de votre essai. Vous pouvez annuler avant sans frais. » et le bouton est **« Souscrire · 0 € aujourd’hui »**. Enregistrez la carte **4242 4242 4242 4242** (ou, onglet « Prélèvement SEPA », l’IBAN de test **FR14 2004 1010 0505 0001 3M02 606**). Vérifiez :
   - la page « Abonnement souscrit ! » : aucun prélèvement aujourd’hui, date et montant du premier prélèvement ;
   - l’e-mail « Votre abonnement Assokit est souscrit » ;
   - **Mon abonnement** : « Essai gratuit jusqu’au … · abonnement Assokit souscrit · premier prélèvement le … », « Carte enregistrée », « Changer ma CB » et « Annuler mon abonnement (sans frais) » ;
   - le cockpit (Associations › la fiche) : badge **« ESSAI · carte enregistrée · 1er prélèvement le … »** ;
   - dans Stripe › Facturation › Abonnements : l’abonnement est **« Essai »** jusqu’à la date de fin d’essai, avec une facture à 0 € (elle n’apparaît pas dans « Mes factures »).
2. **Fin de l’essai et premier prélèvement** : Stripe prélève tout seul le jour de la fin de l’essai, et Assokit passe alors l’association en abonnement actif (e-mail « Votre abonnement Assokit est actif »). Pour l’essayer sans attendre 14 jours, utilisez une **horloge de test** Stripe :
   1. Stripe (mode test) › **Facturation › Horloges de test** ([dashboard.stripe.com/test/test-clocks](https://dashboard.stripe.com/test/test-clocks)) › « Nouvelle simulation » (heure de départ : maintenant). Copiez son identifiant `clock_…` ;
   2. Assokit › Configuration Stripe › bloc 3 › **« Horloge de test Stripe »** : collez l’identifiant, puis « Enregistrer » ;
   3. créez une **nouvelle** association de test (un client Stripe déjà créé ne peut pas être rattaché à une horloge ; 3 clients au plus par horloge), ajoutez-la aux associations de test et souscrivez comme au point 1 ;
   4. dans Stripe, ouvrez l’horloge et **avancez-la** au-delà de la date de fin d’essai (de 15 jours, par exemple) : Stripe émet la première facture et prélève la carte. Dans Assokit, l’association passe en abonnement actif et la facture « Payée » apparaît dans « Mon abonnement › Mes factures ».

   À la fin de vos essais, videz le champ « Horloge de test » : les nouveaux clients suivent de nouveau l’heure réelle. Sans horloge, vous pouvez aussi terminer l’essai d’un abonnement dans Stripe › Facturation › Abonnements › l’abonnement (action « Terminer l’essai maintenant », ou « Mettre à jour l’abonnement » › fin de l’essai : maintenant, selon la version du tableau de bord).
3. **Premier prélèvement refusé** : souscrivez pendant l’essai avec la carte **4000 0000 0000 0341** (acceptée à l’enregistrement, refusée au prélèvement), puis terminez l’essai (point 2). Assokit passe l’association « en retard de paiement » (15 jours de tolérance, accès complet) et envoie « Paiement à régulariser » ; « Changer ma CB » avec la carte 4242 4242 4242 4242 relance le paiement et l’abonnement devient actif.
4. **Annulation pendant l’essai** : sur une association qui a souscrit, Mon abonnement › « Annuler mon abonnement (sans frais) » › « Annuler sans frais ». L’abonnement Stripe est arrêté tout de suite (rien n’est prélevé), l’e-mail « Annulation de votre abonnement Assokit enregistrée » arrive, et l’essai continue jusqu’à sa date puis se termine normalement (« Votre essai a pris fin »). Même résultat si l’association résilie depuis le portail Stripe (« Changer ma CB » › Annuler l’abonnement) : l’abonnement s’arrête à la fin de l’essai, sans prélèvement.
5. **Paiement immédiat (après l’essai)** : depuis le cockpit, laissez se terminer (ou terminez) l’essai d’une association de test, puis Mon abonnement › « Choisir Assokit ». La page affiche « Payer 49,99 €/mois » : le premier mois est prélevé tout de suite. C’est aussi le cas pendant les 48 dernières heures de l’essai.

   | Carte | Résultat attendu |
   |---|---|
   | **4242 4242 4242 4242** | paiement accepté : « Paiement confirmé », abonnement actif |
   | **4000 0025 0000 3155** | fenêtre **3-D Secure** : cliquez « Réussir » (accepté) ou « Échouer » (refusé, aucun montant prélevé) |
   | **4000 0000 0000 9995** | carte **refusée** (fonds insuffisants) : message d’erreur, aucun montant prélevé |

6. **Prélèvement SEPA** : onglet « Prélèvement SEPA », titulaire au choix, IBAN de test **FR14 2004 1010 0505 0001 3M02 606** (accepté après un délai). Après l’essai, la page indique « Prélèvement SEPA en cours de vérification », « Mon abonnement » aussi, et aucun second paiement n’est proposé. Pendant l’essai, le mandat est seulement enregistré : le prélèvement a lieu à la fin de l’essai, et l’essai ne se termine pas tant que la banque n’a pas répondu. En mode test, la confirmation arrive en quelques minutes.
7. Vérifiez sur le compte de test abonné :
   - **Mon abonnement** : « Abonnement actif · Renouvellement automatique », la facture « Payée » avec son PDF ;
   - **Changer ma CB** : ouvre le portail Stripe (en français) ;
   - **Annuler mon abonnement** : « Résiliation programmée » jusqu’à la fin du mois payé, et l’e-mail de confirmation de résiliation est arrivé ;
   - l’e-mail « Votre abonnement Assokit est actif » est arrivé ;
   - la facture PDF porte bien les mentions de RBPS (étape 3.1).
8. Vérifiez côté fondateur :
   - **Configuration Stripe › Activité récente** : les paiements apparaissent, avec la date de la dernière notification reçue de Stripe ;
   - **Cockpit › Associations** : la fiche de l’association de test est sur Assokit, payée par carte ;
   - dans Stripe : **Paiements**, **Abonnements** (menu Facturation) et **Développeurs › Webhooks** (les événements doivent être en « 200 OK »).
9. (Facultatif) Échec d’une échéance mensuelle : sur une association déjà abonnée, **Changer ma CB** et enregistrez la carte **4000 0000 0000 0341**. Puis, dans Stripe › Facturation › Abonnements › l’abonnement, utilisez « Actions › Facturer maintenant » (ou avancez l’horloge de test d’un mois) : le prélèvement est refusé, Assokit passe l’association « en retard de paiement » (15 jours de tolérance) et envoie l’e-mail « Paiement à régulariser ». Remettez la carte 4242 4242 4242 4242 avec Changer ma CB : le paiement est relancé et l’abonnement redevient actif.

---

## Étape 6 — Passer en mode réel

1. Dans Stripe, **désactivez le mode test**, puis **Développeurs › Clés API** ([dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys)) : copiez `pk_live_…` et `sk_live_…`.
2. Sur **https://assokit.fr/fondateur-stripe-config**, bloc 1 : collez **les deux** clés réelles, « Enregistrer les clés ». Les paiements passent automatiquement **en pause** (les réglages du mode test ne valent rien avec les clés réelles).
3. Bloc 2 : **« Configurer Stripe automatiquement »**. Toutes les lignes doivent être ✓. La ligne « Données des essais en mode test » indique combien d’abonnements et de factures de test ont été retirés : les associations de test repassent au plan Démarrage, et un identifiant client de test est remplacé tout seul au prochain paiement. Recliquer plus tard sur ce bouton (après un changement de prix, par exemple) ne touche jamais aux vrais paiements, y compris un prélèvement SEPA en cours de vérification.
4. Bloc 3 : **« Activer les paiements »**. C’est en ligne : **toutes** les associations peuvent payer pour de vrai (la liste des associations de test ne sert qu’en mode test).
5. Refaites en mode réel les réglages de l’étape 3 qui sont propres à chaque mode (e-mails clients, relances).
6. Facultatif : en mode test, **Développeurs › Webhooks**, supprimez le webhook de test. S’il reste, ses notifications sont reconnues et ignorées par Assokit.

> Faites un premier vrai paiement avec une carte à vous sur une association de test, puis remboursez-le dans Stripe (voir plus bas) et résiliez l’abonnement.

---

## Ce qui se passe pour les associations

- **Essai gratuit de 14 jours** à l’inscription, sans carte. Rappels par e-mail à J-7, J-3, J-1 et le dernier jour, avec le lien « Choisir ma formule » (pas pour une association qui a déjà souscrit).
- **Souscription pendant l’essai** : l’association **garde tous ses jours d’essai**. Elle enregistre sa carte (ou son mandat SEPA, Apple Pay, Google Pay) sans rien payer ; le premier mois est prélevé **automatiquement le jour de la fin de l’essai**, puis chaque mois à cette date. Elle reçoit « Votre abonnement Assokit est souscrit », puis, 3 jours avant, « Premier prélèvement … le … » (sauf si elle a souscrit dans ces 3 jours). Le cockpit et l’application Fondateur affichent « ESSAI · carte enregistrée · 1er prélèvement le … ». Elle peut **annuler sans frais** avant cette date (Mon abonnement, ou portail Stripe) : rien n’est prélevé et l’essai se termine normalement. Une fois la date passée, le temps que Stripe prélève (« premier prélèvement en cours »), l’annulation sans frais n’est plus proposée ; elle pourra résilier dès que le prélèvement aura abouti (effet à la fin du mois payé). Le jour du prélèvement : payé → abonnement actif (« Votre abonnement Assokit est actif ») ; refusé → « en retard de paiement », comme une échéance refusée (15 jours de tolérance). Dans les 48 dernières heures de l’essai, ou après, le premier mois est prélevé tout de suite.
- **Vous prolongez l’essai** d’une association qui a souscrit (cockpit, application, ou nouvelle date de fin d’essai dans Pilotage › Abonnements) : le premier prélèvement est déplacé à la nouvelle date chez Stripe. Si Stripe ne répond pas, rien n’est changé et un message vous demande de réessayer. **Vous la passez sur une autre formule** depuis le cockpit : sa souscription Stripe est annulée (rien n’est prélevé).
- **Vous suspendez (« Abus »…) ou refusez une association qui a souscrit pendant son essai** : sa souscription est annulée chez Stripe, rien n’est prélevé ; elle reçoit « Votre abonnement Assokit est annulé : aucun prélèvement ». Si Stripe ne répond pas à ce moment-là, le cockpit (ou l’application) vous le dit : annulez l’abonnement dans Stripe avant la fin de l’essai. Sinon, à la fin de l’essai, Assokit l’arrête de lui-même, ne démarre aucun abonnement et vous envoie « Premier prélèvement à rembourser » s’il a été prélevé.
- **Fin de l’essai sans souscription** : l’association passe en accès limité (plan Démarrage), ses données sont conservées ; elle reçoit « Votre essai a pris fin » et peut choisir Assokit à tout moment.
- **Prélèvement SEPA** : la banque confirme en quelques jours ouvrés. Pendant ce temps l’essai ne se termine pas et aucun second paiement n’est possible ; l’abonnement s’active tout seul à la confirmation.
- **Chaque mois** : Stripe prélève la carte ou le compte enregistré et envoie le reçu ; la facture apparaît dans « Mon abonnement › Mes factures ».
- **Paiement refusé** : l’association reçoit un e-mail et voit « Régularisez sous N jours » (15 jours de tolérance, accès complet). « Régulariser » ouvre la facture Stripe à payer ; changer de carte dans le portail relance aussitôt le paiement. Après 15 jours sans paiement, l’Assistant IA et la diffusion d’e-mails sont bloqués (le reste de l’espace reste accessible). Si Stripe abandonne après ses relances (3 semaines), l’abonnement est arrêté : e-mail « Votre abonnement Assokit est arrêté », accès complet jusqu’à la fin de la tolérance si elle n’est pas finie, puis plan Démarrage.
- **Résiliation** (« Annuler mon abonnement » ou portail Stripe) : confirmée par e-mail avec la date de fin d’accès ; effet à la fin du mois payé, puis plan Démarrage. Aucun remboursement automatique (pas de « satisfait ou remboursé »).
- **Paiement impossible** (la page de paiement l’explique, avec « Nous contacter ») : association suspendue par vous pour un autre motif que la fin de l’essai ou un impayé (« Abus »…) ; association déjà abonnée sur facture ou par virement (pour passer à la carte, arrêtez d’abord la facturation manuelle à la fin de la période payée) ; association fermée.
- **Association fermée ou supprimée** depuis le cockpit (ou l’application) : ses abonnements Stripe, y compris un premier paiement encore en attente, sont arrêtés. Si un prélèvement SEPA est malgré tout confirmé ensuite, Assokit arrête l’abonnement et vous alerte pour rembourser.
- **Option Domaine personnalisé** : prélevée avec l’abonnement ; vous recevez une alerte et configurez le domaine à la main (Pilotage › Domaines).
- **Application mobile** : aucun prix ni paiement n’y apparaît (règles d’Apple et de Google) ; tout se fait sur le site.

---

## Paiements reçus par les associations (Stripe Connect)

Chaque association peut faire payer en ligne **ses** factures, **ses** cotisations et **ses** dons (carte, Apple Pay, Google Pay, prélèvement SEPA). Elle relie **son propre compte Stripe** depuis Assokit (menu Finances › **Paiements en ligne**). L’argent va directement sur le compte de l’association : il ne transite jamais par RBPS, les frais Stripe sont à sa charge, et Assokit ne prend aucune commission (CGU § 11 bis). Assokit est un intermédiaire technique, pas un prestataire de paiement.

### A. Activer Stripe Connect sur le compte RBPS (une seule fois, à la main)

Stripe ne permet pas de le faire par l’API. Faites-le d’abord en **mode test**, puis refaites-le en **mode réel**.

1. Tableau de bord Stripe › **Connect** (menu de gauche, ou [dashboard.stripe.com/connect](https://dashboard.stripe.com/connect)) › **Commencer**.
2. Type de plateforme : **« Plateforme »** (des utilisateurs reçoivent des paiements de leurs propres clients). Comptes : **« Standard »** (l’association a son propre tableau de bord Stripe, gère ses remboursements et ses litiges, et Stripe lui facture ses frais).
3. **Profil de la plateforme** : activité « Logiciel » (logiciel de gestion pour associations et petites entreprises), site https://assokit.fr, description courte : « Les associations clientes d’Assokit reçoivent les cotisations, dons et paiements de factures de leurs membres et clients sur leur propre compte Stripe. Assokit ne détient aucun fonds et ne prend aucune commission. »
4. **Image de marque Connect** (Paramètres › Connect › Image de marque) : nom « Assokit », icône et couleur (vert #059669). C’est ce que l’association voit pendant son inscription sur Stripe.
5. En **mode réel**, Stripe examine le profil de la plateforme avant d’ouvrir Connect (quelques jours). Tant que ce n’est pas fait, la ligne Stripe Connect reste ⚠.
6. Clé **restreinte** (rk_…) : ajoutez les droits d’écriture « Comptes connectés » (Accounts), « Liens de compte » et « Sessions Checkout ». Avec la clé secrète standard (sk_…), rien à faire.

### B. Brancher Assokit

1. Étape 1 faite (migration `2026-10-30-paiements-en-ligne-associations.sql`).
2. Configuration Stripe › **« Configurer Stripe automatiquement »** : la ligne « Paiements des associations (Stripe Connect) » doit être ✓ (webhook `https://assokit.fr/stripe-connect-webhook` créé, 8 événements, secret enregistré). Si elle reste ⚠ « Stripe Connect n’est pas encore activé », refaites la partie A dans le bon mode.
3. Bloc **4. Paiements reçus par les associations** › **« Ouvrir les paiements en ligne aux associations »**. Le bouton « Mettre en pause » retire tous les boutons « Payer en ligne » (les paiements déjà faits restent enregistrés et les notifications de Stripe continuent d’être traitées).
4. Le même bloc montre les associations reliées (« ✓ Encaisse », « ⚠ Inscription à terminer », « ⏳ Vérification Stripe », « ✕ Déconnecté ») et les montants encaissés ce mois-ci et au total.

En **mode test**, comme pour les abonnements, seules les associations de test (bloc 3) voient le paiement en ligne. Les espaces de démonstration (DEMO F et démos commerciales) ne peuvent jamais relier de compte en mode réel.

### C. Tout essayer en mode test

Avec une association de test, dans le navigateur (jamais dans l’application) :

1. **Paiements en ligne › « Connecter mon compte Stripe »** : la page d’inscription de Stripe s’ouvre ; en mode test, Stripe propose de **passer** les vérifications (bouton « Utiliser des données de test »). Au retour, la page affiche « ✓ Paiements en ligne actifs ». Cochez Factures, Cotisations, Dons et Prélèvement SEPA, puis « Enregistrer ».
2. **Facture** : créez une facture, envoyez-la (l’e-mail contient « Payer en ligne… » et le lien ; le PDF a un QR code « Payer en ligne »). Ouvrez le lien, « Payer … en ligne », carte **4242 4242 4242 4242**. Retour sur « Paiement confirmé — merci ! » ; la facture est « Payée » (règlement « Carte bancaire », référence pi_…), les relances s’arrêtent, l’association reçoit « 💳 Paiement en ligne reçu » (cloche et e-mail), le client reçoit « Reçu de paiement ».
3. **Cotisation** : Cotisations › la campagne › bloc « Paiement en ligne par carte » : copiez le lien (ou le QR code), ouvrez-le, choisissez un tarif, payez. Le paiement apparaît « 💳 CB (Stripe) » dans la campagne avec les frais Stripe. Avec le **lien personnel** d’un adhérent (même bloc, ou e-mail de relance de cotisation), son adhésion est prolongée.
4. **Don** : créez une campagne de type « Dons » ; la page `/don/…` propose un montant libre (1 € minimum, 10 000 € maximum).
5. **Prélèvement SEPA** : IBAN de test **FR1420041010050500013M02606** (accepté) ou **FR8420041010050500013M02607** (rejeté) : la facture affiche « Prélèvement SEPA en cours » puis passe « Payée » (ou « rejeté », avec un e-mail au payeur et à l’association).
6. **Remboursement** : dans le tableau de bord Stripe de l’association, remboursez un paiement. Une cotisation passe « Remboursé » ; une facture repasse « à payer » (établissez un avoir si la vente est annulée) ; l’association reçoit « ↩ Paiement en ligne remboursé ».

Les paiements faits en mode test sont enregistrés comme de vrais paiements dans les associations de test (factures payées, cotisations) : faites ces essais avec des factures et des campagnes de test, puis annulez-les. Les comptes Stripe de test des associations ne servent pas en mode réel : au passage en mode réel, chaque association relie de nouveau son compte (« Connecter mon compte Stripe »).

### D. Ce que voient les associations et leurs payeurs

- **Paiements en ligne** (administrateur ou trésorier) : relier / reprendre l’inscription / état du compte (paiements, versements, informations demandées par Stripe) / choisir ce qui se paie en ligne / se déconnecter / derniers paiements avec les frais Stripe. Guide à leur transmettre : `docs/paiements-en-ligne-associations.md`.
- **Payeur** : page Stripe aux couleurs d’Assokit, au nom de l’association ; reçu « Reçu de paiement » envoyé au nom de l’association (ce n’est pas un reçu fiscal).
- **Application mobile** : jamais de paiement dans l’application (règles d’Apple et de Google). Les pages de paiement s’ouvrent dans le navigateur du téléphone ; les fiches facture et campagne proposent « Partager le lien de paiement ». La page « Paiements en ligne » y est en lecture seule.
- **Comptabilité (export FEC)** : un paiement en ligne est compté comme un règlement manuel (512 / 411 pour une facture, 512 / 756 pour une cotisation, 512 / **754100 Dons manuels** pour un don) ; les frais Stripe de chaque paiement sont passés en **627800 Frais et commissions bancaires**. Le compte 512 suit les paiements bruts moins les frais ; les virements de Stripe vers la banque arrivent plus tard (rapprochement à faire par le comptable). À valider avec un expert-comptable.
- **Remboursements et litiges** : l’association les gère dans **son** tableau de bord Stripe ; Assokit se met à jour (cotisation « Remboursé », facture de nouveau « à payer », alerte en cas de contestation). RBPS n’intervient pas.

### E. Anciennes clés Stripe saisies par des associations

L’ancienne page « Configuration des paiements » permettait à une association de coller ses propres clés Stripe : ce n’est plus possible, et elles ne servent à rien. Elles restent en base tant que vous ne les effacez pas. Pour les effacer (recommandé, après accord), dans phpMyAdmin :

```
UPDATE org_payment_settings SET stripe_secret_key = NULL, stripe_webhook_secret = NULL, stripe_publishable_key = NULL, stripe_enabled = 0;
```

---

## Voir les paiements

- **Assokit › Configuration Stripe › Activité récente** : derniers paiements (payés, à régulariser, refusés) et notifications Stripe en échec.
- **Alertes** : un e-mail et une notification dans le cockpit à chaque nouvel abonnement, refus de paiement, résiliation, remboursement, litige, double abonnement ou option domaine.
- **Stripe** : Paiements, Facturation › Abonnements, Facturation › Factures, Clients. Les virements vers votre compte bancaire : Soldes (ou « Virements »).

## Remboursements, litiges et annulations

- **Rembourser** : Stripe › Paiements › le paiement › « Rembourser ». Assokit vous envoie une alerte mais ne change pas l’abonnement : si vous voulez aussi l’arrêter, annulez-le (ci-dessous).
- **Arrêter un abonnement tout de suite** : Stripe › Facturation › Abonnements › l’abonnement › « Annuler l’abonnement » › « Immédiatement ». Assokit passe l’association au plan Démarrage. Fermer ou supprimer l’association depuis le cockpit annule aussi son abonnement Stripe.
- **Litige** (contestation auprès de la banque) : alerte « Contestation de paiement » ; répondez dans Stripe › Paiements › Litiges avant la date limite.
- **Changer une association de formule depuis le cockpit** ne touche pas Stripe : si le cockpit affiche « Abonnement Stripe … toujours actif », annulez-le dans Stripe.

## Prix et TVA

- **Changer le prix** : modifiez-le dans Pilotage › **Plans tarifaires**, puis cliquez **tout de suite** sur « Configurer Stripe automatiquement ». Tant que ce n’est pas fait, le paiement en ligne est refusé (« momentanément indisponible », aucun montant prélevé) et vous recevez l’alerte « Paiement bloqué : prix Stripe à mettre à jour » : jamais un montant prélevé différent de celui affiché. Un nouveau prix Stripe est créé pour les **nouveaux** abonnements, l’ancien est archivé : les associations déjà abonnées gardent leur montant (Stripe ne change jamais le prix d’un abonnement en cours ; pour le leur appliquer, prévenez-les 30 jours avant, CGU § 4).
- **Le jour où RBPS devient assujettie à la TVA** : la TVA est due sur **toutes** les ventes à partir de la date d’assujettissement, abonnements déjà en cours compris. Aucune facture sans TVA ne doit être émise après cette date.
  1. Assokit › Paramètres société : cochez « Société assujettie à la TVA » et saisissez le numéro de TVA de RBPS (il s’ajoute aux mentions des factures) ; ajoutez-le aussi dans Stripe (Paramètres › Détails de l’entreprise) ;
  2. Assokit › Configuration Stripe › bloc TVA : **« Activer la TVA »**, puis « Configurer Stripe automatiquement ». La mention « TVA non applicable, art. 293 B du CGI » est retirée du pied de facture de **tous** les clients Stripe (le message de confirmation donne le nombre de clients mis à jour) ;
  3. abonnements **déjà en cours** (Assokit n’applique la TVA automatiquement qu’aux nouveaux) : dans Stripe › Facturation › Abonnements › chaque abonnement › « Mettre à jour l’abonnement » › ajoutez le taux « TVA 20 % » (créé par Assokit), sans prorata, avant leur prochaine échéance. Cela vaut aussi pour les abonnements « Essai » souscrits pendant l’essai et pas encore prélevés (le message de confirmation donne leur nombre) : les pages de ces associations affichent le montant que Stripe prélèvera vraiment (« TTC » seulement si leur abonnement porte la TVA). Deux choix commerciaux : garder le prix HT (le montant prélevé augmente de 20 % : prévenez chaque association 30 jours avant, CGU § 4) ou garder le montant prélevé (baissez alors le prix HT de ces abonnements). Demandez à votre comptable lequel retenir.

## À prévoir : facturation électronique

La réforme de la facturation électronique impose aux TPE et micro-entreprises d’**émettre** des factures électroniques à partir du **1er septembre 2027** pour leurs clients assujettis à la TVA (voir docs/FACTURATION-ELECTRONIQUE.md). Les factures PDF de Stripe devront alors passer par une plateforme agréée (PA). À vérifier avec votre comptable avant cette date : quelles associations clientes sont concernées, et quelle plateforme choisir.

---

## En cas de problème

| Ce que vous voyez | Que faire |
|---|---|
| ✕ « Base de données prête » | Refaites l’étape 1 (commande `migrations/run.php`), puis recliquez. |
| ✕ « Clés API » | Recopiez les deux clés depuis Développeurs › Clés API, du même mode (test ou réel). |
| ✕ « Compte Stripe activé » (mode réel) | Terminez l’activation du compte dans Stripe (étape 2). |
| ✕ « Webhook » | En mode réel, ouvrez la page en **https://assokit.fr** (sans www) et recliquez. |
| ⚠ « Prélèvement SEPA » | Activez-le dans Paramètres › Moyens de paiement, puis recliquez. |
| ⚠ « Mentions des factures » | Complétez Paramètres société (raison sociale, SIREN, adresse), puis recliquez. |
| Alerte « Paiement bloqué : prix Stripe à mettre à jour » | Le prix a changé dans Plans tarifaires : recliquez sur « Configurer Stripe automatiquement ». |
| Une association voit « Le paiement en ligne ouvre très bientôt » | Stripe est en mode test et elle n’est pas dans la liste des associations de test (bloc 3) ; en mode réel, tout le monde peut payer. |
| ⚠ « Domaine Apple Pay / Google Pay » | La carte reste proposée. Si Stripe demande un fichier de vérification Apple, téléchargez-le depuis Paramètres › Domaines des moyens de paiement et déposez-le dans `~/public_html/.well-known/` (il n’est pas dans le dépôt : `git pull` ne l’apporte pas). |
| ⚠ « Paiements des associations (Stripe Connect) » | Activez Connect dans Stripe (section « Paiements reçus par les associations », partie A), dans le mode affiché, puis recliquez. Les abonnements n’en dépendent pas. |
| Une association voit « Le paiement en ligne ouvre très bientôt » sur Paiements en ligne | Bloc 4 fermé, migration 2026-10-30 manquante, webhook Connect absent, ou mode test et association hors de la liste de test. |
| Un payeur a payé, mais la facture ou la cotisation n’est pas à jour | La page « Paiement confirmé » l’enregistre ; sinon la notification de Stripe (webhook Connect). Regardez Activité récente (notifications en échec) et, dans Stripe, Développeurs › Webhooks › le webhook « Stripe Connect ». |
| « Notification(s) en échec » dans Activité récente | Stripe renvoie automatiquement pendant 3 jours. Si cela dure, regardez le détail dans Développeurs › Webhooks et contactez le développeur. |
| Une association dit avoir payé mais reste « en essai » | Sa page « Paiement confirmé » active l’abonnement ; sinon la notification de Stripe le fait. Vérifiez Activité récente, puis Développeurs › Webhooks. |
| « ESSAI · carte enregistrée · prélèvement en cours » après la fin de l’essai | Normal pendant environ une heure (Stripe prélève peu après la fin de l’essai ; quelques jours ouvrés pour un prélèvement SEPA). Sans nouvelle de Stripe, Assokit relit l’abonnement toutes les 10 minutes ; si rien ne se passe pendant 3 jours, l’essai se termine normalement : regardez l’abonnement dans Stripe. |

## Récapitulatif des commandes serveur

```
cd ~/public_html
git pull
/usr/local/bin/php migrations/run.php 2026-10-28-stripe-mise-en-service.sql
/usr/local/bin/php migrations/run.php 2026-10-29-stripe-souscription-pendant-essai.sql
/usr/local/bin/php migrations/run.php 2026-10-30-paiements-en-ligne-associations.sql
```

Tâches planifiées : `cron-trial-check.php` toutes les 10 minutes et `cron_subscriptions.php` chaque jour à 8 h, avec `/usr/local/bin/php` (voir l’étape 1).
