# Suivi des livraisons WhatsApp

Le pilote envoie déjà des messages. Ce jalon reçoit les statuts Meta pour afficher « Envoyée », « Livrée », « Lue » et les échecs dans RelaxIT Notify. Il ne traite pas les conversations entrantes.

## 1. Déployer

Suivre [deployment.md](deployment.md) sur la branche `codex/meta-webhooks`. Les migrations ajoutent une table de réception et des colonnes de suivi, sans modifier les comptes existants. Le redémarrage de Nginx applique aussi l’exclusion du webhook de son journal d’accès.

Conserver tous les paramètres du pilote qui fonctionne, notamment le token d’accès, le modèle, les variables et `META_WHATSAPP_ENABLED_AFTER`. Ne pas recommencer l’inscription du numéro professionnel sur téléphone.

## 2. Configurer les secrets sur le serveur

Ajouter dans `app/.env` :

```dotenv
META_WHATSAPP_WEBHOOK_ENABLED=true
META_WHATSAPP_WABA_ID=5182491892029942
META_WHATSAPP_VERIFY_TOKEN=REMPLACER_PAR_UN_SECRET_ALEATOIRE
META_WHATSAPP_APP_SECRET=REMPLACER_PAR_LE_SECRET_DE_L_APPLICATION_META
```

Le WABA ci-dessus correspond au compte de test utilisé pendant le pilote : vérifier sa valeur dans Meta. Conserver `META_WHATSAPP_PHONE_NUMBER_ID=1365911333266978` si ce numéro de test est toujours l’expéditeur.

Générer le token de vérification avec `openssl rand -hex 32`, puis copier sa valeur dans le fichier et dans Meta. Le secret d’application se trouve dans les paramètres de base de l’application Meta. Il est distinct du token d’accès utilisé pour envoyer les messages. Saisir les secrets uniquement sur le serveur et dans Meta, sans les publier dans Git ou dans une conversation. Les intermédiaires comme Traefik ne doivent pas journaliser les paramètres de vérification de cette URL.

Appliquer la configuration :

```bash
docker compose exec app php artisan config:cache
docker compose restart app worker scheduler
docker compose exec app php artisan relaxit:whatsapp-status
docker compose exec app php artisan schedule:list
```

Le contrôle doit indiquer les webhooks activés et une configuration locale complète. Il ne vérifie pas les secrets auprès de Meta. Le scheduler doit présenter `relaxit:queue-notifications` et `relaxit:reconcile-meta-webhooks`.

## 3. Enregistrer le callback chez Meta

Dans la configuration WhatsApp/Webhooks de la même application Meta :

1. Renseigner l’URL de rappel : `https://notify.relaxit.pro/api/webhooks/whatsapp`.
2. Renseigner comme token de vérification la valeur exacte de `META_WHATSAPP_VERIFY_TOKEN`.
3. Vérifier et enregistrer, puis abonner le champ **messages**, qui transporte aussi les statuts de livraison.
4. Vérifier que l’application est abonnée au WABA concerné. Si nécessaire, suivre la procédure Meta d’abonnement de l’application au compte WhatsApp Business (`/{WABA-ID}/subscribed_apps`).

Un accès manuel à l’URL sans les paramètres Meta renvoie normalement 403. Un webhook désactivé ou incomplet renvoie 503. Un POST sans signature valide renvoie 403. Ces réponses ne sont pas un test de livraison.

## 4. Vérifier un nouveau message

Envoyer une nouvelle demande de démonstration via RelaxIT Notify, avec une nouvelle clé d’idempotence, en reprenant le modèle et les trois variables du [pilote](meta-whatsapp.md). Un ancien message ne garantit pas de nouveaux événements après l’abonnement.

Dans Clients → Globale Santé → Notifications, actualiser après une minute. Le statut peut passer directement à « Livrée » ou « Lue » si les événements arrivent rapidement. Ouvrir le message sur le téléphone ; la lecture n’apparaîtra que si Meta transmet cet événement. Le journal d’audit et l’API de consultation reflètent aussi les changements.

Pour déclencher le rapprochement sans attendre :

```bash
docker compose exec app php artisan relaxit:reconcile-meta-webhooks
docker compose logs scheduler --tail=50
```

Si le statut reste « Acceptée par Meta », vérifier l’abonnement `messages`, le WABA, le Phone Number ID et le secret de la même application. Ne pas renvoyer le message uniquement pour corriger son suivi. Les messages envoyés depuis la console Meta ne correspondent pas nécessairement à une notification RelaxIT.

## Traitement et limites

- Le GET de vérification compare le token. Chaque POST vérifie `X-Hub-Signature-256` par HMAC SHA-256 sur le corps original, avec le secret d’application.
- Seuls les statuts du WABA et du numéro configurés sont enregistrés. Le corps brut, les messages entrants, les coordonnées et les descriptions d’erreur Meta ne sont pas conservés dans la table de réception.
- L’accusé de réception 200 intervient après l’écriture en base. Une panne de stockage renvoie une erreur ; les doublons sont absorbés par une empreinte unique.
- Le scheduler rapproche au maximum 500 événements par minute avec l’identifiant Meta exact (`wamid`). Un événement reçu avant l’enregistrement de cet identifiant est retenté après cinq minutes. Aucun rapprochement approximatif par destinataire n’est effectué.
- Les dates de chaque type de statut conservent la première occurrence connue. La priorité est lecture, livraison, échec, puis envoi : un événement retardé ne fait pas régresser une lecture. Les dates manquantes restent vides.
- Les nouveaux envois mémorisent leur compte et numéro expéditeur. Les envois du pilote antérieurs à ce jalon utilisent la configuration actuelle pour ce contrôle.
- Les événements non rapprochés sont supprimés après sept jours ; ceux traités après trente jours. Les statuts finaux des notifications et leur audit restent conservés. Si un timeout d’envoi a empêché de connaître le `wamid`, le callback seul ne permet pas de retrouver la demande.
- Le suivi peut rester actif lorsque les nouveaux envois sont désactivés. Il ne déclenche aucun renvoi automatique.

Références Meta : [vérification et signature des webhooks](https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/webhooks/start/), [format des événements](https://www.postman.com/meta/whatsapp-business-platform/folder/tduohwq/webhook-payload-reference), [objet statuses](https://www.postman.com/meta/whatsapp-business-platform/folder/fuaee8l/statuses-object).
