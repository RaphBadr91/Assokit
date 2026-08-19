<?php
/**
 * api/seed-grants-catalog.php
 * ------------------------------------------------------------------
 * Amorce le catalogue de dispositifs de financement (grant_catalog).
 * Dispositifs NATIONAUX réels et stables (org_id NULL = global).
 * Idempotent : upsert par clé unique (source, source_ref).
 *
 * ⚠️  Les montants/dates exacts varient chaque année et selon le territoire.
 *     On reste volontairement prudent : deadline_apply NULL quand la date
 *     change chaque année, summary explicite, source_url = page officielle.
 *     L'enrichissement fin (dates réelles, dispositifs locaux) viendra de
 *     l'API Aides-territoires ou d'une curation manuelle par org.
 *
 * Exécution : php api/seed-grants-catalog.php   (ou via navigateur admin)
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../config.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    // En web : réservé au super-admin / fondateur pour éviter tout abus.
    require_once __DIR__ . '/../includes-layout.php';
    require_login();
    $u = current_user();
    if (empty($u['is_super_admin']) && empty($u['is_founder'])) {
        http_response_code(403);
        exit('Réservé au fondateur.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

/**
 * Catalogue curé. Chaque entrée = un dispositif.
 * beneficiary / sectors : CSV. sectors vide = tous secteurs.
 */
