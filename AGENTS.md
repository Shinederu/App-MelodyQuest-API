# Guide Agents - MelodyQuest API

Ce depot contient le backend PHP extrait de MelodyQuest. Il doit rester deployable dans le runtime stable `P:\PROD\API\melodyquest` sans copier de fichiers de developpement en production.

## Lecture de demarrage

1. Lire `P:\AGENTS.md`.
2. Lire `P:\ECOSYSTEM.md`.
3. Lire `P:\DEV\GitHub\AGENTS.md`.
4. Lire ce fichier.
5. Lire `README.md`.
6. Lire les migrations `sql\` si le changement touche la DB.

## Source de verite

- Backend DEV: `P:\DEV\GitHub\App-MelodyQuest-API`
- Backend PROD: `P:\PROD\API\melodyquest`
- Frontend DEV: `P:\DEV\GitHub\App-MelodyQuest`
- Endpoint API: `https://api.shinederu.ch/melodyquest/`
- Code projet: `melodyquest`
- Tables DB: `mq_*` dans `ShinedeCore`
- Permission admin catalogue: `melodyquest.catalog.manage`

## Structure

- `index.php`: routeur API par `action`.
- `config\`: constantes runtime non secretes et lecture env.
- `controllers\`: validation payload et reponses.
- `middlewares\`: auth session et permissions.
- `services\`: logique metier, DB, Mercure.
- `utils\`: helpers request/response/YouTube.
- `sql\`: migrations source, a ne pas deployer en runtime public.
- `scripts\`: outils CLI source, a ne pas deployer en runtime public.

Ne pas recreer d'anciens dossiers `Controller`, `Service`, `Repository` ou `Infrastructure` dans ce repo ou en PROD.

## Auth, permissions et DB

- Auth via session `sid`, tables `auth_sessions` et `users`.
- Config runtime partagee avec `Module-Auth-API` quand l'autoload Auth est present en PROD.
- Permissions via `Module-ShinedeCore-PHP`, deploye sous `P:\PROD\API\core`.
- Verifier les droits avec `hasPermission($userId, 'melodyquest', '<permission>')`.
- Ne pas ecrire dans les tables d'un autre projet hors contrat documente.
- Les migrations MelodyQuest doivent rester idempotentes quand c'est possible.

## Temps reel

- Transport principal: Mercure.
- Topics:
  - `https://api.shinederu.ch/melodyquest/topics/public-lobbies`
  - `https://api.shinederu.ch/melodyquest/topics/lobbies/{LOBBY_CODE}`
- Il n'y a pas de fallback SSE supporte dans l'API actuelle.
- Toute information temps reel doit pouvoir etre reconstruite via API HTTP (`listPublicLobbies`, `getLobbyByCode`, `getRoundState`).

## Etat TV a preserver

Depuis le 2026-06-12, le mode TV frontend utilise un lecteur YouTube simple. L'action experimentale `markTvRoundReady`, le demarrage accelere par signal "TV prete" et les constantes associees ont ete retires. Ne pas les restaurer sans nouvelle analyse.

## Verifications

```powershell
Get-ChildItem P:\DEV\GitHub\App-MelodyQuest-API -Recurse -Filter *.php | % { php -l $_.FullName }
git -c safe.directory=* diff --check
rg -n "password|passwd|secret|BEGIN (RSA|OPENSSH|PRIVATE)|api_key" P:\DEV\GitHub\App-MelodyQuest-API
```

## Deploiement

Copier uniquement le runtime necessaire vers `P:\PROD\API\melodyquest`:

- `index.php`
- `config\`
- `controllers\`
- `middlewares\`
- `services\`
- `utils\`

Ne pas deployer:

- `.git`, `.github`
- `README.md`, `AGENTS.md`, `PROD_TEST_CHECKLIST.md`
- `.env.example`
- `sql\`
- `scripts\`
- tests, caches, brouillons, exports temporaires

Preserver les fichiers runtime existants si presents (`.env`, logs, caches runtime). Ne jamais copier de secret depuis DEV vers PROD.
