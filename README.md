# MelodyQuest API

MelodyQuest est un blindtest multijoueur base sur une authentification centralisee partagee sur le domaine/sous-domaines Shinederu.

## Contraintes produit

- Frontend en JS/CSS/HTML (sans framework)
- Auth obligatoire (session partagee via API auth)
- Creation/rejoindre un lobby
- Parametrage du lobby reserve au createur
- Catalogue musical structure par categories et familles
- Validation manuelle des nouvelles musiques avant usage en partie
- Stockage des pistes via identifiant video YouTube (aucun fichier audio en base)
- Lecture synchronisee entre joueurs via etat de lecture partage
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
- Validation pre-prod: `PROD_TEST_CHECKLIST.md`

La migration `002` ajoute `mq_lobbies.total_rounds` et `mq_lobbies.selected_category_ids`.
La migration `006` fusionne les categories dupliquees vers les categories canoniques (`animes` -> `anime`, `musiques` -> `musique`, `jeux-video` -> `jeux`) et normalise les selections de categories stockees dans les lobbies.
La migration `007` ajoute les options `mq_lobbies.show_track_category` et `mq_lobbies.allow_early_reveal_vote`, ainsi que la table `mq_round_reveal_votes` pour le vote de revelation anticipee.
La migration `008` ajoute `mq_lobbies.answer_similarity_threshold`, seuil de correspondance entre `70` et `100`, avec `100` comme comportement strict historique.
La migration `009` ajoute `mq_player_suggestions` pour les corrections/alias/nouvelles musiques proposes par les joueurs et `mq_round_suggestion_holds` pour bloquer temporairement le passage a la manche suivante pendant qu'un joueur remplit une proposition.
La migration `010` ajoute `mq_tv_pairings`, table temporaire de liaison entre une television/ecran dedie et un salon MelodyQuest. Le code TV expire rapidement tant qu'il est en attente, puis la liaison est prolongee pendant que la TV synchronise le salon.

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

- `php melodyquest/scripts/import_blindtest_catalog.php --file="P:\DEV\Temp\blindtest with cat.csv" --dry-run`
- `php melodyquest/scripts/import_blindtest_catalog.php --file="P:\DEV\Temp\blindtest with cat.csv" --created-by=1`

## Actions API (index.php)

Base URL de production:

- `https://api.shinederu.ch/melodyquest/`
- Dossier serveur: `API/melodyquest/`

Authentifie:

- `POST action=createLobby`
- `POST action=joinLobby`
- `POST action=leaveLobby`
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
- `GET action=listPublicLobbies`
- `GET action=listCategories`
- `GET action=listFamilies&category_id=...` (optionnel)
- `GET action=listTracks&family_id=...` (optionnel)

`updateLobbyConfig` accepte aussi `visibility` (`public`/`private`), `show_track_category`, `allow_early_reveal_vote` et `answer_similarity_threshold`.
`voteRevealRound` enregistre un vote pour reveler la solution avant la fin du chrono; l'API refuse ce vote si l'option est desactivee, si la reponse est deja revelee ou si au moins un joueur a deja trouve. Depuis `009`, la revelation anticipee demande 100% des joueurs presents.
`holdSuggestion` et `releaseSuggestionHold` posent/retirent un verrou temporaire de manche pendant qu'un joueur propose une correction depuis l'ecran de jeu. `voteNextRound` refuse d'avancer tant qu'un verrou actif existe.
`submitSuggestion` accepte une correction de piste (`track_correction`, authentifie depuis une partie) ou une nouvelle musique (`new_track`, possible depuis la page publique sans session). Une URL YouTube fournie doit etre normalisable en ID video.
`getRoundState` renvoie `round.track.category_id`, `round.track.category_name`, `early_reveal_votes` et `suggestion_holds` pour l'interface de jeu.
`submitAnswer` utilise `answer_similarity_threshold`: `100` impose la correspondance normalisee exacte; en dessous, le backend calcule une similarite hybride (Levenshtein, similar_text, Jaro-Winkler) avec garde-fous sur les reponses courtes.
`linkTvPairing` lie un code TV en attente au salon de l'utilisateur connecte; l'utilisateur doit deja etre membre du salon.

