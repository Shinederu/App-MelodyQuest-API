# MelodyQuest Prod Test Checklist

## Prerequis

- Les fichiers `API/melodyquest/*` deployes sur le serveur API.
- Base de donnees `ShinedeCore` a jour avec les migrations `sql/001_melodyquest_core.sql` a `sql/011_melodyquest_round_preloads.sql`.
- Au moins un utilisateur avec `melodyquest.catalog.manage` via les droits `core_*`, ou un super-admin global `core.super_admin`, pour les tests admin.
- Domaine front `https://melodyquest.shinederu.ch` pointe vers le dossier serveur `MelodyQuest/` (index + assets).
- Si le live Docker sert `Z:\Nginx\www`, verifier que `P:\PROD` et `Z:\Nginx\www` sont synchronises.

## Etat connu avant pause

- Projet mis en pause le 2026-06-12 apres retour du mode TV au lecteur YouTube iframe simple.
- Cache-bust frontend attendu: `20260612-tv-basic-player`.
- L'ancienne action experimentale `markTvRoundReady` ne doit plus exister.
- Le mode TV peut encore dependre du temps de buffering YouTube; les essais de double lecteur TV ont ete retires.

## Variables d'environnement

Configurer (PHP runtime):

- `DB_TYPE` (ou `MQ_DB_TYPE`)
- `DB_HOST` (ou `MQ_DB_HOST`)
- `DB_NAME=ShinedeCore` (ou `MQ_DB_NAME`)
- `DB_USER` (ou `MQ_DB_USER`)
- `DB_PASS` (ou `MQ_DB_PASS`)
- `DB_PORT` (ou `MQ_DB_PORT`)
- `MERCURE_HUB_URL=https://mercure.shinederu.ch/.well-known/mercure`
- `MERCURE_PUBLISH_URL=http://mercure/.well-known/mercure`
- `MERCURE_PUBLISHER_JWT_KEY`
- `MERCURE_SUBSCRIBER_JWT_KEY`
- `MQ_ROUND_PRELOAD_SECONDS` optionnel, par defaut `3`
- `MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD` optionnel, par defaut `100`
- `MQ_TV_PRELOAD_LOOKAHEAD` optionnel, par defaut `3`

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
12. `GET /melodyquest/?action=getLobbyByCode&lobby_code=...` retourne `data.realtime.transport=mercure` si le hub est configure.
13. `GET /melodyquest/?action=listPublicLobbies` retourne `data.realtime.transport=mercure` si le hub est configure.
14. `GET https://mercure.shinederu.ch/.well-known/mercure?topic=...` recoit bien les updates correspondants.
15. En absence d'env Mercure cote PHP, le transport retombe proprement sur `sse`.
16. `POST /melodyquest/?action=createTvPairing` cree un code TV.
17. `GET /melodyquest/?action=getTvPairing&device_token=...` retourne le statut du code TV.
18. `GET /melodyquest/?action=getTvState&device_token=...` retourne le snapshot TV une fois lie.
19. `POST /melodyquest/?action=markTvRoundReady` doit etre absent/refuse: cette action n'est plus supportee.

## Smoke tests frontend

1. Ouvrir `https://melodyquest.shinederu.ch/#/public`.
2. Se connecter.
3. Depuis `#/main`, creer un lobby ou rejoindre via `#/lobby-list`.
4. Verifier que la liste des lobbies se met a jour automatiquement.
5. Verifier que les joueurs/status/scoreboard du lobby se mettent a jour automatiquement.
6. Ajouter des tracks au pool (owner).
7. Demarrer une manche, soumettre une reponse, reveal puis finish.
8. Verifier que `#/game` redirige vers le menu si aucune partie n'est precisee.
9. Verifier `/tv`: QR affiche, pas de header/footer, son TV actif apres liaison, aucun conteneur `tv-video-preload-player`.
10. Verifier `#/tv-link`: liaison d'une TV au salon courant via code.
11. Verifier le mode joueur de salon quand une TV est liee: interface reponse seule, sans lecteur video local.
12. Verifier les pages management avec un compte admin (`#/management*`).
13. Ajouter une musique, verifier qu'elle apparait dans `#/management-validation`, puis la valider apres controle de la preview YouTube.

## Criteres go/no-go

- Toutes les actions retournent `success=true` quand les payloads sont valides.
- Les droits owner/admin sont effectivement bloques pour les autres utilisateurs.
- Le score se met a jour apres reponse.
- Le round state et le playback state sont coherents entre joueurs.
- Le mode Mercure fonctionne sur `mercure.shinederu.ch`.
- Si Mercure n'est pas encore disponible cote PHP, le fallback SSE reste operationnel sans blocage UI.
- Le mode TV ne bloque pas le demarrage des manches meme si YouTube bufferise.