$catalog = [
    // ─────────────── ÉTAT — Vie associative (transverse) ───────────────
    [
        'title' => "FDVA 1 — Formation des bénévoles",
        'funder_name' => "État / DJEPVA — FDVA",
        'funder_type' => 'etat', 'program_code' => 'FDVA1',
        'summary' => "Financement de la formation des bénévoles associatifs (hors sport, qui relève de l'ANS). Campagne annuelle nationale, dépôt sur Le Compte Asso. Ouvert à toute association qui forme ses bénévoles.",
        'geo_scope' => 'national', 'sectors' => '', 'beneficiary' => 'association',
        'amount_min' => 500, 'amount_max' => 15000, 'recurrence' => 'annuel',
        'apply_url' => 'https://lecompteasso.associations.gouv.fr/',
        'source_ref' => 'etat-fdva1',
        'source_url' => 'https://www.associations.gouv.fr/le-fdva-formation.html',
    ],
    [
        'title' => "FDVA 2 — Fonctionnement et projets innovants",
        'funder_name' => "État / Préfecture — FDVA",
        'funder_type' => 'etat', 'program_code' => 'FDVA2',
        'summary' => "Soutien au fonctionnement global ou à un projet innovant d'une association. Campagne annuelle instruite au niveau départemental/régional. Priorité aux petites associations peu subventionnées.",
        'geo_scope' => 'national', 'sectors' => '', 'beneficiary' => 'association',
        'amount_min' => 1000, 'amount_max' => 20000, 'recurrence' => 'annuel',
        'apply_url' => 'https://lecompteasso.associations.gouv.fr/',
        'source_ref' => 'etat-fdva2',
        'source_url' => 'https://www.associations.gouv.fr/le-fdva-fonctionnement-innovation.html',
    ],
    [
        'title' => "Poste FONJEP",
        'funder_name' => "FONJEP — Jeunesse & Éducation populaire",
        'funder_type' => 'etat', 'program_code' => 'FONJEP',
        'summary' => "Aide pluriannuelle à l'emploi (poste FONJEP) pour les associations d'éducation populaire, jeunesse, sport ou animation de la vie sociale. Cofinancement d'un poste salarié sur 3 ans renouvelables.",
        'geo_scope' => 'national', 'sectors' => 'jeunesse,education,social,sport', 'beneficiary' => 'association',
        'amount_min' => 7164, 'amount_max' => 8000, 'recurrence' => 'pluriannuel',
        'apply_url' => 'https://www.fonjep.org/',
        'source_ref' => 'etat-fonjep',
        'source_url' => 'https://www.fonjep.org/le-poste-fonjep',
    ],
    [
        'title' => "Agrément Service Civique",
        'funder_name' => "État / Agence du Service Civique",
        'funder_type' => 'etat', 'program_code' => 'SC',
        'summary' => "Accueil de volontaires en Service Civique : l'indemnité du volontaire (~620 €/mois) est prise en charge par l'État (versée par l'ASP), plus une aide au tutorat. Permet de renforcer l'action sans coût salarial. Agrément permanent.",
        'geo_scope' => 'national', 'sectors' => '', 'beneficiary' => 'association',
        'amount_min' => null, 'amount_max' => null, 'recurrence' => 'permanent',
        'apply_url' => 'https://www.service-civique.gouv.fr/organismes',
        'source_ref' => 'etat-service-civique',
        'source_url' => 'https://www.service-civique.gouv.fr/',
    ],
    [
        'title' => "DLA — Dispositif Local d'Accompagnement",
        'funder_name' => "État / Banque des Territoires",
        'funder_type' => 'etat', 'program_code' => 'DLA',
        'summary' => "Accompagnement gratuit (conseil, stratégie, consolidation de l'emploi) pour les associations employeuses. Ce n'est pas une subvention en numéraire mais une prestation d'accompagnement financée, très utile pour structurer et pérenniser.",
        'geo_scope' => 'national', 'sectors' => '', 'beneficiary' => 'association',
        'amount_min' => null, 'amount_max' => null, 'recurrence' => 'permanent',
        'apply_url' => 'https://www.info-dla.fr/',
        'source_ref' => 'etat-dla',
        'source_url' => 'https://www.info-dla.fr/',
    ],

    // ─────────────── SPORT ───────────────
    [
        'title' => "ANS — Projet Sportif Fédéral (PSF)",
        'funder_name' => "Agence nationale du Sport",
        'funder_type' => 'etat', 'program_code' => 'ANS-PSF',
        'summary' => "Subvention aux clubs et associations sportives affiliées, via la campagne annuelle de leur fédération (part territoriale de l'ANS). Développement de la pratique, emploi sportif, équipements de proximité.",
        'geo_scope' => 'national', 'sectors' => 'sport', 'beneficiary' => 'association',
        'amount_min' => 1000, 'amount_max' => 25000, 'recurrence' => 'annuel',
        'apply_url' => 'https://lecompteasso.associations.gouv.fr/',
        'source_ref' => 'etat-ans-psf',
        'source_url' => 'https://www.agencedusport.fr/',
    ],

    // ─────────────── SOCIAL / FAMILLE / ENFANCE ───────────────
    [
        'title' => "CAF — Appels à projets (REAAP, CLAS, ALSH…)",
        'funder_name' => "CAF (Caisse d'Allocations Familiales)",
        'funder_type' => 'caf', 'program_code' => 'CAF-AAP',
        'summary' => "Soutiens de la CAF départementale : parentalité (REAAP), accompagnement à la scolarité (CLAS), accueils de loisirs (ALSH), animation de la vie sociale. Enveloppes et prestations de service selon le territoire.",
        'geo_scope' => 'departement', 'sectors' => 'social,jeunesse,education,famille', 'beneficiary' => 'association',
        'amount_min' => 1000, 'amount_max' => 30000, 'recurrence' => 'annuel',
        'apply_url' => 'https://www.caf.fr/partenaires',
        'source_ref' => 'caf-aap',
        'source_url' => 'https://www.caf.fr/partenaires/caf-partenaire-associations',
    ],
    [
        'title' => "Politique de la Ville — Contrats de ville (QPV)",
        'funder_name' => "État / ANCT — Politique de la ville",
        'funder_type' => 'etat', 'program_code' => 'QPV',
        'summary' => "Appels à projets annuels pour les actions menées en Quartier Prioritaire de la Ville (QPV) : cohésion sociale, emploi, éducation, citoyenneté. Réservé aux projets bénéficiant aux habitants des QPV.",
        'geo_scope' => 'national', 'sectors' => 'social,jeunesse,education,emploi', 'beneficiary' => 'association',
        'amount_min' => 1000, 'amount_max' => 40000, 'recurrence' => 'annuel',
        'apply_url' => 'https://agence-cohesion-territoires.gouv.fr/',
        'source_ref' => 'etat-qpv',
        'source_url' => 'https://agence-cohesion-territoires.gouv.fr/politique-de-la-ville-95',
        'req_qpv' => 1,
    ],

    // ─────────────── FONDATIONS / MÉCÉNAT ───────────────
    [
        'title' => "Fondation de France — Appels à projets",
        'funder_name' => "Fondation de France",
        'funder_type' => 'fondation', 'program_code' => 'FDF',
        'summary' => "Large gamme d'appels à projets (solidarité, santé, environnement, culture, recherche). Réservé aux associations d'intérêt général. Consulter le calendrier des appels en cours.",
        'geo_scope' => 'national', 'sectors' => 'social,sante,environnement,culture', 'beneficiary' => 'association',
        'amount_min' => 2000, 'amount_max' => 50000, 'recurrence' => 'ponctuel',
        'apply_url' => 'https://www.fondationdefrance.org/fr/nos-appels-a-projets',
        'source_ref' => 'fond-fdf',
        'source_url' => 'https://www.fondationdefrance.org/fr/trouver-une-subvention',
        'req_ig' => 1,
    ],
    [
        'title' => "Fondation la France s'engage",
        'funder_name' => "Fondation la France s'engage",
        'funder_type' => 'fondation', 'program_code' => 'FFE',
        'summary' => "Soutien aux projets d'innovation sociale à fort impact et essaimables. Dotation importante + accompagnement sur 3 ans. Très sélectif, pour des structures déjà éprouvées cherchant à changer d'échelle.",
        'geo_scope' => 'national', 'sectors' => 'social,environnement,education,emploi', 'beneficiary' => 'association',
        'amount_min' => 30000, 'amount_max' => 300000, 'recurrence' => 'annuel',
        'apply_url' => 'https://fondationlafrancesengage.org/',
        'source_ref' => 'fond-ffe',
        'source_url' => 'https://fondationlafrancesengage.org/',
        'req_ig' => 1,
    ],
    [
        'title' => "Mécénat d'entreprise & fondations territoriales",
        'funder_name' => "Fondations d'entreprise (Crédit Agricole, Macif, Vinci…)",
        'funder_type' => 'entreprise', 'program_code' => 'MECENAT',
        'summary' => "De nombreuses fondations d'entreprise soutiennent des projets locaux d'intérêt général (lien social, insertion, environnement). Ciblez celles présentes sur votre territoire ; l'association doit pouvoir émettre un reçu fiscal.",
        'geo_scope' => 'national', 'sectors' => '', 'beneficiary' => 'association',
        'amount_min' => 1000, 'amount_max' => 20000, 'recurrence' => 'ponctuel',
        'apply_url' => 'https://www.fondationdefrance.org/fr/les-fondations-sous-egide',
        'source_ref' => 'ent-mecenat',
        'source_url' => 'https://www.fondationdefrance.org/',
        'req_ig' => 1,
    ],

    // ─────────────── CULTURE ───────────────
    [
        'title' => "DRAC — Aides à la création & diffusion culturelle",
        'funder_name' => "État / DRAC (Ministère de la Culture)",
        'funder_type' => 'etat', 'program_code' => 'DRAC',
        'summary' => "Aides des Directions régionales des affaires culturelles : création, diffusion, éducation artistique et culturelle, patrimoine. Dispositifs variés selon la discipline et la région.",
        'geo_scope' => 'region', 'sectors' => 'culture,patrimoine', 'beneficiary' => 'association',
        'amount_min' => 2000, 'amount_max' => 50000, 'recurrence' => 'annuel',
        'apply_url' => 'https://www.culture.gouv.fr/Regions',
        'source_ref' => 'etat-drac',
        'source_url' => 'https://www.culture.gouv.fr/Aides-demarches/Subventions',
    ],

    // ─────────────── EUROPE / JEUNESSE ───────────────
    [
        'title' => "Erasmus+ & Corps européen de solidarité",
        'funder_name' => "Union européenne / Agence Erasmus+",
        'funder_type' => 'europe', 'program_code' => 'ERASMUS',
        'summary' => "Financement de la mobilité, des échanges de jeunes, du volontariat et des partenariats européens. Pour les associations de jeunesse, d'éducation et de sport. Appels à échéances fixes dans l'année.",
        'geo_scope' => 'europe', 'sectors' => 'jeunesse,education,sport,culture', 'beneficiary' => 'association',
        'amount_min' => 5000, 'amount_max' => 250000, 'recurrence' => 'annuel',
        'apply_url' => 'https://info.erasmusplus.fr/',
        'source_ref' => 'eu-erasmus',
        'source_url' => 'https://info.erasmusplus.fr/',
    ],

    // ─────────────── ENVIRONNEMENT / TRANSITION ───────────────
    [
        'title' => "ADEME — Fonds Économie circulaire & transition",
        'funder_name' => "ADEME",
        'funder_type' => 'etat', 'program_code' => 'ADEME',
        'summary' => "Aides de l'ADEME pour les projets de transition écologique : réemploi, économie circulaire, sensibilisation, énergie. Ouvert aux associations comme aux TPE/PME porteuses de projets environnementaux.",
        'geo_scope' => 'national', 'sectors' => 'environnement', 'beneficiary' => 'association,tpe',
        'amount_min' => 5000, 'amount_max' => 100000, 'recurrence' => 'ponctuel',
        'apply_url' => 'https://agirpourlatransition.ademe.fr/',
        'source_ref' => 'etat-ademe',
        'source_url' => 'https://agirpourlatransition.ademe.fr/entreprises/',
    ],

    // ─────────────── COLLECTIVITÉS (génériques, à personnaliser) ───────────────
    [
        'title' => "Région — Aides à la vie associative",
        'funder_name' => "Conseil régional",
        'funder_type' => 'region', 'program_code' => 'REGION-ASSO',
        'summary' => "Chaque Région propose ses propres dispositifs (fonctionnement, emploi associatif, projets structurants, jeunesse). Consultez le portail des aides de votre Région — les critères et montants varient fortement.",
        'geo_scope' => 'region', 'sectors' => '', 'beneficiary' => 'association',
        'amount_min' => 1000, 'amount_max' => 50000, 'recurrence' => 'annuel',
        'apply_url' => 'https://www.demarches-simplifiees.fr/',
        'source_ref' => 'region-generic',
        'source_url' => 'https://www.associations.gouv.fr/les-subventions-des-collectivites.html',
    ],
    [
        'title' => "Département — Appels à projets & subventions",
        'funder_name' => "Conseil départemental",
        'funder_type' => 'departement', 'program_code' => 'DEPT-ASSO',
        'summary' => "Le Département soutient l'action sociale, la jeunesse, le sport et la culture de proximité. Dispositifs et calendriers propres à chaque département — vérifiez le site du Conseil départemental.",
        'geo_scope' => 'departement', 'sectors' => 'social,sport,culture,jeunesse', 'beneficiary' => 'association',
        'amount_min' => 500, 'amount_max' => 30000, 'recurrence' => 'annuel',
        'apply_url' => 'https://www.demarches-simplifiees.fr/',
        'source_ref' => 'dept-generic',
        'source_url' => 'https://www.associations.gouv.fr/les-subventions-des-collectivites.html',
    ],
    [
        'title' => "Commune / EPCI — Subvention de fonctionnement",
        'funder_name' => "Mairie / Intercommunalité",
        'funder_type' => 'commune', 'program_code' => 'COMMUNE-ASSO',
        'summary' => "La subvention communale reste la première ressource de la plupart des associations locales : fonctionnement, projet, mise à disposition de locaux ou de matériel. Rapprochez-vous du service Vie associative de votre mairie.",
        'geo_scope' => 'commune', 'sectors' => '', 'beneficiary' => 'association',
        'amount_min' => 200, 'amount_max' => 20000, 'recurrence' => 'annuel',
        'apply_url' => '',
        'source_ref' => 'commune-generic',
        'source_url' => 'https://www.associations.gouv.fr/les-subventions-des-collectivites.html',
    ],

    // ─────────────── TPE / PME ───────────────
    [
        'title' => "France Num — Transition numérique des TPE",
        'funder_name' => "État / France Num — Bpifrance",
        'funder_type' => 'etat', 'program_code' => 'FRANCENUM',
        'summary' => "Diagnostics, accompagnement et aides à la numérisation des TPE/PME (site, vente en ligne, outils de gestion). Réseau d'Activateurs France Num et dispositifs régionaux associés.",
        'geo_scope' => 'national', 'sectors' => 'numerique', 'beneficiary' => 'tpe',
        'amount_min' => 500, 'amount_max' => 10000, 'recurrence' => 'permanent',
        'apply_url' => 'https://www.francenum.gouv.fr/',
        'source_ref' => 'tpe-francenum',
        'source_url' => 'https://www.francenum.gouv.fr/financer-mon-projet',
    ],
    [
        'title' => "Bpifrance — Aides à la création & au développement",
        'funder_name' => "Bpifrance",
        'funder_type' => 'etat', 'program_code' => 'BPI',
        'summary' => "Prêts d'honneur, garanties, aides à l'innovation et au développement pour les TPE/PME. À combiner avec les aides régionales à la création d'entreprise.",
        'geo_scope' => 'national', 'sectors' => '', 'beneficiary' => 'tpe',
        'amount_min' => 2000, 'amount_max' => 100000, 'recurrence' => 'permanent',
        'apply_url' => 'https://www.bpifrance.fr/',
        'source_ref' => 'tpe-bpi',
        'source_url' => 'https://www.bpifrance.fr/nos-solutions',
    ],
    [
        'title' => "Région — Aides à la création / développement TPE",
        'funder_name' => "Conseil régional (développement économique)",
        'funder_type' => 'region', 'program_code' => 'REGION-TPE',
        'summary' => "La Région est le chef de file du développement économique : subventions et avances pour la création, l'investissement, l'embauche et la transition des TPE/PME. Dispositifs propres à chaque Région.",
        'geo_scope' => 'region', 'sectors' => '', 'beneficiary' => 'tpe',
        'amount_min' => 1000, 'amount_max' => 50000, 'recurrence' => 'annuel',
        'apply_url' => 'https://les-aides.fr/',
        'source_ref' => 'region-tpe',
        'source_url' => 'https://les-aides.fr/',
    ],
];

