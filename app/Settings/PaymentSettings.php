<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PaymentSettings extends Settings
{
    // Default Gateway
    public string $default_gateway = 'offline';

    // Offline Payment Settings
    public bool $offline_enabled = false;

    public string $offline_description = 'Pay via direct bank transfer.';

    public string $offline_instructions = 'Please transfer the amount to our bank account and email the receipt.';

    // Stripe Settings
    public bool $stripe_enabled;

    public string $stripe_key;

    public string $stripe_secret;

    public string $stripe_webhook_secret;

    public string $stripe_webhook_id;

    // Razorpay Settings
    public bool $razorpay_enabled = false;

    public string $razorpay_key_id = '';

    public string $razorpay_key_secret = '';

    public string $razorpay_webhook_secret = '';

    // Tax Settings
    public bool $tax_enabled = false;

    public static function group(): string
    {
        return 'payment';
    }
}
