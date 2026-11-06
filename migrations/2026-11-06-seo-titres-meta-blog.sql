-- ------------------------------------------------------------------
-- SEO du blog (Search Console, 7 jours au 6 novembre 2026) : titres et descriptions
-- des articles très vus mais peu cliqués. Ne touche qu'aux colonnes meta_title et
-- meta_description de asso_blog_articles, article par article (clé = slug).
-- Le titre affiché dans l'article (H1, colonne title), le slug (URL), l'extrait et la
-- date de mise à jour ne changent pas.
--
-- Idempotente : relancée, elle réécrit les mêmes valeurs. Un slug absent de la base
-- ne fait rien (0 ligne modifiée).
-- Avant de l'appliquer : comparer avec les valeurs actuelles dans admin-blog (Articles),
-- et vérifier que chaque article traite bien ce que sa description annonce.
-- ------------------------------------------------------------------

-- 1) Titres SEO coupés par « … » par l'ancien générateur : on les vide, la page reprend
--    alors le titre complet de l'article (blog-article.php). Les descriptions coupées
--    sont vidées quand l'extrait est complet : la page reprend l'extrait, coupé à la
--    dernière phrase entière s'il dépasse 160 caractères (includes-public.php).
--    (blog-article.php ignore de toute façon une description qui finit par « … ».)
UPDATE asso_blog_articles SET meta_title = NULL WHERE meta_title LIKE '%…';
UPDATE asso_blog_articles SET meta_description = NULL
 WHERE meta_description LIKE '%…'
   AND excerpt IS NOT NULL AND excerpt NOT LIKE '%…'
   AND CHAR_LENGTH(excerpt) >= 70;

-- 2) Nouveaux titres (60 caractères au plus, « · Assokit » ajouté à l'affichage s'il tient)
--    et descriptions (155 caractères au plus, phrases complètes), selon les requêtes réelles.
UPDATE asso_blog_articles SET meta_title = 'Agenda comptable d''une association : les 12 échéances',
       meta_description = 'Clôture des comptes, assemblée générale, déclarations, subventions : l''agenda comptable de l''association mois par mois, avec les 12 dates à retenir.'
 WHERE slug = 'calendrier-comptable-association-2026-12-echeances-a-ne-jamais-manquer';
UPDATE asso_blog_articles SET meta_title = 'Bureau d''association loi 1901 : composition et rôles',
       meta_description = 'Président, trésorier, secrétaire : qui compose le bureau d''une association loi 1901, combien de membres il faut et quel est le rôle de chacun.'
 WHERE slug = 'bureau-association-composition-role';
UPDATE asso_blog_articles SET meta_title = 'Changement de trésorier : la passation en 7 étapes',
       meta_description = 'Remplacer le trésorier d''une association : banque, procurations, comptes et documents à transmettre. La passation en 7 étapes, sans rien oublier.'
 WHERE slug = 'changement-tresorier-association-passation-en-7-etapes-cles';
UPDATE asso_blog_articles SET meta_title = 'Barème kilométrique 2026 en association : tarifs et calcul',
       meta_description = 'Remboursement des frais kilométriques en association en 2026 : barème applicable, calcul pour les bénévoles et justificatifs à conserver.'
 WHERE slug = 'indemnites-kilometriques-en-association-bareme-optimal-2026-et-maximisation';
UPDATE asso_blog_articles SET meta_title = 'Adhésion à une association : règles et bonnes pratiques',
       meta_description = 'Conditions d''adhésion, bulletin, cotisation, refus d''un candidat : les règles à connaître pour gérer les adhésions de votre association loi 1901.'
 WHERE slug = 'adhesion-association-regles-bonnes-pratiques';
UPDATE asso_blog_articles SET meta_title = 'Cotisation d''association : fixer, encaisser, relancer',
       meta_description = 'Montant, appel, règlement par chèque, virement ou espèces, reçu et relance : comment gérer les cotisations des adhérents de votre association.'
 WHERE slug = 'cotisation-adherent-association-gerer';
UPDATE asso_blog_articles SET meta_title = 'Chorus Pro pour une association : inscription en 8 étapes',
       meta_description = 'Créer le compte Chorus Pro de votre association et déposer votre première facture à une mairie ou à un service public, étape par étape.'
 WHERE slug = 'chorus-pro-association-inscription-et-premier-envoi-en-8-etapes';
