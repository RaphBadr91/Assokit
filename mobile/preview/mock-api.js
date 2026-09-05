/**
 * Données de démonstration pour la prévisualisation web.
 * Reprend la forme exacte des réponses de /api/app-*.php, sans jamais toucher
 * au serveur : la prévisualisation est hors ligne et ne contient aucune donnée
 * réelle d'association.
 */

const TODAY = new Date();
const d = (offset) => {
  const x = new Date(TODAY);
  x.setDate(x.getDate() + offset);
  return x;
};
const fr = (x) => String(x.getDate()).padStart(2, '0') + '/' + String(x.getMonth() + 1).padStart(2, '0') + '/' + x.getFullYear();
const iso = (x) => x.toISOString().slice(0, 10);
const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const dayLabel = (x) => DAYS[x.getDay()] + ' ' + x.getDate() + ' ' + MONTHS[x.getMonth()];

const DASHBOARD = {
  ok: true,
  profile: 'asso',
  role: 'admin',
  can_create_projects: true,
  is_founder: false,
  is_super_admin: false,
  notif_unread: 3,
  msg_unread: 2,
  support_unread: 0,
  first_name: 'Raphaël',
  org_name: 'Latitude 91',
  org_initials: 'LA',
  org_logo: null,
  head_line: '3 échéances cette semaine · 2 factures à relancer',
  today: [
    { kind: 'event', id: 1, time: '09:30', title: 'Réunion du bureau', sub: 'Salle des fêtes', color: '#059669' },
    { kind: 'invoice', id: 18, time: '—', title: 'Facture FA-2026-018', sub: "Échéance aujourd'hui · 840 €", color: '#2563EB' },
    { kind: 'grant', id: 4, time: '—', title: 'Fonds de développement associatif', sub: 'À déposer avant le ' + fr(d(21)), color: '#B45309' },
  ],
  kpis: {
    projets_actifs: 7,
    membres: 248,
    membres_nouveaux: 12,
    evenements: 5,
    budget_used: 18420,
    budget_planned: 24000,
    clients: 34,
    devis_encours: 3,
    factures: 41,
    ca_paid: 18420,
    impayes: 2340,
  },
};

const PROJECTS = {
  ok: true,
  projects: [
    { id: 1, name: 'Festival de quartier 2026', folder: 'Culture', status: 'active', progress: 62, participants: 14 },
    { id: 2, name: 'Ateliers numériques seniors', folder: 'Solidarité', status: 'warning', progress: 28, participants: 6 },
    { id: 3, name: 'Rénovation du local', folder: 'Vie associative', status: 'active', progress: 81, participants: 4 },
    { id: 4, name: 'Tournoi inter-associations', folder: 'Sport', status: 'done', progress: 100, participants: 22 },
  ],
};

/* Fiches projet, une par carte de la liste : ouvrir un projet différent doit
   donner un contenu différent, sinon l'aperçu ne montre rien de la variété
   réelle (avancement, budget dépassé, checklist, équipe). */
