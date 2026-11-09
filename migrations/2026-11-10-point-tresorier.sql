-- ============================================================
-- Migration : point du lundi du trésorier (formule Équipe, cron-point-tresorier.php)
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS) : relançable sans erreur ni doublon.
-- Lancement : php migrations/run.php 2026-11-10-point-tresorier.sql (ou --all)
--
-- Le point du lundi annonce les NOUVELLES anomalies de la semaine : il garde, par association,
-- les empreintes des anomalies déjà signalées (ak_anom_hash, anomalies-engine.php) et la date
-- du dernier envoi. Aucune donnée personnelle : des empreintes sha1 et des dates.
-- Sans cette table, le cron fonctionne quand même : il présente alors les anomalies « à traiter ».
-- La désinscription des destinataires est dans treasurer_digest_optout
-- (migrations/2026-11-09-grille-tarifaire.sql).
-- ============================================================
CREATE TABLE IF NOT EXISTS treasurer_digest_state (
  org_id         INT NOT NULL,
  anomaly_hashes MEDIUMTEXT NULL,
  last_sent_at   DATETIME NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
