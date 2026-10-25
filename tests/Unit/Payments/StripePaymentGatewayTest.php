<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Payments\CardDetails;
use App\Payments\ChargeRequest;
use App\Payments\Enums\PaymentFailureReason;
use App\Payments\Exceptions\PaymentFailed;
use App\Payments\Gateways\StripePaymentGateway;
use App\Payments\Money;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StripePaymentGatewayTest extends TestCase
{
    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new Factory();
        $this->http->preventStrayRequests();
    }

    public function test_it_tokenizes_the_card_and_confirms_the_payment(): void
    {
        $this->fakeResponses($this->successfulPayment());

        $result = $this->gateway()->charge($this->request());

        self::assertSame('ch_test_001', $result->providerChargeId);
        self::assertSame(1050, $result->money->amount);
        self::assertSame('EUR', $result->money->currency);

        $this->http->assertSent(
            fn (Request $request): bool =>
                $request->url() === 'https://api.stripe.com/v1/tokens'
                && $request['card']['number'] === '4242424242424242'
                && $request['card']['cvc'] === '123'
                && $request->hasHeader(
                    'Authorization',
                    'Basic '.base64_encode('sk_test_example:'),
                )
        );

        $this->http->assertSent(
            fn (Request $request): bool =>
                $request->url() === 'https://api.stripe.com/v1/payment_intents'
                && (int) $request['amount'] === 1050
                && $request['currency'] === 'eur'
                && $request['payment_method_data']['card']['token'] === 'tok_test'
                && $request['confirm'] === 'true'
                && $request['error_on_requires_action'] === 'true'
        );

        $requests = $this->http->recorded();

        self::assertNotSame(
            $requests[0][0]->header('Idempotency-Key'),
            $requests[1][0]->header('Idempotency-Key'),
        );

        $this->http->assertSentCount(2);
    }

    #[DataProvider('failureResponses')]
    public function test_it_normalizes_provider_failures(
        array $body,
        int $status,
        PaymentFailureReason $expected,
    ): void {
        $this->fakeResponses($body, $status);

        try {
            $this->gateway()->charge($this->request());
            self::fail('A payment failure was expected.');
        } catch (PaymentFailed $exception) {
            self::assertSame($expected, $exception->reason);
        }

        $this->http->assertSentCount(2);
    }

    public static function failureResponses(): array
    {
        return [
            'decline' => [
                ['error' => ['type' => 'card_error', 'code' => 'card_declined']],
                402,
                PaymentFailureReason::PaymentDeclined,
            ],
            'authentication required' => [
                ['error' => [
                    'type' => 'card_error',
                    'code' => 'authentication_required',
                ]],
                402,
                PaymentFailureReason::AuthenticationRequired,
            ],
            'invalid API key' => [
                ['error' => ['type' => 'authentication_error']],
                401,
                PaymentFailureReason::ProviderUnavailable,
            ],
            'provider server error' => [
                ['error' => ['type' => 'api_error']],
                500,
                PaymentFailureReason::OutcomeUnknown,
            ],
            'idempotency conflict' => [
                ['error' => ['type' => 'idempotency_error']],
                400,
                PaymentFailureReason::IdempotencyConflict,
            ],
            'request rejected' => [
                ['error' => ['type' => 'invalid_request_error']],
                400,
                PaymentFailureReason::PaymentRequestRejected,
            ],
            'processing is not success' => [
                ['status' => 'processing'],
                200,
                PaymentFailureReason::OutcomeUnknown,
            ],
            'incomplete success response' => [
                ['status' => 'succeeded'],
                200,
                PaymentFailureReason::OutcomeUnknown,
            ],
        ];
    }

    public function test_a_payment_connection_failure_has_an_unknown_outcome(): void
    {
        $this->http->fake([
            'https://api.stripe.com/v1/tokens' =>
                Factory::response(['id' => 'tok_test']),
            'https://api.stripe.com/v1/payment_intents' =>
                Factory::failedConnection(),
        ]);

        try {
            $this->gateway()->charge($this->request());
            self::fail('A payment failure was expected.');
        } catch (PaymentFailed $exception) {
            self::assertSame(
                PaymentFailureReason::OutcomeUnknown,
                $exception->reason,
            );
        }
    }

    public function test_a_tokenization_failure_does_not_attempt_payment(): void
    {
        $this->http->fake([
            'https://api.stripe.com/v1/tokens' =>
                Factory::response([
                    'error' => ['type' => 'authentication_error'],
                ], 401),
        ]);

        try {
            $this->gateway()->charge($this->request());
            self::fail('A payment failure was expected.');
        } catch (PaymentFailed $exception) {
            self::assertSame(
                PaymentFailureReason::ProviderUnavailable,
                $exception->reason,
            );
        }

        $this->http->assertSentCount(1);
    }

    private function fakeResponses(array $payment, int $status = 200): void
    {
        $this->http->fake([
            'https://api.stripe.com/v1/tokens' =>
                Factory::response(['id' => 'tok_test']),
            'https://api.stripe.com/v1/payment_intents' =>
                Factory::response($payment, $status),
        ]);
    }

    private function successfulPayment(): array
    {
        return [
            'id' => 'pi_test_001',
            'status' => 'succeeded',
            'latest_charge' => 'ch_test_001',
            'amount_received' => 1050,
            'currency' => 'eur',
            'livemode' => false,
        ];
    }

    private function gateway(): StripePaymentGateway
    {
        return new StripePaymentGateway(
            http: $this->http,
            secretKey: 'sk_test_example',
            merchantScope: 'merchant-1',
            apiVersion: '2024-06-20',
        );
    }

    private function request(): ChargeRequest
    {
        return new ChargeRequest(
            money: new Money(1050, 'EUR'),
            card: new CardDetails(
                number: '4242424242424242',
                expirationMonth: 12,
                expirationYear: 2035,
                cvv: '123',
                cardholderName: 'Test Customer',
            ),
            idempotencyKey: 'attempt-001',
        );
    }
}