const PROJECT_DETAILS = {
  1: {
    ok: true,
    project: {
      id: 1, name: 'Festival de quartier 2026', folder: 'Culture', status: 'active',
      progress: 62, budget_used: 7400, budget_planned: 12000, budget_pct: 62,
      steps_done: 3, steps_total: 6, location: 'Parc des Loges, Évry',
      objective: 'Réunir 1 200 habitants sur deux jours de programmation gratuite.',
      description: "Trois scènes, un village associatif et une restauration solidaire.\n- Programmation musicale bouclée\n- Sécurité et arrêté municipal en cours\n- Bénévoles : 40 sur 60 recherchés",
      referent: { name: 'Haoua Ali', initials: 'HA' },
    },
    steps: [
      { id: 1, title: 'Déposer la demande d’occupation du domaine public', description: 'Dossier mairie', done: true },
      { id: 2, title: 'Signer les contrats des trois groupes', description: '', done: true },
      { id: 3, title: 'Commander la scène et la sono', description: 'Devis retenu : 3 200 €', done: true },
      { id: 4, title: 'Recruter 20 bénévoles supplémentaires', description: '', done: false },
      { id: 5, title: 'Boucler le plan de sécurité', description: 'À valider avec la préfecture', done: false },
      { id: 6, title: 'Lancer la communication', description: '', done: false },
    ],
    members: [
      { id: 1, name: 'Haoua Ali', initials: 'HA', role: 'admin' },
      { id: 2, name: 'Marc Dubois', initials: 'MD', role: 'coordinator' },
      { id: 4, name: 'Sofia Berger', initials: 'SB', role: 'member' },
    ],
  },
  2: {
    ok: true,
    project: {
      id: 2, name: 'Ateliers numériques seniors', folder: 'Solidarité', status: 'warning',
      progress: 28, budget_used: 3100, budget_planned: 2800, budget_pct: 100,
      steps_done: 1, steps_total: 5, location: 'Maison de quartier des Épinettes',
      objective: 'Accompagner 45 seniors vers l’autonomie numérique en 10 séances.',
      description: "Le budget est dépassé de 300 € : le prestataire a facturé deux séances supplémentaires.\n- Salle réservée jusqu'en juin\n- 28 inscrits sur 45",
      referent: { name: 'Marc Dubois', initials: 'MD' },
    },
    steps: [
      { id: 7, title: 'Réserver la salle informatique', description: '', done: true },
      { id: 8, title: 'Recruter deux animateurs', description: 'Un seul trouvé', done: false },
      { id: 9, title: 'Commander 10 tablettes', description: '', done: false },
      { id: 10, title: 'Relancer le CCAS pour le cofinancement', description: 'Budget dépassé', done: false },
      { id: 11, title: 'Évaluer la première session', description: '', done: false },
    ],
    members: [{ id: 2, name: 'Marc Dubois', initials: 'MD', role: 'coordinator' }],
  },
  3: {
    ok: true,
    project: {
      id: 3, name: 'Rénovation du local', folder: 'Vie associative', status: 'active',
      progress: 81, budget_used: 9800, budget_planned: 11500, budget_pct: 85,
      steps_done: 4, steps_total: 5, location: '12 rue des Mares, Évry',
      objective: 'Mettre le local aux normes d’accessibilité avant l’assemblée générale.',
      description: 'Peinture et sol terminés. Reste la rampe d’accès, livrée la semaine prochaine.',
      referent: { name: 'Sofia Berger', initials: 'SB' },
    },
    steps: [
      { id: 12, title: 'Obtenir trois devis', description: '', done: true },
      { id: 13, title: 'Voter les travaux en bureau', description: '', done: true },
      { id: 14, title: 'Refaire le sol et les peintures', description: '', done: true },
      { id: 15, title: 'Remplacer l’éclairage', description: '', done: true },
      { id: 16, title: 'Poser la rampe d’accès', description: 'Livraison annoncée lundi', done: false },
    ],
    members: [
      { id: 4, name: 'Sofia Berger', initials: 'SB', role: 'member' },
      { id: 1, name: 'Haoua Ali', initials: 'HA', role: 'admin' },
    ],
  },
  4: {
    ok: true,
    project: {
      id: 4, name: 'Tournoi inter-associations', folder: 'Sport', status: 'done',
      progress: 100, budget_used: 1850, budget_planned: 2000, budget_pct: 93,
      steps_done: 4, steps_total: 4, location: 'Gymnase municipal',
      objective: 'Faire se rencontrer huit associations du territoire autour d’un tournoi.',
      description: 'Édition bouclée : 22 équipes, 180 participants. Bilan envoyé au service des sports.',
      referent: { name: 'Haoua Ali', initials: 'HA' },
    },
    steps: [
      { id: 17, title: 'Réserver le gymnase', description: '', done: true },
      { id: 18, title: 'Inscrire les associations', description: '', done: true },
      { id: 19, title: 'Organiser la buvette', description: '', done: true },
      { id: 20, title: 'Envoyer le bilan à la mairie', description: '', done: true },
    ],
    members: [
      { id: 1, name: 'Haoua Ali', initials: 'HA', role: 'admin' },
      { id: 3, name: 'Léa Fontaine', initials: 'LF', role: 'member' },
    ],
  },
};

