<?php
/**
 * asso-ai-helpers.php
 * --------------------------------------------------------------
 * Helpers Communication IA — Pack PHASE 4.5
 * Convention : préfixe ak_ai_*
 *
 * Configuration requise dans config.php :
 *   define('CLAUDE_API_KEY', 'sk-ant-...');
 *   // Optionnels :
 *   // define('AK_AI_MODEL', 'claude-sonnet-4-5');
 *   // define('AK_AI_MAX_TOKENS', 2048);
 * --------------------------------------------------------------
 */

if (!defined('AK_AI_MODEL'))      define('AK_AI_MODEL', 'claude-sonnet-4-5');
if (!defined('AK_AI_MAX_TOKENS')) define('AK_AI_MAX_TOKENS', 2048);
if (!defined('AK_AI_API_URL'))    define('AK_AI_API_URL', 'https://api.anthropic.com/v1/messages');

// --------------------------------------------------------------
// Catalogue des DOSSIERS (6 thèmes)
// --------------------------------------------------------------
if (!function_exists('ak_ai_folders_catalog')) {
    function ak_ai_folders_catalog(): array {
        return [
            'vie-associative' => [
                'label' => 'Vie associative',
                'icon'  => '📋',
                'color' => '#7E22CE',
                'desc'  => 'AG, réunions, événements, newsletters internes.',
            ],
            'dons' => [
                'label' => 'Dons & donateurs',
                'icon'  => '💝',
                'color' => '#DC2626',
                'desc'  => 'Appels aux dons, reçus fiscaux, remerciements.',
            ],
            'adherents' => [
                'label' => 'Adhérents & Bénévoles',
                'icon'  => '👥',
                'color' => '#0EA5E9',
                'desc'  => 'Recrutement, accueil, fidélisation.',
            ],
            'social' => [
                'label' => 'Réseaux sociaux',
                'icon'  => '📱',
                'color' => '#EC4899',
                'desc'  => 'Posts, calendrier éditorial, carrousels.',
            ],
            'rapports' => [
                'label' => 'Rapports annuels',
                'icon'  => '📊',
                'color' => '#059669',
                'desc'  => 'Rapport moral, rapport d\'activité, bilans.',
            ],
            'courrier' => [
                'label' => 'Courrier officiel',
                'icon'  => '✉️',
                'color' => '#F59E0B',
                'desc'  => 'Courriers institutionnels, demandes de subvention, presse.',
            ],
        ];
    }
}

if (!function_exists('ak_ai_folder')) {
    function ak_ai_folder(string $key): array {
        $cat = ak_ai_folders_catalog();
        return $cat[$key] ?? ['label' => 'Dossier', 'icon' => '📁', 'color' => '#475569', 'desc' => ''];
    }
}

