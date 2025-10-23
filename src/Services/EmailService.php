<?php

namespace CarbonTrack\Services;

use CarbonTrack\Models\Message;
use Monolog\Logger;
use PHPMailer\PHPMailer\PHPMailer;

class EmailService
{
    protected ?PHPMailer $mailer = null;
    protected $config;
    protected $logger;
    protected bool $forceSimulation = false;
    private ?string $lastError = null;
    // These will be initialized from environment variables (with config fallbacks) in the constructor
    private string $fromAddress;
    private string $fromName;
    private string $appName;
    private string $supportEmail;
    private ?string $frontendUrl = null;
    private ?NotificationPreferenceService $preferenceService = null;

    private const TAG_ACTIVITY_NAME = '{{activity_name}}';
    private const TAG_POINTS_EARNED = '{{points_earned}}';
    private const TAG_REASON = '{{reason}}';
    private const TAG_PRODUCT_NAME = '{{product_name}}';
    private const TAG_QUANTITY = '{{quantity}}';
    private const TAG_TOTAL_POINTS = '{{total_points}}';
    private const TAG_STATUS = '{{status}}';
    private const TAG_ADMIN_NOTES = '{{admin_notes}}';
    private const DEFAULT_LAYOUT_TEMPLATE = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{email_title}}</title>
</head>
<body style="margin:0;padding:24px;font-family:Arial,sans-serif;background-color:#f5f7fa;color:#1f2937;">
    <div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;padding:32px;box-shadow:0 10px 40px rgba(15,23,42,0.08);">
        <h1 style="margin-top:0;font-size:24px;color:#0ea5e9;">{{email_title}}</h1>
        <div style="font-size:16px;line-height:1.6;">{{content}}</div>
        {{buttons}}
        <div style="margin-top:32px;font-size:13px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:16px;text-align:center;">
            <p style="margin:0 0 8px 0;">&copy; {{current_year}} {{app_name}}. All rights reserved.</p>
            <p style="margin:0;">Need help? <a href="mailto:{{support_email}}" style="color:#0ea5e9;text-decoration:none;">{{support_email}}</a></p>
            {{footer_note}}
        </div>
    </div>
