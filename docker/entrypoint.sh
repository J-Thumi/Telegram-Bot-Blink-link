#!/bin/bash
set -e

echo "🚀 Starting Laravel Telegram Bot Application"
echo "============================================="

# Wait for database to be ready
if [ ! -z "$DB_HOST" ]; then
    echo "⏳ Waiting for database connection..."
    until nc -z -v -w30 $DB_HOST ${DB_PORT:-3306}
    do
        echo "Waiting for database connection..."
        sleep 5
    done
    echo "✅ Database is ready!"
fi

# Run database migrations
echo "📦 Running database migrations..."
php artisan migrate --force

# Clear and cache configurations
echo "🔄 Clearing and caching configurations..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

# Set storage permissions
echo "🔧 Setting storage permissions..."
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# Create storage link if not exists
if [ ! -L /var/www/html/public/storage ]; then
    echo "🔗 Creating storage link..."
    php artisan storage:link
fi

# Check if running in production (not local)
if [ "$APP_ENV" != "local" ]; then
    # Set webhook for Telegram bot
    if [ ! -z "$TELEGRAM_BOT_TOKEN" ] && [ ! -z "$TELEGRAM_WEBHOOK_SECRET" ]; then
        echo "🤖 Setting Telegram webhook..."
        
        # Wait a few seconds for the application to be ready
        sleep 5
        
        # Set webhook using the Telegram API
        WEBHOOK_URL="${APP_URL}/api/telegram/webhook"
        
        curl -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook" \
            -H "Content-Type: application/json" \
            -d "{
                \"url\": \"${WEBHOOK_URL}\",
                \"secret_token\": \"${TELEGRAM_WEBHOOK_SECRET}\",
                \"allowed_updates\": [\"message\", \"callback_query\"],
                \"drop_pending_updates\": true,
                \"max_connections\": 40
            }"
        
        echo ""
        echo "✅ Webhook configured: ${WEBHOOK_URL}"
        
        # Verify webhook
        curl -s "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/getWebhookInfo" | grep -q "webhook"
        if [ $? -eq 0 ]; then
            echo "✅ Webhook verified successfully!"
        else
            echo "⚠️  Webhook verification failed, but continuing..."
        fi
    else
        echo "⚠️  Telegram credentials missing. Skipping webhook setup."
    fi
else
    echo "🏠 Local environment detected. Skipping webhook setup."
fi

# Start Supervisor (manages PHP-FPM and Nginx)
echo "🎯 Starting Supervisor..."
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf