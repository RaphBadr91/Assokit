<?php
/**
 * mon-asso-ia-admin-quotas-save.php
 * --------------------------------------------------------------
 * NEUTRALISÉ — les quotas sont désormais immuables.
 * Cet endpoint redirige simplement vers le hub IA.
 * Conservé pour invalider toute tentative POST sur l'ancienne URL.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

require_login();

header('Location: /mon-asso-ia');
exit;
