# Pilote Meta WhatsApp — RelaxIT Notify

Le mode `pilot`, conservé par défaut, limite les envois à un client, un destinataire et un modèle. Le mode `production` permet plusieurs destinataires pour le client et l’application configurés. Les envois restent désactivés par défaut. La configuration du numéro et son abonnement aux webhooks doivent être validés chez Meta avant activation.

## Ouverture aux patients de Globale Santé

La branche `codex/whatsapp-production-guardrails` inclut l'en-tête image et ajoute un mode explicite. Après déploiement suivant [deployment.md](deployment.md), configurer :

```dotenv
META_WHATSAPP_MODE=production
META_WHATSAPP_APPLICATION=dolibarr
META_WHATSAPP_MAX_ATTEMPTS_PER_24H=250
```

Conserver `META_WHATSAPP_TENANT_CODE=GLOBALE_SANTE`, le modèle approuvé, sa langue, ses trois variables et l'URL de l'image. `META_WHATSAPP_TEST_RECIPIENT` reste enregistré pour revenir au pilote, mais ne limite plus les destinataires en production. Une valeur de mode autre que `pilot` ou `production` bloque les envois. Le client actif, l'application et le modèle restent contrôlés. Mettre une nouvelle date UTC dans `META_WHATSAPP_ENABLED_AFTER` au lancement pour ne pas ouvrir un ancien lot en attente.

Le consentement est vérifié dans Dolimed Notif à la création, puis juste avant transmission à RelaxIT : case d'accord WhatsApp cochée, mobile international valide, rendez-vous futur `AC_RDV` rattaché au tiers. RelaxIT ne lit pas la fiche patient et ne revérifie pas un consentement retiré après transmission. Les clés API `dolibarr` doivent rester réservées à ce connecteur ; ce mode n'ajoute pas de registre central de consentement ni de traitement des réponses entrantes.

### Plafond local et suivi

Le plafond est **un nombre de tentatives d'envoi sur 24 heures glissantes**, de 1 à 250, commun à toute cette installation RelaxIT. Il compte chaque prise en charge `send_started_at`, y compris les échecs, les résultats incertains et plusieurs messages au même patient. Les anciennes tentatives du pilote sont incluses. Il ne se remet pas à zéro à minuit et n'est pas remis à zéro par un changement de numéro ou de client. Une réservation transactionnelle PostgreSQL empêche deux workers de dépasser simultanément le plafond. Le compteur devient durable avant l'appel Meta ; une interruption consomme donc aussi une tentative par prudence.

Ce compteur prudent n'est **pas une lecture du quota Meta** et ne mesure pas les destinataires uniques. Il ne voit pas les envois effectués depuis d'autres outils ou installations : réduire le plafond si le compte est partagé, et continuer à surveiller la limite dans Meta. Ne pas supprimer l'historique des dernières 24 heures pour réinitialiser ce compteur.

Une demande excédentaire devient `blocked` avec `whatsapp_daily_limit_reached`, visible sur la notification et dans l'API. Aucun appel Meta n'est effectué. Elle n'est pas automatiquement réactivée lorsque la capacité revient : éviter une confirmation envoyée tardivement. Dolimed récupère cet état lors de sa synchronisation. Traiter le rendez-vous concerné manuellement, sans créer de doublons pour contourner le plafond. Les nouveaux rendez-vous pourront repartir quand des tentatives sortiront de la fenêtre des 24 heures.

```bash
docker compose exec app php artisan relaxit:whatsapp-status
```

La commande indique le mode et `Tentatives locales sur 24 h : N / 250`, sans numéro ni secret. Pour suspendre les envois, mettre `META_WHATSAPP_ENABLED=false`, refaire le cache et redémarrer les workers. Pour revenir au destinataire unique, mettre `META_WHATSAPP_MODE=pilot` avec un `META_WHATSAPP_TEST_RECIPIENT` valide et recharger de la même manière.

### Recette après ouverture

- Créer un rendez-vous fictif futur avec un second numéro de test consenti, différent du destinataire historique du pilote.
- Vérifier l'image, le texte, puis `Lu` dans RelaxIT et Dolimed après synchronisation.
- Avec un tiers de test sans accord WhatsApp, vérifier qu'aucune demande n'est créée dans Dolimed Notif.
- Vérifier que le compteur local augmente pour le premier envoi uniquement. Les tests automatisés couvrent le plafond sans envoyer des centaines de messages réels.

## Périmètre

- Modèles avec paramètres texte positionnels dans le corps (ou sans paramètres), avec un en-tête image facultatif configuré sur le serveur. Les modèles à paramètres nommés, autres en-têtes média, boutons dynamiques et les messages libres ne sont pas pris en charge dans ce pilote.
- Les demandes créées avant la date d’activation et les anciennes demandes `awaiting_provider` ne sont jamais envoyées automatiquement.
- Un seul appel Meta par tentative ; pas de répétition automatique après timeout, réponse ambiguë ou interruption du worker.
- `submitted` signifie accepté par Meta, pas livré ou lu. Le [webhook de statuts](meta-webhooks.md) permet ensuite de suivre la livraison et la lecture ; vérifier aussi la réception sur le téléphone.
- Aucun stockage de token en base ni affichage dans le navigateur. L’outil de diagnostic ne fait aucun appel réseau et n’affiche que les noms de réglages invalides.

## 1. Déployer le code sans activer les envois

Suivre [deployment.md](deployment.md) sur `codex/meta-webhooks`, avec sauvegarde préalable. La migration ajoute `provider_message_id`, `send_started_at` et `submitted_at`. Conserver `APP_KEY` et les secrets existants.

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

### Modèle avec en-tête image

Si le modèle approuvé contient `HEADER / IMAGE`, ajouter dans `app/.env` :

```dotenv
META_WHATSAPP_HEADER_IMAGE_URL="https://votre-domaine.example/images/logo.png"
```

Remplacer cette URL illustrative par un lien direct HTTPS public vers une image JPEG ou PNG accessible à Meta sans authentification (5 Mo maximum). Utiliser une URL stable sous votre contrôle, pas le lien temporaire `example.header_handle` renvoyé par Meta. L'image d'exemple du modèle ne remplace pas l'image à fournir à chaque envoi.

RelaxIT transmet cette URL à Meta dans `header.parameters`, puis les variables texte dans `body.parameters`. L'image est commune au modèle du pilote ; Dolimed continue d'envoyer uniquement ses trois variables. Laisser la variable vide pour un modèle sans en-tête image. Le diagnostic local vérifie la forme de l'URL, pas son accessibilité ni le format réel du fichier.

Après modification : `docker compose exec app php artisan config:cache`, puis `docker compose restart app worker scheduler`. Créer un nouveau rendez-vous fictif pour la recette ; les notifications déjà en échec ne sont pas renvoyées automatiquement.

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

Le diagnostic doit afficher « Envois activés » et « Mode : pilote » et « Configuration locale complète ». Il ne valide pas la connectivité réseau ou le token chez Meta. Si le token temporaire a expiré, en générer un nouveau dans Meta et refaire le cache/restart. Le passage à un token d’exploitation sera traité avant la production.

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
| blocked / outside_whatsapp_pilot | Vérifier le client et, en mode pilote, le destinataire. |
| blocked / whatsapp_application_not_allowed | Vérifier que la clé API appartient à l’application autorisée. |
| blocked / whatsapp_daily_limit_reached | Plafond local de tentatives atteint ; traiter le rendez-vous manuellement, sans relance automatique. |
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
