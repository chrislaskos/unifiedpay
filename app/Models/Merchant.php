<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Merchant extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'psp',
        'psp_credentials',
    ];

    protected $hidden = [
        'psp_credentials',
    ];

    protected function casts(): array
    {
        return [
            'psp' => PaymentProvider::class,
            'psp_credentials' => 'encrypted:array',
        ];
    }
}
