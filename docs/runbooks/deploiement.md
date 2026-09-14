# Runbook — Déploiement et rollback

Cahier des charges DevOps : "Déploiements et stratégie de release" (procédure type production en 9
étapes + rollback) et "Documentation, runbooks et support technique" (contenu minimal d'un runbook
déploiement : "Procédure release, rollback, smoke tests, responsabilités").

## Ce qui existe

| Élément | Fichier | Déclenchement |
|---|---|---|
| Déploiement | [`.github/workflows/main.yml`](../../.github/workflows/main.yml) (workflow "CD") | Automatique dès que le workflow "Unit Tests" ([`unittest.yml`](../../.github/workflows/unittest.yml)) réussit sur `master`, ou manuellement (`workflow_dispatch`) depuis l'onglet Actions de GitHub |

Il n'existe qu'**un seul environnement distant réellement déployé** aujourd'hui : le serveur
`admin-agriculture.trouvemoi.com`, accédé par SSH. `docs/runbooks/backup.md` l'appelle "le serveur de
production" ; c'est la convention reprise ici. Voir "Écarts avec le cahier DevOps" plus bas : le
cahier prévoit cinq environnements séparés (Local, CI, Staging, Préproduction, Production), ce projet
n'en a qu'un de déployé automatiquement.

## Comment ça marche

À chaque push sur `master` dont les tests passent (ou déclenchement manuel), `main.yml` :

1. Enregistre la clé d'hôte SSH réelle du serveur (`ssh-keyscan`) avant de s'y connecter -- au lieu de
   désactiver la vérification, comme c'était le cas avant.
2. Se connecte en SSH (port 22, mot de passe via `sshpass`) et exécute, dans l'ordre :
   1. `git pull origin master`
   2. `composer install --no-interaction --prefer-dist --optimize-autoloader`
   3. `composer dump-env prod` (compile `.env.local.php` en environnement `prod` -- voir
      `docs/runbooks/backup.md` pour comment `backup.sh` réutilise ensuite ce fichier)
   4. `bin/console doctrine:migrations:migrate --no-interaction --env=prod`
   5. `bin/console cache:clear --no-interaction --env=prod`
   6. `bin/console cache:warmup --no-interaction --env=prod`
   7. `bin/console assets:install public --no-interaction --env=prod`
   8. `bin/console importmap:install --no-interaction --env=prod`
   9. `bin/console asset-map:compile --env=prod`

C'est un déploiement de type **rolling** au sens du cahier ("remplacement progressif... MVP si
l'application est stateless") : pas de bascule bleu/vert, l'application est indisponible ou instable
pendant la fenêtre `composer install` + `cache:clear`, mais l'API est bien stateless (JWT), donc c'est
cohérent avec la recommandation MVP du cahier.

### Pourquoi `--env=prod` (et pas `dev`, comme avant)

Le serveur tournait jusqu'ici en environnement Symfony `dev`. Deux conséquences concrètes, pas
seulement théoriques :

- `config/packages/flysystem.yaml` bascule sur un adaptateur **disque local** en `when@dev`, avec une
  URL publique codée en dur `http://localhost:8000/dev-storage` -- inutilisable depuis l'extérieur.
  Autrement dit, les photos producteur et pièces jointes de messagerie étaient très probablement
  enregistrées sur le disque du serveur avec des URLs cassées, au lieu du bucket S3-compatible
  (`config/packages/flysystem.yaml`, storages `producer_media.storage`/`message_attachments.storage`,
  adaptateur `aws` en dehors de `when@dev`/`when@test`).
- `config/packages/web_profiler.yaml` active la barre de debug et le profiler en `when@dev`, et
  `security.yaml` laisse `^/(_profiler|_wdt|assets|build)/` en accès public sur le firewall `dev` --
  ce qui exposait potentiellement `/_profiler` sans authentification sur un serveur joignable
  publiquement.

**Prérequis pour que `--env=prod` fonctionne correctement** : les variables `STORAGE_BUCKET`,
`STORAGE_BUCKET_ATTACHMENTS`, `STORAGE_PUBLIC_URL`, `STORAGE_KEY`, `STORAGE_SECRET`,
`STORAGE_ENDPOINT`, `STORAGE_REGION` doivent être correctement renseignées dans le `.env`/
`.env.local.php` du serveur (elles servaient déjà à `backup.sh` pour le bucket de sauvegardes -- à
vérifier que ce sont bien les bonnes valeurs pour le bucket de médias producteur, pas seulement pour
celui des sauvegardes). Si elles manquent ou sont fausses, l'upload de photo/pièce jointe échouera
**de façon visible** (erreur S3) au lieu de produire silencieusement une URL `localhost` cassée comme
avant -- c'est un progrès, mais à surveiller sur le premier déploiement suivant ce changement.

