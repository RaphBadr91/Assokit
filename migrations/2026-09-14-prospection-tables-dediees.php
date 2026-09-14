<?php
/**
 * 2026-09-14-prospection-tables-dediees.php
 * ------------------------------------------------------------------
 * Répare une collision de noms que j'ai introduite.
 *
 * L'onglet Prospection des associations avait été écrit sur une table
 * `asso_prospects`. Ce nom était déjà pris : api/_app-prospect.php crée
 * une table `asso_prospects` pour la prospection du FONDATEUR (envoi de
 * courriels aux associations repérées dans l'annuaire), avec un tout
 * autre schéma — pas de org_id, une contrainte d'unicité sur l'e-mail.
 *
 * Conséquences sur une installation où la table du fondateur existait :
 *
 *   - `CREATE TABLE IF NOT EXISTS asso_prospects` n'a rien fait ;
 *   - les `ALTER TABLE ... ADD COLUMN` ont donc ajouté quatre colonnes
 *     à la table du fondateur : qr_id, consent_at, emailed, emailed_at ;
 *   - la page Prospection échouait sur « Unknown column 'p.org_id' ».
 *
 * Les tables de l'association s'appellent désormais `asso_prospection`
 * et `asso_prospection_events`. Aucun risque de collision : le fondateur
 * garde `asso_prospects`.
 *
 * Ce script gère les deux situations possibles :
 *
 *   a) `asso_prospects` porte org_id  → c'est MA table (installation où
 *      le fondateur n'avait jamais servi). Les données sont recopiées
 *      dans les nouvelles tables, puis les anciennes sont supprimées pour
 *      que le fondateur puisse recréer la sienne.
 *
 *   b) `asso_prospects` n'a pas org_id → c'est celle du FONDATEUR. On n'y
 *      touche pas, sinon pour retirer les quatre colonnes que mes
 *      migrations y avaient ajoutées par erreur. `source` est laissée :
 *      elle appartient au fondateur, mon ADD COLUMN IF NOT EXISTS l'a
 *      trouvée en place et ne l'a pas modifiée.
 *
 * Rejouable sans dommage.
 *
 * Usage :
 *     php migrations/2026-09-14-prospection-tables-dediees.php
 * ------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) { http_response_code(403); die('Forbidden'); }

require_once __DIR__ . '/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "PDO indisponible (config.php).\n"); exit(1); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e) {}

function dit(string $s): void { echo $s . "\n"; }

function table_existe(PDO $pdo, string $t): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$t]);
    return (int) $st->fetchColumn() > 0;
}

function colonne_existe(PDO $pdo, string $t, string $c): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$t, $c]);
    return (int) $st->fetchColumn() > 0;
}

// ------------------------------------------------------------------
// 1. Les tables de l'association, sous leur nom définitif
// ------------------------------------------------------------------
$pdo->exec("CREATE TABLE IF NOT EXISTS asso_prospection (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  org_id       INT NOT NULL,
  prenom       VARCHAR(120) NOT NULL DEFAULT '',
  nom          VARCHAR(120) NOT NULL DEFAULT '',
  telephone    VARCHAR(40)  NOT NULL DEFAULT '',
  email        VARCHAR(190) NOT NULL DEFAULT '',
  called       TINYINT(1)   NOT NULL DEFAULT 0,
  called_at    DATETIME NULL,
  emailed      TINYINT(1)   NOT NULL DEFAULT 0,
  emailed_at   DATETIME NULL,
  callback_at  DATETIME NULL,
  source       VARCHAR(16)  NOT NULL DEFAULT 'manuel',
  qr_id        INT NULL,
  consent_at   DATETIME NULL,
  notes        TEXT NULL,
  created_by   INT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by   INT NULL,
  updated_at   DATETIME NULL,
  deleted_at   DATETIME NULL,
  KEY idx_org_rappel   (org_id, callback_at),
  KEY idx_org_appele   (org_id, called),
  KEY idx_org_nom      (org_id, nom, prenom),
  KEY idx_org_supprime (org_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
dit('asso_prospection : en place');

$pdo->exec("CREATE TABLE IF NOT EXISTS asso_prospection_events (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  org_id      INT NOT NULL,
  prospect_id INT NOT NULL,
  user_id     INT NULL,
  type        VARCHAR(24) NOT NULL,
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prospect (prospect_id, id),
  KEY idx_org_date (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
dit('asso_prospection_events : en place');

// ------------------------------------------------------------------
// 2. Que faire de l'ancienne asso_prospects ?
// ------------------------------------------------------------------
if (!table_existe($pdo, 'asso_prospects')) {
    dit('asso_prospects : absente, rien à reprendre');
} elseif (colonne_existe($pdo, 'asso_prospects', 'org_id')) {
    // Cas (a) : c'était bien la table de l'association.
    $n = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospects")->fetchColumn();
    dit("asso_prospects : c'est la table de l'association ($n fiches), reprise en cours");

    // Les colonnes communes seulement : le schéma a pu bouger entre-temps.
    $cols = ['org_id','prenom','nom','telephone','email','called','called_at',
             'callback_at','notes','created_by','created_at','updated_by',
             'updated_at','deleted_at','emailed','emailed_at','source','qr_id','consent_at'];
    $reprises = array_values(array_filter($cols, fn($c) => colonne_existe($pdo, 'asso_prospects', $c)));
    $liste = implode(', ', $reprises);
    $pdo->exec("INSERT INTO asso_prospection (id, $liste)
                SELECT id, $liste FROM asso_prospects
                ON DUPLICATE KEY UPDATE asso_prospection.id = asso_prospection.id");
    dit('  fiches reprises : ' . count($reprises) . ' colonnes');

    if (table_existe($pdo, 'asso_prospect_events')) {
        $pdo->exec("INSERT INTO asso_prospection_events (id, org_id, prospect_id, user_id, type, detail, created_at)
                    SELECT id, org_id, prospect_id, user_id, type, detail, created_at FROM asso_prospect_events
                    ON DUPLICATE KEY UPDATE asso_prospection_events.id = asso_prospection_events.id");
        dit('  historique repris');
    }

    // Libère le nom : le fondateur recréera sa propre table au besoin.
    $pdo->exec("DROP TABLE IF EXISTS asso_prospect_events");
    $pdo->exec("DROP TABLE asso_prospects");
    dit('  ancienne table supprimée, le nom est rendu à la prospection du fondateur');
} else {
    // Cas (b) : table du fondateur, polluée par mes migrations.
    dit("asso_prospects : c'est la table du fondateur — laissée en place");
    $intruses = ['qr_id', 'consent_at', 'emailed', 'emailed_at'];
    $retirees = [];
    foreach ($intruses as $c) {
        if (colonne_existe($pdo, 'asso_prospects', $c)) {
            $pdo->exec("ALTER TABLE asso_prospects DROP COLUMN `$c`");
            $retirees[] = $c;
        }
    }
    dit($retirees
        ? '  colonnes ajoutées par erreur, retirées : ' . implode(', ', $retirees)
        : '  aucune colonne parasite');

    // asso_prospect_events n'a jamais servi à personne d'autre : c'était la
    // mienne, restée vide puisque la page n'a jamais pu créer de fiche.
    if (table_existe($pdo, 'asso_prospect_events')) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospect_events")->fetchColumn();
        if ($n === 0) {
            $pdo->exec("DROP TABLE asso_prospect_events");
            dit('  asso_prospect_events (vide) supprimée');
        } else {
            $pdo->exec("INSERT INTO asso_prospection_events (id, org_id, prospect_id, user_id, type, detail, created_at)
                        SELECT id, org_id, prospect_id, user_id, type, detail, created_at FROM asso_prospect_events
                        ON DUPLICATE KEY UPDATE asso_prospection_events.id = asso_prospection_events.id");
            $pdo->exec("DROP TABLE asso_prospect_events");
            dit("  asso_prospect_events : $n lignes reprises puis table supprimée");
        }
    }
}

// ------------------------------------------------------------------
// 3. Vérification
// ------------------------------------------------------------------
dit('');
$ko = 0;
try {
    $pdo->query("SELECT COUNT(*) AS total, SUM(called = 1) AS appeles, SUM(emailed = 1) AS emails,
                 SUM(callback_at IS NOT NULL) AS rappels
                 FROM asso_prospection WHERE org_id = 0 AND deleted_at IS NULL")->fetch();
    dit('La requête de la page Prospection passe.');
} catch (Throwable $e) {
    $ko++;
    dit('ÉCHEC : ' . $e->getMessage());
}

if (table_existe($pdo, 'asso_prospects')) {
    foreach (['qr_id', 'consent_at', 'emailed', 'emailed_at'] as $c) {
        if (colonne_existe($pdo, 'asso_prospects', $c) && !colonne_existe($pdo, 'asso_prospects', 'org_id')) {
            $ko++;
            dit("ÉCHEC : la colonne $c traîne encore sur la table du fondateur.");
        }
    }
}

dit($ko ? "\n$ko problème(s)." : "\nTerminé. Rechargez /prospection.");
exit($ko ? 1 : 0);
