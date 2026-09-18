# Besoins côté hébergement — TrouveMoi Agri

Document à transmettre au responsable de l'hébergement (serveur `admin-agriculture.trouvemoi.com`,
Plesk). Il liste ce dont l'équipe technique a besoin, par priorité, pour finaliser le déploiement
et confirmer que la production est correctement configurée.

## Contexte rapide

Le déploiement automatique (CI/CD) était bloqué depuis un moment par plusieurs problèmes
d'infrastructure (mauvais port SSH, chemin de déploiement incorrect, dépôt Git jamais initialisé
sur le serveur) — **tous corrigés de notre côté**, aucune action requise ici. Le pipeline allait
ensuite jusqu'aux migrations de base de données, où il butait sur le point 1 ci-dessous
(PostGIS) : **confirmé réglé côté hébergeur le 2026-09-18**. Reste à relancer un déploiement complet
pour confirmer que tout va bien de bout en bout (pas encore reconfirmé par un run réel depuis la
levée du blocage).

---

## 1. Installer l'extension PostgreSQL PostGIS -- ✅ réglé (2026-09-18)

Les migrations de la base échouaient avec :
```
SQLSTATE[0A000]: Feature not supported: 7 ERROR: extension "postgis" is not available
DETAIL: Could not open extension control file "/usr/share/postgresql/16/extension/postgis.control"
```

PostGIS (indispensable au matching géographique -- recherche de producteurs par distance, une
fonctionnalité centrale de la plateforme) n'était pas installé sur le serveur PostgreSQL (version
16, Ubuntu 24.04). **Confirmé installé côté hébergeur le 2026-09-18.** Reste à relancer le
déploiement (`workflow_dispatch` sur `main.yml`, ou un nouveau push sur `master`) pour confirmer
que les migrations passent désormais en entier -- pas encore reconfirmé par un run réel.

## 2. Vérifier `.env.local` sur le serveur (priorité haute)

Fichier : `/var/www/vhosts/trouvemoi.com/admin-agriculture.trouvemoi.com/.env.local`

Merci de confirmer que chacune de ces variables a une vraie valeur de production (pas vide, pas
une valeur de test/développement) :

| Variable | Rôle |
|---|---|
| `APP_ENV` | Doit valoir `prod` |
| `APP_SECRET` | Doit être une valeur aléatoire non vide |
| `DATABASE_URL` | Connexion PostgreSQL réelle (utilisateur, mot de passe, hôte, nom de base) |
| `JWT_PASSPHRASE` | Actuellement manquante ou vide — l'authentification de toute l'API en dépend, elle est cassée sans ça |
| `MAILER_DSN` | DSN SMTP réel (la valeur par défaut ne permet aucun envoi d'email réel) |
| `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` | Clés Stripe de production |
| `STORAGE_ENDPOINT` / `STORAGE_REGION` / `STORAGE_BUCKET` / `STORAGE_BUCKET_ATTACHMENTS` / `STORAGE_KEY` / `STORAGE_SECRET` / `STORAGE_PUBLIC_URL` | Accès au bucket de stockage réel (photos producteur, pièces jointes, sauvegardes) |
| `CORS_ALLOWED_ORIGINS` | Doit lister le vrai domaine du site, pas une adresse locale |
| `DEFAULT_URI` | Doit être l'URL réelle de l'API, pas une adresse locale |

## 3. Confirmer la présence des clés JWT (priorité haute)

Les fichiers suivants doivent exister physiquement sur le serveur, dans le dossier de
l'application :
- `config/jwt/private.pem`
- `config/jwt/public.pem`

S'ils sont absents, l'authentification de l'API restera impossible même une fois `.env.local`
corrigé.

## 4. Emplacement des logs applicatifs

Une fois l'environnement de production correctement actif, où consulte-t-on les logs applicatifs
de la plateforme (Symfony/PHP-FPM) ? Utile pour documenter la procédure de diagnostic en cas
d'incident.

## 5. Confirmation de l'environnement

Confirmer qu'il n'existe qu'un seul environnement serveur (`admin-agriculture.trouvemoi.com`),
sans environnement de test/staging séparé — pour mise à jour de notre documentation interne.

## 6. Accès SSH par clé (à prévoir, pas urgent)

Le déploiement se connecte aujourd'hui par mot de passe, avec un compte disposant apparemment de
droits élevés (root ou équivalent — confirmé par plusieurs indices techniques côté déploiement).
Dès que possible, nous souhaiterions passer à une authentification par clé SSH : nous fournirons
une clé publique à installer sur le compte de déploiement, en remplacement du mot de passe actuel.

---

## Message prêt à transmettre au responsable de l'hébergement

> Bonjour,
>
> Merci d'avoir installé PostGIS — c'est confirmé de notre côté. Voici où nous en sommes sur le
> déploiement de TrouveMoi Agri, et ce dont nous avons encore besoin pour finaliser la mise en
> production.
>
> **Déjà réglé** (aucune action de votre part) : le pipeline de déploiement automatique était
> bloqué par plusieurs problèmes (mauvais port SSH, mauvais chemin sur le serveur, dépôt Git jamais
> initialisé, droits Composer) puis par l'extension PostGIS manquante — tout est désormais réglé.
> Nous allons relancer un déploiement complet pour confirmer que tout va bien de bout en bout.
>
> **Ce qu'il nous reste à vérifier/configurer avec vous :**
>
> 1. **Vérifier le fichier `.env.local`** sur le serveur
>    (`/var/www/vhosts/trouvemoi.com/admin-agriculture.trouvemoi.com/.env.local`) et confirmer que
>    ces variables ont bien une vraie valeur de production (pas vide, pas une valeur de test) :
>    `APP_ENV` (doit être `prod`), `APP_SECRET`, `DATABASE_URL`, `JWT_PASSPHRASE`, `MAILER_DSN`,
>    `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STORAGE_ENDPOINT`, `STORAGE_REGION`,
>    `STORAGE_BUCKET`, `STORAGE_BUCKET_ATTACHMENTS`, `STORAGE_KEY`, `STORAGE_SECRET`,
>    `CORS_ALLOWED_ORIGINS`, `DEFAULT_URI`.
>
> 2. **Confirmer que les clés JWT existent** sur le serveur :
>    `config/jwt/private.pem` et `config/jwt/public.pem`, dans le dossier de l'application.
>
> 3. **Nous indiquer où consulter les logs applicatifs** (Symfony/PHP-FPM) une fois le
>    déploiement en production actif.
>
> 4. **Confirmer qu'il n'existe qu'un seul environnement serveur**
>    (`admin-agriculture.trouvemoi.com`), sans staging/préprod séparée, pour notre documentation.
>
> 5. **Accès SSH par clé** (pas urgent, à prévoir) : le déploiement se connecte aujourd'hui par
>    mot de passe. Dès que possible, nous vous fournirons une clé publique à installer sur le
>    compte de déploiement, pour remplacer l'authentification par mot de passe.
>
> N'hésitez pas à nous solliciter si un point n'est pas clair. Merci d'avance !

---

*Document mis à jour le 2026-09-18. Contact technique : équipe de développement TrouveMoi Agri.*
