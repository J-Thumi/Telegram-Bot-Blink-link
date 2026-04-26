
# Telegram Premium Access Bot with Blink Lightning Payments

This Laravel application provides a complete system for selling access to a private Telegram channel. Users pay via M-Pesa (using Bitika as a Lightning -> M-Pesa bridge) and receive a single-use invite link to your channel.

## System Flow

1.  User sends `/buy` to your Telegram bot.
2.  Bot communicates with your Laravel backend.
3.  Laravel creates a Lightning invoice via the Blink API.
4.  Bot sends the invoice to the user with a "Pay via M-Pesa (Bitika)" button.
5.  User pays using M-Pesa on Bitika.xyz.
6.  Bitika settles the Lightning invoice on Blink.
7.  Blink sends a webhook to your Laravel app confirming payment.
8.  Laravel uses the Telegram Bot API to generate a **single-use invite link** for your private channel.
9.  Laravel sends the invite link to the user via the bot.
10. User clicks the link and joins. The link expires after first use.

## Tech Stack

- **Laravel 11.x** – Backend framework.
- **MySQL / PostgreSQL** – Database to track invoices and purchases.
- **Blink API** – Lightning Network invoice generation and webhooks.
- **Telegram Bot API** – Bots, messages, and invite links.
- **Bitika.xyz** – M-Pesa to Lightning proxy for the user.

## Prerequisites

- PHP 8.2+ with Composer.
- A VPS with a public domain name and HTTPS (required for webhooks).
- A Telegram Bot token from [@BotFather](https://t.me/botfather).
- A private Telegram channel where you will add the bot as an administrator.
- A Blink wallet account and API keys (from [Blink](https://blink.sv/)).

## Installation

### 1. Clone the repository and install dependencies

```bash
git clone git@github.com:J-Thumi/Telegram-Bot-Blink-link.git
cd Telegram-Bot-Blink-link
composer install


### 2. Environment Configuration

Copy the example environment file and edit it.

```bash
cp .env.example .env
php artisan key:generate
```

Add the following variables to your `.env` file:

```ini
# Telegram
TELEGRAM_BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz
TELEGRAM_CHANNEL_USERNAME=@my_premium_channel
TELEGRAM_WEBHOOK_SECRET=your_super_secret_webhook_token

# Blink Lightning API
BLINK_API_KEY=blink_live_abcdefghijklmnopqrstuv
BLINK_API_URL=https://api.blink.sv

# App
APP_URL=https://your-vps-domain.com
```

### 3. Database Setup

Create a database and configure your `.env` `DB_*` variables. Then run migrations:

```bash
php artisan migrate
```

### 4. Set Up the Telegram Webhook

Make sure your Laravel app is accessible from the internet (e.g., running on your VPS). Then run:

```bash
php artisan telegram:set-webhook
```

This command tells Telegram to send all updates to `https://your-domain.com/api/telegram/webhook`.

### 5. Add Bot to Your Private Channel

1.  Open your private Telegram channel.
2.  Go to channel info -> Administrators -> Add Administrator.
3.  Search for your bot's username (e.g., `@MyAccessBot`).
4.  Give it at least the **"Invite users"** and **"Manage invite links"** permissions.

### 6. Configure Blink Webhook

In your Blink dashboard (or via their API), set a webhook URL to `https://your-domain.com/api/blink/webhook`. Enable the `invoice.paid` event. You should also generate and verify a secret token for this webhook (see Security section below).

## Security – Important!

- **Telegram Webhook Secret:** The code example uses a `secret_token` when setting the webhook. Telegram will send this token in the `X-Telegram-Bot-Api-Secret-Token` header. **You must verify this header** in your `TelegramWebhookController` to ensure requests are from Telegram. (Add a middleware or check in the `handle()` method).
- **Blink Webhook Signature:** Blink's API may include a signature header (e.g., `X-Signature`). **Implement verification** inside `BlinkWebhookController` to prevent fake payment callbacks.
- **Database Transactions:** Wrap critical paths (creating invoice + purchase) in a database transaction to avoid inconsistencies.

## Usage

1.  Start your Laravel queue worker (if processing any jobs) and ensure your web server is running.
2.  Open Telegram, find your bot, and send `/buy` or `/start`.
3.  The bot will reply with a Lightning invoice and a payment button.
4.  Click the button to pay via M-Pesa on Bitika.xyz.
5.  After successful payment, the bot will instantly send you your single-use invite link.
6.  Click the link to join your private channel.

## Customization

- **Change price:** Edit the `$amountInSatoshis` variable in `TelegramWebhookController@handlePurchaseCommand`.
- **Change invite link expiry:** Modify the `$expireInSeconds` argument in `TelegramService@createSingleUseInviteLink`.
- **Add subscription logic:** Use Laravel's scheduler to revoke links after a month and send renewal invoices.

## Troubleshooting

- **Webhook not working?** Check your `storage/logs/laravel.log`. Also ensure `APP_URL` is correct and your VPS is reachable from the internet.
- **"Bot can't invite users" error:** Make sure the bot is an admin in your private channel with the correct permissions.
- **Invoice not marked as paid?** Check the Blink webhook is reaching your server. Use a tool like ngrok for local testing.

## License

This project is open-source and available under the MIT License.

### Final Steps and Recommendations

1.  **Implement Webhook Security**: The code comments highlight where you need to add signature verification. This is **non-negotiable** for a production app.
2.  **Queues for Reliability**: The `handlePurchaseCommand` and webhook controller can be made more robust by dispatching jobs to handle API calls (e.g., `SendTelegramMessage`, `CreateBlinkInvoice`). This prevents timeouts.
3.  **Testing**: Write unit and feature tests for your services and controllers.
4.  **Deployment**:
    *   Set up a VPS with Nginx and PHP-FPM.
    *   Configure SSL (e.g., using Let's Encrypt).
    *   Set up a queue worker using Supervisor.
    *   Deploy using a tool like Envoyer, Forge, or a simple Git pull script.

You now have a complete, step-by-step blueprint. Build, test, and deploy with confidence. If you encounter any specific issues (like a Blink API response format difference), check their developer docs and adjust the `BlinkService` accordingly. Good luck with your project