# MelodyQuest Prod Test Checklist

## Prerequis

- Front runtime deploye: `P:\PROD\MelodyQuest\index.html` et `P:\PROD\MelodyQuest\assets\`.
- API runtime deploye: `P:\PROD\API\melodyquest\index.php`, `config\`, `controllers\`, `middlewares\`, `repositories\`, `services\`, `utils\`.
- Aucun fichier non-runtime en PROD: `.git`, `.github`, `README.md`, `AGENTS.md`, `PROD_TEST_CHECKLIST.md`, `.env.example`, `sql\`, `scripts\`, tests, caches ou brouillons.
- DB `ShinedeCore` a jour avec les migrations `sql/001_melodyquest_core.sql` a `sql/018_melodyquest_game_history.sql`.
- Au moins un utilisateur avec `melodyquest.catalog.manage` via `core_*`, ou un super-admin global `core.super_admin`, pour les tests admin.
- Domaine front `https://melodyquest.shinederu.ch` pointe vers le dossier serveur `MelodyQuest/`.
- API publique accessible sous `https://api.shinederu.ch/melodyquest/`.
- Mercure accessible sous `https://mercure.shinederu.ch/.well-known/mercure`.

## Etat attendu

- Cache-bust frontend attendu: `20260810-history-safety`.
- Mode actif: reponses, score, classement, votes.
- Mode passif: salon + TV possibles, mais pas de score, pas de reponse et pas de votes.
- Les suppressions de catalogue et de salon demandent une confirmation nommee.
- Une partie est archivee dans `mq_game_session_*` avant reset, suppression, fermeture ou purge.
- Fin du mode passif: retour automatique au lobby.
- L'action `markTvRoundReady` n'existe plus et doit etre refusee.
- Le mode TV utilise un lecteur YouTube simple; aucun conteneur de double lecteur/preload TV ne doit etre requis.

## Variables d'environnement

Configurer cote PHP runtime:

- `DB_TYPE` ou `MQ_DB_TYPE`
- `DB_HOST` ou `MQ_DB_HOST`
- `DB_NAME=ShinedeCore` ou `MQ_DB_NAME`
- `DB_USER` ou `MQ_DB_USER`
- `DB_PASS` ou `MQ_DB_PASS`
- `DB_PORT` ou `MQ_DB_PORT`
- `MERCURE_HUB_URL=https://mercure.shinederu.ch/.well-known/mercure`
- `MERCURE_PUBLISH_URL=http://mercure/.well-known/mercure`
- `MERCURE_PUBLISHER_JWT_KEY`
- `MERCURE_SUBSCRIBER_JWT_KEY`
- `MQ_ROUND_PRELOAD_SECONDS`, optionnel, defaut `3`
- `MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD`, optionnel, defaut `80`
- `MQ_AWAY_BONUS_PERCENT`, optionnel, defaut `10`
- `MQ_TV_PRELOAD_LOOKAHEAD`, optionnel, defaut `3`
- `MQ_AUTH_BASE_API`, optionnel

## Smoke tests API

Avec une session `sid` valide:

1. `POST action=createLobby` en mode `participative`.
2. `POST action=createLobby` en mode `autoplay`.
3. `GET action=listPublicLobbies&game_mode=participative`.
4. `GET action=listPublicLobbies&game_mode=autoplay`.
5. `POST action=joinLobby`.
6. `GET action=getLobbyByCode&lobby_code=...`.
7. `PUT action=updateLobbyConfig` avec categories, categorie visible, seuil de precision et visibilite.
8. `POST action=touchLobby` avec `presence_status=away`, puis `active`.
9. `POST action=kickPlayer` depuis le createur.
10. `GET action=listCategories`.
11. `POST action=startRound`.
12. `GET action=getRoundState&lobby_id=...`.
13. `POST action=submitAnswer`.
14. `POST action=voteRevealRound`.
15. `POST action=revealRound`.
16. `POST action=holdSuggestion`, puis `releaseSuggestionHold`.
17. `POST action=voteNextRound`.
18. `POST action=finishRound`.
19. `GET action=getScoreboard&lobby_id=...`.
20. `POST action=createTvPairing`.
21. `GET action=getTvPairing&device_token=...`.
22. `POST action=linkTvPairing`.
23. `GET action=getTvState&device_token=...`.
24. `POST action=submitSuggestion` pour une correction de piste.
25. `POST action=submitSuggestion` en `new_track` depuis une page publique ou sans session.
26. `POST action=markTvRoundReady` doit retourner une erreur.

Avec un compte admin catalogue:

1. `GET action=listPendingTracks`.
2. `POST action=validateTrack` avec correction de champs et alias.
3. `POST action=unvalidateTrack`.
4. `GET action=listSuggestions&status=all`.
5. `POST action=updateSuggestion`.
6. `POST action=applySuggestion`.
7. `POST action=updateSuggestionStatus`.
8. `GET action=listAnswerAttempts&outcome=all`.
9. CRUD categories/familles/pistes si la passe touche le catalogue.

## Smoke tests frontend

1. Ouvrir `https://melodyquest.shinederu.ch/#/public`.
2. Se connecter.
3. Depuis `#/main`, verifier le switch actif/passif.
4. Creer un salon actif, puis rejoindre avec un deuxieme utilisateur si possible.
5. Verifier `#/lobby`: categories, joueurs, present/absent, partage, liaison TV.
6. Lancer le mode actif:
   - video cachee avant revelation;
   - champ reponse autofocus au debut de manche;
   - mauvaises reponses limitees;
   - solution claire apres bonne reponse/revelation;
   - classement et votes coherents.
7. Creer un salon passif:
   - salon prive par defaut;
   - partage et liaison TV disponibles;
   - pas de scoreboard pendant la partie passive;
   - retour automatique au lobby a la fin.
8. Verifier `/tv`: QR affiche, pas de header/footer, son actif apres liaison.
9. Verifier `#/tv-link`: saisie code et scan QR si support camera disponible.
10. Verifier le mode joueur de salon si une TV est liee.
11. Verifier `#/suggest-track`.
12. Verifier les pages `#/management*` avec un compte admin.

## Mercure

1. `GET action=getLobbyByCode` retourne `data.realtime.transport=mercure` si le hub est configure.
2. `GET action=listPublicLobbies` retourne `data.realtime.transport=mercure` si le hub est configure.
3. Les mises a jour lobby/joueurs apparaissent sans rechargement manuel.
4. En cas d'indisponibilite Mercure, l'interface se reconstruit par HTTP sans rester bloquee.

## Criteres go/no-go

- Toutes les actions valides retournent `success=true`.
- Les erreurs retournent `success=false` avec un status HTTP coherent.
- Les droits owner/admin sont bloques pour les autres utilisateurs.
- Les donnees solution ne sont pas exposees trop tot aux joueurs qui n'ont pas trouve.
- Le score se met a jour apres reponse correcte.
- Les joueurs absents ne bloquent pas les votes/transitions.
- Les suggestions bloquent bien le passage quand un hold est actif.
- Les salons actifs/passifs restent separes dans les listes.
- La TV ne bloque pas le demarrage des manches.
- Chaque session archivee possede au moins un participant et aucun enfant orphelin.
- Une seconde execution du backfill ne cree aucune session supplementaire.
- Aucune doc interne ou secret n'est present dans les dossiers PROD.
