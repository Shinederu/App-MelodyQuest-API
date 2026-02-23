<?php

function get_action(string $method, array $body): ?string
{
    if ($method === 'GET') {
        return $_GET['action'] ?? null;
    }

    if (isset($body['action']) && is_string($body['action'])) {
        return $body['action'];
    }

    return $_REQUEST['action'] ?? null;
}

function get_body(): array
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'DELETE'], true)) {
        return [];
    }

    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    if (!empty($_POST) && is_array($_POST)) {
        return $_POST;
    }

    return [];
}

function get_session_id(): ?string
{
    if (!empty($_COOKIE['sid'])) {
        return trim((string)$_COOKIE['sid']);
    }
    if (!empty($_COOKIE['session_id'])) {
        return trim((string)$_COOKIE['session_id']);
    }
    if (!empty($_SERVER['HTTP_X_SESSION_ID'])) {
        return trim((string)$_SERVER['HTTP_X_SESSION_ID']);
    }
    if (!empty($_SERVER['HTTP_SESSION_ID'])) {
        return trim((string)$_SERVER['HTTP_SESSION_ID']);
    }

    return null;
}

