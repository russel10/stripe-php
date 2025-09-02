// Carregar configuração do Stripe de forma segura do backend
let stripe;
let stripeConfig;

// Função para carregar configuração do backend
const loadStripeConfig = async () => {
  try {
    const response = await fetch("config.php");
    const data = await response.json();

    if (!data.success) {
      throw new Error(data.error || "Erro ao carregar configuração");
    }

    stripeConfig = data.data;
    stripe = Stripe(stripeConfig.stripePublishableKey);

    console.log("✅ Configuração do Stripe carregada com sucesso");
    return true;
  } catch (error) {
    console.error("❌ Erro ao carregar configuração do Stripe:", error);
    showToast("Erro ao inicializar sistema de pagamento", "error");
    return false;
  }
};

let elements;
let paymentElement;

// Elementos do DOM
const form = document.getElementById("payment-form");
const submitButton = document.getElementById("submit");
const buttonText = document.getElementById("button-text");
const spinner = document.getElementById("spinner");
const loadingOverlay = document.getElementById("loading-overlay");
const amountInput = document.getElementById("amount-input");
const totalDisplay = document.getElementById("total-amount");
const cardHolderName = document.getElementById("card-holder-name");

// Elementos dos campos Stripe
let cardNumber, cardExpiry, cardCvc;

// Estado da aplicação
let isProcessing = false;
let currentAmount = 10000; // centavos

// Utilitários
const formatCurrency = (amount) => {
  return new Intl.NumberFormat("pt-BR", {
    style: "currency",
    currency: "BRL",
  }).format(amount / 100);
};

const parseCurrency = (value) => {
  const cleanValue = value.replace(/[^\d,]/g, "").replace(",", ".");
  return Math.round(parseFloat(cleanValue || 0) * 100);
};

const debounce = (func, wait) => {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
};

// Sistema de toast
const showToast = (message, type = "info", duration = 5000) => {
  const container = document.getElementById("toast-container");
  const toast = document.createElement("div");
  toast.className = `toast ${type}`;
  toast.textContent = message;

  container.appendChild(toast);

  setTimeout(() => {
    toast.style.animation = "slideOut 0.3s ease forwards";
    setTimeout(() => {
      container.removeChild(toast);
    }, 300);
  }, duration);
};

// Anúncios para leitores de tela
const announceToScreenReader = (message) => {
  const announcer = document.getElementById("sr-announcements");
  announcer.textContent = message;
  setTimeout(() => {
    announcer.textContent = "";
  }, 1000);
};

// Validação de campos
const validateField = (field, value, rules) => {
  const errors = [];

  if (rules.required && (!value || value.trim() === "")) {
    errors.push("Este campo é obrigatório");
  }

  if (rules.minLength && value && value.length < rules.minLength) {
    errors.push(`Mínimo de ${rules.minLength} caracteres`);
  }

  if (rules.maxLength && value && value.length > rules.maxLength) {
    errors.push(`Máximo de ${rules.maxLength} caracteres`);
  }

  if (rules.pattern && value && !rules.pattern.test(value)) {
    errors.push(rules.patternMessage || "Formato inválido");
  }

  return errors;
};

const showFieldError = (fieldId, messages) => {
  const errorElement = document.getElementById(`${fieldId}-error`);
  const inputElement =
    document.getElementById(fieldId) ||
    document.getElementById(`${fieldId}-element`);

  if (messages.length > 0) {
    errorElement.textContent = messages[0];
    errorElement.classList.remove("hidden");
    if (inputElement) {
      inputElement.classList.add("error");
      inputElement.setAttribute("aria-invalid", "true");
    }
  } else {
    errorElement.classList.add("hidden");
    if (inputElement) {
      inputElement.classList.remove("error");
      inputElement.setAttribute("aria-invalid", "false");
    }
  }
};

const clearFieldError = (fieldId) => {
  showFieldError(fieldId, []);
};

// Validações específicas
const validateName = (name) => {
  return validateField("name", name, {
    required: true,
    minLength: 2,
    maxLength: 100,
    pattern: /^[a-zA-ZÀ-ÿ\s]+$/,
    patternMessage: "Use apenas letras e espaços",
  });
};

