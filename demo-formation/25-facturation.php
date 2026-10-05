<?php
/**
 * 25 — Facturation : clients, devis, factures, paiements, récurrences,
 * relances, étiquettes.
 * ------------------------------------------------------------------
 * DEMO F vend des formations à des collectivités, des OPCO et des PME.
 * Les règles de la facture électronique sont respectées à la lettre,
 * parce qu'un commercial montrera l'écran Factur-X et les anomalies :
 *   - numéros « demo-formation-AAAA-NNNNNN », séquences continues par
 *     année, attribuées dans l'ordre chronologique ; les brouillons
 *     portent les numéros les plus hauts (sinon « trou de séquence ») ;
 *   - totaux = somme exacte des lignes ; association non assujettie à
 *     la TVA (taux NULL, TVA 0, HT = TTC) ;
 *   - payée ⇒ date de paiement ; en attente ⇒ échéance future ;
 *     en retard ⇒ échéance passée.
 *
 * Les dates sont relatives à aujourd'hui (12 derniers mois, puis les
 * 12 précédents) : la démo raconte la même histoire quel que soit le mois.
 * Les clients sont fictifs ; leurs e-mails sont sur le domaine de démo.
 * ------------------------------------------------------------------
 */

/** clé => [type, nom affiché, raison sociale, prénom, nom, ville, CP, profil de paiement, étiquettes, note] */
function df_cat_clients(): array
{
    return [
        'opco-proxi'   => ['company', 'OPCO Proxi-Services', 'OPCO Proxi-Services', 'Hélène', 'Garcia', 'Évry-Courcouronnes', '91000', 'public', ['entreprises', 'formation'], 'Bon de commande obligatoire.'],
        'opco-cohesion'=> ['company', 'OPCO Cohésion & Territoires', 'OPCO Cohésion & Territoires', 'Marc', 'Dumas', 'Massy', '91300', 'public', ['entreprises', 'formation'], null],
        'mission-emploi'=>['company', 'Mission Emploi Paris-Saclay', 'Association Mission Emploi Paris-Saclay', 'Sonia', 'Bertin', 'Orsay', '91400', 'public', ['insertion', 'collectivites'], null],
        'departement'  => ['company', 'Direction de l\'Insertion — Département (fictif)', 'Département de Démo-Essonne', 'Pierre', 'Lacroix', 'Évry-Courcouronnes', '91000', 'public', ['collectivites', 'insertion'], 'Mandat administratif — paiement à 45 jours.'],
        'ville'        => ['company', 'Ville de Démoville — DRH', 'Commune de Démoville', 'Agnès', 'Royer', 'Démoville', '91300', 'public', ['collectivites', 'formation'], 'Mandat administratif — paiement à 45 jours.'],
        'ccas-demoville'=>['company', 'CCAS de Démoville', 'Centre communal d\'action sociale de Démoville', 'Isabelle', 'Garnier', 'Démoville', '91300', 'public', ['collectivites', 'numerique'], null],
        'ccas-villebon'=> ['company', 'CCAS de Villebon-les-Prés', 'CCAS de Villebon-les-Prés', 'Jacques', 'Perret', 'Villebon-sur-Yvette', '91140', 'public', ['collectivites', 'numerique'], 'Mandat administratif — paiement à 45 jours.'],
        'cs-tilleuls'  => ['company', 'Centre social Les Tilleuls', 'Association Centre social Les Tilleuls', 'Fatima', 'Ziani', 'Massy', '91300', 'pme', ['fle', 'collectivites'], null],
        'cs-lilas'     => ['company', 'Centre social Les Lilas', 'Association Centre social Les Lilas', 'Romain', 'Faure', 'Palaiseau', '91120', 'pme', ['fle'], null],
        'fjt-phare'    => ['company', 'Résidence Habitat Jeunes Le Phare', 'Association Habitat Jeunes Le Phare', 'Coralie', 'Lemoine', 'Longjumeau', '91160', 'pme', ['insertion', 'numerique'], null],
        'transports'   => ['company', 'Transports Lumière SAS', 'Transports Lumière SAS', 'Grégory', 'Lumière', 'Wissous', '91320', 'pme', ['entreprises', 'formation'], null],
        'boulangeries' => ['company', 'Boulangeries Durand & Fils', 'Durand & Fils SARL', 'Alain', 'Durand', 'Massy', '91300', 'pme', ['entreprises', 'fle'], null],
        'logistique'   => ['company', 'Logistique Orly-Sud', 'Logistique Orly-Sud SAS', 'Nadège', 'Barre', 'Chilly-Mazarin', '91380', 'pme', ['entreprises', 'formation'], null],
        'hotel'        => ['company', 'Hôtel Le Relais de l\'Yvette', 'SARL Le Relais de l\'Yvette', 'Damien', 'Roche', 'Palaiseau', '91120', 'pme', ['entreprises', 'fle'], null],
        'clinique'     => ['company', 'Clinique Sainte-Aude', 'Clinique Sainte-Aude SAS', 'Véronique', 'Marchal', 'Antony', '92160', 'pme', ['entreprises', 'formation'], null],
        'cabinet'      => ['company', 'Cabinet Mercier & Associés', 'Mercier & Associés SELARL', 'Thibault', 'Mercier', 'Orsay', '91400', 'pme', ['entreprises', 'numerique'], null],
        'cooperative'  => ['company', 'Coopérative Bio du Hurepoix', 'Coopérative Bio du Hurepoix SCIC', 'Léonie', 'Faucher', 'Igny', '91430', 'pme', ['entreprises'], null],
        'region'       => ['company', 'Région (fictive) — Direction de la formation', 'Conseil régional de Démo-Île', 'Laurence', 'Hamon', 'Évry-Courcouronnes', '91000', 'public', ['collectivites', 'numerique'], 'Convention pluriannuelle — versement par tranches.'],
        'ei-renov'     => ['company', 'Rénov\'Insertion (entreprise d\'insertion)', 'Rénov\'Insertion SAS', 'Karim', 'Saïdi', 'Massy', '91300', 'pme', ['insertion', 'entreprises'], null],
        'p-ndiaye'     => ['individual', 'Aminata N\'Diaye', null, 'Aminata', 'N\'Diaye', 'Massy', '91300', 'particulier', ['fle'], null],
        'p-rossi'      => ['individual', 'Lucas Rossi', null, 'Lucas', 'Rossi', 'Palaiseau', '91120', 'particulier', ['formation'], null],
        'p-benali'     => ['individual', 'Sarah Benali', null, 'Sarah', 'Benali', 'Antony', '92160', 'particulier', ['numerique'], null],
        'p-morel'      => ['individual', 'Jean Morel', null, 'Jean', 'Morel', 'Igny', '91430', 'particulier', ['formation'], null],
        'p-cisse'      => ['individual', 'Mariam Cissé', null, 'Mariam', 'Cissé', 'Massy', '91300', 'particulier', ['fle'], null],
        'p-lefort'     => ['individual', 'Christine Lefort', null, 'Christine', 'Lefort', 'Longjumeau', '91160', 'particulier', ['insertion'], null],
        'p-ozturk'     => ['individual', 'Emre Öztürk', null, 'Emre', 'Öztürk', 'Wissous', '91320', 'particulier', ['fle'], null],
        'p-blanchard'  => ['individual', 'Nathalie Blanchard', null, 'Nathalie', 'Blanchard', 'Orsay', '91400', 'particulier', ['formation'], null],
        'p-traore'     => ['individual', 'Moussa Traoré', null, 'Moussa', 'Traoré', 'Chilly-Mazarin', '91380', 'particulier', ['insertion'], null],
        'p-gauthier'   => ['individual', 'Élise Gauthier', null, 'Élise', 'Gauthier', 'Verrières-le-Buisson', '91370', 'particulier', ['numerique'], null],
        'p-kaya'       => ['individual', 'Deniz Kaya', null, 'Deniz', 'Kaya', 'Massy', '91300', 'particulier', ['fle'], null],
        'p-ferrand'    => ['individual', 'Olivier Ferrand', null, 'Olivier', 'Ferrand', 'Palaiseau', '91120', 'particulier', ['formation'], null],
    ];
}

