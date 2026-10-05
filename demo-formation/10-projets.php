<?php
/**
 * 10 — Projets : dossiers, projets, étapes, équipes, conversations,
 * fichiers, dépenses, historique, partage public, documents IA, archives.
 * ------------------------------------------------------------------
 * Tout part de catalogue.php. Les dates suivent le calendrier de chaque
 * projet, et l'activité récente est dosée pour que le tableau de bord
 * raconte quelque chose : le FLE accélère, l'aide aux devoirs ralentit,
 * deux projets passent « à surveiller » pour cause de budget.
 *
 * Registre rempli : DF::$ids['dossiers'][clé], DF::$ids['projets'][clé],
 * DF::$ids['etapes_ouvertes'][clé projet] = [ids], DF::$ids['messages'][clé projet] = [ids].
 * ------------------------------------------------------------------
 */

/** Conversations : clé projet => [[auteur, jour, 'HH:MM', texte], …]. */
function df_cat_messages(): array
{
    return [
        'fle-automne' => [
            ['benevole', -70, '18:12', 'Bonsoir à tous ! Les tests de positionnement sont terminés : 24 inscrits, 9 en A1, 10 en A1+, 5 déjà proches de l\'A2. Je propose trois groupes.'],
            ['salarie', -69, '09:05', 'Parfait Julie. Je réserve les salles du centre social : mardi et jeudi matin, plus le samedi pour le groupe du soir ?'],
            ['fle2', -69, '10:40', 'Le samedi matin m\'arrange, je prends le groupe A1+.'],
            ['admin', -66, '20:15', 'Bravo pour ce démarrage ! La convention avec le centre social est signée, je l\'ai déposée dans les fichiers.'],
            ['benevole', -60, '17:30', 'Premier mois : 92 % de présence. Les apprenants adorent les mises en situation (pharmacie, CAF, entretien).'],
            ['accueil', -55, '11:02', 'Deux nouvelles inscriptions à l\'accueil ce matin, liste d\'attente ouverte.'],
            ['salarie', -48, '14:20', 'Sortie au Musée de l\'histoire de l\'immigration validée pour le 12, 18 inscrits. Les billets sont pris en charge par le budget « sorties ».'],
            ['benevole', -45, '19:48', 'Retour de la sortie : super moment, plusieurs apprenants ont présenté une œuvre en français devant le groupe 👏'],
            ['fle2', -30, '10:05', 'Les manuels « Ensemble A2 » sont arrivés, 12 exemplaires. Facture ajoutée dans les dépenses.'],
            ['admin', -20, '21:00', 'La Fondation Avenir Solidaire nous demande un témoignage d\'apprenant pour son rapport annuel. Quelqu\'un a une idée ?'],
            ['benevole', -19, '08:50', '@Sophie Amina (groupe A1+) est partante, elle a trouvé un emploi d\'aide à domicile grâce au parcours !'],
            ['salarie', -13, '09:12', 'Point d\'étape : 5 étapes sur 8 bouclées. Prochaine : évaluations intermédiaires. @Julie tu as la main ?'],
            ['benevole', -12, '18:40', 'Oui, je prépare les grilles cette semaine. Je m\'inspire du format DILF.'],
            ['fle2', -6, '10:30', 'Mon groupe a progressé sur l\'oral, je l\'ai noté dans le suivi. Hâte de voir les évaluations.'],
            ['accueil', -5, '15:10', 'Liste d\'attente : 7 personnes. On pourrait ouvrir un 4e groupe en janvier ?'],
            ['admin', -4, '19:25', 'Excellente idée. Karim, peux-tu chiffrer un 4e groupe pour le dossier de subvention de la Région ?'],
            ['salarie', -3, '09:40', 'C\'est fait : 4 200 € pour 12 apprenants sur 20 semaines. J\'ai mis le chiffrage dans les fichiers.'],
            ['benevole', -2, '20:05', 'Grilles d\'évaluation prêtes, je les dépose demain. Évaluations la semaine prochaine 💪'],
            ['fle2', -1, '11:15', 'Top ! Je ferai passer l\'oral à mon groupe samedi.'],
            ['salarie', 0, '08:35', 'Rappel : les attestations de présence du mois sont à signer avant vendredi pour le financeur.'],
        ],
        'delf-b1' => [
            ['fle2', -58, '17:00', '14 candidats au DELF B1 cette année, dont 9 pour leur dossier de naturalisation.'],
            ['benevole', -40, '18:30', 'Examen blanc n°1 : 11 sur 14 au-dessus de 50. Très encourageant.'],
            ['fle2', -22, '16:45', 'Examen blanc n°2 : moyenne 63/100. Les productions orales sont nettement meilleures.'],
            ['salarie', -15, '10:00', 'Inscriptions à la session officielle envoyées à l\'Alliance française. Frais pris en charge pour 6 candidats au tarif solidaire.'],
            ['fle2', -4, '18:10', 'Dernière ligne droite : séances d\'entraînement intensif le samedi jusqu\'à l\'examen.'],
        ],
        'fle-pro' => [
            ['formatrice', -45, '09:30', 'Lancement du FLE pro : 12 participants, surtout orientés aide à domicile et logistique.'],
            ['insertion', -32, '14:00', 'Deux entreprises partenaires acceptent d\'accueillir les stages d\'observation.'],
            ['formatrice', -14, '11:20', 'Attention : le budget est presque consommé, la location des salles aux Ulis coûte plus cher que prévu.'],
            ['admin', -9, '19:00', 'J\'ai passé le projet en « à surveiller ». Karim, on en parle au prochain point ?'],
            ['salarie', -8, '09:15', 'Oui. Piste : rapatrier deux séances dans nos locaux de Massy pour économiser 900 €.'],
        ],
        'pass-numerique' => [
            ['formatrice', -130, '10:00', 'La convention avec le Département est signée : 120 Pass Numérique pour la saison 🎉'],
            ['financeur', -120, '15:30', 'La Fondation Avenir Solidaire confirme son cofinancement. Ravie de suivre ce projet de près !'],
            ['numerique2', -100, '18:00', 'Les 12 postes de la salle informatique sont installés et configurés.'],
            ['formatrice', -60, '17:45', '8 groupes « premiers pas » terminés : 96 bénéficiaires, 4,8/5 de satisfaction.'],
            ['civique', -35, '11:10', 'J\'ai créé un petit guide illustré « Ma boîte mail en 5 étapes », il plaît beaucoup !'],
            ['financeur', -21, '16:20', 'Merci pour le bilan intermédiaire, très clair. La Fondation valide le versement du 2e acompte.'],
            ['formatrice', -10, '09:40', 'Ateliers « démarches en ligne » : 102 personnes ont créé leur compte Ameli ou CAF elles-mêmes.'],
            ['membre', -6, '20:30', 'Je voulais dire merci : grâce aux ateliers je fais maintenant mes démarches tout seul et j\'ai pu postuler en ligne.'],
            ['formatrice', -5, '08:55', 'Merci Thomas, ça nous touche ! Accepterais-tu de témoigner pour la fiche de communication ?'],
            ['membre', -5, '12:10', 'Avec plaisir 🙂'],
            ['salarie', -2, '14:00', '113 bénéficiaires sur 120 : on atteindra l\'objectif avant la fin du trimestre.'],
        ],
        'permanences' => [
            ['insertion', -150, '09:00', 'Permanences ouvertes à l\'Espace France Services : mardi et jeudi, 9 h – 12 h.'],
            ['accueil', -90, '12:30', 'Record ce matin : 19 personnes reçues, beaucoup pour la déclaration de revenus.'],
            ['numerique2', -40, '17:15', 'Formation FranceConnect faite pour les 4 nouveaux bénévoles.'],
            ['insertion', -11, '10:20', '310 personnes accompagnées depuis janvier. Je prépare le rapport pour les partenaires.'],
            ['membre', -3, '18:00', 'Je peux venir aider jeudi matin si besoin, je commence à bien connaître le site de la CAF.'],
        ],
        'smartphone' => [
            ['formatrice', -150, '10:00', 'Six séances programmées à la résidence Les Tilleuls, 36 inscrits.'],
            ['evenements', -60, '16:00', 'Dernière séance : les résidents ont fait une visio avec leurs petits-enfants, beaucoup d\'émotion !'],
            ['formatrice', -21, '09:30', 'Questionnaire : 97 % de satisfaction. Je clôture le projet.'],
        ],
        'plie' => [
            ['insertion', -105, '09:00', 'Démarrage de l\'accompagnement renforcé : 40 participants orientés par le PLIE.'],
            ['financeur', -90, '14:00', 'Bonjour à l\'équipe, je serai votre interlocutrice à la Fondation pour ce projet.'],
            ['emploi', -70, '18:20', 'Premiers ateliers CV : très bonne dynamique de groupe.'],
            ['insertion', -40, '11:00', '12 immersions en entreprise réalisées (PMSMP), 5 se transforment en promesse d\'embauche.'],
            ['admin', -25, '20:00', 'Bravo Yanis ! Ce sont exactement les résultats que le comité de pilotage attend.'],
            ['insertion', -9, '09:45', 'Point : 14 retours à l\'emploi ou en formation qualifiante sur 40, à mi-parcours.'],
            ['financeur', -8, '15:10', 'Excellent. Pouvez-vous m\'envoyer les indicateurs pour notre comité du 15 ?'],
            ['insertion', -7, '09:30', '@Claire c\'est dans les fichiers : « Indicateurs_PLIE_mi-parcours.pdf ».'],
            ['emploi', -1, '18:45', 'Le job dating approche, 9 participants du PLIE sont inscrits.'],
        ],
        'job-dating' => [
            ['insertion', -38, '10:00', '15 entreprises confirmées : logistique, aide à domicile, restauration, BTP.'],
            ['evenements', -20, '17:30', 'Salle Jean-Moulin réservée, café d\'accueil prévu.'],
            ['communication', -12, '11:15', 'Flyers et posts LinkedIn prêts, je publie lundi.'],
            ['emploi', -6, '18:00', 'Simulations d\'entretien : 41 candidats préparés.'],
            ['insertion', -2, '09:05', 'Plus que quelques jours ! Il nous manque 2 bénévoles pour l\'accueil.'],
            ['evenements', -1, '19:30', 'Je m\'inscris pour l\'accueil 🙋‍♀️'],
        ],
        'atelier-cv' => [
            ['salarie', -195, '10:00', 'Cinq ateliers CV & LinkedIn au programme ce printemps.'],
            ['communication', -130, '15:00', 'Séance photo pro : 22 portraits réalisés, les participants sont ravis.'],
            ['salarie', -112, '09:30', 'Bilan : 28 CV refaits, 19 profils LinkedIn créés. Projet clôturé.'],
        ],
        'bureautique-mairie' => [
            ['salarie', -44, '10:00', 'Convention signée avec la Ville de Démoville : 18 agents, Word puis Excel.'],
            ['formatrice', -30, '16:00', 'Tests de niveau faits, deux groupes constitués.'],
            ['prestataire', -16, '17:20', 'Module Word terminé, très bon retour de la DRH.'],
            ['salarie', -10, '09:00', 'Facture du module Word envoyée à la Ville. Module Excel la semaine prochaine.'],
            ['formatrice', -3, '18:00', 'Supports Excel prêts (tableaux croisés, mise en forme conditionnelle).'],
            ['prestataire', 0, '09:10', 'Je confirme ma présence pour les trois jours Excel.'],
        ],
        'excel-ccas' => [
            ['salarie', -18, '10:30', 'Devis accepté par le CCAS de Démoville : trois sessions Excel et outils collaboratifs.'],
            ['numerique2', -7, '17:00', 'Sessions planifiées les 3 prochains mardis. Je m\'occupe de la session 1.'],
        ],
        'clea' => [
            ['salarie', -6, '11:00', 'Nouvelle offre CléA numérique : deux entreprises intéressées, convention à signer.'],
        ],
        'devoirs' => [
            ['devoirs', -33, '18:00', 'Convention avec le collège signée, 32 élèves repérés par les professeurs principaux.'],
            ['civique', -27, '17:30', 'Première séance : ambiance studieuse, les élèves étaient ponctuels.'],
            ['logistique', -24, '19:00', 'Goûters livrés pour le mois, merci à la boulangerie partenaire.'],
            ['devoirs', -12, '18:30', 'Les séances du lundi sont un peu chargées : 18 élèves pour 3 bénévoles.'],
            ['accueil', -11, '17:10', 'Je peux venir le lundi à partir de la semaine prochaine.'],
            ['civique', -10, '18:20', 'Super, merci Samira !'],
            ['devoirs', -9, '20:00', 'Rencontre avec les parents à organiser avant les vacances.'],
            ['devoirs', -2, '18:15', 'Petite baisse de fréquentation cette semaine (sorties scolaires).'],
        ],
        'remobilisation' => [
            ['salarie', -28, '10:00', 'La Mission locale nous oriente 16 jeunes, entretiens d\'entrée faits.'],
            ['insertion', -20, '14:00', 'Le transport vers les entreprises de stage coûte plus que prévu (cars + titres de transport).'],
            ['admin', -12, '19:30', 'Budget engagé à 92 % : je passe le projet en « à surveiller ». On cherche un complément ?'],
            ['salarie', -11, '09:00', 'Je dépose une demande au FDVA fonctionnement-innovation, réponse dans 6 semaines.'],
            ['civique', -4, '16:40', 'Les jeunes ont choisi leur projet collectif : un court-métrage sur leur quartier 🎬'],
        ],
        'ag' => [
            ['admin', -24, '20:00', 'Date de l\'AG fixée, salle des fêtes réservée. Merci de m\'envoyer vos chiffres par pôle.'],
            ['secretaire', -18, '09:30', 'Je centralise les chiffres dans le rapport d\'activité, déjà 60 % rédigé.'],
            ['tresoriere', -10, '18:45', 'Les comptes sont arrêtés, le rapport financier sera prêt pour le CA.'],
            ['communication', -5, '11:00', 'Maquette du rapport d\'activité en cours, avec les photos de l\'année.'],
            ['admin', -1, '21:10', 'Je présente le rapport moral au CA jeudi. Merci à tous pour cette belle année !'],
        ],
        'qualiopi' => [
            ['salarie', -115, '09:00', 'Lancement du renouvellement Qualiopi : autodiagnostic des 32 indicateurs.'],
            ['formatrice', -100, '16:30', 'Livret d\'accueil mis à jour avec les nouvelles formations.'],
            ['communication', -80, '11:00', 'Questionnaires de satisfaction à chaud et à froid en ligne, taux de réponse 78 %.'],
            ['salarie', -60, '10:15', 'Procédure de réclamation formalisée et affichée.'],
            ['admin', -45, '19:00', 'Merci Karim, c\'est un vrai travail de fond. La certification conditionne nos financements.'],
            ['salarie', -30, '09:20', 'Veille réglementaire documentée (indicateurs 23 à 25).'],
            ['formatrice', -16, '17:00', 'Audit blanc : 2 non-conformités mineures, déjà corrigées.'],
            ['salarie', -9, '08:45', 'Il reste les preuves de l\'indicateur 22 (compétences des formateurs). Je les rassemble.'],
            ['formatrice', -6, '14:30', 'J\'ai déposé mes attestations de formation continue dans les fichiers.'],
            ['communication', -5, '10:10', 'Les attestations Pix de l\'équipe sont aussi dans le dossier.'],
            ['salarie', -2, '09:00', 'Audit de renouvellement dans 5 semaines. On est prêts à 90 % 💪'],
            ['admin', 0, '07:55', 'Bravo à toute l\'équipe pour ce sprint Qualiopi !'],
        ],
        'forum-assos' => [
            ['evenements', -392, '18:00', '210 visiteurs sur le stand, 34 adhésions sur la journée !'],
        ],
        'ete-numerique' => [
            ['formatrice', -432, '17:00', 'Fin des ateliers d\'été : 18 jeunes, un mini-site créé par chaque groupe.'],
        ],
        'ete-sorties' => [
            ['fle2', -401, '18:00', 'Trois sorties réussies, merci aux bénévoles accompagnateurs.'],
        ],
    ];
}

