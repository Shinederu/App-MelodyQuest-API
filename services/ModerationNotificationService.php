<?php

require_once __DIR__ . '/DatabaseService.php';
require_once __DIR__ . '/../utils/after_response.php';

class ModerationNotificationService
{
    public static function queue(string $kind, int $id): void
    {
        mq_defer_after_response('moderation-' . $kind . '-' . $id, static function () use ($kind, $id): void {
            $db = DatabaseService::getInstance();
            $query = $kind === 'track'
                ? 'SELECT title FROM mq_tracks WHERE id = :id AND is_validated = 0'
                : 'SELECT COALESCE(proposed_title, current_title, proposed_alias, "Proposition") AS title FROM mq_player_suggestions WHERE id = :id AND status = "pending"';
            $stmt = $db->prepare($query);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) return;
            self::send($kind, $id, (string)$row['title']);
        });
    }

    public static function send(string $kind, int $id, string $title): bool
    {
        if (!filter_var($_ENV['MQ_MODERATION_EMAIL_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN)) return false;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class) || empty($_ENV['SMTP_HOST']) || empty($_ENV['SMTP_FROM'])) {
            error_log('MelodyQuest moderation email unavailable: SMTP configuration or PHPMailer missing');
            return false;
        }
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'];
            $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 465);
            $mail->SMTPAuth = filter_var($_ENV['SMTP_AUTH'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $mail->Username = $_ENV['SMTP_USER'] ?? '';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            $secure = strtolower($_ENV['SMTP_SECURE'] ?? ($mail->Port === 587 ? 'starttls' : 'smtps'));
            $mail->SMTPSecure = match ($secure) {
                'ssl', 'smtps' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
                'tls', 'starttls' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $mail->Timeout = 5;
            $mail->Timelimit = 10;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($_ENV['SMTP_FROM'], $_ENV['SMTP_NAME'] ?? 'MelodyQuest');
            $mail->addAddress('contact@shinederu.ch');
            $label = $kind === 'track' ? 'Musique à valider' : 'Proposition à examiner';
            $route = $kind === 'track' ? 'management-validation' : 'management-suggestions';
            $mail->Subject = 'MelodyQuest : ' . $label;
            $mail->Body = $label . ' #' . $id . "\n\n" . $title . "\n\nhttps://melodyquest.shinederu.ch/#/" . $route;
            return $mail->send();
        } catch (Throwable $error) {
            error_log('MelodyQuest moderation email failed: ' . $kind . ' #' . $id);
            return false;
        }
    }
}
