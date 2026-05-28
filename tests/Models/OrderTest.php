<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Laravel\Models\Order;
use Vatly\Laravel\Tests\BaseTestCase;

class OrderTest extends BaseTestCase
{
    use RefreshDatabase;

    public function test_it_implements_order_interface(): void
    {
        $order = new Order;

        $this->assertInstanceOf(OrderInterface::class, $order);
    }

    public function test_it_can_be_created_with_attributes(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'order-test@example.com',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'ord_test_123',
            'status' => 'paid',
            'total' => 9900,
            'currency' => 'EUR',
            'invoice_number' => 'INV-2024-001',
            'payment_method' => 'credit_card',
        ]);

        $this->assertSame('ord_test_123', $order->getVatlyId());
        $this->assertSame('paid', $order->getStatus());
        $this->assertSame(9900, $order->getTotal());
        $this->assertSame('EUR', $order->getCurrency());
        $this->assertSame('INV-2024-001', $order->getInvoiceNumber());
        $this->assertSame('credit_card', $order->getPaymentMethod());
        $this->assertTrue($order->isPaid());
    }

    public function test_it_has_a_morph_to_owner_relationship(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'order-owner@example.com',
            'password' => bcrypt('password'),
        ]);

        $order = Order::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'ord_owner_123',
            'status' => 'paid',
            'total' => 4900,
            'currency' => 'EUR',
        ]);

        $this->assertInstanceOf(User::class, $order->owner);
        $this->assertSame($user->id, $order->owner->id);
    }

    public function test_user_can_access_orders_via_relationship(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'order-rel@example.com',
            'password' => bcrypt('password'),
        ]);

        Order::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'ord_rel_1',
            'status' => 'paid',
            'total' => 9900,
            'currency' => 'EUR',
        ]);

        Order::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'vatly_id' => 'ord_rel_2',
            'status' => 'paid',
            'total' => 4900,
            'currency' => 'USD',
        ]);

        $this->assertCount(2, $user->orders);
    }

    public function test_is_paid_returns_false_for_non_paid_orders(): void
    {
        $order = new Order(['status' => 'pending']);

        $this->assertFalse($order->isPaid());
    }
}
