# Pilote Meta WhatsApp — RelaxIT Notify

Le pilote relie une nouvelle notification au numéro de test Meta. Il reste désactivé par défaut et limité à un client, un destinataire et un modèle. Il ne modifie pas le numéro professionnel utilisé dans WhatsApp Business sur téléphone.

## Périmètre

- Modèles avec paramètres texte positionnels dans le corps uniquement (ou sans paramètres). Les modèles à paramètres nommés, en-tête média, boutons dynamiques et les messages libres ne sont pas pris en charge dans ce pilote.
- Les demandes créées avant la date d’activation et les anciennes demandes `awaiting_provider` ne sont jamais envoyées automatiquement.
- Un seul appel Meta par tentative ; pas de répétition automatique après timeout, réponse ambiguë ou interruption du worker.
- `submitted` signifie accepté par Meta, pas livré ou lu. Le webhook de statuts sera une étape distincte ; vérifier la réception sur le téléphone pour ce pilote.
- Aucun stockage de token en base ni affichage dans le navigateur. L’outil de diagnostic ne fait aucun appel réseau et n’affiche que les noms de réglages invalides.

## 1. Déployer le code sans activer les envois

Suivre [deployment.md](deployment.md) sur `codex/meta-whatsapp-pilot`, avec sauvegarde préalable. La migration ajoute `provider_message_id`, `send_started_at` et `submitted_at`. Conserver `APP_KEY` et les secrets existants.

## 2. Renseigner la configuration dans app/.env

Saisir le token directement dans le fichier sur le VPS. Ne pas le transmettre dans la conversation, une capture, une PR ou Git.

```dotenv
META_WHATSAPP_ENABLED=false
META_WHATSAPP_VERSION=v25.0
META_WHATSAPP_PHONE_NUMBER_ID=1365911333266978
META_WHATSAPP_ACCESS_TOKEN="TOKEN_SAISI_SUR_LE_SERVEUR"
META_WHATSAPP_TENANT_CODE=GLOBALE_SANTE
META_WHATSAPP_TEST_RECIPIENT=+221XXXXXXXXX
META_WHATSAPP_ENABLED_AFTER=DATE_UTC_A_RENSEIGNER
META_WHATSAPP_TEMPLATE=nom_exact_du_modele_meta
META_WHATSAPP_LANGUAGE=en_US
META_WHATSAPP_BODY_VARIABLES=
```

Le Phone Number ID provient du numéro de test montré dans la capture. Il est distinct du WABA ID. Utiliser le numéro destinataire déjà autorisé et testé dans Meta, au format international avec `+`.

Copier le nom et la langue EXACTS de l’objet `template` du test Meta reçu. Si son corps comporte des paramètres positionnels, attribuer un nom local à chacun, dans le même ordre : par exemple `META_WHATSAPP_BODY_VARIABLES=name,order_reference` pour deux paramètres. L’API RelaxIT devra recevoir exactement ces clés dans `variables`. Cette liste illustrative n’affirme pas que le modèle de votre compte attend deux paramètres.

Pour un modèle sans paramètres, laisser la liste vide et envoyer `"variables": {}`. Le serveur refuse tout autre modèle et toute variable manquante ou supplémentaire. L’état d’approbation du modèle et la validité du token sont vérifiés par Meta lors de la requête, pas par la commande locale.

## 3. Autoriser la sortie réseau du worker

Le Compose de base conserve son réseau backend interne. L’override ajoute au worker un réseau bridge sortant, sans port publié. PostgreSQL, Redis, app et scheduler conservent leur topologie. Ce réseau autorise une sortie Internet générale du worker, il ne constitue pas un filtre limité aux domaines Meta. Le code appelle uniquement `https://graph.facebook.com` avec TLS vérifié et sans suivre les redirections.

```bash
cd /docker/relaxit-notify
docker compose -f docker-compose.yml -f docker-compose.meta.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.meta.yml up -d --no-deps worker
```

