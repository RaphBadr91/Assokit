-- ------------------------------------------------------------------
-- Grille tarifaire de novembre 2026 (chantier site) : texte du blog.
-- L'article « Créer une association loi 1901 » (seed-blog-articles.php) annonçait
-- « à partir de 13 €/mois pour le plan Démarrage avec tarif réduit asso » : ce prix
-- et cette formule n'existent pas. La phrase est remplacée par le prix réel le plus bas
-- de la grille (Essentiel, 19,99 € HT/mois).
-- Ne touche que la colonne content_md de asso_blog_articles, seulement si l'ancienne
-- phrase y figure encore.
--
-- Idempotente : relancée, elle ne trouve plus l'ancienne phrase (0 ligne modifiée).
-- Lancement : php migrations/run.php 2026-11-09-grille-site-blog.sql
-- ------------------------------------------------------------------

UPDATE asso_blog_articles
   SET content_md = REPLACE(content_md,
       'Outils de gestion (comme Assokit) : à partir de 13 €/mois pour le plan Démarrage avec tarif réduit asso',
       'Outils de gestion (comme Assokit) : dès 19,99 € HT/mois (formule Essentiel), après un essai gratuit de 14 jours')
 WHERE content_md LIKE '%à partir de 13 €/mois pour le plan Démarrage%';
