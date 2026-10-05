<?php
/**
 * 40 — Messagerie d'équipe (canaux) et tickets de support.
 * ------------------------------------------------------------------
 * Huit canaux : publics, privés et un canal d'annonces où seule la
 * direction écrit. Thomas (adhérent) et Claire (financeuse) ne sont
 * membres d'aucun canal privé : se connecter avec leurs comptes montre
 * la confidentialité. Chaque compte de connexion a quelques messages non
 * lus, jamais des dizaines.
 *
 * Support : uniquement des tickets résolus ou clos, sans assignation —
 * rien n'entre dans la file du fondateur. Le dernier porte une réponse
 * non lue, pour montrer le badge.
 * ------------------------------------------------------------------
 */

/** slug => [nom, icône, couleur, type, description, membres (privés) [clé => rôle]] */
function df_cat_canaux(): array
{
    return [
        'general'               => ['Général', '💬', 'green', 'public', 'Le fil de toute l\'équipe : salariés, bénévoles et adhérents', []],
        'annonces'              => ['Annonces', '📣', 'blue', 'announce', 'Infos officielles du bureau et de la coordination', []],
        'equipe-pedagogique'    => ['Équipe pédagogique', '🎓', 'purple', 'private', 'Formateurs salariés et bénévoles',
            ['admin' => 'moderator', 'salarie' => 'moderator', 'benevole' => 'member', 'formatrice' => 'member', 'insertion' => 'member', 'fle2' => 'member', 'devoirs' => 'member', 'numerique2' => 'member']],
        'formations-entreprises'=> ['Formations entreprises', '💼', 'teal', 'private', 'Devis, sessions et facturation des clients',
            ['admin' => 'moderator', 'salarie' => 'moderator', 'formatrice' => 'member', 'insertion' => 'member', 'tresoriere' => 'member']],
        'bureau'                => ['Bureau', '🏛️', 'red', 'private', 'Échanges du bureau de l\'association',
            ['admin' => 'moderator', 'secretaire' => 'member', 'tresoriere' => 'member', 'vicepres' => 'member', 'salarie' => 'member']],
        'benevoles'             => ['Bénévoles', '🤝', 'amber', 'public', 'Planning et entraide des bénévoles', []],
        'parcours-numerique'    => ['Parcours numérique', '💻', 'pink', 'public', 'Questions et entraide des apprenants des ateliers', []],
        'evenements'            => ['Événements', '🎉', 'purple', 'public', 'Organisation des événements de l\'association', []],
    ];
}

