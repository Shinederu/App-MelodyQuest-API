<?php

require_once __DIR__ . '/../config/config.php';

class MercureService
{
    public function canPublish(): bool
    {
        return MQ_MERCURE_PUBLISH_URL !== '' && MQ_MERCURE_PUBLISHER_JWT_KEY !== '';
    }

    public function canAuthorizeSubscribers(): bool
    {
        return MQ_MERCURE_HUB_URL !== '' && MQ_MERCURE_SUBSCRIBER_JWT_KEY !== '';
    }

    public function getHubUrl(): string
    {
        return MQ_MERCURE_HUB_URL;
    }

    public function getPublishUrl(): string
    {
        return MQ_MERCURE_PUBLISH_URL;
    }

    public function getPublicLobbiesTopic(): string
    {
        return MQ_MERCURE_TOPIC_BASE . '/public-lobbies';
    }

    public function getLobbyTopic(string $lobbyCode): string
    {
        return MQ_MERCURE_TOPIC_BASE . '/lobbies/' . rawurlencode(strtoupper(trim($lobbyCode)));
    }

    public function authorizeLobbySubscription(string $lobbyCode): void
    {
        if (!$this->canAuthorizeSubscribers()) {
            return;
        }

        $topic = $this->getLobbyTopic($lobbyCode);
        $ttl = MQ_MERCURE_SUBSCRIBER_TTL_SECONDS;
        $token = $this->createJwt(
            [
                'mercure' => [
                    'subscribe' => [$topic],
                ],
            ],
            MQ_MERCURE_SUBSCRIBER_JWT_KEY,
            $ttl
        );

        setcookie('mercureAuthorization', $token, [
            'expires' => time() + $ttl,
            'path' => MQ_MERCURE_COOKIE_PATH,
            'domain' => MQ_MERCURE_COOKIE_DOMAIN,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function publish(string $topic, array $payload, bool $private, string $eventType, ?string $eventId = null): bool
    {
        if (!$this->canPublish()) {
            return false;
        }

        $postFields = [
            'topic' => $topic,
            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'type' => $eventType,
        ];

        if ($private) {
            $postFields['private'] = 'on';
        }

        if ($eventId !== null && $eventId !== '') {
            $postFields['id'] = $eventId;
        }

        $token = $this->createJwt(
            [
                'mercure' => [
                    'publish' => ['*'],
                ],
            ],
            MQ_MERCURE_PUBLISHER_JWT_KEY,
            300
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'ignore_errors' => true,
                'timeout' => MQ_MERCURE_PUBLISH_TIMEOUT_SECONDS,
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/x-www-form-urlencoded',
                ]),
                'content' => http_build_query($postFields, '', '&', PHP_QUERY_RFC3986),
            ],
        ]);

        $result = @file_get_contents($this->getPublishUrl(), false, $context);
        $statusLine = $http_response_header[0] ?? '';
        $statusCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int)$matches[1] : 0;

        if ($result === false || $statusCode < 200 || $statusCode >= 300) {
            error_log('MelodyQuest Mercure publish failed for topic ' . $topic);
            return false;
        }

        return true;
    }

    private function createJwt(array $claims, string $secret, int $ttlSeconds): string
    {
        $issuedAt = time();
        $payload = $claims + [
            'iat' => $issuedAt,
            'exp' => $issuedAt + max(1, $ttlSeconds),
        ];

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
