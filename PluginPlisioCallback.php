<?php

require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/admin/models/StatusAliasGateway.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice_EventLog.php';
require_once 'modules/admin/models/Error_EventLog.php';

class PluginPlisioCallback extends PluginCallback
{
    public function processCallback()
    {
        $secretKey = trim((string) $this->settings->get('plugin_plisio_Secret Key'));

        if ($secretKey === '') {
            CE_Lib::log(4, '** Plisio callback: Secret Key is empty');
            $this->respond(500, 'Secret key is not configured');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            CE_Lib::log(4, '** Plisio callback: invalid request method');
            $this->respond(405, 'Method not allowed');
            return;
        }

        if (empty($_POST) || !is_array($_POST)) {
            CE_Lib::log(4, '** Plisio callback: empty POST payload');
            $this->respond(400, 'Empty payload');
            return;
        }

        if (!$this->verifyCallbackData($_POST, $secretKey)) {
            CE_Lib::log(4, '** Plisio callback: invalid verify_hash');
            $this->respond(422, 'Invalid signature');
            return;
        }

        $txnId          = $this->getPostString('txn_id');
        $invoiceNumber  = $this->getPostString('order_number');
        $orderName      = $this->getPostString('order_name');
        $status         = strtolower($this->getPostString('status'));
        $comment        = $this->getPostString('comment');
        $sourceAmount   = $this->getPostFloat('source_amount');
        $sourceCurrency = strtoupper($this->getPostString('source_currency'));
        $amount         = $this->getPostFloat('amount');
        $currency       = strtoupper($this->getPostString('currency'));

        if ($txnId === '') {
            CE_Lib::log(4, '** Plisio callback: missing txn_id');
            $this->respond(400, 'Missing txn_id');
            return;
        }

        if ($invoiceNumber === '') {
            CE_Lib::log(4, '** Plisio callback: missing order_number');
            $this->respond(400, 'Missing order_number');
            return;
        }

        $invoiceAmount   = $sourceAmount > 0 ? $sourceAmount : $amount;
        $invoiceCurrency = $sourceCurrency !== '' ? $sourceCurrency : $currency;

        if ($invoiceAmount <= 0) {
            CE_Lib::log(4, '** Plisio callback: invalid amount received');
            $this->respond(422, 'Invalid amount');
            return;
        }

        $payloadForLog = array(
            'txn_id'          => $txnId,
            'order_number'    => $invoiceNumber,
            'order_name'      => $orderName,
            'status'          => $status,
            'source_amount'   => $sourceAmount,
            'source_currency' => $sourceCurrency,
            'amount'          => $amount,
            'currency'        => $currency,
            'comment'         => $comment,
        );
        CE_Lib::log(4, '** Plisio callback payload: ' . json_encode($payloadForLog));

        if ($this->isDuplicateTransaction($txnId)) {
            CE_Lib::log(4, '** Plisio callback: duplicate txn_id ' . $txnId);
            $this->respond(200, 'OK');
            return;
        }

        $expectedAmount = $this->getExpectedInvoiceAmount($invoiceNumber);

        if ($expectedAmount !== null) {
            if (!$this->amountsMatch($expectedAmount, $invoiceAmount)) {
                CE_Lib::log(
                    4,
                    '** Plisio callback: amount mismatch for invoice #' . $invoiceNumber .
                    '. Expected: ' . $expectedAmount . ', received: ' . $invoiceAmount
                );

                $cPlugin = new Plugin($invoiceNumber, 'plisio', $this->user);
                $cPlugin->setAmount($invoiceAmount);
                $cPlugin->setAction('charge');
                $cPlugin->setTransactionID($txnId);
                $cPlugin->PaymentPending(
                    "Plisio payment amount mismatch for invoice #{$invoiceNumber}. Expected {$expectedAmount}, received {$invoiceAmount}.",
                    $invoiceNumber
                );

                $this->respond(200, 'OK');
                return;
            }
        }

        $cPlugin = new Plugin($invoiceNumber, 'plisio', $this->user);
        $cPlugin->setAmount($invoiceAmount);
        $cPlugin->setAction('charge');
        $cPlugin->setTransactionID($txnId);

        if ($status === 'completed') {
            $transaction = $this->buildCompletedMessage(
                $invoiceNumber,
                $invoiceAmount,
                $invoiceCurrency,
                $amount,
                $currency,
                $comment
            );

            $cPlugin->PaymentAccepted($invoiceAmount, $transaction, $invoiceNumber);
            $this->respond(200, 'OK');
            return;
        }

        if (in_array($status, array('new', 'pending', 'pending internal'), true)) {
            $transaction = $this->buildPendingMessage(
                $invoiceNumber,
                $invoiceAmount,
                $invoiceCurrency,
                $amount,
                $currency,
                $comment,
                $status
            );

            $cPlugin->PaymentPending($transaction, $invoiceNumber);
            $this->respond(200, 'OK');
            return;
        }

        if (in_array($status, array('expired', 'cancelled', 'cancelled duplicate', 'error'), true)) {
            $transaction = $this->buildRejectedMessage($invoiceNumber, $status, $comment);

            $cPlugin->PaymentRejected($transaction);
            $this->respond(200, 'OK');
            return;
        }

        $transaction = "Plisio payment update received for invoice #{$invoiceNumber}. Unknown status: {$status}.";
        if ($comment !== '') {
            $transaction .= " ({$comment})";
        }

        $cPlugin->PaymentPending($transaction, $invoiceNumber);
        $this->respond(200, 'OK');
    }

