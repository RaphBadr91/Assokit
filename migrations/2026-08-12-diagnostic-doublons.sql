-- ============================================================
-- Diagnostic des doublons de numérotation (avant contraintes UNIQUE)
-- À exécuter dans phpMyAdmin. NE MODIFIE RIEN (SELECT seulement).
-- ============================================================

-- 1) Doublons sur (org_id, année, séquence) — la clef qui a échoué
SELECT org_id, invoice_year, invoice_sequence,
       COUNT(*)                                   AS nb,
       GROUP_CONCAT(id ORDER BY id)               AS ids,
       GROUP_CONCAT(invoice_number ORDER BY id)   AS numeros,
       GROUP_CONCAT(status ORDER BY id)           AS statuts,
       GROUP_CONCAT(DATE(created_at) ORDER BY id) AS dates,
       GROUP_CONCAT(amount_ttc_cents ORDER BY id) AS montants_ttc
  FROM asso_invoices
 GROUP BY org_id, invoice_year, invoice_sequence
HAVING nb > 1
 ORDER BY nb DESC, org_id, invoice_year, invoice_sequence;

-- 2) Doublons sur (org_id, invoice_number) — l'autre contrainte à venir
SELECT org_id, invoice_number,
       COUNT(*)                     AS nb,
       GROUP_CONCAT(id ORDER BY id) AS ids,
       GROUP_CONCAT(status ORDER BY id) AS statuts
  FROM asso_invoices
 GROUP BY org_id, invoice_number
HAVING nb > 1
 ORDER BY nb DESC;

-- 3) Combien de factures concernées sont de simples BROUILLONS (draft) ?
--    (plus faciles à corriger : renumérotation/suppression sans impact légal)
SELECT status, COUNT(*) AS nb
  FROM asso_invoices
 WHERE (org_id, invoice_year, invoice_sequence) IN (
        SELECT org_id, invoice_year, invoice_sequence
          FROM asso_invoices
         GROUP BY org_id, invoice_year, invoice_sequence
        HAVING COUNT(*) > 1)
 GROUP BY status;
