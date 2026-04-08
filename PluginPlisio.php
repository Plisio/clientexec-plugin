<?php

require_once 'modules/billing/models/class.gateway.plugin.php';

class PluginPlisio extends GatewayPlugin
{
    public function getVariables()
    {
        return array(
            lang("Plugin Name") => array(
                "type"        => "hidden",
                "description" => lang("How CE sees this plugin (not to be confused with the Signup Name)"),
                "value"       => lang("Plisio")
            ),
            lang("Secret Key") => array(
                "type"        => "text",
                "description" => lang("Enter your Secret Key from your <a target='_blank' href='https://www.plisio.net/account/api'>plisio.net</a> account"),
                "value"       => ""
            ),
            lang("Signup Name") => array(
                "type"        => "text",
                "description" => lang("Select the name to display in the signup process for this payment type."),
                "value"       => "Plisio"
            )
        );
    }

    public function credit($params)
    {
        return $this->user->lang("This payment gateway does not support refunds.");
    }

    public function singlepayment($params, $test = false)
    {
        $secretKey = trim((string) $this->settings->get('plugin_plisio_Secret Key'));

        if ($secretKey === '') {
            CE_Lib::log(4, '** Plisio: Secret Key is not configured');
            echo $this->user->lang('Plisio gateway is not configured.');
            exit;
        }

        if ((int) $params['isSignup'] === 1) {
            if ($this->settings->get('Signup Completion URL') != '') {
                $returnURL = $this->settings->get('Signup Completion URL') . '?success=1';
                $returnURLCancel = $this->settings->get('Signup Completion URL');
            } else {
                $returnURL = $params["clientExecURL"] . "/order.php?step=complete&pass=1";
                $returnURLCancel = $params["clientExecURL"] . "/order.php?step=3";
            }
        } else {
            $returnURL = $params["invoiceviewURLSuccess"];
            $returnURLCancel = $params["invoiceviewURLCancel"];
        }

        $callbackUrl = rtrim($params["clientExecURL"], '/') . '/plugins/gateways/plisio/callback.php';

        $query = array(
            'source_currency'      => $params['userCurrency'],
            'source_amount'        => $params['invoiceTotal'],
            'order_number'         => $params['invoiceNumber'],
            'order_name'           => $params['invoiceDescription'],
            'description'          => $params['invoiceDescription'],
            'email'                => $params['userEmail'],
            'callback_url'         => $callbackUrl,
            'success_callback_url' => $returnURL,
            'fail_callback_url'    => $returnURLCancel,
            'success_invoice_url'  => $returnURL,
            'fail_invoice_url'     => $returnURLCancel,
            'plugin'               => 'clientexec',
            'version'              => '1.0.0',
            'return_existing'      => 'true',
            'api_key'              => $secretKey
        );

        $invoiceData = $this->createInvoice($query);

        if (!is_array($invoiceData)) {
            CE_Lib::log(4, '** Plisio: invalid API response format');
            echo $this->user->lang('Payment gateway error. Invalid Plisio response.');
            exit;
        }

        if (!isset($invoiceData['status']) || $invoiceData['status'] !== 'success') {
            CE_Lib::log(4, '** Plisio: invoice creation failed: ' . json_encode($invoiceData));
            echo $this->user->lang('Payment gateway error. Unable to create invoice.');
            exit;
        }

        if (
            !isset($invoiceData['data']) ||
            !is_array($invoiceData['data']) ||
            !isset($invoiceData['data']['invoice_url']) ||
            trim((string) $invoiceData['data']['invoice_url']) === ''
        ) {
            CE_Lib::log(4, '** Plisio: invoice_url missing in API response: ' . json_encode($invoiceData));
            echo $this->user->lang('Payment gateway error. Invoice URL is missing.');
            exit;
        }

        $invoiceUrl = trim((string) $invoiceData['data']['invoice_url']);

        CE_Lib::log(
            4,
            '** Plisio: invoice created for CE invoice #' . $params['invoiceNumber'] .
            ', redirecting customer to ' . $invoiceUrl
        );

        header('Location: ' . $invoiceUrl);
        exit;
    }

    private function createInvoice(array $query)
    {
        $endpoint = 'https://api.plisio.net/api/v1/invoices/new?' . http_build_query($query);

        CE_Lib::log(4, '** Plisio: create invoice request for order #' . $query['order_number']);

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response  = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                CE_Lib::log(4, '** Plisio: cURL error: ' . $curlError);
                return false;
            }

            if ($httpCode !== 200) {
                CE_Lib::log(4, '** Plisio: unexpected HTTP code ' . $httpCode . '; response: ' . $response);
                return false;
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                CE_Lib::log(4, '** Plisio: failed to decode JSON response: ' . $response);
                return false;
            }

            return $decoded;
        }

        $response = @file_get_contents($endpoint);
        if ($response === false) {
            CE_Lib::log(4, '** Plisio: file_get_contents request failed');
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            CE_Lib::log(4, '** Plisio: failed to decode JSON response: ' . $response);
            return false;
        }

        return $decoded;
    }
}