// --------------------------------------------------------------
// Catalogue COMPLET des OUTILS (19 outils)
// Chaque outil définit ses fields (formulaire dynamique)
// --------------------------------------------------------------
if (!function_exists('ak_ai_tools_catalog')) {
    function ak_ai_tools_catalog(): array {
        // Champs réutilisables
        $tone_field   = ['name' => 'tone',     'type' => 'tone',     'label' => 'Ton'];
        $length_field = ['name' => 'length',   'type' => 'length',   'label' => 'Longueur'];
        $sujet_field  = ['name' => 'sujet',    'type' => 'textarea', 'label' => 'Sujet / objet', 'required' => true, 'rows' => 3];

        return [

            // ============== VIE ASSOCIATIVE ==============
            'convocation-ag' => [
                'folder' => 'vie-associative',
                'label'  => 'Convocation AG',
                'icon'   => '🗳️',
                'desc'   => 'Convocation à l\'Assemblée Générale (ordinaire ou extraordinaire).',
                'fields' => [
                    ['name' => 'type_ag', 'type' => 'select', 'label' => 'Type d\'AG',
                     'options' => ['ordinaire' => 'Ordinaire', 'extraordinaire' => 'Extraordinaire']],
                    ['name' => 'date_ag', 'type' => 'text', 'label' => 'Date et heure', 'placeholder' => 'Ex: samedi 15 juin 2026 à 14h30', 'required' => true],
                    ['name' => 'lieu',    'type' => 'text', 'label' => 'Lieu', 'placeholder' => 'Ex: Salle des fêtes de Lyon'],
                    ['name' => 'ordre_jour', 'type' => 'textarea', 'label' => 'Ordre du jour', 'rows' => 5,
                     'placeholder' => "- Approbation du PV de l'AG précédente\n- Rapport moral\n- Rapport financier\n- Élection du bureau\n- Questions diverses", 'required' => true],
                    $tone_field, $length_field,
                ],
            ],
            'compte-rendu-reunion' => [
                'folder' => 'vie-associative',
                'label'  => 'Compte-rendu réunion',
                'icon'   => '📝',
                'desc'   => 'Synthèse structurée d\'une réunion (CA, bureau, équipe).',
                'fields' => [
                    ['name' => 'type_reunion', 'type' => 'text', 'label' => 'Type de réunion', 'placeholder' => 'CA, bureau, équipe projet…'],
                    ['name' => 'date_reunion', 'type' => 'text', 'label' => 'Date', 'placeholder' => '15/05/2026'],
                    ['name' => 'participants', 'type' => 'textarea', 'label' => 'Participants', 'rows' => 2, 'placeholder' => 'Liste des présents'],
                    ['name' => 'notes',        'type' => 'textarea', 'label' => 'Notes brutes (à structurer)', 'rows' => 8, 'required' => true,
                     'placeholder' => 'Collez vos notes en vrac, l\'IA va les structurer en compte-rendu propre…'],
                    $tone_field, $length_field,
                ],
            ],
            'annonce-evenement' => [
                'folder' => 'vie-associative',
                'label'  => 'Annonce d\'événement',
                'icon'   => '🎉',
                'desc'   => 'Texte d\'annonce pour un événement asso (interne ou public).',
                'fields' => [
                    ['name' => 'nom_event', 'type' => 'text', 'label' => 'Nom de l\'événement', 'required' => true],
                    ['name' => 'date_event','type' => 'text', 'label' => 'Date / horaires', 'placeholder' => 'Samedi 12 juin à 18h'],
                    ['name' => 'lieu',      'type' => 'text', 'label' => 'Lieu'],
                    ['name' => 'descriptif','type' => 'textarea', 'label' => 'Descriptif / objectif', 'rows' => 4, 'required' => true],
                    ['name' => 'cible',     'type' => 'text', 'label' => 'Public visé', 'placeholder' => 'Adhérents, grand public, donateurs…'],
                    $tone_field, $length_field,
                ],
            ],
            'newsletter' => [
                'folder' => 'vie-associative',
                'label'  => 'Newsletter',
                'icon'   => '✉️',
                'desc'   => 'Email engageant pour adhérents et donateurs.',
                'fields' => [
                    $sujet_field,
                    ['name' => 'points', 'type' => 'textarea', 'label' => 'Points à aborder (un par ligne)', 'rows' => 5,
                     'placeholder' => "- 250 nouveaux adhérents\n- Inauguration de notre antenne à Lyon\n- Appel à bénévoles pour le festival d'été"],
                    ['name' => 'cta', 'type' => 'text', 'label' => 'Appel à l\'action', 'placeholder' => 'Faire un don, s\'inscrire…'],
                    $tone_field, $length_field,
                ],
            ],

            // ============== DONS ==============
            'appel-dons' => [
                'folder' => 'dons',
                'label'  => 'Appel aux dons',
                'icon'   => '🎯',
                'desc'   => 'Lettre/email mobilisant pour une campagne de dons.',
                'fields' => [
                    ['name' => 'cause', 'type' => 'textarea', 'label' => 'Cause / projet à financer', 'rows' => 3, 'required' => true],
                    ['name' => 'objectif_montant', 'type' => 'text', 'label' => 'Objectif de collecte', 'placeholder' => 'Ex: 15 000 €'],
                    ['name' => 'usage_concret',    'type' => 'textarea', 'label' => 'Utilisation concrète des dons', 'rows' => 3,
                     'placeholder' => 'Ex: 10 € = 1 repas pour un bénéficiaire'],
                    ['name' => 'echeance', 'type' => 'text', 'label' => 'Échéance', 'placeholder' => 'Ex: avant le 30 juin'],
                    ['name' => 'avantage_fiscal', 'type' => 'select', 'label' => 'Mention avantage fiscal',
                     'options' => ['oui' => 'Oui (66%)', 'non' => 'Non']],
                    $tone_field, $length_field,
                ],
            ],
            'recu-fiscal-email' => [
                'folder' => 'dons',
                'label'  => 'Email reçu fiscal',
                'icon'   => '🧾',
                'desc'   => 'Email accompagnant l\'envoi du reçu fiscal.',
                'fields' => [
                    ['name' => 'prenom_donateur', 'type' => 'text', 'label' => 'Prénom du donateur', 'placeholder' => 'Pour personnaliser'],
                    ['name' => 'montant',  'type' => 'text', 'label' => 'Montant du don', 'placeholder' => '50 €'],
                    ['name' => 'annee',    'type' => 'text', 'label' => 'Année fiscale', 'placeholder' => '2025'],
                    ['name' => 'projet',   'type' => 'text', 'label' => 'Projet financé (optionnel)'],
                    $tone_field,
                ],
            ],
            'remerciement-donateur' => [
                'folder' => 'dons',
                'label'  => 'Remerciement donateur',
                'icon'   => '🙏',
                'desc'   => 'Message chaleureux et personnalisé après un don.',
                'fields' => [
                    ['name' => 'prenom_donateur', 'type' => 'text', 'label' => 'Prénom du donateur'],
                    ['name' => 'montant', 'type' => 'text', 'label' => 'Montant du don'],
                    ['name' => 'recurrent', 'type' => 'select', 'label' => 'Don ponctuel ou récurrent',
                     'options' => ['ponctuel' => 'Ponctuel', 'recurrent' => 'Récurrent / mensuel']],
                    ['name' => 'impact', 'type' => 'textarea', 'label' => 'Impact concret du don', 'rows' => 3,
                     'placeholder' => 'Ce qu\'il permet de faire concrètement'],
                    $tone_field, $length_field,
                ],
            ],

            // ============== ADHÉRENTS & BÉNÉVOLES ==============
            'recrutement-benevoles' => [
                'folder' => 'adherents',
                'label'  => 'Recrutement bénévoles',
                'icon'   => '🤝',
                'desc'   => 'Annonce pour recruter des bénévoles sur une mission.',
                'fields' => [
                    ['name' => 'mission', 'type' => 'textarea', 'label' => 'Mission proposée', 'rows' => 3, 'required' => true],
                    ['name' => 'profil', 'type' => 'textarea', 'label' => 'Profil recherché / compétences', 'rows' => 2],
                    ['name' => 'engagement_temps', 'type' => 'text', 'label' => 'Engagement en temps', 'placeholder' => 'Ex: 2h/semaine'],
                    ['name' => 'lieu', 'type' => 'text', 'label' => 'Lieu / à distance'],
                    ['name' => 'contact', 'type' => 'text', 'label' => 'Contact pour postuler'],
                    $tone_field, $length_field,
                ],
            ],
            'bienvenue-adherent' => [
                'folder' => 'adherents',
                'label'  => 'Bienvenue nouvel adhérent',
                'icon'   => '👋',
                'desc'   => 'Email de bienvenue chaleureux pour un nouvel adhérent.',
                'fields' => [
                    ['name' => 'prenom', 'type' => 'text', 'label' => 'Prénom (placeholder dans le texte)'],
                    ['name' => 'avantages', 'type' => 'textarea', 'label' => 'Avantages adhérents à mentionner', 'rows' => 3,
                     'placeholder' => '- Newsletter mensuelle\n- Invitations aux événements\n- Espace membre'],
                    ['name' => 'next_steps', 'type' => 'textarea', 'label' => 'Prochaines étapes suggérées', 'rows' => 2,
                     'placeholder' => 'Ex: Participer à la prochaine AG, rejoindre un groupe de travail'],
                    $tone_field, $length_field,
                ],
            ],
            'relance-cotisation' => [
                'folder' => 'adherents',
                'label'  => 'Relance cotisation',
                'icon'   => '📌',
                'desc'   => 'Relance bienveillante pour une cotisation non renouvelée.',
                'fields' => [
                    ['name' => 'prenom', 'type' => 'text', 'label' => 'Prénom (optionnel)'],
                    ['name' => 'montant_cotisation', 'type' => 'text', 'label' => 'Montant cotisation', 'placeholder' => '25 €/an'],
                    ['name' => 'lien_paiement', 'type' => 'text', 'label' => 'Lien de paiement / instructions'],
                    ['name' => 'ton_relance', 'type' => 'select', 'label' => 'Niveau de relance',
                     'options' => ['douce' => 'Douce (1ère relance)', 'standard' => 'Standard (2ème)', 'ferme' => 'Plus ferme (3ème)']],
                    $tone_field,
                ],
            ],

            // ============== RÉSEAUX SOCIAUX ==============
            'post' => [
                'folder' => 'social',
                'label'  => 'Post réseaux sociaux',
                'icon'   => '📱',
                'desc'   => 'LinkedIn, Instagram, Facebook, X — adapté à chaque plateforme.',
                'fields' => [
                    ['name' => 'platform', 'type' => 'select', 'label' => 'Plateforme', 'required' => true,
                     'options' => ['linkedin' => 'LinkedIn', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X (Twitter)']],
                    $sujet_field,
                    ['name' => 'cta', 'type' => 'text', 'label' => 'Appel à l\'action (optionnel)'],
                    $tone_field,
                ],
            ],
            'idees' => [
                'folder' => 'social',
                'label'  => 'Calendrier éditorial',
                'icon'   => '💡',
                'desc'   => 'Génère 5 à 30 idées de contenus pour la période choisie.',
                'fields' => [
                    ['name' => 'themes', 'type' => 'textarea', 'label' => 'Thèmes / axes prioritaires', 'rows' => 3,
                     'placeholder' => 'Ex: Sensibilisation, témoignages, coulisses…'],
                    ['name' => 'periode', 'type' => 'text', 'label' => 'Période', 'placeholder' => 'le mois prochain'],
                    ['name' => 'nb', 'type' => 'number', 'label' => 'Nombre d\'idées', 'min' => 5, 'max' => 30, 'default' => 12],
                    $tone_field,
                ],
            ],
            'carrousel' => [
                'folder' => 'social',
                'label'  => 'Carrousel storytelling',
                'icon'   => '🎴',
                'desc'   => 'Carrousel LinkedIn/Instagram en 5 à 8 slides narratives.',
                'fields' => [
                    ['name' => 'sujet', 'type' => 'textarea', 'label' => 'Sujet du carrousel', 'rows' => 3, 'required' => true],
                    ['name' => 'nb_slides', 'type' => 'number', 'label' => 'Nombre de slides', 'min' => 4, 'max' => 10, 'default' => 6],
                    ['name' => 'angle', 'type' => 'text', 'label' => 'Angle / promesse',
                     'placeholder' => 'Ex: 5 leçons apprises / 7 chiffres clés / Notre histoire'],
                    $tone_field,
                ],
            ],

            // ============== RAPPORTS ANNUELS ==============
            'rapport-moral' => [
                'folder' => 'rapports',
                'label'  => 'Rapport moral',
                'icon'   => '📜',
                'desc'   => 'Rapport moral annuel du président pour l\'AG.',
                'fields' => [
                    ['name' => 'annee', 'type' => 'text', 'label' => 'Année', 'placeholder' => '2025', 'required' => true],
                    ['name' => 'faits_marquants', 'type' => 'textarea', 'label' => 'Faits marquants de l\'année', 'rows' => 5, 'required' => true],
                    ['name' => 'difficultes', 'type' => 'textarea', 'label' => 'Difficultés rencontrées', 'rows' => 3],
                    ['name' => 'perspectives', 'type' => 'textarea', 'label' => 'Perspectives année suivante', 'rows' => 3],
                    $tone_field, $length_field,
                ],
            ],
            'rapport-activite' => [
                'folder' => 'rapports',
                'label'  => 'Rapport d\'activité',
                'icon'   => '📈',
                'desc'   => 'Rapport d\'activité structuré (actions, chiffres, impact).',
                'fields' => [
                    ['name' => 'annee', 'type' => 'text', 'label' => 'Année', 'required' => true],
                    ['name' => 'actions', 'type' => 'textarea', 'label' => 'Actions menées (une par ligne)', 'rows' => 6, 'required' => true,
                     'placeholder' => "- Festival annuel : 1200 participants\n- Programme scolaire : 8 écoles, 450 enfants\n- 12 ateliers de sensibilisation"],
                    ['name' => 'chiffres_cles', 'type' => 'textarea', 'label' => 'Chiffres clés', 'rows' => 3,
                     'placeholder' => "- 250 adhérents (+15%)\n- 45 000 € de budget\n- 3 salariés"],
                    ['name' => 'temoignages', 'type' => 'textarea', 'label' => 'Témoignages / citations (optionnel)', 'rows' => 3],
                    $tone_field, $length_field,
                ],
            ],
            'bilan-annuel-court' => [
                'folder' => 'rapports',
                'label'  => 'Synthèse annuelle (membres)',
                'icon'   => '📰',
                'desc'   => 'Version courte et engageante du bilan, pour newsletter membres.',
                'fields' => [
                    ['name' => 'annee', 'type' => 'text', 'label' => 'Année', 'required' => true],
                    ['name' => 'temps_forts', 'type' => 'textarea', 'label' => 'Temps forts de l\'année', 'rows' => 5, 'required' => true],
                    ['name' => 'chiffres', 'type' => 'textarea', 'label' => '3-5 chiffres marquants', 'rows' => 3],
                    ['name' => 'merci', 'type' => 'text', 'label' => 'Message de remerciement final'],
                    $tone_field,
                ],
            ],

            // ============== COURRIER OFFICIEL ==============
            'courrier-institutionnel' => [
                'folder' => 'courrier',
                'label'  => 'Courrier institutionnel',
                'icon'   => '🏛️',
                'desc'   => 'Courrier formel à mairie, préfecture, partenaire institutionnel.',
                'fields' => [
                    ['name' => 'destinataire', 'type' => 'text', 'label' => 'Destinataire (titre + organisme)', 'required' => true,
                     'placeholder' => 'Madame la Maire de Lyon'],
                    ['name' => 'objet', 'type' => 'text', 'label' => 'Objet du courrier', 'required' => true],
                    ['name' => 'corps', 'type' => 'textarea', 'label' => 'Contenu / demande', 'rows' => 6, 'required' => true],
                    ['name' => 'demande_precise', 'type' => 'text', 'label' => 'Demande précise / action attendue'],
                    $length_field,
                ],
            ],
            'demande-subvention' => [
                'folder' => 'courrier',
                'label'  => 'Demande de subvention',
                'icon'   => '💶',
                'desc'   => 'Lettre de demande de subvention argumentée et structurée.',
                'fields' => [
                    ['name' => 'organisme', 'type' => 'text', 'label' => 'Organisme financeur', 'required' => true,
                     'placeholder' => 'Ex: Région Auvergne-Rhône-Alpes'],
                    ['name' => 'projet', 'type' => 'textarea', 'label' => 'Description du projet', 'rows' => 4, 'required' => true],
                    ['name' => 'montant_demande', 'type' => 'text', 'label' => 'Montant demandé', 'placeholder' => '8 000 €'],
                    ['name' => 'budget_total', 'type' => 'text', 'label' => 'Budget total du projet'],
                    ['name' => 'beneficiaires', 'type' => 'text', 'label' => 'Bénéficiaires visés (nombre + profil)'],
                    ['name' => 'impact_attendu', 'type' => 'textarea', 'label' => 'Impact attendu', 'rows' => 3],
                    $length_field,
                ],
            ],
            'presse' => [
                'folder' => 'courrier',
                'label'  => 'Communiqué de presse',
                'icon'   => '📰',
                'desc'   => 'Communiqué au format pro français standard.',
                'fields' => [
                    $sujet_field,
                    ['name' => 'contexte',   'type' => 'textarea', 'label' => 'Contexte', 'rows' => 3],
                    ['name' => 'infos_cles', 'type' => 'textarea', 'label' => 'Informations clés (chiffres, dates)', 'rows' => 4],
                    ['name' => 'ville',      'type' => 'text', 'label' => 'Ville d\'émission'],
                    ['name' => 'date',       'type' => 'text', 'label' => 'Date', 'default' => 'auto'],
                    ['name' => 'contact',    'type' => 'text', 'label' => 'Contact presse'],
                    $tone_field, $length_field,
                ],
            ],

            // ============== TRANSVERSE (sans dossier) ==============
            'reformuler' => [
                'folder' => null,
                'label'  => 'Reformuler / améliorer',
                'icon'   => '✨',
                'desc'   => 'Réécrire un texte existant : ton, longueur, clarté.',
                'fields' => [
                    ['name' => 'texte', 'type' => 'textarea', 'label' => 'Texte à reformuler', 'rows' => 8, 'required' => true],
                    ['name' => 'objectif', 'type' => 'text', 'label' => 'Objectif',
                     'placeholder' => 'Plus court / Plus accessible / Plus engageant…'],
                    $tone_field, $length_field,
                ],
            ],
        ];
    }
}

if (!function_exists('ak_ai_tool')) {
    function ak_ai_tool(string $type): array {
        $cat = ak_ai_tools_catalog();
        if (isset($cat[$type])) return $cat[$type];
        return ['folder' => null, 'label' => 'Outil IA', 'icon' => '🤖', 'desc' => '', 'fields' => []];
    }
}

if (!function_exists('ak_ai_tools_by_folder')) {
    function ak_ai_tools_by_folder(string $folder_key): array {
        $out = [];
        foreach (ak_ai_tools_catalog() as $key => $tool) {
            if (($tool['folder'] ?? null) === $folder_key) $out[$key] = $tool;
        }
        return $out;
    }
}

if (!function_exists('ak_ai_tools_transverse')) {
    function ak_ai_tools_transverse(): array {
        $out = [];
        foreach (ak_ai_tools_catalog() as $key => $tool) {
            if (empty($tool['folder'])) $out[$key] = $tool;
        }
        return $out;
    }
}

// --------------------------------------------------------------
// Settings IA par org
// --------------------------------------------------------------
if (!function_exists('ak_ai_get_settings')) {
    function ak_ai_get_settings(PDO $pdo, int $org_id): array {
        try {
            $st = $pdo->prepare("SELECT * FROM asso_ai_settings WHERE org_id = :o LIMIT 1");
            $st->execute([':o' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
            $pdo->prepare("INSERT IGNORE INTO asso_ai_settings (org_id, created_at) VALUES (:o, NOW())")
                ->execute([':o' => $org_id]);
        } catch (Throwable $e) { error_log('[ak_ai_get_settings] ' . $e->getMessage()); }
        return ['org_id' => $org_id, 'default_tone' => 'chaleureux', 'default_length' => 'medium', 'default_language' => 'fr', 'signature' => null, 'monthly_quota' => 100];
    }
}

if (!function_exists('ak_ai_get_org_context')) {
    function ak_ai_get_org_context(PDO $pdo, int $org_id): array {
        $defaults = ['name' => 'Notre association', 'description' => '', 'tagline' => ''];
        try {
            $st = $pdo->prepare("SELECT * FROM organizations WHERE id = :o LIMIT 1");
            $st->execute([':o' => $org_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return [
                'name' => $row['name'] ?? $defaults['name'],
                'description' => $row['description'] ?? '',
                'tagline' => $row['tagline'] ?? '',
            ];
        } catch (Throwable $e) { /* silent */ }
        return $defaults;
    }
}

if (!function_exists('ak_ai_count_this_month')) {
    function ak_ai_count_this_month(PDO $pdo, int $org_id): int {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_ai_generations WHERE org_id = :o AND status = 'success' AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')");
            $st->execute([':o' => $org_id]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('ak_ai_count_total')) {
    function ak_ai_count_total(PDO $pdo, int $org_id): int {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_ai_generations WHERE org_id = :o AND status = 'success'");
            $st->execute([':o' => $org_id]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }
}

// --------------------------------------------------------------
// Directives ton/longueur/langue
// --------------------------------------------------------------
if (!function_exists('ak_ai_tone_directive')) {
    function ak_ai_tone_directive(string $tone): string {
        return [
            'chaleureux'    => 'Adopte un ton chaleureux, humain, accueillant.',
            'professionnel' => 'Adopte un ton professionnel, posé, factuel. Vouvoiement.',
            'dynamique'     => 'Adopte un ton dynamique, énergique, motivant.',
            'inspirant'     => 'Adopte un ton inspirant, vibrant, qui donne envie de s\'engager.',
            'engage'        => 'Adopte un ton engagé, militant, qui assume des prises de position.',
            'humoristique'  => 'Adopte un ton léger, complice, avec une pointe d\'humour bienveillant.',
        ][$tone] ?? 'Adopte un ton chaleureux et humain.';
    }
}

if (!function_exists('ak_ai_length_directive')) {
    function ak_ai_length_directive(string $len): string {
        return [
            'short'  => 'Sois concis : 80 à 150 mots.',
            'medium' => 'Longueur moyenne : 200 à 400 mots.',
            'long'   => 'Détaillé : 500 à 800 mots.',
        ][$len] ?? 'Longueur moyenne : 200 à 400 mots.';
    }
}

// --------------------------------------------------------------
// Construction prompt par outil (gros switch organisé)
// --------------------------------------------------------------
if (!function_exists('ak_ai_build_prompt')) {
    function ak_ai_build_prompt(string $tool, array $inp, array $org, array $settings): array {
        $org_name  = $org['name'] ?? 'Notre association';
        $org_desc  = $org['description'] ?: ($org['tagline'] ?: '');
        $tone      = $inp['tone']     ?? $settings['default_tone']     ?? 'chaleureux';
        $length    = $inp['length']   ?? $settings['default_length']   ?? 'medium';
        $signature = trim((string)($settings['signature'] ?? ''));

        $tone_dir   = ak_ai_tone_directive($tone);
        $length_dir = ak_ai_length_directive($length);
        $lang_dir   = 'Réponds en français.';
        $ctx        = "Tu écris pour l'association \"{$org_name}\"." . ($org_desc ? " À propos : {$org_desc}." : '');
        $sig        = $signature ? "Termine par cette signature exacte : \"{$signature}\"." : '';

        $system = "Tu es un assistant de communication pour des associations à but non lucratif. "
                . "Tu rédiges des contenus authentiques, engageants et utiles. "
                . "Évite le jargon corporate et les superlatifs creux. "
                . "Produis directement le texte demandé en Markdown propre, sans préambule ni méta-commentaire. "
                . "N'invente jamais de chiffres ou faits non fournis par l'utilisateur.";

        // raccourci pour récupérer un input avec valeur par défaut
        $g = fn($k, $d = '') => trim((string)($inp[$k] ?? $d));

        $user = '';
        switch ($tool) {

            // === VIE ASSOCIATIVE ===
            case 'convocation-ag':
                $user = "{$ctx}\n\nRédige une convocation à l'Assemblée Générale " . $g('type_ag', 'ordinaire') . ".\n"
                      . "Date : " . $g('date_ag') . "\n"
                      . ($g('lieu') ? "Lieu : " . $g('lieu') . "\n" : '')
                      . "Ordre du jour à intégrer :\n" . $g('ordre_jour') . "\n\n"
                      . "Format : courrier officiel structuré (en-tête, objet, ordre du jour numéroté, modalités de vote/pouvoir, signature).\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}\n{$sig}";
                break;

            case 'compte-rendu-reunion':
                $user = "{$ctx}\n\nStructure les notes brutes suivantes en compte-rendu propre et professionnel.\n\n"
                      . "Type de réunion : " . $g('type_reunion', 'réunion') . "\n"
                      . ($g('date_reunion') ? "Date : " . $g('date_reunion') . "\n" : '')
                      . ($g('participants') ? "Participants : " . $g('participants') . "\n" : '')
                      . "\nNotes brutes :\n---\n" . $g('notes') . "\n---\n\n"
                      . "Format attendu : en-tête (date, participants), points discutés (sections claires), décisions prises, actions à mener (qui/quoi/quand).\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}";
                break;

            case 'annonce-evenement':
                $user = "{$ctx}\n\nRédige une annonce engageante pour un événement.\n\n"
                      . "Nom : " . $g('nom_event') . "\n"
                      . ($g('date_event') ? "Date : " . $g('date_event') . "\n" : '')
                      . ($g('lieu') ? "Lieu : " . $g('lieu') . "\n" : '')
                      . "Descriptif : " . $g('descriptif') . "\n"
                      . ($g('cible') ? "Public visé : " . $g('cible') . "\n" : '')
                      . "\n{$tone_dir}\n{$length_dir}\n{$lang_dir}\n{$sig}";
                break;

            case 'newsletter':
                $user = "{$ctx}\n\nRédige une newsletter email engageante.\n\n"
                      . "Thème principal : " . $g('sujet') . "\n"
                      . ($g('points') ? "Points :\n" . $g('points') . "\n" : '')
                      . ($g('cta') ? "Appel à l'action : " . $g('cta') . "\n" : '')
                      . "\nStructure : objet d'email accrocheur (commence par 'Objet : '), salutation, 1-3 sections avec sous-titres, CTA final.\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}\n{$sig}";
                break;

            // === DONS ===
            case 'appel-dons':
                $afi = ($g('avantage_fiscal', 'oui') === 'oui') ? "Inclus une mention sur la déduction fiscale (66% pour particuliers).\n" : '';
                $user = "{$ctx}\n\nRédige un appel aux dons mobilisateur.\n\n"
                      . "Cause/projet : " . $g('cause') . "\n"
                      . ($g('objectif_montant') ? "Objectif : " . $g('objectif_montant') . "\n" : '')
                      . ($g('usage_concret') ? "Utilisation concrète des dons :\n" . $g('usage_concret') . "\n" : '')
                      . ($g('echeance') ? "Échéance : " . $g('echeance') . "\n" : '')
                      . "\n{$afi}"
                      . "Structure : accroche émotionnelle, situation/besoin, impact concret du don, appel à l'action clair.\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}\n{$sig}";
                break;

            case 'recu-fiscal-email':
                $user = "{$ctx}\n\nRédige un email court et chaleureux accompagnant l'envoi d'un reçu fiscal.\n\n"
                      . ($g('prenom_donateur') ? "Donateur : " . $g('prenom_donateur') . "\n" : '')
                      . ($g('montant') ? "Montant : " . $g('montant') . "\n" : '')
                      . ($g('annee') ? "Année fiscale : " . $g('annee') . "\n" : '')
                      . ($g('projet') ? "Projet financé : " . $g('projet') . "\n" : '')
                      . "\nDois inclure : ligne d'objet ('Objet : '), salutation personnalisée, remerciement, mention du PDF en pièce jointe, rappel déduction fiscale 66%, signature.\n"
                      . "{$tone_dir}\n{$lang_dir}\n{$sig}";
                break;

            case 'remerciement-donateur':
                $rec = $g('recurrent', 'ponctuel') === 'recurrent' ? 'récurrent (mensuel)' : 'ponctuel';
                $user = "{$ctx}\n\nRédige un remerciement chaleureux et personnalisé pour un don {$rec}.\n\n"
                      . ($g('prenom_donateur') ? "Prénom du donateur : " . $g('prenom_donateur') . "\n" : '')
                      . ($g('montant') ? "Montant : " . $g('montant') . "\n" : '')
                      . ($g('impact') ? "Impact concret du don :\n" . $g('impact') . "\n" : '')
                      . "\nÉvite le ton convenu. Sois sincère, raconte ce que ce don change concrètement.\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}\n{$sig}";
                break;

            // === ADHÉRENTS ===
            case 'recrutement-benevoles':
                $user = "{$ctx}\n\nRédige une annonce engageante pour recruter des bénévoles.\n\n"
                      . "Mission : " . $g('mission') . "\n"
                      . ($g('profil') ? "Profil recherché : " . $g('profil') . "\n" : '')
                      . ($g('engagement_temps') ? "Engagement : " . $g('engagement_temps') . "\n" : '')
                      . ($g('lieu') ? "Lieu : " . $g('lieu') . "\n" : '')
                      . ($g('contact') ? "Contact : " . $g('contact') . "\n" : '')
                      . "\nStructure : titre accrocheur, mission inspirante (le 'pourquoi'), profil recherché, conditions, comment postuler.\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}\n{$sig}";
                break;

            case 'bienvenue-adherent':
                $user = "{$ctx}\n\nRédige un email de bienvenue chaleureux pour un nouvel adhérent.\n\n"
                      . ($g('prenom') ? "Utilise le prénom \"" . $g('prenom') . "\" (ou \"{prenom}\" si placeholder).\n" : 'Utilise le placeholder {prenom}.\n')
                      . ($g('avantages') ? "Avantages à mentionner :\n" . $g('avantages') . "\n" : '')
                      . ($g('next_steps') ? "Prochaines étapes suggérées :\n" . $g('next_steps') . "\n" : '')
                      . "\nFormat : objet d'email, salutation, message d'accueil sincère (pas convenu), avantages concrets, premières étapes, signature.\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}\n{$sig}";
                break;

            case 'relance-cotisation':
                $niveau = $g('ton_relance', 'douce');
                $user = "{$ctx}\n\nRédige une relance de cotisation, niveau : {$niveau}.\n\n"
                      . ($g('prenom') ? "Prénom : " . $g('prenom') . "\n" : '')
                      . ($g('montant_cotisation') ? "Montant cotisation : " . $g('montant_cotisation') . "\n" : '')
                      . ($g('lien_paiement') ? "Lien de paiement : " . $g('lien_paiement') . "\n" : '')
                      . "\nReste bienveillant : c'est un membre, pas un client. Rappelle l'impact de l'asso, propose un canal pour échanger en cas de difficulté.\n"
                      . "{$tone_dir}\n{$lang_dir}\n{$sig}";
                break;

            // === SOCIAL ===
            case 'post':
                $platform = $g('platform', 'linkedin');
                $specs = [
                    'linkedin'  => 'LinkedIn — 150-300 mots, pro mais humain, 3-5 hashtags fin, 2-3 émojis max.',
                    'instagram' => 'Instagram — accroche forte 1ère ligne, 100-200 mots, 5-8 émojis, 8-15 hashtags séparés en bas.',
                    'facebook'  => 'Facebook — conversationnel, 100-250 mots, 1-3 émojis, 2-3 hashtags max.',
                    'x'         => 'X — UN tweet de 240 caractères max OU thread numéroté de 3-5 tweets.',
                ];
                $user = "{$ctx}\n\nRédige un post pour " . ($specs[$platform] ?? $specs['linkedin']) . "\n\n"
                      . "Sujet : " . $g('sujet') . "\n"
                      . ($g('cta') ? "Appel à l'action : " . $g('cta') . "\n" : '')
                      . "\n{$tone_dir}\n{$lang_dir}\n{$sig}";
                break;

            case 'idees':
                $nb = max(5, min(30, (int)($inp['nb'] ?? 12)));
                $user = "{$ctx}\n\nGénère un calendrier éditorial avec {$nb} idées de contenus pour " . $g('periode', 'le mois prochain') . ".\n"
                      . ($g('themes') ? "Thèmes prioritaires : " . $g('themes') . "\n" : '')
                      . "\nPour chaque idée : **Titre** / Format suggéré / Angle en 1 phrase / Hashtags si pertinent.\n"
                      . "Varie les formats (post LinkedIn, story, vidéo courte, article) et les angles (inspirant, pédago, témoignage, coulisses).\n"
                      . "{$tone_dir}\n{$lang_dir}";
                break;

            case 'carrousel':
                $nb = max(4, min(10, (int)($inp['nb_slides'] ?? 6)));
                $user = "{$ctx}\n\nRédige un carrousel storytelling de {$nb} slides.\n\n"
                      . "Sujet : " . $g('sujet') . "\n"
                      . ($g('angle') ? "Angle : " . $g('angle') . "\n" : '')
                      . "\nFormat attendu (un bloc par slide, séparé par '---') :\n"
                      . "Slide 1 (cover) : titre fort + sous-titre\n"
                      . "Slides intermédiaires : 1 idée par slide, titre + 2-3 lignes max\n"
                      . "Slide finale : appel à l'action ou question ouverte\n\n"
                      . "{$tone_dir}\n{$lang_dir}";
                break;

            // === RAPPORTS ===
            case 'rapport-moral':
                $user = "{$ctx}\n\nRédige le rapport moral du président pour l'année " . $g('annee') . ".\n\n"
                      . "Faits marquants : " . $g('faits_marquants') . "\n"
                      . ($g('difficultes') ? "Difficultés rencontrées : " . $g('difficultes') . "\n" : '')
                      . ($g('perspectives') ? "Perspectives : " . $g('perspectives') . "\n" : '')
                      . "\nFormat : introduction personnelle, bilan moral (vie de l'asso, équipes, gouvernance), réussites, défis surmontés, perspectives, remerciements, signature.\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}";
                break;

            case 'rapport-activite':
                $user = "{$ctx}\n\nRédige un rapport d'activité structuré pour l'année " . $g('annee') . ".\n\n"
                      . "Actions menées :\n" . $g('actions') . "\n"
                      . ($g('chiffres_cles') ? "Chiffres clés :\n" . $g('chiffres_cles') . "\n" : '')
                      . ($g('temoignages') ? "Témoignages :\n" . $g('temoignages') . "\n" : '')
                      . "\nFormat : intro contexte, sections par axe d'activité (avec sous-titres), chiffres en gras, citations en encadré, conclusion sur l'impact.\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}";
                break;

            case 'bilan-annuel-court':
                $user = "{$ctx}\n\nRédige une synthèse annuelle courte et engageante (newsletter membres) pour l'année " . $g('annee') . ".\n\n"
                      . "Temps forts : " . $g('temps_forts') . "\n"
                      . ($g('chiffres') ? "Chiffres marquants : " . $g('chiffres') . "\n" : '')
                      . ($g('merci') ? "Message de remerciement : " . $g('merci') . "\n" : '')
                      . "\nFormat : titre vibrant, 3-5 sections courtes, chiffres mis en valeur, ton de fierté collective.\n"
                      . "{$tone_dir}\n{$lang_dir}\n{$sig}";
                break;

            // === COURRIER ===
            case 'courrier-institutionnel':
                $user = "{$ctx}\n\nRédige un courrier institutionnel formel.\n\n"
                      . "Destinataire : " . $g('destinataire') . "\n"
                      . "Objet : " . $g('objet') . "\n"
                      . "Contenu : " . $g('corps') . "\n"
                      . ($g('demande_precise') ? "Demande précise : " . $g('demande_precise') . "\n" : '')
                      . "\nFormat : en-tête asso (à gauche) + destinataire (à droite), 'Objet : ', formule d'appel ('Madame, Monsieur,' ou titre du destinataire), 3-4 paragraphes structurés, formule de politesse de clôture, signature.\n"
                      . "Ton : formel, respectueux, précis. Vouvoiement obligatoire.\n"
                      . "{$length_dir}\n{$lang_dir}";
                break;

            case 'demande-subvention':
                $user = "{$ctx}\n\nRédige une lettre de demande de subvention argumentée.\n\n"
                      . "Organisme financeur : " . $g('organisme') . "\n"
                      . "Projet : " . $g('projet') . "\n"
                      . ($g('montant_demande') ? "Montant demandé : " . $g('montant_demande') . "\n" : '')
                      . ($g('budget_total') ? "Budget total : " . $g('budget_total') . "\n" : '')
                      . ($g('beneficiaires') ? "Bénéficiaires : " . $g('beneficiaires') . "\n" : '')
                      . ($g('impact_attendu') ? "Impact attendu : " . $g('impact_attendu') . "\n" : '')
                      . "\nFormat : courrier formel structuré : présentation asso, contexte du projet, objectifs, public cible, budget, impact attendu, articulation avec les priorités du financeur, demande explicite, formule de politesse.\n"
                      . "{$length_dir}\n{$lang_dir}";
                break;

            case 'presse':
                $user = "{$ctx}\n\nRédige un communiqué de presse au format pro français.\n\n"
                      . "Sujet : " . $g('sujet') . "\n"
                      . ($g('contexte') ? "Contexte : " . $g('contexte') . "\n" : '')
                      . ($g('infos_cles') ? "Informations clés : " . $g('infos_cles') . "\n" : '')
                      . ($g('ville') ? "Ville : " . $g('ville') . "\n" : '')
                      . "Date : " . $g('date', date('d/m/Y')) . "\n"
                      . ($g('contact') ? "Contact presse : " . $g('contact') . "\n" : '')
                      . "\nStructure : 'COMMUNIQUÉ DE PRESSE' en haut, ville+date, titre fort, chapeau résumé, 2-4 paragraphes, citation marquante, encadré 'À propos de {$org_name}', bloc 'Contact presse'.\n"
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}";
                break;

            // === TRANSVERSE ===
            case 'reformuler':
                $user = "{$ctx}\n\nReformule et améliore le texte suivant.\n\n"
                      . "---\n" . $g('texte') . "\n---\n\n"
                      . ($g('objectif') ? "Objectif spécifique : " . $g('objectif') . "\n\n" : '')
                      . "{$tone_dir}\n{$length_dir}\n{$lang_dir}\n\n"
                      . "Produis directement la version améliorée, sans expliquer ce que tu as changé.";
                break;

            default:
                $user = "{$ctx}\n\nAide à rédiger un contenu de communication.\nDétails fournis : " . json_encode($inp, JSON_UNESCAPED_UNICODE)
                      . "\n\n{$tone_dir}\n{$length_dir}\n{$lang_dir}";
        }

        return ['system' => $system, 'user' => $user];
    }
}

// --------------------------------------------------------------
// Appel API Claude
// --------------------------------------------------------------
if (!function_exists('ak_ai_call_api')) {
    function ak_ai_call_api(string $system, string $user_msg): array {
        if (function_exists('set_time_limit')) @set_time_limit(250);
        $api_key = defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : (getenv('CLAUDE_API_KEY') ?: '');
        if (!$api_key) {
            return ['ok' => false, 'text' => '', 'tokens_in' => 0, 'tokens_out' => 0,
                    'error' => 'Clé API IA non configurée (CLAUDE_API_KEY manquante).', 'model' => AK_AI_MODEL];
        }
        $payload = [
            'model'      => AK_AI_MODEL,
            'max_tokens' => AK_AI_MAX_TOKENS,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user_msg]],
        ];
        $ch = curl_init(AK_AI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 230,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return ['ok' => false, 'text' => '', 'tokens_in' => 0, 'tokens_out' => 0, 'error' => 'Erreur réseau : ' . ($err ?: 'inconnue'), 'model' => AK_AI_MODEL];
        $data = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'text' => '', 'tokens_in' => 0, 'tokens_out' => 0,
                    'error' => $data['error']['message'] ?? "Erreur HTTP {$code}", 'model' => AK_AI_MODEL];
        }
        $text = '';
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if (($block['type'] ?? '') === 'text' && isset($block['text'])) $text .= $block['text'];
            }
        }
        return [
            'ok' => true, 'text' => trim($text),
            'tokens_in'  => (int)($data['usage']['input_tokens']  ?? 0),
            'tokens_out' => (int)($data['usage']['output_tokens'] ?? 0),
            'error' => null, 'model' => $data['model'] ?? AK_AI_MODEL,
        ];
    }
}

// --------------------------------------------------------------
// Sauvegarde / chargement génération
// --------------------------------------------------------------
if (!function_exists('ak_ai_save_generation')) {
    function ak_ai_save_generation(PDO $pdo, int $org_id, int $user_id, string $tool, array $inputs, array $result): ?int {
        try {
            $tool_def = ak_ai_tool($tool);
            $folder = $tool_def['folder'] ?? null;
            $title_src = $inputs['sujet'] ?? $inputs['nom_event'] ?? $inputs['objet'] ?? $inputs['cause'] ?? $inputs['mission'] ?? $inputs['themes'] ?? $inputs['texte'] ?? $tool_def['label'];
            $title = mb_substr(trim((string)$title_src), 0, 200);
            if ($title === '') $title = $tool_def['label'];

            $st = $pdo->prepare("INSERT INTO asso_ai_generations
                (org_id, user_id, tool_type, folder, title, input_data, output_text, model, tokens_input, tokens_output, status, error_message, created_at)
                VALUES (:org, :uid, :tool, :folder, :title, :inp, :out, :model, :tin, :tout, :status, :err, NOW())");
            $st->execute([
                ':org' => $org_id, ':uid' => $user_id, ':tool' => $tool, ':folder' => $folder,
                ':title' => $title,
                ':inp' => json_encode($inputs, JSON_UNESCAPED_UNICODE),
                ':out' => $result['text'] ?? '',
                ':model' => $result['model'] ?? AK_AI_MODEL,
                ':tin' => (int)($result['tokens_in']  ?? 0),
                ':tout' => (int)($result['tokens_out'] ?? 0),
                ':status' => !empty($result['ok']) ? 'success' : 'error',
                ':err' => $result['error'] ?? null,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) { error_log('[ak_ai_save_generation] ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('ak_ai_load_generation')) {
    function ak_ai_load_generation(PDO $pdo, int $gen_id, int $org_id): ?array {
        try {
            $st = $pdo->prepare("SELECT * FROM asso_ai_generations WHERE id = :id AND org_id = :org LIMIT 1");
            $st->execute([':id' => $gen_id, ':org' => $org_id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }
}

// --------------------------------------------------------------
// Markdown → HTML simple
// --------------------------------------------------------------
if (!function_exists('ak_ai_md_to_html')) {
    function ak_ai_md_to_html(string $md): string {
        $h = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
        $h = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $h);
        $h = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $h);
        $h = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $h);
        $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
        $h = preg_replace('/\*(.+?)\*/s',     '<em>$1</em>', $h);
        $h = preg_replace('/^- (.+)$/m', '<li>$1</li>', $h);
        $h = preg_replace('/(<li>.*?<\/li>(\n|$))+/s', "<ul>\n$0</ul>\n", $h);
        $h = nl2br($h);
        return $h;
    }
}

// --------------------------------------------------------------
// DIFFUSION : récupération destinataires
// --------------------------------------------------------------
if (!function_exists('ak_ai_collect_recipients')) {
    /**
     * @param array $sources ['roles' => ['admin','member'], 'project_ids' => [1,2], 'manual' => "a@b.fr\nc@d.fr"]
     * @return array [['email','name','source'], ...] dédupliqués
     */
    function ak_ai_collect_recipients(PDO $pdo, int $org_id, array $sources): array {
        $out = [];
        $seen = [];

        // Par rôle
        if (!empty($sources['roles']) && is_array($sources['roles'])) {
            try {
                $roles = array_values(array_filter(array_map('strval', $sources['roles'])));
                if ($roles) {
                    $place = implode(',', array_fill(0, count($roles), '?'));
                    $sql = "SELECT email, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), email) AS name, role
                            FROM users
                            WHERE org_id = ? AND role IN ($place)
                              AND (deleted_at IS NULL OR deleted_at = '' OR deleted_at = '0000-00-00 00:00:00')
                              AND (is_active IS NULL OR is_active = 1)
                              AND email IS NOT NULL AND email != ''";
                    $st = $pdo->prepare($sql);
                    $st->execute(array_merge([$org_id], $roles));
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $email = strtolower(trim($r['email']));
                        if ($email && !isset($seen[$email])) {
                            $seen[$email] = true;
                            $out[] = ['email' => $email, 'name' => $r['name'], 'source' => 'role:' . $r['role']];
                        }
                    }
                }
            } catch (Throwable $e) { error_log('[recipients role] ' . $e->getMessage()); }
        }

        // Par projet (tente la table project_users / project_members / fallback silencieux)
        if (!empty($sources['project_ids']) && is_array($sources['project_ids'])) {
            $pids = array_values(array_filter(array_map('intval', $sources['project_ids'])));
            if ($pids) {
                $place = implode(',', array_fill(0, count($pids), '?'));
                $tries = [
                    "SELECT u.email, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS name, pu.project_id
                     FROM project_users pu INNER JOIN users u ON u.id = pu.user_id
                     WHERE pu.project_id IN ($place) AND u.org_id = ?
                       AND (u.deleted_at IS NULL) AND (u.is_active IS NULL OR u.is_active = 1)
                       AND u.email IS NOT NULL AND u.email != ''",
                    "SELECT u.email, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS name, pm.project_id
                     FROM project_members pm INNER JOIN users u ON u.id = pm.user_id
                     WHERE pm.project_id IN ($place) AND u.org_id = ?
                       AND (u.deleted_at IS NULL) AND (u.is_active IS NULL OR u.is_active = 1)
                       AND u.email IS NOT NULL AND u.email != ''",
                ];
                foreach ($tries as $sql) {
                    try {
                        $st = $pdo->prepare($sql);
                        $st->execute(array_merge($pids, [$org_id]));
                        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $r) {
                            $email = strtolower(trim($r['email']));
                            if ($email && !isset($seen[$email])) {
                                $seen[$email] = true;
                                $out[] = ['email' => $email, 'name' => $r['name'], 'source' => 'project:' . (int)$r['project_id']];
                            }
                        }
                        break; // si la 1ère table fonctionne, on s'arrête
                    } catch (Throwable $e) { /* table absente, on essaie la suivante */ }
                }
            }
        }

        // Manuel (texte libre, séparateurs : virgule, point-virgule, retour ligne)
        if (!empty($sources['manual'])) {
            $raw = (string)$sources['manual'];
            $emails = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($emails as $em) {
                $em = strtolower(trim($em));
                if ($em && filter_var($em, FILTER_VALIDATE_EMAIL) && !isset($seen[$em])) {
                    $seen[$em] = true;
                    $out[] = ['email' => $em, 'name' => $em, 'source' => 'manual'];
                }
            }
        }

        return $out;
    }
}

// --------------------------------------------------------------
// DIFFUSION : envoi via Resend (un par un, simple)
// --------------------------------------------------------------
if (!function_exists('ak_ai_send_email_resend')) {
    function ak_ai_send_email_resend(string $to_email, string $to_name, string $subject, string $html): array {
        $key  = defined('RESEND_API_KEY')    ? RESEND_API_KEY    : (getenv('RESEND_API_KEY') ?: '');
        $from = defined('RESEND_FROM_EMAIL') ? RESEND_FROM_EMAIL : (getenv('RESEND_FROM_EMAIL') ?: 'noreply@assokit.fr');
        if (!$key) return ['ok' => false, 'error' => 'RESEND_API_KEY manquante'];

        $payload = [
            'from'    => 'Assokit <' . $from . '>',
            'to'      => [$to_name ? "{$to_name} <{$to_email}>" : $to_email],
            'subject' => $subject,
            'html'    => $html,
        ];
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 12,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return ['ok' => false, 'error' => 'Erreur réseau : ' . $err];
        $d = json_decode($resp, true);
        if ($code >= 200 && $code < 300) return ['ok' => true, 'id' => $d['id'] ?? null];
        return ['ok' => false, 'error' => $d['message'] ?? "HTTP {$code}"];
    }
}
