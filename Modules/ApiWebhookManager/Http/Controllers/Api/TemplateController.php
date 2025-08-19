<?php

namespace Modules\ApiWebhookManager\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant\WhatsappTemplate;
use Illuminate\Http\Request;

/**
 * @group Template Management
 *
 * APIs for managing WhatsApp templates within the tenant context
 */
class TemplateController extends Controller
{
    /**
     * List Templates
     *
     * Get a list of Templates with optional pagination.
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Number of items per page. Example: 15
     *
     * @response scenario=success status=200 {
     *   "status": "success",
     *   "data": [
     *     {
     *         "id": 1,
     *         "tenant_id": 2,
     *         "template_id": 510070465356446,
     *         "template_name": "welcome_to_whatsbot",
     *         "language": "en",
     *         "status": "APPROVED",
     *         "category": "MARKETING",
     *         "header_data_format": "IMAGE",
     *         "header_data_text": null,
     *          "header_params_count": 0,
     *         "body_data": "Welcome to WhatsBot - WhatsApp Marketing, Bot & Chat Module for Perfex CRM Support! 😊\n\nYou can explore and interact with our module in multiple ways:\n\n1️⃣ *Buy Module*: If you want to purchase the module 🛒.\n\n3️⃣ *Explore All Features*: To read detailed documentation and explore all features, on our online documentation 📚.",
     *         "body_params_count": 0,
     *         "footer_data": null,
     *         "footer_params_count": 0,
     *         "buttons_data": "[{\"type\":\"URL\",\"text\":\"Buy Module\",\"url\":\"https:\\/\\/codecanyon.net\\/item\\/whatsbot-whatsapp-marketing-bot-chat-module-for-perfex-crm\\/53052338\"},{\"type\":\"URL\",\"text\":\"Watch youtube Demo\",\"url\":\"https:\\/\\/youtu.be\\/tihN9GyXuzI\"},{\"type\":\"QUICK_REPLY\",\"text\":\"more\"}]",
     *         "created_at": "2025-07-12T10:44:50.000000Z",
     *         "updated_at": "2025-07-12T10:44:50.000000Z"
     *     }
     *   ]
     * }
     * @response status=401 scenario="unauthenticated" {
     *   "status": "error",
     *   "message": "Invalid API token"
     * }
     */
    public function index(Request $request)
    {
        try {
            $tenant_id = $request->get('tenant_id');

            $templates = WhatsappTemplate::where('tenant_id', $tenant_id)
                ->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15);

            return response()->json([
                'status' => 'success',
                'data' => $templates,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => t('failed_to_fetch_templates'),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Templates Details
     *
     * Get detailed information about a specific Templates.
     *
     * @urlParam id integer required The ID of the Templates. Example: 1
     *
     * @response scenario=success status=200 {
     *   "status": "success",
     *   "data": {
     *      "id": 1,
     *      "tenant_id": 2,
     *      "template_id": 510070465356446,
     *      "template_name": "welcome_to_whatsbot",
     *      "language": "en",
     *      "status": "APPROVED",
     *      "category": "MARKETING",
     *      "header_data_format": "IMAGE",
     *      "header_data_text": null,
     *      "header_params_count": 0,
     *      "body_data": "Welcome to WhatsBot - WhatsApp Marketing, Bot & Chat Module for Perfex CRM Support! 😊\n\nYou can explore and interact with our module in multiple ways:\n\n1️⃣ *Buy Module*: If you want to purchase the module 🛒.\n\n3️⃣ *Explore All Features*: To read detailed documentation and explore all features, on our online documentation 📚.",
     *      "body_params_count": 0,
     *      "footer_data": null,
     *      "footer_params_count": 0,
     *      "buttons_data": "[{\"type\":\"URL\",\"text\":\"Buy Module\",\"url\":\"https:\\/\\/codecanyon.net\\/item\\/whatsbot-whatsapp-marketing-bot-chat-module-for-perfex-crm\\/53052338\"},{\"type\":\"URL\",\"text\":\"Watch youtube Demo\",\"url\":\"https:\\/\\/youtu.be\\/tihN9GyXuzI\"},{\"type\":\"QUICK_REPLY\",\"text\":\"more\"}]",
     *      "created_at": "2025-07-12T10:44:50.000000Z",
     *      "updated_at": "2025-07-12T10:44:50.000000Z"
     *   }
     * }
     * @response status=404 scenario="not found" {
     *   "status": "error",
     *   "message": "Template not found"
     * }
     */
    public function show(Request $request, $subdomain, $id)
    {
        try {
            $tenant_id = $request->get('tenant_id');

            $template = WhatsappTemplate::where([
                ['tenant_id', '=', $tenant_id],
                ['id', '=', $id],
            ])->first();

            if (! $template) {
                return response()->json([
                    'status' => 'error',
                    'message' => t('template_not_found'),
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $template,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => t('template_not_found'),
                'error' => $e->getMessage(),
            ], 404);
        }
    }
}