const validateAmount = (amount) => {
  const numericAmount = parseCurrency(amount);
  const errors = [];

  if (!amount || amount.trim() === "") {
    errors.push("Valor é obrigatório");
  } else if (isNaN(numericAmount) || numericAmount <= 0) {
    errors.push("Valor deve ser um número válido");
  } else if (numericAmount < (stripeConfig?.minAmount || 50)) {
    errors.push(
      `Valor mínimo: R$ ${((stripeConfig?.minAmount || 50) / 100)
        .toFixed(2)
        .replace(".", ",")}`
    );
  } else if (numericAmount > (stripeConfig?.maxAmount || 99999999)) {
    errors.push(
      `Valor máximo: R$ ${(
        (stripeConfig?.maxAmount || 99999999) / 100
      ).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}`
    );
  }

  return errors;
};

// Inicialização dos campos Stripe
const initializeStripeElements = () => {
  const appearance = {
    theme: "stripe",
    variables: {
      colorPrimary: "#0055de",
      colorBackground: "#ffffff",
      colorText: "#32325d",
      colorDanger: "#e74c3c",
      fontFamily:
        '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
      borderRadius: "6px",
    },
    rules: {
      ".Input": {
        fontSize: "16px",
        padding: "12px",
      },
    },
  };

  elements = stripe.elements({ appearance });

  // Card Number
  cardNumber = elements.create("cardNumber", {
    placeholder: "1234 1234 1234 1234",
    showIcon: true,
  });
  cardNumber.mount("#card-number-element");

  // Card Expiry
  cardExpiry = elements.create("cardExpiry", {
    placeholder: "MM/AA",
  });
  cardExpiry.mount("#card-expiry-element");

  // Card CVC
  cardCvc = elements.create("cardCvc", {
    placeholder: "123",
    style: {
      base: {
        fontSize: "16px",
        color: "#32325d",
      },
    },
  });
  cardCvc.mount("#card-cvc-element");

  // Event listeners para validação em tempo real
  setupStripeEventListeners();
};

const setupStripeEventListeners = () => {
  // Card number events
  cardNumber.on("change", (event) => {
    handleStripeFieldChange("card", event);
    updateCardBrandIcon(event.brand);
  });

  cardNumber.on("focus", () => {
    document.getElementById("card-number-element").classList.add("focused");
  });

  cardNumber.on("blur", () => {
    document.getElementById("card-number-element").classList.remove("focused");
  });

  // Card expiry events
  cardExpiry.on("change", (event) => {
    handleStripeFieldChange("expiry", event);
  });

  cardExpiry.on("focus", () => {
    document.getElementById("card-expiry-element").classList.add("focused");
  });

  cardExpiry.on("blur", () => {
    document.getElementById("card-expiry-element").classList.remove("focused");
  });

  // Card CVC events
  cardCvc.on("change", (event) => {
    handleStripeFieldChange("cvc", event);
  });

  cardCvc.on("focus", () => {
    document.getElementById("card-cvc-element").classList.add("focused");
  });

  cardCvc.on("blur", () => {
    document.getElementById("card-cvc-element").classList.remove("focused");
  });
};

const handleStripeFieldChange = (field, event) => {
  const element = document.getElementById(
    `card-${field === "card" ? "number" : field}-element`
  );
  const errorId = field === "card" ? "card" : field;

  if (event.error) {
    showFieldError(errorId, [event.error.message]);
    element.classList.add("error");
  } else {
    clearFieldError(errorId);
    element.classList.remove("error");
    if (event.complete) {
      element.classList.add("success");
    } else {
      element.classList.remove("success");
    }
  }
};

// Formatação do campo de valor
const formatAmountInput = (input) => {
  let value = input.value.replace(/[^\d]/g, "");

  if (value === "") {
    input.value = "";
    return;
  }

  // Converte centavos para reais
  const numericValue = parseInt(value) / 100;

  // Limita o valor máximo
  if (numericValue > 999999.99) {
    value = "99999999";
  }

  // Formata como moeda brasileira
  const formatted = (parseInt(value) / 100).toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  input.value = formatted;
};

