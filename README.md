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
- identites joueur compte ou invite;
- snapshots Mercure.

Le frontend source vit dans `P:\DEV\GitHub\App-MelodyQuest`.

## Statut et priorite

MelodyQuest est le seul produit Shinede en developpement continu. Les pistes
documentees restent cependant un parking tant qu'elles ne sont pas
explicitement priorisees. Toute evolution backend doit repondre a un besoin
produit concret et employer le plus petit contrat, stockage ou flux suffisant.

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
- `bin\` (worker de reprise temps reel uniquement)
- `config\`
- `controllers\`
- `middlewares\`
- `repositories\`
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
  - `autoplay`: mode passif avec enchainement automatique, sans score, sans votes de manche et sans reponse attendue. Le sondage de connaissance de l'œuvre reste facultatif apres revelation.
- Les salons passifs restent de vrais salons: code, membres, partage et liaison TV.
- Les nouveaux salons sont publics par defaut dans les deux modes: 30 manches de 20 secondes.
- La categorie visible est activee par defaut pour les nouveaux salons.
- La precision des reponses est a `80%` par defaut pour les nouveaux salons.
- La selection musicale est equilibree entre categories selectionnees quand c'est possible.
- Les pistes jouees par les participants pendant les 30 derniers jours sont evitees en priorite; la contrainte est relachee par paliers si le catalogue est trop petit.
- La presence joueur est manuelle (`active` / `away`).
- Le createur peut passer un joueur present/absent ou l'exclure.
- Les joueurs absents ne bloquent pas les votes/transitions et recoivent un bonus de compensation.
- Les essais de reponse actifs et archives sont reunis sans doublon pour admin/statistiques.
- L'analyse admin regroupe les fautes proches par oeuvre, remonte les alias recurrents et distingue les idees de contenu.
- Les suggestions joueurs peuvent etre editees, refusees, marquees traitees ou appliquees directement au catalogue.
- Les demandes de suppression exigent un motif et une confirmation admin explicite; les signalements automatiques de videos indisponibles ne suppriment ni ne desactivent une piste.
- Les pistes ont des bornes debut/fin en secondes et une note de notoriété optionnelle de 1 a 10.
- Le lobby peut filtrer par notoriété minimale; toutes les pistes restent eligibles par defaut.
- Une nouvelle musique a valider ou proposition declenche une notification best-effort a `contact@shinederu.ch`.
- Les avatars historiques `action=getAvatar` sont normalises vers l'API Auth active avant retour frontend.
- Le mode TV frontend utilise un lecteur YouTube simple; l'action experimentale `markTvRoundReady` n'existe plus.
- Les publications Mercure sont coalescees dans `mq_realtime_outbox` et executees apres la reponse HTTP.
- La racine frontend et les parcours joueur fonctionnent sans compte.
- Les comptes utilisent `actor_id = users.id`; les invites utilisent `actor_id = -mq_guest_sessions.id`.
- Une session invitee expire apres 2 heures d'inactivite glissantes et ne cree jamais de ligne dans `users`.
- Un sondage facultatif Oui/Non concerne l'œuvre a deviner, en actif ou passif,
  uniquement quand la solution est accessible au joueur. Aucun effet sur le jeu.

## Contraintes produit

- Compte facultatif pour jouer; authentification obligatoire uniquement pour l'administration.
- Proposition publique de nouvelle musique possible sans session.
- Stockage des pistes par identifiant YouTube; aucun fichier audio local.
- YouTube reste la source principale.
- Commandes metier via HTTP API.
- Mercure publie les snapshots et ne remplace pas les endpoints HTTP.
- Administration musicale reservee a `melodyquest.catalog.manage` ou au super-admin global.
- `users.role='admin'` reste seulement un fallback de transition.

## Structure

- `index.php`: routeur par action/methode HTTP.
- `bin\`: worker CLI runtime de reprise de la file temps reel.
- `config\`: configuration runtime non secrete et constantes.
- `controllers\`: validation payload et reponses HTTP.
- `middlewares\`: session auth, identite joueur compte/invite et permissions.
- `repositories\`: persistance specialisee et testable, notamment historique et outbox temps reel.
- `services\`: logique metier, DB, selection anti-repetition, analyse des reponses, Mercure, outbox et suggestions.
- `utils\`: helpers request/response/YouTube et travaux post-reponse.
- `sql\`: migrations source.
- `scripts\`: outils CLI source, notamment imports catalogue et backfill d'historique.
- `tests\`: tests PHP sans dependance externe.

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
- `018_melodyquest_game_history.sql`: sessions de jeu et snapshots append-only.
- `019_melodyquest_realtime_outbox.sql`: file durable et coalescee des snapshots Mercure.
- `020_melodyquest_guest_players.sql`: sessions invitees, namespace `actor_id` et attribution anonyme de l'historique.
- `021_melodyquest_track_preferences.sql`: notoriété des pistes, seuil des salons, oeuvre/timestamps des propositions et defauts 30/20. Ajouts idempotents, aucune suppression; les bornes de `mq_tracks` existaient deja.
- `022_melodyquest_playback_reports.sql`: types `track_removal` / `video_unavailable`, cle unique des signalements en attente et echeance `mq_rounds.unavailable_skip_at`. Aucune suppression de donnees; a appliquer avant le nouveau runtime.
- `023_melodyquest_family_knowledge.sql`: avis par œuvre, uniques par compte ou
  session invitee. Expiration invitee: FK SET NULL, avis anonymise conserve.
  Aucune migration ne remet automatiquement le catalogue en attente.

Regles DB:

- Les migrations significatives restent dans `sql\`.
- Les migrations doivent etre idempotentes quand c'est possible.
- Ne jamais supprimer de donnees sans demande explicite.
- Les anciennes donnees de tentatives/reponses sont conservees pour statistiques et analyse admin.
- L'historique `mq_game_session_*` ne reference pas les tables live: les noms, pistes, categories et utilisateurs utiles sont snapshots.
- Les colonnes `user_id` restent reservees aux comptes et sont `NULL` pour un invite; `actor_id` identifie tous les joueurs.
- La suppression d'une session invitee expiree conserve les snapshots et historiques de partie.

### Historique des parties

Une session est archivee avant toute remise a zero, suppression explicite, fermeture du dernier joueur ou purge d'un salon inactif. L'archive et l'operation destructive partagent une transaction; une erreur d'archive bloque donc la suppression.

Tables:

- `mq_game_sessions`: salon, proprietaire, mode, configuration et dates;
- `mq_game_session_players`: participants, role, presence et score final;
- `mq_game_session_rounds`: piste et libelles figes par manche;
- `mq_game_session_answers` et `mq_game_session_answer_attempts`;
- `mq_game_session_reveal_votes` et `mq_game_session_away_bonuses`.

Le repository complete les anciens participants manquants a partir des activites disponibles, avec `INSERT IGNORE`: aucune ligne d'historique existante n'est ecrasee.

Backfill idempotent des salons deja termines ou fermes:

```powershell
php P:\DEV\GitHub\App-MelodyQuest-API\scripts\backfill_game_history.php --env-dir=P:\PROD\API\auth --db-host=192.168.10.10
```

La migration `018` doit etre appliquee auparavant avec un compte autorise a creer des tables. Le script utilise ensuite le compte applicatif et ne modifie ni ne supprime les donnees sources.

## Remise en verification manuelle

Commande source-only: `scripts/recheck_catalog.php`, dry-run par defaut.
Exemple de precontrole sans modification:

```powershell
php scripts/recheck_catalog.php --env-dir=P:\PROD\API\auth --db-host=192.168.10.10 --database=ShinedeCore
```

L'application explicite ajoute `--apply --expected-count=<nombre-verifie>` et
`--backup=<chemin-absolu-hors-PROD.json>`. Le fichier doit etre nouveau. La
transaction verrouille les salons et pistes, refuse toute partie en cours,
exporte les lignes avant modification, puis remet uniquement la validation a
zero (validateur/date effaces). Les timecodes, titres, IDs, notes et autres
metadonnees sont compares avant/apres; historique, œuvres et alias restent intacts.
Aucun mail par piste ni suppression. Sans piste revalidee, aucune nouvelle
partie ne peut demarrer. Ne jamais automatiser/rejouer cette operation au
deploiement. Pour une restauration exceptionnelle, relire le backup et les
modifications posterieures avant toute ecriture; pas de restauration aveugle.

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

## Import catalogue JSON

Les lots JSON structures utilisent:

```text
P:\DEV\GitHub\App-MelodyQuest-API\scripts\import_catalog_expansion.php
```

Commandes utiles:

```powershell
php scripts\import_catalog_expansion.php --file=scripts\catalog\<lot>.json --env-dir=P:\PROD\API\auth --db-host=192.168.10.10 --dry-run
php scripts\import_catalog_expansion.php --file=scripts\catalog\<lot>.json --env-dir=P:\PROD\API\auth --db-host=192.168.10.10
php scripts\export_catalog.php --output=P:\DEV\Temp\melodyquest-catalog.json --env-dir=P:\PROD\API\auth --db-host=192.168.10.10
```

Le lot `2026-07-27_catalog_expansion.json` ajoute 250 pistes dans chacune
des sept categories standard et 40 pistes de YouTubeurs francais. Les pistes
sont importees actives et validees; l'import est transactionnel et refuse les
identifiants YouTube deja presents.

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

## Identite joueur et invites

- `GET action=getPlayerIdentity`: renvoie le compte courant, la session invitee courante ou `null`; ne cree pas de session.
- `POST action=updateGuestNickname`: cree au besoin la session invitee puis valide/modifie son pseudo.
- `POST action=endGuestSession`: retire l'invite de ses salons actifs, supprime la session temporaire et efface le cookie.
- Cookie invite: `mq_guest`, token aleatoire 256 bits, `Secure`, `HttpOnly`, `SameSite=Lax`, chemin `/melodyquest/`.
- Seul le hash SHA-256 du token est stocke dans `mq_guest_sessions`.
- TTL glissant par defaut: `7200` secondes; le prolongement DB est limite a une ecriture toutes les 5 minutes.
- Les pseudos font 3 a 32 lettres/chiffres/tirets/underscores, ne peuvent pas usurper un compte ou un nom reserve et restent uniques parmi les invites actifs.
- Les `actor_id` positifs sont des comptes; les `actor_id` negatifs sont des invites. `0` n'est jamais une identite serveur valide.
- Les invites n'obtiennent jamais les permissions admin et leurs statistiques de profil ne sont pas conservees.

## Actions joueur compte ou invite

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

- `createLobby` accepte `game_mode`, `visibility`, `total_rounds`, `round_duration_seconds`, `reveal_duration_seconds`, `selected_category_ids`, `show_track_category`, `allow_early_reveal_vote`, `answer_similarity_threshold`, `min_familiarity`.
- `min_familiarity` vaut 1..10, defaut 1. Les pistes sans note sont traitees comme 1 pour le filtre, sans leur attribuer artificiellement une note. Le filtre s'applique au comptage, au tirage, aux pools et aux preloads; les categories restent equilibrees dans le catalogue eligible.
- `listCategories` expose `track_counts_by_familiarity` (cles 1..10 et 0 pour non note) pour actualiser les comptes du lobby sans nouvelle requete.
- `createLobby` active `show_track_category` par defaut si absent.
- `createLobby` utilise `MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD`, `80` par defaut.
- `updateLobbyConfig` accepte les memes options de reglage.
- `listPublicLobbies` filtre par `game_mode`; `participative` est le defaut.
- `touchLobby` accepte `presence_status` (`active`, `away`) et, pour le createur, `target_actor_id` (`target_user_id` reste tolere pour compatibilite).
- `kickPlayer` retire un joueur du salon sans detruire son historique de score.
- `voteRevealRound` demande 100% des joueurs actifs, refuse si l'option est desactivee, si quelqu'un a deja trouve, ou si la reponse est deja revelee.
- `voteNextRound` demande 50% des joueurs actifs apres revelation et refuse tant qu'un verrou de suggestion est actif.
- `holdSuggestion` bloque temporairement le passage a la manche suivante pendant qu'un joueur propose une correction.
- `submitSuggestion` accepte `track_correction` ou `track_removal` depuis une partie compte/invite et `new_track` depuis la page publique. `track_removal` requiert un `note` non vide; `applySuggestion` exige `confirm_removal: true` (booleen strict) avant suppression et refuse une piste encore referencee par une manche.
- Une correction accepte soit `proposed_alias`, soit `proposed_family_name` (nom de l'oeuvre a deviner), ainsi que `proposed_title`, `proposed_artist`, `proposed_youtube_url`, `proposed_start_offset_seconds` et `proposed_end_offset_seconds`.
- Les timestamps sont des secondes entieres entre 0 et 86400, avec fin strictement apres debut. Une proposition vide laisse les valeurs actuelles; `0` est une proposition de debut valide.
- `submitSuggestion` limite l'abus a 5 suggestions par 10 minutes et par acteur/IP.
- `submitAnswer` utilise `answer_similarity_threshold` avec similarite hybride et garde-fous sur reponses courtes.

## Signalements de lecture

- `POST action=reportPlaybackError`: `lobby_id`, `round_id`, `youtube_video_id`,
  `error_code` entier 100/101/150. Identite membre compte/invite, ou `device_token`
  d'une TV actuellement liee au meme salon. Aucun appel anonyme sans jeton valide.
- La video doit correspondre a la manche courante d'une partie en cours.
  Les erreurs 2/5/153 et le buffering ne sont pas des preuves de suppression.
- Une suggestion `video_unavailable` en attente par video via `automatic_report_key`
  unique; une seule notification best-effort a sa creation. La cle est liberee
  lorsque le signalement est traite, refuse ou applique. Le catalogue ne change pas.
- Seul le createur ou une TV liee programme `unavailable_skip_at`, a NOW(3)+6s.
  Un membre ordinaire peut signaler sans interrompre les autres appareils.
- `POST action=advanceUnavailableRound`: `lobby_id`, `round_id`, meme authentification.
  Verrouille salon/manche, attend l'echeance et la fin des verrous de proposition,
  puis termine la manche et cree la suivante (ou termine la partie).
  Reponse `advanced`, et `stale` si la manche n'est plus courante; appels idempotents.
- Les transitions utilisent l'outbox Mercure existante. L'echeance du snapshot
  `round.unavailable_skip_at_unix` est calculee par UNIX_TIMESTAMP MySQL, sans
  reinterpreter le fuseau horaire en PHP. Pas de nouveau timer/worker serveur.
- Les points deja attribues et l'historique sont conserves. Aucun changement
  de synchronisation saine, de prechargement ou de qualite YouTube.

## Round state

### Connaissance des œuvres

- `GET action=getFamilyKnowledge&lobby_id=...&round_id=...`
- `POST action=voteFamilyKnowledge`: `lobby_id`, `round_id`, `known` booleen strict.
- Identite joueur et appartenance au salon requises, compte ou invite. Pas de
  vote anonyme ni de jeton TV. L'œuvre est derivee de la manche courante, jamais
  acceptee depuis le client; les memes regles de visibilite que `getRoundState`
  s'appliquent (joueur ayant trouve ou revelation globale, hors video indisponible).
- Table `mq_family_knowledge`: un avis par œuvre/compte ou œuvre/session invitee,
  modifiable par upsert. Plusieurs musiques de la meme œuvre ne multiplient pas les avis.
- Reponse privee `family_id`, `choice` (bool/null), `known_count`, `vote_count`,
  `known_percent` (entier arrondi ou null sans vote). `listFamilies` ajoute
  `knowledge` avec les trois valeurs agregees, jamais les choix individuels.
- Le pourcentage vaut `100 * Oui / (Oui + Non)`. Il ne mesure que les repondants,
  pas la population generale. Afficher l'effectif; un petit echantillon reste fragile.
- Aucun pseudo, horodatage de vote ou historique de preferences n'est stocke.
  A expiration/suppression d'un invite, son rattachement disparait mais son avis
  participe toujours au total anonyme. Un nouvel invite peut donc revoter;
  ce sondage n'est pas un scrutin resistant aux comptes/sessions multiples.
- Pas de nouvelle publication Mercure, de polling, de score ou de verrou de manche.
  La note editoriale `mq_tracks.familiarity` et `min_familiarity` restent independantes.

### Snapshot de manche

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

- `round.track` contient les donnees necessaires a la lecture: `youtube_video_id`, `start_offset_seconds`, `end_offset_seconds`;
- la categorie peut etre incluse si le salon l'autorise;
- les champs solution sont exposes au joueur qui a trouve, puis a tout le monde apres revelation.

`next_track` et `upcoming_tracks` restent expurges des champs solution.

Les joueurs ne recoivent pas l'historique complet des tentatives. `answer_attempts` expose seulement les derniers essais rates visibles. L'historique complet reste en DB pour admin/statistiques.

## Mode TV public

- `POST action=createTvPairing`: cree un code court et un `device_token`.
- `GET action=getTvPairing&device_token=...`: statut du code TV.
- `GET action=getTvState&device_token=...`: snapshot lobby/round/scoreboard pour la TV liee, sans session auth utilisateur.

Il n'existe plus d'action `markTvRoundReady`.

La liaison reste autorisee dans un salon `finished` pour preparer une nouvelle partie, mais pas dans un salon ferme. Les bornes d'extrait ne modifient ni le timer des manches ni le protocole de synchronisation.

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
- `GET action=listAnswerAttempts&outcome=wrong|correct|scored|all&category_id=...&period=7|30|90|365|all&search=...`
- `POST action=addFamilyAlias`
- `DELETE action=deleteCategory`
- `DELETE action=deleteFamily`
- `DELETE action=deleteTrack`

Details:

- `listPendingTracks` accepte `category_id`, `search`, `page`, `page_size` (1..100,
  defaut 50). Reponse `items`, `total` filtre, `pending_total` global, `page`,
  `pages`, `page_size`. Pages hors plage ramenees a la derniere, ordre stable
  categorie/œuvre/titre/ID. Le frontend ne doit pas compter seulement `items`.
- `createTrack`, `updateTrack` et `validateTrack` acceptent `start_offset_seconds`, `end_offset_seconds` et `familiarity` (null ou entier 1..10), controles par `utils/track_options.php`.
- `validateTrack` accepte aussi `track_id`, `category_id`, `family_name`, `aliases`, `title`, `artist`, `youtube_video_id`, `youtube_url`.
- `replacement_family_name`, utilise par l'application d'une correction, renomme la famille existante (140 caracteres max): toutes ses pistes partagent ce nom. Identifiant, slug et alias sont conserves.
- Si `aliases` est fourni a `validateTrack`, la liste remplace les alias de l'oeuvre cible.
- `updateSuggestion` enregistre les champs editables d'une suggestion sans modifier le catalogue.
- `applySuggestion` applique une correction ou cree une nouvelle piste validee, puis marque la suggestion `reviewed`.
- Les champs de revue comprennent `proposed_family_name`, `admin_start_offset_seconds` et `admin_end_offset_seconds`. Une suggestion deja appliquee renvoie `already_applied` sans rejouer la modification.
- `updateSuggestionStatus` passe une suggestion en `pending`, `reviewed` ou `rejected`.
- `listAnswerAttempts` fusionne les essais live et `mq_game_session_answer_attempts`, ignore la copie live lorsqu'elle est deja archivee, puis expose `alias_candidates`, `content_ideas`, `items` et `summary`.
- Un candidat alias demande au moins deux occurrences ou deux joueurs. Les variantes proches d'une meme oeuvre sont regroupees avant classement.
- `addFamilyAlias` ajoute de facon idempotente un alias a une oeuvre existante. La route exige `melodyquest.catalog.manage` via le middleware admin.

## Authentification et permissions

- Session via cookie `sid`.
- Session invitee via cookie `mq_guest`; elle n'accorde aucun droit administratif.
- Tables Auth: `auth_sessions`, `users`.
- Table temporaire MelodyQuest: `mq_guest_sessions`.
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
- `MQ_GUEST_SESSION_TTL_SECONDS`, defaut `7200`, borne `900` a `86400`
- `MQ_GUEST_SESSION_TOUCH_INTERVAL_SECONDS`, defaut `300`, borne `60` a `900`
- `MQ_DEFAULT_ANSWER_SIMILARITY_THRESHOLD`, defaut `80`
- `MQ_TRACK_REPEAT_LOOKBACK_DAYS`, defaut `30`
- `MQ_TRACK_REPEAT_STRICT_DAYS`, defaut `7`, borne par la fenetre precedente
- `MQ_TRACK_REPEAT_HISTORY_LIMIT`, defaut `500`
- `MQ_AWAY_BONUS_PERCENT`, defaut `10`
- `MQ_ROUND_PRELOAD_SECONDS`, defaut `3`, borne `0` a `10`
- `MQ_TV_PRELOAD_LOOKAHEAD`, defaut `3`, borne `1` a `5`
- `MQ_MERCURE_TOPIC_BASE`, optionnel
- `MQ_MERCURE_PUBLISH_TIMEOUT_SECONDS`, defaut `1`, borne `0.2` a `3`
- `MQ_REALTIME_OUTBOX_BATCH_SIZE`, defaut `8`, borne `1` a `50`
- `MQ_REALTIME_OUTBOX_MAX_RUNTIME_MS`, defaut `2000`, borne `100` a `10000`
- `MQ_REALTIME_OUTBOX_LOCK_TIMEOUT_SECONDS`, defaut `30`, borne `5` a `300`
- `MQ_MODERATION_EMAIL_ENABLED`, defaut `true`
- SMTP partage existant: `SMTP_HOST`, `SMTP_PORT`, `SMTP_AUTH`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE`, `SMTP_FROM`, `SMTP_NAME` (aucune duplication des secrets)

