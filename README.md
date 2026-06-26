# MelodyQuest API

## Role

Cette API est le backend proprietaire de MelodyQuest.

Elle gere:

- salons actifs et passifs;
- manches, tirage musical, reponses, votes et scores;
- catalogue musical;
- validation et corrections admin;
- suggestions joueurs;
- historique des tentatives de reponse;
- presence manuelle;
- liaison TV;
- snapshots Mercure.

Le frontend source vit dans `P:\DEV\GitHub\App-MelodyQuest`.

## Repo et deploiement

- Repo source: `P:\DEV\GitHub\App-MelodyQuest-API`
- Repo GitHub: `https://github.com/Shinederu/App-MelodyQuest-API.git`
- Runtime PROD: `P:\PROD\API\melodyquest`
- Endpoint public: `https://api.shinederu.ch/melodyquest/`
- Code projet stable: `melodyquest`
- Front consommateur: `https://melodyquest.shinederu.ch/`

Le dossier PROD ne doit pas etre un clone du repo.

Fichiers/dossiers runtime autorises en PROD:

- `index.php`
- `config\`
- `controllers\`
- `middlewares\`
- `services\`
- `utils\`

Ne pas deployer en PROD:

- `.git`
- `.github`
- `README.md`
- `AGENTS.md`
- `PROD_TEST_CHECKLIST.md`
- `.env.example`
- `sql\`
- `scripts\`
- tests
- caches
- brouillons
- exports temporaires

## Etat courant

- Deux modes de salon existent:
  - `participative`: mode actif avec reponses, scores, votes et classement;
  - `autoplay`: mode passif avec enchainement automatique, sans score, sans votes et sans reponse attendue.
- Les salons passifs restent de vrais salons: code, membres, partage et liaison TV.
- Les salons passifs sont prives par defaut cote frontend.
- La categorie visible est activee par defaut pour les nouveaux salons.
- La precision des reponses est a `80%` par defaut pour les nouveaux salons.
- La selection musicale est equilibree entre categories selectionnees quand c'est possible.
- La presence joueur est manuelle (`active` / `away`).
- Le createur peut passer un joueur present/absent ou l'exclure.
- Les joueurs absents ne bloquent pas les votes/transitions et recoivent un bonus de compensation.
- Les essais de reponse sont conserves en DB pour admin/statistiques.
- Les suggestions joueurs peuvent etre editees, refusees, marquees traitees ou appliquees directement au catalogue.
- Les avatars historiques `action=getAvatar` sont normalises vers l'API Auth active avant retour frontend.
- Le mode TV frontend utilise un lecteur YouTube simple; l'action experimentale `markTvRoundReady` n'existe plus.

## Contraintes produit

- Auth obligatoire pour jouer.
- Proposition publique de nouvelle musique possible sans session.
- Stockage des pistes par identifiant YouTube; aucun fichier audio local.
- YouTube reste la source principale.
- Commandes metier via HTTP API.
- Mercure publie les snapshots et ne remplace pas les endpoints HTTP.
- Administration musicale reservee a `melodyquest.catalog.manage` ou au super-admin global.
- `users.role='admin'` reste seulement un fallback de transition.

## Structure

- `index.php`: routeur par action/methode HTTP.
- `config\`: configuration runtime non secrete et constantes.
- `controllers\`: validation payload et reponses HTTP.
- `middlewares\`: session auth et permissions.
- `services\`: logique metier, DB, selection, Mercure, suggestions.
- `utils\`: helpers request/response/YouTube.
- `sql\`: migrations source.
- `scripts\`: outils CLI source, notamment import catalogue.

Ne pas recreer d'anciens dossiers `Controller`, `Service`, `Repository` ou `Infrastructure`.

## Base de donnees

Schema partage: `ShinedeCore`.

Tables MelodyQuest: prefixe `mq_*`.

Migrations:

- `001_melodyquest_core.sql`: schema initial categories/familles/pistes/lobbies/manches/scores.
- `002_melodyquest_lobby_settings.sql`: `total_rounds`, categories selectionnees.
- `003_melodyquest_family_aliases.sql`: alias de familles/reponses.
- `004_melodyquest_track_validation.sql`: validation des pistes.
- `005_melodyquest_track_video_id_only.sql`: stockage video YouTube par ID.
- `006_melodyquest_merge_duplicate_categories.sql`: fusion categories dupliquees et normalisation.
- `007_melodyquest_game_options.sql`: options categorie visible, vote reveal et votes de revelation.
- `008_melodyquest_answer_similarity.sql`: seuil de similarite des reponses.
- `009_melodyquest_player_suggestions.sql`: suggestions joueurs et verrous de suggestion.
- `010_melodyquest_tv_pairings.sql`: liaison TV.
- `011_melodyquest_round_preloads.sql`: file de pistes a venir.
- `012_melodyquest_presence_and_attempts.sql`: presence joueur, retrait non destructif et tentatives de reponse.
- `013_melodyquest_answer_similarity_default_80.sql`: seuil par defaut SQL a `80`.
- `014_melodyquest_away_bonus.sql`: bonus absent idempotent.
- `015_melodyquest_autoplay_mode.sql`: `mq_lobbies.game_mode`.
- `016_melodyquest_category_visible_default.sql`: categorie visible par defaut.
- `017_melodyquest_admin_suggestion_review.sql`: champs de revue/application admin des suggestions.

Regles DB:

- Les migrations significatives restent dans `sql\`.
- Les migrations doivent etre idempotentes quand c'est possible.
- Ne jamais supprimer de donnees sans demande explicite.
- Les anciennes donnees de tentatives/reponses sont conservees pour statistiques et analyse admin.

## Import catalogue CSV

Script:

```text
P:\DEV\GitHub\App-MelodyQuest-API\scripts\import_blindtest_catalog.php
```

Mapping applique:

- groupe racine -> `mq_categories`
- `title` source -> `mq_families.name`
- `alternative_title` -> `mq_family_aliases.alias`
- playlist source -> `mq_tracks.title`
- `youtube_url` source -> `mq_tracks.youtube_video_id`
- `preview_start_seconds` -> `mq_tracks.start_offset_seconds`
- `reveal_start_seconds` est ignore

Commandes utiles:

```powershell
php P:\DEV\GitHub\App-MelodyQuest-API\scripts\import_blindtest_catalog.php --file="P:\DEV\Temp\blindtest with cat.csv" --dry-run
php P:\DEV\GitHub\App-MelodyQuest-API\scripts\import_blindtest_catalog.php --file="P:\DEV\Temp\blindtest with cat.csv" --created-by=1
```

## API HTTP

Base production:

```text
https://api.shinederu.ch/melodyquest/
```

Format:

- `GET` et `DELETE`: `action` en query string.
- `POST` et `PUT`: JSON avec `Content-Type: application/json` et cle `action` dans le corps.

Reponse succes:

```json
{
  "success": true,
  "data": {}
}
```

Reponse erreur:

```json
{
  "success": false,
  "error": "Message lisible"
}
```

## Actions authentifiees joueur

- `POST action=createLobby`
- `POST action=joinLobby`
- `POST action=leaveLobby`
- `POST action=touchLobby`
- `POST action=kickPlayer`
- `POST action=deleteLobby`
- `POST action=resetLobbyForReplay`
- `PUT action=updateLobbyConfig`
- `POST action=syncPlayback`
- `GET action=getLobbyByCode&lobby_code=...`
- `GET action=getPlaybackState&lobby_id=...`
- `POST action=addTrackToPool`
- `POST action=removeTrackFromPool`
- `GET action=listTrackPool&lobby_id=...`
- `POST action=startRound`
- `POST action=revealRound`
- `POST action=finishRound`
- `POST action=voteNextRound`
- `POST action=voteRevealRound`
- `POST action=submitAnswer`
- `POST action=holdSuggestion`
- `POST action=releaseSuggestionHold`
- `POST action=submitSuggestion`
- `POST action=linkTvPairing`
- `GET action=getRoundState&lobby_id=...`
- `GET action=getScoreboard&lobby_id=...`
- `GET action=listPublicLobbies&game_mode=participative|autoplay`
- `GET action=listCategories`
- `GET action=listFamilies&category_id=...`
- `GET action=listTracks&family_id=...`

Details importants:

- `createLobby` accepte `game_mode`, `visibility`, `total_rounds`, `round_duration_seconds`, `reveal_duration_seconds`, `selected_category_ids`, `show_track_category`, `allow_early_reveal_vote`, `answer_similarity_threshold`.
- `createLobby` active `show_track_category` par defaut si absent.
- `createLobby` utilise `MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD`, `80` par defaut.
- `updateLobbyConfig` accepte les memes options de reglage.
- `listPublicLobbies` filtre par `game_mode`; `participative` est le defaut.
- `touchLobby` accepte `presence_status` (`active`, `away`) et, pour le createur, `target_user_id`.
- `kickPlayer` retire un joueur du salon sans detruire son historique de score.
- `voteRevealRound` demande 100% des joueurs actifs, refuse si l'option est desactivee, si quelqu'un a deja trouve, ou si la reponse est deja revelee.
- `voteNextRound` demande 50% des joueurs actifs apres revelation et refuse tant qu'un verrou de suggestion est actif.
- `holdSuggestion` bloque temporairement le passage a la manche suivante pendant qu'un joueur propose une correction.
- `submitSuggestion` accepte `track_correction` authentifie depuis une partie et `new_track` depuis la page publique.
- `submitSuggestion` limite l'abus a 5 suggestions par 10 minutes et par utilisateur/IP.
- `submitAnswer` utilise `answer_similarity_threshold` avec similarite hybride et garde-fous sur reponses courtes.

## Round state

`getRoundState` renvoie notamment:

- `round.preload_seconds`
- `round.is_waiting_to_start`
- `round.starts_in_seconds`
- `next_round_number`
- `next_track`
- `upcoming_tracks`
- `early_reveal_votes`
- `suggestion_holds`
- `solved_players`
- `answer_attempts`

Avant revelation globale:

- `round.track` contient les donnees necessaires a la lecture: `youtube_video_id`, `start_offset_seconds`;
- la categorie peut etre incluse si le salon l'autorise;
- les champs solution sont exposes au joueur qui a trouve, puis a tout le monde apres revelation.

`next_track` et `upcoming_tracks` restent expurges des champs solution.

Les joueurs ne recoivent pas l'historique complet des tentatives. `answer_attempts` expose seulement les derniers essais rates visibles. L'historique complet reste en DB pour admin/statistiques.

## Mode TV public

- `POST action=createTvPairing`: cree un code court et un `device_token`.
- `GET action=getTvPairing&device_token=...`: statut du code TV.
- `GET action=getTvState&device_token=...`: snapshot lobby/round/scoreboard pour la TV liee, sans session auth utilisateur.

Il n'existe plus d'action `markTvRoundReady`.

## Actions admin catalogue

Reservees a `melodyquest.catalog.manage`, `core.super_admin` ou au fallback historique `users.role='admin'`.

- `POST action=createCategory`
- `POST action=createFamily`
- `POST action=createTrack`
- `GET action=listPendingTracks`
- `PUT action=updateCategory`
- `PUT action=updateFamily`
- `PUT action=updateTrack`
- `POST action=validateTrack`
- `POST action=unvalidateTrack`
- `GET action=listSuggestions&status=pending|reviewed|rejected|all`
- `POST action=updateSuggestion`
- `POST action=applySuggestion`
- `POST action=updateSuggestionStatus`
- `GET action=listAnswerAttempts&outcome=wrong|correct|scored|all&search=...`
- `DELETE action=deleteCategory`
- `DELETE action=deleteFamily`
- `DELETE action=deleteTrack`

Details:

- `validateTrack` peut recevoir `track_id`, `category_id`, `family_name`, `aliases`, `title`, `artist`, `youtube_video_id`, `youtube_url`, `start_offset_seconds`.
- Si `aliases` est fourni a `validateTrack`, la liste remplace les alias de l'oeuvre cible.
- `updateSuggestion` enregistre les champs editables d'une suggestion sans modifier le catalogue.
- `applySuggestion` applique une correction ou cree une nouvelle piste validee, puis marque la suggestion `reviewed`.
- `updateSuggestionStatus` passe une suggestion en `pending`, `reviewed` ou `rejected`.
- `listAnswerAttempts` expose des groupes de reponses et essais recents pour aider a reperer alias/corrections/idees.

## Authentification et permissions

- Session via cookie `sid`.
- Tables Auth: `auth_sessions`, `users`.
- Config Auth partagee via runtime `P:\PROD\API\auth` quand disponible.
- Permissions via `Module-ShinedeCore-PHP`, deploye sous `P:\PROD\API\core`.
- Permission stable:

```text
melodyquest.catalog.manage
```

Code attendu:

```php
hasPermission($userId, 'melodyquest', 'catalog.manage')
```

Ne pas ajouter de logique metier basee sur le libelle d'un role configurable.

## Configuration

Le backend charge la config DB via variables `MQ_DB_*` en priorite, puis `DB_*`.

Variables:

- `MQ_DB_TYPE` ou `DB_TYPE`
- `MQ_DB_HOST` ou `DB_HOST`
- `MQ_DB_NAME` ou `DB_NAME`
- `MQ_DB_USER` ou `DB_USER`
- `MQ_DB_PASS` ou `DB_PASS`
- `MQ_DB_PORT` ou `DB_PORT`
- `MERCURE_HUB_URL`
- `MERCURE_PUBLISH_URL`
- `MERCURE_PUBLISHER_JWT_KEY`
- `MERCURE_SUBSCRIBER_JWT_KEY`
- `MQ_OWNER_STALE_TIMEOUT_SECONDS`, defaut `300`
- `MQ_PLAYER_INACTIVE_TIMEOUT_SECONDS`, conserve pour compatibilite mais detection automatique inactive
- `MQ_AUTH_BASE_API`, fallback `BASE_API`, puis `https://api.shinederu.ch/auth/`
- `MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD`, defaut `80`
- `MQ_AWAY_BONUS_PERCENT`, defaut `10`
- `MQ_ROUND_PRELOAD_SECONDS`, defaut `3`, borne `0` a `10`
- `MQ_TV_PRELOAD_LOOKAHEAD`, defaut `3`, borne `1` a `5`
- `MQ_MERCURE_TOPIC_BASE`, optionnel

