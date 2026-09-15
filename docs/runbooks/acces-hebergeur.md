# Besoins côté hébergement — TrouveMoi Agri

Document à transmettre au responsable de l'hébergement (serveur `admin-agriculture.trouvemoi.com`,
Plesk). Il liste ce dont l'équipe technique a besoin, par priorité, pour finaliser le déploiement
et confirmer que la production est correctement configurée.

## Contexte rapide

Le déploiement automatique (CI/CD) était bloqué depuis un moment par plusieurs problèmes
d'infrastructure (mauvais port SSH, chemin de déploiement incorrect, dépôt Git jamais initialisé
sur le serveur) — **tous corrigés de notre côté**, aucune action requise ici. Le pipeline va
maintenant jusqu'aux migrations de base de données, où il bute sur un point qui, lui, nécessite une
action côté serveur (point 1 ci-dessous).

---

## 1. Installer l'extension PostgreSQL PostGIS (bloquant, priorité haute)

Les migrations de la base échouent avec :
```
SQLSTATE[0A000]: Feature not supported: 7 ERROR: extension "postgis" is not available
DETAIL: Could not open extension control file "/usr/share/postgresql/16/extension/postgis.control"
```

PostGIS n'est pas installé sur le serveur PostgreSQL (version 16, Ubuntu 24.04). C'est
indispensable au matching géographique (recherche de producteurs par distance), une fonctionnalité
centrale de la plateforme. Merci d'installer, sur le serveur qui héberge PostgreSQL :

```bash
sudo apt-get update
sudo apt-get install -y postgresql-16-postgis-3
```

(Si ce paquet exact n'est pas trouvé, `apt-cache search postgis` listera le nom correspondant à ce
serveur.) Aucune autre action nécessaire ensuite de notre côté : le prochain déploiement reprendra
les migrations automatiquement.

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

*Document mis à jour le 2026-09-15. Contact technique : équipe de développement TrouveMoi Agri.*