</body>
</html>
HTML;
    private const DEFAULT_BUTTON_COLOR = '#0ea5e9';

    public function __construct(array $config, Logger $logger, ?NotificationPreferenceService $preferenceService = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->preferenceService = $preferenceService;
        $this->forceSimulation = $this->normalizeForceSimulation($config['force_simulation'] ?? false);

        // Initialize identity fields from environment first, then config, then sane defaults
        $this->fromAddress = (string) ($_ENV['MAIL_FROM_ADDRESS']
            ?? ($this->config['from_address'] ?? $this->config['from_email'] ?? 'noreply@example.com'));
        $this->fromName = (string) ($_ENV['MAIL_FROM_NAME']
            ?? ($this->config['from_name'] ?? 'CarbonTrack'));

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

        // APP_NAME (or fallback to MAIL_FROM_NAME, then config, then 'CarbonTrack')
        $appNameEnv = $_ENV['APP_NAME'] ?? ($_ENV['MAIL_FROM_NAME'] ?? null);
        if (is_string($appNameEnv) && trim($appNameEnv) !== '') {
            $this->appName = $appNameEnv;
        } elseif (is_string($this->config['app_name'] ?? null) && trim((string) $this->config['app_name']) !== '') {
            $this->appName = (string) $this->config['app_name'];
        } else {
            $this->appName = $this->fromName ?: 'CarbonTrack';
        }

        // SUPPORT_EMAIL (or fallback to reply_to, then MAIL_FROM_ADDRESS, then default)
        $support = $_ENV['SUPPORT_EMAIL']
            ?? ($this->config['support_email'] ?? ($this->config['reply_to'] ?? null));
        if (!is_string($support) || trim((string) $support) === '') {
            $support = $_ENV['MAIL_FROM_ADDRESS'] ?? $this->fromAddress ?? 'support@example.com';
        }
        $this->supportEmail = (string) $support;

        // FRONTEND_URL (prefer explicit env, then APP_URL, then config)
        $frontend = $_ENV['FRONTEND_URL']
            ?? ($_ENV['APP_URL'] ?? ($this->config['frontend_url'] ?? null));
        $this->frontendUrl = is_string($frontend) && trim((string) $frontend) !== '' ? (string) $frontend : null;
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

            // Prioritize environment variables for identity fields
            $fromAddress = $_ENV['MAIL_FROM_ADDRESS']
                ?? ($this->config['from_address'] ?? ($this->config['from_email'] ?? 'noreply@example.com'));
            $fromName = $_ENV['MAIL_FROM_NAME']
                ?? ($this->config['from_name'] ?? 'CarbonTrack');
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
        $this->lastError = null;
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
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Send a broadcast email using BCC to protect recipient privacy
     * @param array<int, array{email:string, name: string|null}> $recipients
     */
    public function sendBroadcastEmail(array $recipients, string $subject, string $bodyHtml, string $bodyText = "", ?string $category = null): bool
    {
        $this->lastError = null;
        $category = $category ?: NotificationPreferenceService::CATEGORY_ANNOUNCEMENT;
        $cleaned = [];
        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $name = $recipient['name'] ?? null;
            if (!$this->shouldSendEmail($email, $category)) {
                continue;
            }
            $cleaned[] = ['email' => $email, 'name' => $name];
        }

        if (empty($cleaned)) {
            $this->lastError = 'No deliverable email recipients provided';
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
                    'subject' => $subject,
                    'category' => $category,
                ]);
                return true;
            }

            $reason = $this->forceSimulation ? 'force_simulation' : 'mailer_unavailable';
            $this->logger->info('Simulated broadcast email send', [
                'recipient_count' => count($cleaned),
                'subject' => $subject,
                'reason' => $reason,
                'category' => $category,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Broadcast email could not be sent.', [
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function sendMessageNotification(
        string $toEmail,
        string $toName,
        string $subject,
        string $messageBody,
        string $category,
        string $priority = Message::PRIORITY_NORMAL
    ): bool {
        if (!$this->shouldSendEmail($toEmail, $category)) {
            return false;
        }

        $buttons = [];
        $messagesUrl = $this->buildFrontendUrl('messages');
        if ($messagesUrl) {
            $buttons[] = [
                'text' => 'View in CarbonTrack',
                'url' => $messagesUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $priorityNotice = $this->buildPriorityNoticeText($priority);

        $contentHtml = '<p style="margin:0 0 16px 0;">' . sprintf('Hello %s,', $this->esc($toName)) . '</p>';
        if ($priorityNotice !== '') {
            $contentHtml .= '<p style="margin:0 0 16px 0;color:#dc2626;font-weight:600;">' . $this->esc($priorityNotice) . '</p>';
        }
        $contentHtml .= '<p style="margin:0 0 12px 0;">You have a new notification in ' . $this->esc($this->appName) . '.</p>';
        $contentHtml .= '<div style="margin:16px 0;padding:16px;background:#f8fafc;border-radius:12px;">'
            . $this->renderMessageContentHtml($messageBody)
            . '</div>';
        $contentHtml .= '<p style="margin:12px 0 0 0;">You can review the full details in the app at any time.</p>';

        $bodyHtml = $this->renderLayout($subject, $contentHtml, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    /**
     * @param array<int, array{email:string,name:string|null}> $recipients
     */
    public function sendAnnouncementBroadcast(
        array $recipients,
        string $title,
        string $content,
        string $priority = Message::PRIORITY_NORMAL
    ): bool {
        if (empty($recipients)) {
            $this->lastError = 'No deliverable email recipients provided';
            return false;
        }

        $subject = $this->buildAnnouncementSubject($title, $priority);
        $priorityNotice = $this->buildPriorityNoticeText($priority);

        $contentHtml = '<p style="margin:0 0 16px 0;">'
            . sprintf('Hello %s community member,', $this->esc($this->appName))
            . '</p>';

        if ($priorityNotice !== '') {
            $contentHtml .= '<p style="margin:0 0 16px 0;color:#dc2626;font-weight:600;">' . $this->esc($priorityNotice) . '</p>';
        }

        $contentHtml .= '<p style="margin:0 0 12px 0;">'
            . $this->esc($this->appName)
            . ' has published a new announcement:</p>';

        $contentHtml .= '<div style="margin:16px 0;padding:16px;background:#f8fafc;border-radius:12px;">'
            . '<h2 style="margin:0 0 12px 0;font-size:18px;color:#0f172a;">' . $this->esc($title) . '</h2>'
            . $this->renderMessageContentHtml($content)
            . '</div>';

        $contentHtml .= '<p style="margin:12px 0 0 0;">You can review the announcement in your inbox at any time.</p>';

        $buttons = [];
        $messagesUrl = $this->buildFrontendUrl('messages');
        if ($messagesUrl) {
            $buttons[] = [
                'text' => 'View announcements',
                'url' => $messagesUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $bodyHtml = $this->renderLayout($subject, $contentHtml, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendBroadcastEmail(
            $recipients,
            $subject,
            $bodyHtml,
            $bodyText,
            NotificationPreferenceService::CATEGORY_ANNOUNCEMENT
        );
    }

    /**
     * Send a message notification email to multiple recipients using BCC.
     *
     * @param array<int, array{email:string,name:string|null}> $recipients
     */
    public function sendMessageNotificationToMany(
        array $recipients,
        string $subject,
        string $messageBody,
        string $category,
        string $priority = Message::PRIORITY_NORMAL
    ): bool {
        if (empty($recipients)) {
            $this->lastError = 'No recipients provided for bulk notification.';
            return false;
        }

        $buttons = [];
        $messagesUrl = $this->buildFrontendUrl('messages');
        if ($messagesUrl) {
            $buttons[] = [
                'text' => 'Open CarbonTrack',
                'url' => $messagesUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $priorityNotice = $this->buildPriorityNoticeText($priority);

        $contentHtml = '<p style="margin:0 0 16px 0;">Hello,</p>';
        if ($priorityNotice !== '') {
            $contentHtml .= '<p style="margin:0 0 16px 0;color:#dc2626;font-weight:600;">' . $this->esc($priorityNotice) . '</p>';
        }
        $contentHtml .= '<p style="margin:0 0 12px 0;">There is a new notification in ' . $this->esc($this->appName) . ' that may require your attention.</p>';
        $contentHtml .= '<div style="margin:16px 0;padding:16px;background:#f8fafc;border-radius:12px;">'
            . $this->renderMessageContentHtml($messageBody)
            . '</div>';
        $contentHtml .= '<p style="margin:12px 0 0 0;">You can review the full details in the app at any time.</p>';

        $bodyHtml = $this->renderLayout($subject, $contentHtml, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendBroadcastEmail($recipients, $subject, $bodyHtml, $bodyText, $category);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function buildAnnouncementSubject(string $title, string $priority): string
    {
        $prefix = '';
        $normalized = strtolower(trim($priority));
        if ($normalized === Message::PRIORITY_URGENT) {
            $prefix = '[URGENT] ';
        } elseif ($normalized === Message::PRIORITY_HIGH) {
            $prefix = '[HIGH] ';
        }

        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            $trimmedTitle = 'Platform announcement';
        }

        return $prefix . $trimmedTitle;
    }

    /**
     * Load an email template from disk, falling back to provided content when unavailable.
     */
    private function readTemplate(string $filename, string $fallback): string
    {
        $base = $this->config['templates_path'] ?? '';
        $base = $base !== '' ? rtrim($base, "/\\") . DIRECTORY_SEPARATOR : '';
        $path = $base . ltrim($filename, "/\\");

        try {
            $contents = @file_get_contents($path);
        } catch (\Throwable $e) {
            $contents = false;
        }

        if ($contents === false || $contents === '') {
            try {
                $this->logger->warning('Email template missing or unreadable', [
                    'template' => $path
                ]);
            } catch (\Throwable $logError) {
                // Ignore logging failures to keep mail flow resilient
            }
            return $fallback;
        }

        return $contents;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function buildFrontendUrl(?string $path = null): ?string
    {
        if ($this->frontendUrl === null || $this->frontendUrl === '') {
            return null;
        }

        $base = rtrim($this->frontendUrl, '/');
        if ($path === null || $path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Render the shared email layout.
     *
     * @param array<int, array{text:string,url:string,color?:string}> $buttons
     */
    private function renderLayout(string $title, string $contentHtml, array $buttons = [], ?string $footerNote = null): string
    {
        $layout = $this->readTemplate('layout.html', self::DEFAULT_LAYOUT_TEMPLATE);
        $buttonHtml = $this->buildButtonsHtml($buttons);

        $replacements = [
            '{{email_title}}' => $this->esc($title),
            '{{content}}' => $contentHtml,
            '{{buttons}}' => $buttonHtml,
            '{{app_name}}' => $this->esc($this->appName),
            '{{current_year}}' => date('Y'),
            '{{support_email}}' => $this->esc($this->supportEmail),
            '{{footer_note}}' => $footerNote ? '<p>' . $this->esc($footerNote) . '</p>' : '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $layout);
    }

    private function renderMessageContentHtml(string $messageBody): string
    {
        $normalized = preg_replace("/\r\n|\r/", "\n", (string) $messageBody);
        $normalized = trim($normalized ?? '');
        if ($normalized === '') {
            return '<p style="margin:0;color:#475569;">No additional message details were provided.</p>';
        }

        $blocks = preg_split("/\n{2,}/", $normalized) ?: [$normalized];
        $htmlSegments = [];
        foreach ($blocks as $block) {
            $trimmed = trim($block);
            if ($trimmed === '') {
                continue;
            }
            $htmlSegments[] = '<p style="margin:0 0 12px 0;">' . nl2br($this->esc($trimmed)) . '</p>';
        }

        if (empty($htmlSegments)) {
            $htmlSegments[] = '<p style="margin:0;color:#475569;">' . $this->esc($normalized) . '</p>';
        }

        return implode('', $htmlSegments);
    }

    private function buildPriorityNoticeText(string $priority): string
    {
        $normalized = strtolower(trim($priority));
        switch ($normalized) {
            case 'urgent':
                return 'This notification is marked as URGENT. Please review it as soon as possible.';
            case 'high':
                return 'This notification is marked as high priority.';
            default:
                return '';
        }
    }

    /**
     * @param array<int, array{text:string,url:string,color?:string}> $buttons
     */
    private function buildButtonsHtml(array $buttons): string
    {
        $items = [];
        foreach ($buttons as $button) {
            $text = trim((string) ($button['text'] ?? ''));
            $url = trim((string) ($button['url'] ?? ''));
            if ($text === '' || $url === '') {
                continue;
            }
            $color = trim((string) ($button['color'] ?? self::DEFAULT_BUTTON_COLOR));
            $items[] = sprintf(
                '<a class="cta-button" href="%s" style="background-color:%s">%s</a>',
                $this->esc($url),
                $this->esc($color),
                $this->esc($text)
            );
        }

        if (empty($items)) {
            return '';
        }

        return '<div class="button-group">' . implode('', $items) . '</div>';
    }

    /**
     * @param array<int, array{text:string,url:string,color?:string}> $buttons
     */
    private function appendButtonActionsToText(string $bodyText, array $buttons): string
    {
        $links = [];
        foreach ($buttons as $button) {
            $text = trim((string) ($button['text'] ?? ''));
            $url = trim((string) ($button['url'] ?? ''));
            if ($text === '' || $url === '') {
                continue;
            }
            $links[] = $text . ': ' . $url;
        }

        if (empty($links)) {
            return $bodyText;
        }

        $bodyText = rtrim($bodyText);
        $bodyText .= "\n\nActions:\n" . implode("\n", $links) . "\n";

        return $bodyText;
    }

    /**
     * Build a plain-text fallback from the HTML body.
     *
     * @param array<int, array{text:string,url:string,color?:string}> $buttons
     */
    private function buildTextBody(string $html, array $buttons = []): string
    {
        $replacements = [
            '<br>' => "\n",
            '<br/>' => "\n",
            '<br />' => "\n",
        ];
        $blockBreaksPattern = '/<\s*\/?(p|div|section|article|li|tr|td|h[1-6])[^>]*>/i';
        $normalized = str_ireplace(array_keys($replacements), array_values($replacements), $html);
        $normalized = preg_replace($blockBreaksPattern, "\n", $normalized ?? $html);

        $text = strip_tags($normalized ?? $html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text ?? '');

        return $this->appendButtonActionsToText($text, $buttons);
    }

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    public function getAppName(): string
    {
        return $this->appName;
    }

    /**
     * Schedule an email-related callback to run after response is sent, with synchronous fallback.
     *
     * @param callable $callback Receives a boolean flag indicating whether it's running in async context.
     */
    public function dispatchAsyncEmail(callable $callback, array $context = [], bool $preferAsync = true): bool
    {
        $sapi = PHP_SAPI ?? php_sapi_name();
        $isCli = in_array($sapi, ['cli', 'phpdbg', 'embed'], true);

        if (!$preferAsync || $this->forceSimulation || $isCli) {
            return (bool) $callback(false);
        }

        try {
            register_shutdown_function(function () use ($callback, $context): void {
                try {
                    $callback(true);
                } catch (\Throwable $e) {
                    try {
                        $this->logger->error('Async email callback failed', [
                            'error' => $e->getMessage(),
                            'context' => $context,
                        ]);
                    } catch (\Throwable $logError) {
                        // ignore logging issues in shutdown context
                    }
                }
            });

            return true;
        } catch (\Throwable $e) {
            try {
                $this->logger->debug('Failed to register async email callback; falling back to sync send', [
                    'error' => $e->getMessage(),
                    'context' => $context,
                ]);
            } catch (\Throwable $logError) {
                // ignore
            }

            return (bool) $callback(false);
        }
    }

    private function shouldSendEmail(string $email, string $category): bool
    {
        if ($this->preferenceService === null) {
            return true;
        }

        try {
            return $this->preferenceService->shouldSendEmailByEmail($email, $category);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve notification preference, falling back to send', [
                'email' => $email,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    public function sendVerificationCode(
        string $toEmail,
        string $toName,
        string $code,
        int $expiryMinutes = 30,
        ?string $verificationLink = null
    ): bool {
        if (!$this->shouldSendEmail($toEmail, NotificationPreferenceService::CATEGORY_VERIFICATION)) {
            return false;
        }

        $subject = $this->config['subjects']['verification_code'] ?? 'Your Verification Code';

        $htmlTemplate = $this->readTemplate(
            'verification_code.html',
            '<p>Hello {{username}},</p><p>Your verification code is <strong>{{verification_code}}</strong>. '
            . 'The code expires in {{expiry_minutes}} minutes.</p>{{link_block}}'
            . '<p>If you did not request this code you can safely ignore this email.</p>'
        );

        $buttons = [];
        $linkBlockHtml = '';
        $safeLink = null;
        if ($verificationLink) {
            $safeLink = $this->esc($verificationLink);
            $buttons[] = [
                'text' => 'Verify Email',
                'url' => $verificationLink,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
            $linkBlockHtml = sprintf(
                '<p>You can also open this link directly: <a href="%1$s">%1$s</a></p>',
                $safeLink
            );
        }

        $replacements = [
            '{{code}}' => $this->esc($code),
            '{{verification_code}}' => $this->esc($code),
            '{{username}}' => $this->esc($toName),
            '{{expiry_minutes}}' => $this->esc((string) $expiryMinutes),
            '{{link_block}}' => $linkBlockHtml,
            '{{verification_link}}' => $safeLink ?? '',
            '{{link}}' => $safeLink ?? '',
        ];

        $bodyHtmlContent = str_replace(array_keys($replacements), array_values($replacements), $htmlTemplate);
        $bodyHtml = $this->renderLayout('Verify your email address', $bodyHtmlContent, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendPasswordResetLink(string $toEmail, string $toName, string $link)
    {
        if (!$this->shouldSendEmail($toEmail, NotificationPreferenceService::CATEGORY_SECURITY)) {
            return false;
        }

        $subject = $this->config['subjects']['password_reset'] ?? 'Password Reset Request';
        $htmlTemplate = $this->readTemplate(
            'password_reset.html',
            '<p>Hello {{username}},</p>'
            . '<p>We received a request to reset your password.</p>'
            . '<p>If this was you, use the button below to create a new password.</p>'
            . '<p>If you did not request a password reset you can ignore this message.</p>'
        );

        $buttons = [];
        if (trim($link) !== '') {
            $buttons[] = [
                'text' => 'Reset password',
                'url' => $link,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $contentHtml = str_replace(
            ['{{username}}', '{{link}}'],
            [$this->esc($toName), $this->esc($link)],
            $htmlTemplate
        );
        $bodyHtml = $this->renderLayout('Reset your password', $contentHtml, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendActivityApprovedNotification(string $toEmail, string $toName, string $activityName, float $pointsEarned)
    {
        if (!$this->shouldSendEmail($toEmail, NotificationPreferenceService::CATEGORY_ACTIVITY)) {
            return false;
        }

        $subject = $this->config['subjects']['activity_approved'] ?? 'Your Carbon Activity Approved!';
        $htmlTemplate = $this->readTemplate(
            'activity_approved.html',
            '<p>Hello {{username}},</p>'
            . '<p>Your submission <strong>{{activity_name}}</strong> has been approved.</p>'
            . '<p>You earned <strong>{{points_earned}}</strong> points for this activity.</p>'
        );

        $buttons = [];
        $activityUrl = $this->buildFrontendUrl('dashboard/activities');
        if ($activityUrl) {
            $buttons[] = [
                'text' => 'View activity history',
                'url' => $activityUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $points = $this->formatNumber($pointsEarned);
        $bodyHtmlContent = str_replace(
            [
                '{{username}}',
                self::TAG_ACTIVITY_NAME,
                self::TAG_POINTS_EARNED,
            ],
            [
                $this->esc($toName),
                $this->esc($activityName),
                $this->esc($points),
            ],
            $htmlTemplate
        );
        $bodyHtml = $this->renderLayout('Activity approved', $bodyHtmlContent, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendActivityRejectedNotification(string $toEmail, string $toName, string $activityName, string $reason)
    {
        if (!$this->shouldSendEmail($toEmail, NotificationPreferenceService::CATEGORY_ACTIVITY)) {
            return false;
        }

        $subject = $this->config['subjects']['activity_rejected'] ?? 'Your Carbon Activity Rejected';
        $htmlTemplate = $this->readTemplate(
            'activity_rejected.html',
            '<p>Hello {{username}},</p>'
            . '<p>We reviewed <strong>{{activity_name}}</strong> but could not approve it.</p>'
            . '<p>Reason: {{reason}}</p>'
            . '<p>You can review the submission, make changes, and resubmit at any time.</p>'
        );

        $buttons = [];
        $activityUrl = $this->buildFrontendUrl('dashboard/activities');
        if ($activityUrl) {
            $buttons[] = [
                'text' => 'Review submission',
                'url' => $activityUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $bodyHtmlContent = str_replace(
            [
                '{{username}}',
                self::TAG_ACTIVITY_NAME,
                self::TAG_REASON,
            ],
            [
                $this->esc($toName),
                $this->esc($activityName),
                $this->esc($reason),
            ],
            $htmlTemplate
        );
        $bodyHtml = $this->renderLayout('Activity requires updates', $bodyHtmlContent, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendCarbonRecordReviewSummaryEmail(
        string $toEmail,
        string $toName,
        string $action,
        array $records,
        string $title,
        ?string $reviewNote = null,
        ?string $reviewedBy = null
    ): bool {
        if (!$this->shouldSendEmail($toEmail, NotificationPreferenceService::CATEGORY_ACTIVITY)) {
            return false;
        }

        $normalizedAction = strtolower(trim($action));
        if ($normalizedAction === 'approved') {
            $normalizedAction = 'approve';
        } elseif ($normalizedAction === 'rejected') {
            $normalizedAction = 'reject';
        }
        $isApprove = $normalizedAction === 'approve';

        $subjectMap = $this->config['subjects']['carbon_record_review_summary'] ?? [];
        if (is_array($subjectMap)) {
            $subject = (string) ($subjectMap[$normalizedAction] ?? ($isApprove ? 'Carbon record review approved' : 'Carbon record review result'));
        } else {
            $subject = is_string($subjectMap) ? (string) $subjectMap : ($isApprove ? 'Carbon record review approved' : 'Carbon record review result');
        }

        $headline = $title !== '' ? $title : ($isApprove ? 'Carbon record review approved' : 'Carbon record review result');
        $intro = $isApprove
            ? 'The following carbon reduction records were approved:'
            : 'The following carbon reduction records require your attention:';

        $items = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $activity = (string) ($record['activity_name'] ?? 'Activity');
            $value = $record['data_value'] ?? null;
            $unit = $record['unit'] ?? null;
            $points = $record['points_earned'] ?? null;
            $date = $record['date'] ?? null;

            $parts = ['Activity: ' . $this->esc($activity)];
            if ($value !== null && $value !== '') {
                $dataText = (string) $value;
                if ($unit !== null && $unit !== '') {
                    $dataText .= ' ' . $unit;
                }
                $parts[] = 'Data: ' . $this->esc($dataText);
            }
            if ($points !== null && $points !== '') {
                $parts[] = 'Points: ' . $this->esc((string) $points);
            }
            if ($date !== null && $date !== '') {
                $parts[] = 'Date: ' . $this->esc((string) $date);
            }

            if ($reviewNote && !empty($record['review_note'])) {
                $parts[] = 'Note: ' . $this->esc((string) $record['review_note']);
            }

            $items[] = '<li>' . implode(' · ', $parts) . '</li>';
        }

        if (empty($items)) {
            $items[] = '<li>No record details provided.</li>';
        }

        $listHtml = '<ul style="padding-left:20px;">' . implode('', $items) . '</ul>';

        $contentHtml = '<p>Hello ' . $this->esc($toName) . ',</p>'
            . '<p>' . $this->esc($intro) . '</p>'
            . $listHtml;

        if ($reviewNote) {
            $contentHtml .= '<p>Review note: ' . $this->esc($reviewNote) . '</p>';
        }
        if ($reviewedBy) {
            $contentHtml .= '<p>Reviewer: ' . $this->esc($reviewedBy) . '</p>';
        }

        $buttons = [];
        $activitiesUrl = $this->buildFrontendUrl('dashboard/activities');
        if ($activitiesUrl) {
            $buttons[] = [
                'text' => $isApprove ? 'View approved records' : 'Review records',
                'url' => $activitiesUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $bodyHtml = $this->renderLayout($headline, $contentHtml, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendExchangeConfirmation(string $toEmail, string $toName, string $productName, int $quantity, float $totalPoints)
    {
        if (!$this->shouldSendEmail($toEmail, NotificationPreferenceService::CATEGORY_TRANSACTION)) {
            return false;
        }

        $subject = $this->config['subjects']['exchange_confirmation'] ?? 'Your Exchange Order Confirmed';
        $htmlTemplate = $this->readTemplate(
            'exchange_confirmation.html',
            '<p>Hello {{username}},</p>'
            . '<p>Thanks for redeeming <strong>{{product_name}}</strong>.</p>'
            . '<p>Quantity: {{quantity}} · Points spent: {{total_points}}</p>'
            . '<p>We will notify you when the exchange is ready for pickup.</p>'
        );

        $buttons = [];
        $storeUrl = $this->buildFrontendUrl('store');
        if ($storeUrl) {
            $buttons[] = [
                'text' => 'Browse more rewards',
                'url' => $storeUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $bodyHtmlContent = str_replace(
            [
                '{{username}}',
                self::TAG_PRODUCT_NAME,
                self::TAG_QUANTITY,
                self::TAG_TOTAL_POINTS,
            ],
            [
                $this->esc($toName),
                $this->esc($productName),
                $this->esc((string) $quantity),
                $this->esc($this->formatNumber($totalPoints)),
            ],
            $htmlTemplate
        );
        $bodyHtml = $this->renderLayout('Exchange confirmed', $bodyHtmlContent, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }


    public function sendExchangeStatusUpdate(string $toEmail, string $toName, string $productName, string $status, string $adminNotes = '')
    {
        if (!$this->shouldSendEmail($toEmail, NotificationPreferenceService::CATEGORY_TRANSACTION)) {
            return false;
        }

        $subject = $this->config['subjects']['exchange_status_update'] ?? 'Your Exchange Order Status Updated';
        $htmlTemplate = $this->readTemplate(
            'exchange_status_update.html',
            '<p>Hello {{username}},</p>'
            . '<p>Your exchange for <strong>{{product_name}}</strong> was updated to <strong>{{status}}</strong>.</p>'
            . '{{admin_notes_block}}'
            . '<p>Thank you for helping us reduce carbon emissions.</p>'
        );

        $buttons = [];
        $storeUrl = $this->buildFrontendUrl('store');
        if ($storeUrl) {
            $buttons[] = [
                'text' => 'View rewards',
                'url' => $storeUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $notesHtml = '';
        $notesText = '';
        if ($adminNotes !== '') {
            $notesHtml = '<p>Notes from our team: ' . $this->esc($adminNotes) . '</p>';
            $notesText = 'Notes from our team: ' . $adminNotes;
        }

        $bodyHtmlContent = str_replace(
            [
                '{{username}}',
                self::TAG_PRODUCT_NAME,
                self::TAG_STATUS,
                self::TAG_ADMIN_NOTES,
                '{{admin_notes_block}}',
            ],
            [
                $this->esc($toName),
                $this->esc($productName),
                $this->esc($status),
                $this->esc($adminNotes),
                $notesHtml,
            ],
            $htmlTemplate
        );
        $bodyHtml = $this->renderLayout('Exchange status update', $bodyHtmlContent, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendWelcomeEmail(string $toEmail, string $toName): bool
    {
        if (!$this->shouldSendEmail($toEmail, NotificationPreferenceService::CATEGORY_SYSTEM)) {
            return false;
        }

        $subject = $this->config['subjects']['welcome'] ?? 'Welcome to CarbonTrack';
        $contentHtml = sprintf(
            '<p>Hello %s,</p>'
            . '<p>Welcome to %s! Your account is ready to go.</p>'
            . '<p>Here are a few ideas to get started:</p>'
            . '<ul>'
            . '<li>Log your recent carbon saving activities.</li>'
            . '<li>Explore the store for rewards.</li>'
            . '<li>Invite friends to join your sustainability journey.</li>'
            . '</ul>',
            $this->esc($toName),
            $this->esc($this->appName)
        );

        $buttons = [];
        $dashboardUrl = $this->buildFrontendUrl('dashboard');
        if ($dashboardUrl) {
            $buttons[] = [
                'text' => 'Open dashboard',
                'url' => $dashboardUrl,
                'color' => self::DEFAULT_BUTTON_COLOR,
            ];
        }

        $bodyHtml = $this->renderLayout('Welcome aboard', $contentHtml, $buttons);
        $bodyText = $this->buildTextBody($bodyHtml, $buttons);

        return $this->sendEmail($toEmail, $toName, $subject, $bodyHtml, $bodyText);
    }

    public function sendPasswordResetEmail(string $toEmail, string $toName, string $token): bool
    {
        $base = $this->config['reset_link_base']
            ?? ($_ENV['FRONTEND_URL'] ?? ($_ENV['APP_URL'] ?? ''));
        $link = '#';
        if ($base) {
            $link = rtrim($base, '/') . '/reset-password?token=' . urlencode($token);
            if ($toEmail !== '') {
                $link .= '&email=' . urlencode($toEmail);
            }
        }

        return $this->sendPasswordResetLink($toEmail, $toName, $link);
    }
}

