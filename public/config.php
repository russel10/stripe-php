<?php
/**
 * Endpoint para fornecer configurações do frontend
 * Retorna apenas a chave publicável do Stripe (nunca a secreta)
 */

require_once '../config.php';

// Headers de segurança
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Para desenvolvimento local - remover em produção
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

// Verificar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Retornar apenas configurações seguras para o frontend
    $config = [
        'success' => true,
        'data' => [
            'stripePublishableKey' => $stripePublishableKey,
            'environment' => $appEnv,
            'currency' => 'brl',
            'minAmount' => 50, // R$ 0,50 em centavos
            'maxAmount' => 99999999 // R$ 999.999,99 em centavos
        ],
        'timestamp' => date('c')
    ];

    echo json_encode($config);

} catch (\Throwable $e) {
    error_log('Erro ao fornecer configuração: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor'
    ]);
}
