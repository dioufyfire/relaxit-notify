# Accès RelaxIT Notify — premier jalon

L’application conserve Laravel 13, PHP 8.4, PostgreSQL, Redis et la stack Docker/Traefik existante. Le portail utilise Vue 3 et Inertia conformément au cadrage.

## Rôles

Les rôles plateforme sont portés par `users.platform_role`, les rôles client par `tenant_user.role`. Les valeurs sont des enums applicatives et sont contraintes en base. Un rôle client ne donne jamais un rôle plateforme.

| Rôle | Portée | Consultation | Modification du tenant |
|---|---|---|---|
| Super Admin RelaxIT | Tous les tenants | Oui | Oui, création comprise |
| Support RelaxIT | Tous les tenants | Oui | Non |
| Admin Client | Tenants dont il est membre | Oui | Oui, coordonnées uniquement |
| User Client | Tenants dont il est membre | Oui | Non |
| Read Only | Tenants dont il est membre | Oui | Non |

Les droits d’envoi de User Client seront implémentés avec le moteur de notifications ; aucun endpoint d’envoi n’est livré ici. La gestion web des utilisateurs et l’attribution des rôles restent hors de ce jalon.

## Isolation

Le tenant sélectionné est conservé en session. Chaque requête le résout à nouveau depuis les tenants autorisés de l’utilisateur actif. Une appartenance supprimée ou un tenant désactivé invalide immédiatement ce contexte. Les routes de détail et modification appliquent également une policy. Les futurs modèles métier devront recevoir un tenant explicite et filtrer leurs requêtes ; le socle ne prétend pas filtrer automatiquement des tables futures.

## Initialisation

Le seeder crée `GLOBALE_SANTE` sans remplacer les données existantes. Une commande interactive crée le premier Super Admin ; aucun mot de passe par défaut, aucune inscription publique et aucune promotion silencieuse d’un compte existant.
