<?php
/**
 * Endpoint para criação de PaymentIntents
 * Versão melhorada com validações e tratamento de erros aprimorados
 */

require_once '../vendor/autoload.php';
require_once '../config.php';

use Stripe\Exception\ApiErrorException;

// Headers de segurança e CORS (ajustar conforme necessário)
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Para desenvolvimento local - remover em produção
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

// Configuração do Stripe
$stripe = new \Stripe\StripeClient($stripeSecretKey);

// Função para logging estruturado
function logRequest($level, $message, $context = []) {
    $log = [
        'timestamp' => date('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'request_id' => uniqid('req_', true)
    ];

    error_log(json_encode($log), 3, '../storage/log/stripe_create.log');
}

// Função para calcular valor do pedido
function calculateOrderAmount($items): int {
    $total = 0;

    if (!is_array($items)) {
        return 0;
    }

    foreach ($items as $item) {
        $amount = 0;

        // Suportar tanto object quanto array
        if (is_object($item) && isset($item->amount)) {
            $amount = (int) $item->amount;
        } elseif (is_array($item) && isset($item['amount'])) {
            $amount = (int) $item['amount'];
        }

        // Validar valor individual
        if ($amount > 0 && $amount <= 99999999) { // Máximo ~R$ 999.999,99
            $total += $amount;
        }
    }

    return max(0, $total);
}

// Função para validar dados de entrada
function validateInput($data) {
    $errors = [];

    // Validar items
    if (!isset($data->items) || !is_array($data->items) || empty($data->items)) {
        $errors[] = 'Items são obrigatórios e devem ser um array não vazio';
    }

    // Validar valor total
    $amount = calculateOrderAmount($data->items);
    if ($amount < 50) {
        $errors[] = 'Valor mínimo: R$ 0,50';
    }

    if ($amount > 99999999) {
        $errors[] = 'Valor máximo: R$ 999.999,99';
    }

    // Validar order_id (opcional mas recomendado)
    if (isset($data->order_id)) {
        if (!is_string($data->order_id) || strlen($data->order_id) > 100) {
            $errors[] = 'order_id deve ser uma string com até 100 caracteres';
        }
    }

    // Validar email (opcional)
    if (isset($data->customer_email)) {
        if (!filter_var($data->customer_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email inválido';
        }

        if (strlen($data->customer_email) > 254) {
            $errors[] = 'Email muito longo';
        }
    }

    return $errors;
}

// Função para gerar resposta de erro padronizada
function errorResponse($message, $code = 400, $details = null) {
    http_response_code($code);

    $response = [
        'success' => false,
        'error' => $message,
        'timestamp' => date('c')
    ];

    if ($details !== null) {
        $response['details'] = $details;
    }

    return json_encode($response);
}

// Função para gerar resposta de sucesso
function successResponse($data) {
    return json_encode([
        'success' => true,
        'data' => $data,
        'timestamp' => date('c')
    ]);
}

try {
    // Verificar método HTTP
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logRequest('warning', 'Invalid HTTP method', ['method' => $_SERVER['REQUEST_METHOD']]);
        echo errorResponse('Método não permitido', 405);
        exit;
    }

    // Ler e decodificar JSON
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        logRequest('error', 'Empty request body');
        echo errorResponse('Corpo da requisição vazio', 400);
        exit;
    }

    $jsonData = json_decode($rawInput);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logRequest('error', 'Invalid JSON', [
            'json_error' => json_last_error_msg(),
            'raw_input' => substr($rawInput, 0, 500) // Log apenas primeiros 500 chars
        ]);
        echo errorResponse('JSON inválido: ' . json_last_error_msg(), 400);
        exit;
    }

    // Validar dados de entrada
    $validationErrors = validateInput($jsonData);
    if (!empty($validationErrors)) {
        logRequest('warning', 'Validation failed', ['errors' => $validationErrors]);
        echo errorResponse('Dados inválidos', 400, $validationErrors);
        exit;
    }

    // Calcular valor e preparar dados
    $amount = calculateOrderAmount($jsonData->items);
    $orderId = $jsonData->order_id ?? 'order_' . uniqid() . '_' . time();

    logRequest('info', 'Creating PaymentIntent', [
        'order_id' => $orderId,
        'amount' => $amount,
        'currency' => 'brl'
    ]);

    // Preparar dados do PaymentIntent
    $paymentIntentData = [
        'amount' => $amount,
        'currency' => 'brl',
        'payment_method_types' => ['card'],
        'metadata' => [
            'order_id' => $orderId,
            'created_via' => 'checkout_form',
            'environment' => 'development' // Mudar para 'production' quando necessário
        ]
    ];

    // Adicionar email se fornecido
    if (!empty($jsonData->customer_email)) {
        $paymentIntentData['receipt_email'] = $jsonData->customer_email;
        $paymentIntentData['metadata']['customer_email'] = $jsonData->customer_email;
    }

    // Adicionar informações adicionais se disponíveis
    if (isset($jsonData->customer_name)) {
        $paymentIntentData['metadata']['customer_name'] = $jsonData->customer_name;
    }

    // Chave de idempotência para evitar duplicatas
    $idempotencyKey = $orderId . ':create_pi:' . date('Y-m-d');

    try {
        // Criar PaymentIntent no Stripe
        $paymentIntent = $stripe->paymentIntents->create($paymentIntentData, [
            'idempotency_key' => $idempotencyKey
        ]);

        logRequest('info', 'PaymentIntent created successfully', [
            'payment_intent_id' => $paymentIntent->id,
            'order_id' => $orderId,
            'amount' => $amount
        ]);

        // Resposta de sucesso
        echo successResponse([
            'clientSecret' => $paymentIntent->client_secret,
            'paymentIntentId' => $paymentIntent->id,
            'amount' => $amount,
            'currency' => 'brl'
        ]);

    } catch (ApiErrorException $e) {
        // Erro específico da API Stripe
        logRequest('error', 'Stripe API error', [
            'stripe_error_type' => $e->getError()->type ?? 'unknown',
            'stripe_error_code' => $e->getError()->code ?? 'unknown',
            'stripe_error_message' => $e->getMessage(),
            'order_id' => $orderId,
            'amount' => $amount
        ]);

        // Mapear erros comuns para mensagens amigáveis
        $userMessage = 'Erro ao processar pagamento';
        switch ($e->getError()->type ?? '') {
            case 'card_error':
                $userMessage = 'Erro no cartão: ' . $e->getMessage();
                break;
            case 'invalid_request_error':
                $userMessage = 'Dados do pagamento inválidos';
                break;
            case 'api_error':
                $userMessage = 'Erro temporário. Tente novamente em alguns instantes';
                break;
            case 'authentication_error':
                $userMessage = 'Erro de autenticação com o processador';
                break;
            case 'rate_limit_error':
                $userMessage = 'Muitas tentativas. Aguarde um momento';
                break;
        }

        echo errorResponse($userMessage, 400, [
            'stripe_error_type' => $e->getError()->type ?? null,
            'stripe_error_code' => $e->getError()->code ?? null
        ]);
        exit;
    }

} catch (\Throwable $e) {
    // Erro inesperado
    logRequest('error', 'Unexpected error', [
        'error_type' => get_class($e),
        'error_message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    echo errorResponse('Erro interno do servidor', 500);
}