/** slug => [[auteur, jour, heure, texte, réponse au n-ième message du fil (index) | null], …] */
function df_cat_fils(): array
{
    return [
        'general' => [
            ['admin', -44, '09:10', 'Bonne rentrée à toutes et à tous ! 🍂 Le planning des ateliers est en ligne dans l\'agenda.', null],
            ['accueil', -43, '11:20', 'La salle Lovelace a de nouveau du chauffage, merci à la mairie 😅', null],
            ['salarie', -40, '17:45', 'Rappel : les clés de la salle informatique sont à l\'accueil, pensez à les rendre le soir.', null],
            ['evenements', -36, '10:05', 'Photos de la soirée de rentrée disponibles, merci à tous pour l\'ambiance !', null],
            ['formatrice', -33, '14:30', 'Le vidéoprojecteur de la salle Hopper est réparé.', null],
            ['membre', -30, '18:10', 'Merci pour l\'accueil, je me sens déjà chez moi ici 🙂', null],
            ['admin', -30, '20:02', '@thomas bienvenue parmi nous !', 5],
            ['logistique', -26, '09:15', 'Livraison des chaises pour la salle polyvalente jeudi matin.', null],
            ['communication', -22, '16:40', 'Nouvelle page Instagram : **@demof.formation** 📸 Partagez !', null],
            ['civique', -20, '11:05', 'Les attestations Pass Numérique du mois sont prêtes à l\'accueil.', null],
            ['salarie', -17, '09:30', 'Qui a emprunté le paperboard ? 😄', null],
            ['numerique2', -17, '10:12', 'Moi, je le rapporte demain, désolé !', 10],
            ['admin', -14, '19:30', 'Merci à **toute l\'équipe** pour ce mois de septembre record : 41 nouveaux adhérents 🎉', null],
            ['fle2', -12, '15:20', 'Petite victoire : deux apprenantes ont réussi leur entretien d\'embauche cette semaine 💪', null],
            ['accueil', -10, '09:40', 'Le café est offert ce matin à l\'accueil ☕', null],
            ['insertion', -8, '17:15', 'Nouvelle entreprise partenaire pour le job dating : une enseigne de logistique à Wissous.', null],
            ['evenements', -6, '12:30', 'Qui peut prêter des rallonges pour les portes ouvertes ?', null],
            ['logistique', -6, '13:10', 'J\'en ai trois, je les apporte.', 16],
            ['communication', -5, '10:00', 'Les affiches des portes ouvertes sont imprimées, je les dépose dans les commerces samedi.', null],
            ['formatrice', -3, '16:50', 'Atelier smartphone de mercredi : 14 inscrits, on refait une session en janvier ?', null],
            ['admin', -2, '18:40', 'Oui ! Bonne idée @élodie', 19],
            ['salarie', -1, '09:05', 'Réunion d\'équipe lundi : n\'oubliez pas vos points d\'avancement.', null],
            ['accueil', -1, '14:20', 'Trois nouvelles inscriptions FLE aujourd\'hui.', null],
            ['civique', -1, '17:35', 'Le guide « Ma boîte mail en 5 étapes » est imprimé en 50 exemplaires.', null],
            ['admin', 0, '07:52', 'Belle journée à tous, et bon CA ce matin pour les administrateurs ☀️', null],
            ['salarie', 0, '08:15', 'Salle informatique ouverte dès 9 h pour l\'atelier.', null],
        ],
        'annonces' => [
            ['admin', -42, '10:00', '🎉 **Bonne nouvelle** : la Fondation Avenir Solidaire renouvelle son soutien de 20 000 € au parcours emploi des jeunes.', null],
            ['salarie', -35, '09:00', 'Nouveau : les feuilles d\'émargement se signent sur tablette, plus de papier ! ✍️', null],
            ['secretaire', -28, '11:00', 'Le procès-verbal du dernier CA est disponible dans Assemblées.', null],
            ['admin', -21, '18:30', 'Audit Qualiopi blanc validé : 30 indicateurs sur 32 conformes 👏', null],
            ['salarie', -14, '09:00', 'Rappel : les notes de frais du mois sont à déposer avant le 5.', null],
            ['admin', -7, '19:00', 'L\'**assemblée générale** aura lieu dans trois semaines à 18 h 30. Convocations envoyées 📨', null],
            ['secretaire', -3, '10:30', 'Les candidatures au conseil d\'administration sont ouvertes jusqu\'à la veille de l\'AG.', null],
            ['salarie', -1, '16:00', 'Portes ouvertes dans 12 jours : toutes les mains sont les bienvenues 🙌', null],
        ],
        'equipe-pedagogique' => [
            ['salarie', -41, '09:20', 'Positionnement FLE des 8 nouveaux apprenants : 5 en A1, 3 en A2.', null],
            ['benevole', -41, '10:02', 'Je prends les A1 le mardi, @hélène tu gardes le samedi ?', 0],
            ['fle2', -41, '11:15', 'Oui, parfait.', 1],
            ['formatrice', -37, '14:00', 'Session Pix : 12 candidats, je réserve la salle informatique.', null],
            ['salarie', -33, '17:30', '**Émargements** à signer avant vendredi pour le financeur, merci 🙏', null],
            ['devoirs', -30, '18:45', 'L\'aide aux devoirs démarre lundi au collège, 32 élèves inscrits.', null],
            ['benevole', -26, '19:10', 'Quelqu\'un a des fiches sur le vocabulaire de la santé (niveau A2) ?', null],
            ['formatrice', -26, '20:05', 'Oui, je te les dépose dans le projet FLE.', 6],
            ['insertion', -21, '11:30', 'Trois apprenants FLE pro ont un entretien la semaine prochaine, on peut faire des simulations ?', null],
            ['benevole', -21, '13:00', 'Je m\'en occupe jeudi.', 8],
            ['salarie', -16, '09:00', 'Préparation DELF B1 : examen blanc n°2 corrigé, moyenne 63/100 📈', null],
            ['numerique2', -12, '17:20', 'J\'ai préparé un quiz « arnaques en ligne » pour les seniors, je le partage ?', null],
            ['formatrice', -12, '18:00', 'Avec plaisir @antoine', 11],
            ['fle2', -8, '10:40', 'Sortie au musée : 18 inscrits, il manque un accompagnateur.', null],
            ['devoirs', -6, '18:30', 'Fréquentation un peu en baisse cette semaine (sorties scolaires).', null],
            ['salarie', -4, '09:15', 'Rappel : évaluations intermédiaires FLE la semaine prochaine. @julie les grilles sont prêtes ?', null],
            ['benevole', -2, '20:05', 'Oui, déposées dans le projet ✅', 15],
            ['formatrice', -1, '17:40', 'Supports Excel niveau 2 terminés pour la Ville de Démoville.', null],
            ['insertion', -1, '18:10', 'Super, merci Élodie !', 17],
            ['numerique2', 0, '08:05', 'Je serai en retard de 10 min à l\'atelier, @inès peux-tu accueillir le groupe ?', null],
        ],
        'formations-entreprises' => [
            ['salarie', -40, '10:00', 'Devis envoyé à la Ville de Démoville : Excel intermédiaire, 2 jours.', null],
            ['tresoriere', -38, '14:20', 'Facture du CCAS de Villebon réglée, merci !', null],
            ['salarie', -33, '09:30', 'La clinique demande une session Word en novembre.', null],
            ['formatrice', -33, '11:00', 'Je suis disponible les 12 et 13.', 2],
            ['insertion', -27, '16:10', 'L\'OPCO prend en charge la formation des salariés de Logistique Orly-Sud 👍', null],
            ['salarie', -20, '09:45', '**Devis signé** par la Ville de Démoville 🎉 Je crée le projet.', null],
            ['tresoriere', -15, '18:00', 'Deux factures en retard chez des PME, je lance les relances depuis Assokit.', null],
            ['salarie', -9, '10:30', 'Nouvelle demande : certification CléA pour 8 salariés d\'une entreprise d\'insertion.', null],
            ['admin', -9, '19:20', 'Excellent, c\'est exactement le développement qu\'on visait.', 7],
            ['formatrice', -4, '16:00', 'Module Word terminé pour la Ville : 4,7/5 de satisfaction.', null],
            ['tresoriere', -2, '11:15', 'La facture du module Word est partie, échéance à 45 jours (mandat administratif).', null],
            ['salarie', 0, '08:20', 'Bilan du trimestre : 5 formations vendues, chiffre d\'affaires en hausse de 30 %.', null],
        ],
        'bureau' => [
            ['admin', -43, '20:00', 'Ordre du jour du prochain CA : budget, Qualiopi, recrutement d\'un CDD FLE.', null],
            ['tresoriere', -39, '19:15', 'Le budget prévisionnel est prêt : 248 000 €, équilibré.', null],
            ['secretaire', -35, '18:30', 'Convocation du CA envoyée.', null],
            ['vicepres', -28, '21:00', 'Pour le CDD de Yanis : renouvellement conseillé, ses résultats PLIE sont excellents.', null],
            ['admin', -28, '21:30', 'D\'accord, on le vote au CA.', 3],
            ['tresoriere', -21, '18:45', 'Dossier FDVA : bilan à rendre bientôt, je m\'en occupe.', null],
            ['salarie', -14, '09:10', 'Le stage de remobilisation dépasse son budget (92 %). Piste : demande complémentaire au FDVA.', null],
            ['admin', -12, '19:40', 'Je le passe en « à surveiller » et on en parle mardi.', 6],
            ['secretaire', -6, '20:15', 'Rapport d\'activité rédigé à 80 %, il manque les chiffres de l\'emploi.', null],
            ['tresoriere', -3, '19:00', 'Comptes arrêtés : léger excédent, fonds associatif consolidé.', null],
            ['vicepres', -1, '21:05', 'Bravo Nadia 👏', 9],
            ['admin', -1, '21:30', 'Merci à tous, rendez-vous demain matin pour le CA en visio.', null],
        ],
        'benevoles' => [
            ['accueil', -44, '10:00', 'Planning des permanences d\'accueil du mois : il reste deux créneaux le jeudi.', null],
            ['evenements', -44, '12:30', 'Je prends le jeudi matin.', 0],
            ['devoirs', -38, '18:00', 'Appel à bénévoles pour l\'aide aux devoirs : 18 élèves le lundi pour 3 bénévoles 😅', null],
            ['accueil', -37, '09:15', 'Je peux venir le lundi à partir de la semaine prochaine.', 2],
            ['salarie', -30, '17:00', 'Formation des bénévoles « accueillir un public allophone » : 9 inscrits.', null],
            ['emploi', -25, '19:20', 'Les ateliers CV du mercredi ont trouvé leur rythme 👍', null],
            ['logistique', -18, '10:40', 'Qui peut m\'aider à monter les stands du forum samedi ?', null],
            ['numerique2', -18, '11:00', 'Présent !', 6],
            ['evenements', -10, '18:30', 'Merci pour la soirée de rentrée, on était 60 ! 🎉', null],
            ['insertion', -5, '16:45', 'Appel à **2 bénévoles** pour l\'accueil du forum emploi samedi.', null],
            ['benevole', -4, '20:10', 'Je peux le matin.', 9],
            ['evenements', -2, '12:00', 'Je prends l\'après-midi 🙋‍♀️', 9],
            ['accueil', -1, '15:30', 'Petit rappel : le badge d\'accès est à rendre en fin de permanence.', null],
            ['devoirs', -1, '19:45', 'Rencontre avec les parents d\'élèves fixée au mardi 18 h.', null],
            ['logistique', 0, '07:58', 'Les tables du forum sont dans la camionnette ✅', null],
        ],
        'parcours-numerique' => [
            ['formatrice', -40, '10:00', 'Bienvenue sur le canal des ateliers numériques ! Posez vos questions ici 🙂', null],
            ['membre', -30, '18:20', 'Comment je fais pour joindre mon CV à une candidature en ligne ?', null],
            ['formatrice', -30, '19:00', '@thomas clique sur « Parcourir », choisis ton fichier PDF, puis « Envoyer ». On le refait ensemble mercredi !', 1],
            ['civique', -24, '11:10', 'Le guide « Ma boîte mail en 5 étapes » est disponible à l\'accueil.', null],
            ['membre', -18, '17:30', 'J\'ai réussi à créer mon compte Ameli tout seul 🎉', null],
            ['numerique2', -18, '18:00', 'Bravo Thomas 👏', 4],
            ['formatrice', -12, '09:30', 'Rappel : apportez vos identifiants FranceConnect à l\'atelier démarches.', null],
            ['membre', -6, '20:15', 'Merci pour l\'atelier de mercredi, très clair.', null],
            ['civique', -3, '10:45', 'Nouvelle session « premiers pas » en janvier, inscriptions ouvertes.', null],
            ['formatrice', -1, '18:20', '@thomas tu veux bien témoigner pour notre fiche de communication ?', null],
            ['numerique2', 0, '08:10', 'Pensez à recharger vos téléphones pour l\'atelier de ce matin 📱', null],
        ],
        'evenements' => [
            ['evenements', -42, '10:00', 'Programme des événements du trimestre : forum emploi, portes ouvertes, remise des certificats, soirée solidaire.', null],
            ['communication', -30, '11:30', 'Les visuels des portes ouvertes sont prêts, je les publie lundi.', null],
            ['insertion', -21, '16:00', 'Forum emploi : 15 entreprises confirmées ✅', null],
            ['evenements', -14, '18:00', 'Café des langues : 22 inscrits, c\'est complet !', null],
            ['admin', -9, '19:10', 'Merci à l\'équipe événements, vous êtes formidables 🙏', null],
            ['communication', -4, '10:20', 'Invitations des portes ouvertes envoyées à tous les adhérents.', null],
            ['evenements', -1, '17:50', 'Remise des certificats Pix & DELF : la salle des fêtes est réservée.', null],
        ],
    ];
}

