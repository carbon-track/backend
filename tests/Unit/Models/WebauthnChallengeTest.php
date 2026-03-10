<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Models;

use CarbonTrack\Models\WebauthnChallenge;
use CarbonTrack\Tests\Integration\TestSchemaBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

class WebauthnChallengeTest extends TestCase
{
    private PDO $pdo;
    private WebauthnChallenge $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        TestSchemaBuilder::init($this->pdo);

        $this->model = new WebauthnChallenge($this->pdo);
    }

    public function testFindActiveReturnsFutureChallengeForMatchingUser(): void
    {
        $this->model->create([
            'challenge_id' => 'challenge-future',
            'user_id' => 7,
            'flow_type' => 'registration',
            'challenge' => 'abc123',
            'context' => ['label' => 'Desk Key'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
        ]);

        $record = $this->model->findActive('challenge-future', 'registration', 7);

        $this->assertIsArray($record);
        $this->assertSame('challenge-future', $record['challenge_id']);
        $this->assertSame(['label' => 'Desk Key'], $record['context']);
    }

    public function testFindActiveRejectsExpiredChallenge(): void
    {
        $this->model->create([
            'challenge_id' => 'challenge-expired',
            'user_id' => 7,
            'flow_type' => 'registration',
            'challenge' => 'abc123',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 5),
        ]);

        $record = $this->model->findActive('challenge-expired', 'registration', 7);

        $this->assertNull($record);
    }

    public function testDeleteExpiredRemovesOnlyExpiredRows(): void
    {
        $this->model->create([
            'challenge_id' => 'challenge-old',
            'user_id' => 7,
            'flow_type' => 'registration',
            'challenge' => 'old',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);
        $this->model->create([
            'challenge_id' => 'challenge-new',
            'user_id' => 7,
            'flow_type' => 'registration',
            'challenge' => 'new',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 60),
        ]);

        $deleted = $this->model->deleteExpired();

        $this->assertSame(1, $deleted);
        $this->assertNull($this->model->findActive('challenge-old', 'registration', 7));
        $this->assertIsArray($this->model->findActive('challenge-new', 'registration', 7));
    }
}
