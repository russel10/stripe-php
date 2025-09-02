<?php

require_once '../vendor/autoload.php';
require_once '../secrets.php';

// Headers de segurança
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Configuração do Stripe
$stripe = new \Stripe\StripeClient($stripeSecretKey);

logEvent('info', 'Transaction data saved', ['stripe' => $stripe]);

// Logging
function logEvent($level, $message, $context = []) {
    $log = [
        'timestamp' => date('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    error_log(json_encode($log), 3, '../storage/log/stripe_webhook.log');
}

// Função para salvar dados no "banco" (simular com arquivo para POC)
function saveTransactionData($data) {
    $file = '../data/transactions.json';
    $transactions = [];

    // Carregar transações existentes
    if (file_exists($file)) {
        $existing = file_get_contents($file);
        $transactions = json_decode($existing, true) ?: [];
    }

    // Adicionar nova transação
    $transactions[] = [
        'id'                  => $data['id'],
        'amount'              => $data['amount'],
        'currency'            => $data['currency'],
        'status'              => $data['status'],
        'created'             => $data['created'],
        'payment_method'      => $data['payment_method'] ?? null,
        'metadata'            => $data['metadata'] ?? [],
        'webhook_received_at' => time()
    ];

    // Salvar arquivo
    if (!is_dir('../data')) {
        mkdir('../data', 0755, true);
    }

    file_put_contents($file, json_encode($transactions, JSON_PRETTY_PRINT));

    logEvent('info', 'Transaction data saved', ['transaction_id' => $data['id']]);
}

// Função para enviar notificações (email, SMS, etc.)
function sendNotifications($paymentIntent, $eventType) {
    $amount = number_format($paymentIntent['amount'] / 100, 2, ',', '.');

    switch ($eventType) {
        case 'payment_intent.succeeded':
            // Simular envio de email de confirmação
            $subject = "Pagamento aprovado - R$ {$amount}";
            $message = "Seu pagamento de R$ {$amount} foi processado com sucesso!\n\n";
            $message .= "ID da transação: {$paymentIntent['id']}\n";
            $message .= "Data: " . date('d/m/Y H:i:s') . "\n";

            logEvent('info', 'Success notification sent', [
                'transaction_id' => $paymentIntent['id'],
                'amount' => $paymentIntent['amount']
            ]);
            break;

        case 'payment_intent.payment_failed':
            // Simular envio de notificação de falha
            $subject = "Falha no pagamento - R$ {$amount}";
            $message = "Não foi possível processar seu pagamento de R$ {$amount}.\n\n";
            $message .= "ID da transação: {$paymentIntent['id']}\n";
            $message .= "Tente novamente ou use outro cartão.\n";

            logEvent('warning', 'Failure notification sent', [
                'transaction_id' => $paymentIntent['id'],
                'amount' => $paymentIntent['amount']
            ]);
            break;
    }

    // Aqui você implementaria o envio real do email/SMS
    // mail($email, $subject, $message);
    // sendSMS($phone, $message);
}

try {
    // Verificar se é uma requisição POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Obter payload e signature
    $payload = file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    if (empty($sig_header)) {
        logEvent('error', 'Missing Stripe signature header');
        http_response_code(400);
        echo json_encode(['error' => 'Missing signature']);
        exit;
    }

    // Verificar assinatura do webhook
    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhookSecret);
    } catch (\UnexpectedValueException $e) {
        logEvent('error', 'Invalid payload', ['error' => $e->getMessage()]);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        logEvent('error', 'Invalid signature', ['error' => $e->getMessage()]);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    logEvent('info', 'Webhook received', [
        'event_id' => $event['id'],
        'event_type' => $event['type']
    ]);

    // Processar eventos
    switch ($event['type']) {
        case 'payment_intent.succeeded':
            $paymentIntent = $event['data']['object'];

            logEvent('info', 'Payment succeeded', [
                'payment_intent_id' => $paymentIntent['id'],
                'amount' => $paymentIntent['amount'],
                'currency' => $paymentIntent['currency']
            ]);

            // Salvar dados da transação
            saveTransactionData([
                'id'             => $paymentIntent['id'],
                'amount'         => $paymentIntent['amount'],
                'currency'       => $paymentIntent['currency'],
                'status'         => 'succeeded',
                'created'        => $paymentIntent['created'],
                'payment_method' => $paymentIntent['payment_method'],
                'metadata'       => $paymentIntent['metadata']
            ]);

            // Enviar notificações
            sendNotifications($paymentIntent, 'payment_intent.succeeded');

            // Aqui você faria:
            // - Atualizar status do pedido no banco
            // - Enviar email de confirmação
            // - Ativar produto/serviço
            // - Atualizar estoque

            break;

        case 'payment_intent.payment_failed':
            $paymentIntent = $event['data']['object'];

            logEvent('warning', 'Payment failed', [
                'payment_intent_id' => $paymentIntent['id'],
                'amount' => $paymentIntent['amount'],
                'last_payment_error' => $paymentIntent['last_payment_error']
            ]);

            // Salvar dados da transação
            saveTransactionData([
                'id'             => $paymentIntent['id'],
                'amount'         => $paymentIntent['amount'],
                'currency'       => $paymentIntent['currency'],
                'status'         => 'failed',
                'created'        => $paymentIntent['created'],
                'payment_method' => $paymentIntent['payment_method'],
                'metadata'       => $paymentIntent['metadata'],
                'failure_reason' => $paymentIntent['last_payment_error']['message'] ?? 'Unknown'
            ]);

            // Enviar notificações
            sendNotifications($paymentIntent, 'payment_intent.payment_failed');

            // Aqui você faria:
            // - Marcar pedido como falhado
            // - Enviar email de falha
            // - Liberar estoque reservado

            break;

        case 'payment_intent.canceled':
            $paymentIntent = $event['data']['object'];

            logEvent('info', 'Payment canceled', [
                'payment_intent_id' => $paymentIntent['id']
            ]);

            // Processar cancelamento
            saveTransactionData([
                'id'       => $paymentIntent['id'],
                'amount'   => $paymentIntent['amount'],
                'currency' => $paymentIntent['currency'],
                'status'   => 'canceled',
                'created'  => $paymentIntent['created'],
                'metadata' => $paymentIntent['metadata']
            ]);

            break;

        case 'payment_intent.requires_action':
            $paymentIntent = $event['data']['object'];

            logEvent('info', 'Payment requires action', [
                'payment_intent_id' => $paymentIntent['id'],
                'next_action' => $paymentIntent['next_action']['type'] ?? 'unknown'
            ]);

            // Este evento é disparado quando o pagamento precisa de ação adicional
            // como autenticação 3D Secure

            break;

        case 'charge.dispute.created':
            $dispute = $event['data']['object'];

            logEvent('warning', 'Chargeback created', [
                'dispute_id' => $dispute['id'],
                'charge_id' => $dispute['charge'],
                'amount' => $dispute['amount'],
                'reason' => $dispute['reason']
            ]);

            // Processar contestação/chargeback
            // - Notificar equipe
            // - Coletar evidências
            // - Preparar resposta

            break;

        default:
            logEvent('info', 'Unhandled event type', ['event_type' => $event['type']]);
            break;
    }

    // Resposta de sucesso
    http_response_code(200);
    echo json_encode(['status' => 'success']);

} catch (\Stripe\Exception\ApiErrorException $e) {
    logEvent('error', 'Stripe API error', [
        'error_type' => get_class($e),
        'error_message' => $e->getMessage(),
        'error_code' => $e->getStripeCode()
    ]);

    http_response_code(400);
    echo json_encode([
        'error' => 'Stripe API error',
        'message' => $e->getMessage()
    ]);

} catch (\Exception $e) {
    logEvent('error', 'Unexpected error', [
        'error_type' => get_class($e),
        'error_message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error'
    ]);
}

