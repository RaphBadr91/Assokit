-- ============================================================
-- Migration : Cartes de visite numériques (QR codes)
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS / INSERT IGNORE).
--
--   qr_cards : une ligne par QR code imprimé. Le `slug` est l'adresse
--   gravée dans le code (assokit.fr/carte/<slug>) — il ne change JAMAIS
--   une fois le QR imprimé, c'est toute la raison de cette table : les
--   coordonnées se corrigent ici sans réimprimer.
--
--   Douze emplacements sont créés d'avance et vides. Chacun a déjà son
--   QR pré-généré dans assets/brand/qr-cartes/ : ouvrir un nouvel
--   emplacement ne demande donc aucune génération à l'exécution.
-- ============================================================

CREATE TABLE IF NOT EXISTS qr_cards (
  slug         VARCHAR(32)  NOT NULL,        -- adresse gravée dans le QR
  is_active    TINYINT(1)   NOT NULL DEFAULT 0,
  prenom       VARCHAR(120) NOT NULL DEFAULT '',
  nom          VARCHAR(120) NOT NULL DEFAULT '',
  fonction     VARCHAR(160) NOT NULL DEFAULT '',
  societe      VARCHAR(160) NOT NULL DEFAULT 'Assokit',
  tel          VARCHAR(40)  NOT NULL DEFAULT '',
  tel_fixe     VARCHAR(40)  NOT NULL DEFAULT '',
  email        VARCHAR(190) NOT NULL DEFAULT '',
  site         VARCHAR(255) NOT NULL DEFAULT 'https://assokit.fr',
  adr_rue      VARCHAR(255) NOT NULL DEFAULT '',
  adr_cp       VARCHAR(20)  NOT NULL DEFAULT '',
  adr_ville    VARCHAR(120) NOT NULL DEFAULT '',
  adr_pays     VARCHAR(80)  NOT NULL DEFAULT 'France',
  linkedin     VARCHAR(255) NOT NULL DEFAULT '',
  note         VARCHAR(500) NOT NULL DEFAULT '',
  scan_count   INT UNSIGNED NOT NULL DEFAULT 0,   -- combien de fois la fiche a été ouverte
  last_scan_at DATETIME NULL,
  updated_by   INT NULL,
  updated_at   DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Douze emplacements. INSERT IGNORE : relancer la migration ne réécrit
-- aucune fiche déjà remplie.
INSERT IGNORE INTO qr_cards (slug) VALUES
  ('1'), ('2'), ('3'), ('4'), ('5'), ('6'),
  ('7'), ('8'), ('9'), ('10'), ('11'), ('12');
