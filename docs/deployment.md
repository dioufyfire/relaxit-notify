# Déployer RelaxIT Notify — authentification, clés API, audit et notifications

## Mise à jour depuis le jalon déjà installé

Le compte Super Admin, Globale Santé et les accès existants sont conservés. Ce jalon ajoute les champs de suivi Meta à `notifications` et inclut sa création si nécessaire ; les migrations des clés API et du journal sont aussi incluses si elles ne sont pas encore appliquées. Les dépendances PHP/JS sont identiques au jalon précédent ; reconstruire les assets est nécessaire. Le seeder et `relaxit:bootstrap` restent réexécutables : un administrateur déjà présent n’est pas modifié. Ne pas changer les `.env` ni `APP_KEY`.

Après installation : Clients → Globale Santé → Notifications, Clés API ou Journal d’audit. Créer la clé Dolibarr seulement lorsque vous êtes prêt à conserver son secret et à configurer l’intégration.

## Préparation

Depuis `/docker/relaxit-notify`, sauvegarder PostgreSQL avec la procédure habituelle et conserver la révision courante (`git rev-parse HEAD`). Ne pas remplacer les `.env` existants ni régénérer `APP_KEY`.

L’image de production reste PHP 8.4 ; PostgreSQL, Redis, Nginx et Traefik gardent leur topologie. Les dépendances frontend se compilent dans un conteneur Node temporaire.

Vérifier dans `app/.env` :

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://notify.relaxit.pro
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Conserver les paramètres et secrets PostgreSQL/Redis actuels. Renseigner `TRUSTED_PROXIES` pour que les limites de connexion utilisent les adresses des visiteurs. Cette variable accepte une liste séparée par des virgules des adresses/CIDR des proxies réellement utilisés, sans faire confiance à toutes les adresses. En production, les URL sont générées en HTTPS si `APP_URL` utilise HTTPS.

## Récupérer le code avant toute installation

La branche `codex/meta-whatsapp-pilot` contient ce jalon et le précédent. Tant que ces changements ne sont pas fusionnés, `main` ne contient pas la version à déployer. Sur le VPS :

```bash
cd /docker/relaxit-notify
git status --short
git fetch origin
git switch codex/meta-whatsapp-pilot
git pull --ff-only origin codex/meta-whatsapp-pilot
git log -1 --oneline
ls -l app/package-lock.json app/app/Console/Commands/BootstrapRelaxit.php docker-compose.install.yml
```

Si Git signale des modifications locales ou refuse le changement de branche, les conserver et résoudre le conflit avant de poursuivre ; ne pas utiliser de reset forcé. Les `.env` ignorés restent en place. Après fusion du jalon, il est aussi possible de déployer `main` à jour.

Les trois fichiers du dernier contrôle doivent exister. L’absence de `package-lock.json` ou de `BootstrapRelaxit.php` indique une mauvaise révision ou un mauvais dossier : ne pas contourner cela avec `npm install` ou en installant Faker en production.

## Installation

Exécuter les commandes dans l’ordre, depuis `/docker/relaxit-notify`, et **s’arrêter dès la première erreur**.

Le service `app` est connecté uniquement au réseau `backend` avec `internal: true`. Il ne peut pas télécharger les dépendances. Le fichier `docker-compose.install.yml` utilise le même Dockerfile PHP 8.4 dans un conteneur temporaire, sans port publié, sur un réseau bridge avec accès sortant. Composer y télécharge les dépendances avec `--no-scripts` ; les étapes Laravel sont ensuite exécutées dans `app`, qui peut joindre PostgreSQL et Redis.

Conserver une sauvegarde PostgreSQL récente avant les migrations.

```bash
docker compose exec app php artisan down
docker compose -p relaxit-notify-install -f docker-compose.install.yml run --build --rm installer
docker compose exec app composer dump-autoload --no-dev --optimize --no-interaction
docker run --rm --mount "type=bind,src=$PWD/app,dst=/app" -w /app node:22-alpine sh -c 'test -f package-lock.json && npm ci && npm run build'
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan relaxit:bootstrap
```

La dernière commande demande le nom, l’adresse email et le mot de passe du premier Super Admin, avec confirmation masquée. Saisir ces informations directement sur le serveur. Elle refuse de promouvoir un utilisateur existant, de remplacer un mot de passe ou de créer un deuxième Super Admin. Une nouvelle exécution lorsqu’un Super Admin existe ne modifie aucun compte.

