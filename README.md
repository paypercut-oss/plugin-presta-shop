# Paypercut Payments for PrestaShop

Accept payments via Paypercut in your PrestaShop store. Supports credit/debit cards, Google Pay, Apple Pay and more.

## Compatibility

- **PrestaShop**: 1.7.7+ / 8.x / 9.x
- **PHP**: 7.1+

## Features

- **Hosted checkout**: Redirect customers to Paypercut's secure payment page
- **Embedded checkout**: Inline payment form on your checkout page
- **Google Pay & Apple Pay**: Digital wallet support
- **Webhooks**: Automatic payment status synchronization
- **Refunds**: Process full and partial refunds from the PrestaShop admin
- **Multi-language**: 13 languages included (BG, CS, DA, DE, EN, ES, FR, HU, IT, NB, PL, RO, SV)
- **Multi-currency**: BGN, DKK, SEK, NOK, GBP, EUR, USD, CHF, CZK, HUF, PLN, RON
- **Debug sessions**: a merchant-started, self-expiring diagnostic feed for Paypercut support ([details](docs/telemetry.md))

## Installation

### Manual Installation

1. Download the module
2. Upload the `paypercut` folder to `/modules/` in your PrestaShop installation
3. Go to **Modules > Module Manager** in the back office
4. Search for "Paypercut" and click **Install**

### Configuration

1. Navigate to **Modules > Module Manager > Paypercut > Configure**
2. Enter your **API Key** from the [Paypercut Dashboard](https://dashboard.paypercut.io)
3. Click **Test Connection** to verify
4. Leave **Environment** on `production` unless Paypercut support asked you to change it — it selects both the payment API host and the telemetry host
5. Configure your preferred **Checkout Mode** (Hosted or Embedded)
6. Click **Create Webhook** to set up automatic payment notifications
7. Save your settings

## Webhook Setup

The module can automatically create and manage webhooks. Click **Create Webhook** in the module configuration page. The webhook URL will be:

```
https://yourstore.com/module/paypercut/webhook
```

## Debug Sessions

When Paypercut support needs to see what your store is doing, open
**Configure > Debug Session** and press **Start debug session**. The module then
sends diagnostic events to Paypercut for about an hour and stops by itself; you
can stop it sooner at any time. Nothing is sent when no session is running.

The panel shows the session ID to quote in a support ticket, and — while the
session runs — exactly which events left your store.

### External services

A debug session sends diagnostic data to Paypercut's telemetry service
(`https://telemetry.paypercut.io`), and obtains a short-lived diagnostic token
from `https://api.paypercut.io`. Both are operated by Paypercut. Nothing is sent
outside a running session. A store connected to a Paypercut test environment
contacts that environment's hosts instead; the panel names the two hosts your
store will actually contact before you start.

**What is shared:** Module, PrestaShop, PHP and theme versions; the modules
active on this store and their versions; how this store has the Paypercut module
configured (which checkout mode is selected and which options are switched on —
never the values of your credentials); a record of each checkout, refund and
payment notification the module handled and whether it succeeded, identified by
PrestaShop order reference and Paypercut payment reference; when something
fails, the error message, the file and line it came from, and which module or
theme raised it; and when the session started and stopped.

**Not shared:** customer names, email addresses, billing or shipping addresses,
order totals, line items, payment card data, the reason text you type when
issuing a refund, or any API key, webhook secret or password.

Your API key is never sent to the telemetry service. It is used once, over
HTTPS, to obtain a short-lived diagnostic token from api.paypercut.io.

Paypercut keeps this diagnostic data for 30 days.

## Supported Payment Statuses

| Paypercut Status     | PrestaShop Status  |
| -------------------- | ------------------ |
| `succeeded`          | Payment accepted   |
| `pending`            | Awaiting payment   |
| `failed`             | Payment error      |
| `canceled`           | Canceled           |
| `refunded`           | Refunded           |
| `partially_refunded` | Partially refunded |

## File Structure

```
paypercut/
├── paypercut.php              # Main module class
├── config.xml                 # Module metadata
├── logo.png                   # Module logo
├── LICENSE                    # License file
├── classes/
│   ├── PaypercutApi.php       # API client
│   ├── PaypercutApiException.php # Structured API error
│   ├── PaypercutEnvironment.php  # dev/stage/production host resolution
│   ├── PaypercutCustomer.php  # Customer mapping model
│   ├── PaypercutTransaction.php # Transaction model
│   ├── PaypercutRefund.php    # Refund tracking model
│   ├── PaypercutWebhookLog.php # Webhook idempotency log
│   └── telemetry/             # Debug sessions (see docs/telemetry.md)
├── tests/                     # Dependency-free suite: php tests/run.php
├── upgrade/                   # PrestaShop upgrade scripts
├── controllers/
│   ├── admin/
│   │   └── AdminPaypercutController.php  # Admin configuration
│   └── front/
│       ├── redirect.php       # Hosted checkout redirect
│       ├── validation.php     # Return/confirmation handler
│       └── webhook.php        # Webhook receiver
├── sql/
│   ├── install.php            # Database table creation
│   └── uninstall.php          # Database table removal
├── translations/              # 13 language files
├── tools/
│   └── generate_translations.php  # Translation regenerator
└── views/
    ├── css/
    │   ├── paypercut.css      # Front-office styles
    │   └── paypercut-admin.css # Admin styles
    ├── js/
    │   └── paypercut-admin.js  # Admin JavaScript
    ├── img/
    │   └── paypercut.png      # Checkout icon
    └── templates/
        ├── admin/
        │   └── configure.tpl  # Admin config page
        ├── front/
        │   ├── payment_option.tpl      # Payment method display
        │   └── payment_option_form.tpl # Embedded checkout form
        └── hook/
            ├── displayPaymentReturn.tpl      # Order confirmation
            ├── displayOrderDetail.tpl        # Customer order detail
            └── displayAdminOrderMainBottom.tpl # Admin order panel
```

## Tests

The module ships a dependency-free suite — no Composer, no vendored framework,
so the folder stays installable by copying it:

```bash
php tests/run.php
```

It lints every PHP file and pins the pieces that must not drift: the
environment host pairing, the telemetry deny assertion, the merchant-facing
disclosure copy, and the event catalogue in `docs/telemetry.md`. CI
(`.github/workflows/tests.yml`) runs it on each push and pull request.

## Regenerating Translations

If you modify translatable strings, regenerate the translation files:

```bash
php modules/paypercut/tools/generate_translations.php
```

## Documentation

- [`docs/telemetry.md`](docs/telemetry.md) — debug sessions: the event catalogue, the storage contract, and what never leaves the store
- [`docs/INSTALLATION_GUIDE.md`](docs/INSTALLATION_GUIDE.md) — step-by-step install

## Support

For support, visit [https://paypercut.io](https://paypercut.io) or contact support@paypercut.io.

## License

This module is licensed under the MIT License.
