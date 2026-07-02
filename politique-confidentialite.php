<?php
/**
 * Politique de confidentialité — Assokit
 */
$page_title = 'Politique de confidentialité';
$page_content = '
<p class="lead">Chez Assokit, nous prenons la protection de vos données personnelles très au sérieux. Cette politique explique ce que nous collectons, pourquoi, et comment vous gardez le contrôle.</p>

<h2>1. Qui collecte vos données ?</h2>
<p>Assokit est responsable du traitement de vos données personnelles. Contact : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a></p>

<h2>2. Quelles données collectons-nous ?</h2>
<ul>
<li><strong>Données de compte :</strong> nom, prénom, email, mot de passe (chiffré)</li>
<li><strong>Données d\'association :</strong> nom, adresse, statuts, liste d\'adhérents</li>
<li><strong>Données d\'activité :</strong> projets, messages internes, factures, événements</li>
<li><strong>Données techniques :</strong> adresse IP, navigateur, dates de connexion (logs)</li>
</ul>

<h2>3. Pourquoi ces données ?</h2>
<ul>
<li>Fournir le service Assokit et ses fonctionnalités</li>
<li>Assurer la sécurité de votre compte</li>
<li>Vous envoyer des notifications liées à votre usage</li>
<li>Respecter nos obligations légales (facturation, comptabilité)</li>
</ul>

<h2>4. Où sont stockées vos données ?</h2>
<p>🇫🇷 <strong>Vos données sont hébergées en France</strong>, chez O2Switch (Clermont-Ferrand, Auvergne). Elles ne quittent jamais le territoire de l\'Union Européenne, conformément au RGPD.</p>

<h2>5. Qui y a accès ?</h2>
<p>Personne d\'autre que :</p>
<ul>
<li>Vous et les membres de votre association (selon leurs rôles)</li>
<li>Notre équipe technique, uniquement en cas d\'incident nécessitant une intervention</li>
</ul>
<p>Nous ne vendons pas, ne partageons pas, ne monnayons pas vos données avec des tiers.</p>

<h2>6. Combien de temps ?</h2>
<ul>
<li><strong>Compte actif :</strong> tant que vous utilisez Assokit</li>
<li><strong>Compte inactif :</strong> conservation 3 ans après la dernière connexion, puis suppression</li>
<li><strong>Données de facturation :</strong> 10 ans (obligation légale comptable)</li>
</ul>

<h2>7. Vos droits (RGPD)</h2>
<p>Vous pouvez à tout moment :</p>
<ul>
<li><strong>Accéder</strong> à vos données personnelles</li>
<li><strong>Rectifier</strong> des informations incorrectes</li>
<li><strong>Supprimer</strong> votre compte et vos données</li>
<li><strong>Exporter</strong> vos données dans un format réutilisable</li>
<li><strong>Vous opposer</strong> à certains traitements</li>
</ul>
<p>Pour exercer ces droits : <a href="mailto:contact@assokit.fr">contact@assokit.fr</a> — réponse sous 30 jours.</p>

<h2>8. IA et vos données</h2>
<p>Notre copilote IA analyse les données de votre association pour vous suggérer des actions pertinentes. Ces analyses restent internes à votre espace. Aucune donnée personnelle de vos adhérents n\'est envoyée aux services IA externes — seules les informations générales du projet sont transmises.</p>

<h2>9. Cookies</h2>
<p>Assokit utilise uniquement des cookies techniques nécessaires au fonctionnement du service (session de connexion). Aucun cookie publicitaire ou de tracking.</p>

<h2>10. Sécurité</h2>
<ul>
<li>Mots de passe chiffrés (bcrypt)</li>
<li>Connexion sécurisée HTTPS</li>
<li>Tokens temporaires pour la création/réinitialisation des mots de passe</li>
<li>Sauvegardes régulières</li>
</ul>

<h2>11. Modifications</h2>
<p>Cette politique peut évoluer. Nous vous préviendrons par email en cas de changement significatif.</p>

<h2>12. Réclamation</h2>
<p>En cas de désaccord, vous pouvez saisir la <a href="https://www.cnil.fr/" target="_blank" rel="noopener">CNIL</a>, autorité française de protection des données.</p>
';
require __DIR__ . '/_legal-template.php';
