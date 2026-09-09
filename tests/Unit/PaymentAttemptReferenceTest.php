<?php

namespace Tests\Unit;

use App\Support\PaymentAttemptReference;
use PHPUnit\Framework\TestCase;

class PaymentAttemptReferenceTest extends TestCase
{
    public function test_generated_references_are_distinct_and_vnpay_compatible(): void
    {
        $first = PaymentAttemptReference::generate();
        $second = PaymentAttemptReference::generate();

        $this->assertNotSame($first, $second);
        $this->assertTrue(PaymentAttemptReference::isValid($first));
        $this->assertTrue(PaymentAttemptReference::isValid($second));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{26}$/D', $first);
        $this->assertStringNotContainsString('-', $first);
        $this->assertFalse(PaymentAttemptReference::isValid('ORD-20260909-0001'));
    }
}
