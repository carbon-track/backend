<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Models\User;
use CarbonTrack\Services\NotificationPreferenceService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class NotificationPreferenceServiceTest extends TestCase
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

        self::$capsule->schema()->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->nullable();
            $table->integer('notification_email_mask')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::$capsule->schema()->dropIfExists('users');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$capsule->table('users')->delete();
    }

    private function makeService(): NotificationPreferenceService
    {
        $logger = new Logger('notification-preference-test');
        $logger->pushHandler(new NullHandler());

        return new NotificationPreferenceService($logger);
    }

    public function testGetPreferencesForUserDefaultsToEnabled(): void
    {
        $user = User::create([
            'username' => 'pref-default',
            'email' => 'default@example.com',
            'password' => 'secret',
            'status' => 'active',
            'notification_email_mask' => 0,
        ]);

        $service = $this->makeService();
        $preferences = $service->getPreferencesForUser((int) $user->id);

        $byCategory = [];
        foreach ($preferences as $row) {
            $byCategory[$row['category']] = $row;
        }

        $this->assertTrue($byCategory[NotificationPreferenceService::CATEGORY_SYSTEM]['email_enabled']);
        $this->assertTrue($byCategory[NotificationPreferenceService::CATEGORY_TRANSACTION]['email_enabled']);
        $this->assertTrue($byCategory[NotificationPreferenceService::CATEGORY_ACTIVITY]['email_enabled']);
        $this->assertTrue($byCategory[NotificationPreferenceService::CATEGORY_ANNOUNCEMENT]['email_enabled']);
        $this->assertTrue($byCategory[NotificationPreferenceService::CATEGORY_VERIFICATION]['email_enabled'], 'Locked categories must remain enabled.');
    }

    public function testUpdatePreferencesPersistsBitmaskAndEnforcesChecks(): void
    {
        $user = User::create([
            'username' => 'pref-toggle',
            'email' => 'toggle@example.com',
            'password' => 'secret',
            'status' => 'active',
            'notification_email_mask' => 0,
        ]);

        $service = $this->makeService();
        $service->updatePreferences((int) $user->id, [
            [
                'category' => NotificationPreferenceService::CATEGORY_SYSTEM,
                'email_enabled' => false,
            ],
            [
                'category' => NotificationPreferenceService::CATEGORY_ANNOUNCEMENT,
                'email_enabled' => false,
            ],
        ]);

        $user->refresh();
        $this->assertSame(9, $user->notification_email_mask, 'System (bit0) and announcement (bit3) should be disabled.');
        $this->assertFalse($service->shouldSendEmail((int) $user->id, NotificationPreferenceService::CATEGORY_SYSTEM));
        $this->assertFalse($service->shouldSendEmailByEmail($user->email, NotificationPreferenceService::CATEGORY_ANNOUNCEMENT));
        $this->assertTrue($service->shouldSendEmail((int) $user->id, NotificationPreferenceService::CATEGORY_TRANSACTION));
        $this->assertTrue($service->shouldSendEmailByEmail($user->email, NotificationPreferenceService::CATEGORY_VERIFICATION), 'Locked verification category should ignore mask.');

        $service->updatePreferences((int) $user->id, [
            [
                'category' => NotificationPreferenceService::CATEGORY_SYSTEM,
                'email_enabled' => true,
            ],
        ]);

        $user->refresh();
        $this->assertSame(8, $user->notification_email_mask, 'Only announcement (bit3) should remain disabled.');
        $this->assertTrue($service->shouldSendEmail((int) $user->id, NotificationPreferenceService::CATEGORY_SYSTEM));
        $this->assertFalse($service->shouldSendEmail((int) $user->id, NotificationPreferenceService::CATEGORY_ANNOUNCEMENT));
    }
}
