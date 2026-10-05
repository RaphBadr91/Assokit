<?php
/**
 * 45 — Communication : campagnes, diffusions, modèles, rédactions IA,
 * prospection (partenaires et clients potentiels) et codes QR.
 * ------------------------------------------------------------------
 * Aucune rédaction IA datée d'aujourd'hui : le quota du jour se compte
 * par personne et par outil, et une démo ne doit pas démarrer bridée.
 * Toute fiche de prospection avec une date de rappel a son rappel en
 * attente à la même date (sinon la première action l'effacerait).
 * ------------------------------------------------------------------
 */

function df_seed_communication(): void
{
    $u = DF::$u;
    $com = $u['communication'] ?? $u['salarie'];
    $actifs = df_tous_membres();
    $nbActifs = count($actifs);

    // ---------- Campagnes ----------
    if (df_a_table('communication_campaigns')) {
        $ia = [
            ['Convocation AG ordinaire', 'email', 'draft', -1, "Chères et chers adhérents,\n\nVous êtes convoqués à l'assemblée générale ordinaire de l'association DEMO F, dans trois semaines à 18 h 30, salle polyvalente (et en visio).\n\nOrdre du jour : rapport moral, rapport d'activité, comptes, renouvellement du tiers sortant du CA.\n\nVotre présence compte : 1 380 heures de formation et 214 apprenants accompagnés cette année, c'est votre réussite.\n\nSophie Laurent, présidente"],
            ['Appel à bénévoles', 'facebook', 'sent', -9, "🙋 On recrute ! L'aide aux devoirs du collège des Tilleuls cherche 3 bénévoles le lundi soir (17 h – 18 h 30). Pas besoin d'être prof : de la patience et un sourire suffisent. Écrivez-nous : contact@demo-f.assokit.fr"],
            ['Newsletter mensuelle', 'email', 'sent', -33, "Ce mois-ci chez DEMO F : 41 nouveaux adhérents, ouverture d'un groupe FLE du soir, 14 retours à l'emploi dans le PLIE, et le forum emploi qui approche (15 entreprises !)."],
            ['Communiqué de presse', 'press', 'sent', -60, "Massy — L'association DEMO F renouvelle sa certification Qualiopi et lance une offre de certification CléA numérique pour les salariés des entreprises du territoire."],
            ['Post LinkedIn', 'linkedin', 'sent', -15, "Fierté 💪 Dans notre accompagnement renforcé PLIE, 14 participants sur 40 ont déjà retrouvé un emploi ou une formation qualifiante — à mi-parcours. Merci à nos entreprises partenaires et à la Fondation Avenir Solidaire. #insertion #emploi #Essonne"],
            ['Rapport moral du président', 'email', 'draft', -2, "Une année de croissance : de 120 à 156 adhérents, 12 projets conduits, 5 formations vendues à des entreprises et des collectivités. Merci aux 26 bénévoles et aux 6 salariés qui font vivre DEMO F."],
            ['Courrier Mairie', 'other', 'sent', -80, "Objet : demande de mise à disposition d'une salle pour le groupe FLE du samedi."],
            ['Demande de partenariat entreprise', 'email', 'sent', -45, "Madame, Monsieur, nous formons chaque année plus de 200 personnes au numérique et au français professionnel. Nous serions heureux d'accueillir vos collaborateurs dans nos sessions CléA."],
            ['Relance cotisation', 'email', 'sent', -24, "Bonjour, votre adhésion arrive à échéance. Renouvelez-la en deux minutes pour continuer à profiter des ateliers. Tarif solidaire à 10 €."],
            ['Bilan de projet', 'email', 'draft', -4, "Pass Numérique : 113 bénéficiaires sur 120, 102 personnes autonomes dans leurs démarches en ligne, 4,8/5 de satisfaction."],
        ];
        foreach ($ia as [$type, $canal, $statut, $j, $texte]) {
            $quand = df_jh($j, sprintf('%02d:%02d', df_entre(9, 18), df_entre(0, 59)));
            df_ins('communication_campaigns', [
                'org_id' => DF::$org, 'created_by' => df_proba(0.5) ? $u['admin'] : $com, 'channel' => $canal === 'other' ? 'email' : $canal,
                'title' => $type . ' — ' . date('d/m/Y H:i', strtotime($quand)), 'subject' => $type, 'content' => $texte,
                'recipients_filter_json' => ['target' => 'all'], 'recipients_count' => $statut === 'sent' ? $nbActifs : 0,
                'status' => $statut, 'scheduled_at' => null, 'sent_at' => $statut === 'sent' ? date('Y-m-d H:i:s', strtotime($quand) + 3600) : null,
                'ai_generated' => 1, 'ai_cost_euros' => 0.03, 'created_at' => $quand,
            ]);
        }
        $manuelles = [
            ['Newsletter de rentrée', 'email', 'sent', -35], ['Newsletter de l\'été', 'email', 'sent', -95], ['Newsletter de printemps', 'email', 'sent', -160],
            ['Newsletter d\'hiver', 'email', 'sent', -250], ['Rappel atelier numérique', 'sms', 'sent', -6], ['Rappel cotisation', 'sms', 'sent', -40],
            ['Convocation AG (SMS)', 'sms', 'scheduled', -1, 4], ['Invitation portes ouvertes', 'email', 'scheduled', -2, 9],
            ['Annonce sortie au musée', 'email', 'cancelled', -12],
        ];
        foreach ($manuelles as $m) {
            [$titre, $canal, $statut, $j] = $m;
            $quand = df_jh($j, '10:30');
            df_ins('communication_campaigns', [
                'org_id' => DF::$org, 'created_by' => $com, 'channel' => $canal, 'title' => $titre, 'subject' => $titre,
                'content' => $titre . ' — message de l\'association DEMO F.',
                'recipients_filter_json' => $canal === 'sms' ? ['target' => 'role', 'roles' => ['member']] : ['target' => 'all'],
                'recipients_count' => $canal === 'sms' ? df_entre(40, 90) : $nbActifs, 'status' => $statut,
                'scheduled_at' => $statut === 'scheduled' ? df_jh($m[4], '09:00') : null,
                'sent_at' => $statut === 'sent' ? df_jh($j, '11:00') : null, 'ai_generated' => 0, 'ai_cost_euros' => 0, 'created_at' => $quand,
            ]);
        }
    }

    // ---------- Diffusions (e-mails envoyés depuis Assokit) ----------
    if (df_a_table('communication_broadcasts')) {
        $diffusions = [
            ['Newsletter d\'octobre — 3 nouvelles sessions CléA', 'all', null, -3],
            ['Invitation : assemblée générale ordinaire', 'all', null, -7],
            ['Rappel : cotisation de l\'année', 'by_role', ['member'], -21],
            ['Bénévoles : planning des ateliers numériques', 'by_role', ['admin', 'coordinator', 'referent'], -26],
            ['Bilan des sorties positives à 6 mois (78 %)', 'all', null, -48],
            ['Formateurs : nouveaux supports FLE disponibles', 'custom', null, -55],
            ['Newsletter de rentrée', 'all', null, -35],
            ['Atelier CV : places disponibles', 'by_role', ['member'], -70],
            ['Fermeture estivale des locaux', 'all', null, -95],
            ['Newsletter de juin', 'all', null, -125],
            ['Conseil d\'administration : documents préparatoires', 'custom', null, -140],
            ['Brouillon — Soirée solidaire de fin d\'année', 'all', null, -1, 'draft'],
            ['Brouillon — Témoignages d\'apprenants', 'all', null, -6, 'draft'],
        ];
        $q = DF::$pdo->prepare("SELECT id, email, role FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL");
        $q->execute([DF::$org]);
        $tous = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($diffusions as $d) {
            [$sujet, $type, $roles, $j] = $d;
            $statut = $d[4] ?? 'sent';
            $cible = match ($type) {
                'all' => $tous,
                'by_role' => array_values(array_filter($tous, fn($x) => in_array($x['role'], $roles, true))),
                default => df_echantillon($tous, df_entre(10, 25)),
            };
            $echecs = $statut === 'sent' && df_proba(0.35) ? df_entre(1, 3) : 0;
            $quand = df_jh($j, '10:00');
            $bid = df_ins('communication_broadcasts', [
                'org_id' => DF::$org, 'created_by_user_id' => df_proba(0.5) ? $u['admin'] : $com, 'campaign_id' => null,
                'subject' => $sujet, 'body' => "Bonjour,\n\n$sujet.\n\nRetrouvez tous les détails dans votre espace Assokit.\n\nL'équipe DEMO F",
                'recipient_type' => $type, 'recipient_roles' => $roles, 'recipient_user_ids' => $type === 'custom' ? array_map(fn($x) => (int)$x['id'], $cible) : null,
                'status' => $statut, 'nb_total' => count($cible), 'nb_sent' => $statut === 'sent' ? count($cible) - $echecs : 0,
                'nb_failed' => $echecs, 'sent_at' => $statut === 'sent' ? df_jh($j, '10:02') : null, 'created_at' => $quand,
            ]);
            if (!$bid || $statut !== 'sent') continue;
            foreach ($cible as $i => $x) {
                $ko = $i < $echecs;
                df_ins('communication_broadcast_recipients', ['broadcast_id' => $bid, 'user_id' => (int)$x['id'], 'email' => $x['email'],
                                                              'status' => $ko ? 'bounced' : 'sent', 'error_message' => $ko ? 'Boîte inexistante (bounce)' : null,
                                                              'sent_at' => df_jh($j, '10:02')]);
            }
        }
    }

    // ---------- Modèles enregistrés ----------
    foreach ([['Convocation AG ordinaire — modèle', 'ag', 'convocation_ag_ordinaire', 1, 6], ['Relance cotisation douce', 'adherents', 'relance_cotisation', 1, 14],
              ['Accueil nouvel apprenant', 'adherents', 'accueil_nouveau_membre', 0, 9], ['Demande de subvention Mairie — atelier numérique', 'subvention', 'subvention_mairie', 0, 3],
              ['Remerciement mécène', 'dons', 'remerciement_donateur', 0, 4], ['Post LinkedIn — sortie de promo', 'reseaux_sociaux', 'post_linkedin', 1, 11],
              ['Communiqué de presse — Qualiopi', 'courriers', 'communique_presse', 0, 2], ['Rapport d\'activité', 'rapport', 'rapport_activite', 0, 1]] as $i => [$titre, $cat, $type, $fav, $usage]) {
        df_ins('communication_saved_templates', ['org_id' => DF::$org, 'created_by' => $i % 2 ? $com : $u['admin'], 'category' => $cat, 'type' => $type,
                                                 'title' => $titre, 'content' => $titre . "\n\nTexte du modèle, prêt à personnaliser : {prenom}, {date}, {lieu}.",
                                                 'is_favorite' => $fav, 'usage_count' => $usage, 'created_at' => df_jh(-df_entre(60, 180), '10:00'),
                                                 'updated_at' => df_jh(-df_entre(1, 50), '10:00')]);
    }

    df_seed_ia();
    df_seed_prospection();
}

