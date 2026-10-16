# Seydeco

Site de location de matériel de réception — Symfony 7.4, PHP 8.4, AssetMapper, MariaDB.

## Développer en local

Prérequis : PHP 8.4+, Composer, [Symfony CLI](https://symfony.com/download), Docker.

```bash
composer install
docker compose up -d        # base MariaDB 10.11 (+ Mailpit pour les e-mails)
symfony serve -d            # https://127.0.0.1:8000
```

La Symfony CLI fournit automatiquement `DATABASE_URL` et `MAILER_DSN` à partir de Docker
(lancer les commandes avec `symfony console …` / `symfony php …`).
Les réglages propres à votre machine vont dans `.env.local` (jamais commité).

Mettre la base à jour :

```bash
symfony console doctrine:migrations:migrate -n
```

## Back-office

Créer le compte administrateur (une seule fois ; un second compte est refusé) :

```bash
symfony console app:admin:create serife@exemple.fr     # le mot de passe est demandé deux fois, sans s'afficher
```

Puis se connecter sur `/admin/connexion` : email + mot de passe, puis code à 6 chiffres reçu par email
(valable 15 minutes, bouton « Renvoyer un code »).

En développement, les emails ne partent pas vraiment : ils arrivent dans **Mailpit**.
Ouvrir l'adresse donnée par `symfony open:local:webmail` (ou `docker compose port mailer 8025`).

Mot de passe oublié : pas de lien par email (hors périmètre V1). Sur le serveur, supprimer le compte en base
puis relancer `app:admin:create`.

Sur le serveur, `MAILER_DSN` et `MAILER_FROM` (adresse d'expédition) vont dans `shared/.env.local`.

## Tests

Les tests utilisent la base `app_test` (créée automatiquement au premier `docker compose up`) :

```bash
symfony console doctrine:migrations:migrate -n --env=test
symfony php bin/phpunit
composer audit
```

Volume Docker créé avant l'ajout de la base de test : `docker compose down -v && docker compose up -d`
(efface les données locales de développement).

La CI GitHub Actions (`.github/workflows/ci.yml`) lance les deux à chaque push.
Un commit dont la CI n'est pas verte ne peut pas être déployé.

## Déployer sur o2switch

Une seule fois sur la machine de développement :

```bash
gh auth login                      # deploy.sh interroge GitHub pour vérifier la CI
cp deploy.env.dist deploy.env      # puis remplir avec les accès o2switch (fichier jamais commité)
```

Puis, à chaque mise en ligne (pousser d'abord sur `main` et attendre la CI verte) :

```bash
./deploy.sh
```

Le script : vérifie la CI du dernier commit de `main` → dump de la base → nouvelle release horodatée →
`composer install --no-dev` → migrations → `asset-map:compile` → `cache:clear` → bascule du lien `current`
→ tag Git `release-…`. Si une migration échoue, le dump est restauré et le site reste sur l'ancienne release.

### Retour arrière

```bash
./deploy.sh --rollback
```

Remet la release précédente **et** la base telle qu'elle était avant le dernier déploiement
(les données saisies depuis sont perdues ; le script demande confirmation).

### Checklist serveur (première fois)

- [ ] Clé SSH installée, `SSH_HOST` et `REMOTE_DIR` remplis dans `deploy.env`.
- [ ] Chemin du PHP 8.4 en ligne de commande confirmé (`PHP_BIN`, par ex. `/opt/alt/php84/usr/bin/php`) et `composer.phar` présent (`COMPOSER_PHAR`).
- [ ] Base MariaDB/MySQL créée dans cPanel ; sa version reportée dans `serverVersion` de `.env` et dans `compose.yaml`.
- [ ] `REMOTE_DIR/shared/.env.local` créé à la main avec au minimum :
      `APP_ENV=prod`, `APP_SECRET=` (valeur aléatoire, ne plus la changer : elle sert aussi aux compteurs anti-essais),
      `DATABASE_URL=` (accès o2switch), `MAILER_DSN=` (SMTP o2switch), `MAILER_FROM=` (adresse d'expédition).
- [ ] Après le premier déploiement : `app:admin:create` en SSH pour créer le compte de Serife.
- [ ] Racine web du domaine pointée sur `REMOTE_DIR/current/public`.
- [ ] HTTPS activé (AutoSSL) ; la redirection HTTP → HTTPS est faite par `public/.htaccess`.
- [ ] Fuseau MySQL : `SET time_zone = 'Europe/Paris'` accepté par le serveur (sinon demander le chargement des fuseaux à o2switch).