/** Fichiers : clé projet => [[nom, type (pdf|jpg|png), auteur, jour, [lignes du document]], …]. */
function df_cat_fichiers(): array
{
    return [
        'fle-automne' => [
            ['Programme_FLE_A1-A2_automne.pdf', 'pdf', 'benevole', -72, ['Trois groupes de niveau, 6 heures par semaine.', 'Compétences visées : comprendre et se faire comprendre dans les situations de la vie quotidienne.', 'Évaluation : DILF / TCF en fin de parcours.']],
            ['Convention_centre_social_signee.pdf', 'pdf', 'admin', -66, ['Mise à disposition de deux salles, mardi et jeudi matin, samedi matin.', 'Durée : de septembre à juin.']],
            ['Liste_groupes_niveaux.pdf', 'pdf', 'benevole', -69, ['Groupe A1 : 9 apprenants.', 'Groupe A1+ : 10 apprenants.', 'Groupe A2 : 5 apprenants.']],
            ['Photo_sortie_musee.jpg', 'jpg', 'benevole', -45, []],
            ['Feuille_emargement_semaine_06.pdf', 'pdf', 'fle2', -20, ['Présences de la semaine 6 : 22 / 24.']],
            ['Chiffrage_4e_groupe_janvier.pdf', 'pdf', 'salarie', -3, ['4e groupe : 12 apprenants, 20 semaines.', 'Coût : 4 200 € (formatrice, supports, salle).']],
            ['Grilles_evaluation_intermediaire.pdf', 'pdf', 'benevole', -1, ['Compréhension orale, production orale, compréhension écrite, production écrite.']],
        ],
        'delf-b1' => [
            ['Resultats_examen_blanc_2.pdf', 'pdf', 'fle2', -22, ['Moyenne : 63 / 100.', 'Production orale : nette progression.']],
            ['Planning_entrainement_intensif.pdf', 'pdf', 'fle2', -4, ['Samedis 9 h – 12 h jusqu\'à l\'examen.']],
        ],
        'fle-pro' => [
            ['Lexique_aide_a_domicile.pdf', 'pdf', 'formatrice', -38, ['200 mots et expressions du métier.']],
            ['Point_budget_FLE_pro.pdf', 'pdf', 'admin', -9, ['Budget prévu : 9 800 €.', 'Consommé : 93 %.']],
        ],
        'pass-numerique' => [
            ['Convention_Departement_Pass_Numerique.pdf', 'pdf', 'formatrice', -130, ['120 chèques Pass Numérique.', 'Période : saison en cours.']],
            ['Bilan_intermediaire_Fondation.pdf', 'pdf', 'formatrice', -22, ['96 bénéficiaires accompagnés.', 'Satisfaction : 4,8 / 5.']],
            ['Guide_ma_boite_mail_en_5_etapes.pdf', 'pdf', 'civique', -35, ['1. Ouvrir sa boîte mail. 2. Lire un message. 3. Répondre. 4. Joindre un document. 5. Se protéger des arnaques.']],
            ['Photo_atelier_premiers_pas.jpg', 'jpg', 'numerique2', -58, []],
            ['Attestations_Pass_Numerique_septembre.pdf', 'pdf', 'formatrice', -8, ['17 attestations signées.']],
        ],
        'permanences' => [
            ['Affiche_permanences.png', 'png', 'communication', -148, []],
            ['Statistiques_permanences_T3.pdf', 'pdf', 'insertion', -11, ['310 personnes reçues depuis janvier.', 'CAF 34 %, impôts 22 %, France Travail 19 %, retraite 11 %, autres 14 %.']],
        ],
        'smartphone' => [
            ['Bilan_ateliers_smartphone.pdf', 'pdf', 'formatrice', -21, ['36 participants, 97 % de satisfaction.']],
        ],
        'plie' => [
            ['Indicateurs_PLIE_mi-parcours.pdf', 'pdf', 'insertion', -7, ['14 sorties positives sur 40 à mi-parcours.', '12 immersions en entreprise.']],
            ['Budget_previsionnel_PLIE.pdf', 'pdf', 'admin', -100, ['Budget : 32 000 €.']],
            ['Convention_PLIE_signee.pdf', 'pdf', 'insertion', -108, ['Accompagnement renforcé de 40 participants sur 12 mois.']],
            ['Planning_immersions_PMSMP.pdf', 'pdf', 'emploi', -45, ['12 immersions planifiées.']],
            ['Photo_atelier_CV.jpg', 'jpg', 'emploi', -70, []],
        ],
        'job-dating' => [
            ['Flyer_job_dating.png', 'png', 'communication', -12, []],
            ['Liste_entreprises_job_dating.pdf', 'pdf', 'insertion', -38, ['15 entreprises : logistique, aide à domicile, restauration, BTP.']],
        ],
        'atelier-cv' => [
            ['Bilan_atelier_CV_LinkedIn.pdf', 'pdf', 'salarie', -112, ['28 CV refaits, 19 profils LinkedIn créés.']],
        ],
        'bureautique-mairie' => [
            ['Convention_Ville_Demoville_signee.pdf', 'pdf', 'salarie', -44, ['18 agents. Word (2 jours), Excel (3 jours).']],
            ['Supports_Word_niveau_1.pdf', 'pdf', 'formatrice', -28, ['Mise en forme, styles, publipostage.']],
            ['Supports_Excel_niveau_2.pdf', 'pdf', 'formatrice', -3, ['Tableaux croisés dynamiques, mise en forme conditionnelle.']],
            ['Evaluations_module_Word.pdf', 'pdf', 'prestataire', -15, ['Satisfaction : 4,7 / 5.']],
            ['Photo_formation_agents.jpg', 'jpg', 'formatrice', -16, []],
        ],
        'excel-ccas' => [
            ['Devis_signe_CCAS.pdf', 'pdf', 'salarie', -18, ['Trois sessions de 3 heures.']],
        ],
        'devoirs' => [
            ['Convention_college_des_Tilleuls.pdf', 'pdf', 'devoirs', -33, ['Deux soirs par semaine, 32 élèves.']],
            ['Planning_benevoles_aide_aux_devoirs.pdf', 'pdf', 'devoirs', -30, ['8 bénévoles, lundi et jeudi.']],
        ],
        'remobilisation' => [
            ['Demande_FDVA_complement.pdf', 'pdf', 'salarie', -11, ['Complément demandé : 3 500 €.']],
        ],
        'ag' => [
            ['Rapport_activite_brouillon.pdf', 'pdf', 'secretaire', -6, ['Une année de croissance : 156 adhérents, 12 projets, 5 formations vendues.']],
            ['Comptes_annuels_arretes.pdf', 'pdf', 'tresoriere', -10, ['Résultat positif, fonds propres consolidés.']],
        ],
        'qualiopi' => [
            ['Rapport_audit_Qualiopi_precedent.pdf', 'pdf', 'salarie', -118, ['Certification obtenue sans non-conformité majeure.']],
            ['Livret_accueil_stagiaires.pdf', 'pdf', 'formatrice', -100, ['Présentation, règlement intérieur, accessibilité.']],
            ['Procedure_reclamation.pdf', 'pdf', 'salarie', -60, ['Recevoir, analyser, répondre sous 15 jours.']],
            ['Resultats_audit_blanc.pdf', 'pdf', 'formatrice', -16, ['2 non-conformités mineures, corrigées.']],
            ['Attestations_formation_formateurs.pdf', 'pdf', 'formatrice', -6, ['Indicateur 22 : maintien des compétences des formateurs.']],
            ['Attestations_Pix_equipe.pdf', 'pdf', 'communication', -5, ['5 attestations Pix.']],
        ],
    ];
}

