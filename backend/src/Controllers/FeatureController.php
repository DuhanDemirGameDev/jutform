<?php

namespace JutForm\Controllers;

use JutForm\Core\RequestContext;
use JutForm\Core\Request;
use JutForm\Core\Response;
use JutForm\Models\Form;
use JutForm\Models\Payment;
use JutForm\Services\PaymentGatewayService;
use RuntimeException;

class FeatureController
{
    public function exportPdf(Request $request, string $id): void
    {
        Response::error('Not implemented', 501);
    }

    public function createPayment(Request $request): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }

        $body = $request->jsonBody();
        $formId = (int) ($body['form_id'] ?? 0);
        $amountRaw = $body['amount'] ?? null;
        if ($formId <= 0 || !is_numeric($amountRaw)) {
            Response::error('form_id and amount are required', 400);
        }

        $amount = number_format((float) $amountRaw, 2, '.', '');
        if ((float) $amount <= 0.0) {
            Response::error('amount must be greater than zero', 400);
        }

        $form = Form::find($formId);
        if (!$form || (int) $form['user_id'] !== $uid) {
            Response::error('Not found', 404);
        }

        $gateway = new PaymentGatewayService();
        try {
            $salt = $gateway->fetchSalt();
        } catch (RuntimeException) {
            Response::error('Payment gateway unavailable', 503);
        }

        $datetimeUtc = gmdate('Y-m-d H:i:s');
        $hashInput = $uid . '|' . $amount . '|' . $datetimeUtc;
        $gatewayHash = hash('sha256', $hashInput . $salt);

        try {
            $response = $gateway->charge($uid, $amount, $datetimeUtc, $gatewayHash);
        } catch (RuntimeException) {
            Response::error('Payment gateway unavailable', 503);
        }

        $gatewayStatus = (string) ($response['body']['status'] ?? '');
        if ($gatewayStatus === 'approved') {
            Payment::create(
                $uid,
                $amount,
                isset($response['body']['transaction_id']) && is_string($response['body']['transaction_id'])
                    ? $response['body']['transaction_id']
                    : null,
                'approved',
                $gatewayHash,
                $datetimeUtc
            );
            Response::json([
                'transaction_id' => $response['body']['transaction_id'] ?? null,
                'status' => 'approved',
            ], 200);
        }

        if ($gatewayStatus === 'declined') {
            Payment::create(
                $uid,
                $amount,
                null,
                'declined',
                $gatewayHash,
                $datetimeUtc
            );
            Response::json([
                'status' => 'declined',
                'reason' => $response['body']['reason'] ?? 'declined',
            ], 402);
        }

        Response::error('Payment gateway unavailable', 503);
    }

    public function analyticsSummary(Request $request): void
    {
        Response::error('Not implemented', 501);
    }
}
