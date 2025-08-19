<x-guest-layout>
    <x-slot:title>
        {{ t('forgot_password') }}
    </x-slot:title>
    <div class="min-h-screen bg-gray-50 dark:bg-slate-900 flex items-center justify-center relative">
        <div class="absolute top-4 right-4 sm:top-6 sm:right-6 lg:top-8 lg:right-10 z-20">
            <livewire:language-switcher />
        </div>
        <div class="container mx-auto px-4 py-8">
            <div
                class="flex flex-col lg:flex-row w-full overflow-hidden bg-white dark:bg-slate-800 rounded-xl shadow-2xl">
                <!-- Image Section -->
                <div class="hidden lg:block lg:w-3/5 relative bg-gradient-to-br from-primary-600 to-purple-700">
                    <div class="absolute inset-0 bg-black opacity-10"></div>
                    <div class="relative h-full p-12 flex flex-col justify-between z-10">
                        <div>
                            <h1 class="text-4xl font-bold text-white mb-2">{{ t('password_recovery') }} </h1>
                            <p class="text-white/80 text-lg">{{ t('help_you_reset_your_password') }} </p>
                        </div>
                        <div class="flex items-center justify-center h-full">
                            @php

                            $settings = get_batch_settings(['theme.cover_page_image']);


                            $cover_page_image = $settings['theme.cover_page_image'];
                            // Get the image path from settings
                            $imagePath = $cover_page_image
                            ? Storage::url($cover_page_image)
                            : url('./img/coverpage.png');
                            @endphp

                            <img src="{{ $imagePath }}" alt="Cover Page Image"
                                class="object-contain max-h-full max-w-full">
                        </div>
                        <div class="mt-auto">
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
                                    <x-heroicon-o-shield-check class="w-6 h-6 text-white" />
                                </div>
                                <div>
                                    <p class="text-white font-medium">{{ t('secure_password_reset') }} </p>
                                    <p class="text-white/70 text-sm">{{ t('send_instructions_to_email') }} </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Forgot Password Section -->
                <div class="w-full lg:w-2/5 p-6 lg:p-12 flex items-center justify-center">
                    <div class="w-full max-w-md mx-auto">
                        <!-- Logo/Header -->
                        <div class="text-center mb-8">
                            <div class="flex justify-center mb-3">
                                <div
                                    class="h-12 w-12 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                                    <x-heroicon-o-key class="h-7 w-7 text-primary-600 dark:text-primary-400" />
                                </div>
                            </div>
                            <h2 class="text-3xl font-bold text-gray-800 dark:text-white">{{ t('forgot_password') }}</h2>
                            <p class="mt-2 text-gray-500 dark:text-gray-400">
                                {{ t('link_to_reset_your_password') }}
                            </p>
                        </div>

                        <!-- Session Status -->
                        <div class="mb-4">
                            <x-auth-session-status class="mb-4" :status="session('status')" />
                        </div>

                        <form method="POST" action="{{ route('password.email') }}" x-data="{ loading: false }"
                            @submit="loading = true; $el.submit();">
                            @csrf

                            <!-- Email Address -->
                            <div class="mb-6">
                                <div class="flex items-center  mb-1">
                                    <span class="text-danger-500 text-sm mr-1">*</span>
                                    <x-label for="email" :value="t('email')"
                                        class="text-gray-700 dark:text-gray-300 font-medium" />

                                </div>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <x-heroicon-o-envelope class="h-5 w-5 text-gray-400" />
                                    </div>
                                    <x-text-input id="email"
                                        class="block w-full pl-10 rounded-lg border-gray-300 dark:border-gray-600 focus:border-primary-500 focus:ring-primary-500 dark:focus:ring-primary-500 dark:focus:border-primary-500 dark:bg-gray-700 dark:text-white"
                                        type="text" name="email" :value="old('email')" autofocus
                                        placeholder="your@email.com" />
                                </div>
                                <x-input-error for="email" class="mt-2" />
                            </div>

                            <div class="mb-6">
                                @php
                                $settings = get_batch_settings(['re-captcha.isReCaptchaEnable']);
                                @endphp
                                @if ($settings['re-captcha.isReCaptchaEnable'])
                                <div class="mb-5">
                                    <div class="bg-slate-100 p-4 rounded-md text-sm text-slate-600">
                                        {{ t('site_protected_by_recaptcha') }}
                                        <a href="https://policies.google.com/privacy" class="hover:text-slate-500"
                                            tabindex="-1">{{ t('privacy_policy') }}</a> {{ t('and') }}
                                        <a href="https://policies.google.com/terms" class="hover:text-slate-500"
                                            tabindex="-1">{{ t('terms_of_service') }}</a> apply.
                                    </div>
                                    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                                    <x-input-error :messages="$errors->first('g-recaptcha-response')" class="mt-2"
                                        for="g-recaptcha-response" />
                                </div>
                                @endif

                                <button type="submit"
                                    class="relative w-full flex justify-center items-center px-4 py-3 text-base font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 rounded-lg transition-colors duration-200 shadow-md hover:shadow-lg"
                                    :disabled="loading">

                                    <!-- Loading spinner (absolute positioned) -->
                                    <span x-show="loading" class="absolute inset-0 flex items-center justify-center">
                                        <x-heroicon-o-arrow-path class="animate-spin w-5 h-5" />
                                    </span>

                                    <!-- Regular content with invisible class instead of x-show -->
                                    <div :class="{ 'opacity-0': loading, 'opacity-100': !loading }"
                                        class="flex items-center">
                                        <x-heroicon-o-envelope class="w-5 h-5 mr-2" />
                                        <span>{{ t('send_reset_link') }}</span>
                                    </div>

                                </button>
                                <div class="text-center mt-6">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ t('already_registered') }}
                                        <a href="{{ route('login') }}"
                                            class="text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 font-medium ml-1">
                                            {{ t('login_link') }}
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
@php
$settings = get_batch_settings(['re-captcha.isReCaptchaEnable', 're-captcha.site_key']);
@endphp

@if (!empty($settings['re-captcha.isReCaptchaEnable']))
<script src="https://www.google.com/recaptcha/api.js?render={{ $settings['re-captcha.site_key'] }}"></script>
<script>
    grecaptcha.ready(function() {
            grecaptcha.execute('{{ $settings['re-captcha.site_key'] }}', {
                action: 'login'
            }).then(function(token) {
                document.getElementById('g-recaptcha-response').value = token;
            });
        });
</script>
@endif