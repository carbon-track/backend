<?php

namespace CarbonTrack\Services;

use Monolog\Logger;
use PHPMailer\PHPMailer\PHPMailer;

class EmailService
{
    protected ?PHPMailer $mailer = null;
    protected $config;
    protected $logger;
    protected bool $forceSimulation = false;
    private string $fromAddress = 'noreply@example.com';
    private string $fromName = 'CarbonTrack';

    private const TAG_ACTIVITY_NAME = '{{activity_name}}';
    private const TAG_POINTS_EARNED = '{{points_earned}}';
    private const TAG_REASON = '{{reason}}';
    private const TAG_PRODUCT_NAME = '{{product_name}}';
    private const TAG_QUANTITY = '{{quantity}}';
    private const TAG_TOTAL_POINTS = '{{total_points}}';
    private const TAG_STATUS = '{{status}}';
    private const TAG_ADMIN_NOTES = '{{admin_notes}}';

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->forceSimulation = $this->normalizeForceSimulation($config['force_simulation'] ?? false);

        if (!$this->forceSimulation && class_exists(PHPMailer::class)) {
            $this->mailer = new PHPMailer(true);
            $this->configureMailer();
        } else {
            $this->mailer = null;

            if ($this->forceSimulation) {
                $this->logger->info('EmailService running in forced simulation mode.');
            } else {
                $this->logger->warning('PHPMailer not available; EmailService will simulate sending emails.');
            }
        }
    }

    private function normalizeForceSimulation($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private function configureMailer(): void
    {
        try {
            if ($this->mailer === null) {
                return;
            }

            $debugLevel = (int) ($this->config['smtp_debug'] ?? 0);
            $this->mailer->SMTPDebug = $debugLevel;
            if ($debugLevel > 0) {
                $this->mailer->Debugoutput = function ($str, $level): void {
                    try {
                        $this->logger->debug('SMTP debug output', ['level' => $level, 'message' => $str]);
                    } catch (\Throwable $logError) {
                        // Swallow logging errors to avoid breaking mail flow
                    }
                };
            }

            $this->mailer->isSMTP();
            $this->mailer->Host = $this->config['host'] ?? '';
            $this->mailer->SMTPAuth = !empty($this->config['username']);
            $this->mailer->Username = $this->config['username'] ?? '';
            $this->mailer->Password = $this->config['password'] ?? '';

            $encryption = $this->config['encryption'] ?? 'tls';
            if (in_array($encryption, ['ssl', 'tls'], true)) {
                $constant = $encryption === 'ssl'
                    ? 'PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_SMTPS'
                    : 'PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_STARTTLS';

                $this->mailer->SMTPSecure = defined($constant) ? constant($constant) : $encryption;
            } else {
                $this->mailer->SMTPSecure = $encryption;
            }

            $this->mailer->Port = (int) ($this->config['port'] ?? 587);

            $fromAddress = $this->config['from_address'] ?? ($this->config['from_email'] ?? 'noreply@example.com');
            $fromName = $this->config['from_name'] ?? 'CarbonTrack';
            $this->fromAddress = $fromAddress ?: 'noreply@example.com';
            $this->fromName = $fromName ?: 'CarbonTrack';

            $this->mailer->setFrom($this->fromAddress, $this->fromName);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';
        } catch (\Throwable $e) {
            $this->logger->error("Mailer configuration error: {$e->getMessage()}");
            $this->mailer = null;
        }
    }

    public function sendEmail(string $toEmail, string $toName, string $subject, string $bodyHtml, string $bodyText = ""): bool
    {
        try {
            $mailer = $this->mailer;

            if (!$this->forceSimulation && $mailer instanceof PHPMailer) {
                $mailer->clearAddresses();
                if (method_exists($mailer, 'clearAttachments')) {
                    $mailer->clearAttachments();
                }
                if (method_exists($mailer, 'clearBCCs')) {
                    $mailer->clearBCCs();
                }
                $mailer->addAddress($toEmail, $toName);

                $mailer->Subject = $subject;
                $mailer->Body = $bodyHtml;
                $mailer->AltBody = $bodyText ?: strip_tags($bodyHtml);

                $mailer->send();
                $this->logger->info('Email sent successfully', ['to' => $toEmail, 'subject' => $subject]);
                return true;
            }

            $reason = $this->forceSimulation ? 'force_simulation' : 'mailer_unavailable';
            $this->logger->info('Simulated email send', ['to' => $toEmail, 'subject' => $subject, 'reason' => $reason]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Message could not be sent.', ['to' => $toEmail, 'subject' => $subject, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Send a broadcast email using BCC to protect recipient privacy
     * @param array<int, array{email:string, name: string|null}> $recipients
     */
    public function sendBroadcastEmail(array $recipients, string $subject, string $bodyHtml, string $bodyText = ""): bool
    {
        $cleaned = [];
        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $name = $recipient['name'] ?? null;
            $cleaned[] = ['email' => $email, 'name' => $name];
        }

        if (empty($cleaned)) {
            return false;
        }

        try {
            $mailer = $this->mailer;

            if (!$this->forceSimulation && $mailer instanceof PHPMailer) {
                if (method_exists($mailer, 'clearAddresses')) {
                    $mailer->clearAddresses();
                }
                if (method_exists($mailer, 'clearBCCs')) {
                    $mailer->clearBCCs();
                }
                if (method_exists($mailer, 'clearAttachments')) {
                    $mailer->clearAttachments();
                }

                $mailer->addAddress($this->fromAddress, $this->fromName);
                foreach ($cleaned as $recipient) {
                    $mailer->addBCC($recipient['email'], (string)($recipient['name'] ?? ''));
                }

                $mailer->Subject = $subject;
                $mailer->Body = $bodyHtml;
                $mailer->AltBody = $bodyText ?: strip_tags($bodyHtml);

                $mailer->send();
                $this->logger->info('Broadcast email sent successfully', [
                    'recipient_count' => count($cleaned),
                    'subject' => $subject
                ]);
                return true;
            }

            $reason = $this->forceSimulation ? 'force_simulation' : 'mailer_unavailable';
            $this->logger->info('Simulated broadcast email send', [
                'recipient_count' => count($cleaned),
                'subject' => $subject,
                'reason' => $reason
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Broadcast email could not be sent.', [
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function sendVerificationCode(string $toEmail, string $toName, string $code)
    {
        $subject = $this->config['subjects']['verification_code'] ?? 'Your Verification Code';
        $htmlTemplate = file_get_contents($this->config['templates_path'] . 'verification_code.html');
        $textTemplate = file_get_contents($this->config['templates_path'] . 'verification_code.txt');

        $bodyHtml = str_replace('{{code}}', $code, $htmlTemplate);
        $bodyText = str_replace('{{code}}', $code, $textTemplate);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendPasswordResetLink(string $toEmail, string $toName, string $link)
    {
        $subject = $this->config['subjects']['password_reset'] ?? 'Password Reset Request';
        $htmlTemplate = file_get_contents($this->config['templates_path'] . 'password_reset.html');
        $textTemplate = file_get_contents($this->config['templates_path'] . 'password_reset.txt');

        $bodyHtml = str_replace('{{link}}', $link, $htmlTemplate);
        $bodyText = str_replace('{{link}}', $link, $textTemplate);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendActivityApprovedNotification(string $toEmail, string $toName, string $activityName, float $pointsEarned)
    {
        $subject = $this->config['subjects']['activity_approved'] ?? 'Your Carbon Activity Approved!';
        $htmlTemplate = file_get_contents($this->config['templates_path'] . 'activity_approved.html');
        $textTemplate = file_get_contents($this->config['templates_path'] . 'activity_approved.txt');

        $bodyHtml = str_replace([self::TAG_ACTIVITY_NAME, self::TAG_POINTS_EARNED], [$activityName, $pointsEarned], $htmlTemplate);
        $bodyText = str_replace([self::TAG_ACTIVITY_NAME, self::TAG_POINTS_EARNED], [$activityName, $pointsEarned], $textTemplate);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendActivityRejectedNotification(string $toEmail, string $toName, string $activityName, string $reason)
    {
        $subject = $this->config['subjects']['activity_rejected'] ?? 'Your Carbon Activity Rejected';
        $htmlTemplate = file_get_contents($this->config['templates_path'] . 'activity_rejected.html');
        $textTemplate = file_get_contents($this->config['templates_path'] . 'activity_rejected.txt');

        $bodyHtml = str_replace([self::TAG_ACTIVITY_NAME, self::TAG_REASON], [$activityName, $reason], $htmlTemplate);
        $bodyText = str_replace([self::TAG_ACTIVITY_NAME, self::TAG_REASON], [$activityName, $reason], $textTemplate);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendExchangeConfirmation(string $toEmail, string $toName, string $productName, int $quantity, float $totalPoints)
    {
        $subject = $this->config['subjects']['exchange_confirmation'] ?? 'Your Exchange Order Confirmed';
        $htmlTemplate = file_get_contents($this->config['templates_path'] . 'exchange_confirmation.html');
        $textTemplate = file_get_contents($this->config['templates_path'] . 'exchange_confirmation.txt');

        $bodyHtml = str_replace([self::TAG_PRODUCT_NAME, self::TAG_QUANTITY, self::TAG_TOTAL_POINTS], [$productName, $quantity, $totalPoints], $htmlTemplate);
        $bodyText = str_replace([self::TAG_PRODUCT_NAME, self::TAG_QUANTITY, self::TAG_TOTAL_POINTS], [$productName, $quantity, $totalPoints], $textTemplate);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendExchangeStatusUpdate(string $toEmail, string $toName, string $productName, string $status, string $adminNotes = '')
    {
        $subject = $this->config['subjects']['exchange_status_update'] ?? 'Your Exchange Order Status Updated';
        $htmlTemplate = file_get_contents($this->config['templates_path'] . 'exchange_status_update.html');
        $textTemplate = file_get_contents($this->config['templates_path'] . 'exchange_status_update.txt');

        $bodyHtml = str_replace([self::TAG_PRODUCT_NAME, self::TAG_STATUS, self::TAG_ADMIN_NOTES], [$productName, $status, $adminNotes], $htmlTemplate);
        $bodyText = str_replace([self::TAG_PRODUCT_NAME, self::TAG_STATUS, self::TAG_ADMIN_NOTES], [$productName, $status, $adminNotes], $textTemplate);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendWelcomeEmail(string $toEmail, string $toName): bool
    {
        $subject = $this->config['subjects']['welcome'] ?? 'Welcome to CarbonTrack';
        $bodyHtml = sprintf(
            '<p>Hi %s,</p><p>Welcome to CarbonTrack! Your account has been created successfully.</p><p>Thanks for joining us.</p>',
            htmlspecialchars($toName, ENT_QUOTES, 'UTF-8')
        );
        $bodyText = "Hi {$toName},\n\nWelcome to CarbonTrack! Your account has been created successfully.\n\nThanks for joining us.";
        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendPasswordResetEmail(string $toEmail, string $toName, string $token): bool
    {
        $base = $this->config['reset_link_base']
            ?? ($_ENV['APP_URL'] ?? ($_ENV['FRONTEND_URL'] ?? ''));
        $link = $base ? rtrim($base, '/') . '/reset-password?token=' . urlencode($token) : '#';
        $subject = $this->config['subjects']['password_reset'] ?? 'Password Reset Request';
        $bodyHtml = sprintf(
            '<p>Hi %s,</p><p>We received a request to reset your password.</p><p><a href="%s">Click here to reset your password</a>. This link will expire in 60 minutes.</p><p>If you did not request a password reset, you can safely ignore this email.</p>',
            htmlspecialchars($toName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
        );
        $bodyText = "Hi {$toName},\n\nWe received a request to reset your password.\nOpen this link to reset: {$link}\nThe link expires in 60 minutes. If you did not request this, please ignore this email.";
        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }
}
