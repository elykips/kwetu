<div>
    <x-slot:title>
        {{ t('payment_gateway_settings') }}
    </x-slot:title>
    <div class="space-y-6 max-w-6xl mx-auto">
        <div class="font-display">
            <h1 class="font-display text-3xl text-slate-900 dark:text-slate-200 font-medium">{{
                t('payment_gateway_settings') }}
            </h1>
        </div>
        @php
        $settings = get_batch_settings(['payment.offline_enabled', 'payment.stripe_enabled',
        'payment.razorpay_enabled']);
        // Ensure all keys exist to prevent undefined array key errors
        $settings = array_merge([
        'payment.offline_enabled' => false,
        'payment.stripe_enabled' => false,
        'payment.razorpay_enabled' => false,
        ], $settings);
        @endphp

        <x-card>
            <x-slot:header>
                <div class="flex items-center space-x-4">
                    <div class="flex-shrink-0">
                        <div class="w-14 h-14 bg-primary-100  rounded-full flex items-center justify-center">
                            <x-heroicon-m-bars-arrow-up class="w-6 h-6 text-primary-600" />
                        </div>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white flex items-center">
                            {{ t('payment_gateway_settings') }}
                        </h2>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400 flex items-center">
                            {{ t('configure_and_manage_payment_gateway') }}
                        </div>
                    </div>
                </div>
            </x-slot:header>
            <x-slot:content>
                <!-- Payment Methods Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Offline Payment -->
                    <a href="{{ route('admin.settings.payment.offline') }}" class="group relative">
                        <div
                            class="h-full block p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-info-500 dark:hover:border-info-500 transition-all duration-200 shadow-sm hover:shadow-md">
                            <div class="flex items-center">
                                <div class="shrink-0">
                                    <div class="relative">
                                        <div
                                            class="w-12 h-12 bg-info-100 dark:bg-info-900/50 rounded-lg flex items-center justify-center">
                                            <svg class="w-6 h-6 text-info-600 dark:text-info-400" fill="none"
                                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                            </svg>
                                        </div>

                                        <span
                                            class="absolute -top-1 -right-1 h-3 w-3 rounded-full  border-2 border-white dark:border-gray-800 {{ $settings['payment.offline_enabled'] ? 'bg-success-400' : 'bg-gray-200' }}"></span>
                                    </div>
                                </div>
                                <div class="ml-4 flex-1">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {{ t('offline_payment') }}
                                    </h3>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        {{ t('accept_cash_bank_transfers') }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-4 border-t border-gray-100 dark:border-gray-700 pt-4">

                                @if ($settings['payment.offline_enabled'])
                                <span
                                    class="inline-flex items-center text-xs font-medium text-success-600 dark:text-success-400">
                                    <span class="w-2 h-2 rounded-full bg-success-400 mr-2"></span>
                                    {{ t('active') }}
                                </span>
                                @else
                                <span class="inline-flex items-center text-xs font-medium">
                                    <span class="w-2 h-2 rounded-full bg-gray-300 dark:bg-gray-600 mr-2"></span>
                                    {{ t('not_configured') }}
                                </span>
                                @endif
                            </div>
                        </div>
                    </a>

                    <!-- Stripe -->
                    <a href="{{ route('admin.settings.payment.stripe') }}" class="group relative">
                        <div
                            class="h-full block p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-info-500 dark:hover:border-info-500 transition-all duration-200 shadow-sm hover:shadow-md">
                            <div class="flex items-center">
                                <div class="shrink-0">
                                    <div class="relative">
                                        <div
                                            class="w-12 h-12 bg-purple-100 dark:bg-purple-900/50 rounded-lg flex items-center justify-center">
                                            <svg class="w-6 h-6 text-purple-600 dark:text-purple-400"
                                                viewBox="0 0 24 24" fill="currentColor">
                                                <path
                                                    d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.591-7.305z" />
                                            </svg>
                                        </div> @php
                                        $stripeSettings = get_batch_settings(['payment.stripe_enabled']);
                                        $stripeSettings = array_merge(['payment.stripe_enabled' => false],
                                        $stripeSettings);
                                        @endphp
                                        <span
                                            class="absolute -top-1 -right-1 h-3 w-3 rounded-full border-2 border-white dark:border-gray-800 {{ $stripeSettings['payment.stripe_enabled'] ? 'bg-success-400' : 'bg-gray-200 ' }}"></span>
                                    </div>
                                </div>
                                <div class="ml-4 flex-1">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {{ t('stripe') }}
                                    </h3>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        {{ t('credit_card_international_payments') }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-4 border-t border-gray-100 dark:border-gray-700 pt-4">
                                @if ($settings['payment.stripe_enabled'])
                                <span
                                    class="inline-flex items-center text-xs font-medium text-success-600 dark:text-success-400">
                                    <span class="w-2 h-2 rounded-full bg-success-400 mr-2"></span>
                                    {{ t('active') }}
                                </span>
                                @else
                                <span
                                    class="inline-flex items-center text-xs font-medium text-gray-600 dark:text-gray-400">
                                    <span class="w-2 h-2 rounded-full  bg-gray-300 dark:bg-gray-600 mr-2"></span>
                                    {{ t('not_configured') }}
                                </span>
                                @endif
                            </div>
                        </div>
                    </a>

                    <!-- Razorpay -->
                    <a href="{{ route('admin.settings.payment.razorpay') }}" class="group relative">
                        <div
                            class="h-full block p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-info-500 dark:hover:border-info-500 transition-all duration-200 shadow-sm hover:shadow-md">
                            <div class="flex items-center">
                                <div class="shrink-0">
                                    <div class="relative">
                                        <div
                                            class="w-12 h-12 bg-info-100 dark:bg-info-900/50 rounded-lg flex items-center justify-center">
                                            <svg class="w-6 h-6 text-info-600 dark:text-info-400" fill="none"
                                                stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                            </svg>
                                        </div>
                                        <span
                                            class="absolute -top-1 -right-1 h-3 w-3 rounded-full border-2 border-white dark:border-gray-800 {{ $settings['payment.razorpay_enabled'] ? 'bg-success-400' : 'bg-gray-200' }}"></span>
                                    </div>
                                </div>
                                <div class="ml-4 flex-1">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        {{ t('razorpay') }}
                                    </h3>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        {{ t('upi_cards_netbanking_wallets') }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-4 border-t border-gray-100 dark:border-gray-700 pt-4">
                                @if ($settings['payment.razorpay_enabled'])
                                <span
                                    class="inline-flex items-center text-xs font-medium text-success-600 dark:text-success-400">
                                    <span class="w-2 h-2 rounded-full bg-success-400 mr-2"></span>
                                    {{ t('active') }}
                                </span>
                                @else
                                <span
                                    class="inline-flex items-center text-xs font-medium text-gray-600 dark:text-gray-400">
                                    <span class="w-2 h-2 rounded-full bg-gray-300 dark:bg-gray-600 mr-2"></span>
                                    {{ t('not_configured') }}
                                </span>
                                @endif
                            </div>
                        </div>
                    </a>
                </div>
            </x-slot:content>
        </x-card>
    </div>
</div>