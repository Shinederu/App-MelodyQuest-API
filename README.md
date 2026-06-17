# MelodyQuest API

## Role

MelodyQuest est un blindtest multijoueur base sur une authentification centralisee partagee sur le domaine/sous-domaines Shinederu.

Cette API est le proprietaire backend de MelodyQuest: salons, manches, reponses, catalogue musical, validation, suggestions joueurs, liaison TV et publication temps reel.

## Repo et deploiement

- Repo source: `P:\DEV\GitHub\App-MelodyQuest-API`
- Repo GitHub: `https://github.com/Shinederu/App-MelodyQuest-API.git`
- Runtime PROD: `P:\PROD\API\melodyquest`
- Endpoint public: `https://api.shinederu.ch/melodyquest/`
- Code projet stable: `melodyquest`

Le dossier PROD ne doit pas etre un clone du repo. Il ne doit contenir que le runtime API necessaire:

- `index.php`;
- `config\`;
- `controllers\`;
- `middlewares\`;
- `services\`;
- `utils\`.

Ne pas deployer en PROD: `.git`, `.github`, `README.md`, `AGENTS.md`, `PROD_TEST_CHECKLIST.md`, `.env.example`, `sql\`, `scripts\`, tests, caches, brouillons ou exports.

## Etat de pause - 2026-06-12

Le projet MelodyQuest est mis en pause dans un etat stable de reprise. Les derniers commits applicatifs lies a MelodyQuest avant cette pause sont:

- frontend `App-MelodyQuest`: `295dd11 Restore basic MelodyQuest TV player`;
- backend `App-MelodyQuest-API`: `28dbdda Remove MelodyQuest TV ready playback flow`.

Le mode TV public reste actif, mais il utilise de nouveau un lecteur YouTube iframe simple cote frontend. L'action experimentale `markTvRoundReady`, le demarrage accelere par signal "TV prete" et les constantes `MQ_TV_ROUND_PRELOAD_MAX_WAIT_SECONDS` / `MQ_TV_READY_START_LEAD_SECONDS` ont ete retires. Les tables `mq_tv_pairings` et `mq_round_preloads` restent utiles: la premiere lie une TV a un salon, la seconde prepare la file de pistes a venir cote backend.

Point a surveiller lors de la reprise: les delais de buffering YouTube sur TV ne sont pas resolus de facon definitive. Les essais de double lecteur TV ont cree des regressions son/video et ne doivent pas etre remis tels quels.

## Contraintes produit

- Frontend en JS/CSS/HTML (sans framework)
- Auth obligatoire (session partagee via API auth)
- Creation/rejoindre un lobby
- Mode de lobby `participative` ou `autoplay`: le mode automatique reutilise le tirage/manches mais n'attend aucune reponse joueur, aucun score et aucun vote.
- Parametrage du lobby reserve au createur
- Catalogue musical structure par categories et familles
- Validation manuelle des nouvelles musiques avant usage en partie
- Stockage des pistes via identifiant video YouTube (aucun fichier audio en base)
- Lecture synchronisee entre joueurs via etat de lecture partage
- Creation des manches avec une courte synchronisation serveur (`MQ_ROUND_PRELOAD_SECONDS`) et une file de pistes stockee en base pour connaitre les prochaines musiques sans recalculer le tirage au dernier moment.
- Repartition des musiques par categorie equilibree sur la duree du salon: si plusieurs categories sont selectionnees, le backend vise un nombre equivalent de manches par categorie; une categorie avec trop peu de musiques donne son maximum, puis les manches restantes sont redistribuees entre les autres categories.
- Avatars joueurs normalises cote backend: les anciennes URLs `action=getAvatar` stockees en base sont reconstruites vers l'API Auth active avant d'etre renvoyees aux lobbies, salons publics, classements et votes.
- Administration musicale reservee au droit central `melodyquest.catalog.manage` ou au super-admin global; `users.role='admin'` reste seulement un fallback de transition.

## Base de donnees

Le schema MelodyQuest est installe dans `ShinedeCore` avec des tables prefixees `mq_`.

Migration:

- `sql/001_melodyquest_core.sql`
- `sql/002_melodyquest_lobby_settings.sql`
- `sql/003_melodyquest_family_aliases.sql`
- `sql/004_melodyquest_track_validation.sql`
- `sql/005_melodyquest_track_video_id_only.sql`
- `sql/006_melodyquest_merge_duplicate_categories.sql`
- `sql/007_melodyquest_game_options.sql`
- `sql/008_melodyquest_answer_similarity.sql`
- `sql/009_melodyquest_player_suggestions.sql`
- `sql/010_melodyquest_tv_pairings.sql`
- `sql/011_melodyquest_round_preloads.sql`
- `sql/012_melodyquest_presence_and_attempts.sql`
- `sql/013_melodyquest_answer_similarity_default_80.sql`
- `sql/014_melodyquest_away_bonus.sql`
- `sql/015_melodyquest_autoplay_mode.sql`
- `sql/016_melodyquest_category_visible_default.sql`
- `sql/017_melodyquest_admin_suggestion_review.sql`
- Validation pre-prod: `PROD_TEST_CHECKLIST.md`

La migration `002` ajoute `mq_lobbies.total_rounds` et `mq_lobbies.selected_category_ids`.
La migration `006` fusionne les categories dupliquees vers les categories canoniques (`animes` -> `anime`, `musiques` -> `musique`, `jeux-video` -> `jeux`) et normalise les selections de categories stockees dans les lobbies.
La migration `007` ajoute les options `mq_lobbies.show_track_category` et `mq_lobbies.allow_early_reveal_vote`, ainsi que la table `mq_round_reveal_votes` pour le vote de revelation anticipee.
La migration `008` ajoute `mq_lobbies.answer_similarity_threshold`, seuil de correspondance entre `70` et `100`, avec `100` comme comportement strict historique.
La migration `009` ajoute `mq_player_suggestions` pour les corrections/alias/nouvelles musiques proposes par les joueurs et `mq_round_suggestion_holds` pour bloquer temporairement le passage a la manche suivante pendant qu'un joueur remplit une proposition.
La migration `010` ajoute `mq_tv_pairings`, table temporaire de liaison entre une television/ecran dedie et un salon MelodyQuest. Le code TV expire rapidement tant qu'il est en attente, puis la liaison est prolongee pendant que la TV synchronise le salon.
La migration `011` ajoute `mq_round_preloads`, file de pistes a venir par salon/manche. Elle permet de choisir les musiques a venir sans recalculer le tirage au dernier moment. Le frontend TV utilise actuellement un lecteur YouTube actif simple et ne pilote plus de lecteur d'avance.
La migration `012` ajoute la presence joueur (`presence_status`, `removed_at`, `removed_by`) et `mq_round_answer_attempts` pour conserver les essais de reponse sans remplacer le score courant.
La migration `013` passe la valeur SQL par defaut de `mq_lobbies.answer_similarity_threshold` a `80` pour les nouveaux salons. Les salons existants gardent leur valeur.
La migration `014` ajoute `mq_round_away_bonuses`, trace idempotente des points de compensation attribues aux joueurs absents quand le premier joueur trouve une manche.
La migration `015` ajoute `mq_lobbies.game_mode` avec `participative` par defaut et `autoplay` pour les blindtests automatiques sans saisie/score/vote.
La migration `016` passe la valeur SQL par defaut de `mq_lobbies.show_track_category` a `1` pour les nouveaux salons. Les salons existants gardent leur valeur.
La migration `017` ajoute les champs de travail admin des suggestions (`admin_category_id`, `admin_family_name`, `admin_start_offset_seconds`) et la trace d'application (`applied_track_id`, `applied_at`).

## Import catalogue CSV

Un script CLI permet d'importer l'export blindtest multi-sections (groupes, playlists, liaisons, tracks) dans le schema MelodyQuest.

Mapping applique:

- groupe racine -> `mq_categories`
- `title` source -> `mq_families.name`
- `alternative_title` -> `mq_family_aliases.alias`
- playlist source -> `mq_tracks.title`
- `youtube_url` source -> `mq_tracks.youtube_video_id`
- `preview_start_seconds` -> `mq_tracks.start_offset_seconds`
- `reveal_start_seconds` est ignore

Commandes utiles:

- `php P:\DEV\GitHub\App-MelodyQuest-API\scripts\import_blindtest_catalog.php --file="P:\DEV\Temp\blindtest with cat.csv" --dry-run`
- `php P:\DEV\GitHub\App-MelodyQuest-API\scripts\import_blindtest_catalog.php --file="P:\DEV\Temp\blindtest with cat.csv" --created-by=1`

## Actions API (index.php)

Base URL de production:

- `https://api.shinederu.ch/melodyquest/`
- Dossier source: `P:\DEV\GitHub\App-MelodyQuest-API`
- Dossier runtime: `P:\PROD\API\melodyquest`