/** Prestations : clé => [désignation, prix unitaire HT €, [qté min, qté max], étiquette, pour particuliers ?] */
function df_cat_prestations(): array
{
    return [
        'excel'      => ['Formation Excel niveau 2 — 14 h (intra)', 1680, [1, 1], 'formation', false],
        'word'       => ['Formation Word & outils collaboratifs — 7 h', 840, [1, 2], 'formation', false],
        'jour'       => ['Journée de formation intra — accueil du public', 650, [1, 3], 'formation', false],
        'sst'        => ['Formation SST initiale — 2 jours (par stagiaire)', 230, [6, 10], 'formation', false],
        'fle-pro'    => ['Parcours FLE à visée professionnelle — 60 h (par apprenant)', 420, [3, 8], 'fle', false],
        'pix'        => ['Préparation certification Pix — 21 h (par stagiaire)', 290, [4, 9], 'numerique', false],
        'clea'       => ['Certification CléA numérique — évaluation et accompagnement', 350, [3, 8], 'numerique', false],
        'vae'        => ['Accompagnement VAE — 12 h', 780, [1, 3], 'insertion', false],
        'atelier'    => ['Atelier inclusion numérique (3 h)', 270, [4, 8], 'numerique', false],
        'mad'        => ['Mise à disposition de formateur — un mois', 2350, [1, 1], 'fle', false],
        'coaching'   => ['Accompagnement vers l\'emploi — 10 h (par participant)', 360, [3, 8], 'insertion', false],
        'dossier'    => ['Frais de dossier pédagogique', 60, [1, 1], 'formation', false],
        'p-fle'      => ['Reste à charge — Parcours FLE A2 (60 h)', 180, [1, 1], 'fle', true],
        'p-pix'      => ['Préparation certification Pix — reste à charge', 120, [1, 1], 'numerique', true],
        'p-vae'      => ['Accompagnement VAE — 12 h (financement personnel)', 480, [1, 1], 'insertion', true],
        'p-coaching' => ['Coaching emploi — 5 séances', 250, [1, 1], 'insertion', true],
        'p-delf'     => ['Frais d\'inscription examen DELF B1', 165, [1, 1], 'fle', true],
    ];
}

