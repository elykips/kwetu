@props(['disabled' => false])

<div x-data="{
    isLoading: false,
    init() {
        Livewire.on('contacts-updated', () => {
            setTimeout(() => {
                this.isLoading = false;
            }, 500);
        });
    }
}">
    <select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge([
        'class' =>
        'block w-full border-slate-300 rounded-md shadow-sm text-slate-900 sm:text-sm focus:ring-info-500
        focus:border-info-500 disabled:opacity-50 dark:border-slate-500 dark:bg-slate-800 dark:placeholder-slate-500
        dark:text-slate-200 dark:focus:ring-info-500 dark:focus:border-info-500 dark:focus:placeholder-slate-600',
        ]) !!}>
        {{ $slot }}
    </select>
</div>