Autre effet de bord à connaître : `config/packages/monolog.yaml` écrit les logs applicatifs sur
`php://stderr` (formaté JSON) en `when@prod`, alors qu'en `dev` ils allaient dans
`var/log/dev.log` (celui que `make logs` surveille). Sur ce serveur non conteneurisé, il faut donc
vérifier où le processus PHP-FPM/Apache redirige sa sortie d'erreur standard pour retrouver les logs
applicatifs après ce changement -- `make logs` (qui tail `var/log/dev.log`) ne les montrera plus.

## Procédure de déploiement (checklist cahier DevOps)

Le cahier liste 9 étapes pour un déploiement type production. État réel de chacune sur ce projet :

| # | Étape (cahier) | État sur ce projet |
|---|---|---|
| 1 | Release validée en préproduction | ⚠️ Pas de préproduction séparée -- les tests CI (`unittest.yml`) sur `master` tiennent lieu de validation. |
| 2 | Créer/identifier le tag Git de release | ⚠️ Pas de tag sémantique. Le déploiement pointe sur le SHA exact de commit qui a passé les tests (ou sur `master` en cas de déclenchement manuel) -- un identifiant immuable, mais pas un tag lisible. |
| 3 | Construire des images Docker immuables | ❌ Pas d'image : déploiement par `git pull` direct sur le serveur. `compose.yaml`/`compose.override.yaml` existent mais seulement pour le développement local (PostgreSQL, MinIO), pas pour le déploiement. |
| 4 | Exécuter les migrations en mode sécurisé | ✅ `doctrine:migrations:migrate --env=prod` fait partie du pipeline. |
| 5 | Déployer les services applicatifs | ✅ `composer install` + cache warmup. |
| 6 | Vider/réchauffer les caches | ✅ Fait une seule fois (plus de doublon). |
| 7 | Smoke tests production | ❌ Aucun test automatique après déploiement. Voir "Smoke tests manuels" ci-dessous. |
| 8 | Surveiller métriques/logs pendant la stabilisation | ❌ Pas d'observabilité en place (item déjà suivi dans `TODO.md`, bloc DevOps). |
| 9 | Documenter le résultat de la release | ⚠️ L'historique Git + les logs du workflow GitHub Actions en tiennent lieu ; pas de changelog technique dédié. |

### Smoke tests manuels

En l'absence d'étape 7 automatisée, vérifier au minimum après chaque déploiement :

```bash
curl -sf https://admin-agriculture.trouvemoi.com/api/categories > /dev/null && echo OK
curl -sf https://admin-agriculture.trouvemoi.com/admin/login > /dev/null && echo OK
```

`GET /api/categories` est une route publique qui interroge réellement la base (catalogue) --
un échec ici indique un problème de connexion DB ou une erreur 500 applicative. `/admin/login`
confirme que le back-office répond. Vérifier aussi qu'un upload de photo/pièce jointe fonctionne
réellement après le premier déploiement suivant le passage à `--env=prod` (voir ci-dessus).

## Rollback

Le cahier exige que "tout déploiement puisse être annulé ou corrigé rapidement" et que "le rollback
applicatif puisse revenir à l'image Docker précédente". Sans image Docker ici, le rollback se fait au
niveau Git :

1. Identifier le dernier commit `master` connu comme sain (celui déployé avant la release
   problématique -- consulter l'historique du workflow "CD" dans l'onglet Actions de GitHub).
2. Revenir à ce commit sur `master` **via un `git revert`** (pas un `reset --hard`), pour que
   l'historique reste lisible et que le prochain déploiement automatique ne redéploie pas la version
   cassée :
   ```bash
   git revert <sha-du-commit-fautif>
   git push origin master
   ```
   Ce push relance automatiquement le pipeline "CD" comme n'importe quel push sur `master` --
   pas besoin d'intervenir manuellement sur le serveur dans le cas courant.
