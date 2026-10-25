<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Payments\ChargeRequest;
use App\Payments\ChargeResult;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Enums\PaymentFailureReason;
use App\Payments\Exceptions\PaymentFailed;
use App\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Payments\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

final class StripePaymentGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    public function __construct(
        private readonly Factory $http,
        private readonly string $secretKey,
        private readonly string $merchantScope,
        private readonly string $apiVersion,
    ) {
        // This adapter is deliberately sandbox-only at this stage.
        if (! str_starts_with($secretKey, 'sk_test_')) {
            throw new PaymentGatewayUnavailable(
                'A Stripe test secret key is required.',
            );
        }

        if ($apiVersion === '') {
            throw new PaymentGatewayUnavailable(
                'A Stripe API version is required.',
            );
        }
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $token = $this->post(
            path: '/tokens',
            payload: [
                'card' => [
                    'number' => $request->card->number,
                    'exp_month' => $request->card->expirationMonth,
                    'exp_year' => $request->card->expirationYear,
                    'cvc' => $request->card->cvv,
                    'name' => $request->card->cardholderName,
                ],
            ],
            idempotencyKey: $this->idempotencyKey($request, 'token'),
            mayCharge: false,
        );

        $tokenId = $token->json('id');

        if (! is_string($tokenId) || ! str_starts_with($tokenId, 'tok_')) {
            throw new PaymentFailed(
                PaymentFailureReason::ProviderUnavailable,
            );
        }

        $payment = $this->post(
            path: '/payment_intents',
            payload: [
                'amount' => $request->money->amount,
                'currency' => strtolower($request->money->currency),
                'payment_method_types' => ['card'],
                'payment_method_data' => [
                    'type' => 'card',
                    'card' => [
                        'token' => $tokenId,
                    ],
                    'billing_details' => [
                        'name' => $request->card->cardholderName,
                    ],
                ],
                'capture_method' => 'automatic',
                'confirm' => 'true',
                'error_on_requires_action' => 'true',
            ],
            idempotencyKey: $this->idempotencyKey($request, 'payment'),
            mayCharge: true,
        );

        $status = $payment->json('status');

        if ($status === 'requires_action') {
            throw new PaymentFailed(
                PaymentFailureReason::AuthenticationRequired,
            );
        }

        if (in_array($status, ['requires_payment_method', 'canceled'], true)) {
            throw new PaymentFailed(PaymentFailureReason::PaymentDeclined);
        }

        if ($status !== 'succeeded') {
            throw new PaymentFailed(PaymentFailureReason::OutcomeUnknown);
        }

        $chargeId = $payment->json('latest_charge');
        $amount = $payment->json('amount_received');
        $currency = $payment->json('currency');

        // Do not report success from an incomplete or inconsistent response.
        if (
            ! is_string($chargeId)
            || ! str_starts_with($chargeId, 'ch_')
            || $amount !== $request->money->amount
            || ! is_string($currency)
            || strtoupper($currency) !== $request->money->currency
            || $payment->json('livemode') !== false
        ) {
            throw new PaymentFailed(PaymentFailureReason::OutcomeUnknown);
        }

        return new ChargeResult(
            providerChargeId: $chargeId,
            money: new Money($amount, strtoupper($currency)),
        );
    }

    private function post(
        string $path,
        array $payload,
        string $idempotencyKey,
        bool $mayCharge,
    ): Response {
        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->withBasicAuth($this->secretKey, '')
                ->withHeaders([
                    'Stripe-Version' => $this->apiVersion,
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->connectTimeout(5)
                ->timeout(30)
                ->withOptions(['allow_redirects' => false])
                ->post(self::BASE_URL.$path, $payload);
        } catch (ConnectionException) {
            throw new PaymentFailed(
                $mayCharge
                    ? PaymentFailureReason::OutcomeUnknown
                    : PaymentFailureReason::ProviderUnavailable,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw new PaymentFailed(
            $this->failureReason($response, $mayCharge),
        );
    }

    private function failureReason(
        Response $response,
        bool $mayCharge,
    ): PaymentFailureReason {
        $type = $response->json('error.type');
        $code = $response->json('error.code');

        if (
            $response->serverError()
            || $code === 'processing_error'
        ) {
            return $mayCharge
                ? PaymentFailureReason::OutcomeUnknown
                : PaymentFailureReason::ProviderUnavailable;
        }

        if (in_array($response->status(), [401, 403, 429], true)) {
            return PaymentFailureReason::ProviderUnavailable;
        }

        if (
            $response->status() === 409
            || $type === 'idempotency_error'
            || $code === 'idempotency_key_in_use'
        ) {
            return PaymentFailureReason::IdempotencyConflict;
        }

        if (in_array($code, [
            'authentication_required',
            'payment_intent_action_required',
        ], true)) {
            return PaymentFailureReason::AuthenticationRequired;
        }

        if ($type === 'card_error') {
            return PaymentFailureReason::PaymentDeclined;
        }

        return PaymentFailureReason::PaymentRequestRejected;
    }

    private function idempotencyKey(
        ChargeRequest $request,
        string $operation,
    ): string {
        $scope = hash(
            'sha256',
            $this->merchantScope.':'.$request->idempotencyKey,
        );

        return 'unifiedpay:'.$operation.':'.$scope;
    }
}
