# Guide Agents - MelodyQuest API

Ce depot contient le backend PHP de MelodyQuest. Il doit rester deployable dans `P:\PROD\API\melodyquest` sans copier de fichiers de developpement en production.

## Priorite produit

MelodyQuest est le seul produit en developpement continu, mais une idee
documentee n'est pas une commande de travail. Exiger une priorisation explicite
et un besoin concret avant d'ajouter endpoint, table, worker, flux temps reel ou
dependance. Preferer le plus petit changement complet.

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
- `bin\`: worker CLI runtime de reprise de l'outbox Mercure.
- `config\`: constantes runtime non secretes et lecture env.
- `controllers\`: validation payload et reponses.
- `middlewares\`: auth session, identite joueur compte/invite et permissions.
- `repositories\`: persistance specialisee, notamment historique et outbox temps reel.
- `services\`: logique metier, DB, Mercure, outbox, suggestions et TV.
- `utils\`: helpers request/response/YouTube et travaux post-reponse.
- `sql\`: migrations source, a ne pas deployer en runtime public.
- `scripts\`: outils CLI source, a ne pas deployer en runtime public.
- `tests\`: suite PHP locale, a ne pas deployer.

Ne pas recreer d'anciens dossiers `Controller`, `Service`, `Repository` ou `Infrastructure`.

## Etat a preserver

- Modes de salon: `participative` et `autoplay`.
- Le mode `autoplay` est passif: pas de score, pas de vote, pas de reponse attendue.
- La categorie visible et la precision `80%` sont les valeurs par defaut des nouveaux salons.
- La presence est manuelle: `active` ou `away`.
- Les joueurs absents ne bloquent pas les votes/transitions et recoivent un bonus selon config.
- Le createur peut exclure un joueur sans detruire son historique de score.
- Les tentatives de reponse restent en DB pour admin/statistiques.
- Toute partie avec au moins une manche est copiee dans `mq_game_session_*` avant reset, suppression, fermeture ou purge.
- Les snapshots de session sont append-only et ne doivent pas recevoir de FK vers les tables live.
- Les suggestions joueurs peuvent etre editees et appliquees au catalogue.
- La TV ne signale plus `markTvRoundReady`; ne pas restaurer cette action sans nouvelle analyse.
- YouTube reste la source principale; ne pas ajouter de stockage audio local.
- Les commandes HTTP ne publient jamais directement vers Mercure: elles alimentent `mq_realtime_outbox`.
- Les parcours joueur acceptent un compte ou une session invitee; le management exige toujours un compte autorise.
- Une identite joueur utilise `actor_id`: positif pour `users.id`, negatif pour `-mq_guest_sessions.id`, jamais `0` cote serveur.
- Ne jamais creer de ligne `users` pour un invite ni donner de permission admin a une session invitee.
- Les `user_id` restent `NULL` pour les invites; conserver les snapshots de nom dans les historiques.

## Auth, permissions et DB

- Auth via session `sid`, tables `auth_sessions` et `users`.
- Invites via cookie HttpOnly `mq_guest` et table temporaire `mq_guest_sessions`, TTL glissant de 2 heures par defaut.
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
- La migration `019_melodyquest_realtime_outbox.sql` doit etre appliquee avant de deployer le code d'outbox.
- La migration `020_melodyquest_guest_players.sql` doit etre appliquee avant le runtime invite; elle ne supprime aucune donnee de jeu.
- Les lignes sont coalescees par flux et acquittees avec leur generation; ne pas remplacer ce mecanisme par un `publish()` synchrone dans un controleur.
- Le drainage normal commence seulement apres `fastcgi_finish_request()`; un echec du hub ne doit pas faire echouer la commande metier.
- `bin\process_realtime_outbox.php` est le filet CLI de reprise et peut etre supervise en mode `--loop` si necessaire.

## Verifications

```powershell
Get-ChildItem P:\DEV\GitHub\App-MelodyQuest-API -Recurse -Filter *.php | % { php -l $_.FullName }
php P:\DEV\GitHub\App-MelodyQuest-API\tests\run.php
git -c safe.directory=* diff --check
rg -n "password|passwd|secret|BEGIN (RSA|OPENSSH|PRIVATE)|api_key" P:\DEV\GitHub\App-MelodyQuest-API
```

## Deploiement

Copier uniquement le runtime necessaire vers `P:\PROD\API\melodyquest`:

- `index.php`
- `bin\`
- `config\`
- `controllers\`
- `middlewares\`
- `repositories\`
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
