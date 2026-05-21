<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Fluent\Billable as FluentBillable;
use Vatly\Laravel\Tests\BaseTestCase;

class VatlyHelperTest extends BaseTestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_fluent_billable_for_a_user_with_the_trait(): void
    {
        $user = User::factory()->create(['vatly_id' => 'customer_abc']);

        $billable = vatly($user);

        $this->assertInstanceOf(FluentBillable::class, $billable);
    }

    public function test_it_returns_null_for_null_owner(): void
    {
        $this->assertNull(vatly(null));
    }

    public function test_it_returns_null_for_a_model_without_the_trait(): void
    {
        $plainModel = new class extends Model {};

        $this->assertNull(vatly($plainModel));
    }

    public function test_it_returns_null_for_an_arbitrary_object(): void
    {
        $object = new \stdClass();

        $this->assertNull(vatly($object));
    }
}
