<?php

namespace JutForm\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;
use JutForm\Core\RequestContext;
use JutForm\Core\Request;
use JutForm\Core\Response;
use JutForm\Models\Form;
use JutForm\Models\Payment;
use JutForm\Models\Submission;
use JutForm\Services\PaymentGatewayService;
use RuntimeException;

class FeatureController
{
    public function exportPdf(Request $request, string $id): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }

        $formId = (int) $id;
        $form = Form::find($formId);
        if (!$form || (int) $form['user_id'] !== $uid) {
            Response::error('Not found', 404);
        }

        $rows = Submission::findByForm($formId, 5000, 0);
        $html = $this->renderPdfHtml($form, $rows);
        $pdf = $this->renderPdfDocument($html);
        $filename = $this->pdfFilename((string) ($form['title'] ?? ('form-' . $formId)), $formId);

        Response::pdf($filename, $pdf);
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

    /**
     * @param array<string, mixed> $form
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderPdfHtml(array $form, array $rows): string
    {
        $templatePath = dirname(__DIR__, 2) . '/resources/pdf-template.html';
        $template = file_get_contents($templatePath);
        if (!is_string($template) || $template === '') {
            Response::error('PDF template missing', 500);
        }

        $rowText = [];
        foreach ($rows as $index => $row) {
            $rowText[] = $this->formatSubmissionLine($index + 1, $row);
        }

        $rowsBlock = htmlspecialchars(
            $rowText !== [] ? implode("\n\n", $rowText) : 'No submissions yet.',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return strtr($template, [
            '{{form_name}}' => htmlspecialchars((string) ($form['title'] ?? 'Form export'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{generated_at}}' => gmdate('Y-m-d H:i:s') . ' UTC',
            '{{submission_count}}' => (string) count($rows),
            '{{rows}}' => $rowsBlock,
        ]);
    }

    private function renderPdfDocument(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function pdfFilename(string $title, int $formId): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'form-' . $formId;
        }

        return $slug . '-submissions.pdf';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatSubmissionLine(int $index, array $row): string
    {
        $submittedAt = (string) ($row['submitted_at'] ?? '');
        $payload = $this->compactSubmissionPayload((string) ($row['data_json'] ?? ''));

        return sprintf('#%d | %s | %s', $index, $submittedAt, $payload);
    }

    private function compactSubmissionPayload(string $json): string
    {
        if ($json === '') {
            return '{}';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            $normalized[(string) $key] = is_scalar($value) || $value === null
                ? (string) ($value ?? '')
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : $json;
    }
}
