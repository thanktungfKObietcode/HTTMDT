<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Payments\GatewayVerificationException;
use App\Payments\GatewayEventType;
use App\Payments\PaymentEventOutcome;
use App\Payments\VnPayIpnResponse;
use App\Services\PaymentService;
use App\Services\PaymentGatewayJournal;
use App\Services\VnPayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class VnPayController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $payments,
        private readonly VnPayPaymentService $vnpay,
    ) {}

    public function initiate(Request $request, Order $order): RedirectResponse
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 403);

        try {
            return redirect()->away($this->vnpay->paymentUrl($order, (int) $request->user()->id, $request->ip()));
        } catch (Throwable) {
            return redirect()->route('order.show', $order)->withErrors([
                'payment' => 'Chưa thể mở thanh toán VNPay. Vui lòng kiểm tra trạng thái đơn hoặc thử lại sau.',
            ]);
        }
    }

    public function returnResult(Request $request): Response
    {
        try {
            $event = $this->gateway->verifyReturn($request->query());
            app(PaymentGatewayJournal::class)->observe(GatewayEventType::ReturnReceived, $event);
            $viewerId = $request->user()?->is_active ? (int) $request->user()->id : null;
            $result = $this->vnpay->returnResult($event, $viewerId);
        } catch (Throwable) {
            app(PaymentGatewayJournal::class)->observe(GatewayEventType::ReturnReceived, metadata: ['reason' => 'unverified']);
            $result = ['state' => 'invalid', 'order' => null];
        }

        return response()->view('storefront.vnpay-result', $result + [
            'pageTitle' => 'Kết quả thanh toán | Silver Atelier',
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function ipn(Request $request): JsonResponse
    {
        try {
            $event = $this->gateway->verifyIpn($request->query());
        } catch (GatewayVerificationException $exception) {
            app(PaymentGatewayJournal::class)->observe(GatewayEventType::IpnReceived, metadata: ['reason' => $exception->outcome->value]);
            return response()->json(VnPayIpnResponse::body($exception->outcome));
        } catch (Throwable) {
            app(PaymentGatewayJournal::class)->observe(GatewayEventType::IpnReceived, metadata: ['reason' => 'unverified']);
            return response()->json(VnPayIpnResponse::body(PaymentEventOutcome::InvalidEvent));
        }

        try {
            app(PaymentGatewayJournal::class)->observe(GatewayEventType::IpnReceived, $event);
            $outcome = $this->payments->settleVerifiedEvent($event);
        } catch (Throwable) {
            // Never log the signed query or an exception containing SQL bindings.
            Log::error('VNPay IPN settlement failed.', ['merchant_reference' => $event->merchantReference]);
            $outcome = PaymentEventOutcome::TemporaryFailure;
        }

        return response()->json(VnPayIpnResponse::body($outcome))->header('Cache-Control', 'no-store');
    }
}
