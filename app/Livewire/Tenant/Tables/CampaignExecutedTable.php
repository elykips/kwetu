<?php

namespace App\Livewire\Tenant\Tables;

use App\Models\Tenant\CampaignDetail;
use App\Models\Tenant\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;

final class CampaignExecutedTable extends PowerGridComponent
{
    public string $tableName = 'campaign-executed-table-q6pjqg-table';

    public $campaign_id;

    public $tenant_id;

    public $tenant_subdomain;

    use WithExport;

    public function mount(): void
    {
        parent::mount();
        $this->campaign_id = request()->route('campaignId');
        $this->tenant_id = tenant_id();
        $this->tenant_subdomain = tenant_subdomain_by_tenant_id($this->tenant_id);
    }

    public function setUp(): array
    {
        return [
            PowerGrid::exportable('contacts-list')
                ->striped()
                ->stripTags(true)
                ->type(Exportable::TYPE_XLS, Exportable::TYPE_CSV),
            PowerGrid::header()
                ->showSearchInput(),
            PowerGrid::footer()
                ->showPerPage(perPage: table_pagination_settings()['current'], perPageValues: table_pagination_settings()['options'])
                ->showRecordCount(),
        ];
    }

    public function datasource(): Builder
    {
        $query = CampaignDetail::query()
            ->join($this->tenant_subdomain.'_contacts as contact', 'campaign_details.rel_id', '=', 'contact.id')
            ->where('campaign_id', $this->campaign_id)
            ->where('status', '!=', 1)
            ->select([
                'campaign_details.*',
                'contact.phone',
                'contact.firstname',
                'contact.lastname',
                DB::raw("CONCAT(contact.firstname, ' ', contact.lastname) as contact_name"),

            ]);
        if (tenant_check()) {
            $query->where(['campaign_details.tenant_id' => tenant_id(), 'contact.tenant_id' => tenant_id()]);
        }

        return $query;
    }

    public function relationSearch(): array
    {
        return [];
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('campaign_id')
            ->add('rel_id_formatted', fn ($model) => ($contact = Contact::fromTenant($this->tenant_subdomain)->find($model->rel_id)) ?
                $contact->firstname.' '.$contact->lastname :
                $model->rel_id)
            ->add('rel_type')
            ->add('header_message')
            ->add(
                'body_message_formatted',
                fn ($model) => ($model->header_message ? $model->header_message."\n\n" : '').
                    ($model->body_message ?? '').
                    ($model->footer_message ? "\n\n".$model->footer_message : '')
            )
            ->add('footer_message')
            ->add(
                'status_formatted',
                fn ($message) => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium '.
                    match ($message->status) {
                        0 => 'bg-danger-100 text-danger-800 dark:text-danger-400 dark:bg-danger-900/20',
                        1 => 'bg-warning-100 text-warning-800 dark:text-warning-400 dark:bg-warning-900/20',
                        2 => 'bg-success-100 text-success-800 dark:text-success-400 dark:bg-success-900/20',
                        default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
                    }.
                    '">'.match ($message->status) {
                        0 => 'Failed',
                        1 => 'Pending',
                        2 => 'Success',
                        default => 'N/A'
                    }.'</span>'
            )
            ->add('response_message')
            ->add('whatsapp_id')
            ->add(
                'message_status',
                fn ($model) => '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium '.
                    match ($model->message_status) {
                        'sent' => 'bg-info-100 text-info-800 dark:text-info-400 dark:bg-info-900/20',
                        'delivered' => 'bg-success-100 text-success-800 dark:text-success-400 dark:bg-success-900/20',
                        'read' => 'bg-purple-100 text-purple-800 dark:text-purple-400 dark:bg-purple-900/20',
                        'failed' => 'bg-danger-100 text-danger-800 dark:text-danger-400 dark:bg-danger-900/20',
                        'pending' => 'bg-warning-100 text-warning-800 dark:text-warning-400 dark:bg-warning-900/20',
                        default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
                    }.
                    '">'.ucfirst($model->message_status ?? 'N/A').'</span>'
            )
            ->add('created_at');
    }

    public function columns(): array
    {
        return [
            Column::make(t('ids'), 'id')
                ->sortable()
                ->searchable(),

            Column::make(t('name'), 'contact_name', 'contacts.firstname')
                ->sortable()
                ->searchable(),

            Column::make(t('phone'), 'phone', 'contacts.phone')
                ->sortable()
                ->searchable(),
            Column::make(t('message'), 'body_message_formatted', 'body_message')
                ->sortable()
                ->searchable()
                ->bodyAttribute('style', 'width: calc(25 * 3ch); word-wrap: break-word; white-space: normal; line-height: 1.8;'),
            Column::make(t('sent_status'), 'status_formatted', 'status')
                ->sortable()
                ->searchable(),
        ];
    }
}
