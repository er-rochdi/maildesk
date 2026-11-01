# Maildesk

Application Laravel 12 / Vue 3 indépendante pour plusieurs sociétés et leurs boîtes email partagées.

## Fonctionnalités

- Création d’une société, administrateurs et membres ; accès contrôlé par société et par boîte.
- Configuration et modification des connexions Hestia : IMAP SSL 993, SMTP STARTTLS 587 ou SSL 465. Certificat TLS vérifié ; serveurs à adresse IPv4 publique uniquement.
- Synchronisation incrémentale d’INBOX par lots de 50 messages, toutes les minutes ou sur demande. La première synchronisation importe progressivement l’historique, des plus anciens aux plus récents. Les suppressions/déplacements effectués dans le webmail ne sont pas répliqués dans cette archive locale.
- Recherche, pagination, lecture en texte, pièces jointes en stockage privé et téléchargement contrôlé.
- Nouveau message, réponse avec référence au message initial et transfert avec pièces jointes ; À et Cc.
- File d’attente SMTP, auteur authentifié de l’envoi, copie dans le dossier IMAP configuré (Sent par défaut).
- Première ouverture enregistrée séparément pour chaque utilisateur, sans changer le statut lu du serveur IMAP. Les actions effectuées directement dans un autre client mail n’identifient pas les utilisateurs Maildesk.
- Envoyés affiche les messages créés dans Maildesk. Les messages envoyés depuis un autre client ne sont pas importés.
- Diagnostics de synchronisation distincts pour les identifiants, le certificat et le réseau ; une synchronisation réussie efface l’erreur. Les détails sensibles du transport ne sont pas enregistrés.
- Les nouveaux rapports de non-remise RFC 3464 reçus dans INBOX sont reliés à l’email d’origine par Message-ID, boîte et destinataire. Envoyés affiche « Non-remise signalée » et permet d’ouvrir le dernier rapport correspondant, sans renvoi automatique.
- Envoi optionnel par l’API Resend avec identifiant fournisseur et tag de corrélation ; webhooks signés pour envoi, livraison, retard, refus, échec et suppression. La livraison est suivie par destinataire, indépendamment du statut d’envoi et des lectures dans Maildesk.

## Dashboard administrateur

Les administrateurs arrivent sur **Tableau de bord** : totaux envoyés/reçus, reçus sans ouverture Maildesk, erreurs/refus/envois incertains, livraisons confirmées et file d’attente. Les comptes sont limités à leur société et peuvent être filtrés par boîte et période. « Envoyés » compte les emails acceptés pour envoi, y compris ceux dont la livraison a ensuite été refusée ; « livrés » exige une confirmation du fournisseur.

Le **Journal d’activité** indique l’auteur de la demande d’envoi et les utilisateurs ayant ouvert chaque email, avec leur première date d’ouverture. Il propose recherche, pagination et filtres par utilisateur/type. **Voir l’activité** ouvre l’historique sans enregistrer une nouvelle lecture. Les statistiques par membre portent sur les emails de la période sélectionnée. Les ouvertures dans un client externe ou chez le destinataire ne sont pas attribuées aux utilisateurs Maildesk. Les membres non administrateurs conservent leur boîte de réception et ne peuvent pas appeler les endpoints du dashboard.

## Démarrage local

Le site est servi par Herd : http://maildesk.test. Ne pas démarrer de serveur PHP supplémentaire.

```sh
cd /Users/kara/Herd/project_codex/maildesk
./start.sh
```

Le script lance le worker et le planificateur, à garder ouverts. Il ne faut qu’une instance du script. Après modification du frontend : `npm run build`. Après modification des jobs : `php artisan queue:restart`. SQLite est utilisé pour le développement ; MySQL peut être configuré via les variables DB pour le déploiement.

Configurer une boîte depuis l’interface, puis lancer Synchroniser. Renseigner le nom réel du dossier Envoyés (Sent, INBOX.Sent, etc.). Le certificat du serveur doit correspondre au nom renseigné. Ne pas désactiver sa vérification.

## Validation

`php artisan test --filter='WorkspaceTest|MailFlowTest|MailGatewayTest|DeliveryReportTest|MailFailureTest|ResendWebhookTest|ResendMailSenderTest|DashboardTest'`

`node --test tests/js/*.test.js`

Tests en base isolée : séparation des sociétés, accès/révocation, lectures par utilisateur, protection des pièces jointes, idempotence des envois, échec SMTP, échec de copie IMAP, décodage MIME réel avec connexion IMAP simulée et construction des emails sortants. L’interface est vérifiée avec des données simulées. Ces tests ne prouvent pas une connexion à Hestia ou la livraison réelle.