const updateTotalAmount = debounce(() => {
  const rawValue = amountInput.value.replace(/[^\d]/g, "");
  const numericValue = parseInt(rawValue || 0);

  currentAmount = numericValue;

  totalDisplay.classList.add("updating");
  setTimeout(() => {
    totalDisplay.textContent = formatCurrency(numericValue);
    totalDisplay.classList.remove("updating");
  }, 150);

  // Validar o valor
  const errors = validateAmount(amountInput.value);
  showFieldError("amount", errors);

  if (errors.length === 0) {
    amountInput.classList.remove("error");
    amountInput.classList.add("success");
  } else {
    amountInput.classList.add("error");
    amountInput.classList.remove("success");
  }
}, 300);

// Event listeners
const setupEventListeners = () => {
  // Formatação do campo de valor
  amountInput.addEventListener("input", (e) => {
    formatAmountInput(e.target);
    updateTotalAmount();
  });

  // Validação do nome do titular
  cardHolderName.addEventListener(
    "input",
    debounce((e) => {
      const errors = validateName(e.target.value);
      showFieldError("name", errors);

      if (errors.length === 0) {
        e.target.classList.remove("error");
        e.target.classList.add("success");
      } else {
        e.target.classList.add("error");
        e.target.classList.remove("success");
      }
    }, 300)
  );

  // Limpar erros quando o usuário começar a digitar
  cardHolderName.addEventListener("focus", () => {
    clearFieldError("name");
  });

  amountInput.addEventListener("focus", () => {
    clearFieldError("amount");
  });

  // Submit do formulário
  form.addEventListener("submit", handleSubmit);

  // Prevenir submit com Enter nos campos
  form.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && e.target.tagName === "INPUT") {
      e.preventDefault();
      if (e.target.id === "card-holder-name") {
        cardNumber.focus();
      }
    }
  });
};

// Validação do formulário
const validateForm = () => {
  let isValid = true;

  // Validar nome
  const nameErrors = validateName(cardHolderName.value);
  if (nameErrors.length > 0) {
    showFieldError("name", nameErrors);
    isValid = false;
  }

  // Validar valor
  const amountErrors = validateAmount(amountInput.value);
  if (amountErrors.length > 0) {
    showFieldError("amount", amountErrors);
    isValid = false;
  }

  return isValid;
};

// Processamento do pagamento
const handleSubmit = async (e) => {
  e.preventDefault();

  if (isProcessing) return;

  // Validar formulário
  if (!validateForm()) {
    showToast("Por favor, corrija os erros do formulário", "error");
    return;
  }

  setLoading(true);
  isProcessing = true;

  try {
    // Criar PaymentIntent
    const response = await createPaymentIntent();
    const { clientSecret } = response.data;

    // Debug logging
    console.log("Client Secret:", clientSecret);
    console.log("Response structure:", response);

    // Confirmar pagamento
    const { error, paymentIntent } = await stripe.confirmCardPayment(
      clientSecret,
      {
        payment_method: {
          card: cardNumber,
          billing_details: {
            name: cardHolderName.value.trim(),
          },
        },
      }
    );

    if (error) {
      handlePaymentError(error);
    } else {
      handlePaymentSuccess(paymentIntent);
    }
  } catch (err) {
    console.error("Erro no pagamento:", err);
    showToast("Erro inesperado. Tente novamente.", "error");
    announceToScreenReader("Erro no processamento do pagamento");
  } finally {
    setLoading(false);
    isProcessing = false;
  }
};

const createPaymentIntent = async () => {
  const response = await fetch("create.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      items: [{ amount: currentAmount }],
      order_id: `order_${Date.now()}_${Math.random()
        .toString(36)
        .substr(2, 9)}`,
    }),
  });

  const data = await response.json();

  // Debug logging
  console.log("PaymentIntent response:", data);

  if (!response.ok) {
    throw new Error(data.error || "Erro ao criar pagamento");
  }

  return data;
};

