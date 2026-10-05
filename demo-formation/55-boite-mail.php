<?php
/**
 * 55 — Boîte mail (Gmail) de démonstration.
 * ------------------------------------------------------------------
 * Une boîte « contact@demo-f.assokit.fr » déjà reliée (fournisseur
 * « demo » : aucune connexion Google, aucun envoi réel). Une vingtaine
 * de conversations crédibles d'un organisme de formation associatif :
 * OPCO, Département, Région, fondation, apprenants, bénévoles, mairie,
 * assurance… Elles sont rangées par les vraies règles du module
 * (mots-clés de l'objet) ; celles qu'aucune règle ne reconnaît sont
 * marquées « rangées par l'IA ». Le rattachement (adhérent, client,
 * facture) passe par le vrai code de mail-helpers.php.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../mail-helpers.php';

function df_seed_boite_mail(): void
{
    if (!df_a_table('mail_accounts') || !df_a_table('mail_threads')) return;
    $org = DF::$org;
    $pdo = DF::$pdo;

    $acc_id = df_ins('mail_accounts', [
        'org_id' => $org, 'provider' => 'demo', 'email' => 'contact@' . DF_DOMAINE, 'display_name' => 'DEMO F — Formation & Insertion',
        'history_id' => null, 'initial_done' => 1, 'status' => 'active', 'last_sync_at' => date('Y-m-d H:i:s', time() - 240),
        'sync_days' => 30, 'retention_months' => 24, 'ai_sort' => 1,
        'signature' => "Sophie Laurent\nPrésidente — DEMO F Formation & Insertion\n01 99 00 12 34",
        'connected_by_user_id' => DF::$u['admin'], 'created_at' => df_jh(-60, '10:15'),
    ]);
    if (!$acc_id) return;

    foreach (mail_default_categories() as $i => [$slug, $label, $color, $icon, $kw, $roles]) {
        if ($slug === 'subventions') $kw .= ', @fondation-avenir.example';
        df_ins('mail_categories', ['org_id' => $org, 'slug' => $slug, 'label' => $label, 'color' => $color, 'icon' => $icon,
                                   'keywords' => $kw, 'roles' => $roles ?: null, 'position' => $i + 1, 'is_system' => $slug === 'autre' ? 1 : 0]);
    }
    $acc = ['id' => $acc_id, 'org_id' => $org, 'email' => 'contact@' . DF_DOMAINE, 'display_name' => 'DEMO F — Formation & Insertion', 'provider' => 'demo'];

    // Données réelles de la démo, pour que les rattachements tombent juste
    $client = fn(string $nom) => df_val("SELECT email FROM asso_clients WHERE org_id = ? AND display_name LIKE ? AND email IS NOT NULL LIMIT 1", [$org, $nom . '%']);
    $facture = fn(string $statut, string $nom) => df_val("SELECT i.invoice_number FROM asso_invoices i JOIN asso_clients c ON c.id = i.client_id
                                                          WHERE i.org_id = ? AND i.status = ? AND c.display_name LIKE ? ORDER BY i.issued_at DESC LIMIT 1", [$org, $statut, $nom . '%']);
    $mail_u = fn(string $k) => df_val("SELECT email FROM users WHERE id = ?", [DF::$u[$k]]);

    $opco      = $client('OPCO Proxi') ?: 'compta.opco-proxi@' . DF_DOMAINE;
    $dept      = $client('Direction de l\'Insertion') ?: 'compta.departement@' . DF_DOMAINE;
    $ccas      = $client('CCAS de Villebon') ?: 'compta.ccas-villebon@' . DF_DOMAINE;
    $tilleuls  = $client('Centre social Les Tilleuls') ?: 'compta.cs-tilleuls@' . DF_DOMAINE;
    $f_opco    = $facture('paid', 'OPCO Proxi') ?: 'demo-formation-2026-000083';
    $f_dept    = $facture('pending', 'Direction de l\'Insertion') ?: ($facture('overdue', 'Direction de l\'Insertion') ?: 'demo-formation-2026-000079');
    $f_tilleul = $facture('paid', 'Centre social Les Tilleuls') ?: 'demo-formation-2026-000087';

    // [expéditeur, nom, jour, heure, objet, [[sens, minutes après le 1er, texte]], non lu, catégorie si l'IA a dû trancher]
    $fils = [
        [$opco, 'Hélène Garcia — OPCO Proxi-Services', -1, '08:42', "Facture $f_opco : bon de commande à joindre", [
            ['in', 0, "Bonjour,\n\nNous avons bien reçu votre facture $f_opco pour la session « Bureautique — agents d'accueil ».\n\nPour la mettre en paiement, merci de nous renvoyer la facture avec notre numéro de bon de commande (BC-2026-1187) et la feuille d'émargement signée.\n\nBien cordialement,\nHélène Garcia\nService formation — OPCO Proxi-Services"],
        ], true, null],
        [$dept, 'Pierre Lacroix — Département', -3, '10:05', "Relance : facture $f_dept en attente de mandatement", [
            ['out', 0, "Bonjour Monsieur Lacroix,\n\nSauf erreur de notre part, la facture $f_dept relative au parcours PLIE n'a pas encore été mandatée.\n\nPourriez-vous nous indiquer où en est le traitement ?\n\nBien cordialement,\nKarim Benali\nCoordinateur pédagogique"],
            ['in', 1210, "Bonjour Monsieur Benali,\n\nLa facture est bien arrivée au service, elle partira au mandatement avec le lot du 20. Comptez environ trois semaines pour le virement.\n\nCordialement,\nPierre Lacroix\nDirection de l'Insertion"],
        ], true, null],
        ['aap-numerique@region-demo.example', 'Région Île-de-Démo — Pôle formation', -2, '14:30', 'AAP « Compétences numériques 2027 » : ouverture des candidatures', [
            ['in', 0, "Madame, Monsieur,\n\nL'appel à projets « Compétences numériques 2027 » est ouvert jusqu'au 15 décembre. Il finance des parcours d'inclusion numérique de 20 à 60 heures, à hauteur de 10 000 à 60 000 € par structure.\n\nLe cahier des charges et le dossier de candidature sont disponibles sur la plateforme régionale.\n\nLe pôle formation"],
        ], true, null],
        [$mail_u('financeur'), 'Claire Vasseur', -5, '16:20', 'Convention 2026 — point d\'étape et bilan intermédiaire', [
            ['in', 0, "Bonjour Sophie,\n\nComme prévu dans notre convention, pourriez-vous nous transmettre d'ici fin novembre un bilan intermédiaire du parcours FLE (participants, assiduité, premières sorties positives) ?\n\nJe serais ravie d'en discuter lors d'une visite sur site si vous avez une date à proposer.\n\nBelle journée,\nClaire Vasseur\nChargée de mission — Fondation Avenir Solidaire"],
            ['out', 95, "Bonjour Claire,\n\nAvec plaisir : nous vous envoyons le bilan intermédiaire la semaine du 23 novembre. Pour la visite, le jeudi 26 novembre à 14 h vous conviendrait-il ? Vous pourriez assister à la fin d'un atelier.\n\nBien à vous,\nSophie Laurent"],
            ['in', 260, "Parfait pour le jeudi 26 à 14 h, je bloque le créneau. Merci !\n\nClaire"],
        ], false, null],
        [$mail_u('membre'), 'Thomas Petit', -4, '19:12', 'Renouvellement de mon adhésion', [
            ['in', 0, "Bonjour,\n\nMon adhésion arrive bientôt à échéance. Est-ce que je peux la renouveler en ligne, et est-ce que le tarif réduit demandeur d'emploi s'applique toujours ?\n\nMerci beaucoup,\nThomas"],
        ], true, null],
        ['camille.roussel@mail.example', 'Camille Roussel', -6, '11:47', 'Candidature spontanée — bénévolat accompagnement numérique', [
            ['in', 0, "Bonjour,\n\nRetraitée de la fonction publique, je souhaiterais donner deux demi-journées par semaine pour accompagner des personnes éloignées du numérique (démarches en ligne, smartphone).\n\nJe suis disponible pour en parler quand vous le souhaitez.\n\nCamille Roussel"],
            ['out', 180, "Bonjour Madame Roussel,\n\nMerci beaucoup pour votre message, votre profil correspond tout à fait aux besoins de nos ateliers numériques. Seriez-vous disponible mardi prochain à 10 h pour une rencontre à l'accueil ?\n\nBien cordialement,\nKarim Benali"],
        ], false, null],
        ['vie-associative@demoville.example', 'Mairie de Démoville — Vie associative', -7, '09:20', 'Invitation : forum des associations du 14 novembre', [
            ['in', 0, "Madame la Présidente,\n\nNous avons le plaisir de vous inviter au forum des associations qui se tiendra le samedi 14 novembre de 10 h à 17 h au gymnase Jean-Moulin. Un stand de 3 mètres vous sera réservé.\n\nMerci de confirmer votre participation avant le 7 novembre.\n\nLe service Vie associative"],
        ], false, null],
        ['contrats@assocassur.example', 'Assoc\'Assur', -9, '08:05', 'Votre attestation d\'assurance responsabilité civile 2027', [
            ['in', 0, "Madame, Monsieur,\n\nVous trouverez ci-joint votre attestation d'assurance responsabilité civile pour l'année 2027, ainsi que l'avenant couvrant les locaux de la rue des Lilas.\n\nVotre conseiller Assoc'Assur"],
        ], false, null],
        ['partenariats@logistique-yvette.example', 'Julien Masson — Logistique de l\'Yvette', -8, '15:40', 'Proposition de partenariat — job dating logistique', [
            ['in', 0, "Bonjour,\n\nNous recrutons 12 préparateurs de commandes en CDI à Wissous. Nous serions intéressés par un job dating avec les stagiaires de vos parcours insertion, et pourrions accueillir des stages d'observation.\n\nAuriez-vous un créneau pour en discuter ?\n\nJulien Masson\nResponsable RH"],
        ], true, null],
        ['newsletter@mouvement-asso.example', 'Le Mouvement associatif (lettre)', -2, '07:30', 'La lettre d\'octobre du Mouvement associatif', [
            ['in', 0, "Au sommaire ce mois-ci : les nouvelles règles de compte d'emploi des ressources, un webinaire sur le mécénat de compétences et 5 bonnes pratiques pour vos demandes de financement."],
        ], false, 'autre'],
        ['amina.diallo@mail.example', 'Amina Diallo', -10, '17:55', 'Attestation de fin de formation FLE', [
            ['in', 0, "Bonjour,\n\nJ'ai terminé le parcours FLE A1 en septembre. Pourriez-vous me faire une attestation de fin de formation ? France Travail me la demande pour mon dossier.\n\nMerci beaucoup,\nAmina Diallo"],
            ['out', 70, "Bonjour Madame Diallo,\n\nVotre attestation est prête : vous pouvez la retirer à l'accueil dès demain, ou nous pouvons vous l'envoyer par e-mail en PDF si vous préférez.\n\nBelle journée,\nJulie Moreau"],
        ], false, null],
        [$ccas, 'Jacques Perret — CCAS de Villebon', -12, '10:30', 'Devis pour trois ateliers numériques seniors', [
            ['in', 0, "Bonjour,\n\nPourriez-vous nous adresser un devis pour trois ateliers « tablette et démarches en ligne » de 2 heures, pour des groupes de 8 seniors, en janvier ?\n\nCordialement,\nJacques Perret"],
            ['out', 300, "Bonjour Monsieur Perret,\n\nVous trouverez notre devis dans votre espace : 3 ateliers de 2 h, matériel fourni, 690 € au total (association non assujettie à la TVA).\n\nNous restons à votre disposition pour caler les dates.\n\nKarim Benali"],
            ['in', 1500, "Merci, c'est validé de notre côté. Je vous propose les mardis 12, 19 et 26 janvier à 14 h.\n\nJ. Perret"],
        ], true, null],
        ['declarations@urssaf-demo.example', 'Urssaf (démo)', -15, '06:10', 'Urssaf : déclaration sociale à transmettre avant le 15 novembre', [
            ['in', 0, "Votre prochaine échéance de cotisations est fixée au 15 novembre. Pensez à transmettre votre déclaration sociale nominative avant cette date."],
        ], false, null],
        ['conseiller.massy@francetravail-demo.example', 'France Travail — agence de Massy', -11, '14:15', 'Orientation de deux personnes vers votre formation FLE du soir', [
            ['in', 0, "Bonjour,\n\nJe souhaite orienter deux demandeuses d'emploi vers votre session FLE du soir (niveau A1). Reste-t-il des places pour la rentrée de novembre ?\n\nBien cordialement,\nAgnès Martin\nConseillère"],
        ], false, null],
        ['fatou.ndiaye@mail.example', 'Fatou Ndiaye', -1, '18:40', 'Absence de ma fille mercredi', [
            ['in', 0, "Bonjour,\n\nMa fille Aïssatou ne pourra pas venir à l'aide aux devoirs mercredi, elle a rendez-vous chez le médecin. Désolée pour le dérangement.\n\nFatou Ndiaye"],
        ], true, 'formations'],
        ['dons@reconditionne-solidaire.example', 'Reconditionné Solidaire', -13, '11:00', 'Don de 10 ordinateurs portables pour vos ateliers', [
            ['in', 0, "Bonjour,\n\nDans le cadre de notre programme solidaire, nous pouvons vous remettre 10 ordinateurs portables reconditionnés (Windows 11, garantie 1 an). Il suffit de nous retourner la convention de don signée.\n\nL'équipe Reconditionné Solidaire"],
        ], false, 'partenaires'],
        [$mail_u('admin'), 'Sophie Laurent', -16, '21:05', 'Ordre du jour du conseil d\'administration du 3 novembre', [
            ['in', 0, "Bonjour à tous,\n\nVoici l'ordre du jour proposé : 1. Budget prévisionnel 2027, 2. Renouvellement de la convention PLIE, 3. Recrutement d'un formateur FLE, 4. Questions diverses.\n\nSophie"],
        ], false, null],
        ['agence.evry@banque-coop.example', 'Banque Coopérative', -18, '09:00', 'Banque Coopérative — votre relevé mensuel est disponible', [
            ['in', 0, "Votre relevé de compte du mois de septembre est disponible dans votre espace en ligne."],
        ], false, null],
        [$tilleuls, 'Fatima Ziani — Centre social Les Tilleuls', -14, '13:25', "Facture $f_tilleul — paiement effectué", [
            ['in', 0, "Bonjour,\n\nPour information, le virement de la facture $f_tilleul a été effectué ce jour.\n\nMerci encore pour les ateliers FLE, les retours des familles sont excellents !\n\nFatima Ziani"],
            ['out', 40, "Bonjour Fatima,\n\nMerci pour l'information et pour ces beaux retours, ils feront plaisir à l'équipe !\n\nBien à vous,\nSophie Laurent"],
        ], false, null],
        ['qualite@certif-demo.example', 'Certif\'Démo — organisme certificateur', -20, '10:10', 'Audit de surveillance Qualiopi : proposition de dates', [
            ['in', 0, "Madame, Monsieur,\n\nVotre audit de surveillance Qualiopi doit avoir lieu avant le 31 janvier. Nous vous proposons les 8, 9 ou 15 décembre (une journée, sur site).\n\nMerci de nous indiquer la date retenue.\n\nService qualité"],
        ], true, null],
    ];

    $cats = mail_categories($pdo, $org);
    $by_slug = []; foreach ($cats as $c) $by_slug[$c['slug']] = $c;
    $me = 'contact@' . DF_DOMAINE;
    $users_nom = ['admin' => 'Sophie Laurent', 'salarie' => 'Karim Benali', 'benevole' => 'Julie Moreau'];

    foreach ($fils as $n => [$from, $nom, $j, $h, $objet, $msgs, $nonlu, $ia]) {
        if (!$from) continue;
        $gtid = 'demo-t-' . ($n + 1);
        $debut = strtotime(df_passe($j, $h));
        $tid = df_ins('mail_threads', [
            'org_id' => $org, 'account_id' => $acc_id, 'gmail_thread_id' => $gtid, 'subject' => $objet,
            'counterpart_email' => strtolower($from), 'counterpart_name' => $nom, 'category_source' => 'none',
            'created_at' => date('Y-m-d H:i:s', $debut),
        ]);
        if (!$tid) continue;
        foreach ($msgs as $k => [$sens, $min, $texte]) {
            $quand = min(time() - 60, $debut + 60 * $min);
            $out = $sens === 'out';
            $signataire = $out ? (preg_match('/\n(Sophie Laurent|Karim Benali|Julie Moreau)\s*$/u', $texte, $mm) ? $mm[1] : 'Sophie Laurent') : null;
            df_ins('mail_messages', [
                'org_id' => $org, 'thread_id' => $tid, 'gmail_message_id' => "demo-m-" . ($n + 1) . "-$k",
                'rfc_message_id' => '<demo-' . ($n + 1) . "-$k@" . DF_DOMAINE . '>', 'references_hdr' => null,
                'direction' => $out ? 'out' : 'in',
                'from_email' => $out ? $me : strtolower($from), 'from_name' => $out ? $signataire : $nom, 'reply_to' => null,
                'to_list' => json_encode([$out ? ['email' => strtolower($from), 'name' => $nom] : ['email' => $me, 'name' => 'DEMO F']], JSON_UNESCAPED_UNICODE),
                'cc_list' => '[]', 'subject' => ($k > 0 ? 'Re: ' : '') . $objet, 'body_text' => $texte, 'body_html' => null,
                'sent_at' => date('Y-m-d H:i:s', $quand),
                'label_ids' => $out ? 'SENT' : ('INBOX' . ($nonlu && $k === count($msgs) - 1 ? ',UNREAD' : '')),
                'attachments_json' => null,
                'sent_by_user_id' => $out ? (array_search($signataire, $users_nom, true) ? DF::$u[array_search($signataire, $users_nom, true)] : DF::$u['admin']) : null,
                'created_at' => date('Y-m-d H:i:s', $quand),
            ]);
        }
        mail_refresh_thread($pdo, $tid);
        mail_link_thread($pdo, $org, $tid);
        $c = mail_rule_category($cats, $objet, strtolower($from));
        if ($c) $src = 'rule';
        else { $c = $by_slug[$ia ?: 'autre'] ?? $by_slug['autre']; $src = 'ai'; }
        $pdo->prepare("UPDATE mail_threads SET category_id = ?, category_source = ? WHERE id = ?")->execute([$c['id'], $src, $tid]);
    }
}
