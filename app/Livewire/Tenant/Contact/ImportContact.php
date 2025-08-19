<?php

namespace App\Livewire\Tenant\Contact;

use App\Models\Tenant\Contact;
use App\Models\Tenant\Source;
use App\Models\Tenant\Status;
use App\Models\User;
use App\Services\FeatureService;
use App\Traits\WithTenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use League\Csv\Reader;
use League\Csv\Statement;
use Livewire\Component;
use Livewire\WithFileUploads;

class ImportContact extends Component
{
    use WithFileUploads;
    use WithTenantContext;

    public $csvFile;

    public $totalRecords = 0;

    public $validRecords = 0;

    public $invalidRecords = 0;

    public $processedRecords = 0;

    public $skippedDueToLimit = 0;

    public $errorMessages = [];

    public $importInProgress = false;

    protected $batchSize = 100;

    protected $referenceData = [];

    protected $featureLimitChecker;

    protected $rules = [
        'csvFile' => 'required|file|mimes:csv,txt|max:51200',
    ];

    public function boot(FeatureService $featureLimitChecker)
    {
        $this->featureLimitChecker = $featureLimitChecker;
        $this->bootWithTenantContext();
    }

    public function mount()
    {
        if (! checkPermission('tenant.contact.bulk_import')) {
            $this->notify(['type' => 'danger', 'message' => t('access_denied_note')], true);

            return redirect(tenant_route('tenant.dashboard'));
        }

        $this->mountWithTenantContext();
    }

    public function getStaffMembersProperty()
    {
        return User::where('tenant_id', tenant_id())
            ->where('user_type', 'tenant')
            ->get();
    }

    public function getContactStatusesProperty()
    {
        return Status::where('tenant_id', tenant_id())->get();
    }

    public function getLeadSourcesProperty()
    {
        return Source::where('tenant_id', tenant_id())->get();
    }

