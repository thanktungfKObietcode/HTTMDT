<?php

namespace Tests\Unit;

use App\Support\VnPayAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class VnPayAmountTest extends TestCase
{
    public function test_it_converts_decimal_vnd_to_and_from_vnpay_amount_without_floats(): void
    {
        $this->assertSame('100', VnPayAmount::toGatewayAmount('1.00'));
        $this->assertSame('1250000000', VnPayAmount::toGatewayAmount('12500000.00'));
        $this->assertSame('999999999900', VnPayAmount::toGatewayAmount('9999999999.00'));

        $this->assertSame('1.00', VnPayAmount::toDecimal('100'));
        $this->assertSame('12500000.00', VnPayAmount::toDecimal('1250000000'));
        $this->assertSame('9999999999.00', VnPayAmount::toDecimal('999999999900'));
    }

    #[DataProvider('invalidDecimalAmounts')]
    public function test_it_rejects_invalid_internal_amounts(string $amount): void
    {
        $this->expectException(RuntimeException::class);

        VnPayAmount::toGatewayAmount($amount);
    }

    /** @return array<string, array{string}> */
    public static function invalidDecimalAmounts(): array
    {
        return [
            'zero' => ['0.00'],
            'negative' => ['-1.00'],
            'malformed' => ['one hundred'],
            'too many decimals' => ['1.000'],
            'fractional VND' => ['1.01'],
            'gateway overflow' => ['10000000000.00'],
        ];
    }

    #[DataProvider('invalidGatewayAmounts')]
    public function test_it_rejects_invalid_gateway_amounts(string $amount): void
    {
        $this->expectException(RuntimeException::class);

        VnPayAmount::toDecimal($amount);
    }

    /** @return array<string, array{string}> */
    public static function invalidGatewayAmounts(): array
    {
        return [
            'zero' => ['0'],
            'fractional VND boundary' => ['101'],
            'too many digits' => ['1000000000000'],
            'decimal characters' => ['100.00'],
            'negative' => ['-100'],
            'malformed' => ['abc'],
        ];
    }

    public function test_it_rejects_non_vnd_currency(): void
    {
        $this->expectException(RuntimeException::class);

        VnPayAmount::toGatewayAmount('100.00', 'USD');
    }
}
