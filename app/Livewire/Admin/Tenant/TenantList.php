<?php

namespace App\Livewire\Admin\Tenant;

use App\Events\Tenant\TenantDeleted;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Component;

class TenantList extends Component
{
    public Tenant $tenant;

    public User $user;

    public $tenantId;

    public $confirmingDeletion = false;

    protected $listeners = [
        'editTenant' => 'editTenant',
        'confirmDelete' => 'confirmDelete',
        'viewTenant' => 'viewTenant',
        'confirmTenantRegistration' => 'confirmTenantRegistration',
    ];

    public function mount()
    {
        if (! checkPermission('admin.tenants.view')) {
            $this->notify(['type' => 'danger', 'message' => t('access_denied_note')], true);

            return redirect(route('admin.dashboard'));
        }
    }

    public function createTenant()
    {
        $this->redirect(tenant_route('admin.tenants.save'));
    }

    public function editTenant($tenantId)
    {
        $this->tenant = Tenant::findOrFail($tenantId);
        $this->redirect(route('admin.tenants.save', ['tenantId' => $tenantId]));
    }

    public function viewTenant($tenantId)
    {
        $this->redirect(route('admin.tenants.view', ['tenantId' => $tenantId]));
    }

    public function confirmDelete($tenantId)
    {
        $this->tenantId = $tenantId;
        $this->confirmingDeletion = true;
    }

    public function delete()
    {
        if (checkPermission('admin.tenants.delete')) {
            $tenant = Tenant::findOrFail($this->tenantId);

            if ($tenant->id == auth()->id()) {
                $this->notify(['type' => 'warning', 'message' => t('cannot_delete_yourself')]);

                return;
            }

            // Check for active subscriptions
            $hasActiveSubscription = $tenant->subscriptions()->exists();

            if ($hasActiveSubscription) {
                $this->notify(['type' => 'warning', 'message' => t('you_cant_delete_this_tenant')]);
                $this->confirmingDeletion = false;

                return;
            }

            User::where('tenant_id', $tenant->id)->delete();
            $tenant->delete();

            if ($tenant->delete()) {
                event(new TenantDeleted($tenant));
            }

            $this->notify(['type' => 'success', 'message' => t('tenant_deleted_successfully')]);
            $this->confirmingDeletion = false;
            $this->dispatch('pg:eventRefresh-tenant-table');
        }
    }

    public function confirmTenantRegistration($tenantId)
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->notify(['type' => 'danger', 'message' => t('tenant_not_found')]);

            return;
        }

        // Update email_verified_at on the related user
        $user = User::where('tenant_id', $tenant->id)->first();
        if ($user) {
            $user->email_verified_at = now();
            $user->save();
        }

        $this->notify(['type' => 'success', 'message' => t('tenant_verified_successfully')]);
        $this->dispatch('pg:eventRefresh-tenant-table');
    }

    public function refreshTable()
    {
        $this->dispatch('pg:eventRefresh-tenant-table');
    }

    public function render()
    {
        return view('livewire.admin.tenant.tenant-list');
    }
}
