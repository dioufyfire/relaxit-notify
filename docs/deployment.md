# Déployer le jalon authentification et tenants

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

## Installation

Récupérer la branche livrée après revue, puis depuis la racine du projet :

```bash
docker compose exec app php artisan down
docker compose exec app composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
docker run --rm -v "$PWD/app:/app" -w /app node:22-alpine sh -c 'npm ci && npm run build'
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
```

Si une étape échoue, résoudre l’erreur avant de réactiver le site. Si le code est revenu à sa révision précédente, réinstaller ses dépendances et reconstruire ses assets avant `up`. Les migrations de ce jalon ajoutent des tables/colonnes ; éviter leur rollback après création des comptes et appartenances, car il supprimerait ces données.

## Vérification fonctionnelle

Ouvrir `https://notify.relaxit.pro/login`. Se connecter avec le compte créé, ouvrir Clients puis Globale Santé, sélectionner ce client et vérifier le tableau de bord. Se déconnecter et vérifier qu’une URL privée renvoie à la connexion. Contrôler les cookies de session Secure, HttpOnly et SameSite=Lax dans le navigateur.

Les modules notifications, clés API, audit complet, 2FA et gestion web des utilisateurs appartiennent aux jalons suivants. Aucun envoi WhatsApp n’est déclenché par cette installation.

## Tests isolés

```bash
docker compose -f docker-compose.test.yml build tests
docker compose -f docker-compose.test.yml run --rm --no-deps tests composer install --no-interaction
docker compose -f docker-compose.test.yml run --rm tests php artisan test
docker compose -f docker-compose.test.yml down
```

Cette stack utilise PostgreSQL 16 et Redis 7 dédiés, sans montage des volumes de production. Ne pas lancer les tests contre la base de production.