const MEMBER_DETAILS = {
  1: {
    ok: true, admin: true,
    member: { id: 1, name: 'Haoua Ali', initials: 'HA', color: '#059669', email: 'haoua.ali@exemple.fr', phone: '06 12 34 56 78', city: 'Évry', role_label: 'Administratrice', up_to_date: true, adhesion_since: fr(d(-400)), adhesion_until: fr(d(320)), last_login: fr(d(-1)) },
    projects: [
      { id: 1, name: 'Festival de quartier 2026', folder: 'Culture', status: 'active', progress: 62, role: 'referent' },
      { id: 4, name: 'Tournoi inter-associations', folder: 'Sport', status: 'done', progress: 100, role: 'referent' },
    ],
  },
  2: {
    ok: true, admin: true,
    member: { id: 2, name: 'Marc Dubois', initials: 'MD', color: '#2563EB', email: 'marc.dubois@exemple.fr', phone: '06 98 76 54 32', city: 'Corbeil', role_label: 'Coordinateur', up_to_date: false, adhesion_since: fr(d(-720)), adhesion_until: fr(d(-30)), last_login: fr(d(-9)) },
    projects: [{ id: 2, name: 'Ateliers numériques seniors', folder: 'Solidarité', status: 'warning', progress: 28, role: 'referent' }],
  },
  3: {
    ok: true, admin: true,
    member: { id: 3, name: 'Léa Fontaine', initials: 'LF', color: '#7C3AED', email: 'lea.fontaine@exemple.fr', phone: '', city: 'Évry', role_label: 'Membre', up_to_date: false, adhesion_since: fr(d(-95)), adhesion_until: fr(d(270)), last_login: fr(d(-21)) },
    projects: [{ id: 4, name: 'Tournoi inter-associations', folder: 'Sport', status: 'done', progress: 100, role: 'member' }],
  },
  4: {
    ok: true, admin: true,
    member: { id: 4, name: 'Sofia Berger', initials: 'SB', color: '#B45309', email: 'sofia.berger@exemple.fr', phone: '07 45 12 89 03', city: 'Ris-Orangis', role_label: 'Membre', up_to_date: true, adhesion_since: fr(d(-210)), adhesion_until: fr(d(155)), last_login: fr(d(-3)) },
    projects: [{ id: 3, name: 'Rénovation du local', folder: 'Vie associative', status: 'active', progress: 81, role: 'referent' }],
  },
};

const MEMBERS = {
  ok: true,
  members: [
    { id: 1, name: 'Haoua Ali', email: 'haoua.ali@exemple.fr', city: 'Évry', initials: 'HA', color: '#059669', role: 'admin', role_label: 'Administratrice', up_to_date: true, admin: true, coordinator: false, referent: false },
    { id: 2, name: 'Marc Dubois', email: 'marc.dubois@exemple.fr', city: 'Corbeil', initials: 'MD', color: '#2563EB', role: 'coordinator', role_label: 'Coordinateur', up_to_date: false, admin: false, coordinator: true, referent: true },
    { id: 3, name: 'Léa Fontaine', email: 'lea.fontaine@exemple.fr', city: 'Évry', initials: 'LF', color: '#7C3AED', role: 'member', role_label: 'Membre', up_to_date: false, admin: false, coordinator: false, referent: false },
    { id: 4, name: 'Sofia Berger', email: 'sofia.berger@exemple.fr', city: 'Ris-Orangis', initials: 'SB', color: '#B45309', role: 'member', role_label: 'Membre', up_to_date: true, admin: false, coordinator: false, referent: false },
  ],
};

const INVOICES = {
  ok: true,
  allowed: true,
  invoices: [
    { id: 18, number: 'FA-2026-018', client: 'Mairie d\'Évry-Courcouronnes', amount: 840, date: fr(d(-12)), status: 'pending', status_kind: 'wait', status_label: 'En attente', kind: 'wait' },
    { id: 17, number: 'FA-2026-017', client: 'Département de l\'Essonne', amount: 2400, date: fr(d(-28)), status: 'paid', status_kind: 'done', status_label: 'Payée', kind: 'done' },
    { id: 16, number: 'FA-2026-016', client: 'Boulangerie Petit', amount: 320, date: fr(d(-46)), status: 'overdue', status_kind: 'late', status_label: 'En retard', kind: 'late' },
    { id: 15, number: 'FA-2026-015', client: 'Région Île-de-France', amount: 5600, date: fr(d(-58)), status: 'paid', status_kind: 'done', status_label: 'Payée', kind: 'done' },
  ],
};

