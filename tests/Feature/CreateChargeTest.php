<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentProvider;
use App\Models\Merchant;
use App\Payments\ChargeRequest;
use App\Payments\ChargeResult;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\PaymentGatewayResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_is_required(): void
    {
        $this->postJson('/api/charge', $this->payload())
            ->assertUnauthorized();
    }

    public function test_charge_ability_is_required(): void
    {
        $merchant = $this->merchant();
        $token = $merchant->createToken('test', []);

        $this->withToken($token->plainTextToken)
            ->withHeader('Idempotency-Key', 'attempt-001')
            ->postJson('/api/charge', $this->payload())
            ->assertForbidden();
    }

    public function test_it_uses_the_authenticated_merchants_gateway(): void
    {
        $merchant = $this->merchant(PaymentProvider::Pin);

        $gateway = $this->createMock(PaymentGateway::class);

        $gateway->expects(self::once())
            ->method('charge')
            ->willReturnCallback(function (ChargeRequest $request): ChargeResult {
                self::assertSame(1050, $request->money->amount);
                self::assertSame('EUR', $request->money->currency);
                self::assertSame('attempt-001', $request->idempotencyKey);
                self::assertSame('4242424242424242', $request->card->number);
                self::assertSame('123', $request->card->cvv);

                return new ChargeResult(
                    providerChargeId: 'fake-charge-001',
                    money: $request->money,
                );
            });

        $this->app->instance(
            PaymentGatewayResolver::class,
            new PaymentGatewayResolver([
                'stripe' => function (Merchant $resolved): PaymentGateway {
                    self::fail('The Stripe factory must not be selected.');
                },
                'pin' => function (Merchant $resolved) use (
                    $merchant,
                    $gateway,
                ): PaymentGateway {
                    self::assertSame($merchant->id, $resolved->id);
                    self::assertSame(
                        ['secret_key' => 'test-secret'],
                        $resolved->psp_credentials,
                    );

                    return $gateway;
                },
            ]),
        );

        $this->authenticate($merchant);

        $this->postJson('/api/charge', $this->payload())
            ->assertCreated()
            ->assertExactJson([
                'data' => [
                    'provider_charge_id' => 'fake-charge-001',
                    'status' => 'succeeded',
                    'amount' => 1050,
                    'currency' => 'EUR',
                ],
            ]);
    }

    public function test_invalid_input_never_reaches_the_gateway(): void
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $gateway->expects(self::never())->method('charge');

        $this->app->instance(
            PaymentGatewayResolver::class,
            new PaymentGatewayResolver([
                'stripe' => fn (Merchant $merchant): PaymentGateway => $gateway,
            ]),
        );

        $this->authenticate($this->merchant());

        $payload = $this->payload();
        $payload['amount'] = 0;
        $payload['card']['expiration_date'] = now()
            ->subMonth()
            ->format('m/Y');

        $this->postJson('/api/charge', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'amount',
                        'card.expiration_date',
                    ],
                ],
            ]);
    }

    public function test_an_idempotency_header_is_required(): void
    {
        $merchant = $this->merchant();
        $token = $merchant->createToken('test', ['charges:create']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/charge', $this->payload())
            ->assertUnprocessable()
            ->assertJsonStructure([
                'error' => ['details' => ['idempotency_key']],
            ]);
    }

    public function test_the_caller_cannot_select_a_merchant_or_provider(): void
    {
        $this->authenticate($this->merchant());

        $payload = $this->payload();
        $payload['merchant_id'] = 999;
        $payload['psp'] = 'pin';

        $this->postJson('/api/charge', $payload)
            ->assertUnprocessable()
            ->assertJsonStructure([
                'error' => ['details' => ['merchant_id', 'psp']],
            ]);
    }

    public function test_an_unconfigured_provider_returns_503(): void
    {
        $this->authenticate($this->merchant());

        $this->postJson('/api/charge', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath(
                'error.code',
                'PAYMENT_PROVIDER_UNAVAILABLE',
            );
    }

    private function authenticate(Merchant $merchant): void
    {
        $token = $merchant->createToken('test', ['charges:create']);

        $this->withToken($token->plainTextToken)
            ->withHeader('Idempotency-Key', 'attempt-001');
    }

    private function merchant(
        PaymentProvider $provider = PaymentProvider::Stripe,
    ): Merchant {
        return Merchant::create([
            'name' => 'Test Merchant',
            'psp' => $provider,
            'psp_credentials' => [
                'secret_key' => 'test-secret',
            ],
        ]);
    }

    private function payload(): array
    {
        return [
            'amount' => 1050,
            'currency' => 'EUR',
            'card' => [
                'card_number' => '4242424242424242',
                'expiration_date' => now()->addYear()->format('m/Y'),
                'cvv' => '123',
                'cardholder_name' => 'Test Customer',
            ],
        ];
    }
}