const DF_TAGS = [
    'formation'     => ['Formation professionnelle', '#4F46E5'],
    'insertion'     => ['Insertion', '#059669'],
    'fle'           => ['FLE', '#F59E0B'],
    'numerique'     => ['Inclusion numérique', '#0EA5E9'],
    'collectivites' => ['Collectivités', '#DC2626'],
    'entreprises'   => ['Entreprises', '#7C3AED'],
];

function df_uuid(string $graine): string
{
    $h = hash('sha256', 'demo-f|uuid|' . $graine);
    return sprintf('%s-%s-4%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3),
                   dechex(8 + hexdec($h[16]) % 4), substr($h, 17, 3), substr($h, 20, 12));
}

/** Lignes chiffrées : [[désignation, qté, PU HT €], …] → [lignes calculées, total en centimes]. */
function df_lignes(array $lignes): array
{
    $out = [];
    $total = 0;
    foreach ($lignes as [$des, $qte, $pu]) {
        $ht = (int)round($qte * $pu * 100);
        $out[] = ['designation' => $des, 'quantity' => $qte, 'unit_price_ht_cents' => (int)round($pu * 100),
                  'vat_rate' => null, 'total_ht_cents' => $ht, 'total_vat_cents' => 0, 'total_ttc_cents' => $ht];
        $total += $ht;
    }
    return [$out, $total];
}

