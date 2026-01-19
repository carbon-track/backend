<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Models\User;
use CarbonTrack\Models\UserGroup;
use CarbonTrack\Models\UserUsageStats;
use CarbonTrack\Services\QuotaService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class QuotaServiceTest extends TestCase
{
    private static Capsule $capsule;

    public static function setUpBeforeClass(): void
    {
        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        self::migrate();
    }

    public static function tearDownAfterClass(): void
    {
        $schema = self::$capsule->schema();
        $schema->dropIfExists('user_usage_stats');
        $schema->dropIfExists('users');
        $schema->dropIfExists('user_groups');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$capsule->table('user_usage_stats')->delete();
        self::$capsule->table('users')->delete();
        self::$capsule->table('user_groups')->delete();
    }

    public function testDailyQuotaHandlesCarbonDates(): void
    {
        $user = $this->makeUserWithGroup(['daily_limit' => 2]);

        UserUsageStats::create([
            'user_id' => $user->id,
            'resource_key' => 'llm_daily',
            'counter' => 1,
            'last_updated_at' => Carbon::now()->subDay(),
            'reset_at' => Carbon::now()->subDay(),
        ]);

        $this->assertEquals(['daily_limit' => 2], $user->group->getQuotaConfig('llm'));

        $service = new QuotaService();

        $this->assertTrue($service->checkAndConsume($user, 'llm', 1));

        $stats = UserUsageStats::where('user_id', $user->id)
            ->where('resource_key', 'llm_daily')
            ->firstOrFail();

        $this->assertSame(1, (int) $stats->counter, 'Counter should reset then consume cost.');
        $this->assertTrue($stats->reset_at->greaterThan(Carbon::now()), 'Reset time should be in the future.');
    }

    public function testTokenBucketHandlesCarbonDates(): void
    {
        $user = $this->makeUserWithGroup(['rate_limit' => 2.0]);

        UserUsageStats::create([
            'user_id' => $user->id,
            'resource_key' => 'llm_bucket',
            'counter' => 1.0,
            'last_updated_at' => Carbon::now()->subSeconds(30),
        ]);

        $this->assertEquals(['rate_limit' => 2.0], $user->group->getQuotaConfig('llm'));

        $service = new QuotaService();

        $this->assertTrue($service->checkAndConsume($user, 'llm', 1));

        $stats = UserUsageStats::where('user_id', $user->id)
            ->where('resource_key', 'llm_bucket')
            ->firstOrFail();

        $this->assertGreaterThanOrEqual(0.0, (float) $stats->counter);
        $this->assertNotNull($stats->last_updated_at);
    }

    private static function migrate(): void
    {
        $schema = self::$capsule->schema();

        $schema->create('user_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->unique();
            $table->longText('config')->nullable();
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $schema->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->default('user');
            $table->string('status')->default('active');
            $table->decimal('points', 10, 2)->default(0);
            $table->boolean('is_admin')->default(false);
            $table->integer('group_id')->nullable();
            $table->longText('quota_override')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('user_usage_stats', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->string('resource_key', 50);
            $table->decimal('counter', 10, 4)->default(0);
            $table->dateTime('last_updated_at')->nullable();
            $table->dateTime('reset_at')->nullable();
            $table->primary(['user_id', 'resource_key']);
        });
    }

    private function makeUserWithGroup(array $quotaConfig): User
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');
        $groupId = self::$capsule->table('user_groups')->insertGetId([
            'name' => 'Test Group',
            'code' => 'code-' . uniqid(),
            'config' => json_encode(['llm' => $quotaConfig]),
            'is_default' => false,
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $userId = self::$capsule->table('users')->insertGetId([
            'username' => 'user-' . uniqid(),
            'email' => 'test@example.com',
            'password' => 'secret',
            'role' => 'user',
            'status' => 'active',
            'group_id' => $groupId,
            'quota_override' => json_encode([]),
            'points' => 0,
            'is_admin' => false,
            'admin_notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::with('group')->findOrFail($userId);
    }
}
