# Clés API et journal d’audit

Depuis la fiche d’un client, ouvrir **Clés API**. Le Super Admin RelaxIT et l’Admin Client peuvent créer, renouveler et révoquer des clés. Le Support RelaxIT consulte uniquement leurs métadonnées. User Client et Read Only n’ont pas accès à ces écrans.

## Créer une connexion Dolibarr

Pour Globale Santé, donner un nom comme « Dolibarr Globale Santé » et l’identifiant d’application `dolibarr`. Une seule clé non révoquée peut exister pour un couple client/application. La même application peut avoir une clé différente chez chaque client.

Le secret est affiché immédiatement, une seule fois. Le conserver dans le gestionnaire de secrets ou la configuration serveur de l’application. Il n’est pas placé dans les propriétés Inertia, les sessions ni le journal d’audit et n’est pas récupérable ensuite. Seule une empreinte SHA-256 d’un secret aléatoire de 256 bits est stockée, avec une référence publique distincte. L’écran de liste ne montre ni secret ni empreinte.

La date d’expiration est facultative. Une clé expire au début de la date choisie, en UTC. Une clé expirée doit être révoquée avant de recréer une clé pour la même application.

## Vérifier la connexion

L’application cliente effectue :

```http
GET /api/v1/me
Authorization: Bearer VOTRE_CLE
Accept: application/json
```

L’URL de production est `https://notify.relaxit.pro/api/v1/me`. La réponse contient le client associé et l’application :

```json
{
  "tenant": { "id": 1, "code": "GLOBALE_SANTE", "name": "Globale Santé" },
  "application": "dolibarr"
}
```

L’identifiant numérique dépend de votre base. Le client est déterminé uniquement par la clé, jamais par la session web ou un paramètre `tenant_id`. Ne pas transmettre une clé dans une URL. Le [moteur de notifications](notifications.md) fournit maintenant `/api/v1/notifications` pour enregistrer et suivre les demandes. Aucun envoi WhatsApp n’est encore actif.

Réponses : `200` connexion valide ; `401` clé absente, inconnue, falsifiée, expirée, révoquée ou client désactivé ; `429` limite atteinte, avec `Retry-After`. La limite est de 60 requêtes/minute par client, partagée entre ses applications (`API_REQUESTS_PER_MINUTE` dans `app/.env`). Une limite d’entrée supplémentaire de 120 requêtes/minute par IP s’applique avant authentification. Redis doit rester le cache de production. Configurer les proxies de confiance comme indiqué dans le guide de déploiement pour identifier correctement l’IP du visiteur.

## Rotation et révocation

Le renouvellement produit une nouvelle clé et révoque l’ancienne dans la même transaction. L’expiration reste identique. L’ancienne clé cesse de fonctionner pour les nouvelles requêtes dès la validation de la transaction : prévoir la mise à jour immédiate de Dolibarr. Une requête déjà autorisée avant la révocation peut terminer son traitement.

La révocation est définitive. Une nouvelle tentative de révocation ne crée pas d’événement supplémentaire. Si la réponse de création ou de rotation se perd, recharger la liste, révoquer la clé dont le secret n’a pas été reçu, puis en créer une nouvelle.

Les clés sont des accès d’application indépendants du compte qui les a créées. Désactiver ce compte ne révoque pas automatiquement ses clés ; révoquer explicitement les accès d’application concernés. Désactiver un client invalide toutes ses clés lors de la requête suivante.

## Audit

Le journal enregistre les connexions réussies/échouées, déconnexions, création du premier administrateur, créations/modifications/sélections de clients et création/rotation/révocation de clés. Les événements sont disponibles à partir de ce déploiement ; aucun historique antérieur n’est inventé.

Les rôles RelaxIT consultent le journal global. L’Admin Client consulte uniquement les événements de ses clients. Les adresses IP sont réservées aux rôles RelaxIT. Les mots de passe, clés et valeurs de coordonnées modifiées ne sont pas enregistrés ; seuls les noms des champs modifiés le sont.

Aucun écran ni endpoint ne permet d’éditer ou supprimer un événement. Le modèle bloque ces opérations applicatives. Ce mécanisme ne constitue pas un stockage inviolable : un administrateur PostgreSQL conserve la capacité de modifier la base. La conservation réglementaire, les exports et l’archivage devront être définis séparément.
