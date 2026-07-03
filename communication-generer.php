<?php
/**
 * communication-generer.php — Générateur IA de documents (V1 FIX)
 * ================================================================
 * Corrections vs la V1 initiale :
 *   - Ajout include includes-layout.php
 *   - Utilisation de `access_marketing` (pas can_access_marketing)
 *   - Classes CSS alignées sur le design system Assokit
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/ai-helper.php';
require_login();
require_capability('access_marketing');

$user_id = (int) $_SESSION['user_id'];
$org_id  = (int) $_SESSION['org_id'];
$type    = $_GET['type'] ?? $_POST['type'] ?? '';

// Infos asso + user pour injection dans les prompts
$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$stmt->execute([$org_id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

// ==================================================================
// Configuration des types de documents (prompts + champs variables)
// ==================================================================
$types_config = [

    // ========== VIE ASSOCIATIVE ==========
    'convocation_ag_ordinaire' => [
        'title' => 'Convocation AG ordinaire',
        'icon'  => '🏛️',
        'category' => 'ag',
        'fields' => [
            'date_ag'            => ['label' => "Date de l'AG", 'type' => 'datetime-local', 'required' => true],
            'lieu_ag'            => ['label' => 'Lieu (salle + adresse)', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: Salle des fêtes, 12 rue de la Mairie, 91130 Ris-Orangis'],
            'ordre_du_jour'      => ['label' => 'Ordre du jour (un point par ligne)', 'type' => 'textarea', 'required' => true, 'rows' => 6, 'placeholder' => "Rapport moral du président\nRapport d'activité\nRapport financier\nÉlection du bureau\nQuestions diverses"],
            'quorum_requis'      => ['label' => 'Quorum requis', 'type' => 'text', 'placeholder' => 'Ex: 1/3 des membres'],
            'modalites_vote'     => ['label' => 'Modalités de vote', 'type' => 'text', 'placeholder' => 'Main levée + pouvoirs autorisés'],
            'date_limite_pouvoir'=> ['label' => 'Date limite pouvoir', 'type' => 'date'],
            'contact_info'       => ['label' => 'Contact pour questions', 'type' => 'text', 'placeholder' => 'Email + téléphone'],
        ],
        'system' => "Tu es un expert en droit associatif français (loi 1901). Tu rédiges des convocations d'AG formelles, juridiquement correctes, avec le ton institutionnel attendu. Tu utilises la structure : en-tête, objet, corps avec date/lieu/ordre du jour, rappel modalités, formule de politesse, signature.",
        'prompt_template' => "Rédige une convocation d'Assemblée Générale Ordinaire pour l'association \"{nom_asso}\".\n\n=== INFOS ASSO ===\n- Nom : {nom_asso}\n- Email : {email_asso}\n\n=== AG ===\n- Date et heure : {date_ag}\n- Lieu : {lieu_ag}\n- Ordre du jour :\n{ordre_du_jour}\n- Quorum requis : {quorum_requis}\n- Modalités de vote : {modalites_vote}\n- Date limite pouvoir : {date_limite_pouvoir}\n- Contact : {contact_info}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}, Président(e) de l'association\n\nRédige une lettre formelle complète avec : en-tête \"Association {nom_asso}\", date du jour, objet, corps détaillé avec ordre du jour en liste numérotée, rappel du quorum et des modalités, mention sur les procurations, formule de politesse, signature. Environ 400-500 mots.",
    ],

    'pv_ag' => [
        'title' => "Procès-verbal d'AG",
        'icon'  => '📋',
        'category' => 'ag',
        'fields' => [
            'date_ag'            => ['label' => "Date de l'AG", 'type' => 'date', 'required' => true],
            'lieu_ag'            => ['label' => 'Lieu', 'type' => 'text', 'required' => true],
            'nb_presents'        => ['label' => 'Nombre de présents', 'type' => 'number', 'required' => true],
            'nb_pouvoirs'        => ['label' => 'Nombre de pouvoirs', 'type' => 'number'],
            'president_seance'   => ['label' => 'Président de séance', 'type' => 'text', 'required' => true],
            'secretaire_seance'  => ['label' => 'Secrétaire de séance', 'type' => 'text', 'required' => true],
            'notes_brutes'       => ['label' => 'Notes brutes prises en AG', 'type' => 'textarea', 'required' => true, 'rows' => 12, 'placeholder' => "Colle ici tes notes : qui a dit quoi, quels votes, décisions..."],
            'resolutions_votees' => ['label' => 'Résolutions votées (avec résultats)', 'type' => 'textarea', 'rows' => 6, 'placeholder' => "Résolution 1 : Approbation rapport moral — 24 POUR / 0 CONTRE / 2 ABST.\nRésolution 2 : ..."],
        ],
        'system' => "Tu es un expert en rédaction de procès-verbaux d'AG d'associations loi 1901. Tu produis des PV structurés, neutres, juridiquement opposables. Tu restructures des notes brutes en paragraphes clairs par point de l'ordre du jour.",
        'prompt_template' => "Rédige le procès-verbal de l'Assemblée Générale de l'association \"{nom_asso}\".\n\n=== CADRE ===\n- Date : {date_ag}\n- Lieu : {lieu_ag}\n- Présents : {nb_presents}\n- Pouvoirs : {nb_pouvoirs}\n- Président de séance : {president_seance}\n- Secrétaire de séance : {secretaire_seance}\n\n=== NOTES BRUTES ===\n{notes_brutes}\n\n=== RÉSOLUTIONS ===\n{resolutions_votees}\n\nProduis un PV formel avec : en-tête, vérification du quorum, désignation des officiels de séance, rappel de l'ordre du jour, puis un paragraphe par point avec débats + décisions, résultats des votes en clair, clôture de la séance, signatures (Président + Secrétaire). Environ 600-900 mots.",
    ],

    // ========== SUBVENTIONS ==========
    'subvention_mairie' => [
        'title' => 'Demande de subvention Mairie',
        'icon'  => '🏛️',
        'category' => 'subvention',
        'fields' => [
            'nom_mairie'         => ['label' => 'Mairie / commune', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: Mairie de Ris-Orangis'],
            'nom_maire'          => ['label' => 'Nom du Maire ou adjoint', 'type' => 'text'],
            'projet_id'          => ['label' => 'Projet Assokit lié (optionnel)', 'type' => 'select_project'],
            'titre_projet'       => ['label' => 'Titre du projet', 'type' => 'text', 'required' => true],
            'description_projet' => ['label' => 'Description du projet', 'type' => 'textarea', 'required' => true, 'rows' => 6],
            'montant_demande'    => ['label' => 'Montant demandé (€)', 'type' => 'number', 'required' => true],
            'budget_total'       => ['label' => 'Budget total du projet (€)', 'type' => 'number', 'required' => true],
            'autres_financeurs'  => ['label' => 'Autres financeurs envisagés', 'type' => 'text'],
            'impact_local'       => ['label' => 'Impact local / bénéficiaires', 'type' => 'textarea', 'required' => true, 'rows' => 4, 'placeholder' => 'Ex: 150 habitants de Ris-Orangis, dont 60 jeunes des quartiers sud'],
            'duree_projet'       => ['label' => 'Durée / calendrier', 'type' => 'text'],
        ],
        'system' => "Tu es un expert en demandes de subvention auprès des collectivités locales françaises. Tu maîtrises le vocabulaire attendu par les élus locaux : ancrage territorial, intérêt général, cohésion sociale, vie de quartier, politique jeunesse/culture/sport. Tu produis des dossiers argumentés et convaincants.",
        'prompt_template' => "Rédige une lettre de demande de subvention à la Mairie.\n\n=== DESTINATAIRE ===\n- Mairie : {nom_mairie}\n- À l'attention de : {nom_maire}\n\n=== ASSOCIATION ===\n- Nom : {nom_asso}\n\n=== PROJET ===\n- Titre : {titre_projet}\n- Description : {description_projet}\n- Montant demandé : {montant_demande} €\n- Budget total : {budget_total} €\n- Autres financeurs : {autres_financeurs}\n- Impact local : {impact_local}\n- Durée : {duree_projet}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis une lettre de 500-700 mots structurée : formule d'appel, présentation brève de l'association (ancrage local), présentation du projet et son articulation aux enjeux de la commune, budget clair, impact attendu pour les habitants, demande de rendez-vous, formule de politesse, signature. Ton professionnel et concret.",
    ],

    'subvention_drac' => [
        'title' => 'Demande de subvention DRAC',
        'icon'  => '🎭',
        'category' => 'subvention',
        'fields' => [
            'region_drac'          => ['label' => 'DRAC / région', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: DRAC Île-de-France'],
            'projet_id'            => ['label' => 'Projet Assokit lié (optionnel)', 'type' => 'select_project'],
            'titre_projet'         => ['label' => 'Titre du projet culturel', 'type' => 'text', 'required' => true],
            'description_projet'   => ['label' => 'Description artistique', 'type' => 'textarea', 'required' => true, 'rows' => 8, 'placeholder' => "Ambition artistique, démarche, œuvres, artistes..."],
            'montant_demande'      => ['label' => 'Montant demandé (€)', 'type' => 'number', 'required' => true],
            'budget_total'         => ['label' => 'Budget total (€)', 'type' => 'number', 'required' => true],
            'partenaires_culturels'=> ['label' => 'Partenaires culturels', 'type' => 'text', 'placeholder' => 'Salles, festivals...'],
            'mediation_culturelle' => ['label' => 'Actions de médiation', 'type' => 'textarea', 'required' => true, 'rows' => 4, 'placeholder' => 'Ateliers, rencontres, publics ciblés...'],
            'territoire_impact'    => ['label' => 'Territoire et publics', 'type' => 'textarea', 'required' => true, 'rows' => 3],
        ],
        'system' => "Tu es un expert en dossiers culturels pour la DRAC. Tu maîtrises le vocabulaire du Ministère de la Culture : démarche artistique, création, diffusion, médiation, publics empêchés, EAC, droits culturels, diversité, territoires. Tu produis des dossiers ambitieux sur le fond et rigoureux sur l'impact.",
        'prompt_template' => "Rédige une demande de subvention à la DRAC.\n\n=== DESTINATAIRE ===\n- DRAC : {region_drac}\n\n=== ASSOCIATION ===\n- Nom : {nom_asso}\n\n=== PROJET CULTUREL ===\n- Titre : {titre_projet}\n- Description artistique : {description_projet}\n- Montant demandé : {montant_demande} €\n- Budget total : {budget_total} €\n- Partenaires culturels : {partenaires_culturels}\n- Médiation : {mediation_culturelle}\n- Territoire et publics : {territoire_impact}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis un courrier de 700-900 mots : formule d'appel, présentation de la démarche artistique globale, projet détaillé (intention, équipe, œuvres), inscription dans les politiques publiques (EAC, droits culturels), dispositif de médiation, ancrage territorial, plan de financement, formule de politesse soutenue. Vocabulaire DRAC exigeant et précis.",
    ],

    // ========== RAPPORT D'ACTIVITÉ ==========
    'rapport_activite' => [
        'title' => "✨ Rapport d'activité annuel",
        'icon'  => '📊',
        'category' => 'rapport',
        'note_v1' => "💡 En V1, tu décris manuellement les temps forts. En V4, ce rapport sera généré automatiquement depuis vos projets et événements.",
        'fields' => [
            'annee'        => ['label' => 'Année concernée', 'type' => 'number', 'required' => true, 'default' => (int) date('Y') - 1],
            'temps_forts'  => ['label' => "Temps forts de l'année (un par ligne)", 'type' => 'textarea', 'required' => true, 'rows' => 10, 'placeholder' => "Ex:\nOrganisation du festival XYZ (3 jours, 800 participants)\nLancement des ateliers hebdomadaires (40 enfants)\nPartenariat signé avec la Mairie..."],
            'chiffres_cles'=> ['label' => 'Chiffres clés', 'type' => 'textarea', 'required' => true, 'rows' => 6, 'placeholder' => "Adhérents : 124 (+18%)\nBénévoles actifs : 28\nÉvénements organisés : 15\nBénéficiaires : 1 200"],
            'difficultes'  => ['label' => 'Difficultés rencontrées', 'type' => 'textarea', 'rows' => 4],
            'perspectives' => ['label' => 'Perspectives année suivante', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'remerciements'=> ['label' => 'Partenaires à remercier', 'type' => 'text'],
        ],
        'system' => "Tu es un expert en rapports d'activité pour associations loi 1901. Tu produis des documents qui valorisent l'impact social sans tomber dans l'auto-congratulation. Tu structures avec des titres clairs, cites des chiffres précis, équilibres factuel et émotionnel.",
        'prompt_template' => "Rédige le rapport d'activité {annee} de l'association \"{nom_asso}\".\n\n=== DONNÉES ===\nTemps forts :\n{temps_forts}\n\nChiffres clés :\n{chiffres_cles}\n\nDifficultés :\n{difficultes}\n\nPerspectives {annee_plus_1} :\n{perspectives}\n\nPartenaires à remercier : {remerciements}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis un rapport de 1500-2000 mots avec cette structure :\n\n# RAPPORT D'ACTIVITÉ {annee}\n\n## 1. Le mot du président\n## 2. L'association en chiffres\n## 3. Nos actions marquantes\n## 4. Nos partenariats\n## 5. Difficultés et apprentissages\n## 6. Perspectives {annee_plus_1}\n## 7. Remerciements\n\nTon chaleureux mais professionnel. Titres en Markdown.",
    ],

    // ========== RÉSEAUX SOCIAUX ==========
    'post_facebook' => [
        'title' => 'Post Facebook',
        'icon'  => '📘',
        'category' => 'reseaux_sociaux',
        'fields' => [
            'sujet'    => ['label' => 'Sujet du post', 'type' => 'textarea', 'required' => true, 'rows' => 4, 'placeholder' => 'Ex: Notre prochain festival les 15-16 juin avec 10 artistes locaux'],
            'objectif' => ['label' => 'Objectif', 'type' => 'select', 'required' => true, 'options' => ['Informer', 'Appel à action', 'Émouvoir / témoigner', 'Remercier', 'Recruter bénévoles']],
            'lien'     => ['label' => 'Lien à inclure (optionnel)', 'type' => 'text'],
        ],
        'system' => "Tu es un community manager pour associations. Tu rédiges des posts Facebook avec une accroche forte en première ligne, storytelling léger, emojis pertinents (pas trop), CTA clair, 3-5 hashtags en fin. Tu produis TOUJOURS 3 variantes.",
        'prompt_template' => "Rédige 3 variantes de post Facebook pour l'association {nom_asso}.\n\n=== SUJET ===\n{sujet}\n\n=== OBJECTIF ===\n{objectif}\n\n=== LIEN ===\n{lien}\n\nProduis 3 variantes séparées :\n\n---\n**🎯 Variante 1 — Factuelle / Info pure** (100-150 mots)\n\n---\n**❤️ Variante 2 — Émotionnelle / Storytelling** (100-150 mots)\n\n---\n**🚀 Variante 3 — Call-to-action fort** (80-120 mots)\n\nAccroche percutante, emojis modérés, 3-5 hashtags en fin.",
    ],

    'post_instagram' => [
        'title' => 'Post Instagram',
        'icon'  => '📸',
        'category' => 'reseaux_sociaux',
        'fields' => [
            'sujet'    => ['label' => 'Sujet du post', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'visuel'   => ['label' => 'Description du visuel', 'type' => 'text', 'placeholder' => 'Ex: photo du groupe en atelier'],
            'objectif' => ['label' => 'Objectif', 'type' => 'select', 'required' => true, 'options' => ['Engagement', 'Notoriété', 'Appel à action', 'Témoignage']],
        ],
        'system' => "Tu es un community manager Instagram pour associations. Tu sais que l'accroche est cruciale (2 premières phrases). Tu utilises des emojis expressifs, mise en forme aérée, 15-20 hashtags mix gros/moyens/petits.",
        'prompt_template' => "Rédige 2 variantes de caption Instagram pour {nom_asso}.\n\n=== SUJET ===\n{sujet}\n\n=== VISUEL ===\n{visuel}\n\n=== OBJECTIF ===\n{objectif}\n\n---\n**Variante 1 — Concise (150-200 mots)**\n[texte aéré, 2-3 emojis]\n🏷️ #[15-20 hashtags]\n\n---\n**Variante 2 — Storytelling long (250-350 mots)**\n[accroche forte, histoire développée]\n🏷️ #[15-20 hashtags]",
    ],

    'post_linkedin' => [
        'title' => 'Post LinkedIn',
        'icon'  => '💼',
        'category' => 'reseaux_sociaux',
        'fields' => [
            'sujet' => ['label' => 'Sujet du post', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'angle' => ['label' => 'Angle / ce que vous voulez montrer', 'type' => 'select', 'required' => true, 'options' => ['Impact social / résultats', 'Témoignage / expertise', 'Recherche de partenariats', 'Recrutement bénévoles cadres', 'Retour d\'expérience']],
        ],
        'system' => "Tu es un rédacteur LinkedIn expert pour l'ESS. Tu connais les codes : accroche forte, mise en forme aérée (1-2 phrases/bloc), ton pro mais humain, CTA en fin (commentaire, partage), 3-5 hashtags pro.",
        'prompt_template' => "Rédige un post LinkedIn pour {nom_asso}.\n\n=== SUJET ===\n{sujet}\n\n=== ANGLE ===\n{angle}\n\n=== AUTEUR ===\n{prenom_user} {nom_user}\n\nProduis 2 variantes :\n\n---\n**Variante 1 — Court (200-300 mots)**\n[accroche, storytelling, CTA + hashtags]\n\n---\n**Variante 2 — Long (400-600 mots)**\n[histoire développée, enseignement, chiffres, CTA + hashtags]\n\nVocabulaire pro mais humain, pas de langue de bois.",
    ],

    'serie_multi_reseaux' => [
        'title' => 'Série multi-plateformes',
        'icon'  => '🎯',
        'category' => 'reseaux_sociaux',
        'fields' => [
            'sujet'    => ['label' => 'Sujet / actualité à décliner', 'type' => 'textarea', 'required' => true, 'rows' => 5],
            'objectif' => ['label' => 'Objectif commun', 'type' => 'select', 'required' => true, 'options' => ['Informer', 'Mobiliser', 'Remercier', 'Recruter']],
            'lien'     => ['label' => 'Lien à inclure', 'type' => 'text'],
        ],
        'system' => "Tu es un expert cross-canal qui adapte un message à Facebook (chaleureux, communautaire), Instagram (visuel, émotionnel), LinkedIn (pro, narratif).",
        'prompt_template' => "Décline ce sujet en 3 posts adaptés pour {nom_asso}.\n\n=== SUJET ===\n{sujet}\n\n=== OBJECTIF ===\n{objectif}\n\n=== LIEN ===\n{lien}\n\n---\n### 📘 FACEBOOK (120-180 mots)\n[chaleureux, accroche forte, emojis modérés, 3-5 hashtags]\n\n---\n### 📸 INSTAGRAM (150-200 mots + hashtags)\n[visuel, émotionnel, aéré]\n🏷️ #[15 hashtags]\n\n---\n### 💼 LINKEDIN (250-350 mots)\n[pro, narratif, enseignement, CTA, 3-5 hashtags pro]",
    ],
    // ========== V1 — VIE ASSOCIATIVE ==========
    'convocation_ag_extra' => [
        'title' => 'Convocation AG extraordinaire',
        'icon'  => '⚖️',
        'category' => 'ag',
        'fields' => [
            'date_ag'              => ['label' => "Date et heure de l'AGE", 'type' => 'datetime-local', 'required' => true],
            'lieu_ag'              => ['label' => 'Lieu (salle + adresse)', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: Salle des fêtes, 12 rue de la Mairie, 91130 Ris-Orangis'],
            'motif'                => ['label' => 'Motif de la convocation', 'type' => 'select', 'required' => true, 'options' => ['Modification des statuts', 'Dissolution', 'Fusion / scission', 'Radiation d\'un membre', 'Acquisition / cession de bien immobilier', 'Autre décision exceptionnelle']],
            'ordre_du_jour'        => ['label' => 'Ordre du jour (un point par ligne)', 'type' => 'textarea', 'required' => true, 'rows' => 5, 'placeholder' => "Présentation du projet de modification\nDébat et questions\nVote des résolutions"],
            'texte_resolutions'    => ['label' => 'Texte exact des résolutions soumises au vote', 'type' => 'textarea', 'required' => true, 'rows' => 8, 'placeholder' => "Résolution 1 : Modification de l'article 5 des statuts, qui devient...\nRésolution 2 : ..."],
            'quorum_requis'        => ['label' => 'Quorum requis (statutaire)', 'type' => 'text', 'placeholder' => 'Ex: 2/3 des membres à jour de cotisation'],
            'majorite_requise'     => ['label' => 'Majorité requise pour adoption', 'type' => 'text', 'placeholder' => 'Ex: majorité des 2/3 des présents et représentés'],
            'date_limite_pouvoir'  => ['label' => 'Date limite envoi pouvoir', 'type' => 'date'],
            'contact_info'         => ['label' => 'Contact pour questions', 'type' => 'text', 'placeholder' => 'Email + téléphone'],
        ],
        'system' => "Tu es un expert en droit associatif français (loi 1901) spécialisé dans les Assemblées Générales Extraordinaires. Tu rédiges des convocations formelles, juridiquement opposables, qui rappellent les enjeux exceptionnels et les conditions de quorum/majorité renforcées propres à l'AGE. Tu utilises un ton solennel et précis.",
        'prompt_template' => "Rédige une convocation d'Assemblée Générale Extraordinaire pour l'association \"{nom_asso}\".\n\n=== INFOS ASSO ===\n- Nom : {nom_asso}\n- Email : {email_asso}\n\n=== AGE ===\n- Date et heure : {date_ag}\n- Lieu : {lieu_ag}\n- Motif : {motif}\n- Ordre du jour :\n{ordre_du_jour}\n- Texte des résolutions soumises au vote :\n{texte_resolutions}\n- Quorum requis : {quorum_requis}\n- Majorité requise : {majorite_requise}\n- Date limite pouvoir : {date_limite_pouvoir}\n- Contact : {contact_info}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}, Président(e) de l'association\n\nRédige une lettre formelle complète avec : en-tête \"Association {nom_asso}\", date du jour, objet (mentionnant explicitement le caractère EXTRAORDINAIRE), préambule rappelant le motif et son importance, corps détaillé avec ordre du jour, texte précis des résolutions soumises au vote (sous forme numérotée), rappel insistant du quorum et de la majorité qualifiée requise pour ce type d'AG, modalités de pouvoir, formule de politesse, signature. Ton solennel. Environ 500-650 mots.",
    ],

    'rapport_moral' => [
        'title' => 'Rapport moral du président',
        'icon'  => '🎤',
        'category' => 'ag',
        'fields' => [
            'exercice'        => ['label' => 'Exercice concerné', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 2025 ou 2024-2025'],
            'bilan_annee'     => ['label' => "Bilan de l'année (réalisations, événements, projets)", 'type' => 'textarea', 'required' => true, 'rows' => 8, 'placeholder' => "Liste les temps forts : projets, événements, partenariats, chiffres clés..."],
            'difficultes'     => ['label' => 'Difficultés rencontrées (optionnel)', 'type' => 'textarea', 'rows' => 4, 'placeholder' => 'Ce qui a été moins facile, les obstacles, les leçons apprises...'],
            'gouvernance'     => ['label' => 'Vie de la gouvernance (CA, Bureau, bénévoles)', 'type' => 'textarea', 'rows' => 4, 'placeholder' => 'Réunions tenues, renouvellement, engagement des bénévoles...'],
            'partenaires'     => ['label' => 'Partenaires et soutiens clés (optionnel)', 'type' => 'textarea', 'rows' => 3, 'placeholder' => 'Financeurs, partenaires institutionnels et associatifs...'],
            'perspectives'    => ['label' => "Perspectives et projets pour l'année à venir", 'type' => 'textarea', 'required' => true, 'rows' => 5, 'placeholder' => "Vision, projets prioritaires, ambitions..."],
            'remerciements'   => ['label' => 'Personnes à remercier nommément (optionnel)', 'type' => 'textarea', 'rows' => 3, 'placeholder' => 'Bénévoles, partenaires, salariés...'],
        ],
        'system' => "Tu es un expert en gouvernance associative qui rédige des rapports moraux de président pour des Assemblées Générales d'associations loi 1901. Tu produis un texte chaleureux, mobilisateur et fier, qui valorise l'engagement collectif. Tu équilibres bilan factuel, reconnaissance des personnes et vision prospective. Tu utilises la première personne du pluriel quand c'est pertinent.",
        'prompt_template' => "Rédige le rapport moral du président pour l'AG de l'association \"{nom_asso}\".\n\n=== INFOS ===\n- Association : {nom_asso}\n- Exercice : {exercice}\n- Président(e) signataire : {prenom_user} {nom_user}\n\n=== BILAN ===\n{bilan_annee}\n\n=== DIFFICULTÉS ===\n{difficultes}\n\n=== GOUVERNANCE ===\n{gouvernance}\n\n=== PARTENAIRES ===\n{partenaires}\n\n=== PERSPECTIVES ===\n{perspectives}\n\n=== REMERCIEMENTS ===\n{remerciements}\n\nProduis un rapport moral structuré : (1) introduction chaleureuse adressée aux adhérents et partenaires, (2) bilan de l'année avec mise en valeur des réussites et reconnaissance des difficultés surmontées, (3) éclairage sur la vie de la gouvernance et l'engagement des bénévoles, (4) remerciements personnalisés aux partenaires et soutiens, (5) perspectives et ambitions pour l'année à venir, (6) appel à la mobilisation collective en conclusion. Ton : sincère, fier, mobilisateur. Environ 700-1000 mots. Signature : {prenom_user} {nom_user}, Président(e).",
    ],

    'convocation_ca' => [
        'title' => 'Convocation CA / Bureau',
        'icon'  => '📅',
        'category' => 'ag',
        'fields' => [
            'type_instance'      => ['label' => 'Instance convoquée', 'type' => 'select', 'required' => true, 'options' => ['Conseil d\'administration', 'Bureau', 'Bureau élargi']],
            'date_reunion'       => ['label' => 'Date et heure', 'type' => 'datetime-local', 'required' => true],
            'duree_estimee'      => ['label' => 'Durée estimée', 'type' => 'text', 'placeholder' => 'Ex: 2 heures'],
            'format'             => ['label' => 'Format', 'type' => 'select', 'required' => true, 'options' => ['Présentiel', 'Visioconférence', 'Hybride (présentiel + visio)']],
            'lieu_reunion'       => ['label' => 'Lieu (si présentiel ou hybride)', 'type' => 'text', 'placeholder' => 'Adresse complète'],
            'lien_visio'         => ['label' => 'Lien visio (si visio ou hybride)', 'type' => 'text', 'placeholder' => 'https://meet.jit.si/...'],
            'ordre_du_jour'      => ['label' => 'Ordre du jour (un point par ligne)', 'type' => 'textarea', 'required' => true, 'rows' => 6, 'placeholder' => "Validation PV précédent\nPoint financier\nPréparation AG\nQuestions diverses"],
            'documents_joints'   => ['label' => 'Documents préparatoires (optionnel)', 'type' => 'textarea', 'rows' => 3, 'placeholder' => 'Liste des documents à étudier avant la réunion (titres + liens)'],
            'contact_info'       => ['label' => "Contact en cas d'empêchement", 'type' => 'text', 'placeholder' => 'Email + téléphone'],
        ],
        'system' => "Tu es secrétaire d'une association loi 1901. Tu rédiges des convocations claires et concises pour les réunions du Conseil d'administration et du Bureau. Ton : professionnel, opérationnel, cordial. Format court et efficace (les administrateurs sont déjà engagés, pas besoin de longueurs).",
        'prompt_template' => "Rédige une convocation pour une réunion de {type_instance} de l'association \"{nom_asso}\".\n\n=== RÉUNION ===\n- Instance : {type_instance}\n- Date et heure : {date_reunion}\n- Durée estimée : {duree_estimee}\n- Format : {format}\n- Lieu : {lieu_reunion}\n- Lien visio : {lien_visio}\n\n=== ORDRE DU JOUR ===\n{ordre_du_jour}\n\n=== DOCUMENTS À ÉTUDIER ===\n{documents_joints}\n\n=== CONTACT ===\n{contact_info}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nRédige une convocation efficace : (1) en-tête \"Association {nom_asso}\", date du jour, (2) objet précis, (3) corps court annonçant la réunion avec date/heure/format/lieu (ou lien visio si applicable), (4) ordre du jour en liste numérotée, (5) mention des documents préparatoires si présents, (6) appel à confirmer la présence et à signaler tout empêchement, (7) formule de politesse cordiale, (8) signature. Environ 250-350 mots, format opérationnel.",
    ],


    // ========== V2 — DONS ==========
    'appel_dons' => [
        'title' => 'Appel à dons',
        'icon'  => '💌',
        'category' => 'dons',
        'fields' => [
            'nom_campagne'       => ['label' => 'Nom de la campagne', 'type' => 'text', 'required' => true, 'placeholder' => "Ex: Aidez-nous à équiper notre nouveau local"],
            'objectif_financier' => ['label' => 'Objectif financier', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 5 000 €'],
            'besoin_concret'     => ['label' => "À quoi va servir l'argent (concrètement)", 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'histoire_emotion'   => ['label' => 'Histoire ou témoignage qui mobilise', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'impact_chiffres'    => ['label' => 'Chiffres clés (10 € = X)', 'type' => 'textarea', 'rows' => 3, 'placeholder' => "10 € = 1 repas\n50 € = 1 atelier\n200 € = 1 mois de loyer"],
            'date_limite'        => ['label' => 'Date limite de la campagne', 'type' => 'date'],
            'lien_don'           => ['label' => 'Lien pour donner (HelloAsso, etc.)', 'type' => 'text'],
            'canal'              => ['label' => 'Canal de diffusion', 'type' => 'select', 'required' => true, 'options' => ['Email aux adhérents/donateurs', 'Réseaux sociaux (post court)', 'Lettre postale (format long)']],
        ],
        'system' => "Tu es un expert en collecte de fonds pour associations loi 1901. Tu écris des appels à dons qui combinent émotion sincère, transparence sur l'usage des fonds, et call-to-action clair. Tu équilibres histoire humaine et chiffres concrets. Tu adaptes le ton au canal : Email = chaleureux et long, Réseaux sociaux = court et viral, Lettre postale = personnel et formel.",
        'prompt_template' => "Rédige un appel à dons pour l'association \"{nom_asso}\".\n\n=== CAMPAGNE ===\n- Nom : {nom_campagne}\n- Objectif : {objectif_financier}\n- Canal : {canal}\n- Date limite : {date_limite}\n- Lien pour donner : {lien_don}\n\n=== POURQUOI ===\n{besoin_concret}\n\n=== HISTOIRE ===\n{histoire_emotion}\n\n=== IMPACT CHIFFRÉ ===\n{impact_chiffres}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis : (1) accroche émotionnelle qui capte en 5 secondes, (2) explication du besoin avec l'histoire, (3) transparence chiffrée sur l'usage des fonds, (4) CTA fort avec rappel déduction fiscale 66%, (5) signature personnelle. Mots : Email 400-600, RS 80-150, Lettre 500-800.",
    ],

    'remerciement_donateur' => [
        'title' => 'Remerciement donateur',
        'icon'  => '🙏',
        'category' => 'dons',
        'fields' => [
            'prenom_donateur'    => ['label' => 'Prénom du donateur', 'type' => 'text', 'required' => true],
            'nom_donateur'       => ['label' => 'Nom du donateur', 'type' => 'text', 'required' => true],
            'montant_don'        => ['label' => 'Montant du don', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 50 €'],
            'date_don'           => ['label' => 'Date du don', 'type' => 'date', 'required' => true],
            'affectation'        => ['label' => "Affectation (si don fléché)", 'type' => 'text', 'placeholder' => "Ex: Projet Insertion Numérique"],
            'numero_recu'        => ['label' => 'Numéro du reçu fiscal joint', 'type' => 'text', 'placeholder' => 'Ex: 2026-042'],
            'nb_dons_precedents' => ['label' => 'Dons précédents (valoriser fidélité)', 'type' => 'number'],
        ],
        'system' => "Tu es responsable des relations donateurs d'une association loi 1901. Tu écris des lettres de remerciement chaleureuses, sincères, qui valorisent le geste sans tomber dans la flagornerie. Tu rappelles toujours la déduction fiscale de 66% et le reçu fiscal joint.",
        'prompt_template' => "Rédige une lettre de remerciement pour un donateur de \"{nom_asso}\".\n\n=== DONATEUR ===\n{prenom_donateur} {nom_donateur}\nDon : {montant_don} le {date_don}\nAffectation : {affectation}\nDons précédents : {nb_dons_precedents}\n\n=== REÇU FISCAL ===\nN° : {numero_recu}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis : (1) remerciement personnalisé (souligner fidélité si dons précédents > 0), (2) impact concret du don (\"grâce à vous...\"), (3) rappel déduction fiscale 66%, (4) mention reçu fiscal joint, (5) ouverture (newsletter, AG), (6) signature. 200-300 mots. Ton sincère et chaleureux.",
    ],

    // ========== V3 — ADHÉRENTS & BÉNÉVOLES ==========
    'appel_benevoles' => [
        'title' => 'Appel à bénévoles',
        'icon'  => '🙌',
        'category' => 'adherents',
        'fields' => [
            'mission'                 => ['label' => 'Mission proposée', 'type' => 'text', 'required' => true, 'placeholder' => "Ex: Aider à organiser notre fête annuelle"],
            'competences_recherchees' => ['label' => 'Compétences recherchées (optionnel)', 'type' => 'textarea', 'rows' => 3],
            'engagement_horaire'      => ['label' => "Engagement horaire", 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 4h/semaine'],
            'duree'                   => ['label' => 'Durée de la mission', 'type' => 'text', 'placeholder' => 'Ex: 3 mois, ponctuel'],
            'lieu'                    => ['label' => 'Lieu (présentiel/distanciel)', 'type' => 'text'],
            'profil_ideal'            => ['label' => 'Profil idéal (optionnel)', 'type' => 'textarea', 'rows' => 3],
            'contact_inscription'     => ['label' => "Contact pour s'inscrire", 'type' => 'text', 'required' => true, 'placeholder' => 'Email + téléphone'],
        ],
        'system' => "Tu es coordinateur des bénévoles d'une association loi 1901. Tu rédiges des appels à bénévoles motivants et inclusifs. Tu valorises l'expérience humaine, tu es transparent sur l'engagement, et tu rassures sur l'accessibilité. Ton chaleureux, jamais culpabilisant.",
        'prompt_template' => "Rédige un appel à bénévoles pour \"{nom_asso}\".\n\n=== MISSION ===\n{mission}\n\n=== COMPÉTENCES ===\n{competences_recherchees}\n\n=== ENGAGEMENT ===\n- Temps : {engagement_horaire}\n- Durée : {duree}\n- Lieu : {lieu}\n\n=== PROFIL IDÉAL ===\n{profil_ideal}\n\n=== CONTACT ===\n{contact_inscription}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis : (1) accroche enthousiaste, (2) description claire de la mission, (3) ce que le bénévole APPORTE et ce qu'il VIT/APPREND, (4) précisions pratiques, (5) message inclusif (\"pas besoin d'expérience, juste de l'envie\"), (6) CTA contact clair. 250-400 mots.",
    ],

    'relance_cotisation' => [
        'title' => 'Relance cotisation',
        'icon'  => '⏰',
        'category' => 'adherents',
        'fields' => [
            'prenom_adherent'           => ['label' => "Prénom de l'adhérent", 'type' => 'text', 'required' => true],
            'nom_adherent'              => ['label' => "Nom de l'adhérent", 'type' => 'text', 'required' => true],
            'annee_derniere_cotisation' => ['label' => 'Année dernière cotisation', 'type' => 'text', 'placeholder' => 'Ex: 2024'],
            'montant_cotisation'        => ['label' => 'Montant à régler', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 30 €'],
            'ton'                       => ['label' => 'Ton du message', 'type' => 'select', 'required' => true, 'options' => ["Douce (1er rappel — présume l'oubli)", 'Ferme (2e rappel — précis et direct)', 'Ultime (avant radiation — respectueuse mais claire)']],
            'lien_paiement'             => ['label' => 'Lien de paiement', 'type' => 'text'],
            'date_limite'               => ['label' => 'Date limite pour régulariser', 'type' => 'date'],
            'mentions_avantages'        => ['label' => 'Avantages à rappeler', 'type' => 'textarea', 'rows' => 3],
        ],
        'system' => "Tu rédiges des emails de relance de cotisation pour une association loi 1901. Tu adaptes STRICTEMENT le ton selon le niveau : 1er = chaleureux (oubli présumé), 2e = ferme et précis, 3e/ultime = respectueux mais clair sur les conséquences. Tu rappelles toujours les avantages de l'adhésion.",
        'prompt_template' => "Rédige un email de relance de cotisation pour \"{nom_asso}\".\n\n=== ADHÉRENT ===\n{prenom_adherent} {nom_adherent}\nDernière cotisation : {annee_derniere_cotisation}\nMontant : {montant_cotisation}\n\n=== TON ===\n{ton}\n\n=== INFOS ===\nLien : {lien_paiement}\nDate limite : {date_limite}\nAvantages : {mentions_avantages}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis : (1) objet d'email adapté au ton, (2) accroche cohérente, (3) rappel factuel, (4) avantages de l'adhésion, (5) CTA + lien + date, (6) si ton ultime : mention claire des conséquences. 200-300 mots. Ne jamais culpabiliser.",
    ],

    'accueil_nouveau_membre' => [
        'title' => "Accueil nouveau membre",
        'icon'  => '👋',
        'category' => 'adherents',
        'fields' => [
            'prenom_membre'        => ['label' => 'Prénom du nouveau membre', 'type' => 'text', 'required' => true],
            'nom_membre'           => ['label' => 'Nom du nouveau membre', 'type' => 'text', 'required' => true],
            'date_adhesion'        => ['label' => "Date d'adhésion", 'type' => 'date', 'required' => true],
            'type_adhesion'        => ['label' => "Type d'adhésion", 'type' => 'text', 'placeholder' => 'Ex: Membre actif, Bienfaiteur...'],
            'prochains_evenements' => ['label' => 'Prochains événements à mentionner', 'type' => 'textarea', 'rows' => 3],
            'infos_pratiques'      => ['label' => 'Infos pratiques (comment participer)', 'type' => 'textarea', 'rows' => 4],
            'documents_joints'     => ['label' => 'Documents joints (optionnel)', 'type' => 'textarea', 'rows' => 2],
        ],
        'system' => "Tu écris des emails de bienvenue chaleureux pour les nouveaux adhérents d'une association loi 1901. Tu crées du lien dès le premier contact, donnes envie de s'engager, et fournis les infos pratiques essentielles sans noyer. Ton chaleureux et accueillant.",
        'prompt_template' => "Rédige un email de bienvenue pour un nouveau membre de \"{nom_asso}\".\n\n=== MEMBRE ===\n{prenom_membre} {nom_membre}\nAdhésion : {type_adhesion} le {date_adhesion}\n\n=== ÉVÉNEMENTS À VENIR ===\n{prochains_evenements}\n\n=== INFOS PRATIQUES ===\n{infos_pratiques}\n\n=== DOCUMENTS ===\n{documents_joints}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis : (1) accroche chaleureuse personnalisée, (2) mot de bienvenue valorisant sa décision, (3) brève présentation de la mission de l'asso, (4) prochains événements pour l'inviter, (5) infos pratiques claires, (6) ouverture (à très bientôt), (7) signature. 250-400 mots.",
    ],

    'attestation_benevolat' => [
        'title' => 'Attestation de bénévolat',
        'icon'  => '📜',
        'category' => 'adherents',
        'fields' => [
            'prenom_benevole'    => ['label' => 'Prénom du bénévole', 'type' => 'text', 'required' => true],
            'nom_benevole'       => ['label' => 'Nom du bénévole', 'type' => 'text', 'required' => true],
            'periode_debut'      => ['label' => 'Début de la période', 'type' => 'date', 'required' => true],
            'periode_fin'        => ['label' => 'Fin de la période', 'type' => 'date', 'required' => true],
            'nb_heures'          => ['label' => "Nombre d'heures de bénévolat", 'type' => 'number', 'required' => true],
            'nature_missions'    => ['label' => 'Nature des missions exercées', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'numero_attestation' => ['label' => "N° d'attestation (optionnel)", 'type' => 'text'],
            'usage_attestation'  => ['label' => "Usage prévu", 'type' => 'select', 'options' => ['Compte engagement citoyen (CEC)', 'Validation des acquis (VAE)', 'CV / dossier professionnel', 'Dossier administratif', 'Autre']],
        ],
        'system' => "Tu rédiges des attestations de bénévolat formelles et juridiquement valables pour des associations loi 1901. Ton administratif, précis, daté. Le document doit être utilisable pour le Compte engagement citoyen (CEC), la VAE, ou tout dossier officiel. Tu utilises les mentions légales standards.",
        'prompt_template' => "Rédige une attestation de bénévolat pour \"{nom_asso}\".\n\n=== BÉNÉVOLE ===\n{prenom_benevole} {nom_benevole}\n\n=== PÉRIODE ===\nDu {periode_debut} au {periode_fin}\nHeures : {nb_heures}\n\n=== MISSIONS ===\n{nature_missions}\n\n=== USAGE ===\n{usage_attestation}\n\n=== RÉFÉRENCE ===\nN° : {numero_attestation}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}, Président(e) de {nom_asso}\n\nProduis une attestation formelle : (1) en-tête institutionnel, (2) titre \"ATTESTATION DE BÉNÉVOLAT\" + N°, (3) formulation légale (\"Je soussigné(e)... atteste que...\"), (4) identité bénévole + période + heures, (5) description précise des missions, (6) usage prévu, (7) formule \"Fait à [ville], le [date], pour servir et valoir ce que de droit\", (8) signature + cachet. 250-400 mots. Format administratif.",
    ],

    'newsletter_mensuelle' => [
        'title' => 'Newsletter mensuelle',
        'icon'  => '📨',
        'category' => 'adherents',
        'fields' => [
            'mois_concerne'      => ['label' => 'Mois / période', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: Octobre 2025'],
            'actu_phare'         => ['label' => 'Actualité phare du mois', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'evenements_passes'  => ['label' => 'Retour sur événements passés', 'type' => 'textarea', 'rows' => 4],
            'evenements_a_venir' => ['label' => 'Événements à venir', 'type' => 'textarea', 'rows' => 4],
            'chiffres_cles'      => ['label' => 'Chiffres clés (optionnel)', 'type' => 'textarea', 'rows' => 3],
            'appel_action'       => ['label' => 'Appel à action principal', 'type' => 'textarea', 'rows' => 2],
            'mot_president'      => ['label' => 'Édito / mot du président', 'type' => 'textarea', 'rows' => 4],
        ],
        'system' => "Tu rédiges la newsletter mensuelle d'une association loi 1901. Tu produis une lettre structurée, agréable à lire, avec édito personnel, sections claires (rétro, à venir, chiffres, appel), et ton chaleureux qui crée du lien. Format : sections balisées par emojis et titres clairs, paragraphes courts.",
        'prompt_template' => "Rédige la newsletter mensuelle de \"{nom_asso}\".\n\n=== MOIS ===\n{mois_concerne}\n\n=== ÉDITO ===\n{mot_president}\n\n=== ACTU PHARE ===\n{actu_phare}\n\n=== RÉTRO ===\n{evenements_passes}\n\n=== À VENIR ===\n{evenements_a_venir}\n\n=== CHIFFRES ===\n{chiffres_cles}\n\n=== APPEL ===\n{appel_action}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis avec : (1) objet email accrocheur, (2) édito court et personnel, (3) section ✨ À LA UNE, (4) section 📅 RETOUR SUR LE MOIS, (5) section 🚀 À NE PAS RATER, (6) section 📊 EN CHIFFRES, (7) appel à action mis en valeur, (8) clôture chaleureuse + signature. Titres avec emoji + paragraphes courts. 600-900 mots.",
    ],

    // ========== V4 — STORY + RAPPORTS ==========
    'story_instagram' => [
        'title' => 'Story Instagram',
        'icon'  => '📱',
        'category' => 'reseaux_sociaux',
        'fields' => [
            'sujet'     => ['label' => 'Sujet de la Story', 'type' => 'textarea', 'required' => true, 'rows' => 3],
            'nb_slides' => ['label' => 'Nombre de slides', 'type' => 'select', 'required' => true, 'options' => ['3 slides (express)', '5 slides (standard)', '7 slides (immersif)']],
            'objectif'  => ['label' => 'Objectif principal', 'type' => 'select', 'required' => true, 'options' => ['Informer', 'Émouvoir', 'Mobiliser (don / bénévolat)', 'Annoncer un événement', 'Présenter une équipe / un projet']],
            'ton'       => ['label' => 'Ton', 'type' => 'select', 'options' => ['Inspirant', 'Léger / drôle', 'Sérieux / engagé', 'Personnel / témoignage']],
            'cta_final' => ['label' => 'Call-to-action final', 'type' => 'text', 'placeholder' => 'Ex: Lien en bio, Swipe up, DM nous...'],
        ],
        'system' => "Tu es expert en storytelling Instagram pour associations. Tu conçois des Stories qui captent en 3 secondes, gardent l'attention, et terminent sur un CTA clair. Format slide-par-slide : texte court (max 50 mots/slide), suggestion visuel, idée de sticker ou interaction.",
        'prompt_template' => "Conçois une Story Instagram pour \"{nom_asso}\".\n\n=== SUJET ===\n{sujet}\n\n=== FORMAT ===\n- Slides : {nb_slides}\n- Objectif : {objectif}\n- Ton : {ton}\n- CTA final : {cta_final}\n\nProduis le scénario slide par slide. Pour chaque slide :\n- 📝 TEXTE (max 50 mots, accrocheur)\n- 🎨 SUGGESTION VISUEL (description image/vidéo)\n- ✨ STICKER / INTERACTION (sondage, question, GIF, compte à rebours)\n\nStructure : slide 1 = hook fort, slides milieu = développement, dernière = CTA. Format clair, scannable.",
    ],

    'rapport_financier' => [
        'title' => 'Rapport financier simplifié',
        'icon'  => '💶',
        'category' => 'rapport',
        'fields' => [
            'exercice'         => ['label' => 'Exercice concerné', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 2024'],
            'total_produits'   => ['label' => 'Total des produits (recettes)', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 45 000 €'],
            'total_charges'    => ['label' => 'Total des charges (dépenses)', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 42 000 €'],
            'details_produits' => ['label' => 'Détail des produits', 'type' => 'textarea', 'required' => true, 'rows' => 5, 'placeholder' => "Cotisations : 5 000 €\nDons : 8 000 €\nSubvention : 10 000 €\nPrestations : 22 000 €"],
            'details_charges'  => ['label' => 'Détail des charges', 'type' => 'textarea', 'required' => true, 'rows' => 5, 'placeholder' => "Salaires : 25 000 €\nLocaux : 6 000 €\nFournitures : 4 000 €\nDéplacements : 7 000 €"],
            'tresorerie_debut' => ['label' => 'Trésorerie au 1er janvier', 'type' => 'text'],
            'tresorerie_fin'   => ['label' => 'Trésorerie au 31 décembre', 'type' => 'text'],
            'commentaires'     => ['label' => 'Faits marquants / écarts vs budget', 'type' => 'textarea', 'rows' => 4],
        ],
        'system' => "Tu rédiges des rapports financiers simplifiés pour AG d'associations loi 1901. Tu présentes les chiffres avec pédagogie : pas de jargon comptable, comparaisons claires, explications sur les variations. Ton neutre, précis, accessible aux non-comptables.",
        'prompt_template' => "Rédige le rapport financier de \"{nom_asso}\" pour {exercice}.\n\n=== TOTAUX ===\nProduits : {total_produits}\nCharges : {total_charges}\n\n=== DÉTAIL PRODUITS ===\n{details_produits}\n\n=== DÉTAIL CHARGES ===\n{details_charges}\n\n=== TRÉSORERIE ===\nDébut : {tresorerie_debut}\nFin : {tresorerie_fin}\n\n=== COMMENTAIRES ===\n{commentaires}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis : (1) introduction (cadre, exercice), (2) section PRODUITS avec % et commentaires, (3) section CHARGES idem, (4) RÉSULTAT NET avec analyse, (5) ÉVOLUTION TRÉSORERIE, (6) faits marquants vs budget, (7) PERSPECTIVES, (8) signature trésorier(e). Ton pédagogique. 700-1000 mots.",
    ],

    'bilan_projet' => [
        'title' => 'Bilan de projet',
        'icon'  => '📊',
        'category' => 'rapport',
        'fields' => [
            'nom_projet'             => ['label' => 'Nom du projet', 'type' => 'text', 'required' => true],
            'periode'                => ['label' => 'Période couverte', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: Janvier-Juin 2025'],
            'objectifs_initiaux'     => ['label' => 'Objectifs initiaux', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'actions_realisees'      => ['label' => 'Actions réalisées', 'type' => 'textarea', 'required' => true, 'rows' => 5],
            'beneficiaires'          => ['label' => 'Bénéficiaires (nombre + qui)', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: 120 jeunes 16-25 ans'],
            'budget_initial'         => ['label' => 'Budget initial', 'type' => 'text'],
            'budget_realise'         => ['label' => 'Budget réalisé', 'type' => 'text'],
            'resultats_quantitatifs' => ['label' => 'Résultats quantitatifs (KPI atteints)', 'type' => 'textarea', 'rows' => 4],
            'resultats_qualitatifs'  => ['label' => 'Résultats qualitatifs (impact humain)', 'type' => 'textarea', 'rows' => 4],
            'difficultes'            => ['label' => 'Difficultés rencontrées', 'type' => 'textarea', 'rows' => 3],
            'perspectives'           => ['label' => 'Perspectives / suite envisagée', 'type' => 'textarea', 'rows' => 3],
        ],
        'system' => "Tu rédiges des bilans de projet pour financeurs (Région, Département, Mairie, fondations) d'associations loi 1901. Ton factuel, valorisant sans exagérer, transparent sur les difficultés. Tu structures comme attendu par les bailleurs publics.",
        'prompt_template' => "Rédige le bilan du projet \"{nom_projet}\" porté par \"{nom_asso}\".\n\n=== CADRE ===\n- Projet : {nom_projet}\n- Période : {periode}\n- Bénéficiaires : {beneficiaires}\n\n=== OBJECTIFS INITIAUX ===\n{objectifs_initiaux}\n\n=== ACTIONS RÉALISÉES ===\n{actions_realisees}\n\n=== BUDGET ===\nPrévu : {budget_initial}\nRéalisé : {budget_realise}\n\n=== RÉSULTATS QUANTITATIFS ===\n{resultats_quantitatifs}\n\n=== RÉSULTATS QUALITATIFS ===\n{resultats_qualitatifs}\n\n=== DIFFICULTÉS ===\n{difficultes}\n\n=== PERSPECTIVES ===\n{perspectives}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis : (1) résumé exécutif (3-4 lignes), (2) RAPPEL DU PROJET, (3) ACTIONS, (4) RÉSULTATS quantitatifs (tableau si pertinent), (5) RÉSULTATS qualitatifs avec témoignages, (6) BILAN BUDGÉTAIRE (écarts), (7) DIFFICULTÉS et apprentissages, (8) PERSPECTIVES, (9) remerciements. 800-1200 mots. Ton factuel et valorisant.",
    ],

    // ========== V5 — COURRIERS OFFICIELS ==========
    'courrier_mairie' => [
        'title' => 'Courrier Mairie',
        'icon'  => '🏛',
        'category' => 'courriers',
        'fields' => [
            'nom_mairie'          => ['label' => 'Nom de la Mairie', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex: Mairie de Ris-Orangis'],
            'destinataire'        => ['label' => 'Destinataire', 'type' => 'text', 'required' => true, 'placeholder' => "Ex: M. le Maire / M. l'Adjoint à la Vie associative"],
            'type_courrier'       => ['label' => 'Type de demande', 'type' => 'select', 'required' => true, 'options' => ['Demande de mise à disposition de salle', 'Demande de soutien financier (subvention)', 'Demande de rendez-vous', "Demande d'autorisation (manifestation, espace public)", 'Remerciement', 'Autre courrier officiel']],
            'objet'               => ['label' => 'Objet précis', 'type' => 'text', 'required' => true],
            'motif_detaille'      => ['label' => 'Motif / contexte détaillé', 'type' => 'textarea', 'required' => true, 'rows' => 5],
            'date_demandee'       => ['label' => 'Date demandée (si pertinent)', 'type' => 'text'],
            'historique_relation' => ['label' => 'Historique de la relation (optionnel)', 'type' => 'textarea', 'rows' => 3],
            'pieces_jointes'      => ['label' => 'Pièces jointes', 'type' => 'textarea', 'rows' => 2],
        ],
        'system' => "Tu rédiges des courriers officiels d'associations loi 1901 vers les collectivités territoriales. Tu utilises le format institutionnel attendu : en-tête asso à gauche, destinataire à droite, objet, formules de politesse formelles, signature officielle. Ton déférent mais clair sur la demande.",
        'prompt_template' => "Rédige un courrier officiel de \"{nom_asso}\" vers la {nom_mairie}.\n\n=== DESTINATAIRE ===\n{destinataire}, {nom_mairie}\n\n=== TYPE ===\n{type_courrier}\n\n=== OBJET ===\n{objet}\n\n=== MOTIF ===\n{motif_detaille}\n\n=== DATE DEMANDÉE ===\n{date_demandee}\n\n=== HISTORIQUE ===\n{historique_relation}\n\n=== PIÈCES JOINTES ===\n{pieces_jointes}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}, Président(e) de {nom_asso}\n\nProduis un courrier institutionnel COMPLET : (1) en-tête \"Association {nom_asso}\" + adresse + contact, (2) destinataire haut droit, (3) lieu et date, (4) \"Objet :\", (5) \"P.J. :\", (6) appel formel (\"Monsieur le Maire,\"), (7) corps : présentation brève + motif + références historique + demande précise, (8) formule de politesse (\"Je vous prie d'agréer, Monsieur le Maire, l'expression de ma haute considération\"), (9) signature. 400-600 mots. Format institutionnel français.",
    ],

    'communique_presse' => [
        'title' => 'Communiqué de presse',
        'icon'  => '📰',
        'category' => 'courriers',
        'fields' => [
            'sujet'          => ['label' => 'Titre / sujet du CP', 'type' => 'text', 'required' => true],
            'accroche'       => ['label' => 'Accroche / phrase choc', 'type' => 'text', 'required' => true],
            'quoi'           => ['label' => "QUOI : l'événement / annonce", 'type' => 'textarea', 'required' => true, 'rows' => 3],
            'qui'            => ['label' => 'QUI : porteurs et partenaires', 'type' => 'text', 'required' => true],
            'quand'          => ['label' => 'QUAND : date / horaire', 'type' => 'text', 'required' => true],
            'ou'             => ['label' => 'OÙ : lieu', 'type' => 'text', 'required' => true],
            'pourquoi'       => ['label' => 'POURQUOI : contexte / motivation', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'comment'        => ['label' => 'COMMENT : modalités / déroulé', 'type' => 'textarea', 'rows' => 3],
            'chiffres_cles'  => ['label' => 'Chiffres clés (optionnel)', 'type' => 'textarea', 'rows' => 2],
            'citation'       => ['label' => 'Citation à reprendre', 'type' => 'textarea', 'rows' => 2],
            'contact_presse' => ['label' => 'Contact presse', 'type' => 'text', 'required' => true, 'placeholder' => 'Nom, mail, téléphone'],
        ],
        'system' => "Tu rédiges des communiqués de presse pour associations loi 1901, format pro respectant les codes journalistiques : pyramide inversée, 5W, citation, contact presse en bas, accroche percutante. Ton neutre et factuel (pas promotionnel). Un journaliste doit pouvoir reprendre des paragraphes tels quels.",
        'prompt_template' => "Rédige un communiqué de presse pour \"{nom_asso}\".\n\n=== SUJET ===\n{sujet}\n\n=== ACCROCHE ===\n{accroche}\n\n=== 5W ===\n- QUOI : {quoi}\n- QUI : {qui}\n- QUAND : {quand}\n- OÙ : {ou}\n- POURQUOI : {pourquoi}\n- COMMENT : {comment}\n\n=== CHIFFRES ===\n{chiffres_cles}\n\n=== CITATION ===\n{citation}\n\n=== CONTACT PRESSE ===\n{contact_presse}\n\nProduis un CP en pyramide inversée : (1) \"COMMUNIQUÉ DE PRESSE\" + ville + date, (2) TITRE percutant (max 12 mots), (3) chapô en gras (2-3 phrases), (4) §1 = essentiel (5W condensés), (5) §2-3 = contexte et pourquoi, (6) §4 = citation, (7) §5 = chiffres clés / infos pratiques, (8) \"À propos de {nom_asso}\" (3-4 lignes), (9) CONTACT PRESSE en bas. 350-500 mots. Ton journalistique.",
    ],

    'invitation_presse' => [
        'title' => 'Invitation presse',
        'icon'  => '🎥',
        'category' => 'courriers',
        'fields' => [
            'evenement'     => ['label' => "Nom de l'événement", 'type' => 'text', 'required' => true],
            'date_heure'    => ['label' => 'Date et heure', 'type' => 'datetime-local', 'required' => true],
            'lieu'          => ['label' => 'Lieu précis', 'type' => 'text', 'required' => true],
            'accroche'      => ['label' => "Pourquoi c'est intéressant pour la presse", 'type' => 'textarea', 'required' => true, 'rows' => 3],
            'intervenants'  => ['label' => 'Intervenants présents', 'type' => 'textarea', 'rows' => 3],
            'opportunites'  => ['label' => 'Opportunités éditoriales (photos, interviews...)', 'type' => 'textarea', 'rows' => 3],
            'rsvp_email'    => ['label' => 'Email RSVP / contact', 'type' => 'text', 'required' => true],
            'rsvp_deadline' => ['label' => 'Date limite de confirmation', 'type' => 'date'],
        ],
        'system' => "Tu rédiges des invitations presse pour associations loi 1901. Format court et accrocheur, propose une vraie opportunité éditoriale (sujet, image, interview). Mets en avant le côté inédit / utile / incarné. 200-300 mots max. Ton pro, direct, concret.",
        'prompt_template' => "Rédige une invitation presse pour \"{nom_asso}\".\n\n=== ÉVÉNEMENT ===\n{evenement}\nDate : {date_heure}\nLieu : {lieu}\n\n=== ACCROCHE ÉDITORIALE ===\n{accroche}\n\n=== INTERVENANTS ===\n{intervenants}\n\n=== OPPORTUNITÉS ===\n{opportunites}\n\n=== RSVP ===\n{rsvp_email} avant le {rsvp_deadline}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}\n\nProduis : (1) \"INVITATION PRESSE\" en haut, (2) titre accrocheur, (3) §1 pitch éditorial (pourquoi venir), (4) §2 infos pratiques, (5) §3 intervenants pour interviews, (6) §4 opportunités (photos, vidéos, démos), (7) RSVP clair, (8) signature + contact. 200-300 mots.",
    ],

    'partenariat_entreprise' => [
        'title' => 'Demande de partenariat entreprise',
        'icon'  => '🤝',
        'category' => 'courriers',
        'fields' => [
            'nom_entreprise'         => ['label' => "Nom de l'entreprise ciblée", 'type' => 'text', 'required' => true],
            'interlocuteur'          => ['label' => 'Interlocuteur (optionnel)', 'type' => 'text', 'placeholder' => 'Ex: Mme XXX, Directrice RSE'],
            'type_partenariat'       => ['label' => 'Type de partenariat', 'type' => 'select', 'required' => true, 'options' => ['Mécénat financier (défisc. 60% IS)', 'Mécénat de compétence (mise à dispo salarié)', 'Sponsoring (avec contreparties)', 'Don de matériel / produit', 'Partenariat opérationnel']],
            'montant_souhaite'       => ['label' => 'Montant souhaité (si financier)', 'type' => 'text', 'placeholder' => 'Ex: 5 000 €'],
            'projet_concerne'        => ['label' => 'Projet ou action à financer', 'type' => 'text', 'required' => true],
            'arguments_alignement'   => ['label' => 'Pourquoi cette entreprise (valeurs, secteur, RSE)', 'type' => 'textarea', 'required' => true, 'rows' => 4],
            'contreparties_proposees' => ['label' => 'Contreparties proposées', 'type' => 'textarea', 'rows' => 4],
            'historique_partenariat' => ['label' => 'Historique avec cette entreprise', 'type' => 'textarea', 'rows' => 2],
        ],
        'system' => "Tu rédiges des demandes de partenariat (mécénat ou sponsoring) à destination d'entreprises pour des associations loi 1901. Tu connais la fiscalité du mécénat (60% de réduction d'IS dans la limite de 0,5% du CA), tu valorises les contreparties RSE, et tu construis un argumentaire d'alignement de valeurs spécifique. Ton pro, ciblé, gagnant-gagnant, jamais quémandeur.",
        'prompt_template' => "Rédige une lettre de partenariat de \"{nom_asso}\" vers \"{nom_entreprise}\".\n\n=== ENTREPRISE ===\n{nom_entreprise}\nInterlocuteur : {interlocuteur}\n\n=== TYPE ===\n{type_partenariat}\nMontant : {montant_souhaite}\n\n=== PROJET ===\n{projet_concerne}\n\n=== POURQUOI VOUS ===\n{arguments_alignement}\n\n=== CONTREPARTIES ===\n{contreparties_proposees}\n\n=== HISTORIQUE ===\n{historique_partenariat}\n\n=== SIGNATAIRE ===\n{prenom_user} {nom_user}, Président(e) de {nom_asso}\n\nProduis une lettre business : (1) en-tête + destinataire, (2) accroche personnalisée qui montre que tu connais l'entreprise, (3) présentation brève et impactante de l'asso (mission + chiffres clés), (4) projet à soutenir (besoin, objectifs, public), (5) ARGUMENTAIRE D'ALIGNEMENT spécifique entreprise-asso, (6) demande précise (type + montant), (7) rappel mécanisme fiscal (mécénat 60% IS) si financier, (8) CONTREPARTIES détaillées, (9) proposition de RDV, (10) formule de politesse, (11) signature. 500-700 mots. Ton partenarial.",
    ],


];

// Type non supporté → retour catalogue
if (!$type || !isset($types_config[$type])) {
    render_head('Communication — Type non disponible');
    render_sidebar('communication');
    ?>
    <main class="main">
      <div class="empty-state" style="padding:60px 20px">
        <div style="font-size:56px;opacity:.35;margin-bottom:10px">🚧</div>
        <div style="font-size:18px;color:var(--ink);font-weight:500;margin-bottom:6px">
          Type "<?= h($type) ?>" pas encore disponible
        </div>
        <div style="max-width:460px;margin:0 auto 18px;color:var(--ink-3);line-height:1.55">
          Ce type sera ajouté dans une prochaine vague. Utilisez les types déjà disponibles en attendant.
        </div>
        <a href="/communication?tab=rediger" class="btn btn-primary">Retour au catalogue</a>
      </div>
    </main>
    <?php
    render_foot();
    exit;
}

$config = $types_config[$type];

// ==================================================================
// Traitement POST : génération IA
// ==================================================================
$generated = null;
$campaign_id = null;
$error = null;
$form_values = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('check_csrf') && check_csrf($_POST['csrf_token'] ?? '')) {

    // Construction du prompt
    $replacements = [
        '{nom_asso}'     => $org['name'] ?? '',
        '{email_asso}'   => $org['email'] ?? '',
        '{prenom_user}'  => $me['first_name'] ?? '',
        '{nom_user}'     => $me['last_name'] ?? '',
        '{annee}'        => (int) ($_POST['annee'] ?? date('Y') - 1),
        '{annee_plus_1}' => (int) ($_POST['annee'] ?? date('Y') - 1) + 1,
    ];

    foreach ($config['fields'] as $field_key => $field_def) {
        $value = $_POST[$field_key] ?? '';
        $replacements['{' . $field_key . '}'] = is_array($value) ? implode(', ', $value) : $value;
    }

    $prompt = strtr($config['prompt_template'], $replacements);

    // Libère le lock de session AVANT l'appel IA (10-30s, parfois +)
    // → l'utilisateur peut naviguer pendant la génération
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    // Laisse jusqu'à 3 min pour les longs documents (rapport, bilan...)
    @set_time_limit(200);
    @ini_set('max_execution_time', '200');

    // max_tokens adaptatif selon catégorie (évite les timeouts sur docs longs)
    $cat = $config['category'] ?? 'autre';
    $max_tok = match($cat) {
        'rapport', 'courriers', 'adherents' => 2500,
        'ag', 'dons' => 2200,
        default => 1800
    };
    // Appel IA (essaie plusieurs signatures possibles)
    error_log('[comm-gen] type=' . ($type ?? '?') . ' cat=' . $cat . ' max_tok=' . $max_tok);
    try {
        if (function_exists('ai_chat')) {
            $generated = ai_chat([['role' => 'user', 'content' => $prompt]], $config['system'], $max_tok);
        } elseif (function_exists('call_claude_api')) {
            $generated = call_claude_api($config['system'], [['role' => 'user', 'content' => $prompt]]);
        } elseif (function_exists('ask_claude')) {
            $result = ask_claude($config['system'], [['role' => 'user', 'content' => $prompt]], $max_tok);
            if (!empty($result['success'])) {
                $generated = $result['content'];
            } else {
                throw new RuntimeException($result['error'] ?? 'Erreur IA inconnue');
            }
        } else {
            $generated = fallback_claude_call($config['system'], $prompt);
        }

        // Sauvegarde en campaign (draft)
        $channel = ($config['category'] === 'reseaux_sociaux') ? 'facebook' : 'other';
        $stmt = $pdo->prepare("
            INSERT INTO communication_campaigns
                (org_id, created_by, channel, title, content, status, ai_generated, ai_cost_euros, created_at)
            VALUES (?, ?, ?, ?, ?, 'draft', 1, 0.03, NOW())
        ");
        $stmt->execute([
            $org_id, $user_id, $channel,
            $config['title'] . ' — ' . date('d/m/Y H:i'),
            $generated,
        ]);
        $campaign_id = (int)$pdo->lastInsertId();

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/**
 * Fallback cURL direct vers l'API Anthropic si ai-helper.php n'expose
 * aucune des fonctions connues. Utilise ANTHROPIC_API_KEY de config.php.
 */
