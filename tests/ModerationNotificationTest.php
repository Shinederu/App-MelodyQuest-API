<?php

namespace PHPMailer\PHPMailer {
    #[\AllowDynamicProperties]
    class PHPMailer {
        public const ENCRYPTION_SMTPS = 'ssl';
        public const ENCRYPTION_STARTTLS = 'tls';
        public static array $sent = [];
        public static bool $fail = false;
        public function __construct(bool $exceptions = true) {}
        public function isSMTP(): void {}
        public function setFrom(string $address, string $name): void { $this->from = $address; }
        public function addAddress(string $address): void { $this->recipient = $address; }
        public function send(): bool {
            if (self::$fail) throw new \RuntimeException('Test SMTP unavailable');
            self::$sent[] = ['recipient' => $this->recipient, 'subject' => $this->Subject, 'body' => $this->Body];
            return true;
        }
    }
}

namespace {
    require_once __DIR__ . '/bootstrap.php';
    require_once __DIR__ . '/../services/ModerationNotificationService.php';
    mqTest('Les notifications ont un destinataire fixe et un lien de moderation', function (): void {
        $before = $_ENV;
        try {
            $_ENV['MQ_MODERATION_EMAIL_ENABLED'] = 'true';
            $_ENV['SMTP_HOST'] = 'localhost';
            $_ENV['SMTP_FROM'] = 'fixture@example.test';
            mqAssertTrue(ModerationNotificationService::send('suggestion', 42, 'Titre QA'));
            $sent = \PHPMailer\PHPMailer\PHPMailer::$sent;
            mqAssertSame('contact@shinederu.ch', $sent[0]['recipient']);
            mqAssertTrue(str_contains($sent[0]['body'], '#/management-suggestions'));
            $_ENV['MQ_MODERATION_EMAIL_ENABLED'] = 'false';
            mqAssertFalse(ModerationNotificationService::send('track', 43, 'Pas de mail'));
            mqAssertSame(1, count(\PHPMailer\PHPMailer\PHPMailer::$sent));
        } finally { $_ENV = $before; }
    });
    mqTest('Un echec SMTP ne fait pas echouer la soumission', function (): void {
        $before = $_ENV;
        try {
            $_ENV['SMTP_HOST'] = 'localhost';
            $_ENV['SMTP_FROM'] = 'fixture@example.test';
            $_ENV['MQ_MODERATION_EMAIL_ENABLED'] = 'true';
            \PHPMailer\PHPMailer\PHPMailer::$fail = true;
            mqAssertFalse(ModerationNotificationService::send('track', 44, 'Test echec'));
        } finally { $_ENV = $before; \PHPMailer\PHPMailer\PHPMailer::$fail = false; }
    });
}
