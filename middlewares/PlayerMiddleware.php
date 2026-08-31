<?php

require_once __DIR__ . '/AuthMiddleware.php';
require_once __DIR__ . '/../services/PlayerSessionService.php';

final class PlayerMiddleware
{
    public static function optional(bool $touchGuest = true): ?array
    {
        $userId = AuthMiddleware::optional();
        $sessions = new PlayerSessionService();
        if ($userId !== null && $userId > 0) {
            return $sessions->getIdentityByActorId($userId);
        }

        return $sessions->resolveCurrentGuest($touchGuest);
    }

    public static function check(?string $requestedNickname = null): array
    {
        $identity = self::optional();
        if ($identity !== null) {
            return $identity;
        }

        return (new PlayerSessionService())->createGuest($requestedNickname);
    }

    public static function renameGuest(string $nickname): array
    {
        if (AuthMiddleware::optional() !== null) {
            throw new RuntimeException('Le pseudo d’un compte se modifie depuis le profil utilisateur');
        }

        return (new PlayerSessionService())->renameCurrentGuest($nickname);
    }

    public static function endGuest(): void
    {
        (new PlayerSessionService())->endCurrentGuest();
    }
}