function fallback_claude_call(string $system, string $user_message): string
{
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
        throw new RuntimeException('Clé API Anthropic non configurée.');
    }
    $payload = [
        'model'      => 'claude-sonnet-4-5',
        'max_tokens' => 4000,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $user_message]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 180,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code !== 200) {
        throw new RuntimeException("Erreur API Anthropic ($http_code) : $response");
    }
    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? '(Pas de contenu retourné)';
}

render_head($config['title']);
render_sidebar('communication');
?>

<main class="main">

  <div style="font-size:13px;color:var(--ink-3);margin-bottom:12px">
    <a href="/communication?tab=rediger" style="color:var(--ink-3)">💬 Communication</a>
    <span style="margin:0 6px">›</span>
    <span><?= h($config['title']) ?></span>
  </div>

  <div class="main-head">
    <div>
      <h1 class="page-title"><?= $config['icon'] ?> <?= h($config['title']) ?></h1>
      <?php if (!empty($config['note_v1'])): ?>
        <div class="page-sub" style="color:var(--ai-dark);background:var(--ai-light);padding:8px 12px;border-radius:8px;margin-top:8px;display:inline-block">
          <?= h($config['note_v1']) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <strong>⚠️ Erreur :</strong> <?= h($error) ?>
    </div>
  <?php endif; ?>

  <div class="gen-layout">

    <!-- Colonne gauche : Formulaire -->
    <aside class="gen-form-wrap">
      <form method="POST" action="/communication-generer">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="type" value="<?= h($type) ?>">

        <div class="gen-form-head">
          <div class="gen-form-title">📝 Informations</div>
          <div class="gen-form-sub">Plus vous remplissez, meilleur sera le résultat.</div>
        </div>

        <?php foreach ($config['fields'] as $key => $f): ?>
          <?php
          $required = !empty($f['required']);
          $placeholder = $f['placeholder'] ?? '';
          $rows = $f['rows'] ?? 3;
          $default = $form_values[$key] ?? ($f['default'] ?? '');
          ?>
          <div class="gen-group">
            <label for="f_<?= $key ?>">
              <?= h($f['label']) ?><?= $required ? ' <span style="color:#B91C1C">*</span>' : '' ?>
            </label>

            <?php if ($f['type'] === 'textarea'): ?>
              <textarea id="f_<?= $key ?>" name="<?= $key ?>" rows="<?= (int) $rows ?>"
                placeholder="<?= h($placeholder) ?>" <?= $required ? 'required' : '' ?>><?= h($default) ?></textarea>

            <?php elseif ($f['type'] === 'select'): ?>
              <select id="f_<?= $key ?>" name="<?= $key ?>" <?= $required ? 'required' : '' ?>>
                <option value="">— Choisir —</option>
                <?php foreach ($f['options'] as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $default === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>

            <?php elseif ($f['type'] === 'select_project'): ?>
              <?php
              $stmt = $pdo->prepare("SELECT p.id, p.title FROM projects p JOIN folders fo ON p.folder_id = fo.id WHERE fo.org_id = ? ORDER BY p.title");
              $stmt->execute([$org_id]);
              $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
              ?>
              <select id="f_<?= $key ?>" name="<?= $key ?>">
                <option value="">— Aucun —</option>
                <?php foreach ($projects as $p): ?>
                  <option value="<?= (int) $p['id'] ?>" <?= (string) $default === (string) $p['id'] ? 'selected' : '' ?>>
                    <?= h($p['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>

            <?php else: ?>
              <input type="<?= h($f['type']) ?>" id="f_<?= $key ?>" name="<?= $key ?>"
                value="<?= h((string) $default) ?>" placeholder="<?= h($placeholder) ?>"
                <?= $required ? 'required' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <button type="submit" id="gen-submit-btn" class="btn btn-primary" style="width:100%;margin-top:8px;padding:12px;font-size:14px"🔄 Génération en cours… (10-30s)';b.style.opacity='.7';},10);})(this);">
          <?= $generated ? '🔄 Régénérer' : '✨ Générer avec l\'IA' ?>
        </button>

        <?php if ($generated): ?>
          <div style="font-size:11.5px;color:var(--ink-3);text-align:center;margin-top:8px">
            💡 Modifiez les champs puis régénérez pour une variante
          </div>
        <?php endif; ?>
      </form>
    </aside>

    <!-- Colonne droite : Résultat -->
    <section class="gen-result-wrap">
      <?php if ($generated): ?>
        <div class="gen-result-head">
          <div class="gen-result-title">📄 Document généré</div>
          <div class="gen-result-actions">
            <button type="button" class="btn btn-ghost" onclick="copyGen()">📋 Copier</button>
            <?php if (!empty($campaign_id)): ?><a href="/communication-pdf?id=<?= (int)$campaign_id ?>" class="btn btn-ghost" target="_blank" rel="noopener">📄 Télécharger PDF</a><?php else: ?><button type="button" class="btn btn-ghost" onclick="downloadGen()">💾 Télécharger</button><?php endif; ?>
            <button type="button" class="btn btn-ghost" onclick="saveGen()">⭐ Sauvegarder</button>
          </div>
        </div>
        <textarea id="gen-content" class="gen-content" rows="28"><?= h($generated) ?></textarea>
        <div style="text-align:right;font-size:11.5px;color:var(--ink-3);margin-top:6px">
          Généré par Assokit IA
        </div>
      <?php else: ?>
        <div class="gen-empty">
          <div style="font-size:48px;margin-bottom:10px;opacity:.5">✨</div>
          <div style="font-size:16px;font-weight:500;color:var(--ink);margin-bottom:4px">
            Votre document apparaîtra ici
          </div>
          <div style="font-size:13px;color:var(--ink-3);line-height:1.55;max-width:340px;margin:0 auto">
            Remplissez le formulaire à gauche puis cliquez sur <strong>« Générer »</strong>.<br>
            La génération prend 10 à 30 secondes.
          </div>
        </div>
      <?php endif; ?>
    </section>

  </div>

</main>

<script>
function copyGen() {
  var ta = document.getElementById('gen-content');
  ta.select();
  document.execCommand('copy');
  alert('✅ Copié dans le presse-papier');
}
function downloadGen() {
  var c = document.getElementById('gen-content').value;
  var b = new Blob([c], { type: 'text/plain;charset=utf-8' });
  var u = URL.createObjectURL(b);
  var a = document.createElement('a');
  a.href = u; a.download = '<?= $type ?>_<?= date('Y-m-d') ?>.txt';
  a.click(); URL.revokeObjectURL(u);
}
function saveGen() {
  var title = prompt('Nom du template à sauvegarder :', '<?= h($config['title']) ?>');
  if (!title) return;
  var fd = new FormData();
  <?php if (function_exists('csrf_token')): ?>fd.append('csrf_token', '<?= csrf_token() ?>');<?php endif; ?>
  fd.append('title', title);
  fd.append('content', document.getElementById('gen-content').value);
  fd.append('category', '<?= $config['category'] ?>');
  fd.append('type', '<?= $type ?>');
  fetch('/communication-template-save', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => alert(d.success ? '✅ Sauvegardé dans votre bibliothèque' : '❌ ' + (d.error || 'Erreur')))
    .catch(() => alert('❌ Erreur réseau'));
}
</script>

<style>
.gen-layout { display: grid; grid-template-columns: 360px 1fr; gap: 18px; align-items: flex-start; }
@media (max-width: 900px) { .gen-layout { grid-template-columns: 1fr; } }

.gen-form-wrap {
  background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-lg);
  padding: 18px; position: sticky; top: 20px;
  max-height: calc(100vh - 40px); overflow-y: auto;
}
.gen-form-head { margin-bottom: 14px; }
.gen-form-title { font-size: 14.5px; font-weight: 600; color: var(--ink); }
.gen-form-sub { font-size: 12px; color: var(--ink-3); margin-top: 2px; }

.gen-group { margin-bottom: 12px; }
.gen-group label { display: block; font-size: 12.5px; font-weight: 500; margin-bottom: 5px; color: var(--ink); }
.gen-group input, .gen-group textarea, .gen-group select {
  width: 100%; padding: 8px 10px;
  border: 1px solid var(--border-strong); border-radius: 8px;
  background: var(--bg); color: var(--ink);
  font-family: inherit; font-size: 13px; box-sizing: border-box;
}
.gen-group textarea { resize: vertical; min-height: 60px; line-height: 1.45; }
.gen-group input:focus, .gen-group textarea:focus, .gen-group select:focus {
  outline: none; border-color: var(--acc);
}

.gen-result-wrap { min-height: 480px; }
.gen-result-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px; }
.gen-result-title { font-size: 14.5px; font-weight: 600; color: var(--ink); }
.gen-result-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.gen-result-actions .btn { padding: 6px 12px; font-size: 12.5px; }

.gen-content {
  width: 100%; padding: 18px;
  background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-lg);
  color: var(--ink); font-family: inherit; font-size: 13.5px; line-height: 1.6;
  resize: vertical; min-height: 480px; box-sizing: border-box;
}

.gen-empty {
  background: var(--bg); border: 1px dashed var(--border-strong);
  border-radius: var(--radius-lg); padding: 70px 20px; text-align: center;
}
</style>

<?php render_foot(); ?>

<script>
// AKBTN-FIX-V1 — désactive bouton APRÈS validation HTML5 OK, ESC pour reset manuel
(function(){
  document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('gen-submit-btn');
    if (!btn) return;
    var form = btn.closest('form');
    if (!form) return;
    var originalHtml = btn.innerHTML;

    form.addEventListener('submit', function() {
      btn.disabled = true;
      btn.innerHTML = '🔄 Génération en cours...';
      btn.style.opacity = '.7';
      if (typeof showIaOverlay === 'function') showIaOverlay();
    });

    // Échap = débloque le bouton si resté coincé après une erreur silencieuse
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && btn.disabled) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        btn.style.opacity = '1';
        console.log('[Assokit] Bouton génération réactivé (ESC)');
      }
    });

    // Au retour navigateur (bfcache), réactive aussi le bouton
    window.addEventListener('pageshow', function(e) {
      if (e.persisted && btn.disabled) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        btn.style.opacity = '1';
      }
    });
  });
})();
</script>

