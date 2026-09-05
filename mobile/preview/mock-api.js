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
    { id: 4, name: 'Fonds de développement associatif', funder: 'Région Île-de-France', funder_type: 'region', requested: 15000, granted: null, deadline: fr(d(21)), status: 'draft', status_kind: 'draft', status_label: 'Brouillon', project: 'Festival de quartier 2026', archived: false },
    { id: 5, name: 'Appel à projets Jeunesse', funder: "Département de l'Essonne", funder_type: 'departement', requested: 8000, granted: 6500, deadline: fr(d(-40)), status: 'granted', status_kind: 'done', status_label: 'Accordé', project: 'Ateliers numériques seniors', archived: false },
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
};

export function mockResponse(url, isPost) {
  const path = String(url).split('?')[0];
  if (isPost) {
    // Les écritures réussissent, sans rien persister : l'aperçu est hors ligne.
    return { ok: true, id: Math.floor(Math.random() * 900) + 100, message: 'Aperçu hors ligne : rien n’est enregistré.' };
  }
  return ROUTES[path] || { ok: false, error: 'preview' };
}
