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
- Administration musicale reservee aux admins (flag admin modifie manuellement en DB)

## Base de donnees

Le schema MelodyQuest est installe dans `ShinedeCore` avec des tables prefixees `mq_`.

Migration:

- `sql/001_melodyquest_core.sql`
- Validation pre-prod: `PROD_TEST_CHECKLIST.md`

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
- `POST action=submitAnswer`
- `GET action=getRoundState&lobby_id=...`
- `GET action=getScoreboard&lobby_id=...`
- `GET action=listPublicLobbies`
- `GET action=listCategories`
- `GET action=listFamilies&category_id=...` (optionnel)
- `GET action=listTracks&family_id=...` (optionnel)

Flux temps reel (SSE):

- `GET action=streamLobby&lobby_id=...`
- `GET action=streamPublicLobbies`

Admin uniquement (`users.is_admin = 1 (ou users.role = 'admin')`):

- `POST action=createCategory`
- `POST action=createFamily`
- `POST action=createTrack`
- `PUT action=updateCategory`
- `PUT action=updateFamily`
- `PUT action=updateTrack`
- `DELETE action=deleteCategory`
- `DELETE action=deleteFamily`
- `DELETE action=deleteTrack`

## Environnement

Le backend MelodyQuest charge le meme runtime `.env` que `auth`.

- Utilise prioritairement `MQ_DB_*` si presents
- Sinon fallback sur `DB_*`

## Regle admin

Le statut admin n'est pas expose au frontend pour elevation.
Il est derive de users.is_admin (si present) ou users.role='admin', et se gere manuellement en base.