function df_seed_messages(): void
{
    if (!df_a_table('channels')) return;
    $u = DF::$u;
    $qui = fn(string $k) => $u[$k] ?? $u['admin'];
    $publics = df_tous_membres();
    $canaux = [];
    $pos = 0;
    foreach (df_cat_canaux() as $slug => [$nom, $icone, $couleur, $type, $desc, $membres]) {
        $cree = df_jh(-df_entre(90, 400), '10:00');
        $cid = df_ins('channels', [
            'org_id' => DF::$org, 'parent_org_id' => null, 'created_by' => $u['admin'], 'name' => $nom, 'slug' => $slug,
            'description' => $desc, 'icon' => $icone, 'color_theme' => $couleur, 'type' => $type, 'is_mairie_channel' => 0,
            'position' => $pos++, 'is_archived' => 0, 'created_at' => $cree, 'updated_at' => $cree,
        ]);
        if (!$cid) continue;
        $canaux[$slug] = ['id' => $cid, 'nom' => $nom, 'prive' => $type === 'private', 'membres' => []];
        df_retenir('canaux', $slug, $cid);
        foreach ($membres as $k => $role) {
            if (empty($u[$k])) continue;
            df_ins('channel_members', ['channel_id' => $cid, 'user_id' => $u[$k], 'role' => $role, 'joined_at' => $cree]);
            $canaux[$slug]['membres'][] = $u[$k];
        }
    }

    // Messages, fil par fil, dans l'ordre chronologique.
    $fils = df_cat_fils();
    $ids = [];
    foreach ($fils as $slug => $messages) {
        if (empty($canaux[$slug])) continue;
        $cid = $canaux[$slug]['id'];
        usort($messages, fn($a, $b) => [$a[1], $a[2]] <=> [$b[1], $b[2]]);
        $locaux = [];
        foreach ($messages as $i => [$auteur, $j, $h, $texte, $rep]) {
            $quand = df_passe($j, $h);
            $edite = df_proba(0.06);
            $mid = df_ins('channel_messages', [
                'channel_id' => $cid, 'user_id' => $qui($auteur), 'content' => $texte, 'is_edited' => $edite ? 1 : 0,
                'is_pinned' => 0, 'reply_to_message_id' => $rep !== null ? ($locaux[$rep] ?? null) : null,
                'created_at' => $quand, 'updated_at' => $edite ? date('Y-m-d H:i:s', strtotime($quand) + 60 * df_entre(3, 20)) : $quand,
                'deleted_at' => null,
            ]);
            $locaux[$i] = $mid;
            if ($mid) $ids[$slug][] = [$mid, $qui($auteur), $quand, $texte, $auteur];
        }
        if (!empty($ids[$slug])) df_maj('channels', ['updated_at' => end($ids[$slug])[2]], 'id = ?', [$cid]);
    }
    DF::$ids['messages_canaux'] = $ids;

    // Lectures : une ligne par canal visible et par compte de connexion, avec quelques non-lus.
    $nonLus = [
        'admin'     => ['bureau' => 2, 'formations-entreprises' => 1, 'evenements' => 1],
        'salarie'   => ['equipe-pedagogique' => 4, 'formations-entreprises' => 1, 'benevoles' => 2],
        'benevole'  => ['benevoles' => 5, 'equipe-pedagogique' => 2],
        'membre'    => ['parcours-numerique' => 2, 'annonces' => 1, 'evenements' => 1],
        'financeur' => ['annonces' => 1],
    ];
    foreach ($nonLus as $k => $cibles) {
        $uid = $u[$k];
        foreach ($canaux as $slug => $c) {
            if ($c['prive'] && !in_array($uid, $c['membres'], true)) continue;
            $liste = $ids[$slug] ?? [];
            if (!$liste) continue;
            $n = $cibles[$slug] ?? 0;
            $lu = count($liste);
            while ($n > 0 && $lu > 0) {
                if ($liste[$lu - 1][1] !== $uid) $n--;
                $lu--;
            }
            $dernier = $lu > 0 ? $liste[$lu - 1] : null;
            df_ins('channel_reads', ['channel_id' => $c['id'], 'user_id' => $uid, 'last_read_message_id' => $dernier ? $dernier[0] : 0,
                                     'last_read_at' => $dernier ? date('Y-m-d H:i:s', min(time() - 120, strtotime($dernier[2]) + 60 * df_entre(2, 30))) : df_jh(-30, '09:00')]);
        }
    }

    df_seed_support();
}

