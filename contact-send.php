<?php
/**
 * contact-send.php — ancien point d'envoi du formulaire de contact (retiré)
 * --------------------------------------------------------------
 * /contact poste désormais sur lui-même (contact.php), avec une limite
 * d'envois par IP et les garde-fous de send_transactional_email.
 * Ce point d'entrée, public et sans limite, envoyait des e-mails Assokit
 * à n'importe quelle adresse : il ne traite plus rien. Toute requête,
 * POST compris, est renvoyée vers le formulaire (le POST devient un GET,
 * sans enregistrement ni envoi).
 * --------------------------------------------------------------
 */
header('Location: /contact', true, 301);
exit;