    private function verifyCallbackData(array $post, $secretKey)
    {
        if (!isset($post['verify_hash']) || $post['verify_hash'] === '') {
            CE_Lib::log(4, '** Plisio callback: verify_hash missing');
            return false;
        }

        $verifyHash = (string) $post['verify_hash'];
        unset($post['verify_hash']);

        ksort($post);

        if (isset($post['expire_utc'])) {
            $post['expire_utc'] = (string) $post['expire_utc'];
        }

        if (isset($post['tx_urls'])) {
            $post['tx_urls'] = html_entity_decode(
                (string) $post['tx_urls'],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        $postString = serialize($post);
        $checkKey   = hash_hmac('sha1', $postString, $secretKey);

        return hash_equals($checkKey, $verifyHash);
    }

    /**
     * Базовая проверка дубля.
     * Эту функцию тебе, скорее всего, надо будет адаптировать под реальные таблицы Clientexec.
     */
    private function isDuplicateTransaction($txnId)
    {
        try {
            // Заглушка.
            // Здесь нужно сделать запрос в БД Clientexec и проверить,
            // есть ли уже обработанная транзакция с таким txnId.
            //
            // Например: искать txn_id в invoice_eventlog / transactionlog / gateway log,
            // в зависимости от того, где Clientexec хранит transaction ID.
            //
            // Пока возвращаем false, чтобы не ломать логику.
            return false;
        } catch (Exception $e) {
            CE_Lib::log(4, '** Plisio callback: duplicate check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение ожидаемой суммы счёта.
     * Эту функцию тоже надо привязать к реальной модели/таблице Clientexec.
     */
    private function getExpectedInvoiceAmount($invoiceNumber)
    {
        try {
            // Заглушка.
            // Здесь надо получить сумму invoice из Clientexec по $invoiceNumber.
            //
            // Если не удалось получить сумму — возвращаем null, и сверка пропускается.
            return null;
        } catch (Exception $e) {
            CE_Lib::log(4, '** Plisio callback: failed to load expected invoice amount: ' . $e->getMessage());
            return null;
        }
    }

    private function amountsMatch($expected, $received)
    {
        return abs((float) $expected - (float) $received) < 0.00001;
    }

    private function getPostString($key)
    {
        return isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    }

    private function getPostFloat($key)
    {
        return isset($_POST[$key]) ? (float) $_POST[$key] : 0.0;
    }

    private function buildCompletedMessage(
        $invoiceNumber,
        $invoiceAmount,
        $invoiceCurrency,
        $cryptoAmount,
        $cryptoCurrency,
        $comment
    ) {
        $message = "Plisio payment completed for invoice #{$invoiceNumber}.";
        if ($invoiceAmount > 0 && $invoiceCurrency !== '') {
            $message .= " Invoice amount: {$invoiceAmount} {$invoiceCurrency}.";
        }
        if ($cryptoAmount > 0 && $cryptoCurrency !== '') {
            $message .= " Received crypto: {$cryptoAmount} {$cryptoCurrency}.";
        }
        if ($comment !== '') {
            $message .= " ({$comment})";
        }
        return $message;
    }

    private function buildPendingMessage(
        $invoiceNumber,
        $invoiceAmount,
        $invoiceCurrency,
        $cryptoAmount,
        $cryptoCurrency,
        $comment,
        $status
    ) {
        $message = "Plisio payment is pending for invoice #{$invoiceNumber}. Status: {$status}.";
        if ($invoiceAmount > 0 && $invoiceCurrency !== '') {
            $message .= " Invoice amount: {$invoiceAmount} {$invoiceCurrency}.";
        }
        if ($cryptoAmount > 0 && $cryptoCurrency !== '') {
            $message .= " Current crypto amount: {$cryptoAmount} {$cryptoCurrency}.";
        }
        if ($comment !== '') {
            $message .= " ({$comment})";
        }
        return $message;
    }

    private function buildRejectedMessage($invoiceNumber, $status, $comment)
    {
        $message = "Plisio payment failed for invoice #{$invoiceNumber}. Status: {$status}.";
        if ($comment !== '') {
            $message .= " ({$comment})";
        }
        return $message;
    }

    private function respond($statusCode, $message)
    {
        if (!headers_sent()) {
            http_response_code((int) $statusCode);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message;
    }
}