`.env.example` est un exemple local versionne. Ne pas le copier en PROD.

### Notifications de moderation

`ModerationNotificationService` reutilise PHPMailer fourni par l'autoload Auth.
Le destinataire est fixe a `contact@shinederu.ch`. Creation/repassage en attente
d'une piste et soumission d'une proposition enregistrent un travail post-reponse.
Le service relit l'etat avant envoi et ignore un element deja traite/valide.
Il envoie un lien vers la page de gestion, sans donnees de session/joueur.
Une erreur SMTP est loggee sans annuler l'action utilisateur. Ce mecanisme est
best-effort: pas de retry durable ni de garantie de livraison en boite mail.
Desactiver `MQ_MODERATION_EMAIL_ENABLED` sur les environnements de test.

## Mercure

- Hub public: `https://mercure.shinederu.ch/.well-known/mercure`
- Publish interne recommande: `http://mercure/.well-known/mercure`
- Topic lobbies publics: `https://api.shinederu.ch/melodyquest/topics/public-lobbies`
- Topic lobby prive: `https://api.shinederu.ch/melodyquest/topics/lobbies/{LOBBY_CODE}`

Les reponses `listPublicLobbies` et `getLobbyByCode` exposent `data.realtime`.

Mercure sert a publier des evenements/snapshots. Les commandes critiques passent par HTTP.

