<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Repositories;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Laravel\Repositories\EloquentCustomerBindingRepository;
use Vatly\Laravel\Tests\BaseTestCase;
use Vatly\Laravel\VatlyConfig;

class EloquentCustomerBindingRepositoryTest extends BaseTestCase
{
    use RefreshDatabase;

    private EloquentCustomerBindingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('vatly.billable_model', User::class);
        $this->repo = $this->app->make(EloquentCustomerBindingRepository::class);
    }

    public function test_bind_writes_vatly_id_to_the_user_row(): void
    {
        $user = User::factory()->create(['vatly_id' => null]);

        $this->repo->bind('cus_new', (string) $user->id);

        $this->assertSame('cus_new', $user->fresh()->vatly_id);
    }

    public function test_bind_is_idempotent(): void
    {
        $user = User::factory()->create(['vatly_id' => null]);

        $this->repo->bind('cus_first', (string) $user->id);
        $this->repo->bind('cus_first', (string) $user->id);

        $this->assertSame('cus_first', $user->fresh()->vatly_id);
    }

    public function test_record_is_a_no_op_for_default_eloquent_driver(): void
    {
        $this->repo->record('cus_anon');

        $this->assertSame(0, User::query()->where('vatly_id', 'cus_anon')->count());
    }

    public function test_host_id_for_returns_user_id_when_bound(): void
    {
        $user = User::factory()->create(['vatly_id' => 'cus_lookup']);

        $this->assertSame((string) $user->id, $this->repo->hostIdFor('cus_lookup'));
    }

    public function test_host_id_for_returns_null_when_not_bound(): void
    {
        $this->assertNull($this->repo->hostIdFor('cus_unknown'));
    }

    public function test_vatly_id_for_returns_vatly_id_when_bound(): void
    {
        $user = User::factory()->create(['vatly_id' => 'cus_lookup']);

        $this->assertSame('cus_lookup', $this->repo->vatlyIdFor((string) $user->id));
    }

    public function test_vatly_id_for_returns_null_when_not_bound(): void
    {
        $user = User::factory()->create(['vatly_id' => null]);

        $this->assertNull($this->repo->vatlyIdFor((string) $user->id));
    }

    public function test_vatly_id_for_returns_null_for_nonexistent_host(): void
    {
        $this->assertNull($this->repo->vatlyIdFor('999999'));
    }
}