UPDATE asso_blog_articles SET meta_title = 'Erreur sur une facture : avoir ou facture rectificative ?',
       meta_description = 'Une erreur de facturation ? Quand émettre un avoir, quand une facture rectificative, et comment corriger une facture électronique dans les règles.'
 WHERE slug = 'erreur-sur-facture-electronique-comment-corriger-legalement-en-2026';
UPDATE asso_blog_articles SET meta_title = 'Radier un membre d''association : la procédure',
       meta_description = 'Radiation d''un adhérent : motifs valables, convocation, droits de la défense et décision. La procédure pour exclure un membre en respectant vos statuts.'
 WHERE slug = 'radier-membre-association-procedure';
UPDATE asso_blog_articles SET meta_title = 'Dépense sans justificatif en association : les limites',
       meta_description = 'Ticket perdu, achat en espèces, facture absente : ce qu''une association peut justifier autrement, et ce qu''elle risque en cas de contrôle.'
 WHERE slug = 'depenses-sans-justificatif-en-association-les-limites-legales-en-2026';
UPDATE asso_blog_articles SET meta_title = 'Affectation du résultat d''une association : qui décide ?',
       meta_description = 'Excédent ou déficit : l''assemblée générale ou le conseil d''administration vote-t-il l''affectation du résultat ? Report à nouveau, réserves, écritures.'
 WHERE slug = 'affectation-du-resultat-en-association-qui-decide-l-ag-ou-le-conseil-d';
UPDATE asso_blog_articles SET meta_title = 'Cerfa 12156 : remplir sa demande de subvention',
       meta_description = 'Le dossier de demande de subvention cerfa 12156 rubrique par rubrique : présentation, budget, projet, pièces jointes et erreurs à éviter.'
 WHERE slug = 'cerfa-12156-le-guide-complet-pour-remplir-votre-demande-de-subvention-sans';
UPDATE asso_blog_articles SET meta_title = 'Convocation d''AG d''association : délais et mentions',
       meta_description = 'Délai de convocation d''une assemblée générale, forme, ordre du jour, quorum : ce que prévoient la loi et vos statuts pour convoquer vos membres.'
 WHERE slug = 'assemblee-generale-association-convocation-et-delais-legaux-en-2026';
UPDATE asso_blog_articles SET meta_title = 'Numéro de bon de commande sur facture : obligatoire ?',
       meta_description = 'Le numéro de bon de commande est-il une mention obligatoire de la facture ? Ce que dit la loi, l''effet de la facture électronique et quand il est exigé.'
 WHERE slug = 'numero-de-bon-de-commande-sur-facture-obligation-legale-ou-simple-usage';
UPDATE asso_blog_articles SET meta_title = 'Contrôle Urssaf en association : 5 points de vigilance',
       meta_description = 'Factures, bénévoles défrayés, intervenants, avantages : les points que l''Urssaf examine dans une association, et comment s''y préparer.'
 WHERE slug = 'controle-urssaf-et-facture-electronique-en-association-5-points-de-vigilance';
UPDATE asso_blog_articles SET meta_title = 'Sponsoring ou mécénat : comment facturer un partenaire',
       meta_description = 'Parrainage facturé avec TVA ou don ouvrant droit à un reçu fiscal ? Les critères pour distinguer sponsoring et mécénat dans une association.'
 WHERE slug = 'association-et-facturation-de-partenariat-sponsors-vs-mecenat-fiscal';
UPDATE asso_blog_articles SET meta_title = 'Droits d''auteur et association : qui les perçoit ?',
       meta_description = 'Une association peut-elle percevoir des droits d''auteur ? Cession, sociétés de gestion, fiscalité et comptabilisation : les règles expliquées simplement.'
 WHERE slug = 'droits-d-auteur-et-association-comment-les-percevoir-en-2026';
UPDATE asso_blog_articles SET meta_title = 'Provisions en association : quand et comment les passer',
       meta_description = 'Litige, gros entretien, risque : quand une association doit constituer une provision, comment la calculer et l''enregistrer en comptabilité.'
 WHERE slug = 'provisions-comptables-association-quand-et-comment-les-constituer-en-2026';
UPDATE asso_blog_articles SET meta_title = 'Plan comptable des associations 2026 : les comptes clés',
       meta_description = 'Le plan comptable des associations (règlement ANC 2018-06) : classes 6 et 7, fonds dédiés, contributions volontaires en nature. Les comptes à connaître.'
 WHERE slug = 'plan-comptable-association-2026-guide-complet-des-comptes-obligatoires';