function df_seed_facturation(): void
{
    if (!df_a_table('asso_invoices')) return;
    $u = DF::$u;
    $treso = $u['tresoriere'] ?? $u['admin'];
    $billing = 'contact@' . DF_DOMAINE;
    $org = DF::$pdo->query("SELECT * FROM organizations WHERE id = " . (int)DF::$org)->fetch(PDO::FETCH_ASSOC) ?: [];
    unset($org['notes_superadmin'], $org['internal_notes'], $org['stripe_customer_id']);
    $emetteur = $org;

    // ---------- Étiquettes ----------
    $tags = [];
    foreach (DF_TAGS as $cle => [$nom, $couleur]) {
        $tags[$cle] = df_ins('asso_tags', ['org_id' => DF::$org, 'name' => $nom, 'slug' => $cle, 'color' => $couleur,
                                           'created_by_user_id' => $u['admin'], 'created_at' => df_jh(-300, '10:00')]);
    }
    $lier = function (?string $tag, string $type, ?int $id) use ($tags) {
        if ($tag && !empty($tags[$tag]) && $id) df_ins('asso_tag_links', ['tag_id' => $tags[$tag], 'entity_type' => $type, 'entity_id' => $id]);
    };

    // ---------- Clients ----------
    $clients = [];
    $n = 0;
    foreach (df_cat_clients() as $cle => [$type, $nom, $raison, $prenom, $nomContact, $ville, $cp, $profil, $ctags, $note]) {
        $n++;
        $email = $type === 'company' ? 'compta.' . $cle . '@' . DF_DOMAINE : df_email($prenom, $nomContact, '.client');
        $ligne = [
            'org_id' => DF::$org, 'client_type' => $type, 'display_name' => $nom, 'legal_name' => $raison,
            'contact_first_name' => $prenom, 'contact_last_name' => $nomContact, 'email' => $email, 'phone' => df_tel(5000 + $n * 17, $type === 'individual'),
            'address_street' => (10 + $n * 3) . ' ' . df_choix(['rue de la République', 'avenue du Général-de-Gaulle', 'rue Pasteur', 'boulevard de la Gare', 'rue des Écoles', 'allée des Tilleuls']),
            'address_complement' => $type === 'company' && $n % 3 === 0 ? 'Service comptabilité' : null,
            'address_zip' => $cp, 'address_city' => $ville, 'address_country' => 'FR',
            'siren' => null, 'siret' => null, 'vat_number' => null, 'internal_notes' => $note,
            'created_at' => df_jh(-df_entre(330, 640), '10:00'), 'updated_at' => df_jh(-df_entre(10, 300), '10:00'),
            'created_by_user_id' => df_proba(0.7) ? $u['admin'] : $treso, 'deleted_at' => null,
        ];
        $id = df_ins('asso_clients', $ligne);
        if (!$id) continue;
        $clients[$cle] = ['id' => $id, 'profil' => $profil, 'type' => $type, 'email' => $email, 'snapshot' => $ligne + ['id' => $id], 'tags' => $ctags];
        df_retenir('clients', $cle, $id);
        foreach ($ctags as $t) $lier($t, 'client', $id);
    }
    if (!$clients) return;
    $entreprises = array_keys(array_filter($clients, fn($c) => $c['type'] === 'company'));
    $particuliers = array_keys(array_filter($clients, fn($c) => $c['type'] === 'individual'));
    $presta = df_cat_prestations();

    /** Lignes plausibles pour un client. */
    $composer = function (string $client) use ($clients, $presta): array {
        $perso = $clients[$client]['type'] === 'individual';
        $cles = array_keys(array_filter($presta, fn($p) => $p[4] === $perso));
        $prefere = array_values(array_filter($cles, fn($k) => in_array($presta[$k][3], $clients[$client]['tags'], true)));
        $principal = df_choix($prefere ?: $cles);
        [$des, $pu, [$a, $b]] = $presta[$principal];
        $lignes = [[$des, (float)df_entre($a, $b), (float)$pu]];
        if (!$perso && df_proba(0.45)) {
            $second = df_choix(array_values(array_diff($prefere ?: $cles, [$principal])) ?: $cles);
            [$d2, $p2, [$a2, $b2]] = $presta[$second];
            $lignes[] = [$d2, (float)df_entre($a2, max($a2, (int)ceil($b2 / 2))), (float)$p2];
        }
        if (!$perso && df_proba(0.25)) $lignes[] = [$presta['dossier'][0], 1.0, 60.0];
        return [$lignes, $presta[$principal][3]];
    };
    $delai = fn(string $profil) => ['public' => df_entre(35, 50), 'pme' => df_entre(15, 30), 'particulier' => df_entre(5, 10)][$profil];
    $echeance = fn(string $profil) => $profil === 'public' ? 45 : 30;

    // ---------- Devis (avant les factures : certains deviennent factures) ----------
    $specs = [];   // factures à créer : [jour, client, lignes, tag, forcer statut ?, extra]
    $debutAnnee = (int)round((strtotime(date('Y-01-01', DF::$t0)) - DF::$t0) / 86400);
    $fenetre = $debutAnnee < -60 ? $debutAnnee : -200;
    $plan = array_merge(array_fill(0, 13, 'converted'), array_fill(0, 4, 'signed'), array_fill(0, 5, 'sent'),
                        array_fill(0, 2, 'sent-expire'), array_fill(0, 3, 'refused'), array_fill(0, 2, 'draft'), ['cancelled']);
    $devis = [];
    foreach ($plan as $i => $etat) {
        $j = match ($etat) {
            'converted' => df_entre($fenetre, -25), 'signed' => df_entre(-40, -22), 'sent' => df_entre(-25, -3),
            'sent-expire' => df_entre(max($fenetre, -120), max($fenetre, -80)), 'refused' => df_entre($fenetre, -30),
            'draft' => df_entre(-1, 0), default => df_entre($fenetre, -10),
        };
        $client = df_choix($entreprises);
        [$lignes, $tag] = $composer($client);
        $devis[] = [$j, $etat, $client, $lignes, $tag];
    }
    usort($devis, fn($a, $b) => $a[0] <=> $b[0]);
    $seqDevis = [];
    $signataires = ['Agnès Royer, DRH', 'Isabelle Garnier, directrice', 'Grégory Lumière, gérant', 'Véronique Marchal, DRH', 'Hélène Garcia, conseillère formation', 'Nadège Barre, responsable RH'];
    foreach ($devis as $k => [$j, $etat, $client, $lignes, $tag]) {
        $emis = df_jh($j, sprintf('%02d:%02d', df_entre(9, 18), df_entre(0, 59)));
        $an = (int)substr($emis, 0, 4);
        $seqDevis[$an] = ($seqDevis[$an] ?? 0) + 1;
        [$calc, $total] = df_lignes($lignes);
        $num = sprintf('DEVIS-%s-%d-%06d', DF_SLUG, $an, $seqDevis[$an]);
        $envoye = $etat === 'draft' ? null : date('Y-m-d H:i:s', strtotime($emis) + 3600);
        $signe = in_array($etat, ['converted', 'signed'], true) ? df_jh(min(-1, $j + df_entre(3, 12)), '15:20') : null;
        $statut = ['converted' => 'converted', 'signed' => 'signed', 'sent' => 'sent', 'sent-expire' => 'sent',
                   'refused' => 'refused', 'draft' => 'draft', 'cancelled' => 'cancelled'][$etat];
        $qid = df_ins('asso_quotes', [
            'org_id' => DF::$org, 'client_id' => $clients[$client]['id'], 'public_uuid' => df_uuid("devis|$num"),
            'quote_number' => $num, 'quote_year' => $an, 'quote_sequence' => $seqDevis[$an],
            'issued_at' => $emis, 'expires_at' => date('Y-m-d 23:59:59', strtotime($emis) + 30 * 86400),
            'sent_at' => $envoye, 'signed_at' => $signe,
            'refused_at' => $etat === 'refused' ? df_jh(min(-1, $j + df_entre(5, 20)), '11:00') : null,
            'amount_ht_cents' => $total, 'amount_vat_cents' => 0, 'amount_ttc_cents' => $total, 'currency' => 'EUR',
            'status' => $statut, 'emitter_snapshot' => $emetteur, 'client_snapshot' => $clients[$client]['snapshot'],
            'pdf_path' => null, 'description' => $calc[0]['designation'],
            'terms' => "Acompte de 30 % à la commande. Annulation sans frais jusqu'à 7 jours avant le début de la formation.\nOrganisme de formation — déclaration d'activité n° 11 91 00000 91 (fictif). Association non assujettie à la TVA (art. 261-7-1° du CGI).",
            'internal_notes' => $etat === 'refused' ? '[Refusé par client] Budget reporté à l\'an prochain.' : null,
            'signature_type' => $signe ? df_choix(['drawn', 'checkbox']) : null,
            'signature_name' => $signe ? df_choix($signataires) : null, 'signature_image_path' => null,
            'signature_ip' => $signe ? '198.51.100.' . df_entre(2, 250) : null,
            'signature_user_agent' => $signe ? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36' : null,
            'last_viewed_at' => $envoye ? date('Y-m-d H:i:s', strtotime($envoye) + 86400 * df_entre(0, 3)) : null,
            'view_count' => $envoye ? df_entre(1, 4) : 0, 'sent_to_email' => $envoye ? $clients[$client]['email'] : null,
            'created_at' => $emis, 'updated_at' => $signe ?? $emis, 'created_by_user_id' => df_proba(0.6) ? $u['admin'] : $u['salarie'],
        ]);
        if (!$qid) continue;
        foreach ($calc as $o => $l) df_ins('asso_quote_lines', ['quote_id' => $qid, 'line_order' => $o] + $l);
        $lier($tag, 'quote', $qid);
        if ($etat === 'converted') {
            $jConv = (int)round((strtotime($signe) - DF::$t0) / 86400) + df_entre(0, 3);
            $specs[] = [min(-1, $jConv), $client, $lignes, $tag, null, ['quote' => [$qid, $num, $signe]]];
        }
    }

    // ---------- Factures récurrentes ----------
    $recurrences = [
        // [clé, titre, client, prestation, fréquence, nombre d'occurrences passées, statut]
        ['r1', 'Ateliers numériques — CCAS de Villebon', 'ccas-villebon', [['Atelier inclusion numérique (3 h) — forfait mensuel', 4.0, 270.0]], 'monthly', 10, 'active'],
        ['r2', 'Mise à disposition formatrice FLE — Centre social Les Tilleuls', 'cs-tilleuls', [['Mise à disposition de formatrice FLE — un mois', 1.0, 1850.0]], 'monthly', 9, 'active'],
        ['r3', 'Accompagnement RH trimestriel — Logistique Orly-Sud', 'logistique', [['Accompagnement vers l\'emploi des nouveaux salariés — trimestre', 1.0, 2400.0]], 'quarterly', 4, 'active'],
        ['r4', 'Français professionnel — Hôtel Le Relais', 'hotel', [['Cours de français professionnel — 8 h', 1.0, 640.0]], 'monthly', 4, 'paused'],
        ['r5', 'Convention annuelle — Mission Emploi', 'mission-emploi', [['Convention d\'accompagnement — mensualité', 1.0, 1150.0]], 'monthly', 12, 'ended'],
    ];
    $premierDuMois = (int)round((strtotime(date('Y-m-01', DF::$t0)) - DF::$t0) / 86400);
    $recIds = [];
    foreach ($recurrences as [$cle, $titre, $client, $lignes, $freq, $nb, $statut]) {
        $pas = $freq === 'quarterly' ? 3 : 1;
        $decal = $statut === 'ended' ? -14 : ($statut === 'paused' ? -6 : 0);   // mois de la dernière occurrence
        $dates = [];
        for ($k = $nb - 1; $k >= 0; $k--) $dates[] = (int)round((strtotime(df_mois($decal - $k * $pas, 1)) - DF::$t0) / 86400);
        $prochain = df_mois($pas, 1);
        if (strtotime($prochain) - DF::$t0 < 3 * 86400) $prochain = df_mois($pas + 1, 1);
        [$calc] = df_lignes($lignes);
        $rid = df_ins('asso_invoice_recurrences', [
            'org_id' => DF::$org, 'client_id' => $clients[$client]['id'] ?? null, 'source_invoice_id' => null, 'title' => $titre,
            'frequency' => $freq, 'interval_count' => 1, 'day_of_month' => 1, 'start_date' => df_j($dates[0]),
            'end_date' => $statut === 'ended' ? df_j(end($dates)) : null, 'max_occurrences' => $statut === 'ended' ? $nb : null,
            'occurrences_count' => $nb, 'next_run_date' => $statut === 'active' ? $prochain : df_mois(1, 1),
            'last_run_date' => df_j(end($dates)), 'auto_send' => 1, 'notify_admin' => 1, 'status' => $statut,
            'vat_mode' => 'none', 'currency' => 'EUR', 'notes' => null,
            'template_data' => ['lines' => array_map(fn($l) => ['label' => $l['designation'], 'designation' => $l['designation'],
                'quantity' => $l['quantity'], 'unit_price_cents_ttc' => $l['unit_price_ht_cents'], 'unit_price_ht_cents' => $l['unit_price_ht_cents'], 'vat_rate' => null], $calc)],
            'created_by' => $u['admin'], 'created_at' => df_jh($dates[0] - 3, '10:00'), 'updated_at' => df_jh(end($dates), '06:00'),
        ]);
        $recIds[$cle] = $rid;
        foreach ($dates as $d) $specs[] = [$d, $client, $lignes, $clients[$client]['tags'][0] ?? null, null, ['recurrence' => $rid]];
    }

    // ---------- Factures ponctuelles ----------
    // 12 derniers mois : 5 à 7 par mois ; 12 mois précédents : 4 à 7.
    for ($m = -23; $m <= 0; $m++) {
        $nb = $m >= -11 ? df_entre(4, 6) : df_entre(3, 5);
        for ($k = 0; $k < $nb; $k++) {
            $j = (int)round((strtotime(df_mois($m, df_entre(2, 27))) - DF::$t0) / 86400);
            if ($j > -2) continue;
            $client = df_proba(0.3) ? df_choix($particuliers) : df_choix($entreprises);
            [$lignes, $tag] = $composer($client);
            $specs[] = [$j, $client, $lignes, $tag, null, []];
        }
    }
    // Sept factures en retard, émises il y a 40 à 115 jours, chez des payeurs lents ou des PME.
    foreach ([-112, -96, -84, -71, -63, -52, -41] as $i => $j) {
        $client = ['transports', 'boulangeries', 'cabinet', 'cooperative', 'ei-renov', 'fjt-phare', 'clinique'][$i];
        [$lignes, $tag] = $composer($client);
        $specs[] = [$j, $client, $lignes, $tag, 'overdue', []];
    }
    // Une facture annulée (erreur de destinataire), puis sa remplaçante.
    [$lignesA, $tagA] = $composer('hotel');
    $specs[] = [-150, 'cabinet', $lignesA, $tagA, 'cancelled', ['note' => 'Annulée — erreur de destinataire.']];
    $specs[] = [-136, 'hotel', array_merge($lignesA, [['Frais de dossier pédagogique', 1.0, 60.0]]), $tagA, null, []];
    // Trois brouillons du jour.
    foreach ([-1, 0, 0] as $j) {
        $client = df_choix($entreprises);
        [$lignes, $tag] = $composer($client);
        $specs[] = [$j, $client, $lignes, $tag, 'draft', []];
    }

    // ---------- Anomalies volontaires, pour la démo de /anomalies ----------
    // Doublon probable (même client, même montant, à 3 jours), facture payée sans
    // date de paiement, montant hors norme (convention régionale), avoir négatif
    // déjà « ignoré » par la trésorière. Le trou de numérotation est posé plus bas.
    $dup = [['Atelier inclusion numérique (3 h)', 6.0, 300.0]];
    $specs[] = [-26, 'ccas-demoville', $dup, 'numerique', 'pending', ['nodedup' => true]];
    $specs[] = [-23, 'ccas-demoville', $dup, 'numerique', 'pending', ['nodedup' => true]];
    $specs[] = [-150, 'mission-emploi', [['Accompagnement vers l\'emploi — session de printemps (8 participants)', 8.0, 300.0]], 'insertion', 'paid-nodate', []];
    $specs[] = [-20, 'region', [['Convention « Parcours compétences numériques » — 1re tranche', 1.0, 24000.0]], 'numerique', 'pending', ['echeance' => 60]];
    $specs[] = [-60, 'clinique', [['Avoir — séance Excel annulée (formateur absent)', 1.0, -290.0]], 'formation', 'credit', []];

    // ---------- Numérotation et création ----------
    // Jamais deux factures identiques à moins de 8 jours (fausse anomalie « doublon »),
    // sauf le doublon voulu. Fait AVANT le tri : les numéros restent chronologiques.
    foreach ($specs as $i => [$j, $client, $lignes, , $force, $extra]) {
        if ($force === 'draft' || !empty($extra['nodedup'])) continue;
        $cle = $client . '|' . df_lignes($lignes)[1];
        while (array_filter($vus[$cle] ?? [], fn($y) => abs($y - $j) <= 8)) $j -= 9;
        $vus[$cle][] = $j;
        $specs[$i][0] = $j;
    }
    usort($specs, fn($a, $b) => [$a[4] === 'draft', $a[0]] <=> [$b[4] === 'draft', $b[0]]);
    $seq = [];
    $vus = [];
    $retards = [];
    $nbPending = 0;
    foreach ($specs as $n => [$j, $client, $lignes, $tag, $force, $extra]) {
        $c = $clients[$client] ?? null;
        if (!$c) continue;
        [$calc, $total] = df_lignes($lignes);
        if ($total === 0 || ($total < 0 && $force !== 'credit')) continue;

        // Heures croissantes dans la journée : les numéros suivent l'ordre d'émission.
        $rang = $parJour[$j] = ($parJour[$j] ?? -1) + 1;
        $minute = min(539, $rang * 47 + df_entre(0, 20));   // de 9 h à 17 h 59
        $emis = df_jh($j, sprintf('%02d:%02d', 9 + intdiv($minute, 60), $minute % 60));
        $an = (int)substr($emis, 0, 4);
        // Un numéro sauté, une seule fois, il y a environ quatre mois.
        if (empty($trouFait) && $j >= -120 && $force !== 'draft' && (int)substr(df_j(-120), 0, 4) === $an && ($seq[$an] ?? 0) > 3) {
            $seq[$an] = ($seq[$an] ?? 0) + 1;
            $trouFait = true;
        }
        $seq[$an] = ($seq[$an] ?? 0) + 1;
        $num = sprintf('%s-%d-%06d', DF_SLUG, $an, $seq[$an]);
        $duree = $extra['echeance'] ?? $echeance($c['profil']);
        $echu = date('Y-m-d 23:59:59', strtotime($emis) + $duree * 86400);
        $jPaie = $j + $delai($c['profil']);

        $avoir = ($force === 'credit');
        $sansDate = ($force === 'paid-nodate');
        if ($avoir || $sansDate) $statut = 'paid';
        elseif ($force) $statut = $force;
        elseif ($jPaie <= -1) $statut = 'paid';
        else { $statut = 'pending'; }
        if ($statut === 'pending' && strtotime($echu) < DF::$t0) $statut = 'paid';   // sécurité : jamais d'attente échue
        if ($statut === 'overdue') $echu = date('Y-m-d 23:59:59', strtotime($emis) + 30 * 86400);
        if ($statut === 'paid' && $jPaie > -1) $jPaie = -1;

        if ($avoir) $jPaie = $j + 2;
        $paye = $statut === 'paid' && !$sansDate ? df_jh($jPaie, sprintf('%02d:%02d', df_entre(8, 18), df_entre(0, 59))) : null;
        $envoye = $statut === 'draft' ? null : date('Y-m-d H:i:s', strtotime($emis) + 7200);
        $declare = ($statut === 'pending' && $nbPending++ < 2);
        $niveau = $statut === 'overdue' ? min(3, 1 + intdiv((int)((DF::$t0 - strtotime($echu)) / 86400), 15)) : 0;
        $desc = $extra['quote'] ?? null
            ? 'Facture suite au devis ' . $extra['quote'][1] . ' (signé le ' . date('d/m/Y', strtotime($extra['quote'][2])) . ')'
            : $calc[0]['designation'];
        $iid = df_ins('asso_invoices', [
            'recurrence_id' => $extra['recurrence'] ?? null, 'org_id' => DF::$org, 'client_id' => $c['id'],
            'public_uuid' => df_uuid("facture|$num"), 'invoice_number' => $num, 'invoice_year' => $an, 'invoice_sequence' => $seq[$an],
            'issued_at' => $emis, 'due_at' => $echu, 'paid_at' => $paye, 'sent_at' => $envoye,
            'sent_to_email' => $envoye ? $c['email'] : null,
            'amount_ht_cents' => $total, 'amount_vat_cents' => 0, 'amount_ttc_cents' => $total, 'currency' => 'EUR',
            'status' => $statut, 'invoice_type' => $avoir ? 'credit_note' : 'invoice',
            'parent_invoice_id' => $avoir ? (DF::$ids['factures_client']['clinique'][0] ?? null) : null,
            'emitter_snapshot' => $emetteur, 'client_snapshot' => $c['snapshot'], 'pdf_path' => null, 'pdf_generated_at' => null,
            'dunning_enabled' => $statut === 'overdue' ? 1 : 0, 'next_dunning_at' => null, 'dunning_level' => $niveau,
            'description' => $desc, 'internal_notes' => $extra['note'] ?? null,
            'payment_method' => $statut === 'paid' ? df_choix(['virement', 'virement', 'virement', 'cheque', 'cb']) : null,
            'payment_reference' => $statut === 'paid' ? 'VIR-' . substr($num, -6) : null,
            'created_at' => $emis, 'updated_at' => $paye ?? $envoye ?? $emis,
            'finalized_at' => $statut === 'draft' ? null : $emis, 'content_hash' => $statut === 'draft' ? null : hash('sha256', $num . '|' . $total),
            'created_by_user_id' => df_proba(0.55) ? $treso : $u['admin'],
            'last_viewed_at' => $envoye ? date('Y-m-d H:i:s', min(time() - 600, strtotime($envoye) + 86400 * df_entre(0, 4))) : null,
            'view_count' => $envoye ? df_entre(1, 5) : 0,
            'client_marked_paid_at' => $declare ? df_jh(-2, '14:12') : null,
            'client_paid_method' => $declare ? 'virement' : null,
            'client_paid_reference' => $declare ? 'VIR-' . df_entre(100000, 999999) : null,
            'client_paid_note' => $declare ? 'Virement effectué ce jour, merci !' : null,
            'confirmed_paid_by_org_at' => $paye, 'confirmed_paid_by_user_id' => $paye ? $treso : null,
        ]);
        if (!$iid) continue;
        foreach ($calc as $o => $l) df_ins('asso_invoice_lines', ['invoice_id' => $iid, 'line_order' => $o] + $l);
        $lier($tag, 'invoice', $iid);
        DF::$ids['factures'][$statut][] = $iid;
        DF::$ids['factures_client'][$client][] = $iid;
        if ($avoir) DF::$ids['avoir'] = $num;

        if (!empty($extra['quote'])) {
            df_maj('asso_quotes', ['converted_to_invoice_id' => $iid, 'converted_at' => $emis, 'converted_by_user_id' => $u['admin']],
                   'id = ?', [$extra['quote'][0]]);
        }
        if (!empty($extra['recurrence'])) {
            df_ins('asso_invoice_recurrence_runs', ['recurrence_id' => $extra['recurrence'], 'invoice_id' => $iid, 'run_date' => substr($emis, 0, 10),
                                                    'status' => 'success', 'error_message' => null, 'created_at' => $emis]);
        }
        if ($paye && !$avoir) {
            df_ins('asso_invoice_payments', ['invoice_id' => $iid, 'org_id' => DF::$org, 'amount_cents' => $total, 'paid_at' => $paye,
                                             'payment_method' => df_choix(['virement', 'virement', 'cheque', 'cb']), 'reference' => 'VIR-' . substr($num, -6),
                                             'notes' => null, 'source' => 'admin', 'recorded_by_user_id' => $treso]);
        }
        if ($declare) {
            df_ins('asso_invoice_payments', ['invoice_id' => $iid, 'org_id' => DF::$org, 'amount_cents' => $total, 'paid_at' => df_jh(-2, '14:12'),
                                             'payment_method' => 'declared', 'reference' => null, 'notes' => 'Déclaré par le client sur la page de la facture.',
                                             'source' => 'client', 'recorded_by_user_id' => null]);
        }
        // Historique des envois
        if ($envoye && df_a_table('asso_invoice_emails_log')) {
            $mail = fn(string $type, string $quand, ?int $par, string $sujet) => df_ins('asso_invoice_emails_log', [
                'invoice_id' => $iid, 'org_id' => DF::$org, 'recipient_email' => $c['email'], 'cc_email' => $billing,
                'email_type' => $type, 'subject' => $sujet, 'status' => 'sent', 'error_message' => null,
                'sent_by_user_id' => $par, 'sent_at' => $quand, 'created_at' => $quand]);
            $mail('initial', $envoye, $u['admin'], "Facture $num — Association DEMO F");
            if ($statut === 'overdue') {
                $e = strtotime($echu);
                $mail('dunning1', date('Y-m-d H:i:s', $e + 86400 * df_entre(1, 5)), null, "Rappel : facture $num arrivée à échéance");
                if ($niveau >= 2) $mail('dunning2', date('Y-m-d H:i:s', $e + 86400 * df_entre(16, 22)), null, "Relance : facture $num impayée");
                if ($niveau >= 3) $mail('dunning3', date('Y-m-d H:i:s', $e + 86400 * df_entre(46, 50)), null, "Dernier rappel avant mise en demeure — facture $num");
                $retards[] = [$iid, $emis];
            }
        }
    }

    // Widget « relances à valider » du tableau de bord : 4 factures en retard.
    foreach (array_slice($retards, 0, 4) as $i => [$iid, $emis]) {
        $niv = [1, 2, 2, 3][$i];
        $quand = date('Y-m-d H:i:s', strtotime($emis) + 86400 * (15 * $niv));
        df_ins('asso_invoice_notifications', ['invoice_id' => $iid, 'org_id' => DF::$org, 'level' => $niv, 'scheduled_at' => $quand,
                                              'shown_at' => $quand, 'responded_at' => null, 'response' => null, 'response_by_user_id' => null,
                                              'dismissed_at' => null, 'dunning_email_sent_at' => null, 'dunning_email_log_id' => null]);
    }

    // ---------- Paramètres de facturation ----------
    $an = df_annee();
    df_ins('org_invoice_settings', [
        'org_id' => DF::$org, 'next_sequence' => ($seq[$an] ?? 0) + 1, 'current_year' => $an,
        'quote_next_sequence' => ($seqDevis[$an] ?? 0) + 1, 'quote_current_year' => $an, 'default_due_days' => 30,
        'bank_name' => 'Banque de démonstration', 'bank_iban' => 'FR76 3000 6000 0112 3456 7890 189', 'bank_bic' => 'AGRIFRPP',
        'email_invoice_subject' => 'Votre facture {NUMERO} — Association DEMO F',
        'email_invoice_body' => "Bonjour {NOM_CLIENT},\n\nVeuillez trouver ci-joint la facture {NUMERO} d'un montant de {MONTANT_TTC}, à régler avant le {DATE_ECHEANCE}.\nVous pouvez la consulter et la régler en ligne : {LIEN_PUBLIC}\n\nIBAN : {IBAN}\n\nMerci pour votre confiance,\nL'équipe DEMO F — Formation & Insertion",
        'email_relance1_subject' => 'Rappel : facture {NUMERO} arrivée à échéance',
        'email_relance1_body' => "Bonjour {NOM_CLIENT},\n\nSauf erreur de notre part, la facture {NUMERO} ({MONTANT_TTC}) arrivée à échéance le {DATE_ECHEANCE} reste à régler.\nSi le paiement est en cours, merci de ne pas tenir compte de ce message.\n\n{LIEN_PUBLIC}\n\nL'équipe DEMO F",
        'email_relance2_subject' => 'Relance : facture {NUMERO} impayée',
        'email_relance2_body' => "Bonjour {NOM_CLIENT},\n\nMalgré notre premier rappel, la facture {NUMERO} ({MONTANT_TTC}) n'est pas réglée. Pouvez-vous nous indiquer la date de paiement prévue ?\n\n{LIEN_PUBLIC}\n\nL'équipe DEMO F",
        'email_relance3_subject' => 'Dernier rappel avant mise en demeure — facture {NUMERO}',
        'email_relance3_body' => "Bonjour {NOM_CLIENT},\n\nLa facture {NUMERO} ({MONTANT_TTC}) reste impayée malgré nos relances. Sans règlement sous 8 jours, nous serons contraints d'engager une procédure de recouvrement.\n\n{LIEN_PUBLIC}\n\nL'équipe DEMO F",
    ]);
}