Les actions `POST` et `PUT` doivent etre envoyees en JSON (`Content-Type: application/json`) avec la cle `action` dans le corps. Les actions `GET` et `DELETE` lisent `action` depuis la query string.

Authentifie:

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
- `GET action=listPublicLobbies&game_mode=participative|autoplay` (`participative` par defaut)
- `GET action=listCategories`
- `GET action=listFamilies&category_id=...` (optionnel)
- `GET action=listTracks&family_id=...` (optionnel)

`updateLobbyConfig` accepte aussi `visibility` (`public`/`private`), `show_track_category`, `allow_early_reveal_vote` et `answer_similarity_threshold`. `createLobby` active `show_track_category` par defaut si le champ n'est pas fourni.
`createLobby` et `updateLobbyConfig` acceptent `game_mode` (`participative`/`autoplay`). Les salons `autoplay` sont de vrais salons passifs: ils gardent code, membres, partage et liaison TV, mais n'attendent aucune reponse joueur, aucun score et aucun vote. `listPublicLobbies` filtre par `game_mode`; les snapshots Mercure publient les salons publics des deux modes et le frontend filtre selon le switch actif/passif.
`touchLobby` accepte `presence_status` (`active`, `away`). `away` garde le joueur dans le salon mais le retire des votes/attentes. Le createur peut aussi fournir `target_user_id` pour passer un autre joueur present/absent sans le retirer du salon. La presence est volontairement manuelle: fermer l'onglet ne marque plus automatiquement le joueur absent/inactif; le createur doit utiliser `kickPlayer` pour retirer un joueur qui ne revient pas. Les anciennes valeurs `inactive` sont normalisees en `active`.
`voteRevealRound` enregistre un vote pour reveler la solution avant la fin du chrono; l'API refuse ce vote si l'option est desactivee, si la reponse est deja revelee ou si au moins un joueur a deja trouve. Depuis `009`, la revelation anticipee demande 100% des joueurs actifs.
`voteNextRound` sert au passage manuel vers la manche suivante apres revelation et demande 50% des joueurs actifs. Il refuse d'avancer tant qu'un verrou de suggestion actif existe.
`holdSuggestion` et `releaseSuggestionHold` posent/retirent un verrou temporaire de manche pendant qu'un joueur propose une correction depuis l'ecran de jeu.
`submitSuggestion` accepte une correction de piste (`track_correction`, authentifie depuis une partie) ou une nouvelle musique (`new_track`, possible depuis la page publique sans session). Une URL YouTube fournie doit etre normalisable en ID video. Anti-abus: 5 suggestions maximum par 10 minutes et par utilisateur authentifie ou IP anonyme.
`getRoundState` renvoie `round.preload_seconds`, `round.is_waiting_to_start`, `round.starts_in_seconds`, `next_round_number`, `next_track`, `upcoming_tracks`, `early_reveal_votes`, `suggestion_holds`, `solved_players` et `answer_attempts` pour l'interface de jeu. Avant revelation globale, `round.track` ne contient que les donnees necessaires a la lecture (`youtube_video_id`, `start_offset_seconds`) et, si l'option du salon l'autorise, la categorie. Les champs solution (`title`, `artist`, `family_name`, alias, etc.) sont renvoyes au joueur qui a trouve et a tout le monde apres revelation. `next_track` et `upcoming_tracks` restent toujours expurges des champs solution. Les joueurs ne recoivent pas l'historique complet des tentatives: `answer_attempts` expose seulement les derniers essais rates visibles. L'historique complet reste en base dans `mq_round_answer_attempts` pour de futures statistiques/admin.
`submitAnswer` utilise `answer_similarity_threshold`: `100` impose la correspondance normalisee exacte; en dessous, le backend calcule une similarite hybride (Levenshtein, similar_text, Jaro-Winkler) avec garde-fous sur les reponses courtes.
`linkTvPairing` lie un code TV en attente au salon de l'utilisateur connecte; l'utilisateur doit deja etre membre du salon.