/** Fournisseurs de dépenses par nature. [fournisseur, catégorie, TVA, [min, max] TTC, description] */
function df_cat_depenses(): array
{
    return [
        ['Librairie Le Divan — manuels FLE', 'Livres / Matériel pédagogique', 5.5, [180, 620], 'Manuels et cahiers d\'exercices'],
        ['Bureau Vallée Massy', 'Fournitures', 20, [45, 260], 'Fournitures pédagogiques'],
        ['LDLC Pro', 'Matériel informatique', 20, [390, 2400], 'Ordinateurs portables et accessoires'],
        ['Imprimerie Massy Copie', 'Communication & impression', 20, [90, 480], 'Impression de supports et flyers'],
        ['Espace Jean Lurçat', 'Location de salle/matériel', 20, [240, 900], 'Location de salle'],
        ['SNCF — billets de groupe', 'Frais de déplacement', 10, [80, 420], 'Déplacements des participants'],
        ['Intermarché Massy', 'Alimentation & boissons', 5.5, [35, 160], 'Collations des ateliers'],
        ['Numéris Formation SARL', 'Formation & intervention', 20, [600, 2400], 'Intervention de formateur certifié'],
        ['Free Pro', 'Télécom', 20, [30, 60], 'Box internet de la salle'],
        ['Transdev Île-de-France', 'Transport', 10, [220, 980], 'Transport en car'],
    ];
}