const INVOICE_DETAIL = {
  ok: true,
  invoice: {
    id: 18,
    number: 'FA-2026-018',
    client: "Mairie d'Évry-Courcouronnes",
    client_email: 'compta@exemple.fr',
    amount_ht: 700,
    amount_vat: 140,
    amount_ttc: 840,
    issued_at: fr(d(-12)),
    due_at: fr(TODAY),
    paid_at: '',
    status: 'pending',
    status_kind: 'wait',
    status_label: 'En attente',
    is_quote: false,
    public_uuid: 'demo-preview',
    description: 'Animation du forum des associations, prestation du samedi.',
  },
  lines: [
    { label: 'Animation forum des associations', qty: 1, unit: 450, vat: 20, total: 450 },
    { label: 'Location de matériel son', qty: 2, unit: 125, vat: 20, total: 250 },
  ],
};

const EVENTS = {
  ok: true,
  events: [
    { id: 1, title: 'Réunion du bureau', location: 'Salle des fêtes', project: '6 participants', color: '#059669', time: '09:30', day_key: iso(TODAY), day_label: "Aujourd'hui · " + dayLabel(TODAY), all_day: false },
    { id: 2, title: 'Atelier citoyenneté', location: 'Maison de quartier', project: '18 inscrits', color: '#2563EB', time: '14:00', day_key: iso(TODAY), day_label: "Aujourd'hui · " + dayLabel(TODAY), all_day: false },
    { id: 3, title: 'Commission subventions', location: 'Visio', project: '4 participants', color: '#7C3AED', time: '10:00', day_key: iso(d(1)), day_label: 'Demain · ' + dayLabel(d(1)), all_day: false },
    { id: 4, title: 'Assemblée générale', location: 'Gymnase municipal', project: '120 convoqués', color: '#B45309', time: '18:30', day_key: iso(d(2)), day_label: dayLabel(d(2)), all_day: false },
    { id: 5, title: 'Pot de rentrée', location: 'Gymnase municipal', project: 'Ouvert à tous', color: '#059669', time: '20:00', day_key: iso(d(2)), day_label: dayLabel(d(2)), all_day: false },
  ],
};

const COTISATIONS = {
  ok: true,
  campaigns: [
    { id: 1, name: 'Adhésion 2026', year: 2026, active: true, total: 13830, paid: 142, pending: 18, payers: 160, nb: 160 },
    { id: 2, name: 'Adhésion 2025', year: 2025, active: false, total: 12100, paid: 151, pending: 0, payers: 151, nb: 151 },
  ],
};

const COTISATION_DETAIL = {
  ok: true,
  can_manage: true,
  is_admin: true,
  campaign: { id: 1, name: 'Adhésion 2026', year: 2026, active: true, closes_at: fr(d(90)), description: 'Adhésion annuelle ouverte à tous les habitants du quartier.' },
  stats: { amount_paid: 12480, amount_pending: 1350, count_paid: 142, count_pending: 18 },
  tiers: [
    { id: 1, name: 'Adulte', amount: 25 },
    { id: 2, name: 'Étudiant', amount: 12.5 },
    { id: 3, name: 'Famille', amount: 40 },
  ],
  payments: [
    { id: 1, name: 'Haoua Ali', amount: 25, method: 'transfer', method_label: 'Virement', tier: 'Adulte', paid_at: fr(d(-3)), created_at: fr(d(-3)), status: 'paid', status_label: 'Payé', status_bg: '#D1FAE5', status_color: '#065F46' },
    { id: 2, name: 'Marc Dubois', amount: 25, method: 'check', method_label: 'Chèque', tier: 'Adulte', paid_at: '', created_at: fr(d(-2)), status: 'pending', status_label: 'En attente', status_bg: '#FEF3C7', status_color: '#92400E' },
    { id: 3, name: 'Léa Fontaine', amount: 12.5, method: 'cash', method_label: 'Espèces', tier: 'Étudiant', paid_at: '', created_at: fr(d(-1)), status: 'pending', status_label: 'En attente', status_bg: '#FEF3C7', status_color: '#92400E' },
    { id: 4, name: 'Sofia Berger', amount: 40, method: 'transfer', method_label: 'Virement', tier: 'Famille', paid_at: fr(d(-6)), created_at: fr(d(-6)), status: 'paid', status_label: 'Payé', status_bg: '#D1FAE5', status_color: '#065F46' },
  ],
};

