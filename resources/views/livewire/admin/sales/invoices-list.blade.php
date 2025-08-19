<div >
    <x-slot:title>
        {{ t('invoices') }}
    </x-slot:title>
     <div class="mb-6">
        <div class="font-display">
            <h1 class="font-display text-3xl text-slate-900 dark:text-slate-200 font-medium">All Invoices
            </h1>
        </div>
    </div>

    <x-card class="rounded-lg">
        <x-slot:content>
            <div class="lg:mt-0" wire:poll.30s="refreshTable">
                <livewire:admin.tables.invoices-table />
            </div>
        </x-slot:content>
    </x-card>

</div>