UPDATE asso_blog_articles SET meta_title = 'Dons en nature : les valoriser et les comptabiliser',
       meta_description = 'Matériel, local, prestation offerte : comment une association évalue un don en nature, l''enregistre en comptabilité et délivre ou non un reçu fiscal.'
 WHERE slug = 'dons-en-nature-association-comment-les-valoriser-et-les-comptabiliser-en-2026';
UPDATE asso_blog_articles SET meta_title = 'Facture électronique rejetée : motifs et recours',
       meta_description = 'Votre facture électronique est rejetée par la plateforme ? Motifs fréquents, délais, correction et nouvel envoi, étape par étape.'
 WHERE slug = 'rejet-de-facture-electronique-par-plateforme-recours-et-delais-en-2026';
UPDATE asso_blog_articles SET meta_title = 'Augmenter la cotisation sans perdre ses adhérents',
       meta_description = 'Comment annoncer une hausse de cotisation en assemblée générale, la justifier et garder vos membres : 7 méthodes concrètes pour votre association.'
 WHERE slug = 'augmentation-cotisation-association-7-strategies-pour-preserver-vos-adherents';
UPDATE asso_blog_articles SET meta_title = 'Chorus Pro ou plateforme agréée : que choisir ?',
       meta_description = 'Chorus Pro pour facturer le secteur public, plateforme agréée pour le privé : quel circuit de facture électronique choisir pour votre TPE en 2026-2027.'
 WHERE slug = 'chorus-pro-vs-plateforme-privee-quel-flux-de-facturation-choisir-pour-votre-tpe';
UPDATE asso_blog_articles SET meta_title = 'Facturer sans SIRET en association : est-ce possible ?',
       meta_description = 'Une association sans SIRET peut-elle facturer ? Quand le numéro SIRET devient nécessaire, comment l''obtenir et que mettre sur la facture en attendant.'
 WHERE slug = 'facture-association-sans-numero-siret-les-alternatives-legales-en-2027';
UPDATE asso_blog_articles SET meta_title = 'Association et auto-entrepreneur : qui facture qui ?',
       meta_description = 'Faire intervenir un auto-entrepreneur dans votre association : contrat, facture, rémunération de l''intervenant et risque de requalification.'
 WHERE slug = 'association-et-auto-entrepreneur-qui-facture-qui-et-comment-gerer-la-situation';
UPDATE asso_blog_articles SET meta_title = 'Dissolution d''association : procédure et argent restant',
       meta_description = 'Dissoudre une association loi 1901 : vote, liquidation, que faire de l''argent et du matériel restants, déclaration en préfecture. La procédure complète.'
 WHERE slug = 'dissolution-association-procedure-complete-2026-2027';
UPDATE asso_blog_articles SET meta_title = 'Combien de temps garder les factures en association ?',
       meta_description = 'Durée de conservation des factures, archivage papier ou électronique, valeur probante : les règles d''archivage pour votre association.'
 WHERE slug = 'archivage-factures-association-duree-legale-et-obligations-2027';
UPDATE asso_blog_articles SET meta_title = 'Prestation de service payante en association : légal ?',
       meta_description = 'Une association loi 1901 peut-elle vendre des prestations ? Activités lucratives autorisées, conditions, TVA et impôts commerciaux : le point complet.'
 WHERE slug = 'prestation-de-service-payante-en-association-7-activites-lucratives-autorisees';
UPDATE asso_blog_articles SET meta_title = 'Facture d''avoir ou remboursement : quelle différence ?',
       meta_description = 'Facture d''avoir : définition, mentions obligatoires, TVA et écriture comptable, et quand préférer un remboursement. Explications pour TPE et associations.'
 WHERE slug = 'avoir-ou-remboursement-quelle-difference-comptable-et-fiscale-en-tpe';
UPDATE asso_blog_articles SET meta_title = 'Jetons de présence en association : règles et fiscalité',
       meta_description = 'Une association peut-elle verser des jetons de présence à ses dirigeants ? Gestion désintéressée, plafonds, fiscalité et déclaration.'
 WHERE slug = 'jetons-de-presence-en-association-les-regles-meconnues-que-tout-ca-devrait';
UPDATE asso_blog_articles SET meta_title = 'Excel ou logiciel comptable gratuit pour une association ?',
       meta_description = 'Tableur ou logiciel de comptabilité gratuit : avantages, limites et coûts cachés de chaque solution pour tenir les comptes d''une association.'
 WHERE slug = 'logiciel-comptable-gratuit-vs-excel-pour-association-que-choisir-en-2026';
