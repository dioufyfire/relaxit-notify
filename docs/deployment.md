# Déployer RelaxIT Notify — authentification, clés API, audit et notifications

## Préparer plusieurs modèles de rendez-vous

La branche `codex/appointment-lifecycle-templates` inclut le plafond local et le mode production précédents. Elle ajoute une liste explicite de modèles supplémentaires ; aucune migration, dépendance ou compilation frontend n'est nécessaire. Elle ne modifie pas le connecteur Dolimed et n'active pas les modifications, annulations ou rappels automatiquement.

```bash
cd /docker/relaxit-notify
git status --short
git fetch origin
docker compose stop worker scheduler
git switch codex/appointment-lifecycle-templates
git pull --ff-only origin codex/appointment-lifecycle-templates
```

Conserver la configuration existante. Laisser `META_WHATSAPP_ADDITIONAL_TEMPLATES` vide tant que les nouveaux modèles ne sont pas approuvés. La configuration détaillée et les textes proposés figurent dans [meta-whatsapp.md](meta-whatsapp.md#préparation-des-modifications-annulations-et-rappels).

```bash
docker compose exec app php artisan config:cache
docker compose exec app php artisan relaxit:whatsapp-status
```

Après diagnostic réussi :

```bash
docker compose restart app worker scheduler web
```

Le redémarrage de `web` rafraîchit sa résolution de l'adresse de l'application, pour éviter le 502 déjà rencontré après redémarrage. Si Git signale un conflit, ne pas forcer. Le modèle principal et les anciennes demandes restent inchangés. Déployer ce préalable avant d'activer `lifecycle_enabled` dans Dolimed Notif 0.3. Le [guide du connecteur](https://github.com/dioufyfire/dolimed_notif/blob/codex/appointment-lifecycle/README.md) décrit sa migration et la recette des modifications, suppressions et rappels à 24 h. Ne pas changer la date d'activation existante lors de cet ajout de modèles.

## Ouverture aux patients — mode production contrôlé

La branche `codex/whatsapp-production-guardrails` inclut les jalons précédents. Cette mise à jour ne demande ni migration, ni nouvelle dépendance, ni compilation frontend. Elle utilise PostgreSQL pour sérialiser le compteur d'envoi. Le mode pilote reste le défaut : récupérer le code seul n'ouvre pas les destinataires.

Depuis la version avec image validée, conserver une sauvegarde et la révision actuelle, puis arrêter temporairement worker/scheduler pour éviter des workers utilisant des réglages différents :

```bash
cd /docker/relaxit-notify
git status --short
git rev-parse HEAD
docker compose stop worker scheduler
git fetch origin
git switch codex/whatsapp-production-guardrails
git pull --ff-only origin codex/whatsapp-production-guardrails
date -u +%Y-%m-%dT%H:%M:%SZ
```

En cas de conflit Git, conserver les modifications locales et le résoudre avant la suite. Dans `app/.env`, conserver les secrets, l'image et le modèle validés ; ajouter :

```dotenv
META_WHATSAPP_MODE=production
META_WHATSAPP_APPLICATION=dolibarr
META_WHATSAPP_MAX_ATTEMPTS_PER_24H=250
```

Remplacer `META_WHATSAPP_ENABLED_AFTER` par l'heure UTC affichée pour exclure l'ancien lot, et conserver `META_WHATSAPP_ENABLED=true` lorsque l'ouverture est souhaitée. Ne pas supprimer le destinataire de test : il servira pour un retour au mode pilote.

```bash
docker compose exec app php artisan config:cache
docker compose exec app php artisan relaxit:whatsapp-status
docker compose restart app worker scheduler
```

Ne redémarrer les envois qu'après un diagnostic local réussi. Un `restart` conserve le réseau sortant du worker ; pour toute recréation avec `up`, conserver l'override `docker-compose.meta.yml`. La configuration Dolimed reste identique. Faire la recette avec un second numéro de test consenti et suivre les statuts jusqu'à `Lu` dans les deux applications. Les détails du plafond et du consentement figurent dans [meta-whatsapp.md](meta-whatsapp.md#ouverture-aux-patients-de-globale-santé).

Pour revenir au pilote : arrêter worker/scheduler, remettre `META_WHATSAPP_MODE=pilot`, vérifier le destinataire de test, refaire le cache et le diagnostic, puis redémarrer. Conserver le code pour garder le plafond local. Les demandes bloquées ne sont pas remises en file automatiquement.

## Mise à jour de l’en-tête image WhatsApp

Depuis la version `codex/meta-webhooks`, cette évolution n'ajoute ni dépendance, ni migration, ni changement frontend. Conserver les `.env` et `APP_KEY` existants.

```bash
cd /docker/relaxit-notify
git status --short
git fetch origin
git switch codex/meta-image-header
git pull --ff-only origin codex/meta-image-header
```

Si Git signale un conflit, conserver les modifications locales avant de poursuivre. Ajouter dans `app/.env` la variable `META_WHATSAPP_HEADER_IMAGE_URL` décrite dans [meta-whatsapp.md](meta-whatsapp.md#modèle-avec-en-tête-image), avec l'URL HTTPS publique de votre image. Puis :

```bash
docker compose exec app php artisan config:cache
docker compose restart app worker scheduler
docker compose exec app php artisan relaxit:whatsapp-status
```

Le diagnostic est local. Valider ensuite avec un nouveau rendez-vous fictif, la réception de l'image et du texte, puis les statuts de lecture. En cas de retour à la révision précédente, un modèle avec en-tête image restera incompatible ; suspendre les envois avant ce retour.

## Mise à jour depuis le jalon déjà installé

Le compte Super Admin, Globale Santé et les accès existants sont conservés. Ce jalon ajoute la table de réception des webhooks et les dates de livraison à `notifications` et inclut sa création si nécessaire ; les migrations des clés API et du journal sont aussi incluses si elles ne sont pas encore appliquées. Les dépendances PHP/JS sont identiques au jalon précédent ; reconstruire les assets est nécessaire. Le seeder et `relaxit:bootstrap` restent réexécutables : un administrateur déjà présent n’est pas modifié. Conserver les `.env` et `APP_KEY` ; ajouter uniquement les nouvelles variables documentées.

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

La branche `codex/meta-webhooks` contient ce jalon et le précédent. Tant que ces changements ne sont pas fusionnés, `main` ne contient pas la version à déployer. Sur le VPS :

```bash
cd /docker/relaxit-notify
git status --short
git fetch origin
git switch codex/meta-webhooks
git pull --ff-only origin codex/meta-webhooks
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
docker compose exec web nginx -t
docker compose restart web
docker compose exec app php artisan up
docker compose -p relaxit-notify-install -f docker-compose.install.yml down
```

Si une étape échoue, résoudre l’erreur avant de réactiver le site. Si le code est revenu à sa révision précédente, réinstaller ses dépendances et reconstruire ses assets avant `up`. Les migrations de ce jalon ajoutent des tables/colonnes ; éviter leur rollback après création des comptes et appartenances, car il supprimerait ces données.

## Vérification fonctionnelle

Ouvrir `https://notify.relaxit.pro/login`. Se connecter avec le compte créé, ouvrir Clients puis Globale Santé, sélectionner ce client et vérifier le tableau de bord. Se déconnecter et vérifier qu’une URL privée renvoie à la connexion. Contrôler les cookies de session Secure, HttpOnly et SameSite=Lax dans le navigateur.

Les clés API et le journal d’audit sont disponibles depuis les fiches clients. Leur utilisation est détaillée dans [le guide des clés API](api-keys.md). Le moteur de notifications est disponible ; l’activation du pilote Meta est documentée séparément ; la 2FA et la gestion web des utilisateurs appartiennent aux jalons suivants. Le [guide API des notifications](notifications.md) fournit le format des demandes et les contrôles de file. Les envois restent désactivés par défaut. Pour une première activation Meta, suivre [la procédure du pilote](meta-whatsapp.md). Si le pilote fonctionne déjà, conserver ses paramètres et passer directement au [suivi des livraisons](meta-webhooks.md).

## Contrôler le traitement des notifications

Le scheduler publie les demandes arrivées à échéance dans Redis chaque minute (500 au maximum par passage). Le worker traite la file Redis `default`. Vérifier `QUEUE_CONNECTION=redis` et conserver la même valeur `REDIS_QUEUE` dans app, worker et scheduler si vous la personnalisez. Le suivi des livraisons nécessite la configuration du [webhook Meta](meta-webhooks.md). Conserver la sortie réseau du worker déjà configurée pour le pilote.

```bash
docker compose exec app php artisan schedule:list
docker compose exec app php artisan relaxit:queue-notifications
docker compose ps
docker compose logs worker scheduler --tail=50
```

Lorsque le pilote est désactivé, une demande immédiate passe de `queued` à `awaiting_provider`. Cela signifie « prête pour le fournisseur », jamais « envoyée ». Les demandes planifiées attendent leur date ; si Redis perd une publication, le scheduler la republie après un délai de cinq minutes. Lorsque le pilote Meta est activé, les demandes autorisées passent à `submitted`, puis aux statuts reçus par webhook. Ne pas purger la table `notifications` : elle conserve également la protection contre les doublons.

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
