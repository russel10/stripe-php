# 💳 Sistema de Pagamentos Stripe - PHP

Um sistema completo de processamento de pagamentos usando Stripe, desenvolvido em PHP com interface moderna e acessível.

## 🚀 Características

- ✅ **Interface moderna e responsiva** com design acessível
- 🔒 **Processamento seguro** de pagamentos via Stripe
- 📱 **Suporte a múltiplos cartões** (Visa, Mastercard, Elo, American Express)
- 🔔 **Sistema de webhooks** para notificações em tempo real
- 📊 **Logging estruturado** para monitoramento
- 🛡️ **Validações robustas** e tratamento de erros
- 🌐 **Interface em português** com UX otimizada

## 📋 Pré-requisitos

- PHP 7.4 ou superior
- Composer
- Conta Stripe (modo teste ou produção)
- Stripe CLI (para webhooks locais)

## ⚙️ Configuração

### 1. Instalar dependências

```bash
composer install
```

### 2. Configurar chaves do Stripe

Edite o arquivo `secrets.php` com suas chaves do Stripe:

```php
<?php
$stripeSecretKey = 'sk_test_sua_chave_secreta_aqui';
$webhookSecret   = 'whsec_seu_webhook_secret_aqui';
```

### 3. Executar o servidor

**Opção 1: Servidor PHP nativo**

```bash
php -S 127.0.0.1:4242 --docroot=public
```

**Opção 2: Docker**

```bash
docker run --rm -d -p 4242:4242 -v $PWD:/app -w /app/public php:latest php -S 0.0.0.0:4242
```

### 4. Acessar a aplicação

Abra seu navegador em: [http://localhost:4242/checkout.html](http://localhost:4242/checkout.html)

## 🔗 Configuração de Webhooks

Para receber notificações em tempo real sobre o status dos pagamentos:

### 1. Instalar Stripe CLI

```bash
# macOS
brew install stripe/stripe-cli/stripe

# Linux/Windows
# Baixe em: https://github.com/stripe/stripe-cli/releases
```

### 2. Fazer login na sua conta Stripe

```bash
stripe login
```

### 3. Encaminhar eventos para o webhook local

```bash
stripe listen --forward-to localhost:4242/webhook.php
```

### 4. Testar eventos

Em outro terminal, simule um pagamento bem-sucedido:

```bash
stripe trigger payment_intent.succeeded
```

## 📁 Estrutura do Projeto

```
├── public/                 # Arquivos públicos
│   ├── checkout.html      # Interface de pagamento
│   ├── checkout.css       # Estilos da interface
│   ├── checkout.js        # Lógica do frontend
│   ├── create.php         # API para criar PaymentIntent
│   └── webhook.php        # Endpoint para webhooks
├── data/                  # Dados da aplicação
│   └── transactions.json  # Histórico de transações
├── storage/               # Logs do sistema
│   └── log/
│       ├── stripe_create.log
│       └── stripe_webhook.log
├── secrets.php           # Configurações sensíveis
└── composer.json         # Dependências PHP
```

## 🎯 Como Usar

### 1. Interface de Pagamento

- Acesse `checkout.html` no seu navegador
- Preencha os dados do cartão (use cartões de teste do Stripe)
- Defina o valor do pagamento
- Clique em "Confirmar pagamento"

### 2. Cartões de Teste

Use estes cartões para testes:

| Cartão       | Número              | CVV | Resultado           |
| ------------ | ------------------- | --- | ------------------- |
| Visa         | 4242 4242 4242 4242 | 123 | Sucesso             |
| Visa (falha) | 4000 0000 0000 0002 | 123 | Falha               |
| Mastercard   | 5555 5555 5555 4444 | 123 | Sucesso             |
| 3D Secure    | 4000 0025 0000 3155 | 123 | Requer autenticação |

### 3. Monitoramento

- **Logs de criação**: `storage/log/stripe_create.log`
- **Logs de webhooks**: `storage/log/stripe_webhook.log`
- **Transações**: `data/transactions.json`

## 🔧 API Endpoints

### POST `/create.php`

Cria um novo PaymentIntent no Stripe.

**Request:**

```json
{
  "items": [{ "amount": 10000 }],
  "order_id": "pedido_123",
  "customer_email": "cliente@exemplo.com"
}
```

**Response:**

```json
{
  "success": true,
  "data": {
    "clientSecret": "pi_xxx_secret_xxx",
    "paymentIntentId": "pi_xxx",
    "amount": 10000,
    "currency": "brl"
  }
}
```

### POST `/webhook.php`

Endpoint para receber webhooks do Stripe.

**Eventos suportados:**

- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `payment_intent.canceled`
- `payment_intent.requires_action`
- `charge.dispute.created`

## 🛡️ Segurança

- ✅ Validação de entrada rigorosa
- ✅ Sanitização de dados
- ✅ Headers de segurança
- ✅ Verificação de assinatura de webhooks
- ✅ Logging de todas as operações
- ✅ Tratamento de erros sem exposição de dados sensíveis

## 📚 Recursos Adicionais

- [Documentação Stripe](https://stripe.com/docs)
- [Cartões de teste](https://stripe.com/docs/testing)
- [Webhooks](https://stripe.com/docs/webhooks)
- [Stripe CLI](https://stripe.com/docs/stripe-cli)

## 📄 Licença

Este projeto é um exemplo educacional. Use conforme necessário.

---

**Stripe e PHP**
