# Maildesk

Application Laravel 12 / Vue 3 indépendante pour plusieurs sociétés et leurs boîtes email partagées.

## Fonctionnalités

- Création d’une société, administrateurs et membres ; accès contrôlé par société et par boîte.
- Configuration et modification des connexions Hestia : IMAP SSL 993, SMTP STARTTLS 587 ou SSL 465. Certificat TLS vérifié ; serveurs à adresse IPv4 publique uniquement.
- Synchronisation incrémentale d’INBOX par lots de 50 messages, toutes les minutes ou sur demande. La première synchronisation importe progressivement l’historique, des plus anciens aux plus récents. Les suppressions/déplacements effectués dans le webmail ne sont pas répliqués dans cette archive locale.
- Recherche, pagination, lecture en texte ou en HTML isolé, pièces jointes en stockage privé et téléchargement contrôlé. Les nouveaux emails synchronisés conservent une version HTML épurée si elle existe ; les emails déjà importés conservent leur texte.
- Nouveau message, réponse avec référence au message initial et transfert avec pièces jointes ; À et Cc.
- Ajout de destinataires en copie cachée (Cci) et d’une signature personnelle en texte dans Mon profil.
- Suivi d’équipe par message : attribution à un lecteur autorisé de la boîte, statut de traitement et notes internes.
- Réception, archives et corbeille réversible dans Maildesk ; ce classement ne déplace ni ne supprime les messages sur le serveur IMAP.
- File d’attente SMTP, auteur authentifié de l’envoi, copie dans le dossier IMAP configuré (Sent par défaut).
- Première ouverture enregistrée séparément pour chaque utilisateur, sans changer le statut lu du serveur IMAP. Les actions effectuées directement dans un autre client mail n’identifient pas les utilisateurs Maildesk.
- Permissions configurables par l’administrateur : lecture, envoi, synchronisation, consultation des auteurs et lecteurs, tableau de bord, gestion des utilisateurs et gestion des boîtes. Les droits de messagerie restent limités aux boîtes attribuées.
- Envoyés affiche les messages créés dans Maildesk. Les messages envoyés depuis un autre client ne sont pas importés.
- Diagnostics de synchronisation distincts pour les identifiants, le certificat et le réseau ; une synchronisation réussie efface l’erreur. Les détails sensibles du transport ne sont pas enregistrés.
- Les nouveaux rapports de non-remise RFC 3464 reçus dans INBOX sont reliés à l’email d’origine par Message-ID, boîte et destinataire. Envoyés affiche « Non-remise signalée » et permet d’ouvrir le dernier rapport correspondant, sans renvoi automatique.
- Envoi optionnel par l’API Resend avec identifiant fournisseur et tag de corrélation ; webhooks signés pour envoi, livraison, retard, refus, échec et suppression. La livraison est suivie par destinataire, indépendamment du statut d’envoi et des lectures dans Maildesk.

## Tableau de bord et permissions

Les utilisateurs autorisés accèdent au **Tableau de bord** : totaux envoyés/reçus, reçus sans ouverture Maildesk, erreurs/refus/envois incertains, livraisons confirmées et file d’attente. Les résultats sont limités à la société et, pour les membres, aux boîtes attribuées. Ils peuvent être filtrés par boîte et période. « Envoyés » compte les emails acceptés pour envoi, y compris ceux dont la livraison a ensuite été refusée ; « livrés » exige une confirmation du fournisseur.

Le **Journal d’activité** indique l’auteur de la demande d’envoi et les utilisateurs ayant ouvert chaque email, avec leur première date d’ouverture. Il propose recherche, pagination et filtres par utilisateur/type. **Voir l’activité** ouvre l’historique sans enregistrer une nouvelle lecture. Les statistiques par membre portent sur les emails de la période sélectionnée. Les ouvertures dans un client externe ou chez le destinataire ne sont pas attribuées aux utilisateurs Maildesk. Le tableau de bord et le journal exigent leur permission dédiée ; les identités et compteurs individuels exigent en plus les droits d’activité correspondants.

Dans **Utilisateurs → Permissions**, l’administrateur peut accorder ou retirer chaque droit à un membre. La gestion déléguée des utilisateurs permet de créer des comptes membres sans leur attribuer de rôle administrateur ni de permissions. La gestion des boîtes concerne la configuration et les accès aux boîtes de la société.

## Comptes utilisateurs

**Mon profil** permet à chaque utilisateur de modifier son nom, son adresse de connexion et son mot de passe. Un changement d’adresse exige le mot de passe actuel et invalide les anciens liens de récupération. Les changements d’adresse ou de mot de passe déconnectent les autres sessions.

Dans **Utilisateurs**, seul un administrateur de la société peut modifier un autre compte et le désactiver/réactiver. La désactivation conserve l’historique et les accès attribués, invalide les sessions et annule les emails encore en attente. Un envoi déjà commencé ne peut pas être annulé. La réactivation exige une nouvelle connexion ; elle ne relance aucun email annulé. Un administrateur ne peut pas désactiver son propre compte et la société conserve au moins un administrateur actif. La gestion déléguée garde ses droits de création, sans modification ni désactivation des comptes existants.

