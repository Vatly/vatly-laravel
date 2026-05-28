<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vatly\Laravel\Tests\BaseTestCase;

class BillableColumnsMigrationTest extends BaseTestCase
{
    private const STUB_PATH = __DIR__.'/../../../database/migrations/create_vatly_billable_columns.php.stub';

    protected function defineDatabaseMigrations(): void
    {
        // Skip the fixture migrations so we own the users-table shape here.
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
        });
    }

    public function test_it_adds_columns_when_missing(): void
    {
        $this->migrateUp();

        $this->assertTrue(Schema::hasColumn('users', 'vatly_id'));
        $this->assertTrue(Schema::hasColumn('users', 'trial_ends_at'));
    }

    public function test_running_up_twice_does_not_throw(): void
    {
        $this->migrateUp();
        $this->migrateUp();

        $this->assertTrue(Schema::hasColumn('users', 'vatly_id'));
        $this->assertTrue(Schema::hasColumn('users', 'trial_ends_at'));
    }

    public function test_up_is_a_noop_when_columns_already_exist(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('vatly_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        $this->migrateUp();

        $this->assertTrue(Schema::hasColumn('users', 'vatly_id'));
        $this->assertTrue(Schema::hasColumn('users', 'trial_ends_at'));
    }

    public function test_down_only_drops_columns_that_exist(): void
    {
        $this->migrateUp();
        $this->migrateDown();

        $this->assertFalse(Schema::hasColumn('users', 'vatly_id'));
        $this->assertFalse(Schema::hasColumn('users', 'trial_ends_at'));

        $this->migrateDown();
        $this->assertFalse(Schema::hasColumn('users', 'vatly_id'));
    }

    private function migrateUp(): void
    {
        (require self::STUB_PATH)->up();
    }

    private function migrateDown(): void
    {
        (require self::STUB_PATH)->down();
    }
}
