# Runbook — Sauvegardes PostgreSQL

Cahier des charges DevOps : "PostgreSQL dump logique — quotidienne — rétention 7 à 30 jours" et "tester
régulièrement les restaurations, pas uniquement les sauvegardes."

## Ce qui existe

| Élément | Fichier | Fréquence | Où ça s'exécute |
|---|---|---|---|
| Sauvegarde | [`scripts/db/backup.sh`](../../scripts/db/backup.sh) déclenché par [`.github/workflows/backup.yml`](../../.github/workflows/backup.yml) | Quotidienne (2h30 UTC) | Sur le serveur de production, via SSH |
| Test de restauration | [`.github/workflows/restore-test.yml`](../../.github/workflows/restore-test.yml) | Hebdomadaire (lundi 6h UTC) | Dans le runner CI, sur une base PostgreSQL jetable — jamais sur le serveur réel |

Les deux workflows acceptent aussi un déclenchement manuel (`workflow_dispatch`) depuis l'onglet Actions
de GitHub.

## Comment ça marche

1. `backup.sh` lit les identifiants de connexion (base de données + bucket S3-compatible) directement
   depuis `.env.local.php`, déjà présent sur le serveur (généré par `composer dump-env` lors du déploiement,
   voir `.github/workflows/main.yml`). Aucun secret n'est dupliqué côté serveur.
2. Il produit un `pg_dump` compressé (`trouvemoi_agri_AAAAMMJJ_HHMMSS.sql.gz`) et l'envoie dans le bucket
   S3-compatible déjà utilisé pour les médias producteurs, sous le préfixe `backups/`.
3. Il purge ensuite les sauvegardes de plus de 30 jours sur ce même bucket. Cette purge est volontairement
   isolée (sous-shell + `||`) : si elle échoue, elle ne fait pas échouer tout le job — la sauvegarde du jour
   a déjà réussi à ce moment-là, un souci de purge ne doit pas déclencher une fausse alerte "sauvegarde
   échouée".
4. `restore-test.yml` télécharge la sauvegarde la plus récente du bucket, la restaure dans une base
   PostgreSQL/PostGIS jetable créée pour l'occasion dans le runner CI, puis vérifie qu'elle contient au
   moins un utilisateur (`identity.users`). Un dump vide ou corrompu fait échouer le job.

## Alerte "sauvegarde échouée"

Pas de canal d'alerte dédié (Slack/PagerDuty) configuré sur ce projet. L'alerte actuelle est l'email que
GitHub Actions envoie automatiquement aux mainteneurs du dépôt quand un workflow planifié échoue — le même
mécanisme qui prévient déjà en cas d'échec de `unittest.yml`. Si ce n'est pas suffisant en production, ajouter
une étape de notification (webhook Slack, par exemple) dans les deux workflows sur `if: failure()`.

## Restaurer une vraie sauvegarde en cas d'incident

`backup.sh` ne fait que produire des sauvegardes — il ne restaure jamais rien automatiquement sur le
serveur réel, par sécurité. En cas de besoin réel :

1. Se connecter au serveur de production (accès restreint, cf. cahier DevOps — "aucun accès direct permanent
   sans procédure formalisée").
2. Identifier le point de restauration voulu :
   ```bash
   aws s3 ls s3://$STORAGE_BUCKET/backups/ --endpoint-url $STORAGE_ENDPOINT
   ```
3. Télécharger et décompresser la sauvegarde choisie :
   ```bash
   aws s3 cp s3://$STORAGE_BUCKET/backups/<fichier>.sql.gz . --endpoint-url $STORAGE_ENDPOINT
   gunzip <fichier>.sql.gz
   ```
4. **Ne jamais restaurer directement sur la base de production sans passer par une base temporaire d'abord**
   (cahier DevOps, étape 3 du runbook de restauration général) :
   ```bash
   createdb restauration_verif
   psql -d restauration_verif -f <fichier>.sql
   ```
5. Vérifier l'intégrité applicative sur cette base temporaire (nombre d'utilisateurs, de demandes, etc.)
   avant toute bascule.
6. Seulement après validation, basculer ou restaurer la production selon la gravité de l'incident, puis
   rédiger un post-mortem (cahier DevOps, "Runbook restauration").

## Prérequis pour que tout fonctionne

- Sur le serveur de production : `pg_dump`, `gzip`, `aws` (AWS CLI) installés et accessibles dans le PATH.
- Secrets GitHub Actions déjà utilisés par `backup.yml` : `APP_PASS`, `APP_USER`, `APP_HOST` (déjà en place,
  réutilisés depuis `main.yml`).
- Secrets GitHub Actions **à ajouter** pour `restore-test.yml` (celui-ci s'exécute dans le runner CI, pas sur
  le serveur, donc il ne peut pas lire `.env.local.php`) : `STORAGE_KEY`, `STORAGE_SECRET`, `STORAGE_BUCKET`,
  `STORAGE_ENDPOINT` — mêmes noms que dans `.env`, valeurs de production. Sans ça, `restore-test.yml` échoue
  systématiquement (mais de façon visible : la commande `aws s3 ls` renverrait un accès refusé).

## Limites connues

- Sauvegarde logique uniquement (`pg_dump`) : pas de PITR (Point-In-Time Recovery / archivage WAL). En cas
  d'incident entre deux sauvegardes quotidiennes, la perte de données possible va jusqu'à ~24h (RPO). Le
  cahier DevOps l'accepte explicitement pour le MVP ("RPO 24h, RTO 4-8h").
- Un seul bucket, pas de réplication géographique des sauvegardes elles-mêmes.
- La purge de rétention (30 jours) est une boucle `aws s3 rm` fichier par fichier plutôt qu'une politique de
  lifecycle S3 côté fournisseur — plus lente sur un très grand nombre de fichiers, mais portable quel que
  soit le fournisseur S3-compatible réellement utilisé.