const handlePaymentError = (error) => {
  let message = "Erro no processamento do pagamento";

  switch (error.type) {
    case "card_error":
    case "validation_error":
      message = error.message;
      break;
    case "invalid_request_error":
      message = "Dados do pagamento inválidos";
      break;
    default:
      message = "Erro inesperado. Tente novamente.";
  }

  showPaymentMessage(message, "error");
  showToast(message, "error");
  announceToScreenReader(`Erro: ${message}`);

  // Focar no campo mais provável de ter erro
  if (error.payment_method?.card?.brand) {
    cardNumber.focus();
  }
};

const handlePaymentSuccess = (paymentIntent) => {
  showPaymentMessage("Pagamento realizado com sucesso!", "success");
  announceToScreenReader("Pagamento aprovado com sucesso");

  // Redirecionar para página de sucesso
  setTimeout(() => {
    showPaymentStatus(paymentIntent, "success");
  }, 1500);
};

const showPaymentMessage = (message, type = "info") => {
  const messageElement = document.getElementById("payment-message");
  messageElement.textContent = message;
  messageElement.className = type;
  messageElement.classList.remove("hidden");
};

const showPaymentStatus = (paymentIntent, status) => {
  // Esconder formulário
  form.classList.add("hidden");

  // Mostrar página de status
  const statusPage = document.getElementById("payment-status");
  const statusIcon = document.getElementById("status-icon");
  const statusText = document.getElementById("status-text");

  // Configurar ícone e texto baseado no status
  if (status === "success") {
    statusIcon.innerHTML = "✅";
    statusIcon.className = "success";
    statusText.textContent = "Pagamento Aprovado!";

    // Preencher detalhes
    document.getElementById("transaction-id").textContent = paymentIntent.id;
    document.getElementById("transaction-amount").textContent = formatCurrency(
      paymentIntent.amount
    );
    document.getElementById("transaction-status").textContent = "Aprovado";
    document.getElementById("transaction-date").textContent =
      new Date().toLocaleString("pt-BR");

    document.getElementById("details-table").classList.remove("hidden");
  } else if (status === "error") {
    statusIcon.innerHTML = "❌";
    statusIcon.className = "error";
    statusText.textContent = "Pagamento Rejeitado";
    document.getElementById("retry-button").classList.remove("hidden");
  }

  statusPage.classList.remove("hidden");
  statusIcon.focus();
};

const setLoading = (loading) => {
  if (loading) {
    submitButton.disabled = true;
    spinner.classList.remove("hidden");
    buttonText.textContent = "Processando...";
    loadingOverlay.classList.remove("hidden");
    form.classList.add("processing");
    loadingOverlay.setAttribute("aria-hidden", "false");
  } else {
    submitButton.disabled = false;
    spinner.classList.add("hidden");
    buttonText.textContent = "Confirmar pagamento";
    loadingOverlay.classList.add("hidden");
    form.classList.remove("processing");
    loadingOverlay.setAttribute("aria-hidden", "true");
  }
};

// Botão de retry
const setupRetryButton = () => {
  const retryButton = document.getElementById("retry-button");
  retryButton?.addEventListener("click", () => {
    // Voltar para o formulário
    document.getElementById("payment-status").classList.add("hidden");
    form.classList.remove("hidden");

    // Limpar mensagens de erro
    document.getElementById("payment-message").classList.add("hidden");

    // Focar no primeiro campo
    cardHolderName.focus();

    showToast("Você pode tentar realizar o pagamento novamente", "info");
  });
};

// Inicialização
document.addEventListener("DOMContentLoaded", async () => {
  // Carregar configuração do Stripe primeiro
  const configLoaded = await loadStripeConfig();

  if (!configLoaded) {
    // Se não conseguir carregar a configuração, desabilitar o formulário
    document.getElementById("payment-form").style.display = "none";
    document.getElementById("error-message").style.display = "block";
    return;
  }

  // Inicializar elementos do Stripe após carregar a configuração
  initializeStripeElements();
  setupEventListeners();
  setupRetryButton();

  // Formatação inicial do valor
  formatAmountInput(amountInput);
  updateTotalAmount();

  // Focar no primeiro campo
  cardHolderName.focus();

  console.log("✅ Sistema de pagamento inicializado com segurança");
});