$sql = "INSERT INTO grant_catalog
    (title, funder_name, funder_type, program_code, summary, geo_scope, region_code, dept_code,
     sectors, beneficiary, amount_min, amount_max, req_qpv, req_interet_general, recurrence, opens_at, deadline_apply, next_expected,
     apply_url, source, source_ref, source_url, verified_at, is_verified, status, org_id)
    VALUES
    (:title, :funder_name, :funder_type, :program_code, :summary, :geo_scope, :region_code, :dept_code,
     :sectors, :beneficiary, :amount_min, :amount_max, :req_qpv, :req_ig, :recurrence, :opens_at, :deadline_apply, :next_expected,
     :apply_url, :source, :source_ref, :source_url, NOW(), 1, 'active', NULL)
    ON DUPLICATE KEY UPDATE
     title=VALUES(title), funder_name=VALUES(funder_name), funder_type=VALUES(funder_type),
     program_code=VALUES(program_code), summary=VALUES(summary), geo_scope=VALUES(geo_scope),
     sectors=VALUES(sectors), beneficiary=VALUES(beneficiary), amount_min=VALUES(amount_min),
     amount_max=VALUES(amount_max), req_qpv=VALUES(req_qpv), req_interet_general=VALUES(req_interet_general),
     recurrence=VALUES(recurrence), apply_url=VALUES(apply_url),
     source_url=VALUES(source_url), verified_at=NOW(), is_verified=1, status='active', updated_at=NOW()";