3. Si une intervention manuelle immédiate est nécessaire avant qu'un `git revert` ne soit prêt (le
   pipeline ne permet pas de cibler un SHA arbitraire autre que `master`, voir "Écarts") :
   ```bash
   ssh -p 22 <user>@<host>
   cd ~/var/www/vhosts/trouvemoi.com/admin-agriculture.trouvemoi.com
   git fetch origin
   git checkout <sha-du-dernier-déploiement-sain>   # laisse le dépôt en HEAD détachée
   composer install --no-interaction --prefer-dist --optimize-autoloader
   bin/console cache:clear --no-interaction --env=prod
   bin/console cache:warmup --no-interaction --env=prod
   ```
   Important : après une intervention manuelle de ce type, remettre le dépôt sur la branche
   `master` (`git checkout master && git reset --hard origin/master`) avant le prochain déploiement
   automatique, sans quoi `git pull origin master` échouera ("HEAD détachée").
4. **Migrations** : le cahier est explicite -- "les migrations irréversibles doivent être évitées ou
   découpées en étapes compatibles" et "une sauvegarde doit exister avant toute migration sensible".
   Concrètement ici :
   - Si la release annulée n'a *pas* ajouté de migration : rien à faire côté base.
   - Si elle *a* ajouté une migration purement additive (nouvelle table, nouvel index, nouvelle
     colonne nullable) : le code revenu en arrière l'ignore simplement, pas besoin de la défaire.
   - Si la migration a modifié ou supprimé des données/colonnes existantes : ne pas lancer
     `doctrine:migrations:migrate prev` à l'aveugle. Restaurer plutôt depuis la sauvegarde
     précédant cette migration (`docs/runbooks/backup.md`), après avoir vérifié l'intégrité sur une
     base temporaire comme décrit dans ce runbook.
5. Rejouer les smoke tests manuels ci-dessus.
6. Documenter l'incident (cause, détection, correction) -- pas de gabarit de post-mortem existant sur
   ce projet, un simple message dans le canal de suivi de l'équipe suffit pour l'instant.

**Flags fonctionnels** : le cahier recommande de pouvoir "désactiver une fonctionnalité sans
redéployer". Aucun mécanisme de feature flag n'existe sur ce projet -- toute fonctionnalité
problématique ne peut être désactivée qu'en revenant en arrière comme ci-dessus.

## Responsabilités

Aucune astreinte formalisée n'existe sur ce projet. Le déploiement est automatique (push sur
`master`) ou manuel (`workflow_dispatch`) ; un rollback nécessite les identifiants SSH du serveur
(secrets GitHub Actions `APP_USER`/`APP_PASS`/`APP_HOST`, déjà utilisés par `main.yml` et
`backup.yml`). Le job est rattaché à un environnement GitHub nommé `production` : ajouter des
réviseurs obligatoires depuis Réglages > Environments du dépôt pour imposer une validation humaine
avant chaque déploiement, si souhaité (rien n'est configuré par défaut).

## Écarts avec le cahier DevOps

- **Pas d'image Docker versionnée** -- le cahier en fait un livrable explicite ("Chaque image Docker
  doit être taguée avec le numéro de version, le hash Git et l'environnement cible"). Ce projet déploie
  le code source directement sur le serveur.
- **Un seul environnement distant** au lieu des cinq prévus par le cahier (Local/CI/Staging/
  Préproduction/Production) -- pas de distinction entre "validation avant prod" et "prod" elle-même.
- **Pas de tag Git de release** -- le SHA de commit sert d'identifiant, ce qui est immuable mais moins
  lisible qu'un tag sémantique pour retrouver "la version déployée le 12 septembre" par exemple. Cette
  limite empêche aussi de cibler un SHA arbitraire pour un rollback via le workflow lui-même (voir
  "Rollback", étape 3).
- **Pas de smoke tests automatisés**, pas de surveillance pendant la stabilisation post-déploiement --
  lié à l'absence de monitoring déjà suivie dans `TODO.md`.
- **Pas de mécanisme de feature flag.**
- **SSH par mot de passe, pas par clé** -- le cahier liste explicitement "SSH par clé" dans ses
  exigences de sécurité Infrastructure. Décision consciente pour l'instant : la migration vers une
  authentification par clé demande de générer une paire de clés, d'installer la partie publique sur le
  serveur et d'ajouter la clé privée comme secret GitHub -- des actions côté infrastructure qui
  restent à faire séparément.
- **Pas de validation humaine obligatoire avant déploiement** -- le job est rattaché à un environnement
  GitHub (`production`) qui permet d'ajouter cette exigence, mais aucune règle de protection n'est
  configurée pour l'instant (voir "Responsabilités").
