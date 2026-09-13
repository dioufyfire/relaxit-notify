# API de notifications — jalon 2

Ce jalon implémente la réception, la planification et la préparation des notifications. Les envois restent désactivés par défaut. Le [pilote Meta](meta-whatsapp.md) permet un envoi contrôlé après configuration ; aucun SMS, email ni aucune facturation n’est effectué. Le seul canal accepté initialement est `whatsapp` ; le modèle de notification est indépendant du futur fournisseur.

## Authentification et périmètre

Utiliser une clé API créée dans Clients → Globale Santé → Clés API, application `dolibarr`. Envoyer `Authorization: Bearer <secret>` sur chaque requête. Le client et l’application proviennent exclusivement de cette clé. La session web ne donne aucun accès API. Ne jamais placer le secret dans l’URL.

Les limites du [guide des clés API](api-keys.md) s’appliquent aussi aux notifications. Une clé renouvelée pour la même application retrouve les demandes et leur protection contre les doublons. Révoquer une clé empêche les prochains appels mais n’annule pas les demandes déjà acceptées.

## Créer une demande

`POST /api/v1/notifications`, corps JSON et en-tête `Idempotency-Key` requis.

```json
{
  "recipient": "+221770000001",
  "channel": "whatsapp",
  "template": "appointment_reminder",
  "variables": {
    "name": "Exemple",
    "date": "15/10/2026",
    "time": "10:30"
  },
  "external_reference": "rdv-123-reminder"
}
```

Ce numéro et ce contenu sont fictifs. Pour les essais, utiliser uniquement des données de démonstration.

| Champ | Règle |
|---|---|
| recipient | Obligatoire : `+` suivi de 8 à 15 chiffres, premier chiffre non nul. Ne prouve pas que le numéro existe. |
| channel | Obligatoire : `whatsapp` uniquement. |
| template | Obligatoire : identifiant de 100 caractères maximum, minuscules/chiffres/underscore, commençant par une lettre. L’approbation Meta sera vérifiée lors de l’intégration du fournisseur. |
| variables | Obligatoire : objet ou liste, vide autorisé ; 30 valeurs texte maximum, chacune de 1 à 1 000 caractères. Identifiants simples de 64 caractères maximum ; aucun objet imbriqué. |
| schedule_at | Facultatif : date future, au plus 12 mois, ISO 8601 avec secondes et fuseau, par exemple `2026-10-15T10:30:00Z`. Sans ce champ : dès que possible. |
| external_reference | Facultatif : identifiant de votre événement, 128 caractères maximum. Ne remplace pas la clé d’idempotence. |

Les champs supplémentaires sont refusés, notamment `tenant_id`, `application`, `status`, `provider` ou une URL de callback. Les espaces de début/fin des valeurs sont normalisés par Laravel. Les dates sont stockées en UTC, puis affichées dans le fuseau du navigateur.

Réponse initiale **202 Accepted**, en-tête `Location` vers la consultation et corps :

```json
{
  "data": {
    "id": "01K50000000000000000000000",
    "application": "dolibarr",
    "channel": "whatsapp",
    "template": "appointment_reminder",
    "status": "queued",
    "error_code": null,
    "schedule_at": null,
    "created_at": "2026-09-13T12:00:00.000000Z",
    "prepared_at": null
  },
  "replayed": false
}
```

Les identifiants/dates ci-dessus sont illustratifs. L’état peut déjà avoir évolué si le worker traite rapidement. Le contenu, le numéro complet et la référence externe ne sont pas renvoyés : conserver leur correspondance côté application cliente.

## Relances sans doublon

Choisir une `Idempotency-Key` stable pour **un événement précis**, par exemple `rdv-123-reminder-v1` : 1 à 128 lettres/chiffres ou `._:-`. La conserver lors de toute relance après timeout ou erreur réseau.

- Même client + application + clé + contenu normalisé : **200**, même identifiant, `replayed: true`, sans nouvelle demande.
- Même clé et contenu différent : **409**, aucun remplacement. Une nouvelle version métier doit recevoir une nouvelle clé.
- Clé différente : nouvelle demande, même si `external_reference` est identique.
- Ordre des variables ou notation équivalente du fuseau : même contenu. Omettre `schedule_at` ou le fournir à `null` est équivalent.
- Une relance d’une demande planifiée reste acceptée après sa date, si son contenu est inchangé.

