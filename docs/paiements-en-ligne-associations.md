# Paiements en ligne : guide pour les associations

Avec Assokit, vos adhérents, donateurs et clients peuvent payer en ligne **vos cotisations, vos dons et vos factures**, par carte bancaire, Apple Pay, Google Pay ou prélèvement SEPA. L’argent arrive **directement sur le compte bancaire de l’association**.

Assokit s’appuie sur **Stripe**, le prestataire de paiement utilisé par des millions d’entreprises et d’associations. Vous ouvrez **votre propre compte Stripe** depuis Assokit : c’est votre compte, à votre nom, avec votre tableau de bord.

---

## Ce qu’il faut savoir avant de commencer

- **Frais** : Stripe prend des frais sur chaque paiement, à la charge de l’association (à titre indicatif, en Europe : environ 1,5 % + 0,25 € par carte, un peu plus d’un tiers d’euro par prélèvement SEPA ; tarifs à jour sur stripe.com/fr/pricing). **Assokit ne prend aucune commission.**
- **Qui encaisse** : l’association est le vendeur. Assokit est un intermédiaire technique : il affiche les boutons « Payer en ligne », enregistre les paiements reçus et envoie les reçus, mais ne détient jamais votre argent.
- **Versements** : Stripe verse les paiements reçus sur le compte bancaire de l’association, selon le calendrier de votre compte Stripe (en général quelques jours après chaque paiement).
- **Reçus fiscaux** : Assokit envoie un **reçu de paiement**, pas un reçu fiscal (CERFA). Si votre association peut délivrer des reçus fiscaux, continuez de les établir vous-même.
- **Qui peut le configurer** : un administrateur ou le trésorier (droit « Gérer les finances »). Les coordinateurs voient l’état, sans le modifier.

---

## 1. Relier le compte Stripe de l’association (10 minutes, une seule fois)

1. Dans Assokit, sur le site (pas dans l’application) : menu **Finances › Paiements en ligne**.
2. Cliquez sur **« Connecter mon compte Stripe »**. La page sécurisée de Stripe s’ouvre.
3. Stripe vous demande :
   - l’identité de l’association : nom, adresse, numéro RNA ou SIREN, activité ;
   - celle de son **représentant légal** (président·e, ou trésorier·ère s’il est mandaté) : nom, date de naissance, adresse, parfois une pièce d’identité ;
   - l’**IBAN** du compte bancaire de l’association, où les paiements seront versés ;
   - l’acceptation des conditions de service de Stripe.
4. À la fin, Stripe vous ramène sur Assokit. La page affiche **« ✓ Paiements en ligne actifs »**, ou **« Stripe vérifie vos informations »** (en général quelques minutes, parfois un ou deux jours ouvrés : un e-mail de Stripe vous prévient, la page se met à jour toute seule).
5. Stripe demande encore quelque chose ? La page **« Paiements en ligne »** le liste ; cliquez sur **« Reprendre la configuration sur Stripe »**.

## 2. Choisir ce qui se paie en ligne

Sur la même page, rubrique **« Ce qui se paie en ligne »** :

| Case | Effet |
|---|---|
| **Factures** | Bouton « Payer en ligne » sur la page de chaque facture à régler, lien dans l’e-mail d’envoi et dans les relances, QR code sur le PDF. |
| **Cotisations** | Lien de paiement (et QR code) de chaque campagne ; lien **personnel** de chaque adhérent dans les rappels de cotisation. |
| **Dons** | Page de dons à montant libre pour les campagnes de type « Dons ». |
| **Prélèvement SEPA** | Proposé en plus de la carte (paiements en euros). La banque le confirme sous quelques jours ouvrés. À activer d’abord dans votre tableau de bord Stripe (Paramètres › Moyens de paiement). |

Cliquez sur **« Enregistrer »**.

## 3. Faire payer une facture

Rien à faire de plus : dès que la case « Factures » est cochée, chaque facture **en attente** ou **en retard** affiche « 💳 Payer en ligne » sur sa page (le lien de l’e-mail), et l’e-mail d’envoi et les relances contiennent « Payer en ligne par carte bancaire… » avec le lien. Vous pouvez placer le lien où vous voulez dans vos modèles d’e-mail avec la variable `{LIEN_PAIEMENT}` (Facturation › Paramètres).

