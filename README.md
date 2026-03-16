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
4. Configure your preferred **Checkout Mode** (Hosted or Embedded)
5. Click **Create Webhook** to set up automatic payment notifications
6. Save your settings

## Webhook Setup

The module can automatically create and manage webhooks. Click **Create Webhook** in the module configuration page. The webhook URL will be:

```
https://yourstore.com/module/paypercut/webhook
```

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
│   ├── PaypercutCustomer.php  # Customer mapping model
│   ├── PaypercutTransaction.php # Transaction model
│   ├── PaypercutRefund.php    # Refund tracking model
│   └── PaypercutWebhookLog.php # Webhook idempotency log
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

## Regenerating Translations

If you modify translatable strings, regenerate the translation files:

```bash
php modules/paypercut/tools/generate_translations.php
```

## Support

For support, visit [https://paypercut.io](https://paypercut.io) or contact support@paypercut.io.

## License

This module is licensed under the MIT License.