`.env.example` est un exemple local versionne. Ne pas le copier en PROD.

## Mercure

- Hub public: `https://mercure.shinederu.ch/.well-known/mercure`
- Publish interne recommande: `http://mercure/.well-known/mercure`
- Topic lobbies publics: `https://api.shinederu.ch/melodyquest/topics/public-lobbies`
- Topic lobby prive: `https://api.shinederu.ch/melodyquest/topics/lobbies/{LOBBY_CODE}`

Les reponses `listPublicLobbies` et `getLobbyByCode` exposent `data.realtime`.

Mercure sert a publier des evenements/snapshots. Les commandes critiques passent par HTTP.

Chaque etat temps reel doit pouvoir etre reconstruit via HTTP:

- `listPublicLobbies`
- `getLobbyByCode`
- `getRoundState`
- `getTvState`

Il n'y a pas de fallback SSE supporte dans l'API actuelle.

## Dossiers runtime et fichiers partages

- `P:\PROD\API\melodyquest` contient uniquement le runtime PHP.
- Aucun stockage fichier persistant n'est possede par MelodyQuest.
- Catalogue, lobbies, scores, suggestions, tentatives et liaisons TV vivent en DB.
- Logs applicatifs via `error_log` PHP.
- Ne jamais logger de secret, mot de passe, token ou JWT complet.