- Le controleur persiste d'abord la commande metier puis marque le flux concerne dans `mq_realtime_outbox`.
- Une seule ligne est conservee par salon et pour la liste publique; plusieurs commandes rapprochees sont donc regroupees.
- La reponse JSON est envoyee et fermee avec `fastcgi_finish_request()` avant tout appel au hub.
- Le worker reconstruit le dernier snapshot depuis la DB, publie, puis acquitte la ligne avec controle de generation.
- Un echec Mercure conserve la ligne avec backoff; il ne transforme jamais une commande de jeu valide en erreur HTTP.
- Les clients gardent la resynchronisation HTTP comme source de verite.

En fonctionnement normal, chaque commande ayant marque un flux declenche un court drainage post-reponse. Reprise manuelle ponctuelle:

```powershell
php P:\PROD\API\melodyquest\bin\process_realtime_outbox.php
```

Worker continu optionnel si l'infrastructure le supervise:

```powershell
php P:\PROD\API\melodyquest\bin\process_realtime_outbox.php --loop
```

Le mode continu n'est pas requis par le runtime FPM actuel; il sert de filet de reprise ou de futur worker dedie.

Chaque etat temps reel doit pouvoir etre reconstruit via HTTP:

- `listPublicLobbies`
- `getLobbyByCode`
- `getRoundState`
- `getTvState`

