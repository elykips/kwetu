<x-app-layout>
    <x-slot:title>
        {{ t('transactions') }}
    </x-slot:title>
    <div class="mb-6">
        <div class="font-display">
            <h1 class="font-display text-3xl text-slate-900 dark:text-slate-200 font-medium"> {{ t('all_transactions') }}
            </h1>
        </div>
    </div>
   
<x-card class="lg:mx-0 rounded-lg mt-4" x-init="
    setInterval(() => {
        Livewire.dispatch('refreshTable');
        
    }, 30000);
">
    <x-slot:content>
        <div class="mt-8 lg:mt-0">
            <livewire:admin.tables.transaction-table />
        </div>
    </x-slot:content>
</x-card>


</x-app-layout>