Validation réelle du 31 octobre 2026 : nouveau message avec fichier, réponse d’un second acteur de test, transfert du fichier vers contact@bestoasis.ma, SMTP Hestia et retour IMAP ; contenu du fichier identique et copie Sent réussie. Base de test en mémoire, aucun utilisateur de test ajouté à la société réelle. Trois emails portant le préfixe « Maildesk QA » restent dans la boîte réelle. Cette boucle locale au domaine ne valide pas la livraison vers Gmail via Resend.

Le suivi IMAP des non-remises concerne uniquement les rapports structurés effectivement reçus et synchronisés après cette fonctionnalité. Le suivi Resend ci-dessous complète ces rapports. Aucun rapprochement par objet ou par texte libre n’est effectué.

## Resend et webhooks locaux

Configurer dans `.env` :

```dotenv
RESEND_API_KEY=cle_privee_resend
RESEND_SEND_VIA_API=true
RESEND_WEBHOOK_SECRET=whsec_secret_du_webhook
```

L’envoi API doit également être autorisé par l’opérateur pour chaque boîte (`resend_enabled=true` en base). Ce champ n’est ni assignable en masse ni modifiable par les formulaires/API des sociétés : une nouvelle société ne peut pas utiliser la clé globale pour usurper une adresse vérifiée. Sans cette autorisation, la boîte conserve son envoi SMTP Hestia. La réception et la copie Sent utilisent toujours les identifiants IMAP de la boîte.

Le webhook est `POST /webhooks/resend`. Abonner Resend aux événements `email.sent`, `email.delivered`, `email.delivery_delayed`, `email.bounced`, `email.failed`, `email.suppressed`. La clé de signature se configure séparément de la clé API. Le serveur vérifie la signature Svix sur les octets bruts et une tolérance de 5 minutes ; lui seul est exempté de CSRF. Les événements sont dédupliqués et ne relancent jamais l’envoi. Un retard ne remplace pas une livraison confirmée et une livraison tardive ne masque pas un refus. Les événements destinés à d’autres applications/sociétés sont ignorés.

Pour un test sans déploiement, lancer dans deux terminaux :

```sh
node scripts/webhook-proxy.mjs
cloudflared tunnel --url http://127.0.0.1:8789 --no-autoupdate --protocol http2
```

Utiliser le lien HTTPS produit, suivi de `/webhooks/resend`, dans Resend. Le proxy de développement expose uniquement ce webhook et `/health` ; le reste de Maildesk retourne 404. Les pages d’erreur Laravel ne sont pas transmises. Le lien change lorsqu’un nouveau tunnel est créé : mettre à jour l’URL dans Resend, conserver sa clé de signature, et laisser le Mac et les deux processus allumés pendant les tests. Le binaire utilisé durant la validation est téléchargé dans `/tmp/maildesk-webhook-tools/cloudflared`, sans installation globale.

Validation du 31 octobre 2026 : envois API vers les adresses officielles `delivered+…@resend.dev` et `bounced+…@resend.dev`, réception de quatre vrais callbacks signés via le tunnel HTTPS, états livré/refusé associés aux bons messages et copies Sent réussies. Ces adresses simulent les issues de livraison chez Resend ; ce test ne prouve pas un placement en boîte de réception Gmail. L’URL temporaire et l’identifiant du webhook de développement se trouvent dans `storage/app/private/resend-webhook.json` (aucune clé dans ce fichier).

## Exploitation

- Conserver APP_KEY en sécurité : elle chiffre les mots de passe des boîtes. Sauvegarder la base et storage/app/private ensemble.
- En production, HTTPS, APP_DEBUG=false, worker supervisé et cron Laravel sont nécessaires. DB_QUEUE_RETRY_AFTER doit être supérieur au délai maximal des jobs (360 secondes configuré, timeout 240 secondes).
- Les uploads sont limités à 10 fichiers, 10 Mo chacun et 20 Mo au total. Les limites de PHP et du proxy doivent être adaptées : upload_max_filesize au moins 10M, post_max_size supérieur à 20M.
- Un statut « Accepté par le serveur » prouve l’acceptation SMTP, pas la livraison au destinataire. En cas d’envoi non confirmé, contrôler le serveur avant un nouvel envoi : aucune relance SMTP automatique n’est effectuée.
- Les fichiers sont servis en téléchargement, jamais exécutés ou affichés comme HTML. Une politique d’analyse antivirus et de rétention doit être décidée avant un usage public à grande échelle.
