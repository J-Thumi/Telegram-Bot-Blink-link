Here is a detailed `README.md` file designed specifically for your project repository at [J-Thumi/Telegram-Bot-Blink-link](https://github.com/J-Thumi/Telegram-Bot-Blink-link).

---

# Telegram Bot & Automated Content Delivery System

An automated e-commerce and content delivery pipeline built with **Laravel**, **Filament Admin**, and **Telegram Bot API**, integrated with **Bitika** (M-Pesa to Bitcoin Lightning Network gateway).

This application manages automated subscriptions, processes Safaricom M-Pesa STK Push payments, generates dynamic single-use invite links for VIP Telegram channels, and automates scheduled content delivery based on member thresholds and due dates.

---

## Key Features

* **Bitika M-Pesa Integration:** Collect payments via Safaricom M-Pesa STK Push with Lightning Network settlement via Bitika API.


* **Dual Purchase Options:**
* **Instant Access:** Direct delivery of upcoming exam/subject materials straight to the user's Telegram DM upon payment confirmation.


* **VIP Channel Access:** Automated creation and delivery of single-use, non-shareable Telegram channel invite links upon payment verification.




* **Automated Scheduled Dispatch:** Console commands (`subjects:check-due-images`) automatically evaluate member count thresholds in the VIP channel and dispatch subject materials due within 24 hours.


* **Group & Admin Management:** Bot owner administrative commands (`/resetGroup`) to remove non-admin members and purge recent group chat history in bulk using Telegram Bot API 7.0+.


* **Filament Admin Panel:** Comprehensive dashboard to manage subjects, upload media/images, track purchases, monitor payment statuses, and trigger manual content dispatches.


* **Idempotency & Concurrency:** Database row locking (`lockForUpdate`) and UUID idempotency keys ensure no duplicate STK pushes or double-fulfillments occur.



---

## Architecture & Workflow

```
       +------------------+             +----------------------+
       |   Telegram User  |             | Safaricom / Bitika   |
       +--------+---------+             +----------+-----------+
                |                                  |
   1. /start    |                                  |
   2. Selects   |                                  | 4. STK Push & Webhook
      Option    v                                  v
+---------------+----------------------------------+---------------+
|                 Laravel Application (Webhook Engine)             |
|                                                                  |
|  +-------------------+   Atomic Lock   +----------------------+  |
|  | Telegram Controller| --------------> | Bitika Controller    |  |
|  +---------+---------+                 +----------+-----------+  |
|            |                                      |              |
|            v                                      v              |
|  +-------------------+                 +----------------------+  |
|  |  Purchase Model   |                 | PurchaseFulfillment  |  |
|  +-------------------+                 +----------+-----------+  |
+---------------------------------------------------|--------------+
                                                    |
                                 5. Send Invite Link| or Direct Content
                                                    v
                                       +------------+------------+
                                       | Telegram Channel/User   |
                                       +-------------------------+

```

---

## Tech Stack

* **Framework:** Laravel 11.x


* **Admin Panel:** Filament PHP v3


* **Payment Gateway:** Bitika API (M-Pesa STK Push / Lightning)


* **Messaging Platform:** Telegram Bot API (via Laravel HTTP Client)


* **Database:** MySQL / PostgreSQL



---

## Getting Started

### Prerequisites

* PHP >= 8.2
* Composer
* MySQL / MariaDB
* Configured Telegram Bot (via [@BotFather](https://t.me/BotFather))
* Bitika API Credentials

### Installation

1. **Clone the Repository:**
```bash
git clone https://github.com/J-Thumi/Telegram-Bot-Blink-link.git
cd Telegram-Bot-Blink-link

```


2. **Install Dependencies:**
```bash
composer install

```


3. **Configure Environment Variables:**
Copy `.env.example` to `.env` and configure your credentials:
```bash
cp .env.example .env
php artisan key:generate

```


Add your Telegram and Bitika configurations to `.env`:
```ini
# Telegram Configuration
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_CHANNEL_ID=-100xxxxxxxxxx
TELEGRAM_CHANNEL_USERNAME=@your_channel_username
TELEGRAM_WEBHOOK_SECRET=your_custom_secret_string
TELEGRAM_OWNER_ID=123456789
TELEGRAM_BOT_USER_ID=987654321

# Bitika Payment Gateway Configuration
BITIKA_BASE_URL=https://bitikaserver.up.railway.app
BITIKA_API_KEY=your_bitika_api_key
BITIKA_LIGHTNING_ADDRESS=your_address@ln.tips
BITIKA_INSTANT_BUY_AMOUNT=7500
BITIKA_INVOICE_AMOUNT=100

```


4. **Run Migrations & Seeders:**
```bash
php artisan migrate --seed

```


5. **Create Filament Admin User:**
```bash
php artisan make:filament-user

```


6. **Set Telegram Webhook:**
Run the custom Artisan command to set up your webhook URL with Telegram:
```bash
php artisan telegram:manage-bot

```



---

## Telegram Bot Commands

| Command | Permission | Description |
| --- | --- | --- |
| `/start` or `/buy` | All Users | Opens interactive purchasing menu for Instant Access or VIP Access.

 |
| `/cancelInvoices` | All Users | Cancels pending/unpaid invoice requests for the user.

 |
| `/resetGroup` | Bot Owner Only | Removes all non-admin members from the target channel/group and purges recent messages in bulk.

 |

---

## Artisan Commands

| Command | Description |
| --- | --- |
| `php artisan telegram:manage-bot` | Configures, checks info, or removes Telegram webhooks.

 |
| `php artisan subjects:check-due-images` | Scheduled job that checks for subjects due in 24 hours and dispatches images if channel member criteria are met.

 |
| `php artisan subjects:send-specific {subject_id}` | Manually force-sends all images for a specific subject directly to the target channel.

 |

---

## Security & Reliability

* **Database Transactions & Locking:** Invoices use atomic `lockForUpdate()` transactions to prevent race conditions during webhook delivery.


* **Strict Validation:** Kenyan mobile numbers are normalized to `254XXXXXXXXX` format prior to initiating payment requests.


* **Signature/Secret Token Verification:** Webhook updates validate `X-Telegram-Bot-Api-Secret-Token` to ensure requests originate exclusively from Telegram servers.



---

## License

This project is open-source software licensed under the [MIT License](https://www.google.com/search?q=LICENSE).