/** Rédactions IA déjà produites (jamais aujourd'hui : le quota du jour reste entier). */
function df_seed_ia(): void
{
    $u = DF::$u;
    $com = $u['communication'] ?? $u['salarie'];
    df_ins('asso_ai_settings', ['org_id' => DF::$org, 'default_tone' => 'professionnel', 'default_length' => 'medium', 'default_language' => 'fr',
                                'signature' => 'L\'équipe de DEMO F — Formation & Insertion', 'monthly_quota' => 100, 'created_at' => df_jh(-200, '10:00')]);
    if (!df_a_table('asso_ai_generations')) return;
    $catalogue = [];
    if (is_file(dirname(__DIR__) . '/asso-ai-helpers.php')) {
        require_once dirname(__DIR__) . '/asso-ai-helpers.php';
        if (function_exists('ak_ai_tools_catalog')) $catalogue = ak_ai_tools_catalog();
    }
    $gens = [
        ['convocation-ag', 'Assemblée générale ordinaire', ['type_ag' => 'ordinaire', 'date_ag' => df_j(21), 'lieu' => 'Salle polyvalente', 'ordre_jour' => "Rapport moral\nRapport d'activité\nComptes\nÉlection du tiers sortant"], 2, true,
         "# Convocation à l'assemblée générale ordinaire\n\nChères adhérentes, chers adhérents,\n\nNous avons le plaisir de vous convier à notre **assemblée générale ordinaire**, le " . date('d/m/Y', strtotime(df_j(21))) . " à 18 h 30, salle polyvalente (et en visio).\n\n**Ordre du jour**\n- Rapport moral\n- Rapport d'activité\n- Approbation des comptes\n- Élection du tiers sortant du conseil d'administration\n\nVotre voix compte : venez nombreux !\n\nSophie Laurent, présidente"],
        ['recrutement-benevoles', 'Aide aux devoirs du lundi', ['mission' => 'Aide aux devoirs', 'profil' => 'Patient, bienveillant', 'engagement_temps' => '1 h 30 par semaine', 'lieu' => 'Collège des Tilleuls', 'contact' => 'contact@demo-f.assokit.fr'], 9, true,
         "## 🙋 Rejoignez l'aide aux devoirs !\n\nChaque lundi, 18 collégiens comptent sur nous. Il nous manque **3 bénévoles** de 17 h à 18 h 30.\n\nPas besoin d'être enseignant : de la patience et de la bonne humeur suffisent.\n\n👉 Écrivez-nous : contact@demo-f.assokit.fr"],
        ['newsletter', 'Rentrée chez DEMO F', ['sujet' => 'Rentrée', 'points' => "41 nouveaux adhérents\nGroupe FLE du soir\nForum emploi", 'cta' => 'Inscrivez-vous'], 33, false,
         "# La rentrée chez DEMO F 🍂\n\n- **41 nouveaux adhérents** en septembre\n- Ouverture d'un **groupe FLE du soir**\n- **Forum emploi** : 15 entreprises qui recrutent\n\nInscriptions à l'accueil ou par retour d'e-mail."],
        ['post', 'Résultats PLIE à mi-parcours', ['platform' => 'linkedin', 'sujet' => '14 retours à l\'emploi', 'cta' => 'Devenir partenaire'], 15, true,
         "Fierté 💪 Dans notre accompagnement renforcé, **14 participants sur 40** ont déjà retrouvé un emploi ou une formation qualifiante — à mi-parcours.\n\nMerci à nos entreprises partenaires. Vous recrutez ? Parlons-en !\n\n#insertion #emploi #Essonne"],
        ['demande-subvention', 'Groupe FLE du soir', ['organisme' => 'Ville de Massy', 'projet' => 'Groupe FLE du soir', 'montant_demande' => '4 200 €', 'budget_total' => '6 000 €', 'beneficiaires' => '12 adultes', 'impact_attendu' => 'Autonomie et emploi'], 5, false,
         "## Demande de subvention — groupe FLE du soir\n\nNous sollicitons **4 200 €** pour ouvrir un groupe de français du soir destiné à **12 adultes** qui travaillent en journée.\n\n**Impact attendu** : autonomie dans les démarches, accès à l'emploi qualifié, réussite au DILF."],
        ['rapport-moral', 'Rapport moral', ['annee' => (string)df_annee(), 'faits_marquants' => 'Croissance, Qualiopi, PLIE', 'difficultes' => 'Locaux exigus', 'perspectives' => 'Nouveaux locaux'], 2, true,
         "# Rapport moral\n\nCette année a été celle de la **croissance** : de 120 à 156 adhérents, 12 projets conduits, 5 formations vendues.\n\nNos locaux deviennent trop petits : c'est une bonne nouvelle, et notre prochain chantier."],
        ['bienvenue-adherent', 'Bienvenue aux nouveaux apprenants', ['prenom' => '{prenom}', 'avantages' => 'Ateliers, accompagnement', 'next_steps' => 'Test de positionnement'], 12, false,
         "Bonjour {prenom},\n\nBienvenue chez DEMO F ! 🎉 Votre prochain rendez-vous : le **test de positionnement**, mardi à 9 h 30.\n\nÀ très vite,\nL'équipe DEMO F"],
        ['relance-cotisation', 'Relance douce', ['prenom' => '{prenom}', 'montant_cotisation' => '25 €', 'lien_paiement' => '', 'ton_relance' => 'douce'], 24, false,
         "Bonjour {prenom},\n\nVotre adhésion arrive à échéance. Renouvelez-la pour continuer à profiter des ateliers — tarif solidaire à 10 € sur simple déclaration.\n\nMerci de votre fidélité !"],
        ['remerciement-donateur', 'Merci à la Fondation', ['prenom_donateur' => 'Claire', 'montant' => '20 000 €', 'recurrent' => 'ponctuel', 'impact' => '40 jeunes accompagnés'], 70, false,
         "Chère Claire,\n\nGrâce au soutien de la Fondation Avenir Solidaire, **40 jeunes** bénéficient d'un accompagnement renforcé vers l'emploi. Merci !"],
        ['presse', 'Certification CléA', ['sujet' => 'Nouvelle offre CléA', 'contexte' => 'Qualiopi renouvelée', 'infos_cles' => '8 places', 'ville' => 'Massy', 'date' => df_j(21), 'contact' => 'contact@demo-f.assokit.fr'], 60, false,
         "**Massy** — L'association DEMO F lance une offre de **certification CléA numérique** pour les salariés des entreprises du territoire. Première session : 8 places."],
        ['idees', 'Idées de posts pour novembre', ['themes' => 'Emploi, FLE, numérique', 'periode' => 'novembre', 'nb' => '5'], 18, false,
         "1. Témoignage d'Amina, embauchée après le parcours FLE\n2. Les coulisses du forum emploi\n3. « Le saviez-vous ? » : le Pass Numérique\n4. Portrait d'une bénévole\n5. Chiffre du mois : 102 comptes Ameli créés"],
        ['reformuler', 'Message d\'accueil', ['texte' => 'Bienvenu a tous', 'objectif' => 'corriger'], 40, false,
         "Bienvenue à toutes et à tous !"],
    ];
    $gid = [];
    foreach ($gens as $i => [$outil, $titre, $champs, $j, $fav, $md]) {
        $par = [$u['admin'], $com, $u['admin'], $com, $u['admin'], $u['admin'], $com, $u['tresoriere'] ?? $com, $u['admin'], $com, $u['benevole'], $u['benevole']][$i];
        $gid[$i] = df_ins('asso_ai_generations', [
            'org_id' => DF::$org, 'user_id' => $par, 'tool_type' => $outil, 'folder' => $catalogue[$outil]['folder'] ?? null,
            'title' => $titre, 'input_data' => $champs + ['tone' => 'professionnel', 'length' => 'medium'], 'output_text' => $md,
            'model' => 'claude-sonnet-4-5', 'tokens_input' => df_entre(300, 900), 'tokens_output' => df_entre(400, 1800),
            'status' => 'success', 'error_message' => null, 'is_favorite' => $fav ? 1 : 0,
            'created_at' => df_jh(-$j, sprintf('%02d:%02d', df_entre(9, 19), df_entre(0, 59))),
        ]);
    }
    // Diffusions par e-mail de ces rédactions.
    if (df_a_table('asso_ai_diffusions')) {
        foreach ([[1, 'role:benevole', -9, 'sent'], [2, 'role:member', -33, 'sent'], [6, 'project:pass-numerique', -12, 'partial'], [7, 'role:member', -24, 'sent']] as [$g, $source, $j, $statut]) {
            if (empty($gid[$g])) continue;
            $dest = df_echantillon(df_tous_membres(), $g === 2 || $g === 7 ? 60 : 18);
            $infos = [];
            foreach ($dest as $uid) {
                $r = DF::$pdo->query("SELECT email, CONCAT(first_name,' ',last_name) n FROM users WHERE id = " . (int)$uid)->fetch(PDO::FETCH_ASSOC);
                if ($r) $infos[] = ['email' => $r['email'], 'name' => $r['n'], 'source' => $source];
            }
            $ko = $statut === 'partial' ? 2 : 0;
            $did = df_ins('asso_ai_diffusions', [
                'org_id' => DF::$org, 'user_id' => $u['admin'], 'generation_id' => $gid[$g], 'subject' => $gens[$g][1],
                'body_md' => $gens[$g][5], 'body_html' => '<p>' . nl2br(htmlspecialchars($gens[$g][5])) . '</p>', 'recipients_data' => $infos,
                'recipients_count' => count($infos), 'sent_count' => count($infos) - $ko, 'failed_count' => $ko, 'status' => $statut,
                'error_message' => $ko ? '2 adresses refusées par le serveur destinataire.' : null,
                'sent_at' => df_jh($j, '10:05'), 'created_at' => df_jh($j, '10:00'),
            ]);
            if (!$did) continue;
            foreach ($infos as $k => $d) {
                df_ins('asso_ai_diffusion_recipients', ['diffusion_id' => $did, 'email' => $d['email'], 'name' => $d['name'], 'source' => $source,
                                                        'status' => $k < $ko ? 'failed' : 'sent', 'error_message' => $k < $ko ? 'Boîte inexistante' : null,
                                                        'sent_at' => df_jh($j, '10:05'), 'created_at' => df_jh($j, '10:00')]);
            }
        }
    }
}