Le seeder est réexécutable et crée seulement le tenant `GLOBALE_SANTE` (Globale Santé). Il ne crée aucun compte de démonstration. L’email du cabinet est laissé vide tant que la possible faute de frappe dans le cadrage n’est pas confirmée.

```bash
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose exec -u root app chown -R www-data:www-data storage bootstrap/cache
docker compose restart app worker scheduler
docker compose restart web
docker compose exec app php artisan up
docker compose -p relaxit-notify-install -f docker-compose.install.yml down
```

Si une étape échoue, résoudre l’erreur avant de réactiver le site. Si le code est revenu à sa révision précédente, réinstaller ses dépendances et reconstruire ses assets avant `up`. Les migrations de ce jalon ajoutent des tables/colonnes ; éviter leur rollback après création des comptes et appartenances, car il supprimerait ces données.

## Vérification fonctionnelle

Ouvrir `https://notify.relaxit.pro/login`. Se connecter avec le compte créé, ouvrir Clients puis Globale Santé, sélectionner ce client et vérifier le tableau de bord. Se déconnecter et vérifier qu’une URL privée renvoie à la connexion. Contrôler les cookies de session Secure, HttpOnly et SameSite=Lax dans le navigateur.

Les clés API et le journal d’audit sont disponibles depuis les fiches clients. Leur utilisation est détaillée dans [le guide des clés API](api-keys.md). Le moteur de notifications est disponible ; l’activation du pilote Meta est documentée séparément ; la 2FA et la gestion web des utilisateurs appartiennent aux jalons suivants. Le [guide API des notifications](notifications.md) fournit le format des demandes et les contrôles de file. Les envois restent désactivés par défaut. Pour le pilote Meta, suivre ensuite [la procédure dédiée](meta-whatsapp.md).

## Contrôler le traitement des notifications

Le scheduler publie les demandes arrivées à échéance dans Redis chaque minute (500 au maximum par passage). Le worker traite la file Redis `default`. Vérifier `QUEUE_CONNECTION=redis` et conserver la même valeur `REDIS_QUEUE` dans app, worker et scheduler si vous la personnalisez. Aucune nouvelle connexion réseau ni aucun secret Meta ne sont nécessaires à ce jalon.

```bash
docker compose exec app php artisan schedule:list
docker compose exec app php artisan relaxit:queue-notifications
docker compose ps
docker compose logs worker scheduler --tail=50
```

Une demande immédiate passe de `queued` à `awaiting_provider`. Cela signifie « prête pour le fournisseur », jamais « envoyée ». Les demandes planifiées attendent leur date ; si Redis perd une publication, le scheduler la republie après un délai de cinq minutes. Le fournisseur sera connecté dans un jalon distinct. Ne pas purger la table `notifications` : elle conserve également la protection contre les doublons.

Les destinataires, variables et références externes sont chiffrés avec `APP_KEY`. Sa sauvegarde est indispensable pour relire ces données. Ne pas régénérer cette clé lors d’un déploiement. Une future rotation devra traiter le chiffrement et les empreintes d’idempotence.

## Tests isolés

```bash
docker compose -f docker-compose.test.yml build tests
docker compose -f docker-compose.test.yml run --rm --no-deps tests composer install --no-interaction
docker compose -f docker-compose.test.yml run --rm tests php artisan test
docker compose -f docker-compose.test.yml down
```

Cette stack utilise PostgreSQL 16 et Redis 7 dédiés, sans montage des volumes de production. Ne pas lancer les tests contre la base de production.

## Comprendre les erreurs de la première tentative

- `Could not resolve host: repo.packagist.org` dans `app` : le réseau backend est interne. Utiliser le conteneur d’installation ci-dessus. Si la résolution échoue aussi dans ce conteneur, il faut diagnostiquer le DNS/la sortie réseau du VPS, sans désactiver la vérification TLS.
- `npm ci` sans `package-lock.json` : le code du jalon n’est pas récupéré, ou le montage pointe vers un autre dossier. Le verrou npm est versionné dans `app/package-lock.json`.
- `Database\Factories\fake()` pendant le seeding : l’ancien `DatabaseSeeder` appelle encore une factory de démonstration alors que `--no-dev` exclut Faker. Le nouveau seeder crée uniquement `GLOBALE_SANTE`.
- Aucun namespace `relaxit` : vérifier la présence du nouveau fichier de commande, puis l’autoload Composer et `php artisan optimize:clear`.
