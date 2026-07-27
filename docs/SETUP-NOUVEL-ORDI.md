# 💻 Continuer à travailler sur un nouvel ordinateur (Mac)

Tout ton travail est sur **GitHub** (rien d'irremplaçable en local). Ce guide remet
un nouveau Mac dans l'état exact pour continuer à développer Assokit.

> ⏱️ Compte ~30 min la première fois. Tu ne le refais qu'une fois.

---

## Partie 0 — Ce dont tu as besoin sous la main (comptes)

Aie tes identifiants de :
- **claude.ai** (pour continuer avec Claude Code) — email : psiwaneraph@gmail.com
- **GitHub** (compte RaphBadr91)
- **O2switch** (cPanel + SSH)
- **Apple Developer** (pour les builds TestFlight)
- **Resend** (emails)

---

## Partie 1 — Reparler à Claude (le plus important, 2 min)

**Aucune installation.** Ouvre ton navigateur → **claude.ai** → connecte-toi avec
ton compte → tu retrouves Claude Code sur le web. Tu peux relancer une session sur
le projet Assokit et continuer exactement comme avant.

> Le code que Claude modifie est poussé sur GitHub (branche `claude/new-session-36m7pw`),
> donc accessible depuis n'importe quel ordinateur.

Pour le développement en local (builds de l'app, déploiements), continue ci-dessous.

---

## Partie 2 — Installer les outils (Homebrew)

Ouvre **Terminal** (Cmd+Espace → « Terminal »).

1. **Homebrew** (le gestionnaire d'outils du Mac) :
```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```
Suis les instructions à la fin (il te donne 2 lignes `echo ... >> ~/.zprofile` à coller).

2. **Git, Node.js, PHP** :
```bash
brew install git node php
```

3. **EAS CLI** (pour builder l'app iOS) :
```bash
npm install -g eas-cli
```

4. Vérifie que tout est là :
```bash
git --version && node --version && php --version && eas --version
```

---

## Partie 3 — Connexion à GitHub (clé SSH)

1. Génère une clé SSH :
```bash
ssh-keygen -t ed25519 -C "psiwaneraph@gmail.com"
```
(Appuie sur Entrée 3 fois pour accepter les valeurs par défaut.)

2. Copie la clé publique :
```bash
cat ~/.ssh/id_ed25519.pub | pbcopy
```
(Elle est maintenant dans ton presse-papier.)

3. Sur **github.com** → photo de profil → **Settings** → **SSH and GPG keys** →
**New SSH key** → colle (Cmd+V) → **Add SSH key**.

4. Teste :
```bash
ssh -T git@github.com
```
(Réponds « yes » ; tu dois voir « Hi RaphBadr91! »)

---

## Partie 4 — Récupérer le projet

```bash
cd ~/Desktop
git clone git@github.com:RaphBadr91/Assokit.git
cd Assokit
git checkout claude/new-session-36m7pw
```

Configure ton identité Git (une fois) :
```bash
git config --global user.name "Raphael"
git config --global user.email "psiwaneraph@gmail.com"
```

Installe les dépendances de l'app mobile :
```bash
cd mobile
npm install
cd ..
```

---

## Partie 5 — Les raccourcis pratiques (alias)

```bash
echo "alias assokit='cd ~/Desktop/Assokit'" >> ~/.zshrc
echo "alias assokit-build='cd ~/Desktop/Assokit/mobile && eas build --platform ios --profile production'" >> ~/.zshrc
source ~/.zshrc
```
Désormais, tape juste **`assokit`** depuis n'importe où pour aller dans le projet.

---

## Partie 6 — Connexion aux services

- **EAS / Expo** (builds app) :
```bash
eas login
```
- **O2switch (SSH)** — pour déployer le site. Récupère l'hôte/identifiant SSH dans
  ton cPanel O2switch (section « Accès SSH »). Connexion type :
```bash
ssh pura7044@ton-serveur.o2switch.net
```
  puis sur le serveur :
```bash
cd ~/public_html && git pull origin claude/new-session-36m7pw
```

---

## Le workflow quotidien (rappel)

**Développer avec Claude** → il pousse sur GitHub → **déployer** :

- **Site (PHP)** : en SSH sur O2switch → `cd ~/public_html && git pull origin claude/new-session-36m7pw`
- **App (mobile)** : `assokit-build` (puis `eas submit --platform ios --profile production --latest`)

---

## En cas de doute

Tout est versionné sur GitHub. Si tu casses quelque chose en local, tu peux toujours
re-cloner proprement (Partie 4). Rien n'est jamais perdu tant que c'est poussé.
