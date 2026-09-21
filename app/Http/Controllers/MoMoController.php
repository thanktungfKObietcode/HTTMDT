<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Payments\GatewayEventType;
use App\Payments\MoMoGateway;
use App\Payments\PaymentEventOutcome;
use App\Payments\VerifiedPaymentEvent;
use App\Services\MoMoPaymentService;
use App\Services\PaymentGatewayJournal;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MoMoController extends Controller
{
    public function __construct(private readonly MoMoGateway $gateway,
        private readonly MoMoPaymentService $momo, private readonly PaymentService $payments,
        private readonly PaymentGatewayJournal $journal) {}

    public function initiate(Request $request, Order $order): RedirectResponse
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 403);
        try {
            return redirect()->away($this->momo->paymentUrl($order, (int) $request->user()->id));
        } catch (\Throwable) {
            return redirect()->route('order.show', $order)->withErrors([
                'payment' => 'Chưa thể mở thanh toán MoMo. Đơn hàng đã được lưu; vui lòng kiểm tra trạng thái trước khi thử lại.',
            ]);
        }
    }

    public function returnResult(Request $request): Response
    {
        try {
            parse_str((string) $request->server('QUERY_STRING', ''), $values);
            $event = $this->gateway->verifyResult($values, VerifiedPaymentEvent::TYPE_RETURN);
            $this->journal->observe(GatewayEventType::ReturnReceived, $event);
            $viewerId = $request->user()?->is_active ? (int) $request->user()->id : null;
            $result = $this->momo->returnResult($event, $viewerId);
        } catch (\Throwable) {
            $this->journal->observe(GatewayEventType::ReturnReceived, metadata: ['reason' => 'unverified'], gateway: 'momo');
            $result = ['state' => 'invalid', 'order' => null];
        }
        return response()->view('storefront.momo-result', $result + ['pageTitle' => 'Kết quả MoMo | Silver Atelier'])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function ipn(Request $request): JsonResponse|Response
    {
        if (! $request->isJson() || strlen($request->getContent()) > 65536) {
            return response()->json(['message' => 'Invalid notification'], 400);
        }
        try {
            // Middleware may turn an empty signed extraData string into null.
            // Verify the original JSON scalars exactly as MoMo signed them.
            $values = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($values)) {
                throw new \RuntimeException('Invalid MoMo JSON body.');
            }
            $event = $this->gateway->verifyResult($values, VerifiedPaymentEvent::TYPE_IPN);
        } catch (\Throwable) {
            $this->journal->observe(GatewayEventType::IpnReceived, metadata: ['reason' => 'unverified'], gateway: 'momo');
            return response()->json(['message' => 'Invalid notification'], 400);
        }
        try {
            $this->journal->observe(GatewayEventType::IpnReceived, $event);
            $outcome = $this->payments->settleVerifiedEvent($event);
        } catch (\Throwable) {
            return response()->json(['message' => 'Temporary failure'], 503);
        }
        if (in_array($outcome, [PaymentEventOutcome::Processed, PaymentEventOutcome::AlreadyProcessed], true)) {
            return response()->noContent();
        }
        return response()->json(['message' => 'Payment status requires verification'], 409);
    }
}
