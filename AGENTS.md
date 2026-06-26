# Guide Agents - MelodyQuest API

Ce depot contient le backend PHP de MelodyQuest. Il doit rester deployable dans `P:\PROD\API\melodyquest` sans copier de fichiers de developpement en production.

## Lecture de demarrage

1. Lire `P:\AGENTS.md`.
2. Lire `P:\ECOSYSTEM.md`.
3. Lire `P:\DEV\GitHub\AGENTS.md`.
4. Lire ce fichier.
5. Lire `README.md`.
6. Lire `sql\` si le changement touche la DB.
7. Lire le frontend `P:\DEV\GitHub\App-MelodyQuest\README.md` si le changement touche l'UX, les routes, la TV ou le player.

## Source de verite

- Backend DEV: `P:\DEV\GitHub\App-MelodyQuest-API`
- Backend PROD: `P:\PROD\API\melodyquest`
- Frontend DEV: `P:\DEV\GitHub\App-MelodyQuest`
- Frontend PROD: `P:\PROD\MelodyQuest`
- Endpoint API: `https://api.shinederu.ch/melodyquest/`
- Code projet: `melodyquest`
- Tables DB: `mq_*` dans `ShinedeCore`
- Permission admin catalogue: `melodyquest.catalog.manage`

## Structure

- `index.php`: routeur API par `action`.
- `config\`: constantes runtime non secretes et lecture env.
- `controllers\`: validation payload et reponses.
- `middlewares\`: auth session et permissions.
- `services\`: logique metier, DB, Mercure, suggestions, TV.
- `utils\`: helpers request/response/YouTube.
- `sql\`: migrations source, a ne pas deployer en runtime public.
- `scripts\`: outils CLI source, a ne pas deployer en runtime public.

Ne pas recreer d'anciens dossiers `Controller`, `Service`, `Repository` ou `Infrastructure`.

## Etat a preserver

- Modes de salon: `participative` et `autoplay`.
- Le mode `autoplay` est passif: pas de score, pas de vote, pas de reponse attendue.
- La categorie visible et la precision `80%` sont les valeurs par defaut des nouveaux salons.
- La presence est manuelle: `active` ou `away`.
- Les joueurs absents ne bloquent pas les votes/transitions et recoivent un bonus selon config.
- Le createur peut exclure un joueur sans detruire son historique de score.
- Les tentatives de reponse restent en DB pour admin/statistiques.
- Les suggestions joueurs peuvent etre editees et appliquees au catalogue.
- La TV ne signale plus `markTvRoundReady`; ne pas restaurer cette action sans nouvelle analyse.
- YouTube reste la source principale; ne pas ajouter de stockage audio local.

## Auth, permissions et DB

- Auth via session `sid`, tables `auth_sessions` et `users`.
- Config runtime partagee avec `Module-Auth-API` quand l'autoload Auth est present en PROD.
- Permissions via `Module-ShinedeCore-PHP`, deploye sous `P:\PROD\API\core`.
- Verifier les droits avec `hasPermission($userId, 'melodyquest', '<permission>')`.
- Permission admin actuelle: `hasPermission($userId, 'melodyquest', 'catalog.manage')`.
- Ne pas ecrire dans les tables d'un autre projet hors contrat documente.
- Les migrations MelodyQuest doivent rester idempotentes quand c'est possible.
- Ne jamais supprimer de donnees sans demande explicite.

## Temps reel

- Transport principal: Mercure.
- Topics:
  - `https://api.shinederu.ch/melodyquest/topics/public-lobbies`
  - `https://api.shinederu.ch/melodyquest/topics/lobbies/{LOBBY_CODE}`
- Pas de fallback SSE supporte dans l'API actuelle.
- Toute information temps reel doit pouvoir etre reconstruite via HTTP (`listPublicLobbies`, `getLobbyByCode`, `getRoundState`, `getTvState`).

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
