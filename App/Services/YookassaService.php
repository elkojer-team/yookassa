<?php

namespace Modules\Yookassa\App\Services;

use App\Models\Transaction;
use App\Services\AbstractPaymentGateway;
use YooKassa\Client;
use YooKassa\Model\Payment\PaymentStatus;
use YooKassa\Request\Payments\CreatePaymentRequest;
use YooKassa\Request\Refunds\CreateRefundRequest;

class YookassaService extends AbstractPaymentGateway
{
    protected Client $client;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->client = new Client();
        $this->client->setAuth(
            $this->getConfig('shop_id'),
            $this->getConfig('secret_token')
        );
    }

    /**
     * Инициировать платеж
     */
    public function purchase(float $amount, string $currency, array $options = []): mixed
    {
        try {
            $idempotenceKey = uniqid('', true); // Уникальный ключ для идемпотентности

            $paymentRequest = CreatePaymentRequest::builder()
                ->setAmount($amount)
                ->setCurrency($currency)
                ->setCapture(true) // Автоматический захват платежа
                ->setConfirmation([
                    'type' => 'redirect',
                    'return_url' => $options['return_url'] ?? route('payment.callback'),
                ])
                ->setDescription($options['description'] ?? 'Payment via Yookassa')
                ->setMetadata(['order_id' => $options['order_id'] ?? null])
                ->build();

            $response = $this->client->createPayment($paymentRequest, $idempotenceKey);

            // Сохранение транзакции как deposit
            $transaction = Transaction::create([
                'user_id' => $options['user_id'] ?? null,
                'ad_id' => $options['ad_id'] ?? null,
                'amount' => $amount,
                'type' => 'deposit',           // Это платеж
                'payment_method' => 'balance',  // Тип операции — пополнение
                'description' => $options['description'] ?? 'Deposit via Yookassa',
                'gateway_name' => $this->getGatewayName(),
                'gateway_transaction_id' => $response->getId(),
                'payment_url' => $response->getConfirmation()->getConfirmationUrl(),
                'status' => $response->getStatus(),
                'currency' => $currency,
                'metadata' => $options,
            ]);

            $this->log('Payment initiated', [
                'amount' => $amount,
                'currency' => $currency,
                'payment_id' => $response->getId()
            ]);

            return $response; // Возвращаем объект PaymentInterface
        } catch (\Exception $e) {
            $this->log('Payment initiation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Подтвердить платеж
     */
    public function complete(string $transactionId, array $data = []): bool
    {
        try {
            $payment = $this->client->getPaymentInfo($transactionId);

            $transaction = Transaction::where('gateway_transaction_id', $transactionId)->first();
            if ($transaction) {
                $transaction->update([
                    'status' => $payment->getStatus(),
                ]);
            }

            if ($payment->getStatus() === PaymentStatus::SUCCEEDED) {
                $this->log('Deposit completed', ['payment_id' => $transactionId]);
                return true;
            }

            $this->log('Deposit not completed', ['payment_id' => $transactionId, 'status' => $payment->getStatus()]);
            return false;
        } catch (\Exception $e) {
            $this->log('Deposit completion failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Получить статус платежа
     */
    public function getStatus(string $transactionId): string
    {
        try {
            $payment = $this->client->getPaymentInfo($transactionId);
            $status = $payment->getStatus();

            $transaction = Transaction::where('gateway_transaction_id', $transactionId)->first();
            if ($transaction) {
                $transaction->update(['status' => $status]);
            }

            $this->log('Payment status checked', ['payment_id' => $transactionId, 'status' => $status]);
            return $status;
        } catch (\Exception $e) {
            $this->log('Status check failed', ['error' => $e->getMessage()]);
            return 'unknown';
        }
    }

    /**
     * Возврат средств
     */
    public function refund(string $transactionId, float $amount): bool
    {
        try {
            $idempotenceKey = uniqid('', true);

            $refundRequest = CreateRefundRequest::builder()
                ->setPaymentId($transactionId)
                ->setAmount($amount)
                ->setCurrency('RUB') // Валюта должна соответствовать оригинальному платежу
                ->setDescription('Refund for payment ' . $transactionId)
                ->build();

            $response = $this->client->createRefund($refundRequest, $idempotenceKey);

            $this->log('Refund initiated', [
                'payment_id' => $transactionId,
                'amount' => $amount,
                'refund_id' => $response->getId()
            ]);

            return $response->getStatus() === 'succeeded';
        } catch (\Exception $e) {
            $this->log('Refund failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Получить URL для перенаправления на платежную страницу
     */
    public function getPaymentUrl(): string
    {
        // Этот метод предполагает, что purchase() уже вызван и возвращает PaymentInterface
        // Здесь мы возвращаем URL из последнего созданного платежа (если он есть)
        try {
            $payment = $this->purchase(0, 'RUB', ['dry_run' => true]); // Тестовый вызов для получения URL
            return $payment->getConfirmation()->getConfirmationUrl();
        } catch (\Exception $e) {
            $this->log('Failed to get payment URL', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Валидация вебхука
     */
    public function validateWebhook(array $requestData): bool
    {
        try {
            if (empty($requestData['event']) || empty($requestData['object']['id'])) {
                $this->log('Invalid webhook data', ['data' => $requestData]);
                return false;
            }

            $paymentId = $requestData['object']['id'];
            $event = $requestData['event'];

            $transaction = Transaction::where('gateway_transaction_id', $paymentId)->first();
            if ($transaction && $event === 'payment.succeeded') {
                $transaction->update(['status' => PaymentStatus::SUCCEEDED]);
            }

            $this->log('Webhook received', ['event' => $event, 'payment_id' => $paymentId]);
            return true;
        } catch (\Exception $e) {
            $this->log('Webhook validation failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Получить имя платежной системы
     */
    protected function getGatewayName(): string
    {
        return 'yookassa';
    }
}
