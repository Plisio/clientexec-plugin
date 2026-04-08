# Plisio Payment Gateway for Clientexec

This plugin adds cryptocurrency payments via [Plisio](https://plisio.net) to Clientexec.

## Features

- Creates a Plisio invoice when a Clientexec invoice is paid.
- Redirects the customer to the Plisio checkout page.
- Handles Plisio callbacks and updates payment status in Clientexec.
- Supports one-time payments only (no recurring charges, no refund API support).

## Requirements

- A working Clientexec installation.
- A Plisio account and API Secret Key: `https://www.plisio.net`.
- Outbound access from your Clientexec server to `https://api.plisio.net`.
- PHP with `curl` support (`file_get_contents` is used as fallback if `curl` is unavailable).

## Installation

1. Copy the plugin folder to:
   `plugins/gateways/plisio`
2. Make sure the folder contains:
   - `PluginPlisio.php`
   - `PluginPlisioCallback.php`
   - `callback.php`
   - `resource/plugin.ini`
3. Enable the **Plisio** payment method in Clientexec admin.

## Configuration in Clientexec

After enabling the plugin, configure:

- **Secret Key**: your API key from the Plisio dashboard.
- **Signup Name**: the payment method label shown during checkout.

## Callback URL

The plugin automatically sends this callback URL to Plisio:

`https://<your-domain>/plugins/gateways/plisio/callback.php`

Make sure this URL is publicly accessible over HTTPS.

## Status Mapping

Based on callback `status`:

- `completed` -> payment is accepted (`PaymentAccepted`)
- `new`, `pending`, `pending internal` -> payment is pending (`PaymentPending`)
- `expired`, `cancelled`, `cancelled duplicate`, `error` -> payment is rejected (`PaymentRejected`)
- any unknown status -> `PaymentPending`

## Testing

1. Create a test invoice in Clientexec.
2. Select Plisio as the payment method.
3. Verify that the customer is redirected to the Plisio `invoice_url`.
4. After callback delivery, verify invoice status updates in Clientexec.
5. Check Clientexec logs (`CE_Lib::log` entries with the `Plisio` prefix are used in code).