## Dependances inter-projets

- `Module-Auth-API`: sessions, utilisateurs, avatars.
- `Module-ShinedeCore-PHP`: permissions `core_*`.
- `App-MelodyQuest`: frontend navigateur.
- Mercure: snapshots.
- MySQL `ShinedeCore`: `users`, `auth_sessions`, `core_*`, `mq_*`.

Une integration avec un autre projet doit passer par l'API proprietaire du projet cible, pas par ecriture directe dans ses tables.

## Verifications

```powershell
Get-ChildItem P:\DEV\GitHub\App-MelodyQuest-API -Recurse -Filter *.php | % { php -l $_.FullName }
git -c safe.directory=* diff --check
rg -n "password|passwd|secret|BEGIN (RSA|OPENSSH|PRIVATE)|api_key" P:\DEV\GitHub\App-MelodyQuest-API
```

Smoke tests fonctionnels: voir `PROD_TEST_CHECKLIST.md`.

## Deploiement

Preserver les fichiers runtime deja presents en PROD si un jour ils existent (`.env`, logs, caches runtime).

Copie runtime type:

```powershell
$src = 'P:\DEV\GitHub\App-MelodyQuest-API'
$dst = 'P:\PROD\API\melodyquest'
Copy-Item "$src\index.php" "$dst\index.php" -Force
foreach ($dir in @('config','controllers','middlewares','services','utils')) {
  robocopy "$src\$dir" "$dst\$dir" /E /NFL /NDL /NJH /NJS /NP
}
```

Verifier les restes non-runtime en PROD:

```powershell
Get-ChildItem P:\PROD\API\melodyquest -Force |
  Where-Object { $_.Name -in @('README.md','AGENTS.md','PROD_TEST_CHECKLIST.md','.env.example','sql','scripts','.git','.github') }
```

## Notes de reprise

- Ne pas restaurer `markTvRoundReady` ou le double lecteur TV sans nouvelle analyse.
- YouTube reste la source principale; l'hebergement local audio a ete refuse.
- Les migrations SQL doivent etre appliquees explicitement si un schema neuf est prepare.
- Les donnees historiques de tentatives/reponses sont utiles pour statistiques et amelioration catalogue.
- Si une modification touche le player ou la TV, tester un vrai salon avec telephone/PC/TV avant de conclure.