/** Prospection : entreprises, collectivités et associations à démarcher ; codes QR de collecte. */
function df_seed_prospection(): void
{
    if (!df_a_table('asso_prospection')) return;
    $u = DF::$u;
    $com = $u['communication'] ?? $u['salarie'];
    $acteurs = [$u['salarie'], $com, $u['admin']];

    // Codes QR
    $qr = [];
    foreach ([['forum', 'Forum des associations — stand DEMO F', 'both', 1, 214, -2, 'Laissez vos coordonnées : nous vous recontactons pour une formation ou du bénévolat.'],
              ['salon', 'Salon emploi & formation — stand DEMO F', 'collecte', 1, 96, -5, 'Vous recrutez ou vous cherchez une formation ? Laissez-nous un message.'],
              ['accueil', 'Carte de visite — accueil', 'contact', 1, 58, -1, 'Enregistrez nos coordonnées en un geste.'],
              ['portes', 'Portes ouvertes de juin', 'both', 0, 41, -110, 'Merci de votre visite !']] as [$cle, $label, $mode, $actif, $scans, $vu, $intro]) {
        $qr[$cle] = df_ins('asso_qr_codes', ['org_id' => DF::$org, 'token' => substr(hash('sha256', "demo-f|qr|$cle"), 0, 32), 'label' => $label, 'intro' => $intro,
                                             'is_active' => $actif, 'show_vcard' => $mode !== 'collecte' ? 1 : 0, 'ask_phone' => 1, 'ask_message' => $mode === 'collecte' ? 1 : 0,
                                             'scan_count' => $scans, 'submit_count' => 0, 'last_scan_at' => df_jh($vu, '16:20'), 'mode' => $mode,
                                             'created_by' => $com, 'created_at' => df_jh(-130, '10:00'), 'updated_at' => df_jh($vu, '16:20'), 'deleted_at' => null]);
    }
    // Imports
    $imports = [];
    foreach ([['Annuaire-entreprises-Essonne.xlsx', 48, 40, 6, 2, 'entreprise', -40], ['CCAS-mairies-91-92.csv', 22, 20, 2, 0, 'collectiv', -25]] as $n => [$f, $l, $a, $d, $ig, $type, $j]) {
        $imports[$n] = df_ins('asso_prospection_imports', ['org_id' => DF::$org, 'fichier' => $f, 'lignes' => $l, 'ajoutes' => $a, 'doublons' => $d, 'ignorees' => $ig,
                                                           'type_defaut' => $type, 'created_by' => $com, 'created_at' => df_jh($j, '14:00'), 'deleted_at' => null]);
    }

    $entreprises = ['Transports Lumière', 'Logistique Orly-Sud', 'Clinique Sainte-Aude', 'Hôtel Le Relais', 'Boulangeries Durand', 'Groupe Verdier Logistique', 'Restaurant Le Comptoir',
                    'Aide à domicile Essonne Services', 'Garage Moderne de Massy', 'Pharmacie des Écoles', 'Imprimerie Massy Copie', 'Supermarché Les Halles', 'BTP Rénov 91',
                    'Crèche Les Petits Pas', 'Cabinet comptable Arcadie', 'Résidence seniors Les Tilleuls', 'Agence intérim Essonne Emploi', 'Ets Morel & Fils', 'Coopérative Bio du Hurepoix',
                    'Studio graphique Pixel', 'Laverie Pressing Atlantis', 'Auto-école de la Gare', 'Fleuriste L\'Atelier', 'Plomberie Dumas', 'Maison de retraite Le Parc'];
    $collectivites = ['CCAS de Palaiseau', 'Mairie d\'Igny — service emploi', 'CCAS de Wissous', 'Mairie de Champlan', 'Communauté d\'agglo — service insertion', 'Mairie de Longjumeau',
                      'CCAS d\'Antony', 'Mission locale Nord-Essonne', 'Médiathèque de Chilly-Mazarin', 'Maison France Services Orsay'];
    $assos = ['Secours Populaire — antenne Massy', 'Restos du Cœur — Palaiseau', 'Association Les Jardins Partagés', 'Club senior de Massy', 'Association des parents d\'élèves', 'Centre culturel Paul-Bailliart'];
    $prenoms = ['Isabelle', 'Marc', 'Nathalie', 'Stéphane', 'Sandrine', 'Laurent', 'Céline', 'Olivier', 'Aurélie', 'Frédéric', 'Valérie', 'Thierry', 'Karine', 'Sébastien'];
    $noms = ['Moreau', 'Lefèvre', 'Garcia', 'Roussel', 'Fontaine', 'Chevalier', 'Gauthier', 'Perrin', 'Robin', 'Clément', 'Morin', 'Henry'];
    $villes = [['Massy', '91300'], ['Palaiseau', '91120'], ['Antony', '92160'], ['Wissous', '91320'], ['Igny', '91430'], ['Orsay', '91400'], ['Longjumeau', '91160'], ['Chilly-Mazarin', '91380']];
    $notes = ['Responsable RH, intéressée par 6 places CléA en janvier.', 'Rappeler après le comité de direction.', 'Cherche une formation Excel pour 4 salariés.',
              'Partenaire possible pour le job dating.', 'A déjà travaillé avec une autre association, à convaincre.', 'Budget formation voté en décembre.',
              'Souhaite un devis pour une session SST.', 'Intéressé par le parcours FLE pour ses employés.', null, null];

    $fiches = [];
    $liste = array_merge(array_map(fn($x) => [$x, 'entreprise'], $entreprises), array_map(fn($x) => [$x, 'collectiv'], $collectivites), array_map(fn($x) => [$x, 'asso'], $assos));
    foreach ($liste as $i => [$structure, $type]) {
        $source = $i % 7 === 0 ? 'qr' : ($i % 3 === 0 ? 'import' : 'manuel');
        [$ville, $cp] = df_choix($villes);
        $p = df_choix($prenoms); $n = df_choix($noms);
        $cree = -df_entre(5, 90);
        $appele = df_proba(0.6); $mail = df_proba(0.35);
        $salaries = $type === 'entreprise' ? (df_proba(0.75) ? 1 : null) : ($type === 'asso' ? (df_proba(0.5) ? 0 : 1) : null);
        $fid = df_ins('asso_prospection', [
            'org_id' => DF::$org, 'prenom' => $p, 'nom' => $n . ' (' . $structure . ')', 'telephone' => df_tel(7000 + $i * 13, $i % 2 === 0),
            'email' => df_email($p, $n, '.prospect' . $i), 'called' => $appele ? 1 : 0, 'called_at' => $appele ? df_jh(df_entre($cree, -1), '11:00') : null,
            'emailed' => $mail ? 1 : 0, 'emailed_at' => $mail ? df_jh(df_entre($cree, -1), '15:00') : null, 'callback_at' => null,
            'source' => $source, 'qr_id' => $source === 'qr' ? ($qr[df_choix(['forum', 'salon'])] ?? null) : null,
            'consent_at' => $source === 'qr' ? df_jh($cree, '15:30') : null, 'notes' => df_choix($notes),
            'created_by' => $source === 'qr' ? null : df_choix($acteurs), 'created_at' => df_jh($cree, '10:00'),
            'updated_by' => df_choix($acteurs), 'updated_at' => df_jh(min(-1, $cree + 5), '10:00'), 'deleted_at' => null,
            'import_id' => $source === 'import' ? ($imports[$type === 'entreprise' ? 0 : 1] ?? null) : null,
            'type' => $type, 'code_postal' => $cp, 'ville' => $ville, 'departement' => substr($cp, 0, 2),
            'salaries' => $salaries, 'nb_salaries' => $salaries === 1 ? df_choix([3, 8, 12, 25, 40, 120, 250]) : null,
        ]);
        if (!$fid) continue;
        $fiches[] = [$fid, $cree, $source, $structure];
        $ev = fn(string $t, string $d, int $j, ?int $par = null) => df_ins('asso_prospection_events', ['org_id' => DF::$org, 'prospect_id' => $fid,
            'user_id' => $par, 'type' => $t, 'detail' => $d, 'created_at' => df_jh($j, sprintf('%02d:%02d', df_entre(9, 18), df_entre(0, 59)))]);
        $ev($source === 'import' ? 'import' : 'create', $source === 'qr' ? 'Coordonnées laissées via le QR code' : "$p $n", $cree, $source === 'qr' ? null : df_choix($acteurs));
        if ($appele) $ev('call_yes', 'Appel passé', min(-1, $cree + 2), df_choix($acteurs));
        if ($mail) $ev('mail_yes', 'Plaquette envoyée', min(-1, $cree + 3), df_choix($acteurs));
        if ($salaries !== null) $ev('salaries', $salaries ? 'oui' : 'non (100 % bénévole)', min(-1, $cree + 1), df_choix($acteurs));
    }
    // Les fiches QR comptent dans les envois de leur code.
    foreach ($qr as $cle => $id) {
        if ($id) df_sql("UPDATE asso_qr_codes SET submit_count = (SELECT COUNT(*) FROM asso_prospection WHERE qr_id = ?) WHERE id = ?", [$id, $id]);
    }

    // Rappels : en retard, aujourd'hui, demain, cette semaine, plus tard.
    $echeances = [[-1, '10:00'], [-2, '14:30'], [0, '08:15'], [0, '16:00'], [0, '17:30'], [1, '09:30'], [1, '14:00'], [2, '11:00'], [3, '10:00'],
                  [4, '15:00'], [6, '09:00'], [8, '10:30'], [10, '14:00'], [15, '11:00'], [21, '10:00']];
    $issues = ['repondu', 'repondu', 'absent', 'absent', 'reporte'];
    foreach ($fiches as $i => [$fid, $cree, $source, $structure]) {
        $faits = $i % 3 === 0 ? df_entre(1, 3) : ($i % 4 === 0 ? 1 : 0);
        $rang = 0;
        for ($k = 0; $k < $faits; $k++) {
            $rang++;
            $quand = min(-1, $cree + 4 + $k * 6);
            $issue = df_choix($issues);
            df_ins('asso_prospection_rappels', ['org_id' => DF::$org, 'prospect_id' => $fid, 'rang' => $rang, 'du_at' => df_jh($quand, '10:00'),
                                                'fait_at' => df_jh($quand, '10:20'), 'issue' => $issue,
                                                'note' => ['repondu' => 'Intéressé, attend un devis.', 'absent' => 'Messagerie, message laissé.', 'reporte' => 'Rappeler après les congés.'][$issue],
                                                'cree_par' => $u['salarie'], 'fait_par' => df_choix($acteurs), 'created_at' => df_jh($quand - 3, '10:00')]);
            df_ins('asso_prospection_events', ['org_id' => DF::$org, 'prospect_id' => $fid, 'user_id' => $u['salarie'], 'type' => 'rappel_fait',
                                               'detail' => $rang . ($rang === 1 ? 'er' : 'e') . ' rappel fait', 'created_at' => df_jh($quand, '10:20')]);
        }
        if (!isset($echeances[$i])) continue;
        [$j, $h] = $echeances[$i];
        $du = df_jh($j, $h);
        df_maj('asso_prospection', ['callback_at' => $du], 'id = ?', [$fid]);
        df_ins('asso_prospection_rappels', ['org_id' => DF::$org, 'prospect_id' => $fid, 'rang' => $rang + 1, 'du_at' => $du, 'fait_at' => null,
                                            'issue' => null, 'note' => null, 'cree_par' => $u['salarie'], 'fait_par' => null, 'created_at' => df_jh(min(-1, $j - 3), '10:00')]);
        df_ins('asso_prospection_events', ['org_id' => DF::$org, 'prospect_id' => $fid, 'user_id' => $u['salarie'], 'type' => 'callback_set',
                                           'detail' => ($rang + 1) . ($rang === 0 ? 'er' : 'e') . ' rappel · ' . date('d/m/Y à H:i', strtotime($du)), 'created_at' => df_jh(min(-1, $j - 3), '10:00')]);
    }
}