Mode TV public:

- `POST action=createTvPairing`: cree un code court et un `device_token` pour l'ecran TV
- `GET action=getTvPairing&device_token=...`: permet a la TV de savoir si son code est encore en attente ou lie
- `GET action=getTvState&device_token=...`: renvoie un snapshot lobby/round/scoreboard pour la TV liee, sans session auth utilisateur

Flux temps reel:

- priorite: hub Mercure `https://mercure.shinederu.ch/.well-known/mercure`
- fallback de transition: endpoints SSE historiques
- `GET action=streamLobby&lobby_id=...`
- `GET action=streamPublicLobbies`

Admin uniquement (droit central `melodyquest.catalog.manage` ou super-admin global):

- `POST action=createCategory`
- `POST action=createFamily`
- `POST action=createTrack`
- `GET action=listPendingTracks`
- `PUT action=updateCategory`
- `PUT action=updateFamily`
- `PUT action=updateTrack`
- `POST action=validateTrack`
- `GET action=listSuggestions&status=pending|reviewed|rejected|all`
- `POST action=updateSuggestionStatus`
- `DELETE action=deleteCategory`
- `DELETE action=deleteFamily`
- `DELETE action=deleteTrack`

`validateTrack` accepte au minimum `track_id` ou `id`. Il peut aussi recevoir les champs de correction `category_id`, `family_name`, `aliases`, `title`, `artist`, `youtube_video_id` ou `youtube_url`; dans ce cas l'API applique ces corrections puis valide la musique dans une meme transaction. Quand `aliases` est fourni, la liste remplace les alias acceptes de l'oeuvre cible via `mq_family_aliases`.

## Environnement

Le backend MelodyQuest charge le meme runtime `.env` que `auth`.

- Utilise prioritairement `MQ_DB_*` si presents
- Sinon fallback sur `DB_*`
- Pour le temps reel Mercure, le runtime PHP doit aussi connaitre:
  - `MERCURE_HUB_URL`
  - `MERCURE_PUBLISH_URL` (optionnel, recommande en interne Docker)
  - `MERCURE_PUBLISHER_JWT_KEY`
  - `MERCURE_SUBSCRIBER_JWT_KEY`
- `MQ_OWNER_STALE_TIMEOUT_SECONDS` permet d'ajuster le delai de nettoyage des salons dont le createur n'envoie plus de presence; valeur par defaut: `300`.
- `MQ_AUTH_BASE_API` permet de definir la base de l'API Auth utilisee pour reconstruire les URLs d'avatar; fallback sur `BASE_API`, puis `https://api.shinederu.ch/auth/`.
- `MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD` permet de definir le seuil par defaut des nouveaux salons; valeur par defaut: `100`.
  - `MQ_MERCURE_TOPIC_BASE` (optionnel)

## Mercure

- Hub vise: `https://mercure.shinederu.ch/.well-known/mercure`
- Publish interne recommande cote PHP: `http://mercure/.well-known/mercure`
- Topic lobbies publics: `https://api.shinederu.ch/melodyquest/topics/public-lobbies`
- Topic lobby prive: `https://api.shinederu.ch/melodyquest/topics/lobbies/{LOBBY_CODE}`
- Les reponses `listPublicLobbies` et `getLobbyByCode` exposent un bloc `data.realtime`
- Le frontend tente Mercure d'abord, puis retombe sur le SSE historique si la config hub/JWT n'est pas encore disponible

## Regle admin

Le statut admin n'est pas expose au frontend pour elevation.
Les droits catalogue sont portes par les tables `core_*`.
Le role seed par defaut est `melodyquest.catalog_admin`; il donne la permission backend `catalog.manage`, souvent notee `melodyquest.catalog.manage` dans la documentation.
Pendant la transition, `users.role='admin'` reste un fallback super-admin global.