Mode TV public:

- `POST action=createTvPairing`: cree un code court et un `device_token` pour l'ecran TV
- `GET action=getTvPairing&device_token=...`: permet a la TV de savoir si son code est encore en attente ou lie
- `GET action=getTvState&device_token=...`: renvoie un snapshot lobby/round/scoreboard pour la TV liee, sans session auth utilisateur.

Il n'existe plus d'action `markTvRoundReady`. Le frontend TV ne signale plus au backend qu'une video est prete; les manches demarrent selon `MQ_ROUND_PRELOAD_SECONDS`.

## Authentification et permissions

- Les endpoints authentifies valident le cookie session `sid` via `AuthMiddleware`.
- `AuthMiddleware` lit `auth_sessions` et `users` dans le schema `ShinedeCore`.
- L'API charge le runtime `.env` de `P:\PROD\API\auth` via l'autoload Auth si disponible, pour partager la config DB/Mercure.
- Les permissions admin catalogue passent par `Module-ShinedeCore-PHP`, deploye en PROD sous `P:\PROD\API\core`.
- Permission stable: `melodyquest.catalog.manage`.
- `core.super_admin` donne le bypass global; `users.role='admin'` reste seulement un fallback de transition.

Le code doit verifier les permissions avec:

```php
hasPermission($userId, 'melodyquest', 'catalog.manage')
```

