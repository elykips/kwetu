<?php

namespace App\Providers;

use App\Services\Billing\BillingManager;
use App\Services\PaymentGateways\OfflinePaymentGateway;
use App\Services\PaymentGateways\RazorpayPaymentGateway;
use App\Services\PaymentGateways\StripePaymentGateway;
use App\Services\Subscription\SubscriptionManager;
use Illuminate\Support\ServiceProvider;

class SubscriptionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Billing Manager
        $this->app->singleton('billing.manager', function ($app) {
            $manager = new BillingManager;

            // Get Stripe settings from database
            $settings = get_batch_settings([
                'payment.stripe_enabled',
                'payment.stripe_key',
                'payment.stripe_secret',
                'payment.razorpay_enabled',
                'payment.razorpay_key_id',
                'payment.razorpay_key_secret',
            ]);

            // Register Razorpay only if enabled and configured
            if (
                ! empty($settings['payment.razorpay_enabled']) && ! empty($settings['payment.razorpay_key_id']) && ! empty($settings['payment.razorpay_key_secret'])
            ) {
                $manager->register('razorpay', function () use ($settings) {
                    return new RazorpayPaymentGateway(
                        $settings['payment.razorpay_key_id'],
                        $settings['payment.razorpay_key_secret']
                    );
                });
            }

            // Register Stripe only if enabled and configured
            if (
                ! empty($settings['payment.stripe_enabled']) && ! empty($settings['payment.stripe_key']) && ! empty($settings['payment.stripe_secret'])
            ) {
                $manager->register('stripe', function () use ($settings) {
                    return new StripePaymentGateway(
                        $settings['payment.stripe_key'],
                        $settings['payment.stripe_secret']
                    );
                });
            }

            // Register Offline Payment
            $manager->register('offline', function () {
                return new OfflinePaymentGateway(
                    'Please make payment to our bank account and provide your invoice number in the payment details. '.
                    'Bank: Example Bank, Account: 12345678, Sort Code: 01-02-03, Reference: Your invoice number'
                );
            });

            return $manager;
        });

        // Register Subscription Manager
        $this->app->singleton(SubscriptionManager::class, function ($app) {
            return new SubscriptionManager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}
}
