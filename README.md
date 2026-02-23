# MelodyQuest API

MelodyQuest est un blindtest multijoueur base sur une authentification centralisee partagee sur le domaine/sous-domaines Shinederu.

## Contraintes produit

- Frontend en JS/CSS/HTML (sans framework)
- Auth obligatoire (session partagee via API auth)
- Creation/rejoindre un lobby
- Parametrage du lobby reserve au createur
- Catalogue musical structure par categories et familles
- Stockage des pistes via URL YouTube (aucun fichier audio en base)
- Lecture synchronisee entre joueurs via etat de lecture partage
- Administration musicale reservee aux admins (role modifie manuellement en DB)

## Base de donnees

Le schema MelodyQuest est installe dans `ShinedeCore` avec des tables prefixees `mq_`.

Migration:

- `sql/001_melodyquest_core.sql`
- Validation pre-prod: `PROD_TEST_CHECKLIST.md`

Tables principales:

- `mq_categories`
- `mq_families`
- `mq_tracks`
- `mq_lobbies`
- `mq_lobby_players`
- `mq_lobby_track_pool`
- `mq_rounds`
- `mq_round_answers`

## Actions API (index.php)

Base URL de production:

- `https://api.shinederu.lol/melodyquest/`
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
- `POST action=submitAnswer`
- `GET action=getRoundState&lobby_id=...`
- `GET action=getScoreboard&lobby_id=...`
- `GET action=listPublicLobbies`
- `GET action=listCategories`
- `GET action=listFamilies&category_id=...` (optionnel)
- `GET action=listTracks&family_id=...` (optionnel)

Admin uniquement (`users.role = 'admin'`):

- `POST action=createCategory`
- `POST action=createFamily`
- `POST action=createTrack`
- `PUT action=updateCategory`
- `PUT action=updateFamily`
- `PUT action=updateTrack`
- `DELETE action=deleteCategory`
- `DELETE action=deleteFamily`
- `DELETE action=deleteTrack`

## Regle admin

Le statut admin n'est pas expose au frontend pour elevation.
Il est derive du role stocke dans `users.role` et se gere manuellement en base.