/** Tickets de support : résolus ou clos, jamais dans la file active du fondateur. */
function df_seed_support(): void
{
    if (!df_a_table('support_tickets')) return;
    $u = DF::$u;
    $fondateur = (int)df_val("SELECT id FROM users WHERE is_founder = 1 AND is_active = 1 ORDER BY id LIMIT 1") ?: null;
    $tickets = [
        // [auteur, jour, titre, catégorie, priorité, statut, messages [[côté, minutes après, texte]], fermé après (jours)]
        ['admin', -118, 'Importer nos adhérents depuis notre ancien fichier Excel', 'question', 'normal', 'closed', [
            ['org', 0, 'Bonjour, nous avons 132 adhérents dans un fichier Excel. Comment les importer sans tout ressaisir ?'],
            ['support', 37, 'Bonjour Sophie ! Enregistrez votre fichier en CSV, puis Adhérents › Importer : les colonnes Prénom, Nom et E-mail suffisent. Le téléphone et la ville sont repris automatiquement.'],
            ['org', 78, '132 adhérents importés en 5 minutes 👏 Merci !'],
        ], 7],
        ['tresoriere', -74, 'Mention d\'exonération de TVA sur nos factures de formation', 'billing', 'high', 'resolved', [
            ['org', 0, 'Nos factures de formation doivent porter la mention d\'exonération de TVA. Où la paramétrer ?'],
            ['support', 42, 'Bonjour Nadia, comme votre association n\'est pas assujettie, la mention « TVA non applicable » est ajoutée automatiquement. Vous pouvez la compléter dans Facturation › Paramètres.'],
            ['org', 61, 'Parfait, c\'est réglé. Merci pour la rapidité !'],
        ], null],
        ['salarie', -41, 'Suggestion : export des feuilles d\'émargement pour l\'OPCO', 'feature_request', 'low', 'resolved', [
            ['org', 0, 'L\'OPCO nous demande les feuilles d\'émargement signées. Un export PDF serait idéal.'],
            ['support', 1440, 'Merci Karim pour la suggestion, je la transmets à l\'équipe produit.'],
            ['support', 31680, 'Bonne nouvelle : c\'est en ligne ! Émargement › Exporter, en PDF avec les signatures.'],
            ['org', 31750, 'Top, merci !'],
        ], null],
        ['salarie', -9, 'Une bénévole ne reçoit pas son e-mail d\'invitation', 'account', 'normal', 'resolved', [
            ['org', 0, 'Une nouvelle bénévole ne reçoit pas l\'invitation à créer son mot de passe.'],
            ['support', 35, 'Bonjour Karim, l\'adresse saisie contient une faute de frappe (« gmial »). Corrigez-la dans sa fiche puis renvoyez l\'invitation.'],
            ['org', 52, 'C\'était ça, merci !'],
        ], null],
        ['admin', -3, 'Radar subventions : être alerté des appels à projets FSE+', 'question', 'normal', 'resolved', [
            ['org', 0, 'Peut-on recevoir une alerte dès qu\'un appel à projets FSE+ correspond à notre profil ?'],
            ['support', 1390, 'Bonjour Sophie ! Oui : dans le Radar subventions, ouvrez « Alertes » et cochez « Nouvelles pistes ». Vous serez prévenue dans Assokit dès qu\'un dispositif correspond à votre profil.'],
        ], null],
    ];
    foreach ($tickets as $n => [$k, $j, $titre, $cat, $prio, $statut, $msgs, $fermeApres]) {
        $debut = strtotime(df_jh($j, sprintf('%02d:%02d', 9 + $n, 12)));
        $dernier = end($msgs);
        $fin = $debut + 60 * $dernier[1];
        $tid = df_ins('support_tickets', [
            'org_id' => DF::$org, 'created_by_user_id' => $u[$k], 'title' => $titre, 'category' => $cat, 'priority' => $prio,
            'status' => $statut, 'assigned_to_user_id' => null, 'last_message_at' => date('Y-m-d H:i:s', $fin),
            'last_message_by' => $dernier[0], 'resolved_at' => date('Y-m-d H:i:s', $fin + 60),
            'resolved_by_user_id' => $dernier[0] === 'org' ? $u[$k] : $fondateur,
            'closed_at' => $fermeApres ? date('Y-m-d H:i:s', $fin + 86400 * $fermeApres) : null,
            'created_at' => date('Y-m-d H:i:s', $debut), 'updated_at' => date('Y-m-d H:i:s', $fin + 60),
        ]);
        if (!$tid) continue;
        foreach ($msgs as $i => [$cote, $min, $texte]) {
            $dernierSupport = ($n === count($tickets) - 1 && $i === count($msgs) - 1);
            df_ins('support_messages', ['ticket_id' => $tid, 'author_user_id' => $cote === 'org' ? $u[$k] : $fondateur,
                                        'author_side' => $cote, 'body' => $texte, 'is_internal_note' => 0,
                                        'read_by_org' => $dernierSupport ? 0 : 1, 'read_by_support' => 1,
                                        'created_at' => date('Y-m-d H:i:s', $debut + 60 * $min)]);
        }
        df_ins('support_ticket_events', ['ticket_id' => $tid, 'user_id' => $u[$k], 'event_type' => 'created', 'from_value' => null,
                                         'to_value' => 'open', 'note' => null, 'created_at' => date('Y-m-d H:i:s', $debut)]);
        df_ins('support_ticket_events', ['ticket_id' => $tid, 'user_id' => $fondateur, 'event_type' => 'resolved', 'from_value' => 'open',
                                         'to_value' => 'resolved', 'note' => null, 'created_at' => date('Y-m-d H:i:s', $fin + 60)]);
        if ($fermeApres) df_ins('support_ticket_events', ['ticket_id' => $tid, 'user_id' => $fondateur, 'event_type' => 'closed', 'from_value' => 'resolved',
                                                          'to_value' => 'closed', 'note' => null, 'created_at' => date('Y-m-d H:i:s', $fin + 86400 * $fermeApres)]);
    }
}
