<?php

namespace App\Livewire\Tenant\Contact;

use App\Models\Tenant\Contact;
use App\Models\Tenant\ContactNote;
use App\Models\Tenant\Group;
use App\Models\Tenant\Source;
use App\Models\Tenant\Status;
use App\Models\User;
use App\Rules\PurifiedInput;
use App\Services\FeatureService;
use App\Traits\WithTenantContext;
use Corbital\LaravelEmails\Facades\Email;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ContactCreator extends Component
{
    use WithTenantContext;

    public Contact $contact;

    public string $notes_description = '';

    public array $notes = [];

    public $id;

    public $noteId;

    public $confirmingDeletion = false;

    public ?int $contactId = null;

    public int $initialNotesCount;

    public $tab = 'contact-details';

    public ?string $notetab = null;

    public $tenant_id;

    public $tenant_subdomain;

    protected $featureLimitChecker;

    public $grouped_id = null;

    // Array for multiple groups
    public $group_ids = [];

    public $groups = [];

    public function boot(FeatureService $featureLimitChecker)
    {
        $this->featureLimitChecker = $featureLimitChecker;
        $this->bootWithTenantContext();
    }

    public function mount()
    {
        if (! checkPermission(['tenant.contact.create', 'tenant.contact.edit'])) {
            $this->notify(['type' => 'danger', 'message' => t('access_denied_note')], true);

            return redirect(tenant_route('tenant.dashboard'));
        }

        $this->tenant_id = tenant_id();
        $this->tenant_subdomain = tenant_subdomain_by_tenant_id($this->tenant_id);

        $this->mountWithTenantContext();
        $this->id = $this->getId();
        $this->contactId = request()->route('contactId');

        // Initialize contact model
        $this->contact = new Contact;
        $this->contact->setTable($this->tenant_subdomain.'_contacts');

        // Load contact data if editing
        if ($this->contactId) {
            $this->contact = Contact::fromTenant($this->tenant_subdomain)->findOrFail($this->contactId);
            $this->group_ids = $this->contact->getGroupIds();

            // Set legacy grouped_id for backward compatibility if needed
            $this->grouped_id = ! empty($this->group_ids) ? $this->group_ids[0] : null;
        } else {
            // For new contacts
            $this->group_ids = [];
            $this->grouped_id = null;
        }

        $this->loadGroups();

        $this->notetab = request()->query('notetab');
        $this->initialNotesCount = ContactNote::fromTenant($this->tenant_subdomain)->count();
        $this->loadNotes();

        if ($this->notetab === 'notes') {
            $this->tab = 'notes';
        }
    }

    private function loadGroups()
    {
        $this->groups = Group::where('tenant_id', $this->tenant_id)
            ->select(['id', 'name'])
            ->get()
            ->map(function ($group) {
                $name = $group->name;

                return [
                    'id' => $group->id,
                    'name' => $name,
                ];
            });
    }

    protected function rules()
    {
        $contactTable = Contact::fromTenant($this->tenant_subdomain)->getTable();

        return [
            'contact.firstname' => ['required', 'string', new PurifiedInput(t('sql_injection_error')), 'max:191'],
            'contact.lastname' => ['required', 'string', new PurifiedInput(t('sql_injection_error')), 'max:191'],
            'contact.company' => ['nullable', 'string', new PurifiedInput(t('sql_injection_error')), 'max:191'],
            'contact.type' => ['required', 'in:customer,lead'],
            'contact.description' => ['nullable', 'string', new PurifiedInput(t('sql_injection_error')), 'max:65535'],
            'contact.country_id' => ['nullable', 'integer'],
            'contact.zip' => ['nullable', 'string', new PurifiedInput(t('sql_injection_error')), 'max:15'],
            'contact.city' => ['nullable', 'string', new PurifiedInput(t('sql_injection_error')), 'max:100'],
            'contact.state' => ['nullable', 'string', new PurifiedInput(t('sql_injection_error')), 'max:100'],
            'contact.address' => ['nullable', 'string', new PurifiedInput(t('sql_injection_error')), 'max:500'],
            'contact.assigned_id' => ['nullable'],
            'contact.status_id' => ['required', 'exists:statuses,id'],
            'contact.source_id' => ['required', 'exists:sources,id'],
            'contact.email' => ['nullable', 'email', 'max:191', Rule::unique($contactTable, 'email')->ignore($this->contact->id)->where(function ($query) {
                return $query->where('tenant_id', $this->tenant_id);
            })],
            'contact.website' => ['nullable', 'url', new PurifiedInput(t('sql_injection_error')), 'max:100'],
            'contact.phone' => ['required', new PurifiedInput(t('sql_injection_error')), Rule::unique($contactTable, 'phone')->ignore($this->contact->id)->where(function ($query) {
                return $query->where('tenant_id', $this->tenant_id);
            })],
            'group_ids' => [
                'nullable',
                'array',
            ],
            'group_ids.*' => [
                'exists:groups,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $group = Group::find($value);
                    }
                },
            ],
            // For backward compatibility - not actually used in the update
            'grouped_id' => [
                'nullable',
            ],
        ];
    }

    public function loadNotes()
    {
        if (! $this->contact || ! $this->contact->id) {
            $this->notes = [];

            return;
        }

        $notes = new ContactNote;
        $notes->setTable($this->tenant_subdomain.'_contact_notes');

        $this->notes = ContactNote::fromTenant($this->tenant_subdomain)
            ->where('contact_id', $this->contact->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($note) => [
                'id' => $note->id,
                'notes_description' => $note->notes_description,
                'created_at' => $note->created_at->diffForHumans(),
            ])
            ->toArray();
    }

    public function validateNotesDescription()
    {
        $this->validate([
            'notes_description' => ['nullable', 'string', new PurifiedInput(t('sql_injection_error'))],
        ]);
    }

    public function addNote()
    {
        $this->validate([
            'notes_description' => ['required', 'string', new PurifiedInput(t('sql_injection_error'))],
        ]);

        ContactNote::fromTenant($this->tenant_subdomain)->create([
            'notes_description' => $this->notes_description,
            'contact_id' => $this->contact->id,
            'tenant_id' => $this->tenant_id,
        ]);

        $this->notes_description = '';
        $this->notify([
            'type' => 'success',
            'message' => t('note_added_successfully'),
        ]);

        $this->loadNotes();
    }

    public function confirmDelete($noteId)
    {
        $this->noteId = $noteId;

        $this->confirmingDeletion = true;
    }

    public function removeNote()
    {
        ContactNote::fromTenant($this->tenant_subdomain)->where('id', $this->noteId)->delete();

        $this->confirmingDeletion = false;
        $this->notify([
            'type' => 'success',
            'message' => t('note_delete_successfully'),
        ]);

        $this->loadNotes();
    }

    public function save()
    {
        if (checkPermission(['tenant.contact.create', 'tenant.contact.edit'])) {
            $this->validate();

            $this->contact->assigned_id = $this->contact->assigned_id ?: null;

            if (! is_null($this->contact->assigned_id) && ($this->contact->isDirty('assigned_id') || is_null($this->contact->getOriginal('assigned_id')))) {
                $this->contact->dateassigned = now();
            }

            $this->contact->addedfrom = auth()->id();

            if (! $this->contact->exists) {
                $this->contact->is_enabled = true;
            }

            $notesChanged = ContactNote::fromTenant($this->tenant_subdomain)->where('contact_id', $this->contact->id)->exists() !== ($this->initialNotesCount > 0);

            $isNewContact = ! $this->contact->exists;
            if ($isNewContact && $this->featureLimitChecker->hasReachedLimit('contacts', Contact::class)) {
                $this->featureLimitChecker->trackUsage('contacts');
                $this->notify([
                    'type' => 'warning',
                    'message' => t('contact_limit_reached_upgrade_plan'),
                ]);

                return;
            }

            $groupsToSet = $this->group_ids ?: [];

            // Handle legacy grouped_id if it exists (backward compatibility)
            if (empty($groupsToSet) && isset($this->grouped_id) && $this->grouped_id) {
                $groupsToSet = [$this->grouped_id];
            }

            // Set groups using the safe method
            $this->contact->setGroupIds($groupsToSet);

            $assignedChanged = $this->contact->isDirty('assigned_id') && ! is_null($this->contact->assigned_id);

            if ($this->contact->isDirty() || $notesChanged) {
                $this->contact->tenant_id = $this->tenant_id;
                $this->contact->save();

                // Update local group_ids from saved contact
                $this->group_ids = $this->contact->getGroupIds();

                // Set grouped_id to first group for backward compatibility (if needed)
                $this->grouped_id = ! empty($this->group_ids) ? $this->group_ids[0] : null;

                if ($isNewContact) {
                    $this->featureLimitChecker->trackUsage('contacts');
                }

                if (($isNewContact || $assignedChanged) && can_send_email('tenant-new-contact-assigned', 'tenant_email_templates') && is_smtp_valid()) {
                    $this->send_content_assigned_mail();
                }

                $this->initialNotesCount = ContactNote::fromTenant($this->tenant_subdomain)->where('contact_id', $this->contact->id)->count();

                $this->notify([
                    'type' => 'success',
                    'message' => $this->contact->wasRecentlyCreated
                        ? t('contact_created_successfully')
                        : t('contact_update_successfully'),
                ], true);
            }

            return $this->redirect(tenant_route('tenant.contacts.list'));
        }
    }

    public function send_content_assigned_mail()
    {
        try {
            $assignee = User::select('email', 'firstname', 'lastname')->find($this->contact->assigned_id);
            if ($assignee && is_smtp_valid()) {
                $assignedEmail = $assignee->email;
                $content = render_email_template('tenant-new-contact-assigned', ['userId' => auth()->id(), 'contactId' => $this->contact->id, 'tenantId' => $this->tenant_id], 'tenant_email_templates');
                $subject = get_email_subject('tenant-new-contact-assigned', ['userId' => auth()->id(), 'contactId' => $this->contact->id, 'tenantId' => $this->tenant_id], 'tenant_email_templates');

                $result = Email::to($assignedEmail)
                    ->subject($subject)
                    ->content($content)
                    ->send();
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    public function cancel()
    {
        $this->resetValidation();
        $this->redirect(tenant_route('tenant.contacts.list'), navigate: true);
    }

    public function getRemainingLimitProperty()
    {
        return $this->featureLimitChecker->getRemainingLimit('contacts', Contact::class);
    }

    public function getIsUnlimitedProperty()
    {
        return $this->remainingLimit === null;
    }

    public function getHasReachedLimitProperty()
    {
        return $this->featureLimitChecker->hasReachedLimit('contacts', Contact::class);
    }

    public function getStatusesProperty()
    {
        return Status::all();
    }

    public function getSourcesProperty()
    {
        return Source::all();
    }

    public function getCountriesProperty()
    {
        return get_country_list();
    }

    public function getUsersProperty()
    {
        return User::where('tenant_id', $this->tenant_id)->get();
    }

    public function render()
    {
        return view('livewire.tenant.contact.contact-creator');
    }
}
