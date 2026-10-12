-- ============================================================
-- Assemblées : réponse à la convocation distincte de l'émargement
-- ============================================================
-- Avant la séance, un convoqué répond « je viendrai » / « je m'excuse »
-- (rsvp = 'yes' / 'excused'). La présence (status = 'present',
-- signed_at) ne s'enregistre plus que pendant la séance : c'est elle
-- seule qui compte pour le quorum et le procès-verbal.
--
-- Reprise de l'existant : dans une AG pas encore ouverte, une signature
-- « je serai présent·e » faite à l'avance devient une réponse « oui »
-- (le convoqué émargera le jour de la séance avec le même lien).
--
-- Idempotent, sans perte (la signature anticipée est conservée dans
-- signature_data jusqu'à l'émargement). Le code fonctionne aussi sans
-- cette migration : il ne retient alors que les excuses.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L04-agenda.sql
-- ============================================================

ALTER TABLE assembly_attendees
  ADD COLUMN IF NOT EXISTS rsvp VARCHAR(10) NULL,
  ADD COLUMN IF NOT EXISTS rsvp_at DATETIME NULL;

UPDATE assembly_attendees aa
  JOIN assemblies a ON a.id = aa.assembly_id
   SET aa.rsvp = 'yes', aa.rsvp_at = COALESCE(aa.rsvp_at, aa.signed_at), aa.status = 'invited', aa.signed_at = NULL
 WHERE aa.status = 'present'
   AND (a.status IS NULL OR a.status IN ('draft', 'sent'));

-- ============================================================
-- Assemblées : mandataire d'un pouvoir (procuration)
-- ============================================================
-- Un convoqué « Pouvoir » (status = 'proxy') est représenté par un autre
-- participant de la même AG (proxy_attendee_id), qui vote aussi pour lui.
-- Sans cette colonne, le statut « Pouvoir » reste utilisable : il compte
-- pour le quorum, mais sans mandataire il ne vote pas.
-- ============================================================

ALTER TABLE assembly_attendees
  ADD COLUMN IF NOT EXISTS proxy_attendee_id INT UNSIGNED NULL,
  ADD INDEX IF NOT EXISTS idx_aa_proxy (assembly_id, proxy_attendee_id);
