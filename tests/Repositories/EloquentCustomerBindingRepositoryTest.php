<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Repositories;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Laravel\Exceptions\AnonymousVatlyCustomerNotSupportedException;
use Vatly\Laravel\Repositories\EloquentCustomerBindingRepository;
use Vatly\Laravel\Tests\BaseTestCase;

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

    public function test_record_is_a_no_op_for_an_already_bound_customer(): void
    {
        $user = User::factory()->create(['vatly_id' => 'cus_known']);

        $this->repo->record('cus_known');

        // Still exactly one row, vatly_id unchanged.
        $this->assertSame(1, User::query()->where('vatly_id', 'cus_known')->count());
        $this->assertSame('cus_known', $user->fresh()->vatly_id);
    }

    public function test_record_throws_for_an_anonymous_customer(): void
    {
        $this->expectException(AnonymousVatlyCustomerNotSupportedException::class);
        $this->expectExceptionMessageMatches('/cus_anon/');

        $this->repo->record('cus_anon');
    }

    public function test_host_customer_id_for_returns_user_id_when_bound(): void
    {
        $user = User::factory()->create(['vatly_id' => 'cus_lookup']);

        $this->assertSame((string) $user->id, $this->repo->hostCustomerIdFor('cus_lookup'));
    }

    public function test_host_customer_id_for_returns_null_when_not_bound(): void
    {
        $this->assertNull($this->repo->hostCustomerIdFor('cus_unknown'));
    }

    public function test_vatly_customer_id_for_returns_vatly_id_when_bound(): void
    {
        $user = User::factory()->create(['vatly_id' => 'cus_lookup']);

        $this->assertSame('cus_lookup', $this->repo->vatlyCustomerIdFor((string) $user->id));
    }

    public function test_vatly_customer_id_for_returns_null_when_not_bound(): void
    {
        $user = User::factory()->create(['vatly_id' => null]);

        $this->assertNull($this->repo->vatlyCustomerIdFor((string) $user->id));
    }

    public function test_vatly_customer_id_for_returns_null_for_nonexistent_host(): void
    {
        $this->assertNull($this->repo->vatlyCustomerIdFor('999999'));
    }
}
