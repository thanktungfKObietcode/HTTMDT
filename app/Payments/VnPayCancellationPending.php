<?php

namespace App\Payments;

use RuntimeException;

final class VnPayCancellationPending extends RuntimeException
{
    public const MESSAGE = 'Trạng thái thanh toán VNPay đang được xác minh. Vui lòng thử lại sau.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
