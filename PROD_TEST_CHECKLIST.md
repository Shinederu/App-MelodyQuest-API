# MelodyQuest Prod Test Checklist

## Prerequis

- Les fichiers `api/melodyquest/*` deployes sur le serveur API.
- Base de donnees `ShinedeCore` a jour avec `sql/001_melodyquest_core.sql`.
- Au moins un utilisateur avec `users.role = 'admin'` pour les tests admin.
- Domaine front `https://melodyquest.shinederu.lol` pointe vers le dossier serveur `MelodyQuest/` (index + assets).

## Variables d'environnement

Configurer (PHP runtime):

- `MQ_DB_TYPE`
- `MQ_DB_HOST`
- `MQ_DB_NAME=ShinedeCore`
- `MQ_DB_USER`
- `MQ_DB_PASS`
- `MQ_DB_PORT`

## Smoke tests API (authentifie)

1. Login via API auth (cookie `sid` present).
2. `POST /melodyquest/?action=createLobby`
3. `POST /melodyquest/?action=joinLobby`
4. `GET /melodyquest/?action=getLobbyByCode&lobby_code=...`
5. `GET /melodyquest/?action=listCategories`
6. `POST /melodyquest/?action=addTrackToPool`
7. `POST /melodyquest/?action=startRound`
8. `POST /melodyquest/?action=submitAnswer`
9. `POST /melodyquest/?action=revealRound`
10. `POST /melodyquest/?action=finishRound`
11. `GET /melodyquest/?action=getScoreboard&lobby_id=...`

## Smoke tests frontend

1. Ouvrir `https://melodyquest.shinederu.lol/#/public`.
2. Se connecter.
3. Depuis `#/main`, creer un lobby ou rejoindre via `#/lobby-list`.
4. Ajouter des tracks au pool (owner).
5. Demarrer une manche.
6. Soumettre une reponse.
7. Verifier scoreboard et etat round.
8. Verifier les pages management avec un compte admin (`#/management*`).

## Criteres go/no-go

- Toutes les actions retournent `success=true` quand les payloads sont valides.
- Les droits owner/admin sont effectivement bloques pour les autres utilisateurs.
- Le score se met a jour apres reponse.
- Le round state et le playback state sont coherents entre joueurs.
