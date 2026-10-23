<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Merchant;
use App\Payments\CardDetails;
use App\Payments\ChargeRequest;
use App\Payments\Money;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class StoreChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $merchant = $this->user();

        return $merchant instanceof Merchant
            && $merchant->tokenCan('charges:create');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:99999999'],
            'currency' => ['required', 'string', 'in:EUR,USD,AUD'],

            'card' => [
                'required',
                'array:card_number,expiration_date,cvv,cardholder_name',
            ],
            'card.card_number' => [
                'required',
                'string',
                'regex:/^[0-9]{13,19}$/D',
            ],
            'card.expiration_date' => [
                'required',
                'string',
                'date_format:m/Y',
            ],
            'card.cvv' => [
                'required',
                'string',
                'regex:/^[0-9]{3,4}$/D',
            ],
            'card.cardholder_name' => [
                'required',
                'string',
                'max:255',
            ],

            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9._:-]+$/D',
            ],

            'merchant_id' => ['prohibited'],
            'psp' => ['prohibited'],
            'psp_credentials' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('card.expiration_date')) {
                    return;
                }

                [$month, $year] = explode(
                    '/',
                    $this->input('card.expiration_date'),
                );

                $expirationMonth = ((int) $year * 100) + (int) $month;
                $currentMonth = (int) now('UTC')->format('Ym');

                if ($expirationMonth < $currentMonth) {
                    $validator->errors()->add(
                        'card.expiration_date',
                        'The card has expired.',
                    );
                }
            },
        ];
    }

    public function toChargeRequest(): ChargeRequest
    {
        $data = $this->validated();
        $card = $data['card'];

        [$month, $year] = explode('/', $card['expiration_date']);

        return new ChargeRequest(
            money: new Money(
                amount: (int) $data['amount'],
                currency: $data['currency'],
            ),
            card: new CardDetails(
                number: $card['card_number'],
                expirationMonth: (int) $month,
                expirationYear: (int) $year,
                cvv: $card['cvv'],
                cardholderName: $card['cardholder_name'],
            ),
            idempotencyKey: $data['idempotency_key'],
        );
    }

    protected function prepareForValidation(): void
    {
        // The header is authoritative, even if the body contains this field.
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        // Always return JSON; never redirect and flash card input to a session.
        throw new HttpResponseException(
            response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'The request contains invalid data.',
                    'details' => $validator->errors()->toArray(),
                ],
            ], 422),
        );
    }
}