/** Compte-rendus des étapes validées (titre d'étape => ce qui a été fait). */
function df_cat_comptes_rendus(): array
{
    return [
        'Tests de positionnement' => "24 tests passés sur 2 matinées (oral + écrit). Grille DILF utilisée. Résultats : 9 A1, 10 A1+, 5 proches A2 — détail dans « Liste_groupes_niveaux.pdf ».",
        'Constitution des 3 groupes de niveau' => "Groupes validés avec Hélène : A1 le mardi, A1+ le samedi, A2 le jeudi. 12 places max par groupe, liste d'attente ouverte à l'accueil.",
        'Convention salle avec le centre social' => "Convention signée par la présidente et le directeur du centre social. Deux salles le mardi et le jeudi matin, une le samedi. Gratuit en échange d'une séance ouverte aux habitants par trimestre.",
        'Lancement des cours (6 h/semaine)' => "Premier cours fait : 22 présents sur 24. Supports distribués, feuilles d'émargement signées sur tablette.",
        'Sortie au Musée de l\'histoire de l\'immigration' => "18 apprenants + 3 accompagnateurs. Billets pris sur le budget sorties (facture dans Dépenses). Chaque apprenant a présenté une œuvre en français.",
        'Convention avec le département' => "Convention Pass Numérique signée : 120 chèques, valables jusqu'à la fin de la saison. Original rangé au bureau, copie dans les fichiers.",
        'Équipement de la salle (12 postes)' => "12 PC reconditionnés installés et configurés (LDLC Pro). Compte « invité » créé sur chaque poste, imprimante partagée.",
        'Recrutement des bénéficiaires' => "113 bénéficiaires orientés par le CCAS, France Travail et la médiathèque. Fiches d'inscription saisies dans Assokit.",
        'Ateliers « premiers pas » (8 groupes)' => "8 groupes de 12 terminés. Satisfaction 4,8/5. 96 participants savent désormais envoyer un e-mail avec pièce jointe.",
        'Ateliers « démarches en ligne »' => "102 personnes ont créé elles-mêmes leur compte Ameli ou CAF. Deux cas complexes orientés vers les permanences du jeudi.",
        'Orientation des participants par le PLIE' => "40 participants orientés par le PLIE, dossiers complets reçus. Premier rendez-vous individuel fixé pour chacun.",
        'Diagnostics individuels' => "40 diagnostics faits (freins, projet, mobilité). Synthèse envoyée au PLIE le vendredi.",
        'Ateliers CV et lettre de motivation' => "6 ateliers collectifs, 38 CV refaits. Fatou a animé la partie « lettre de motivation ».",
        'Immersions en entreprise (PMSMP)' => "12 immersions réalisées, 5 promesses d'embauche. Conventions PMSMP signées et classées dans les fichiers.",
        'Autodiagnostic des 32 indicateurs' => "Autodiagnostic fait en réunion d'équipe : 26 indicateurs conformes, 6 à renforcer (dont 22 et 26).",
        'Mise à jour du livret d\'accueil' => "Livret mis à jour avec les nouvelles formations, le référent handicap et la procédure de réclamation.",
        'Questionnaires de satisfaction à chaud et à froid' => "Questionnaires en ligne envoyés après chaque session ; taux de réponse 78 %, satisfaction moyenne 4,6/5.",
        'Audit blanc' => "Audit blanc mené par un consultant bénévole : 2 non-conformités mineures (indicateurs 22 et 26), corrigées dans la semaine.",
        'Recueil des besoins avec la DRH' => "Réunion avec la DRH de la Ville : 18 agents, priorité Excel. Deux groupes de niveau, sessions sur site.",
        'Signature de la convention de formation' => "Convention signée par la Ville. Bon de commande reçu, facturation à 45 jours (mandat administratif).",
        'Module Word (2 jours)' => "2 jours faits, 18/18 présents. Satisfaction 4,7/5. Facture du module envoyée à la Ville.",
        'Fixer la date et réserver la salle' => "Salle des fêtes réservée (120 places), visio prévue pour les adhérents qui ne peuvent pas se déplacer.",
        'Collecter les chiffres de chaque pôle' => "Chiffres reçus des 5 pôles : 214 apprenants, 1 380 heures de formation, 12 projets.",
        'Rédiger le rapport d\'activité' => "Rapport rédigé par Marc, relu par Sophie. Maquette en cours avec les photos de l'année.",
    ];
}

