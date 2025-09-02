<?php
/**
 * Configuração de ambiente para o projeto Stripe
 * Carrega variáveis de ambiente de forma segura
 */

// Função para carregar variáveis de ambiente
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
    return true;
}

// Carregar arquivo .env se existir
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    loadEnv($envPath);
} else {
    // Fallback para desenvolvimento - você deve criar o arquivo .env
    error_log('AVISO: Arquivo .env não encontrado. Usando valores padrão.');
}

// Configurações do Stripe
$stripeSecretKey = getenv('STRIPE_SECRET_KEY') ?: 'sk_test_sua_chave_secreta_aqui';
$stripePublishableKey = getenv('STRIPE_PUBLISHABLE_KEY') ?: 'pk_test_sua_chave_publicavel_aqui';
$stripeWebhookSecret = getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_seu_webhook_secret_aqui';

// Configurações da aplicação
$appEnv = getenv('APP_ENV') ?: 'development';