$stmt = $pdo->prepare($sql);
$ok = 0; $err = 0;
foreach ($catalog as $c) {
    try {
        $stmt->execute([
            ':title' => $c['title'],
            ':funder_name' => $c['funder_name'],
            ':funder_type' => $c['funder_type'],
            ':program_code' => $c['program_code'] ?? null,
            ':summary' => $c['summary'] ?? null,
            ':geo_scope' => $c['geo_scope'] ?? 'national',
            ':region_code' => $c['region_code'] ?? null,
            ':dept_code' => $c['dept_code'] ?? null,
            ':sectors' => $c['sectors'] ?? '',
            ':beneficiary' => $c['beneficiary'] ?? 'association',
            ':amount_min' => $c['amount_min'] ?? null,
            ':amount_max' => $c['amount_max'] ?? null,
            ':req_qpv' => !empty($c['req_qpv']) ? 1 : 0,
            ':req_ig' => !empty($c['req_ig']) ? 1 : 0,
            ':recurrence' => $c['recurrence'] ?? 'annuel',
            ':opens_at' => $c['opens_at'] ?? null,
            ':deadline_apply' => $c['deadline_apply'] ?? null,
            ':next_expected' => $c['next_expected'] ?? null,
            ':apply_url' => $c['apply_url'] ?? null,
            ':source' => 'curation_assokit',
            ':source_ref' => $c['source_ref'],
            ':source_url' => $c['source_url'] ?? null,
        ]);
        $ok++;
    } catch (Throwable $e) {
        $err++;
        echo "ERREUR sur {$c['source_ref']} : " . $e->getMessage() . "\n";
    }
}

echo "✅ Catalogue amorcé : $ok dispositif(s) upsert, $err erreur(s).\n";
echo "Total dispositifs globaux : ";
try {
    echo (int)$pdo->query("SELECT COUNT(*) FROM grant_catalog WHERE org_id IS NULL")->fetchColumn() . "\n";
} catch (Throwable $e) { echo "?\n"; }
