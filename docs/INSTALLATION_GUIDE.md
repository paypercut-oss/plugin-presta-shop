# Paypercut for PrestaShop — Installation & Configuration Guide

This guide covers how to upload, install, and configure the Paypercut Payments module on a PrestaShop 1.7.7+, 8.x, or 9.x store.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Installation Methods](#2-installation-methods)
    - [Method A: Upload via Back Office (Recommended)](#method-a-upload-via-back-office-recommended)
    - [Method B: FTP / SFTP Upload](#method-b-ftp--sftp-upload)
    - [Method C: SSH / Command Line](#method-c-ssh--command-line)
3. [Module Activation](#3-module-activation)
4. [Configuration](#4-configuration)
    - [Step 1: API Key](#step-1-api-key)
    - [Step 2: Payment Settings](#step-2-payment-settings)
    - [Step 3: Webhook Setup](#step-3-webhook-setup)
    - [Step 4: General Settings](#step-4-general-settings)
5. [Apple Pay Domain Verification](#5-apple-pay-domain-verification)
6. [Testing](#6-testing)
7. [Going Live](#7-going-live)
8. [Troubleshooting](#8-troubleshooting)
9. [Uninstallation](#9-uninstallation)

---

## 1. Prerequisites

Before installing, make sure you have:

- **PrestaShop** 1.7.7 or later (including 8.x and 9.x)
- **PHP** 7.1 or later (PHP 7.4+ recommended)
- **PHP extensions**: `curl`, `json`, `openssl`
- **SSL certificate** (HTTPS) — required for payment processing and webhooks
- A **Paypercut account** with API keys from [dashboard.paypercut.io](https://dashboard.paypercut.io)

---

## 2. Installation Methods

### Method A: Upload via Back Office (Recommended)

This is the simplest method and requires no server access.

1. **Create a ZIP archive** of the module folder:
    - The ZIP must contain a single top-level folder named `paypercut/`
    - Structure inside the ZIP:
        ```
        paypercut/
        ├── paypercut.php
        ├── config.xml
        ├── logo.png
        ├── classes/
        ├── controllers/
        ├── ...
        ```

    **On Windows** (PowerShell):

    ```powershell
    cd prestashop
    .\create-zip.ps1
    ```

    **On macOS/Linux**:

    ```bash
    cd prestashop
    zip -r paypercut.zip paypercut/
    ```

2. **Log in** to your PrestaShop Back Office as an administrator.

3. **Navigate** to:
    - **PrestaShop 1.7 / 8.x**: Go to **Modules > Module Manager**
    - **PrestaShop 9.x**: Go to **Modules > Module Manager**

4. **Click "Upload a module"** (top-right button).

5. **Drag and drop** the `paypercut.zip` file, or click to browse and select it.

6. **Wait** for the upload and installation to complete. You should see a success message: _"Module installed!"_

7. **Click "Configure"** to proceed to the configuration page.

---

### Method B: FTP / SFTP Upload

Use this method if the back office upload fails (e.g., PHP upload size limits).

1. **Connect** to your server using an FTP/SFTP client (e.g., FileZilla, WinSCP, Cyberduck).

2. **Navigate** to your PrestaShop installation directory, typically:

    ```
    /var/www/html/         (Linux/Apache)
    /home/username/public_html/   (cPanel hosting)
    ```

3. **Go to the `modules/` directory**:

    ```
    /your-prestashop-root/modules/
    ```

4. **Upload** the entire `paypercut/` folder into `modules/`, so the structure becomes:

    ```
    modules/
    ├── paypercut/
    │   ├── paypercut.php
    │   ├── config.xml
    │   ├── logo.png
    │   ├── classes/
    │   ├── controllers/
    │   └── ...
    ├── ps_emailsubscription/
    ├── ps_facetedsearch/
    └── ...
    ```

5. **Set file permissions** (Linux/macOS):

    ```bash
    chmod -R 755 modules/paypercut/
    chown -R www-data:www-data modules/paypercut/   # adjust for your web server user
    ```

6. **Log in** to the Back Office, go to **Modules > Module Manager**.

7. **Search** for "Paypercut" in the module list.

8. **Click "Install"** next to the Paypercut module.

---

### Method C: SSH / Command Line

For developers with SSH access to the server.

1. **SSH into your server**:

    ```bash
    ssh user@your-server.com
    ```

2. **Navigate** to the PrestaShop root:

    ```bash
    cd /var/www/html/your-prestashop
    ```

3. **Upload and extract** the module:

    ```bash
    # Option 1: Copy ZIP and extract
    unzip paypercut.zip -d modules/

    # Option 2: Git clone (if available)
    cd modules/
    git clone <repo-url> paypercut

    # Option 3: SCP from local machine
    scp -r paypercut/ user@server:/var/www/html/your-prestashop/modules/
    ```

4. **Set permissions**:

    ```bash
    chmod -R 755 modules/paypercut/
    chown -R www-data:www-data modules/paypercut/
    ```

5. **Install via PrestaShop CLI** (PrestaShop 1.7.7+):

    ```bash
    php bin/console prestashop:module install paypercut
    ```

    Or install from the Back Office as described in Method B, steps 6-8.

6. **Clear the cache**:
    ```bash
    php bin/console cache:clear
    ```

---

## 3. Module Activation

After installation, verify the module is active:

1. Go to **Modules > Module Manager**.
2. Search for **"Paypercut"**.
3. The module should show as **Installed** with a green indicator.
4. If it shows "Disabled", click the action menu (⋮) and select **Enable**.
5. Ensure the module appears under the **Payment** category.

> **Note**: The module will not appear as a payment option on the checkout page until you configure a valid API key.

---

## 4. Configuration

Click **Configure** on the Paypercut module (or navigate to **Modules > Module Manager > Paypercut > Configure**).

The configuration page has **4 tabs**:

### Step 1: API Key

1. Log in to your [Paypercut Dashboard](https://dashboard.paypercut.io).
2. Go to **Developers > API Keys**.
3. Copy your **Secret API Key** (starts with `sk_test_` for test mode or `sk_live_` for live mode).
4. Paste it in the **API Key** field.
5. Click **Test Connection** — you should see a green "Connection successful!" message.
6. The mode indicator at the top will show **TEST MODE** or **LIVE MODE** based on your key.
7. Click **Save**.

### Step 2: Payment Settings

1. **Checkout Mode**: Choose how customers pay:
    - **Hosted (Redirect)**: Customers are redirected to Paypercut's secure payment page. Simplest to set up.
    - **Embedded (Inline)**: Payment form appears directly on your checkout page. Better UX but requires HTTPS.

2. **Google Pay**: Enable/disable Google Pay as a wallet option.

3. **Apple Pay**: Enable/disable Apple Pay as a wallet option.

    > Apple Pay requires domain verification — see [Section 5](#5-apple-pay-domain-verification).

4. **Statement Descriptor**: Text shown on the customer's bank statement (max 22 characters). Leave empty to use Paypercut's default.

5. **Success Order Status**: The PrestaShop order status assigned when payment succeeds. Default: _Payment accepted_.

6. **Payment Method Configuration**: Select a specific Paypercut payment profile to control which payment methods are offered. Leave as "Default" for all available methods.

7. Click **Save**.

### Step 3: Webhook Setup

Webhooks are **essential** for reliable payment processing. They notify your store of payment events (success, failure, refunds) even if the customer closes their browser.

1. **Webhook URL**: The URL is auto-generated and displayed (e.g., `https://yourstore.com/module/paypercut/webhook`).

2. **Click "Create Webhook"**: The module will automatically register the webhook with Paypercut's API.
    - On success, you'll see a green "Webhook is active" status with the Webhook ID.
    - The webhook secret is stored securely and used to verify incoming webhook signatures.

3. **Verify**: The status should show **"Webhook is active"**.

> **Important**: If you change your store's domain or move to a new server, delete the old webhook and create a new one.

> **Manual Setup Alternative**: If automatic creation fails, go to [Paypercut Dashboard > Developers > Webhooks](https://dashboard.paypercut.io/developers/webhooks), create a webhook with URL `https://yourstore.com/module/paypercut/webhook`, subscribe to all events (`*`), and paste the webhook secret into the module configuration.

### Step 4: General Settings

1. **Debug Logging**: Enable to log API calls, webhook events, and errors to PrestaShop's log system (**Advanced Parameters > Logs**).

    > **Recommendation**: Enable during initial setup and testing. Disable in production unless actively debugging. Logs may contain sensitive data.

2. Module and PrestaShop version information is displayed for support purposes.

3. Click **Save**.

---

## 5. Apple Pay Domain Verification

If you enable Apple Pay, you need to verify your domain:

1. Log in to the [Paypercut Dashboard](https://dashboard.paypercut.io).
2. Go to **Settings > Payment Methods > Apple Pay**.
3. Add your store domain (e.g., `yourstore.com`).
4. Download the domain verification file provided.
5. Upload it to your store at: `https://yourstore.com/.well-known/apple-developer-merchantid-domain-association`
6. Click **Verify** in the dashboard.

The module will also attempt to register your domain automatically when you save the configuration with Apple Pay enabled.

---

## 6. Testing

Before going live, test the integration using **test mode**:

1. Use a **test API key** (starts with `sk_test_`).
2. Place a test order on your store.
3. On the checkout page, select **"Pay with Paypercut"** (or "Paypercut Payments").
4. Use Paypercut's [test card numbers](https://docs.paypercut.io/testing) to simulate payments:
    - **Successful payment**: `4242 4242 4242 4242`
    - **Declined payment**: `4000 0000 0000 0002`
    - **3D Secure required**: `4000 0000 0000 3220`
5. After payment, verify:
    - [ ] Order is created in **Orders > Orders** with correct status
    - [ ] Transaction details appear in the order's Paypercut panel
    - [ ] Webhook events are received (check **Advanced Parameters > Logs** if logging is enabled)
    - [ ] Order confirmation page shows payment success message
    - [ ] Refund form appears in the admin order view

6. **Test a refund**:
    - Open a paid test order
    - In the Paypercut panel at the bottom, enter a refund amount
    - Click **Refund** and verify the refund appears in the refund history

7. **Test webhook reliability**:
    - Place an order and verify it appears even if you close the browser during redirect

---

## 7. Going Live

When testing is complete:

1. Go to the module configuration page.
2. Replace the test API key with your **live API key** (starts with `sk_live_`).
3. Click **Test Connection** to verify.
4. **Delete the test webhook** and **create a new webhook** (the webhook secret changes between environments).
5. Verify the mode indicator shows **LIVE MODE**.
6. **Disable debug logging** for production.
7. Click **Save**.
8. Place a small real order to confirm everything works.

> **Important**: Test and live API keys use different webhook secrets. Always recreate the webhook when switching environments.

---

## 8. Troubleshooting

### Module doesn't appear on checkout page

- Verify the module is **enabled**: Modules > Module Manager > Paypercut.
- Check that the **API Key** is configured and valid.
- Confirm your store's **default currency** is supported (BGN, DKK, SEK, NOK, GBP, EUR, USD, CHF, CZK, HUF, PLN, RON).
- Check that the module is enabled for the correct **customer groups**: Modules > Module Manager > Paypercut > (⋮) action menu > Configure > check group restrictions.
- Clear the PrestaShop cache: **Advanced Parameters > Performance > Clear cache**.

### "Connection failed" on Test Connection

- Verify the API key is correct and complete (no trailing spaces).
- Check that your server can make outbound HTTPS requests to `api.paypercut.io`.
- Ensure the PHP `curl` extension is enabled.
- Check your server's firewall or proxy settings.

### Webhook not receiving events

- Ensure your store is accessible via HTTPS from the internet (not localhost).
- Verify the webhook URL is correct in the Paypercut Dashboard.
- Check the webhook status shows "active" in both the module and the dashboard.
- Look at **Advanced Parameters > Logs** for error details (enable logging first).
- Some hosting providers block POST requests to unknown URLs — check with your host.

### Orders not created after payment

- This is usually a webhook issue. Follow the webhook troubleshooting steps above.
- Check PrestaShop error logs: `var/logs/` or **Advanced Parameters > Logs**.
- Verify the `validation.php` controller is accessible: visit `https://yourstore.com/module/paypercut/validation` — it should redirect to the homepage (not show a 404).

### Refund fails

- Verify the payment status is `succeeded` — only succeeded payments can be refunded.
- Check that the refund amount doesn't exceed the remaining payment amount.
- Ensure the API key is still valid and has refund permissions.

### Blank page or 500 error

- Check PHP error logs on your server.
- Verify PHP version is 7.1+ and required extensions (`curl`, `json`, `openssl`) are installed.
- Try clearing the cache: `php bin/console cache:clear` or via Back Office.
- If using OpCache, restart your PHP-FPM service.

### Multi-shop issues

- The module stores configuration per shop. Configure each shop separately.
- Customer mappings are shop-specific to avoid conflicts.

---

## 9. Uninstallation

To remove the module:

1. Go to **Modules > Module Manager**.
2. Search for **"Paypercut"**.
3. Click the action menu (⋮) and select **Uninstall**.
4. Confirm the uninstallation.

This will:

- Remove all Paypercut database tables (`paypercut_customer`, `paypercut_transaction`, `paypercut_refund`, `paypercut_webhook_log`)
- Delete all module configuration values
- Remove the admin controller tab

> **Note**: Uninstalling does **not** delete the webhook from Paypercut's side. Go to your [Paypercut Dashboard](https://dashboard.paypercut.io/developers/webhooks) and manually delete the webhook to stop receiving events.

To completely remove the module files after uninstallation:

```bash
rm -rf modules/paypercut/
```

---

## Support

- **Documentation**: [docs.paypercut.io](https://docs.paypercut.io)
- **Dashboard**: [dashboard.paypercut.io](https://dashboard.paypercut.io)
- **Email**: support@paypercut.io
