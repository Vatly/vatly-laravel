<?php

declare(strict_types=1);

namespace Vatly\Laravel\Tests\Builders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vatly\API\VatlyApiClient;
use Vatly\Laravel\Builders\CheckoutBuilder;
use Vatly\Laravel\Exceptions\IncompleteInformationException;
use Vatly\Laravel\Tests\BaseTestCase;
use Vatly\Laravel\VatlyApiActions\CreateVatlyCheckout;
use Vatly\Laravel\VatlyApiActions\CreateVatlyCheckoutResponse;

class CheckoutBuilderTest extends BaseTestCase
{
    use RefreshDatabase;

    private CheckoutBuilder $checkoutBuilder;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();

        $this->checkoutBuilder = new CheckoutBuilder(
            owner: $this->owner,
            createVatlyCheckout: new CreateVatlyCheckout(vatlyApiClient: new VatlyApiClient()),
        );
    }

    /** @test */
    public function it_instantiates(): void
    {
        $this->assertInstanceOf(CheckoutBuilder::class, $this->checkoutBuilder);
    }

    /** @test */
    public function it_populates_a_minimum_unfiltered_payload(): void
    {
        $payload = $this->checkoutBuilder
            ->withRedirectUrlSuccess('https://example.com/success')
            ->withRedirectUrlCanceled('https://example.com/canceled')
            ->withItems(collect([
                'subscription_foo_bar',
            ]))
            ->payload(filtered: false);

        $this->assertEquals([
            'products' => [
                'subscription_foo_bar',
            ],
            'customerId' => null,
            'redirectUrlSuccess' => 'https://example.com/success',
            'redirectUrlCanceled' => 'https://example.com/canceled',
            'metadata' => null,
        ], $payload);
    }

    /** @test */
    public function it_filters_payload_by_default(): void
    {
        $payload = $this->checkoutBuilder
            ->withRedirectUrlSuccess('https://example.com/success')
            ->withRedirectUrlCanceled('https://example.com/canceled')
            ->payload();

        $this->assertEquals([
            'redirectUrlSuccess' => 'https://example.com/success',
            'redirectUrlCanceled' => 'https://example.com/canceled',
        ], $payload);
    }

    /** @test */
    public function an_exception_is_thrown_if_no_items_are_provided(): void
    {
        $this->expectExceptionObject(IncompleteInformationException::noCheckoutItems());

        $this->checkoutBuilder->create(
            items: collect(),
            redirectUrlSuccess: 'https://example.com/success',
            redirectUrlCanceled: 'https://example.com/canceled',
        );
    }

    /** @test */
    public function it_creates_a_new_checkout(): void
    {
        $mockedCreateVatlyCheckout = $this->createMock(CreateVatlyCheckout::class);
        $payload = [
            'products' => ['subscription_foo_bar'],
            'redirectUrlSuccess' => 'https://example.com/success',
            'redirectUrlCanceled' => 'https://example.com/canceled',
        ];
        $filters = [];
        $mockedCreateVatlyCheckout
            ->expects($this->once())
            ->method('execute')
            ->with($payload, $filters)
            ->willReturn(new CreateVatlyCheckoutResponse(
                checkoutId: 'checkout_dummy_1',
                checkoutUrl: 'https://foo-bar.com'
            ));

        $checkoutBuilder = new CheckoutBuilder(
            owner: $this->owner,
            createVatlyCheckout: $mockedCreateVatlyCheckout,
        );

        $checkoutBuilder->create(
            items: collect(['subscription_foo_bar']),
            redirectUrlSuccess: 'https://example.com/success',
            redirectUrlCanceled: 'https://example.com/canceled',
        );
    }
}