Quand le client a payé : la facture passe **« Payée »** (règlement « Carte bancaire » ou « Prélèvement », référence Stripe), les relances s’arrêtent, le client reçoit un reçu et vous êtes prévenu (cloche et e-mail « 💳 Paiement en ligne reçu »).

## 4. Faire payer une cotisation

1. **Cotisations ›** la campagne › bloc **« Paiement en ligne par carte »**.
2. **Copier le lien** et partagez-le (e-mail, site, réseaux), ou **téléchargez le QR code** pour une affiche ou un flyer.
3. L’adhérent choisit son tarif (ou un montant libre si vous l’avez proposé dans ✏️ Modifier), indique son nom et son e-mail, et paie.
4. Le paiement apparaît dans la campagne (« 💳 CB (Stripe) », avec les frais Stripe).

**Lien personnel** : dans le même bloc, choisissez un adhérent pour obtenir **son** lien (nom et e-mail préremplis). Payé par ce lien, la cotisation **prolonge son adhésion** automatiquement. Les **rappels de cotisation** (Relances) envoient déjà ce lien personnel à chaque adhérent concerné (variable `{LIEN_COTISATION}`). Un adhérent qui se connecte à Assokit voit aussi « Mon adhésion › Renouveler ma cotisation » sur son tableau de bord quand son adhésion arrive à échéance.

Un paiement fait avec le lien général (sans lien personnel) est enregistré au nom indiqué par le payeur, sans être rattaché à une fiche : rattachez-le vous-même si besoin, comme un chèque reçu.

## 5. Recevoir des dons

1. **Cotisations › Nouvelle campagne**, type **« Dons »** (un don ne renouvelle jamais l’adhésion).
2. Montant minimum facultatif (1 € par défaut, 10 000 € au plus en ligne).
3. Partagez le lien ou le QR code de la campagne : le donateur choisit son montant et paie.
4. Comptabilité : les dons sont enregistrés à part (compte 754100 « Dons manuels » dans l’export FEC).

---

## Remboursements, contestations, déconnexion

- **Rembourser** : dans **votre** tableau de bord Stripe (Paiements › le paiement › Rembourser). Assokit se met à jour tout seul : une cotisation passe « Remboursé » ; une facture repasse « à payer » (établissez un **avoir** si la vente est annulée). Un remboursement partiel est noté, la facture reste payée (avoir partiel à établir).
- **Contestation** (le payeur conteste auprès de sa banque) : Assokit vous prévient ; répondez dans votre tableau de bord Stripe (Paiements › Litiges) avant la date indiquée.
- **Paiement reçu pour une facture déjà réglée** : Assokit vous prévient (« Paiement en ligne à rembourser ») ; remboursez-le depuis Stripe.
- **Déconnecter** : Paiements en ligne › « Déconnecter ». Les boutons « Payer en ligne » disparaissent. Votre compte Stripe et son argent restent à vous ; vous pouvez le relier de nouveau à tout moment.

## Comptabilité

Un paiement en ligne est enregistré comme un paiement saisi à la main (même montant, même date), et l’export FEC ajoute les **frais Stripe** de chaque paiement (compte 627800). Les virements de Stripe vers votre banque regroupent plusieurs paiements, nets des frais : votre comptable les rapproche à partir du tableau de bord Stripe (Soldes › Virements).

## Application mobile

Pour respecter les règles d’Apple et de Google, **rien ne se paie dans l’application** : les liens de paiement s’ouvrent dans le navigateur du téléphone, et les fiches facture et campagne proposent « Partager le lien de paiement ». La configuration (page « Paiements en ligne ») se fait sur le site.

## Questions fréquentes

- **Le payeur voit-il Assokit ?** La page de paiement est celle de Stripe, au nom de votre association. Le reçu est envoyé au nom de l’association.
- **Assokit voit-il les cartes ?** Non. Ni Assokit ni l’association n’ont accès aux données de carte ou à l’IBAN des payeurs : seul Stripe les traite.
- **Et si Stripe refuse ou suspend notre compte ?** Le paiement en ligne n’est plus proposé ; les paiements par chèque, virement ou espèces continuent de s’enregistrer comme avant. Voyez avec Stripe depuis votre tableau de bord.
- **Une question ?** Assokit › Support, ou contact@assokit.fr.