const GRANTS = {
  ok: true,
  grants: [
    { id: 4, name: 'Fonds de développement associatif', funder: 'Région Île-de-France', funder_type: 'Région', requested: 15000, granted: null, deadline: fr(d(21)), status: 'draft', status_kind: 'draft', status_label: 'Brouillon', project: 'Festival de quartier 2026', archived: false },
    { id: 5, name: 'Appel à projets Jeunesse', funder: "Département de l'Essonne", funder_type: 'Département', requested: 8000, granted: 6500, deadline: fr(d(-40)), status: 'granted', status_kind: 'done', status_label: 'Accordé', project: 'Ateliers numériques seniors', archived: false },
  ],
  stats: { nb: 2, requested: 23000, granted: 6500, pending: 1 },
};

const GRANT_DETAIL = {
  ok: true,
  can_manage: true,
  is_admin: true,
  grant: {
    id: 4,
    name: 'Fonds de développement associatif',
    funder: 'Région Île-de-France',
    funder_type: 'Région',
    requested: 15000,
    granted: null,
    deadline: fr(d(21)),
    submitted_at: '',
    decision_at: '',
    deadline_report: fr(d(300)),
    project: 'Festival de quartier 2026',
    cerfa: '12156*05',
    reference: 'FDVA-2026-0871',
    last_relance: '',
    status: 'draft',
    status_kind: 'draft',
    status_label: 'Brouillon',
    archived: false,
    contact_name: 'Service vie associative',
    contact_email: 'subventions@exemple.fr',
    contact_phone: '01 23 45 67 89',
    description: 'Financement du festival de quartier : scène, sécurité et communication.',
  },
  steps: [
    { id: 1, title: 'Constituer le dossier CERFA', done: true, done_at: fr(d(-14)) },
    { id: 2, title: 'Faire signer le président', done: true, done_at: fr(d(-9)) },
    { id: 3, title: 'Joindre le budget prévisionnel', done: false, done_at: '' },
    { id: 4, title: 'Déposer sur la plateforme', done: false, done_at: '' },
  ],
  activity: [
    { id: 1, title: 'Dossier créé', who: 'Haoua Ali', when: fr(d(-16)) },
    { id: 2, title: 'Budget prévisionnel demandé au trésorier', who: 'Marc Dubois', when: fr(d(-8)) },
  ],
};

const EVENT_DETAILS = {
  1: { ok: true, event: { id: 1, title: 'Réunion du bureau', when: dayLabel(TODAY) + ' à 09:30', location: 'Salle des fêtes', project: '', type_label: 'Réunion', color: '#059669', all_day: false, description: 'Ordre du jour : point trésorerie, préparation du festival, calendrier des subventions.' } },
  2: { ok: true, event: { id: 2, title: 'Atelier citoyenneté', when: dayLabel(TODAY) + ' à 14:00', location: 'Maison de quartier', project: 'Ateliers numériques seniors', type_label: 'Atelier', color: '#2563EB', all_day: false, description: '18 inscrits. Prévoir les tablettes et deux animateurs.' } },
  3: { ok: true, event: { id: 3, title: 'Commission subventions', when: dayLabel(d(1)) + ' à 10:00', location: 'Visio', project: '', type_label: 'Réunion', color: '#7C3AED', all_day: false, description: 'Passage en revue du dossier Région avant dépôt.' } },
  4: { ok: true, event: { id: 4, title: 'Assemblée générale', when: dayLabel(d(2)) + ' à 18:30', location: 'Gymnase municipal', project: '', type_label: 'Assemblée', color: '#B45309', all_day: false, description: '120 convoqués. Quorum requis : 61 présents ou représentés.' } },
  5: { ok: true, event: { id: 5, title: 'Pot de rentrée', when: dayLabel(d(2)) + ' à 20:00', location: 'Gymnase municipal', project: '', type_label: 'Convivialité', color: '#059669', all_day: false, description: 'Ouvert à tous, sans inscription.' } },
};