    protected function getValidationRules()
    {
        $tenant_subdomain = tenant_subdomain_by_tenant_id(tenant_id());
        $contactTable = Contact::fromTenant($tenant_subdomain)->getTable();

        return [
            'firstname' => 'required|string|max:191',
            'lastname' => 'required|string|max:191',
            'company' => 'nullable|string|max:191',
            'type' => 'required|in:lead,customer',
            'description' => 'nullable|string',
            'country_id' => 'nullable|exists:countries,id',
            'zip' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:191',
            'assigned_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if (! empty($value)) {
                        $exists = User::where('id', $value)
                            ->where('tenant_id', tenant_id())
                            ->exists();

                        if (! $exists) {
                            $fail(t('the_selected_user_invalid'));
                        }
                    }
                },
            ],
            'status_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    if (! empty($value)) {
                        $exists = Status::where('id', $value)
                            ->where('tenant_id', tenant_id())
                            ->exists();

                        if (! $exists) {
                            $fail(t('selected_status_is_invalid'));
                        }
                    }
                },
            ],
            'source_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    if (! empty($value)) {
                        $exists = Source::where('id', $value)
                            ->where('tenant_id', tenant_id())
                            ->exists();

                        if (! $exists) {
                            $fail(t('selected_source_is_invalid'));
                        }
                    }
                },
            ],
            'email' => ['nullable', 'email', 'max:100', Rule::unique($contactTable, 'email')->where(function ($query) {
                return $query->where('tenant_id', tenant_id());
            })],
            'phone' => [
                'required',
                'string',
                'max:50',
                Rule::unique($contactTable, 'phone')->where(function ($query) {
                    return $query->where('tenant_id', tenant_id());
                }),
                function ($attribute, $value, $fail) {
                    if (! preg_match('/^\+[1-9]\d{5,14}$/', $value)) {
                        $fail(t('phone_validation'));
                    }
                },
            ],
        ];
    }

    protected function validateCsvContents()
    {
        try {
            $csv = Reader::createFromPath($this->csvFile->path());
            $csv->setHeaderOffset(0);

            $headers = array_map('strtolower', $csv->getHeader());
            $requiredColumns = [
                'firstname',
                'lastname',
                'type',
                'phone',
                'status_id',
                'source_id',
            ];

            $missingColumns = array_diff($requiredColumns, $headers);

            if (! empty($missingColumns)) {
                $this->addError('csvFile', t('missing_required_columns').': '.implode(', ', $missingColumns));

                return false;
            }

            // Get accurate count without loading all records
            $stmt = (new Statement)->offset(0);
            $this->totalRecords = iterator_count($stmt->process($csv));
            $this->resetCounters();

            return true;
        } catch (\Exception $e) {
            $this->addError('csvFile', t('invalid_csv_file').': '.$e->getMessage());

            return false;
        }
    }

    protected function loadReferenceData()
    {
        if (empty($this->referenceData)) {
            $this->referenceData = [
                'statuses' => Status::pluck('id', 'name')->toArray(),
                'sources' => Source::pluck('id', 'name')->toArray(),
                'users' => User::pluck('id', 'firstname')->toArray(),
            ];
        }

        return $this->referenceData;
    }

    protected function transformRecord($record)
    {
        $record = array_change_key_case($record, CASE_LOWER);

        return [
            'tenant_id' => tenant_id(),
            'firstname' => $record['firstname'],
            'lastname' => $record['lastname'],
            'company' => $record['company'] ?? null,
            'type' => strtolower($record['type']) ?? 'lead',
            'description' => $record['description'] ?? null,
            'assigned_id' => isset($record['assigned_id']) ? (int) $record['assigned_id'] : null,
            'status_id' => isset($record['status_id']) ? (int) $record['status_id'] : null,
            'source_id' => isset($record['source_id']) ? (int) $record['source_id'] : null,
            'email' => $record['email'] ?? null,
            'phone' => $this->formatPhoneNumber($record['phone']),
            'addedfrom' => tenant_id(),
            'dateassigned' => now(),
            'last_status_change' => now(),
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    protected function processBatch($records)
    {
        $validRecords = [];
        $remainingLimit = $this->getRemainingLimit();
        $limitReached = false;

        foreach ($records as $index => $record) {
            // Check if we've reached the limit
            if (! $this->isUnlimited && $remainingLimit <= 0) {
                $this->skippedDueToLimit++;
                $limitReached = true;

                continue;
            }

            try {
                $transformedRecord = $this->transformRecord($record);
                $validator = Validator::make($transformedRecord, $this->getValidationRules());

                if ($validator->fails()) {
                    $this->invalidRecords++;
                    $this->errorMessages[] = [
                        'row' => $this->processedRecords + $index + 1,
                        'errors' => $validator->errors()->toArray(),
                    ];

                    continue;
                }

                $validRecords[] = $transformedRecord;
                if (! $this->isUnlimited) {
                    $remainingLimit--;
                }
            } catch (\Exception $e) {
                $this->invalidRecords++;
                $this->errorMessages[] = [
                    'row' => $this->processedRecords + $index + 1,
                    'errors' => ['system' => [$e->getMessage()]],
                ];
            }
        }

        $this->validRecords += count($validRecords);

        if (! empty($validRecords)) {
            try {
                $tenant_subdomain = tenant_subdomain_by_tenant_id(tenant_id());
                Contact::fromTenant($tenant_subdomain)->insert($validRecords);

                // Track usage for each successfully inserted contact
                foreach ($validRecords as $record) {
                    $this->featureLimitChecker->trackUsage('contacts');
                }
            } catch (\Exception $e) {
                // Reset valid records count since batch insert failed
                $this->validRecords -= count($validRecords);
                $remainingLimit = $this->getRemainingLimit();

                foreach ($validRecords as $record) {
                    // Skip if limit reached during fallback processing
                    if (! $this->isUnlimited && $remainingLimit <= 0) {
                        $this->skippedDueToLimit++;

                        continue;
                    }

                    try {
                        $contact = Contact::fromTenant($tenant_subdomain)->create($record);

                        if ($contact && $contact->exists) {
                            $this->validRecords++;
                            $this->featureLimitChecker->trackUsage('contacts');
                            if (! $this->isUnlimited) {
                                $remainingLimit--;
                            }
                        }
                    } catch (\Exception $inner) {
                        $this->invalidRecords++;
                        $this->errorMessages[] = [
                            'row' => 'Unknown',
                            'errors' => ['system' => [$inner->getMessage()]],
                        ];
                    }
                }
            }
        }

        return $limitReached;
    }

    public function processImport()
    {
        $this->validate();

        if (! $this->csvFile || $this->importInProgress) {
            return;
        }

        if (! $this->validateCsvContents()) {
            return;
        }

        // Check if we've already reached the limit before starting
        if ($this->hasReachedLimit) {
            $this->notify([
                'type' => 'warning',
                'message' => t('contact_limit_reached_upgrade_plan'),
            ]);

            return;
        }

        // Check if we have enough limit for all records
        $remainingLimit = $this->getRemainingLimit();
        if (! $this->isUnlimited && $remainingLimit < $this->totalRecords) {
            $this->notify([
                'type' => 'warning',
                'message' => t('import_exceeds_available_limit', [
                    'available' => $remainingLimit,
                    'total' => $this->totalRecords,
                ]),
            ]);
        }

        $this->importInProgress = true;
        $this->resetCounters();

        try {
            $csv = Reader::createFromPath($this->csvFile->path());
            $csv->setHeaderOffset(0);

            $this->loadReferenceData();

            $offset = 0;
            $limitReached = false;

            do {
                // Check if limit reached before processing each batch
                if ($this->hasReachedLimit || $limitReached) {
                    break;
                }

                $stmt = (new Statement)
                    ->offset($offset)
                    ->limit($this->batchSize);

                $records = iterator_to_array($stmt->process($csv));

                if (empty($records)) {
                    break;
                }

                $limitReached = $this->processBatch($records);

                $recordsProcessed = count($records);
                $this->processedRecords += $recordsProcessed;
                $offset += $this->batchSize;

                // Update the UI
                $this->dispatch('updateImportProgress', [
                    'processed' => $this->processedRecords,
                    'total' => $this->totalRecords,
                    'valid' => $this->validRecords,
                    'invalid' => $this->invalidRecords,
                    'skipped' => $this->skippedDueToLimit,
                ]);

                gc_collect_cycles();
            } while ($recordsProcessed > 0 && ! $limitReached);

            // Construct appropriate message based on results
            $message = t('import_completed', [
                'valid' => $this->validRecords,
                'invalid' => $this->invalidRecords,
            ]);

            if ($this->skippedDueToLimit > 0) {
                $message .= ' '.t('import_contacts_skipped_due_to_limit', [
                    'skipped' => $this->skippedDueToLimit,
                ]);
            }

            $type = ($this->skippedDueToLimit > 0) ? 'warning' : 'success';

            $this->notify([
                'type' => $type,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            $this->notify([
                'type' => 'danger',
                'message' => t('import_error').': '.$e->getMessage(),
            ]);
        } finally {
            $this->importInProgress = false;
        }
    }

    protected function resetCounters()
    {
        $this->validRecords = 0;
        $this->invalidRecords = 0;
        $this->processedRecords = 0;
        $this->skippedDueToLimit = 0;
        $this->errorMessages = [];
    }

    protected function formatPhoneNumber($phone)
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (! str_starts_with($phone, '+')) {
            $phone = '+'.$phone;
        }

        return $phone;
    }

    public function downloadSample()
    {
        $filePath = public_path('csv_sample/contacts_sample.csv');
        if (! file_exists($filePath)) {
            $this->notify(['type' => 'danger', 'message' => t('sample_file_not_found')]);

            return;
        }

        return response()->download($filePath);
    }

    // Helpers for feature limit tracking
    protected function getRemainingLimit()
    {
        return $this->featureLimitChecker->getRemainingLimit('contacts', Contact::class);
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

    public function getTotalLimitProperty()
    {
        return $this->featureLimitChecker->getLimit('contacts');
    }

    public function render()
    {
        return view('livewire.tenant.contact.import-contact', [
            'remainingLimit' => $this->remainingLimit,
            'isUnlimited' => $this->isUnlimited,
            'hasReachedLimit' => $this->hasReachedLimit,
            'totalLimit' => $this->totalLimit,
        ]);
    }
}