Ne pas ajouter de logique metier basee sur le libelle d'un role configurable.

Flux temps reel:

- priorite: hub Mercure `https://mercure.shinederu.ch/.well-known/mercure`
- resynchronisation possible par API HTTP (`listPublicLobbies`, `getLobbyByCode`, `getRoundState`)
- pas de fallback SSE supporte dans l'API actuelle

Admin uniquement (droit central `melodyquest.catalog.manage` ou super-admin global):

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
- `GET action=listAnswerAttempts&outcome=wrong|correct|scored|all&search=...`
- `POST action=updateSuggestion`
- `POST action=applySuggestion`
- `POST action=updateSuggestionStatus`
- `DELETE action=deleteCategory`
- `DELETE action=deleteFamily`
- `DELETE action=deleteTrack`

`validateTrack` accepte au minimum `track_id` ou `id`. Il peut aussi recevoir les champs de correction `category_id`, `family_name`, `aliases`, `title`, `artist`, `youtube_video_id`, `youtube_url` ou `start_offset_seconds`; dans ce cas l'API applique ces corrections puis valide la musique dans une meme transaction. Quand `aliases` est fourni, la liste remplace les alias acceptes de l'oeuvre cible via `mq_family_aliases`.
`updateSuggestion` enregistre les champs editable d'une suggestion joueur sans modifier le catalogue.
`applySuggestion` applique une suggestion au catalogue: une correction met a jour/valide la musique liee et ajoute l'alias propose sans perdre les alias existants; une nouvelle musique cree une piste validee dans la categorie/oeuvre choisie par l'admin. La suggestion est ensuite marquee `reviewed` avec `applied_track_id` et `applied_at`.
`listAnswerAttempts` expose les groupes de reponses et les essais recents issus de `mq_round_answer_attempts` pour aider l'admin a reperer des alias, corrections ou idees de musiques.

## Configuration

Le backend MelodyQuest charge le meme runtime `.env` que `auth`.

- Utilise prioritairement `MQ_DB_*` si presents
- Sinon fallback sur `DB_*`
- Pour le temps reel Mercure, le runtime PHP doit aussi connaitre:
  - `MERCURE_HUB_URL`
  - `MERCURE_PUBLISH_URL` (optionnel, recommande en interne Docker)
  - `MERCURE_PUBLISHER_JWT_KEY`
  - `MERCURE_SUBSCRIBER_JWT_KEY`
- `MQ_OWNER_STALE_TIMEOUT_SECONDS` permet d'ajuster le delai de nettoyage des salons dont le createur n'envoie plus de presence; valeur par defaut: `300`.
- `MQ_PLAYER_INACTIVE_TIMEOUT_SECONDS` est une ancienne variable conservee pour compatibilite runtime, mais la detection automatique d'inactivite joueur est desactivee. La presence de partie est geree manuellement via `active`/`away`.
- `MQ_AUTH_BASE_API` permet de definir la base de l'API Auth utilisee pour reconstruire les URLs d'avatar; fallback sur `BASE_API`, puis `https://api.shinederu.ch/auth/`.
- `MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD` permet de definir le seuil par defaut des nouveaux salons; valeur par defaut: `80`.
- `MQ_AWAY_BONUS_PERCENT` definit le pourcentage du score du premier joueur qui trouve attribue aux joueurs absents; valeur par defaut: `10`.
- `MQ_ROUND_PRELOAD_SECONDS` permet de definir la courte marge de synchronisation au depart des nouvelles manches; valeur par defaut: `3`, bornee entre `0` et `10`.
- `MQ_TV_PRELOAD_LOOKAHEAD` definit combien de pistes a venir l'API peut garder dans la file de prochaines manches; valeur par defaut: `3`, bornee entre `1` et `5`. Le nom est historique: le frontend TV actuel ne pilote pas de lecteur d'avance.
- `MQ_MERCURE_TOPIC_BASE` (optionnel)