Il n'y a pas de fallback SSE supporte dans l'API actuelle.

## Dossiers runtime et fichiers partages

- `P:\PROD\API\melodyquest` contient uniquement le runtime PHP.
- Aucun stockage fichier persistant n'est possede par MelodyQuest.
- Catalogue, lobbies, scores, suggestions, tentatives, liaisons TV et outbox temps reel vivent en DB.
- Logs applicatifs via `error_log` PHP.
- Ne jamais logger de secret, mot de passe, token ou JWT complet.

## Dependances inter-projets

- `Module-Auth-API`: sessions, utilisateurs, avatars.
- `Module-ShinedeCore-PHP`: permissions `core_*`.
- `App-MelodyQuest`: frontend navigateur.
- Mercure: snapshots.
- MySQL `ShinedeCore`: `users`, `auth_sessions`, `core_*`, `mq_*`; aucun faux compte n'est cree pour un invite.

Une integration avec un autre projet doit passer par l'API proprietaire du projet cible, pas par ecriture directe dans ses tables.

## Verifications

```powershell
Get-ChildItem P:\DEV\GitHub\App-MelodyQuest-API -Recurse -Filter *.php | % { php -l $_.FullName }
php P:\DEV\GitHub\App-MelodyQuest-API\tests\run.php
git -c safe.directory=* diff --check
rg -n "password|passwd|secret|BEGIN (RSA|OPENSSH|PRIVATE)|api_key" P:\DEV\GitHub\App-MelodyQuest-API
```