La protection s’appuie sur un verrou transactionnel et une contrainte unique PostgreSQL. Elle reste active aussi longtemps que la ligne est conservée. Elle garantit l’unicité des demandes enregistrées ; la garantie d’envoi chez le futur fournisseur sera traitée avec ce fournisseur.

## Consulter

`GET /api/v1/notifications/{id}` renvoie **200** avec `data` au même format, sans `replayed`. Une clé d’une autre application ou d’un autre client obtient **404**. La console web permet aux membres autorisés d’un client de consulter les demandes de toutes ses applications, avec les numéros masqués.

| État | Signification |
|---|---|
| pending | Enregistrée, pas encore publiée dans Redis. |
| scheduled | Attend la date demandée. |
| queued | Publication dans Redis tentée ; attend le worker ou une reprise. |
| awaiting_provider | Traitée par le worker, attend la future connexion du fournisseur. Aucun envoi. |
| blocked | Traitement interdit : client désactivé ou configuration/restrictions du pilote. Voir `error_code`. |
| sending | Appel Meta commencé ; aucun nouvel appel automatique pour cette demande. |
| submitted | Demande acceptée par Meta ; ne confirme pas la livraison. |
| failed | Refus HTTP Meta ; aucun renvoi automatique. |
| delivery_unknown | Résultat incertain à vérifier, aucun renvoi automatique. |

Une réactivation du client ne relance pas automatiquement une demande bloquée. Aucun bouton de renvoi, d’annulation ni aucun envoi automatique des demandes `awaiting_provider` n’existe dans ce jalon.

## Résilience et confidentialité

La notification et l’audit sont enregistrés dans une seule transaction PostgreSQL avant publication Redis. Un échec Redis laisse la demande durable en base. Le scheduler recherche chaque minute les demandes dues et les publications de plus de cinq minutes, puis republie au maximum 500 identifiants par passage. Un worker lent ou une panne peuvent donc retarder le traitement ; `schedule_at` est une date au plus tôt, pas une garantie de ponctualité.

Les traitements répétés du même identifiant sont sans effet après préparation. Une erreur interne du worker peut être retentée par la file puis par le scheduler ; les échecs Laravel restent consultables avec les outils habituels. La table constitue la source de reprise, pas Redis seul.

Numéro, variables et référence externe sont chiffrés en base. Redis contient uniquement l’identifiant interne du travail. L’audit ne reçoit ni numéro ni variables. Le journal d’une panne Redis ne contient que la référence publique. Les clés d’idempotence sont stockées sous forme d’empreintes, et le contenu utilise une empreinte HMAC pour la comparaison. La sauvegarde de `APP_KEY` est nécessaire ; les données déchiffrées restent accessibles au processus Laravel autorisé.

## Codes à gérer côté Dolibarr

- 200 / 202 : enregistrer l’identifiant retourné, puis consulter son état.
- 401 : clé invalide/expirée/révoquée ou client désactivé.
- 404 : demande inconnue ou hors du périmètre de la clé.
- 409 : clé d’idempotence réutilisée pour un contenu différent.
- 422 : corriger les champs indiqués dans `errors`.
- 429 : attendre le délai `Retry-After`, puis reprendre avec la même clé d’idempotence.
- 5xx / timeout : relancer avec la même clé d’idempotence et le même contenu.

## Essai depuis le VPS

Après le [déploiement](deployment.md), saisir le secret dans le terminal, sans le coller dans une conversation. Ne pas réutiliser les données réelles d’un patient pour cet essai. Exemple pour Bash :

```bash
read -rsp 'Clé API : ' RELAXIT_API_KEY
printf '\n'
curl --silent --show-error --include \
  https://notify.relaxit.pro/api/v1/notifications \
  -H "Authorization: Bearer $RELAXIT_API_KEY" \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: demo-notification-001' \
  --data '{"recipient":"+221770000001","channel":"whatsapp","template":"appointment_reminder","variables":{"name":"Exemple"},"external_reference":"demo-001"}'
unset RELAXIT_API_KEY
```

Rejouer exactement cette demande donne le même identifiant. Après le passage du worker, Clients → Globale Santé → Notifications affiche « En attente du fournisseur ». Le module Dolimed et le transport Meta sont les étapes suivantes.