`P:\DEV\GitHub\App-MelodyQuest-API\.env.example` reste un exemple local versionne. Il ne doit pas etre copie en PROD.

## Mercure

- Hub vise: `https://mercure.shinederu.ch/.well-known/mercure`
- Publish interne recommande cote PHP: `http://mercure/.well-known/mercure`
- Topic lobbies publics: `https://api.shinederu.ch/melodyquest/topics/public-lobbies`
- Topic lobby prive: `https://api.shinederu.ch/melodyquest/topics/lobbies/{LOBBY_CODE}`
- Les reponses `listPublicLobbies` et `getLobbyByCode` exposent un bloc `data.realtime`
- Le frontend tente Mercure d'abord, puis resynchronise par HTTP si la connexion temps reel est indisponible

Les topics doivent pouvoir etre resynchronises par API HTTP (`listPublicLobbies`, `getLobbyByCode`, `getRoundState`). Mercure ne sert pas a executer des commandes critiques.

## Dossiers runtime et fichiers partages

- `P:\PROD\API\melodyquest` contient uniquement le runtime API PHP liste dans la section deploiement.
- Aucun stockage persistant fichier n'est possede par MelodyQuest actuellement.
- Le catalogue, les lobbies, les scores, les suggestions et les liaisons TV vivent en DB dans les tables `mq_*`.
- Les logs applicatifs passent par `error_log` PHP; ne jamais logger de secret, mot de passe, token ou JWT complet.

## Dependances inter-projets

- `Module-Auth-API` (`https://api.shinederu.ch/auth/`): sessions `sid`, utilisateurs, avatars.
- `Module-ShinedeCore-PHP` (`P:\PROD\API\core`): permissions `core_*`.
- `App-MelodyQuest`: client navigateur consommateur de cette API.
- Mercure: publication de snapshots lobbies publics/prives.
- MySQL `ShinedeCore`: tables `users`, `auth_sessions`, `core_*`, `mq_*`.

Une integration avec un autre projet doit passer par l'API HTTP proprietaire du projet cible, pas par ecriture directe dans ses tables.

## Regle admin

Le statut admin n'est pas expose au frontend pour elevation.
Les droits catalogue sont portes par les tables `core_*`.
Le role seed par defaut est `melodyquest.catalog_admin`; il donne la permission backend `catalog.manage`, souvent notee `melodyquest.catalog.manage` dans la documentation.
Pendant la transition, `users.role='admin'` reste un fallback super-admin global.

## Verifications

```powershell
Get-ChildItem P:\DEV\GitHub\App-MelodyQuest-API -Recurse -Filter *.php | % { php -l $_.FullName }
git -c safe.directory=* diff --check
rg -n "password|passwd|secret|BEGIN (RSA|OPENSSH|PRIVATE)|api_key" P:\DEV\GitHub\App-MelodyQuest-API
```

Smoke tests fonctionnels: voir `PROD_TEST_CHECKLIST.md`.

## Deploiement

Preserver les fichiers runtime deja presents en PROD si un jour ils existent (`.env`, logs, caches runtime). MelodyQuest utilise actuellement la config Auth partagee; ne pas creer de secret dans le repo.

Copie runtime type:

```powershell
$src = 'P:\DEV\GitHub\App-MelodyQuest-API'
$dst = 'P:\PROD\API\melodyquest'
Copy-Item "$src\index.php" "$dst\index.php" -Force
foreach ($dir in @('config','controllers','middlewares','services','utils')) {
  Copy-Item "$src\$dir\*" "$dst\$dir" -Recurse -Force
}
```

Nettoyer les restes non-runtime si presents en PROD:

```powershell
$nonRuntime = @(
  'P:\PROD\API\melodyquest\README.md',
  'P:\PROD\API\melodyquest\AGENTS.md',
  'P:\PROD\API\melodyquest\PROD_TEST_CHECKLIST.md',
  'P:\PROD\API\melodyquest\.env.example',
  'P:\PROD\API\melodyquest\sql',
  'P:\PROD\API\melodyquest\scripts'
)
Remove-Item -LiteralPath $nonRuntime -Recurse -Force -ErrorAction SilentlyContinue
```

## Notes de reprise

- Projet mis en pause le 2026-06-12 avec TV revenue au lecteur YouTube simple.
- Ne pas restaurer `markTvRoundReady` ou le double lecteur TV sans nouvelle analyse.
- YouTube reste la source principale; l'hebergement local d'audio a ete refuse.
- Les migrations SQL restent dans le repo DEV et doivent etre appliquees explicitement si un schema neuf est prepare.



