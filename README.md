# RelaxIT Notify

Portail multi-tenant RelaxIT : Laravel 13 / PHP 8.4, Vue 3 + Inertia 2, PostgreSQL 16 et Redis 7. Déploiement Docker derrière le Traefik existant.

Le premier jalon livre la connexion web, la déconnexion, un tableau de bord, la sélection d’un tenant, les fiches clients et les cinq rôles initiaux. Les rôles RelaxIT sont distincts des rôles par client. Le tenant `GLOBALE_SANTE` est initialisé sans compte ni mot de passe de démonstration.

- [Installation sur le serveur et création du premier administrateur](docs/deployment.md)
- [Clés API, vérification de connexion et audit](docs/api-keys.md)
- [Modèle d’accès et matrice des rôles](docs/access-model.md)

Le compte Super Admin est créé sur le serveur avec `docker compose exec app php artisan relaxit:bootstrap` après les migrations. Le mot de passe est saisi de façon masquée et n’est jamais enregistré dans le dépôt.

## Vérification locale

Depuis la racine du dépôt :

```bash
docker compose -f docker-compose.test.yml build tests
docker compose -f docker-compose.test.yml run --rm --no-deps tests composer install --no-interaction
docker compose -f docker-compose.test.yml run --rm tests php artisan test
docker compose -f docker-compose.test.yml down
```

Compilation du portail :

```bash
docker run --rm -v "$PWD/app:/app" -w /app node:22-alpine sh -c 'npm ci && npm run build'
```

Les tests utilisent une stack distincte, jamais les bases ou volumes de production. Les fichiers `.env`, dépendances et assets compilés ne sont pas versionnés. Laravel Boost est installé uniquement comme dépendance de développement.