Smoke tests fonctionnels: voir `PROD_TEST_CHECKLIST.md`.

Tests d'integration opt-in: `php tests/integration.php --local-fixture`.
Ils utilisent uniquement `mq_ui_test` sur `127.0.0.1:33307` avec un MySQL jetable,
les migrations 001..023 et deux comptes fixture 1/2. Ils ne lisent pas la DB
partagee. Couverture: defauts, moderation des invites, conservation des scores,
filtre musical, application des corrections et liaison TV apres une partie.

## Deploiement

Preserver les fichiers runtime deja presents en PROD si un jour ils existent (`.env`, logs, caches runtime).

Pour cette version, respecter cet ordre afin d'eviter un contrat mixte:

1. verifier les migrations jusqu'a `022`, puis appliquer `sql\023_melodyquest_family_knowledge.sql` sur `ShinedeCore`;
2. deployer immediatement le runtime API;
3. deployer ensuite le frontend `20260914-familiarity-review`;
4. verifier un compte existant, les invites, les filtres et une proposition; ne pas envoyer de fausses propositions dans le catalogue live pour les tests automatises.

Le fichier SQL reste dans DEV et ne doit pas etre copie dans le runtime PROD.

Copie runtime type:

```powershell
$src = 'P:\DEV\GitHub\App-MelodyQuest-API'
$dst = 'P:\PROD\API\melodyquest'
Copy-Item "$src\index.php" "$dst\index.php" -Force
foreach ($dir in @('bin','config','controllers','middlewares','repositories','services','utils')) {
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
- Deployer `020` avant le runtime qui ecrit `actor_id`; la migration est idempotente et retroalimente les comptes existants sans supprimer de lignes.
- Les donnees historiques de tentatives/reponses sont utiles pour statistiques et amelioration catalogue.
- L'anti-repetition s'appuie sur `mq_game_session_rounds` et les participants du salon; ne pas ajouter une seconde table d'historique sans besoin distinct.
- Si une modification touche le player ou la TV, tester un vrai salon avec telephone/PC/TV avant de conclure.