**Mot de passe oublié ?** envoie un lien temporaire à usage unique via le transport mail Laravel. La réponse publique ne révèle pas si l’adresse existe. Les comptes désactivés ne reçoivent pas de lien. Configurer `APP_URL` avec l’URL publique HTTPS et le transport d’envoi de l’application, distinct des connexions aux boîtes partagées :

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=notifications@example.com
MAIL_FROM_NAME=Maildesk
```

Adapter ces valeurs au fournisseur et à une adresse d’envoi autorisée. Avec `MAIL_MAILER=log` en développement, le lien est écrit dans le journal local : aucun email n’est délivré. Les liens expirent après 60 minutes par défaut (`config/auth.php`).

## Démarrage local

Avec Laravel Herd, le site est accessible à l’adresse correspondant au dossier du projet. Il n’est pas nécessaire de démarrer un serveur PHP supplémentaire.

```sh
cd maildesk
./start.sh
```

Le script lance le worker et le planificateur, à garder ouverts. Il ne faut qu’une instance du script. Après modification du frontend : `npm run build`. Après modification des jobs : `php artisan queue:restart`. SQLite est utilisé pour le développement ; MySQL peut être configuré via les variables DB pour le déploiement.

Configurer une boîte depuis l’interface, puis lancer Synchroniser. Renseigner le nom réel du dossier Envoyés (Sent, INBOX.Sent, etc.). Le certificat du serveur doit correspondre au nom renseigné. Ne pas désactiver sa vérification.

## Validation

`php artisan test --filter='WorkspaceTest|MailFlowTest|MailGatewayTest|DeliveryReportTest|MailFailureTest|ResendWebhookTest|ResendMailSenderTest|DashboardTest|PermissionTest'`

`node --test tests/js/*.test.js`

Tests en base isolée : séparation des sociétés, accès/révocation, lectures par utilisateur, protection des pièces jointes, idempotence des envois, échec SMTP, échec de copie IMAP, décodage MIME réel avec connexion IMAP simulée et construction des emails sortants. L’interface est vérifiée avec des données simulées. Ces tests ne prouvent pas une connexion à Hestia ou la livraison réelle.

Le suivi IMAP des non-remises concerne uniquement les rapports structurés effectivement reçus et synchronisés après cette fonctionnalité. Le suivi Resend ci-dessous complète ces rapports. Aucun rapprochement par objet ou par texte libre n’est effectué.

## Envoi avec Resend

Configurer dans `.env` :

```dotenv
RESEND_API_KEY=
RESEND_SEND_VIA_API=true
RESEND_WEBHOOK_SECRET=
```

Renseigner la clé API et le secret de signature fournis par Resend. L’envoi API doit également être autorisé par l’opérateur pour chaque boîte. Sans cette autorisation, la boîte conserve son envoi SMTP Hestia. La réception et la copie Sent utilisent toujours les identifiants IMAP de la boîte.

Le webhook est `POST /webhooks/resend`. Abonner Resend aux événements `email.sent`, `email.delivered`, `email.delivery_delayed`, `email.bounced`, `email.failed`, `email.suppressed`. La clé de signature se configure séparément de la clé API. Le serveur vérifie la signature Svix sur les octets bruts et une tolérance de 5 minutes ; lui seul est exempté de CSRF. Les événements sont dédupliqués et ne relancent jamais l’envoi. Un retard ne remplace pas une livraison confirmée et une livraison tardive ne masque pas un refus. Les événements destinés à d’autres applications/sociétés sont ignorés.

Configurer dans Resend l’URL HTTPS de l’application suivie de `/webhooks/resend`.

## Exploitation

- Conserver APP_KEY en sécurité : elle chiffre les mots de passe des boîtes. Sauvegarder la base et storage/app/private ensemble.
- En production, HTTPS, APP_DEBUG=false, worker supervisé et cron Laravel sont nécessaires. DB_QUEUE_RETRY_AFTER doit être supérieur au délai maximal des jobs (360 secondes configuré, timeout 240 secondes).
- Les uploads sont limités à 10 fichiers, 10 Mo chacun et 20 Mo au total. Les limites de PHP et du proxy doivent être adaptées : upload_max_filesize au moins 10M, post_max_size supérieur à 20M.
- Un statut « Accepté par le serveur » prouve l’acceptation SMTP, pas la livraison au destinataire. En cas d’envoi non confirmé, contrôler le serveur avant un nouvel envoi : aucune relance SMTP automatique n’est effectuée.
- Les fichiers sont servis en téléchargement, jamais exécutés ou affichés comme HTML. Une politique d’analyse antivirus et de rétention doit être décidée avant un usage public à grande échelle.