Conserver les deux fichiers `-f` lors des futurs `up` ou recréations du worker ; un `up` avec le seul Compose de base peut retirer sa sortie réseau. Un simple `restart` ne modifie pas les réseaux.

## 4. Activer une nouvelle fenêtre de test

Lire l’heure UTC :

```bash
date -u +%Y-%m-%dT%H:%M:%SZ
```

La copier dans `META_WHATSAPP_ENABLED_AFTER`, puis passer `META_WHATSAPP_ENABLED=true`. Seules les demandes créées STRICTEMENT après cette date pourront être envoyées. Ne pas mettre une ancienne date pour récupérer des demandes en attente.

```bash
docker compose exec app php artisan config:cache
docker compose restart app scheduler
docker compose -f docker-compose.yml -f docker-compose.meta.yml restart worker
docker compose exec app php artisan relaxit:whatsapp-status
```

Le diagnostic doit afficher « Pilote activé » et « Configuration locale complète ». Il ne valide pas la connectivité réseau ou le token chez Meta. Si le token temporaire a expiré, en générer un nouveau dans Meta et refaire le cache/restart. Le passage à un token d’exploitation sera traité avant la production.

## 5. Tester depuis RelaxIT

Utiliser une clé API RelaxIT pour Globale Santé (distincte du token Meta). Reprendre l’exemple de [notifications.md](notifications.md), en remplaçant :

- `recipient` par le destinataire du pilote ;
- `template` par le modèle Meta configuré ;
- `variables` par les paramètres attendus, dans les noms définis en configuration ;
- `Idempotency-Key` par une nouvelle référence pour ce nouvel essai.

Une relance réseau du MÊME essai doit conserver sa clé d’idempotence. Ne pas réutiliser la clé d’une ancienne démonstration : elle retrouverait cette ancienne demande sans envoi.

La demande doit passer par `sending`, puis `submitted`. L’écran indique « Acceptée par Meta », et la consultation API renvoie `provider_message_id`. Confirmer ensuite sa réception sur le téléphone. Les résultats HTTP Meta sont testés automatiquement avec des réponses simulées ; la réception réelle nécessite cette configuration serveur.

## Interpréter les résultats

| Résultat | Action |
|---|---|
| awaiting_provider | Envois désactivés, demande antérieure à la date d’activation ou ancienne demande déjà préparée. |
| blocked / whatsapp_config_invalid | Revoir les champs signalés par `relaxit:whatsapp-status`. |
| blocked / outside_whatsapp_pilot | Vérifier le client et le destinataire. |
| blocked / whatsapp_template_mismatch | Vérifier le nom du modèle et les noms des variables. |
| failed / meta_http_400 | Meta refuse la demande ; comparer le modèle, la langue et les paramètres avec le test réussi. |
| failed / meta_http_401 ou 403 | Vérifier le token, ses droits et l’accès au numéro de test. |
| failed / meta_http_429 | Limitation Meta ; attendre avant un nouvel essai contrôlé. |
| delivery_unknown | Vérifier le téléphone et les outils Meta avant de décider d’un nouvel essai. Aucun renvoi automatique. |

Si le worker est interrompu après le début de l’appel, le scheduler marque la demande incertaine après cinq minutes. Cela couvre aussi le cas où Meta a accepté le message mais où l’enregistrement local a échoué. Ne pas changer sa clé d’idempotence pour tenter aveuglément un nouvel envoi. La coupure du pilote prend effet sur les prochaines prises en charge après recharge de configuration ; un appel déjà commencé peut se terminer.

## Désactiver

Remettre `META_WHATSAPP_ENABLED=false`, reconstruire le cache et redémarrer app, worker et scheduler avec les commandes précédentes. Les demandes déjà acceptées par Meta ne sont pas annulées.

## Référence du contrat Meta

La [collection officielle Meta](https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api) documente `POST /{Phone-Number-ID}/messages`, l’authentification Bearer et la réponse contenant `messages[].id`. La version `v25.0` est celle du test affiché dans l’application Meta de ce projet.