const NOTIFICATIONS = {
  ok: true,
  unread: 3,
  items: [
    { id: 1, title: 'Marc Dubois vous a mentionné', body: '« On cale la commission subventions mercredi ? »', icon: 'mention', ago: 'il y a 2 h', read: false },
    { id: 2, title: 'Étape assignée', body: 'Boucler le plan de sécurité — Festival de quartier 2026', icon: 'step_assigned', ago: 'hier', read: false },
    { id: 3, title: 'Nouveau membre', body: 'Sofia Berger a rejoint l’association', icon: 'team_added', ago: 'il y a 3 j', read: false },
    { id: 4, title: 'Facture payée', body: 'FA-2026-017 · 2 400 € encaissés', icon: 'message', ago: 'la semaine dernière', read: true },
  ],
};

const CHANNELS = {
  ok: true,
  channels: [
    { id: 1, name: 'Général', slug: 'general', type: 'public', color: '#059669', count: 128, unread: 2 },
    { id: 2, name: 'Bureau', slug: 'bureau', type: 'private', color: '#2563EB', count: 46, unread: 0 },
    { id: 3, name: 'Annonces', slug: 'annonces', type: 'announce', color: '#B45309', count: 12, unread: 0 },
  ],
};

const FOLDERS = {
  ok: true,
  folders: [
    { id: 1, name: 'Culture', color: '#7C3AED' },
    { id: 2, name: 'Solidarité', color: '#2563EB' },
    { id: 3, name: 'Vie associative', color: '#059669' },
    { id: 4, name: 'Sport', color: '#B45309' },
  ],
};

/* Table de correspondance chemin → réponse. Un chemin absent renvoie
   { ok: false } : l'écran affiche alors son état d'erreur, ce qui est le
   comportement réel et non un plantage. */
const ROUTES = {
  '/api/app-dashboard.php': DASHBOARD,
  '/api/app-csrf.php': { ok: true, csrf: 'apercu-hors-ligne' },
  '/api/app-projects.php': PROJECTS,
  '/api/app-members.php': MEMBERS,
  '/api/app-clients.php': { ok: true, clients: [] },
  '/api/app-invoices.php': INVOICES,
  '/api/app-invoice.php': INVOICE_DETAIL,
  '/api/app-events.php': EVENTS,
  '/api/app-cotisations.php': COTISATIONS,
  '/api/app-cotisation.php': COTISATION_DETAIL,
  '/api/app-grants.php': GRANTS,
  '/api/app-grant.php': GRANT_DETAIL,
  '/api/app-notifications.php': NOTIFICATIONS,
  '/api/app-channels.php': CHANNELS,
  '/api/app-folders.php': FOLDERS,
};

/* Fiches dépendantes de l'identifiant : ouvrir deux projets différents doit
   donner deux contenus différents. */
const ROUTES_PAR_ID = {
  '/api/app-project.php': PROJECT_DETAILS,
  '/api/app-member.php': MEMBER_DETAILS,
  '/api/app-event.php': EVENT_DETAILS,
};

function lireId(url) {
  const m = /[?&]id=(\d+)/.exec(String(url));
  return m ? m[1] : null;
}

export function mockResponse(url, isPost) {
  const path = String(url).split('?')[0];
  if (isPost) {
    // Les écritures réussissent, sans rien persister : l'aperçu est hors ligne.
    return { ok: true, id: Math.floor(Math.random() * 900) + 100, message: 'Aperçu hors ligne : rien n’est enregistré.' };
  }
  const parId = ROUTES_PAR_ID[path];
  if (parId) {
    const id = lireId(url);
    // Un identifiant hors du jeu de démonstration retombe sur la première fiche,
    // plutôt que sur un écran d'erreur qui ne dirait rien du design.
    return parId[id] || parId[Object.keys(parId)[0]];
  }
  return ROUTES[path] || { ok: false, error: 'preview' };
}
