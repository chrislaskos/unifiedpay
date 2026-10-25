<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreChargeRequest;
use App\Models\Merchant;
use App\Payments\ChargePayment;
use App\Payments\Enums\PaymentFailureReason;
use App\Payments\Exceptions\PaymentFailed;
use App\Payments\Exceptions\PaymentGatewayUnavailable;
use Illuminate\Http\JsonResponse;

final class ChargeController extends Controller
{
    public function __construct(
        private readonly ChargePayment $chargePayment,
    ) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreChargeRequest $request): JsonResponse
    {
        $merchant = $request->user();

        abort_unless($merchant instanceof Merchant, 403);

        try {
            $result = $this->chargePayment->execute(
                $merchant,
                $request->toChargeRequest(),
            );
        } catch (PaymentGatewayUnavailable) {
            return $this->failureResponse(
                PaymentFailureReason::ProviderUnavailable,
            );
        } catch (PaymentFailed $exception) {
            return $this->failureResponse($exception->reason);
        }

        return response()->json([
            'data' => [
                'provider_charge_id' => $result->providerChargeId,
                'status' => 'succeeded',
                'amount' => $result->money->amount,
                'currency' => $result->money->currency,
            ],
        ], 201);
    }

    private function failureResponse(
        PaymentFailureReason $reason,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $reason->value,
                'message' => $reason->message(),
            ],
        ], $reason->httpStatus());
    }
}