function df_seed_projets(): void
{
    $u = DF::$u;
    $qui = fn(string $k) => $u[$k] ?? $u['admin'];

    // ---------------- Dossiers ----------------
    foreach (df_cat_dossiers() as $cle => [$nom, $couleur, $icone, $archive]) {
        $id = df_ins('folders', [
            'org_id' => DF::$org, 'name' => $nom, 'color_theme' => $couleur, 'icon' => $icone,
            'description' => null, 'created_by' => $u['admin'],
            'created_at' => df_jh($archive ? -480 : -df_entre(200, 400), '10:00'),
            'archived_at' => $archive ? df_jh(-$archive, '10:15') : null,
            'archived_by_user_id' => $archive ? $u['admin'] : null,
            'archived_with_active_projects' => 0,
        ]);
        df_retenir('dossiers', $cle, $id);
    }

    // ---------------- Projets, étapes, équipes ----------------
    $tousMessages = [];
    $parJour = [];       // historique : jours couverts
    foreach (df_cat_projets() as $cle => $p) {
        [$dos, $nom, $statut, $ref, $lieu, $budget, $nb, [$f, $h], $debut, $fin, $etapes, $equipe, $suivi, $archive, $desc] = $p;
        $fid = df_id('dossiers', $dos);
        if (!$fid) continue;
        $faites = count(array_filter($etapes, fn($e) => $e[1]));
        $progres = (int)round($faites / max(1, count($etapes)) * 100);
        $creation = min($debut - df_entre(10, 30), -1);
        $archJ = $archive[0] ?? null;
        $pid = df_ins('projects', [
            'folder_id' => $fid, 'name' => $nom, 'location' => $lieu, 'description' => $desc,
            'objective' => null, 'referent_id' => $qui($ref),
            'budget_planned' => $budget, 'budget_used' => 0, 'progress_percent' => $progres,
            'participants_count' => $nb, 'participants_female' => $f, 'participants_male' => $h,
            'status' => $statut, 'start_date' => df_j($debut), 'end_date' => df_j($fin),
            'created_at' => df_jh($creation, '11:30'),
            'updated_at' => df_jh($statut === 'done' ? min($fin, -1) : -df_entre(1, 6), '17:00'),
            'archived_at' => $archJ ? df_jh(-$archJ, '10:15') : null,
            'archived_by_user_id' => $archJ ? $u['admin'] : null,
            'archived_via_folder' => ($archive[1] ?? false) ? 1 : 0,
        ]);
        if (!$pid) continue;
        df_retenir('projets', $cle, $pid);
        df_log_projet($pid, $qui($ref), 'project_created', 'a créé le projet', null, df_jh($creation, '11:30'));

        // Étapes : les cochées s'étalent du début du projet à aujourd'hui, la dernière très récente.
        $finFaites = min($fin, 0);
        $dernierJour = ($statut === 'done') ? $fin : -df_entre(0, 9);
        $pas = $faites > 1 ? max(1, intdiv(max(1, $dernierJour - $debut - 3), $faites)) : 1;
        foreach ($etapes as $i => [$titre, $cochee, $assignee]) {
            $jour = $cochee ? min($dernierJour, $debut + 3 + $i * $pas) : null;
            if ($cochee && $i === $faites - 1) $jour = $dernierJour;
            if ($cochee && $i === $faites - 2 && $statut !== 'done') $jour = min($jour, $dernierJour - df_entre(2, 8));
            $faitPar = $cochee ? $qui(df_choix(array_keys($equipe))) : null;
            $assigne = $assignee ? $qui($assignee) : (!$cochee && df_proba(0.45) ? $qui(df_choix(array_keys($equipe))) : null);
            if ($assigne === ($u['financeur'] ?? -1)) $assigne = $qui($ref);
            $sid = df_ins('project_steps', [
                'project_id' => $pid, 'position' => $i + 1, 'title' => $titre, 'description' => null,
                'assigned_to_user_id' => $assigne,
                'is_completed' => $cochee ? 1 : 0,
                'completed_at' => $cochee ? df_jh($jour, sprintf('%02d:%02d', df_entre(9, 19), df_entre(0, 59))) : null,
                'completed_by' => $faitPar,
                // Ce qui a été fait (colonne ajoutée le 2026-10-05, ignorée si absente).
                'completion_note' => $cochee ? (df_cat_comptes_rendus()[$titre] ?? null) : null,
                'created_at' => df_jh($creation, '11:4' . min(9, $i)),
            ]);
            if ($cochee) {
                df_log_projet($pid, $faitPar, 'step_updated', 'a validé l\'étape « ' . $titre . ' »',
                              ['field' => 'is_completed', 'old' => 0, 'new' => 1], df_jh($jour, '18:00'));
            } elseif ($sid) {
                DF::$ids['etapes_ouvertes'][$cle][] = $sid;
                if ($assignee) DF::$ids['etapes_assignees'][$assignee][] = [$sid, $pid, $titre];
            }
        }

        // Équipe
        foreach ($equipe as $k => $role) {
            $uid = $u[$k] ?? null;
            if (!$uid) continue;
            df_ins('project_members', ['project_id' => $pid, 'user_id' => $uid, 'role_in_project' => $role,
                                       'joined_at' => df_jh($debut + df_entre(-5, 3), '10:00')]);
        }
        if ($suivi && !empty($u['financeur'])) {
            df_ins('project_followers', ['project_id' => $pid, 'user_id' => $u['financeur']]);
        }

        foreach (df_cat_messages()[$cle] ?? [] as [$auteur, $j, $hm, $texte]) {
            $tousMessages[] = [df_passe($j, $hm), $pid, $cle, $qui($auteur), $texte];
        }
        $statuts[$cle] = [$pid, $statut, $budget, $debut, $fin, $equipe, $ref];
    }

    // ---------------- Messages (dans l'ordre chronologique : les ids suivent les dates) ----------------
    usort($tousMessages, fn($a, $b) => strcmp($a[0], $b[0]));
    $derniers = [];
    foreach ($tousMessages as [$quand, $pid, $cle, $auteur, $texte]) {
        $mid = df_ins('project_messages', ['project_id' => $pid, 'author_id' => $auteur, 'content' => $texte,
                                           'message_type' => 'text', 'created_at' => $quand]);
        if ($mid) {
            DF::$ids['messages'][$cle][] = [$mid, $auteur, $quand, $texte];
            $derniers[$pid][] = [$mid, $auteur];
        }
    }

    // Lectures : chaque compte de connexion a lu ses projets, sauf quelques messages récents.
    $nonLus = ['admin' => ['fle-automne' => 3, 'qualiopi' => 2, 'plie' => 1], 'salarie' => ['qualiopi' => 1, 'bureautique-mairie' => 2],
               'benevole' => ['fle-automne' => 4, 'delf-b1' => 1], 'membre' => ['pass-numerique' => 2], 'financeur' => ['pass-numerique' => 1]];
    foreach (['admin', 'salarie', 'benevole', 'membre', 'financeur'] as $k) {
        $uid = $u[$k];
        foreach (DF::$ids['projets'] ?? [] as $cle => $pid) {
            $liste = $derniers[$pid] ?? [];
            if (!$liste) continue;
            $n = $nonLus[$k][$cle] ?? 0;
            // On ne laisse non lus que des messages écrits par d'autres.
            $lu = count($liste);
            while ($n > 0 && $lu > 0) {
                if ($liste[$lu - 1][1] !== $uid) $n--;
                $lu--;
            }
            $dernierLu = $lu > 0 ? $liste[$lu - 1][0] : 0;
            df_ins('user_project_reads', ['user_id' => $uid, 'project_id' => $pid, 'last_read_message_id' => $dernierLu,
                                          'last_read_at' => df_jh(-1, '18:30')]);
        }
    }

    // ---------------- Fichiers (de vrais fichiers sur le disque) ----------------
    $mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
    $n = 0;
    foreach (df_cat_fichiers() as $cle => $liste) {
        $pid = df_id('projets', $cle);
        if (!$pid) continue;
        foreach ($liste as [$nomFichier, $type, $auteur, $j, $lignes]) {
            $n++;
            $base = pathinfo($nomFichier, PATHINFO_FILENAME);
            $stocke = $base . '_' . (DF::$t0 + $n) . '_' . str_pad((string)(1000 + $n), 4, '0', STR_PAD_LEFT) . '.' . $type;
            $rel = 'uploads/projet_' . $pid . '/' . $stocke;
            $contenu = $type === 'pdf' ? df_pdf(str_replace('_', ' ', $base), $lignes) : df_image($type, $n);
            $taille = $contenu === null ? 0 : df_ecrire($rel, $contenu);
            if ($taille === 0) continue;   // pas de carte vers un fichier absent
            df_ins('project_files', ['project_id' => $pid, 'uploaded_by' => $qui($auteur), 'filename' => $nomFichier,
                                     'filepath' => $rel, 'filesize_bytes' => $taille, 'mime_type' => $mimes[$type],
                                     'created_at' => df_jh($j, sprintf('%02d:%02d', df_entre(9, 19), df_entre(0, 59)))]);
        }
    }

    // ---------------- Dépenses ----------------
    // Part du budget consommée : à surveiller ≥ 90 %, terminé ≈ 95 %, en cours selon l'avancement.
    $cibles = ['fle-pro' => 0.93, 'remobilisation' => 0.92];
    $depenses = df_cat_depenses();
    $valideurs = [$u['admin'], $u['tresoriere'] ?? $u['admin'], $u['salarie']];
    foreach ($statuts as $cle => [$pid, $statut, $budget, $debut, $fin, $equipe, $ref]) {
        if ($statut === 'draft' || $budget <= 0) continue;
        $part = $cibles[$cle] ?? ($statut === 'done' ? 0.95 : df_entre(38, 72) / 100);
        $objectif = $budget * $part;
        $total = 0.0;
        $nb = 0;
        $finJ = min($fin, -1);
        $places = min(12, max(3, (int)ceil($objectif / 1400)));
        while ($total < $objectif * 0.97 && $nb < $places + 2) {
            [$fourn, $cat, $tva, [$min, $max], $libelle] = df_choix($depenses);
            // Gros budgets : la ligne grossit avec ce qui reste à répartir (une intervention, un car…).
            $ttc = df_eur(min(max($max, 3200), max($min, ($objectif - $total) / max(1, $places - $nb))) * df_entre(85, 115) / 100);
            if ($total + $ttc > $objectif * 1.02) $ttc = df_eur(max(20, $objectif - $total));
            $ht = df_eur($ttc / (1 + $tva / 100));
            $j = df_entre(min($debut + 2, $finJ), $finJ);
            $auteur = $qui(df_choix(array_keys($equipe)));
            $iid = df_depense($pid, $auteur, $fourn, $cat, $libelle, $ht, $tva, $ttc, $j, 'validated', df_choix($valideurs));
            if ($iid) { $total += $ttc; $nb++; }
        }
        // Ce qui attend une validation, et ce qui a été refusé.
        if (in_array($statut, ['active', 'warning'], true)) {
            $k = $cle === 'fle-automne' ? 1 : (df_proba(0.45) ? 1 : 0);
            for ($i = 0; $i < $k; $i++) {
                [$fourn, $cat, $tva, [$min, $max], $libelle] = df_choix($depenses);
                $ttc = df_eur(df_entre($min, (int)(($min + $max) / 2)));
                df_depense($pid, $qui(df_choix(array_keys($equipe))), $fourn, $cat, $libelle, df_eur($ttc / (1 + $tva / 100)), $tva, $ttc, -df_entre(1, 10), 'pending', null);
            }
            if ($cle === 'fle-automne' || df_proba(0.2)) {
                df_depense($pid, $qui(df_choix(array_keys($equipe))), 'Intermarché Massy', 'Alimentation & boissons',
                           'Ticket de caisse illisible — à refaire', 37.80 / 1.055, 5.5, 37.80, -df_entre(4, 15), 'rejected', $u['admin']);
            }
        }
        df_maj('projects', ['budget_used' => df_eur($total)], 'id = ?', [$pid]);
    }

    // ---------------- Historique complémentaire ----------------
    $changements = [
        'fle-pro' => [['status_changed', 'a changé le statut : En cours → À surveiller', ['field' => 'status', 'old' => 'active', 'new' => 'warning'], -9, 'admin'],
                      ['budget_changed', 'a modifié le budget prévu : 8 000 € → 9 800 €', ['field' => 'budget_planned', 'old' => 8000, 'new' => 9800], -30, 'admin']],
        'remobilisation' => [['status_changed', 'a changé le statut : En cours → À surveiller', ['field' => 'status', 'old' => 'active', 'new' => 'warning'], -12, 'admin']],
        'smartphone' => [['status_changed', 'a changé le statut : En cours → Terminé', ['field' => 'status', 'old' => 'active', 'new' => 'done'], -20, 'formatrice']],
        'bureautique-mairie' => [['project_updated', 'a modifié le lieu', ['field' => 'location', 'old' => 'Démoville', 'new' => 'Démoville — Hôtel de ville'], -40, 'salarie']],
        'qualiopi' => [['referent_changed', 'a changé le référent : Sophie Laurent → Karim Benali', ['field' => 'referent_id'], -110, 'admin']],
        'pass-numerique' => [['follower_added', 'a ajouté Claire Vasseur comme suiveuse', null, -118, 'formatrice']],
        'plie' => [['follower_added', 'a ajouté Claire Vasseur comme suiveuse', null, -92, 'insertion']],
    ];
    foreach ($changements as $cle => $lignes) {
        $pid = df_id('projets', $cle);
        if (!$pid) continue;
        foreach ($lignes as [$type, $lib, $chg, $j, $k]) df_log_projet($pid, $qui($k), $type, $lib, $chg, df_jh($j, '19:0' . abs($j) % 10));
    }
    // Le graphe d'activité du tableau de bord : au moins une action chaque jour du mois.
    $actifs = array_filter($statuts, fn($s) => in_array($s[1], ['active', 'warning'], true));
    $jours = [];
    if (df_a_table('project_activity_log') && !empty(DF::$ids['projets'])) {
        $in = implode(',', array_map('intval', DF::$ids['projets']));
        foreach (DF::$pdo->query("SELECT DATE(created_at) FROM project_activity_log WHERE project_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $d) $jours[$d] = true;
    }
    for ($j = -29; $j <= 0; $j++) {
        $w = (int)date('N', strtotime(df_j($j)));
        $combien = isset($jours[df_j($j)]) ? ($w <= 5 && df_proba(0.5) ? 1 : 0) : ($w >= 6 ? 1 : 2);
        for ($i = 0; $i < $combien; $i++) {
            $cle = df_choix(array_keys($actifs));
            [$pid, , , , , $equipe] = $actifs[$cle];
            df_log_projet($pid, $qui(df_choix(array_keys($equipe))), 'project_updated', 'a mis à jour le suivi du projet', null,
                          df_jh($j, sprintf('%02d:%02d', df_entre(8, 20), df_entre(0, 59))));
        }
    }

    // ---------------- Partage public ----------------
    foreach ([['pass-numerique', 48, -1, -25, null], ['fle-automne', 23, -3, -40, null], ['pass-numerique', 7, -27, -60, -25]] as [$cle, $vues, $vu, $cree, $revoque]) {
        $pid = df_id('projets', $cle);
        if (!$pid) continue;
        df_ins('project_share_tokens', ['project_id' => $pid, 'token' => substr(hash('sha256', "demo-f|partage|$cle|$cree"), 0, 40),
                                        'created_by' => $u['admin'], 'created_at' => df_jh($cree, '10:00'),
                                        'expires_at' => null, 'revoked_at' => $revoque ? df_jh($revoque, '10:00') : null,
                                        'view_count' => $vues, 'last_viewed_at' => df_jh($vu, '16:42')]);
    }

    // ---------------- Dossiers épinglés ----------------
    foreach ([['admin', 'clients'], ['salarie', 'vie'], ['benevole', 'fle']] as [$k, $dos]) {
        if ($fid = df_id('dossiers', $dos)) df_ins('user_pinned_folders', ['user_id' => $u[$k], 'folder_id' => $fid, 'created_at' => df_jh(-30, '09:00')]);
    }

    // ---------------- Archives ----------------
    $admin = 'Sophie Laurent';
    if ($fid = df_id('dossiers', 'ete')) {
        df_ins('archive_logs', ['org_id' => DF::$org, 'action' => 'archive_folder', 'target_type' => 'folder', 'target_id' => $fid,
                                'target_name' => df_cat_dossiers()['ete'][0], 'admin_user_id' => $u['admin'], 'admin_name' => $admin,
                                'details' => ['projets_archives' => 2], 'created_at' => df_jh(-6, '10:15')]);
    }
    if ($pid = df_id('projets', 'forum-assos')) {
        df_ins('archive_logs', ['org_id' => DF::$org, 'action' => 'archive_folder', 'target_type' => 'project', 'target_id' => $pid,
                                'target_name' => df_cat_projets()['forum-assos'][1], 'admin_user_id' => $u['admin'], 'admin_name' => $admin,
                                'details' => ['statut' => 'done'], 'created_at' => df_jh(-9, '18:20')]);
    }

    df_seed_projets_ia();
}

/** Une dépense de projet, avec un vrai justificatif PDF et sa trace dans l'historique. */
function df_depense(int $pid, int $auteur, string $fourn, string $cat, string $libelle, float $ht, float $tva, float $ttc,
                    int $j, string $statut, ?int $valideur): ?int
{
    static $n = 0;
    $n++;
    $rel = 'uploads/projet_' . $pid . '/factures/facture_' . (DF::$t0 + $n) . '_' . $n . '.pdf';
    $taille = df_ecrire($rel, df_pdf('Facture — ' . $fourn, [
        'Objet : ' . $libelle,
        'Montant HT : ' . number_format($ht, 2, ',', ' ') . ' €   TVA ' . str_replace('.', ',', (string)$tva) . ' %',
        'Montant TTC : ' . number_format($ttc, 2, ',', ' ') . ' €',
        'Facturé à : Association DEMO F — 12 rue des Ateliers, 91300 Massy',
    ]));
    $quand = df_jh($j, sprintf('%02d:%02d', df_entre(9, 19), df_entre(0, 59)));
    $valide = $statut !== 'pending' ? df_jh(min(0, $j + df_entre(1, 4)), '10:30') : null;
    $id = df_ins('project_invoices', [
        'project_id' => $pid, 'uploaded_by' => $auteur, 'supplier_name' => $fourn, 'category' => $cat,
        'description' => $libelle, 'amount_ht' => df_eur($ht), 'vat_rate' => $tva, 'vat_amount' => df_eur($ttc - $ht),
        'amount_ttc' => df_eur($ttc), 'invoice_date' => df_j($j), 'file_path' => $taille ? $rel : null,
        'status' => $statut, 'validated_by' => $statut === 'pending' ? null : $valideur, 'validated_at' => $valide,
        'created_at' => $quand,
    ]);
    if ($id) {
        $montant = number_format($ttc, 2, ',', ' ') . ' € TTC';
        df_log_projet($pid, $auteur, 'invoice_added', "a ajouté une facture de $fourn ($montant)", null, $quand);
        if ($statut === 'validated' && $valideur && $valideur !== $auteur) {
            df_log_projet($pid, $valideur, 'invoice_updated', "a validé la facture de $fourn ($montant)", ['field' => 'status', 'new' => 'validated'], $valide);
        }
        if ($statut === 'rejected') {
            df_log_projet($pid, $valideur, 'invoice_updated', "a refusé la facture de $fourn ($montant)", ['field' => 'status', 'new' => 'rejected'], $valide);
        }
    }
    return $id;
}

function df_log_projet(int $pid, ?int $uid, string $type, string $libelle, ?array $chg, string $quand): void
{
    if (!$uid) return;
    df_ins('project_activity_log', ['project_id' => $pid, 'user_id' => $uid, 'action_type' => $type, 'action_label' => $libelle,
                                    'changes' => $chg, 'ip_address' => null, 'created_at' => $quand]);
}

/** Documents produits par l'assistant IA d'un projet, et une conversation. */
function df_seed_projets_ia(): void
{
    $u = DF::$u;
    $docs = [
        ['fle-automne', 'synthese_etape', 'Synthèse — lancement des cours', 'benevole', -12,
         "## Où en est le parcours FLE ?\n\nLe parcours a démarré il y a dix semaines avec **24 apprenants** répartis en trois groupes de niveau. "
         . "Cinq étapes sur huit sont terminées.\n\n## Ce qui marche\n\n- **92 % de présence** sur le premier mois\n- Les mises en situation (pharmacie, CAF, entretien) motivent le groupe\n"
         . "- La sortie au musée a permis à plusieurs apprenants de prendre la parole en public\n\n## Prochaines étapes\n\n1. Évaluations intermédiaires\n2. Passage du DILF / TCF\n3. Bilan final et attestations\n\n"
         . "**Point d'attention :** sept personnes sont en liste d'attente. Un quatrième groupe en janvier coûterait environ 4 200 €."],
        ['fle-automne', 'fiche_com', 'Fiche de communication — « Le français, la clé de l\'autonomie »', 'admin', -6,
         "## Le français, la clé de l'autonomie\n\nÀ Massy, l'association DEMO F accompagne **24 adultes** vers l'autonomie en français : "
         . "comprendre un courrier, prendre rendez-vous chez le médecin, réussir un entretien d'embauche.\n\n> « Grâce aux cours, j'ai trouvé un emploi d'aide à domicile. » — Amina, groupe A1+\n\n"
         . "**Six heures de cours par semaine**, trois groupes de niveau, des bénévoles formés et une sortie culturelle par trimestre.\n\n"
         . "Vous souhaitez soutenir le parcours ou devenir bénévole ? Contactez-nous : contact@demo-f.assokit.fr"],
        ['fle-automne', 'bilan_ag', 'Bilan pour l\'assemblée générale — Pôle FLE', 'benevole', -2,
         "## Pôle Français Langue Étrangère\n\nCette année, le pôle FLE a accompagné **38 apprenants** sur deux parcours (A1→A2 et DELF B1).\n\n"
         . "- 24 apprenants dans le parcours A1→A2, 14 candidats au DELF B1\n- 92 % d'assiduité moyenne\n- 11 apprenants ont trouvé un emploi ou une formation pendant le parcours\n\n"
         . "**Perspectives :** ouverture d'un quatrième groupe en janvier, sous réserve du financement régional."],
        ['pass-numerique', 'bilan_ag', 'Bilan Pass Numérique', 'formatrice', -9,
         "## Pass Numérique : 113 bénéficiaires sur 120\n\nLes ateliers ont permis à **102 personnes** de créer elles-mêmes leur compte Ameli ou CAF.\n\n"
         . "- Satisfaction : **4,8 / 5**\n- 8 groupes « premiers pas », 6 groupes « démarches en ligne »\n- Cofinancement : Département et Fondation Avenir Solidaire\n\nObjectif atteint avant la fin du trimestre."],
        ['pass-numerique', 'fiche_com', 'Fiche com — Témoignage de Thomas', 'membre', -4,
         "## « Aujourd'hui, je fais mes démarches tout seul »\n\nThomas a suivi les ateliers numériques de DEMO F. Il y a appris à utiliser sa boîte mail, "
         . "à déposer un CV en ligne et à suivre ses remboursements.\n\n**Les ateliers continuent** : renseignements à l'accueil, mardi et jeudi matin."],
        ['qualiopi', 'synthese_etape', 'Synthèse — audit blanc Qualiopi', 'salarie', -15,
         "## Audit blanc : prêts à 90 %\n\n- **30 indicateurs sur 32** conformes\n- 2 non-conformités mineures (indicateurs 22 et 26), corrigées depuis\n\n"
         . "## Reste à faire\n\n1. Rassembler les preuves de l'indicateur 22 (compétences des formateurs)\n2. Audit de renouvellement dans 5 semaines"],
        ['qualiopi', 'bilan_ag', 'Qualité : où en est la certification ?', 'admin', -3,
         "## Certification Qualiopi\n\nLa certification conditionne nos financements publics de la formation. L'audit de renouvellement aura lieu dans cinq semaines.\n\n"
         . "**Taux de satisfaction des stagiaires : 4,6 / 5** — taux de réponse aux questionnaires : 78 %."],
        ['plie', 'synthese_etape', 'Synthèse mi-parcours PLIE', 'insertion', -8,
         "## Accompagnement renforcé PLIE — mi-parcours\n\n- **14 sorties positives** sur 40 participants (emploi ou formation qualifiante)\n- 12 immersions en entreprise, dont 5 promesses d'embauche\n\n"
         . "Prochaine étape : le job dating de l'automne avec 15 entreprises."],
        ['smartphone', 'bilan_ag', 'Bilan des ateliers smartphone seniors', 'formatrice', -20,
         "## 36 seniors connectés\n\n- 97 % de satisfaction\n- 31 participants désormais autonomes en visio avec leur famille\n- Un module « arnaques » très apprécié"],
        ['atelier-cv', 'bilan_ag', 'Bilan atelier CV & LinkedIn', 'salarie', -110,
         "## 28 CV refaits, 19 profils LinkedIn\n\nCinq ateliers collectifs et une séance photo professionnelle : 22 portraits réalisés."],
        ['bureautique-mairie', 'synthese_etape', 'Synthèse — module Word terminé', 'salarie', -14,
         "## Formation des agents de la Ville de Démoville\n\nModule Word terminé pour **18 agents** — satisfaction 4,7 / 5. Le module Excel démarre la semaine prochaine."],
        ['ag', 'bilan_ag', 'Rapport moral — projet', 'admin', -1,
         "## Une année de croissance\n\nNous sommes passés de 120 à **156 adhérents**, nous avons conduit **12 projets** et vendu **5 formations** à des entreprises et des collectivités.\n\n"
         . "Merci aux 26 bénévoles et aux 6 salariés qui font vivre DEMO F au quotidien."],
    ];
    foreach ($docs as [$cle, $type, $titre, $k, $j, $md]) {
        $pid = df_id('projets', $cle);
        if (!$pid) continue;
        df_ins('ai_generated_docs', ['project_id' => $pid, 'user_id' => $u[$k] ?? $u['admin'], 'doc_type' => $type,
                                     'title' => $titre, 'content' => $md, 'created_at' => df_jh($j, '17:3' . abs($j) % 10)]);
    }

    if ($pid = df_id('projets', 'fle-automne')) {
        $cid = df_ins('ai_conversations', ['project_id' => $pid, 'user_id' => $u['benevole'],
                                           'title' => 'Comment organiser les évaluations intermédiaires ?',
                                           'created_at' => df_jh(-3, '20:10'), 'updated_at' => df_jh(-3, '20:14')]);
        if ($cid) {
            foreach ([
                ['user', 'Comment organiser les évaluations intermédiaires pour 24 apprenants de niveaux différents ?', -3, '20:10'],
                ['assistant', "Je vous propose une organisation en trois temps :\n\n1. **Écrit commun** (45 min) : compréhension et production écrite, adaptées à chaque groupe.\n2. **Oral individuel** (10 min par apprenant) : à répartir entre Julie et Hélène sur deux matinées.\n3. **Retour personnalisé** : une fiche par apprenant avec deux points forts et un objectif.\n\nAvec 24 apprenants, comptez environ 4 heures d'oral au total.", -3, '20:11'],
                ['user', 'Peux-tu me proposer une grille pour l\'oral ?', -3, '20:13'],
                ['assistant', "Voici une grille simple sur 20 points :\n\n- Se présenter et parler de soi : /5\n- Comprendre une question et y répondre : /5\n- Vocabulaire de la vie quotidienne : /5\n- Prononciation et fluidité : /5\n\nUn apprenant au-dessus de 12/20 est prêt pour le DILF.", -3, '20:14'],
            ] as [$role, $txt, $j, $hm]) {
                df_ins('ai_messages', ['conversation_id' => $cid, 'role' => $role, 'content' => $txt,
                                       'tokens_used' => $role === 'assistant' ? df_entre(280, 520) : null, 'created_at' => df_jh($j, $hm)]);
            }
        }
    }
}
