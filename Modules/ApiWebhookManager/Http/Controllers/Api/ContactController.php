<?php

namespace Modules\ApiWebhookManager\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Contact as TenantContact;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @group Contact Management
 *
 * APIs for managing contacts within the tenant context
 */
class ContactController extends Controller
{
    protected $featureLimitChecker;

    /**
     * ContactController constructor.
     */
    public function __construct(FeatureService $featureLimitChecker)
    {
        $this->featureLimitChecker = $featureLimitChecker;
    }

    /**
     * List Contacts
     *
     * Get a paginated list of contacts. You can filter by type and other parameters.
     *
     * @queryParam type string Filter by contact type (lead/customer). Example: lead
     * @queryParam source_id integer Filter by source ID. Example: 1
     * @queryParam status_id integer Filter by status ID. Example: 1
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     *
     * @response scenario=success status=200 {
     *   "status": "success",
     *   "data": [
     *     {
     *       "id": 1,
     *       "firstname": "John",
     *       "lastname": "Doe",
     *       "company": "Demo Company",
     *       "type": "lead",
     *       "email": "john@example.com",
     *       "phone": "+1234567890",
     *       "created_at": "2024-02-08 10:00:00",
     *       "updated_at": "2024-02-08 10:00:00"
     *     }
     *   ],
     *   "meta": {
     *     "current_page": 1,
     *     "total": 100,
     *     "per_page": 15
     *   }
     * }
     * @response status=401 scenario="unauthenticated" {
     *   "status": "error",
     *   "message": "Invalid API token"
     * }
     */
    public function index(Request $request, $subdomain)
    {
        try {

            $query = TenantContact::fromTenant($subdomain)->query();

            // Filter by type if provided
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            // Filter by source if provided
            if ($request->has('source_id')) {
                $query->where('source_id', $request->source_id);
            }

            // Filter by status if provided
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }

            $tenant_id = $request->get('tenant_id');

            $contacts = TenantContact::where('tenant_id', $tenant_id)
                ->orderBy('created_at', 'desc')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'status' => 'success',
                'data' => $contacts,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => t('failed_to_fetch_contact'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create Contact
     *
     * Create a new contact or lead in the system.
     *
     * @bodyParam firstname string required The first name of the contact. Example: John
     * @bodyParam lastname string required The last name of the contact. Example: Doe
     * @bodyParam company string The company name. Example: Acme Corp
     * @bodyParam type string required The contact type (lead/customer). Example: lead
     * @bodyParam email string The contact's email address. Example: john@example.com
     * @bodyParam phone string required The contact's phone number. Example: +911234567890
     * @bodyParam status_id integer required The status ID. Example: 1
     * @bodyParam description string Any additional notes. Example: Potential client from website
     *
     * @response scenario=success status=201 {
     *   "status": "success",
     *   "message": "Contact created successfully",
     *   "data": {
     *     "id": 1,
     *     "firstname": "John",
     *     "lastname": "Doe",
     *     "email": "john@example.com",
     *     "created_at": "2024-02-08 10:00:00"
     *   }
     * }
     * @response status=422 scenario="validation error" {
     *   "status": "error",
     *   "message": "Validation failed",
     *   "errors": {
     *     "email": ["The email field must be a valid email address."],
     *     "phone": ["The phone field is required."]
     *   }
     * }
     */
    public function store(Request $request, $subdomain)
    {

        $contactTable = TenantContact::fromTenant($subdomain)->getTable();
        $tenant_id = $request->get('tenant_id');
        $addedfrom = User::where('tenant_id', $tenant_id)
            ->value('id');
        $request->merge(['addedfrom' => $addedfrom]);

        try {
            $validator = Validator::make($request->all(), [
                'firstname' => 'required|string|max:255',
                'lastname' => 'required|string|max:255',
                'email' => [
                    'nullable',
                    'email',
                    'max:191',
                    Rule::unique($contactTable, 'email')->where(function ($query) use ($tenant_id) {
                        return $query->where('tenant_id', $tenant_id);
                    }),
                ],
                'phone' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique($contactTable, 'phone')
                        ->where(function ($query) use ($tenant_id) {
                            return $query->where('tenant_id', $tenant_id);
                        }),
                ],
                'type' => 'required|in:lead,contact',
                'source_id' => 'required|integer',
                'status_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => t('validation_failed'),
                    'errors' => $validator->errors(),
                ], 422);
            }

            if ($this->featureLimitChecker->hasReachedLimit('contacts', TenantContact::class)) {
                $this->featureLimitChecker->trackUsage('contacts');

                return response()->json([
                    'status' => 'error',
                    'message' => t('contact_limit_reached_upgrade_plan'),
                ], 403);
            }

            $contact = TenantContact::create($request->all())->fromTenant($subdomain);

            $this->featureLimitChecker->trackUsage('contacts');

            return response()->json([
                'status' => 'success',
                'message' => t('contact_created_successfully'),
                'data' => $contact,
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => t('failed_to_create_contact'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Contact Details
     *
     * Get detailed information about a specific contact.
     *
     * @urlParam id integer required The ID of the contact. Example: 1
     *
     * @response scenario=success status=200 {
     *   "status": "success",
     *   "data": {
     *     "id": 1,
     *     "firstname": "John",
     *     "lastname": "Doe",
     *     "company": "Demo Company",
     *     "type": "lead",
     *     "email": "john@example.com",
     *     "phone": "+1234567890",
     *     "created_at": "2024-02-08 10:00:00",
     *     "updated_at": "2024-02-08 10:00:00"
     *   }
     * }
     * @response status=404 scenario="not found" {
     *   "status": "error",
     *   "message": "Contact not found"
     * }
     */
    public function show(Request $request, $subdomain, $id)
    {
        try {
            $query = TenantContact::fromTenant($subdomain)->query();
            $tenant_id = $request->get('tenant_id');
            $contact = TenantContact::where([
                ['tenant_id', '=', $tenant_id],
                ['id', '=', $id],
            ])->first();

            return response()->json([
                'status' => 'success',
                'data' => $contact,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => t('contact_not_found'),
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Update Contact
     *
     * Update an existing contact's information.
     *
     * @urlParam id integer required The ID of the contact. Example: 1
     *
     * @bodyParam firstname string required The first name of the contact. Example: John
     * @bodyParam lastname string required The last name of the contact. Example: Doe
     * @bodyParam company string The company name. Example: Acme Corp
     * @bodyParam type string required The contact type (lead/customer). Example: lead
     * @bodyParam email string The contact's email address. Example: john@example.com
     * @bodyParam phone string required The contact's phone number. Example: +911234567890
     * @bodyParam status_id integer required The status ID. Example: 1
     * @bodyParam source_id integer required The source ID. Example: 1
     * @bodyParam description string Any additional notes. Example: Potential client from website
     *
     * @response scenario=success {
     *   "status": "success",
     *   "message": "Contact updated successfully",
     *   "data": {
     *     "id": 1,
     *     "firstname": "John",
     *     "lastname": "Doe",
     *     "email": "john@example.com",
     *     "updated_at": "2024-02-08 11:00:00"
     *   }
     * }
     */
    public function update(Request $request, $subdomain, $id)
    {
        try {
            $contactTable = TenantContact::fromTenant($subdomain)->getTable();
            $tenant_id = $request->get('tenant_id');

            // Assign a user to "addedfrom"
            $addedfrom = User::where('tenant_id', $tenant_id)->value('id');
            $request->merge(['addedfrom' => $addedfrom]);

            $contact = TenantContact::where([
                ['tenant_id', '=', $tenant_id],
                ['id', '=', $id],
            ])->first();

            if (! $contact) {
                return response()->json([
                    'status' => 'error',
                    'message' => t('contact_not_found'),
                ], 404);
            }

            // Fix validation rules
            $validator = Validator::make($request->all(), [
                'firstname' => 'required|string|max:255',
                'lastname' => 'required|string|max:255',
                'email' => [
                    'sometimes',
                    'email',
                    'max:191',
                    Rule::unique($contactTable, 'email')->ignore($id)->where(function ($query) use ($tenant_id) {
                        return $query->where('tenant_id', $tenant_id);
                    }),
                ],
                'phone' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique($contactTable, 'phone')->ignore($id)
                        ->where(function ($query) use ($tenant_id) {
                            return $query->where('tenant_id', $tenant_id);
                        }),
                ],
                'type' => 'sometimes|in:lead,contact',
                'source_id' => 'nullable|integer',
                'status_id' => 'nullable|integer|max:50',
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => 'error',
                    'message' => t('validation_failed'),
                    'errors' => $validator->errors(),
                ], 422);
            }

            $contact->update($request->all());

            return response()->json([
                'status' => 'success',
                'message' => t('contact_update_successfully'),
                'data' => $contact,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => t('failed_to_update_contact'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Contact
     *
     * Remove a contact from the system.
     *
     * @urlParam id integer required The ID of the contact. Example: 1
     *
     * @response scenario=success {
     *   "status": "success",
     *   "message": "Contact deleted successfully"
     * }
     * @response status=404 {
     *   "status": "error",
     *   "message": "Contact not found"
     * }
     */
    public function destroy(Request $request, $subdomain, $id)
    {
        try {

            $tenant_id = $request->get('tenant_id');

            $contact = TenantContact::where([
                ['tenant_id', '=', $tenant_id],
                ['id', '=', $id],
            ])->first();

            if (! $contact) {
                return response()->json([
                    'status' => 'error',
                    'message' => t('contact_not_found'),
                ], 404);
            }

            $contact->delete();

            return response()->json([
                'status' => 'success',
                'message' => t('contact_delete_success'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => t('failed_to_delete_contact'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
