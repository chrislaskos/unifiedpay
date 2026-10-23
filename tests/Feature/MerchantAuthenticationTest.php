<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentProvider;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MerchantAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registered only during tests; not a production API resource.
        Route::middleware('auth:sanctum')
            ->get('/_test/merchant', function (Request $request): array {
                return [
                    'merchant_id' => $request->user()->getAuthIdentifier(),
                ];
            });
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->getJson('/_test/merchant')
            ->assertUnauthorized();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('invalid-token')
            ->getJson('/_test/merchant')
            ->assertUnauthorized();
    }

    public function test_valid_token_authenticates_its_merchant(): void
    {
        $merchant = $this->createMerchant();
        $token = $merchant->createToken('test', ['charges:create']);

        $this->withToken($token->plainTextToken)
            ->getJson('/_test/merchant')
            ->assertOk()
            ->assertExactJson([
                'merchant_id' => $merchant->id,
            ]);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $merchant = $this->createMerchant();
        $token = $merchant->createToken('test', ['charges:create']);

        $token->accessToken->delete();

        $this->withToken($token->plainTextToken)
            ->getJson('/_test/merchant')
            ->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $merchant = $this->createMerchant();
        $token = $merchant->createToken(
            'test',
            ['charges:create'],
            now()->subMinute(),
        );

        $this->withToken($token->plainTextToken)
            ->getJson('/_test/merchant')
            ->assertUnauthorized();
    }

    public function test_psp_credentials_are_encrypted_and_hidden(): void
    {
        $merchant = $this->createMerchant()->fresh();

        $this->assertSame(
            ['secret_key' => 'test-secret'],
            $merchant->psp_credentials,
        );

        $this->assertStringNotContainsString(
            'test-secret',
            $merchant->getRawOriginal('psp_credentials'),
        );

        $this->assertArrayNotHasKey(
            'psp_credentials',
            $merchant->toArray(),
        );
    }

    private function createMerchant(): Merchant
    {
        return Merchant::create([
            'name' => 'Test Merchant',
            'psp' => PaymentProvider::Stripe,
            'psp_credentials' => [
                'secret_key' => 'test-secret',
            ],
        ]);
    }
}
