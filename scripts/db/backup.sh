#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."

# * Réutilise les credentials déjà présents dans .env.local.php (généré par `composer dump-env`, voir
# * .github/workflows/main.yml) -- aucun secret dupliqué à gérer côté serveur.
ENV_VALUES=$(php -r '
$env = require ".env.local.php";
$db = parse_url($env["DATABASE_URL"]);
echo $db["host"], "\n", $db["port"] ?? 5432, "\n", $db["user"], "\n", $db["pass"], "\n", ltrim($db["path"], "/"), "\n";
echo $env["STORAGE_BUCKET"], "\n", $env["STORAGE_ENDPOINT"], "\n", $env["STORAGE_KEY"], "\n", $env["STORAGE_SECRET"], "\n";
')

PGHOST=$(sed -n '1p' <<< "$ENV_VALUES")
PGPORT=$(sed -n '2p' <<< "$ENV_VALUES")
PGUSER=$(sed -n '3p' <<< "$ENV_VALUES")
PGPASSWORD=$(sed -n '4p' <<< "$ENV_VALUES")
PGDATABASE=$(sed -n '5p' <<< "$ENV_VALUES")
STORAGE_BUCKET=$(sed -n '6p' <<< "$ENV_VALUES")
STORAGE_ENDPOINT=$(sed -n '7p' <<< "$ENV_VALUES")
export PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE
export AWS_ACCESS_KEY_ID=$(sed -n '8p' <<< "$ENV_VALUES")
export AWS_SECRET_ACCESS_KEY=$(sed -n '9p' <<< "$ENV_VALUES")

TIMESTAMP=$(date -u +%Y%m%d_%H%M%S)
DUMP_FILE="/tmp/trouvemoi_agri_${TIMESTAMP}.sql.gz"

pg_dump --no-owner --no-privileges | gzip > "$DUMP_FILE"
aws s3 cp "$DUMP_FILE" "s3://${STORAGE_BUCKET}/backups/$(basename "$DUMP_FILE")" --endpoint-url "$STORAGE_ENDPOINT"
rm -f "$DUMP_FILE"

echo "Sauvegarde envoyée : $(basename "$DUMP_FILE")"

# * Rétention 30 jours (cahier DevOps) -- rotation par nom de fichier plutôt qu'une politique de lifecycle
# * S3, pour rester portable quel que soit le fournisseur S3-compatible réellement utilisé en production.
#
# ! Isolée dans un sous-shell avec `||` : la sauvegarde elle-même est déjà terminée et envoyée à ce stade.
# ! Sans cette isolation, un `set -e`/`pipefail` déclenché par un souci passager de purge (bucket
# ! momentanément indisponible, etc.) ferait échouer tout le script -- donc l'étape backup.yml -- et
# ! déclencherait une fausse alerte "sauvegarde échouée" alors que la sauvegarde a réellement réussi.
RETENTION_DAYS=30
CUTOFF=$(date -u -d "-${RETENTION_DAYS} days" +%Y%m%d)
(
    aws s3 ls "s3://${STORAGE_BUCKET}/backups/" --endpoint-url "$STORAGE_ENDPOINT" | while read -r _ _ _ filename; do
        file_date=$(grep -oE '[0-9]{8}' <<< "$filename" | head -n 1)
        if [[ -n "$file_date" && "$file_date" < "$CUTOFF" ]]; then
            aws s3 rm "s3://${STORAGE_BUCKET}/backups/${filename}" --endpoint-url "$STORAGE_ENDPOINT"
        fi
    done
) || echo "Avertissement : la purge des sauvegardes de plus de ${RETENTION_DAYS} jours a échoué (non bloquant)." >&2