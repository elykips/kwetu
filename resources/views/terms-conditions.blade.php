<x-guest-layout class="p-2">
 
    <div class="min-h-screen max-w-6xl mx-auto flex flex-col items-center bg-gray-100 dark:bg-slate-900">
        <x-card class="mt-24">
            <x-slot:header>
                <h1 class="text-3xl font-bold mb-4 break-words">{{ $title }}</h1>
                @if ($updated_at)
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Last updated:
                    {{ $updated_at }}</p>
                @endif
            </x-slot:header>
            <x-slot:content>

                <div class="prose dark:prose-invert max-w-none mb-2">
                    {!! $content !!}
                </div>

            </x-slot:content>
            <x-slot:footer>
                <x-button.primary onclick="window.history.back()">
                    Back
                </x-button.primary>
            </x-slot:footer>
        </x-card>
    </div>
</x-guest-layout>