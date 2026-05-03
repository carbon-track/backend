<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\ProofOfWorkService;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

class ProofOfWorkServiceTest extends TestCase
{
    private const SOLVE_MAX_ATTEMPTS = 1000000;
    private const SOLVE_TIMEOUT_SECONDS = 2;

    public function testCreatesAndVerifiesChallenge(): void
    {
        $service = $this->makeService(8);
        $challenge = $service->createChallenge('auth.login');
        $nonce = $this->solve($challenge['challenge'], $challenge['difficulty']);

        $result = $service->verify($challenge['challenge'], $nonce, 'auth.login');

        $this->assertTrue($result['success']);
        $this->assertSame(8, $result['difficulty']);
    }

    public function testRejectsScopeMismatch(): void
    {
        $service = $this->makeService(8);
        $challenge = $service->createChallenge('auth.login');
        $nonce = $this->solve($challenge['challenge'], $challenge['difficulty']);

        $result = $service->verify($challenge['challenge'], $nonce, 'auth.register');

        $this->assertFalse($result['success']);
        $this->assertSame('scope-mismatch', $result['error']);
    }

    public function testRejectsReplayedChallengeWhenStoreIsAvailable(): void
    {
        $service = $this->makeService(8, $this->makeChallengeDatabase());
        $challenge = $service->createChallenge('auth.login');
        $nonce = $this->solve($challenge['challenge'], $challenge['difficulty']);

        $first = $service->verify($challenge['challenge'], $nonce, 'auth.login');
        $second = $service->verify($challenge['challenge'], $nonce, 'auth.login');

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame('replayed-challenge', $second['error']);
    }

    public function testCleanupExpiredChallengesDeletesExpiredAndOldConsumedRows(): void
    {
        $db = $this->makeChallengeDatabase();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $old = $now->modify('-20 minutes')->format('Y-m-d H:i:s');
        $future = $now->modify('+20 minutes')->format('Y-m-d H:i:s');
        $recent = $now->modify('-2 minutes')->format('Y-m-d H:i:s');

        $insert = $db->prepare(
            'INSERT INTO proof_of_work_challenges (challenge_id, challenge_hash, scope, difficulty, expires_at, used_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute(['expired', 'hash-expired', 'auth.login', 8, $old, null, $old, $old]);
        $insert->execute(['old-used', 'hash-old-used', 'auth.login', 8, $future, $old, $old, $old]);
        $insert->execute(['fresh-used', 'hash-fresh-used', 'auth.login', 8, $future, $recent, $recent, $recent]);
        $insert->execute(['fresh-open', 'hash-fresh-open', 'auth.login', 8, $future, null, $recent, $recent]);

        $service = $this->makeService(8, $db);
        $result = $service->cleanupExpiredChallenges();

        $this->assertSame(2, $result['deleted']);
        $this->assertSame(2, (int)$db->query('SELECT COUNT(*) FROM proof_of_work_challenges')->fetchColumn());
    }

    public function testProductionRequiresConfiguredSecret(): void
    {
        $previous = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';

        try {
            $logger = new Logger('test');
            $logger->pushHandler(new NullHandler());

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('POW_SECRET or JWT_SECRET must be configured in production.');

            new ProofOfWorkService('', $logger);
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previous;
            }
        }
    }

    private function makeService(int $difficulty, ?PDO $db = null): ProofOfWorkService
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        return new ProofOfWorkService('test-secret', $logger, null, null, $difficulty, 120, $db);
    }

    private function makeChallengeDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE proof_of_work_challenges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                challenge_id TEXT NOT NULL UNIQUE,
                challenge_hash TEXT NOT NULL,
                scope TEXT NOT NULL,
                difficulty INTEGER NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        return $db;
    }

    private function solve(string $challenge, int $difficulty): string
    {
        $deadline = microtime(true) + self::SOLVE_TIMEOUT_SECONDS;

        for ($nonce = 0; $nonce < self::SOLVE_MAX_ATTEMPTS && microtime(true) < $deadline; $nonce++) {
            $hash = hash('sha256', $challenge . ':' . $nonce, true);
            if ($this->hasLeadingZeroBits($hash, $difficulty)) {
                return (string)$nonce;
            }
        }

        $this->fail(sprintf(
            'Unable to solve proof-of-work challenge in %d attempts or %d seconds',
            self::SOLVE_MAX_ATTEMPTS,
            self::SOLVE_TIMEOUT_SECONDS
        ));
    }

    private function hasLeadingZeroBits(string $hash, int $difficulty): bool
    {
        $fullBytes = intdiv($difficulty, 8);
        for ($i = 0; $i < $fullBytes; $i++) {
            if (ord($hash[$i]) !== 0) {
                return false;
            }
        }

        $remainingBits = $difficulty % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xff << (8 - $remainingBits);
        return (ord($hash[$fullBytes]) & $mask) === 0;
    }
}
