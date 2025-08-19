<?php

namespace App\Traits;

use App\Exceptions\WhatsAppException;
use App\Models\Tenant\WhatsappTemplate;
use App\Models\Tenant\WmActivityLog;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Netflie\WhatsAppCloudApi\Message\ButtonReply\Button;
use Netflie\WhatsAppCloudApi\Message\ButtonReply\ButtonAction;
use Netflie\WhatsAppCloudApi\Message\CtaUrl\TitleHeader;
use Netflie\WhatsAppCloudApi\Message\Media\LinkID;
use Netflie\WhatsAppCloudApi\Message\Template\Component;
use Netflie\WhatsAppCloudApi\Response\ResponseException;
use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;
use Throwable;

trait WhatsApp
{
    protected static string $facebookAPI = 'https://graph.facebook.com/';

    /**
     * Store WhatsApp settings to avoid multiple database calls
     */
    protected $whatsappSettings;

    protected $connectionSettings;

    /**
     * Load all WhatsApp settings in a single batch call
     */
    protected function loadWhatsAppSettings()
    {
        if (! isset($this->whatsappSettings)) {
            $this->whatsappSettings = get_batch_settings([
                'whatsapp.api_version',
                'whatsapp.wm_fb_app_id',
                'whatsapp.wm_fb_app_secret',
                'whatsapp.queue',
                'whatsapp.webhook_verify_token',
            ]);
        }

        return $this->whatsappSettings;
    }

    protected static array $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'audio/mp3' => 'mp3',
        'video/mp4' => 'mp4',
        'audio/aac' => 'aac',
        'audio/amr' => 'amr',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'mp4',
        'text/plain' => 'txt',
        'application/pdf' => 'pdf',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/msword' => 'doc',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'video/3gp' => '3gp',
        'image/webp' => 'webp',
    ];

    // Add this property to store tenant ID
    protected $wa_tenant_id = null;

    // Add this method to set tenant ID and enable method chaining
    public function setWaTenantId($tenant_id)
    {
        $this->wa_tenant_id = $tenant_id;

        return $this;
    }

    // Add this method to get current tenant ID with fallback
    protected function getWaTenantId()
    {
        return $this->wa_tenant_id ?? tenant_id();
    }

    protected static function getApiVersion(): string
    {
        // For static context, use regular get_setting
        $settings = get_batch_settings(['whatsapp.api_version']);

        return $settings['whatsapp.api_version'] ?? 'v21.0';
    }

    protected static function getBaseUrl(): string
    {
        return self::$facebookAPI.self::getApiVersion().'/';
    }

    protected function handleApiError(Throwable $e, string $operation, array $context = []): array
    {
        $tenant_id = $this->getWaTenantId();

        $errorContext = array_merge([
            'operation' => $operation,
            'account_id' => $this->getAccountID(),
            'phone_id' => $this->getPhoneID(),
            'tenant_id' => $tenant_id,
        ], $context);

        whatsapp_log("[WhatsApp {$operation} Error] ".$e->getMessage(), 'error', $errorContext, $e, $tenant_id);

        return [
            'status' => false,
            'message' => config('app.debug')
                ? $e->getMessage()
                : __('whatsapp.errors.'.$operation, ['default' => 'An error occurred during '.$operation]),
        ];
    }

    /**
     * Load core WhatsApp connection settings in a single batch call
     */
    protected function loadConnectionSettings()
    {
        if (! isset($this->connectionSettings)) {
            $this->connectionSettings = tenant_settings_by_group('whatsapp', $this->wa_tenant_id);
        }

        return $this->connectionSettings;
    }

    private function getToken(): ?string
    {
        $this->loadConnectionSettings();

        return $this->connectionSettings['wm_access_token'] ?? null;
    }

    private function getAccountID(): ?string
    {
        $this->loadConnectionSettings();

        return $this->connectionSettings['wm_business_account_id'] ?? null;
    }

    private function getPhoneID(): ?string
    {
        $this->loadConnectionSettings();

        return $this->connectionSettings['wm_default_phone_number_id'] ?? null;
    }

    private function getFBAppID(): ?string
    {
        $this->loadWhatsAppSettings();

        return $this->whatsappSettings['whatsapp.wm_fb_app_id'] ?? null;
    }

    private function getFBAppSecret(): ?string
    {
        $this->loadWhatsAppSettings();

        return $this->whatsappSettings['whatsapp.wm_fb_app_secret'] ?? null;
    }

    /**
     * Load WhatsApp Cloud API configuration
     *
     * @param  string|null  $fromNumber  Optional phone number to use as the sender
     * @return WhatsAppCloudApi Instance of the WhatsAppCloudApi class
     */
    public function loadConfig($fromNumber = null)
    {
        return new WhatsAppCloudApi([
            'from_phone_number_id' => (! empty($fromNumber)) ? $fromNumber : $this->getPhoneID(),
            'access_token' => $this->getToken(),
        ]);
    }

    public function getPhoneNumbers(): array
    {
        try {
            $response = Http::get(self::getBaseUrl()."{$this->getAccountID()}/phone_numbers", [
                'access_token' => $this->getToken(),
            ]);

            if ($response->failed()) {
                throw new WhatsAppException($response->json('error.message'));
            }

            return ['status' => true, 'data' => $response->json('data')];
        } catch (Throwable $e) {
            return $this->handleApiError($e, 'get_phone_numbers');
        }
    }

    public function loadTemplatesFromWhatsApp(): array
    {
        try {
            $accountId = $this->getAccountID();
            $accessToken = $this->getToken();
            $tenant_id = $this->getWaTenantId();

            $templates = [];
            $url = self::getBaseUrl()."{$accountId}/message_templates?limit=100&access_token={$accessToken}";

            // Fetch all templates using pagination
            do {
                $response = Http::get($url);

                if ($response->failed()) {
                    throw new WhatsAppException($response->json('error.message'));
                }

                $data = $response->json('data');
                if (! $data) {
                    throw new WhatsAppException('Message templates not found.');
                }

                $templates = array_merge($templates, $data);

                $url = $response->json('paging.next') ?? null;
            } while ($url);

            // Get existing template IDs from database to track what should be deleted
            $existingTemplateIds = WhatsappTemplate::where('tenant_id', $tenant_id)
                ->pluck('template_id')->toArray();
            $apiTemplateIds = [];

            foreach ($templates as $templateData) {
                $apiTemplateIds[] = $templateData['id'];
                $template = [
                    'template_name' => $templateData['name'],
                    'language' => $templateData['language'],
                    'status' => $templateData['status'],
                    'category' => $templateData['category'],
                    'tenant_id' => $tenant_id,
                ];

                // Initialize variables
                $components = [];
                $headerText = $bodyText = $footerText = $buttonsData = null;
                $headerParamsCount = $bodyParamsCount = $footerParamsCount = 0;
                $headerVariableValue = null;
                $headerFileUrl = null;
                $bodyVariableValue = null;

                // Loop through components
                foreach ($templateData['components'] as $component) {
                    $type = $component['type'] ?? null;

                    if ($type === 'HEADER') {
                        $format = $component['format'] ?? null;
                        $components['TYPE'] = $format;

                        if (isset($component['text'])) {
                            $headerText = $component['text'];
                            $headerParamsCount = preg_match_all('/{{(.*?)}}/i', $headerText, $matches);
                            $components['HEADER'] = $headerText;
                        }

                        if (isset($component['example'])) {
                            if (! empty($component['example']['header_text'])) {
                                $headerVariableValue = $component['example']['header_text'];
                            }
                            if (! empty($component['example']['header_handle'])) {
                                $headerVariableValue = $component['example']['header_handle'];
                                $headerFileUrl = $headerVariableValue[0];
                            }
                        }
                    }

                    if ($type === 'BODY' && isset($component['text'])) {
                        $bodyText = $component['text'];
                        $bodyParamsCount = preg_match_all('/{{(.*?)}}/i', $bodyText, $matches);
                        $components['BODY'] = $bodyText;

                        if (isset($component['example']['body_text'])) {
                            $bodyVariableValue = $component['example']['body_text'];
                        }
                    }

                    if ($type === 'FOOTER' && isset($component['text'])) {
                        $footerText = $component['text'];
                        $footerParamsCount = preg_match_all('/{{(.*?)}}/i', $footerText, $matches);
                        $components['FOOTER'] = $footerText;
                    }

                    if ($type === 'BUTTONS') {
                        $components['BUTTONS'] = isset($component['buttons']) ? json_encode($component['buttons']) : null;
                    }
                }

                // Assign all extracted data to the $template array
                $template['header_data_text'] = $components['HEADER'] ?? null;
                $template['header_data_format'] = $components['TYPE'] ?? null;
                $template['body_data'] = $components['BODY'] ?? null;
                $template['footer_data'] = $components['FOOTER'] ?? null;
                $template['buttons_data'] = $components['BUTTONS'] ?? null;
                $template['header_params_count'] = $headerParamsCount;
                $template['body_params_count'] = $bodyParamsCount;
                $template['footer_params_count'] = $footerParamsCount;

                // New fields
                $template['header_file_url'] = $headerFileUrl;
                $template['header_variable_value'] = $headerVariableValue;
                $template['body_variable_value'] = $bodyVariableValue;

                // Save or update
                WhatsappTemplate::updateOrCreate(
                    [
                        'template_id' => $templateData['id'],
                        'tenant_id' => $tenant_id,
                    ],
                    $template
                );
            }

            // Delete templates that exist in DB but not in API
            $templatesForDeletion = array_diff($existingTemplateIds, $apiTemplateIds);
            if (! empty($templatesForDeletion)) {
                $deletedCount = WhatsappTemplate::where('tenant_id', $tenant_id)
                    ->whereIn('template_id', $templatesForDeletion)
                    ->delete();

                whatsapp_log('Deleted templates during sync', 'info', [
                    'deleted_count' => $deletedCount,
                    'template_ids' => $templatesForDeletion,
                    'tenant_id' => $tenant_id,
                ], null, $tenant_id);
            }

            return [
                'status' => true,
                'data' => $templates,
                'synced' => [
                    'updated_or_created' => count($apiTemplateIds),
                    'deleted' => count($templatesForDeletion),
                ],
                'message' => t('templates_synced_successfully'),
            ];
        } catch (Throwable $e) {
            return $this->handleApiError($e, 'load_templates');
        }
    }

    public function subscribeWebhook()
    {
        $accessToken = $this->getToken();
        $accountId = $this->getAccountID();
        $tenant_id = $this->getWaTenantId();
        $url = self::$facebookAPI."/$accountId/subscribed_apps?access_token=".$accessToken;

        try {
            $response = Http::post($url);

            $data = $response->json();

            if (isset($data['error'])) {
                return [
                    'status' => false,
                    'message' => $data['error']['message'],
                ];
            }

            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            whatsapp_log('Failed to subscribe webhook: '.$th->getMessage(), 'error', [
                'url' => $url,
                'account_id' => $accountId,
                'tenant_id' => $tenant_id,
            ], $th, $tenant_id);

            return [
                'status' => false,
                'message' => 'Something went wrong: '.$th->getMessage(),
            ];
        }
    }

    public function registerPhoneNumber(string $phoneNumberId)
    {
        $accessToken = $this->getToken();
        $tenantId = $this->getWaTenantId();

        $url = self::$facebookAPI.self::getApiVersion()."/{$phoneNumberId}/register";

        try {
            $response = Http::withToken($accessToken)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'pin' => '123456',
                ]);

            $data = $response->json();

            if (isset($data['error'])) {
                return [
                    'status' => false,
                    'message' => $data['error']['message'],
                ];
            }

            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            whatsapp_log('Failed to register phone number: '.$th->getMessage(), 'error', [
                'url' => $url,
                'phone_number_id' => $phoneNumberId,
                'tenant_id' => $tenantId,
            ], $th, $tenantId);

            return [
                'status' => false,
                'message' => 'Something went wrong: '.$th->getMessage(),
            ];
        }
    }

    public function getMessagingLimit()
    {
        $accessToken = $this->getToken();
        $phoneNumberId = $this->getPhoneID();
        $tenantId = $this->getWaTenantId();

        $url = self::$facebookAPI.self::getApiVersion()."/{$phoneNumberId}?fields=messaging_limit_tier";

        try {
            $response = Http::withToken($accessToken)->get($url);

            $data = $response->json();

            if (isset($data['error'])) {
                return [
                    'status' => false,
                    'message' => $data['error']['message'],
                ];
            }

            $limits = [
                'TIER_250' => 250,
                'TIER_500' => 500,
                'TIER_1K' => 1000,
                'TIER_10K' => 10000,
                'TIER_100K' => 100000,
                'TIER_UNLIMITED' => -1,
            ];

            $tier = $data['messaging_limit_tier'];
            $data['limit_value'] = $limits[$tier] ?? 0;

            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            whatsapp_log('Failed to get phone number limit: '.$th->getMessage(), 'error', [
                'url' => $url,
                'phone_number_id' => $phoneNumberId,
                'tenant_id' => $tenantId,
            ], $th, $tenantId);

            return [
                'status' => false,
                'message' => 'Something went wrong: '.$th->getMessage(),
            ];
        }
    }

    public function debugToken($fb_app_id, $fb_app_secret): array
    {
        try {
            $accessToken = $this->getToken();
            $appAccessToken = $fb_app_id.'|'.$fb_app_secret;

            $response = Http::get(self::getBaseUrl().'debug_token', [
                'input_token' => $accessToken,
                'access_token' => $appAccessToken,
            ]);

            if ($response->failed()) {
                throw new WhatsAppException($response->json('error.message'));
            }

            return ['status' => true, 'data' => $response->json('data')];
        } catch (Throwable $e) {
            return $this->handleApiError($e, 'debug_token');
        }
    }

    public function getProfile(): array
    {
        try {
            $response = Http::get(self::getBaseUrl().$this->getPhoneID().'/whatsapp_business_profile', [
                'fields' => 'profile_picture_url',
                'access_token' => $this->getToken(),
            ]);

            if ($response->failed()) {
                throw new WhatsAppException($response->json('error.message'));
            }

            return ['status' => true, 'data' => $response->json('data')];
        } catch (Throwable $e) {
            $data = $this->handleApiError($e, 'get_profile');

            return ['status' => false, 'data' => [], 'message' => $data['message'] ?? 'An error occurred while fetching the profile.'];
        }
    }

    public function getHealthStatus(): array
    {
        try {
            $response = Http::get(self::getBaseUrl().$this->getAccountID(), [
                'fields' => 'health_status',
                'access_token' => $this->getToken(),
            ]);

            if ($response->failed()) {
                throw new WhatsAppException($response->json('error.message'));
            }

            return ['status' => true, 'data' => $response->json()];
        } catch (Throwable $e) {
            return $this->handleApiError($e, 'health_status');
        }
    }

    public function getMessageLimit(): array
    {
        $startTime = strtotime(date('Y-m-d 00:00:00'));
        $endTime = strtotime(date('Y-m-d 23:59:59'));
        try {

            $response = Http::get(self::getBaseUrl().$this->getAccountID(), [
                'fields' => "id,name,analytics.start({$startTime}).end({$endTime}).granularity(DAY)",
                'access_token' => $this->getToken(),
            ]);

            if ($response->failed()) {
                throw new WhatsAppException($response->json('error.message'));
            }

            return ['status' => true, 'data' => $response->json()];
        } catch (Throwable $e) {
            $data = $this->handleApiError($e, 'health_status');

            return ['status' => false, 'data' => [], 'message' => $data['message']];
        }
    }

    public function generateUrlQR(string $url, ?string $logo = null): bool
    {
        try {
            $tenant_id = $this->getWaTenantId();
            $writer = new PngWriter;

            $qrCode = new QrCode(
                data: $url,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Low,
                size: 300,
                margin: 10,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255)
            );

            if ($logo) {
                $logo = new Logo(
                    path: public_path('img/whatsapp.png'),
                    resizeToWidth: 50,
                    punchoutBackground: true
                );
            }

            // Create generic label
            $label = new Label(
                text: '',
                textColor: new Color(255, 0, 0)
            );

            // Generate the QR code
            $result = $writer->write($qrCode, $logo, $label);

            create_storage_link();

            // Define the path to save the file
            $filePath = storage_path("app/public/tenant/{$tenant_id}/images/qrcode.png");

            // Ensure the directory exists
            if (! file_exists(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }

            // Save the QR code to the file
            $result->saveToFile($filePath);

            return true;
        } catch (Throwable $e) {
            $tenant_id = $this->getWaTenantId();
            whatsapp_log('Error generating QR code: '.$e->getMessage(), 'error', [
                'url' => $url,
                'logo' => $logo,
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);

            return false;
        }
    }

    public function connectWebhook($appId = null, $appSecret = null)
    {
        $appId = $appId ?? $this->getFBAppID();
        $appSecret = $appSecret ?? $this->getFBAppSecret();
        $tenant_id = $this->getWaTenantId();

        try {
            $url = self::$facebookAPI.$appId.'/subscriptions?access_token='.$appId.'|'.$appSecret;

            $response = Http::post($url, [
                'object' => 'whatsapp_business_account',
                'fields' => 'messages,message_template_quality_update,message_template_status_update,account_update',
                'callback_url' => route('whatsapp.webhook'),
                'verify_token' => $this->loadWhatsAppSettings()['whatsapp.webhook_verify_token'] ?? '',
            ]);

            $data = $response->json();

            if (isset($data['error'])) {
                return [
                    'status' => false,
                    'message' => $data['error']['message'],
                ];
            }

            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            whatsapp_log('Error connecting webhook: '.$th->getMessage(), 'error', [
                'tenant_id' => $tenant_id,
            ], $th, $tenant_id);

            return [
                'status' => false,
                'message' => 'Something went wrong: '.$th->getMessage(),
            ];
        }
    }

    public function disconnectWebhook()
    {
        $appId = $this->getFBAppID();
        $appSecret = $this->getFBAppSecret();
        $tenant_id = $this->getWaTenantId();

        $url = self::$facebookAPI.$appId.'/subscriptions?access_token='.$appId.'|'.$appSecret;

        try {
            $response = Http::delete($url, [], [
                'object' => 'whatsapp_business_account',
                'fields' => 'messages,message_template_quality_update,message_template_status_update,account_update',
            ]);

            $data = $response->json();

            if (isset($data['error'])) {
                return [
                    'status' => false,
                    'message' => $data['error']['message'],
                ];
            }

            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            whatsapp_log('Error disconnecting webhook: '.$th->getMessage(), 'error', [
                'tenant_id' => $tenant_id,
            ], $th, $tenant_id);

            return [
                'status' => false,
                'message' => 'Something went wrong: '.$th->getMessage(),
            ];
        }
    }

    public function sendTestMessages($number)
    {
        $tenant_id = $this->getWaTenantId();
        $whatsapp_cloud_api = $this->loadConfig();

        try {
            $result = $whatsapp_cloud_api->sendTemplate($number, 'hello_world', 'en_US');
            $status = true;
            $message = t('whatsapp_message_sent_successfully');
            $data = json_decode($result->body());
            $responseCode = $result->httpStatusCode();
        } catch (\Netflie\WhatsAppCloudApi\Response\ResponseException $th) {
            $status = false;
            $message = $th->responseData()['error']['message'] ?? $th->rawResponse() ?? json_decode($th->getMessage());
            $responseCode = $th->httpStatusCode();

            whatsapp_log('Error sending test message: '.$message, 'error', [
                'number' => $number,
                'response_code' => $responseCode,
                'tenant_id' => $tenant_id,
            ], $th, $tenant_id);
        }

        return ['status' => $status, 'message' => $message ?? ''];
    }

    public function checkServiceHealth(): array
    {
        try {
            $tenant_id = $this->getWaTenantId();

            // Load settings once if not already loaded
            $this->loadWhatsAppSettings();
            $queueSettings = json_decode($this->whatsappSettings['whatsapp.queue'] ?? '{"name":"default"}', true);

            $healthData = [
                'api_status' => $this->getHealthStatus(),
                'queue_size' => Queue::size($queueSettings['name']),
                'daily_api_calls' => Cache::get('whatsapp_api_calls_'.now()->format('Y-m-d')),
                'token_status' => $this->debugToken(),
                'profile_status' => $this->getProfile(),
                'tenant_id' => $tenant_id,
            ];

            whatsapp_log(
                'WhatsApp service health check',
                'info',
                $healthData,
                null,
                $tenant_id
            );

            return ['status' => true, 'data' => $healthData];
        } catch (Throwable $e) {
            return $this->handleApiError($e, 'health_check');
        }
    }

    protected function getExtensionForType(string $mimeType): ?string
    {
        return self::$extensionMap[$mimeType] ?? null;
    }

    /**
     * Send a template message using the WhatsApp Cloud API
     *
     * @param  string  $to  Recipient phone number
     * @param  array  $template_data  Data for the template message
     * @param  string  $type  Type of the message, default is 'campaign'
     * @param  string|null  $fromNumber  Optional sender phone number
     * @return array Response containing status, log data, and any response data or error message
     */
    public function sendTemplate($to, $template_data, $type = 'campaign', $fromNumber = null, $logEntry = true)
    {
        $tenant_id = $this->getWaTenantId();

        // CONVERSATION LIMIT CHECK FOR CAMPAIGNS
        $conversationTrackingNeeded = false;
        $identifierForTracking = null;

        if (($type === 'campaign' || $type == 'Initiate Chat') && ! empty($template_data['rel_id'])) {
            $featureService = app(\App\Services\FeatureService::class);
            $tenant_subdomain = tenant_subdomain_by_tenant_id($tenant_id);

            // Determine the conversation type and identifier
            $conversationType = $template_data['rel_type'] ?? 'guest';
            $identifierForCheck = $template_data['rel_id'];

            // Check if this would be a new conversation
            try {
                $hasActiveSession = $featureService->isConversationSessionActive(
                    $identifierForCheck,
                    $tenant_id,
                    $tenant_subdomain,
                    $conversationType
                );

                if (! $hasActiveSession) {
                    // This would be a new conversation
                    $conversationTrackingNeeded = true;
                    $identifierForTracking = $identifierForCheck;

                    // Check conversation limit
                    if ($featureService->checkConversationLimit($identifierForCheck, $tenant_id, $tenant_subdomain, $conversationType)) {
                        whatsapp_log('Campaign: Conversation limit reached - BLOCKING', 'warning', [
                            'to' => $to,
                            'identifier' => $identifierForCheck,
                            'type' => $conversationType,
                            'current_usage' => $featureService->getCurrentUsage('conversations'),
                            'current_limit' => $featureService->getLimit('conversations'),
                        ], null, $tenant_id);

                        $logdata = [
                            'status' => false,
                            'log_data' => [
                                'response_code' => 429,
                                'category' => $type,
                                'category_id' => $template_data['campaign_id'] ?? $template_data['template_bot_id'] ?? 0,
                                'rel_type' => $conversationType,
                                'rel_id' => $identifierForCheck,
                                'response_data' => json_encode(['error' => 'Conversation limit reached']),
                                'tenant_id' => $tenant_id,
                                'category_params' => json_encode(['templateId' => $template_data['template_id'], 'message' => $message ?? '']),
                                'raw_data' => json_encode(['error' => 'Conversation limit reached']),
                                'phone_number_id' => $this->getPhoneID(),
                                'access_token' => $this->getToken(),
                                'business_account_id' => $this->getAccountID(),
                            ],
                            'data' => [],
                            'message' => t('conversation_limit_reached'),
                        ];
                        if ($logEntry) {
                            WmActivityLog::create($logdata['log_data']);
                        }

                        return $logdata;
                    }
                }
            } catch (\Exception $e) {
                whatsapp_log('Campaign: Error checking conversation limit', 'error', [
                    'to' => $to,
                    'identifier' => $identifierForCheck,
                    'error' => $e->getMessage(),
                ], $e, $tenant_id);
            }
        }

        // BUILD TEMPLATE COMPONENTS
        $rel_type = $template_data['rel_type'];
        $header_data = [];

        if ($template_data['header_data_format'] == 'TEXT') {
            $header_data = parseText($rel_type, 'header', $template_data, 'array');
        }
        $body_data = parseText($rel_type, 'body', $template_data, 'array');
        $buttons_data = parseText($rel_type, 'footer', $template_data, 'array');

        $component_header = $component_body = $component_buttons = [];
        $file_link = asset('storage/'.$template_data['filename']);

        $template_buttons_data = json_decode($template_data['buttons_data']);
        $is_flow = false;
        if (! empty($template_buttons_data)) {
            $button_types = array_column($template_buttons_data, 'type');
            $is_flow = in_array('FLOW', $button_types);
        }

        $component_header = $this->buildHeaderComponent($template_data, $file_link, $header_data);
        $component_body = $this->buildTextComponent($body_data);
        $component_buttons = $this->buildTextComponent($buttons_data);

        if ($is_flow) {
            $buttons = json_decode($template_data['buttons_data']);
            $flow_id = reset($buttons)->flow_id;
            $component_buttons[] = [
                'type' => 'button',
                'sub_type' => 'FLOW',
                'index' => 0,
                'parameters' => [
                    [
                        'type' => 'action',
                        'action' => [
                            'flow_token' => json_encode(['flow_id' => $flow_id, 'rel_data' => $template_data['flow_action_data'] ?? []]),
                        ],
                    ],
                ],
            ];
        }

        $whatsapp_cloud_api = $this->loadConfig($fromNumber);

        try {
            $components = new Component($component_header, $component_body, $component_buttons);
            $result = $whatsapp_cloud_api->sendTemplate($to, $template_data['template_name'], $template_data['language'], $components);
            $status = true;
            $data = json_decode($result->body());
            $responseCode = $result->httpStatusCode();
            $responseData = json_encode($result->decodedBody());
            $rawData = json_encode($result->request()->body());

            // TRACK CONVERSATION AFTER SUCCESSFUL SEND
            if ($status && $conversationTrackingNeeded && $identifierForTracking) {
                try {
                    $featureService = app(\App\Services\FeatureService::class);
                    $tenant_subdomain = tenant_subdomain_by_tenant_id($tenant_id);

                    $tracked = $featureService->trackNewConversation(
                        $identifierForTracking,
                        $tenant_id,
                        $tenant_subdomain,
                        $template_data['rel_type'] ?? 'guest'
                    );
                } catch (\Exception $e) {
                    whatsapp_log('Campaign: Failed to track conversation after send', 'error', [
                        'to' => $to,
                        'identifier' => $identifierForTracking,
                        'error' => $e->getMessage(),
                    ], $e, $tenant_id);
                }
            }
        } catch (\Netflie\WhatsAppCloudApi\Response\ResponseException $th) {
            $status = false;
            $message = $th->responseData()['error']['message'] ?? $th->rawResponse() ?? json_decode($th->getMessage());
            $responseCode = $th->httpStatusCode();
            $responseData = json_encode($message);
            $rawData = json_encode([]);

            whatsapp_log('Error sending template: '.$message, 'error', [
                'to' => $to,
                'template_name' => $template_data['template_name'],
                'language' => $template_data['language'],
                'response_code' => $responseCode,
                'response_data' => $responseData,
                'raw_data' => $rawData,
                'tenant_id' => $tenant_id,
            ], $th, $tenant_id);
        }

        $log_data = [
            'response_code' => $responseCode,
            'category' => $type,
            'category_id' => $template_data['campaign_id'] ?? $template_data['template_bot_id'] ?? 0,
            'rel_type' => $rel_type,
            'rel_id' => $template_data['rel_id'],
            'category_params' => json_encode(['templateId' => $template_data['template_id'], 'message' => $message ?? '']),
            'response_data' => $responseData,
            'raw_data' => $rawData,
            'phone_number_id' => $this->getPhoneID(),
            'access_token' => $this->getToken(),
            'business_account_id' => $this->getAccountID(),
            'tenant_id' => $tenant_id,
        ];

        // Create activity log
        if ($logEntry) {
            WmActivityLog::create($log_data);
        }

        return ['status' => $status, 'log_data' => $log_data, 'data' => $data ?? [], 'message' => $message->error->message ?? ''];
    }

    /**
     * Send a message using the WhatsApp Cloud API
     *
     * @param  string  $to  Recipient phone number
     * @param  array  $message_data  Data for the message
     * @param  string|null  $fromNumber  Optional sender phone number
     * @return array Response containing status, log data, and any response data or error message
     */
    public function sendMessage($to, $message_data, $fromNumber = null, $folder = 'bot_files')
    {
        $tenant_id = $this->getWaTenantId();
        $message_data = parseMessageText($message_data);
        $whatsapp_cloud_api = $this->loadConfig($fromNumber);

        try {
            $rows = [];
            if (! empty($message_data['button1_id'])) {
                $rows[] = new Button($message_data['button1_id'], $message_data['button1']);
            }
            if (! empty($message_data['button2_id'])) {
                $rows[] = new Button($message_data['button2_id'], $message_data['button2']);
            }
            if (! empty($message_data['button3_id'])) {
                $rows[] = new Button($message_data['button3_id'], $message_data['button3']);
            }
            if (! empty($rows)) {
                $action = new ButtonAction($rows);
                $result = $whatsapp_cloud_api->sendButton(
                    $to,
                    $message_data['reply_text'],
                    $action,
                    $message_data['bot_header'],
                    $message_data['bot_footer']
                );
            } elseif (! empty($message_data['button_name']) && ! empty($message_data['button_url']) && filter_var($message_data['button_url'], \FILTER_VALIDATE_URL)) {
                $header = new TitleHeader($message_data['bot_header']);

                $result = $whatsapp_cloud_api->sendCtaUrl(
                    $to,
                    $message_data['button_name'],
                    $message_data['button_url'],
                    $header,
                    $message_data['reply_text'],
                    $message_data['bot_footer'],
                );
            } else {
                $message = $message_data['bot_header']."\n".$message_data['reply_text']."\n".$message_data['bot_footer'];
                if (! empty($message_data['filename'])) {
                    $url = asset('storage/'.$message_data['filename']);
                    $link_id = new LinkID($url);
                    $fileExtensions = get_meta_allowed_extension();
                    $extension = strtolower(pathinfo($message_data['filename'], PATHINFO_EXTENSION));
                    $fileType = array_key_first(array_filter($fileExtensions, fn ($data) => in_array('.'.$extension, explode(', ', $data['extension']))));
                    if ($fileType == 'image') {
                        $result = $whatsapp_cloud_api->sendImage($to, $link_id, $message);
                    } elseif ($fileType == 'video') {
                        $result = $whatsapp_cloud_api->sendVideo($to, $link_id, $message);
                    } elseif ($fileType == 'document') {
                        $result = $whatsapp_cloud_api->sendDocument($to, $link_id, $message_data['filename'], $message);
                    }
                } else {
                    $result = $whatsapp_cloud_api->sendTextMessage($to, $message, true);
                }
            }

            $status = true;
            $data = json_decode($result->body());
            $responseCode = $result->httpStatusCode();
            $responseData = $data;
            $rawData = json_encode($result->request()->body());
        } catch (\Netflie\WhatsAppCloudApi\Response\ResponseException $th) {
            $status = false;
            $message = $th->responseData()['error']['message'] ?? $th->rawResponse() ?? $th->getMessage();
            $responseCode = $th->httpStatusCode();
            $responseData = $message;
            $rawData = json_encode([]);

            whatsapp_log('Error sending message: '.$message, 'error', [
                'to' => $to,
                'message_type' => $folder,
                'response_code' => $responseCode,
                'tenant_id' => $tenant_id,
            ], $th, $tenant_id);
        }

        $log_data = [
            'response_code' => $responseCode ?? 500,
            'category' => $folder == 'bot_files' ? 'message_bot' : '',
            'category_id' => $message_data['id'] ?? 0,
            'rel_type' => $message_data['rel_type'] ?? '',
            'rel_id' => $message_data['rel_id'] ?? '',
            'category_params' => json_encode(['message' => $message ?? '']),
            'response_data' => ! empty($responseData) ? json_encode($responseData) : '',
            'raw_data' => $rawData,
            'phone_number_id' => $this->getPhoneID(),
            'access_token' => $this->getToken(),
            'business_account_id' => $this->getAccountID(),
            'tenant_id' => $tenant_id,
        ];

        // Create activity log
        WmActivityLog::create($log_data);

        return ['status' => $status, 'log_data' => $log_data, 'data' => $data ?? [], 'message' => $message->error->message ?? ''];
    }

    /**
     * Send bulk campaign to WhatsApp recipients
     *
     * @param  string  $to  Recipient phone number
     * @param  array  $templateData  Template configuration
     * @param  array  $campaign  Campaign data
     * @param  string|null  $fromNumber  Sender phone number (optional)
     * @return array Response data
     */
    public function sendBulkCampaign($to, $templateData, $campaign, $fromNumber = null)
    {
        $tenant_id = $this->getWaTenantId();

        try {
            // Parse template data for header, body, and buttons
            $headerData = [];
            if ($templateData['header_data_format'] == 'TEXT') {
                $headerData = parseCsvText('header', $templateData, $campaign);
            }

            $bodyData = parseCsvText('body', $templateData, $campaign);
            $buttonsData = parseCsvText('footer', $templateData, $campaign);

            // Get file link if available
            $fileLink = ($templateData['filename']) ? asset('storage/'.$templateData['filelink']) : '';

            // Build components for WhatsApp message
            $componentHeader = $this->buildHeaderComponent($templateData, $fileLink, $headerData);
            $componentBody = $this->buildTextComponent($bodyData);
            $componentButtons = $this->buildTextComponent($buttonsData);

            // Load WhatsApp API configuration
            $whatsappCloudApi = $this->loadConfig($fromNumber);

            // Create components object and send template
            $components = new Component($componentHeader, $componentBody, $componentButtons);
            $result = $whatsappCloudApi->sendTemplate(
                $to,
                $templateData['template_name'],
                $templateData['language'],
                $components
            );

            return [
                'status' => true,
                'data' => json_decode($result->body(), true),
                'responseCode' => $result->httpStatusCode(),
                'message' => '',
                'phone' => $to,
                'tenant_id' => $tenant_id,
            ];
        } catch (ResponseException $e) {

            whatsapp_log('WhatsApp API Error: '.$e->getMessage(), 'error', [
                'phone' => $to,
                'template' => $templateData['template_name'],
                'response_code' => $e->httpStatusCode(),
                'response_data' => $e->responseData() ?? [],
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);

            return [
                'status' => false,
                'data' => [],
                'responseCode' => $e->httpStatusCode(),
                'message' => $e->responseData()['error']['message'] ?? $e->getMessage(),
                'phone' => $to,
                'tenant_id' => $tenant_id,
            ];
        } catch (\Exception $e) {

            whatsapp_log('WhatsApp Campaign Error: '.$e->getMessage(), 'error', [
                'phone' => $to,
                'template' => $templateData['template_name'] ?? 'unknown',
                'response_code' => 500,
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);

            return [
                'status' => false,
                'data' => [],
                'responseCode' => 500,
                'message' => $e->getMessage(),
                'phone' => $to,
                'tenant_id' => $tenant_id,
            ];
        }
    }

    /**
     * Retry sending a campaign message with exponential backoff
     *
     * @param  string  $to  Recipient phone number
     * @param  array  $templateData  Template configuration
     * @param  array  $campaign  Campaign data
     * @param  string|null  $fromNumber  Sender phone number (optional)
     * @param  int  $maxRetries  Maximum number of retry attempts
     * @return array Response data
     */
    public function sendWithRetry($to, $templateData, $campaign, $fromNumber = null, $maxRetries = 3)
    {
        $tenant_id = $this->getWaTenantId();
        $attempt = 0;
        $result = null;

        while ($attempt < $maxRetries) {
            $result = $this->sendBulkCampaign($to, $templateData, $campaign, $fromNumber);

            // If successful or not a retryable error, break the loop
            if ($result['status'] || ! $this->isRetryableError($result['responseCode'])) {
                break;
            }

            // Exponential backoff: wait longer between each retry
            $waitTime = pow(2, $attempt) * 1000000; // in microseconds (1s, 2s, 4s)
            usleep($waitTime);
            $attempt++;
        }

        return $result;
    }

    /**
     * Check if an error is retryable
     *
     * @param  int  $statusCode  HTTP status code
     * @return bool Whether the error is retryable
     */
    protected function isRetryableError($statusCode)
    {
        // Retry on rate limiting, server errors, and certain client errors
        return in_array($statusCode, [408, 429, 500, 502, 503, 504]);
    }

    /**
     * Handle batch processing for large campaigns
     *
     * @param  array  $recipients  List of recipients
     * @param  array  $templateData  Template configuration
     * @param  int  $batchSize  Batch size (default: 50)
     * @return array Results for each recipient
     */
    public function processBatchCampaign($recipients, $templateData, $batchSize = 50)
    {
        $tenant_id = $this->getWaTenantId();
        $results = [];
        $batches = array_chunk($recipients, $batchSize);

        foreach ($batches as $batch) {
            foreach ($batch as $recipient) {
                $to = $recipient['phone'];
                $result = $this->sendBulkCampaign($to, $templateData, $recipient);
                $results[] = $result;
            }

            // Add a small delay between batches to avoid rate limiting
            if (count($batches) > 1) {
                usleep(500000);
            }
        }

        return $results;
    }

    private function buildHeaderComponent($templateData, $fileLink, $headerData)
    {
        return match ($templateData['header_data_format']) {
            'IMAGE' => [['type' => 'image', 'image' => ['link' => $fileLink]]],
            'DOCUMENT' => [['type' => 'document', 'document' => ['link' => $fileLink, 'filename' => 'file_'.uniqid().'.'.pathinfo($templateData['filename'], PATHINFO_EXTENSION)]]],
            'VIDEO' => [['type' => 'video', 'video' => ['link' => $fileLink]]],
            default => collect($headerData)->map(fn ($header) => ['type' => 'text', 'text' => $header])->toArray(),
        };
    }

    private function buildTextComponent($data)
    {
        return collect($data)->map(fn ($text) => ['type' => 'text', 'text' => $text])->toArray();
    }

    /**
     * Retrieve a URL for a media file using its media ID
     *
     * @param  string  $media_id  Media ID to retrieve the URL for
     * @return string|null Filename of the saved media file or null on failure
     */
    public function retrieveUrl($media_id)
    {
        $tenant_id = $this->getWaTenantId();
        $url = self::$facebookAPI.$media_id;
        $accessToken = $this->getToken();

        $response = Http::withToken($accessToken)->get($url);

        if ($response->successful()) {
            $responseData = $response->json();

            if (isset($responseData['url'])) {
                $mediaUrl = $responseData['url'];
                $mediaData = Http::withToken($accessToken)->get($mediaUrl);

                if ($mediaData->successful()) {
                    $imageContent = $mediaData->body();
                    $contentType = $mediaData->header('Content-Type');

                    $extensionMap = self::$extensionMap;
                    $extension = $extensionMap[$contentType] ?? 'unknown';
                    $filename = 'media_'.uniqid().'.'.$extension;
                    $storagePath = 'whatsapp-attachments/'.$filename;

                    Storage::disk('public')->put($storagePath, $imageContent);

                    return $filename;
                }
            }
        }

        whatsapp_log('Failed to retrieve media URL', 'error', [
            'media_id' => $media_id,
            'tenant_id' => $tenant_id,
        ], null, $tenant_id);

        return null;
    }

    /**
     * Send a flow message based on node type
     *
     * @param  string  $to  Recipient phone number
     * @param  array  $nodeData  Node data from flow
     * @param  string  $nodeType  Type of node
     * @param  string  $phoneNumberId  WhatsApp phone number ID
     * @param  array  $contactData  Contact information
     * @param  array  $context  Flow context information
     * @return array Response data
     */
    public function sendFlowMessage($to, $nodeData, $nodeType, $phoneNumberId, $contactData, $context = [])
    {
        switch ($nodeType) {
            case 'textMessage':
                return $this->sendFlowTextMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            case 'buttonMessage':
                return $this->sendFlowButtonMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            case 'callToAction':
                return $this->sendFlowCTAMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            case 'templateMessage':
                return $this->sendFlowTemplateMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            case 'mediaMessage':
                return $this->sendFlowMediaMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            case 'listMessage':
                return $this->sendFlowListMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            case 'locationMessage':
                return $this->sendFlowLocationMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            case 'contactMessage':
                return $this->sendFlowContactMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            case 'aiAssistant':
                return $this->sendFlowAiMessage($to, $nodeData, $phoneNumberId, $contactData, $context);

            default:
                return ['status' => false, 'message' => 'Unsupported node type: '.$nodeType];
        }
    }

    /**
     * Send a text message from flow
     */
    protected function sendFlowTextMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        // Use array functions to get reply_text from output if present
        $replyText = '';
        if (! empty($nodeData['output']) && is_array($nodeData['output'])) {
            $replyText = collect($nodeData['output'])
                ->pluck('reply_text')
                ->filter()
                ->first() ?? '';
        }
        if (empty($replyText)) {
            // Fallback to 'message' key if present
            $replyText = $nodeData['message'] ?? '';
        }
        // Replace variables in message
        $message = $this->replaceFlowVariables($replyText, $contactData);

        $messageData = [
            'rel_type' => $contactData->type ?? 'guest',
            'rel_id' => $contactData->id ?? '',
            'reply_text' => $message,
            'bot_header' => '',
            'bot_footer' => '',
            'tenant_id' => $this->wa_tenant_id,
        ];

        return $this->sendMessage($to, $messageData, $phoneNumberId);
    }

    /**
     * Send a button message from flow
     */
    protected function sendFlowButtonMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        // Extract button message fields from the first output element
        $output = collect($nodeData['output'] ?? [])->first() ?? [];
        $replyText = $this->replaceFlowVariables($output['reply_text'] ?? '', $contactData);
        $button1 = $output['button1'] ?? null;
        $button2 = $output['button2'] ?? null;
        $button3 = $output['button3'] ?? null;

        // Prepare buttons data with UNIQUE IDs
        $buttons = [];
        $currentNodeId = $context['current_node'] ?? uniqid();

        if ($button1) {
            $uniqueButtonId = $currentNodeId.'_btn_0';
            $buttons[] = new Button($uniqueButtonId, $button1);
        }
        if ($button2) {
            $uniqueButtonId = $currentNodeId.'_btn_1';
            $buttons[] = new Button($uniqueButtonId, $button2);
        }
        if ($button3) {
            $uniqueButtonId = $currentNodeId.'_btn_2';
            $buttons[] = new Button($uniqueButtonId, $button3);
        }

        if (empty($buttons)) {
            return [
                'status' => false,
                'message' => 'No buttons defined for button message',
            ];
        }

        try {
            $whatsapp_cloud_api = $this->loadConfig($phoneNumberId);

            // Create button action
            $buttonAction = new ButtonAction($buttons);

            // Send the button message
            $result = $whatsapp_cloud_api->sendButton(
                $to,
                $replyText,
                $buttonAction
            );

            $response = [
                'status' => true,
                'data' => json_decode($result->body()),
                'responseCode' => $result->httpStatusCode(),
                'responseData' => $result->decodedBody(),
            ];

            // Log the activity with unique button IDs
            $buttonTexts = array_filter([$button1, $button2, $button3]);
            $this->logFlowActivity($to, $response, [
                'rel_type' => $contactData->type ?? 'guest',
                'rel_id' => $contactData->id ?? '',
                'reply_text' => $replyText,
                'buttons' => $buttonTexts,
                'next_nodes' => $context['next_nodes'] ?? [],
            ], 'flow_button_message');

            return $response;
        } catch (\Throwable $e) {
            whatsapp_log('Flow button message error', 'error', [
                'error' => $e->getMessage(),
                'to' => $to,
            ], $e);

            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function sendFlowCTAMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        // Extract CTA fields from the first output element using array functions
        $output = collect($nodeData['output'] ?? [])->first() ?? [];
        $header = $this->replaceFlowVariables($output['bot_header'] ?? '', $contactData);
        $valueText = $this->replaceFlowVariables($output['reply_text'] ?? '', $contactData);
        $footer = $this->replaceFlowVariables($output['bot_footer'] ?? '', $contactData);
        $buttonText = $output['buttonText'] ?? 'Click Here';
        $buttonLink = $output['buttonLink'] ?? '';

        try {
            // Use CtaUrl message type from the library
            $whatsapp_cloud_api = $this->loadConfig($phoneNumberId);

            // Create header component if provided
            $headerComponent = null;
            if (! empty($header)) {
                $headerComponent = new TitleHeader($header);
            }

            // Send the CTA message
            $result = $whatsapp_cloud_api->sendCtaUrl(
                $to,
                $buttonText,
                $buttonLink,
                $headerComponent,
                $valueText,
                $footer
            );

            $response = [
                'status' => true,
                'data' => json_decode($result->body()),
                'responseCode' => $result->httpStatusCode(),
                'responseData' => $result->decodedBody(),
            ];

            // Log the activity
            $this->logFlowActivity($to, $response, [
                'rel_type' => $contactData->type ?? 'guest',
                'rel_id' => $contactData->id ?? '',
                'header' => $header,
                'value_text' => $valueText,
                'footer' => $footer,
                'button_text' => $buttonText,
                'button_link' => $buttonLink,
            ], 'flow_cta_message');

            return $response;
        } catch (\Throwable $e) {
            whatsapp_log('Flow CTA message error', 'error', [
                'error' => $e->getMessage(),
                'to' => $to,
            ], $e);

            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a template message from flow
     */
    protected function sendFlowTemplateMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        $templateId = $nodeData['templateId'] ?? '';
        $params = $nodeData['params'] ?? [];

        // Get template details from database
        $template = \App\Models\Tenant\WhatsappTemplate::where('template_id', $templateId)->first();

        if (! $template) {
            return ['status' => false, 'message' => 'Template not found'];
        }

        // Prepare template data
        $templateData = [
            'rel_type' => $contactData->type ?? 'guest',
            'rel_id' => $contactData->id ?? '',
            'template_id' => $templateId,
            'template_name' => $template->template_name,
            'language' => $template->language,
            'header_data_format' => $template->header_data_format,
            'header_data_text' => $template->header_data_text,
            'body_data' => $template->body_data,
            'footer_data' => $template->footer_data,
            'template_bot_id' => 0,
        ];

        // Add parameters
        if (! empty($params['header'])) {
            $templateData['header_params'] = json_encode($this->replaceFlowVariablesInArray($params['header'], $contactData));
        }

        if (! empty($params['body'])) {
            $templateData['body_params'] = json_encode($this->replaceFlowVariablesInArray($params['body'], $contactData));
        }

        if (! empty($params['footer'])) {
            $templateData['footer_params'] = json_encode($this->replaceFlowVariablesInArray($params['footer'], $contactData));
        }

        return $this->sendTemplate($to, $templateData, 'template_bot', $phoneNumberId);
    }

    /**
     * Send a media message from flow
     */
    protected function sendFlowMediaMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        // Extract media fields from the first output element using array functions
        $output = collect($nodeData['output'] ?? [])->first() ?? [];
        $mediaType = $output['media_type'] ?? 'image';
        $mediaUrl = $output['media_url'] ?? '';
        $caption = $this->replaceFlowVariables($output['media_caption'] ?? '', $contactData);
        $fileName = $output['media_filename'] ?? basename($mediaUrl);

        // Load WhatsApp Cloud API
        $whatsapp_cloud_api = $this->loadConfig($phoneNumberId);

        try {
            $result = null;
            $link = new LinkID($mediaUrl);

            switch ($mediaType) {
                case 'image':
                    $result = $whatsapp_cloud_api->sendImage($to, $link, $caption);
                    break;

                case 'video':
                    $result = $whatsapp_cloud_api->sendVideo($to, $link, $caption);
                    break;

                case 'audio':
                    $result = $whatsapp_cloud_api->sendAudio($to, $link);
                    break;

                case 'document':
                    $result = $whatsapp_cloud_api->sendDocument($to, $link, $fileName, $caption);
                    break;

                default:
                    return ['status' => false, 'message' => 'Unsupported media type'];
            }

            $response = [
                'status' => true,
                'data' => json_decode($result->body()),
                'responseCode' => $result->httpStatusCode(),
                'responseData' => $result->decodedBody(),
            ];

            // Log the activity
            $this->logFlowActivity($to, $response, [
                'rel_type' => $contactData->type ?? 'guest',
                'rel_id' => $contactData->id ?? '',
                'media_type' => $mediaType,
                'media_url' => $mediaUrl,
                'caption' => $caption,
            ], 'flow_media_message');

            return $response;
        } catch (\Throwable $e) {
            whatsapp_log('Flow media message error', 'error', [
                'error' => $e->getMessage(),
                'media_type' => $mediaType,
                'to' => $to,
            ], $e);

            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a list message from flow
     */
    protected function sendFlowListMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        // Extract list message fields from the first output element
        $output = collect($nodeData['output'] ?? [])->first() ?? [];
        $headerText = $this->replaceFlowVariables($output['bot_header'] ?? '', $contactData);
        $bodyText = $this->replaceFlowVariables($output['reply_text'] ?? '', $contactData);
        $footerText = $this->replaceFlowVariables($output['bot_footer'] ?? '', $contactData);
        $buttonText = $output['buttonText'] ?? 'View Options';
        $sections = $output['sections'] ?? [];

        if (empty($sections)) {
            return [
                'status' => false,
                'message' => 'No sections defined for list message',
            ];
        }

        // Load WhatsApp Cloud API
        $whatsapp_cloud_api = $this->loadConfig($phoneNumberId);
        $currentNodeId = $context['current_node'] ?? uniqid();

        try {
            // Format sections for WhatsApp API with unique IDs
            $formattedSections = [];
            foreach ($sections as $sectionIndex => $section) {
                $formattedSection = [
                    'title' => $section['title'] ?? 'Section '.($sectionIndex + 1),
                    'rows' => [],
                ];

                foreach ($section['items'] as $itemIndex => $item) {
                    // Create unique list item ID for flow navigation
                    $uniqueItemId = $currentNodeId.'_item_'.$sectionIndex.'_'.$itemIndex;

                    $formattedSection['rows'][] = [
                        'id' => $uniqueItemId, // Use unique ID instead of original item ID
                        'title' => $item['title'] ?? 'Item '.($itemIndex + 1),
                        'description' => $item['description'] ?? '',
                    ];
                }
                $formattedSections[] = $formattedSection;
            }

            // Create interactive list message payload
            $interactivePayload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'list',
                    'body' => [
                        'text' => $bodyText,
                    ],
                    'action' => [
                        'button' => $buttonText,
                        'sections' => $formattedSections,
                    ],
                ],
            ];

            // Add header if provided
            if (! empty($headerText)) {
                $interactivePayload['interactive']['header'] = [
                    'type' => 'text',
                    'text' => $headerText,
                ];
            }

            // Add footer if provided
            if (! empty($footerText)) {
                $interactivePayload['interactive']['footer'] = [
                    'text' => $footerText,
                ];
            }

            whatsapp_log('Sending list message with unique IDs', 'debug', [
                'node_id' => $currentNodeId,
                'to' => $to,
                'sections_count' => count($formattedSections),
                'total_items' => array_sum(array_map(function ($section) {
                    return count($section['rows']);
                }, $formattedSections)),
            ]);

            // Send using raw API call
            $response = Http::withToken($this->getToken())
                ->post(self::getBaseUrl().$this->getPhoneID().'/messages', $interactivePayload);

            $responseData = $response->json();

            $result = [
                'status' => $response->successful(),
                'data' => (object) $responseData,
                'responseCode' => $response->status(),
                'responseData' => $responseData,
            ];

            whatsapp_log('List message sent', 'info', [
                'node_id' => $currentNodeId,
                'to' => $to,
                'response_code' => $response->status(),
                'success' => $response->successful(),
                'message_id' => $responseData['messages'][0]['id'] ?? 'unknown',
            ]);

            // Log the activity
            $this->logFlowActivity($to, $result, [
                'rel_type' => $contactData->type ?? 'guest',
                'rel_id' => $contactData->id ?? '',
                'header' => $headerText,
                'body' => $bodyText,
                'footer' => $footerText,
                'button' => $buttonText,
                'sections' => $sections,
                'unique_ids_used' => true,
                'next_nodes' => $context['next_nodes'] ?? [],
            ], 'flow_list_message');

            return $result;
        } catch (\Throwable $e) {
            whatsapp_log('Flow list message error', 'error', [
                'error' => $e->getMessage(),
                'to' => $to,
                'node_id' => $currentNodeId,
                'trace' => $e->getTraceAsString(),
            ], $e);

            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a location message from flow
     */
    protected function sendFlowLocationMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        // Extract location fields from the first output element using array functions
        $output = collect($nodeData['output'] ?? [])->first() ?? [];
        $latitude = $output['location_latitude'] ?? '';
        $longitude = $output['location_longitude'] ?? '';
        $name = $this->replaceFlowVariables($output['location_name'] ?? '', $contactData);
        $address = $this->replaceFlowVariables($output['location_address'] ?? '', $contactData);

        // In netflie/whatsapp-cloud-api v2.2, we may need to use raw API for location
        try {
            // Send using raw API call since the library might not support location directly
            $response = Http::withToken($this->getToken())
                ->post(self::getBaseUrl().$this->getPhoneID().'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'location',
                    'location' => [
                        'latitude' => (float) $latitude,
                        'longitude' => (float) $longitude,
                        'name' => $name,
                        'address' => $address,
                    ],
                ]);

            $responseData = $response->json();

            $result = [
                'status' => $response->successful(),
                'data' => (object) $responseData,
                'responseCode' => $response->status(),
                'responseData' => $responseData,
            ];

            // Log the activity
            $this->logFlowActivity($to, $result, [
                'rel_type' => $contactData->type ?? 'guest',
                'rel_id' => $contactData->id ?? '',
                'latitude' => $latitude,
                'longitude' => $longitude,
                'name' => $name,
                'address' => $address,
            ], 'flow_location_message');

            return $result;
        } catch (\Throwable $e) {
            whatsapp_log('Flow location message error', 'error', [
                'error' => $e->getMessage(),
                'to' => $to,
            ], $e);

            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a contact message from flow
     */
    protected function sendFlowContactMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        // Extract contacts array from the first output element using array functions
        $output = collect($nodeData['output'] ?? [])->first() ?? [];
        $contacts = $output['contacts'] ?? [];
        $processedContacts = [];

        // Process and replace variables in contact data
        foreach ($contacts as $contact) {
            $firstName = $this->replaceFlowVariables($contact['firstName'] ?? '', $contactData);
            $lastName = $this->replaceFlowVariables($contact['lastName'] ?? '', $contactData);
            $formattedName = trim($firstName.' '.$lastName);
            $processed = [
                'name' => [
                    'formatted_name' => $formattedName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ],
                'phones' => [
                    [
                        'phone' => $this->replaceFlowVariables($contact['phone'] ?? '', $contactData),
                        'type' => 'CELL',
                    ],
                ],
            ];

            // Add email if provided
            if (! empty($contact['email'])) {
                $processed['emails'] = [
                    [
                        'email' => $this->replaceFlowVariables($contact['email'] ?? '', $contactData),
                        'type' => 'WORK',
                    ],
                ];
            }

            // Add company info if provided
            if (! empty($contact['company'])) {
                $processed['org'] = [
                    'company' => $this->replaceFlowVariables($contact['company'] ?? '', $contactData),
                ];

                if (! empty($contact['title'])) {
                    $processed['org']['title'] = $this->replaceFlowVariables($contact['title'] ?? '', $contactData);
                }
            }

            $processedContacts[] = $processed;
        }

        // In netflie/whatsapp-cloud-api v2.2, we need to use raw API for contacts
        try {
            // Send using raw API call
            $response = Http::withToken($this->getToken())
                ->post(self::getBaseUrl().$this->getPhoneID().'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'contacts',
                    'contacts' => $processedContacts,
                ]);

            $responseData = $response->json();

            $result = [
                'status' => $response->successful(),
                'data' => (object) $responseData,
                'responseCode' => $response->status(),
                'responseData' => $responseData,
            ];

            // Log the activity
            $this->logFlowActivity($to, $result, [
                'rel_type' => $contactData->type ?? 'guest',
                'rel_id' => $contactData->id ?? '',
                'contacts' => $processedContacts,
            ], 'flow_contact_message');

            return $result;
        } catch (\Throwable $e) {
            whatsapp_log('Flow contact message error', 'error', [
                'error' => $e->getMessage(),
                'to' => $to,
            ], $e);

            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send an AI-generated message from flow
     */
    protected function sendFlowAiMessage($to, $nodeData, $phoneNumberId, $contactData, $context)
    {
        $prompt = $this->replaceFlowVariables($nodeData['prompt'] ?? '', $contactData);
        $aiModel = $nodeData['aiModel'] ?? 'gpt-3.5-turbo';
        $contextType = $nodeData['contextType'] ?? 'message';

        // Get AI response (implement this based on your AI service)
        $aiResponse = $this->generateFlowAiResponse($prompt, $aiModel, $contextType, $context);

        if (! $aiResponse) {
            return ['status' => false, 'message' => 'Failed to generate AI response'];
        }

        $messageData = [
            'rel_type' => $contactData->type ?? 'guest',
            'rel_id' => $contactData->id ?? '',
            'reply_text' => $aiResponse,
            'bot_header' => '',
            'bot_footer' => '',
        ];

        return $this->sendMessage($to, $messageData, $phoneNumberId);
    }

    /**
     * Replace flow variables in text with contact data
     */
    public function replaceFlowVariables($text, $contactData)
    {
        if (empty($text)) {
            return $text;
        }
        $data['rel_type'] = $contactData['type'] ?? 'lead';
        $data['rel_id'] = $contactData['id'] ?? '';
        $data['reply_text'] = $text;
        $data['tenant_id'] = $this->wa_tenant_id;

        $data = parseMessageText($data);

        return $data['reply_text'] ?? '';
    }

    /**
     * Delete a template from Meta and local database
     */
    public function deleteTemplate(string $templateName, string $templateId): array
    {
        $tenant_id = $this->getWaTenantId();
        $accessToken = $this->getToken();
        $accountId = $this->getAccountID();

        try {
            // First, delete from Meta WhatsApp Business API
            $url = self::getBaseUrl()."{$accountId}/message_templates";

            $response = Http::withToken($accessToken)
                ->delete($url, [
                    'name' => $templateName,
                    'hsm_id' => $templateId,
                ]);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Failed to delete template from Meta';

                whatsapp_log('Meta API template deletion failed', 'error', [
                    'template_name' => $templateName,
                    'template_id' => $templateId,
                    'response_code' => $response->status(),
                    'error_message' => $errorMessage,
                    'tenant_id' => $tenant_id,
                ], null, $tenant_id);

                return [
                    'status' => false,
                    'message' => "Meta API Error: {$errorMessage}",
                    'error_code' => $response->status(),
                    'meta_deleted' => false,
                    'db_deleted' => false,
                ];
            }

            $metaResponse = $response->json();

            whatsapp_log('Template deleted from Meta successfully', 'info', [
                'template_name' => $templateName,
                'template_id' => $templateId,
                'meta_response' => $metaResponse,
                'tenant_id' => $tenant_id,
            ], null, $tenant_id);

            // If Meta deletion is successful, delete from local database
            try {
                $deleted = WhatsappTemplate::where('template_id', $templateId)
                    ->where('tenant_id', $tenant_id)
                    ->delete();

                if ($deleted) {
                    whatsapp_log('Template deleted from database successfully', 'info', [
                        'template_name' => $templateName,
                        'template_id' => $templateId,
                        'tenant_id' => $tenant_id,
                    ], null, $tenant_id);

                    return [
                        'status' => true,
                        'message' => 'Template deleted successfully from Meta and database',
                        'meta_deleted' => true,
                        'db_deleted' => true,
                        'meta_response' => $metaResponse,
                    ];
                } else {
                    whatsapp_log('Template not found in database', 'warning', [
                        'template_name' => $templateName,
                        'template_id' => $templateId,
                        'tenant_id' => $tenant_id,
                    ], null, $tenant_id);

                    return [
                        'status' => true,
                        'message' => 'Template deleted from Meta but not found in database',
                        'meta_deleted' => true,
                        'db_deleted' => false,
                        'meta_response' => $metaResponse,
                    ];
                }
            } catch (\Exception $dbException) {
                whatsapp_log('Database deletion failed after Meta deletion', 'error', [
                    'template_name' => $templateName,
                    'template_id' => $templateId,
                    'db_error' => $dbException->getMessage(),
                    'tenant_id' => $tenant_id,
                ], $dbException, $tenant_id);

                return [
                    'status' => false,
                    'message' => 'Template deleted from Meta but database deletion failed: '.$dbException->getMessage(),
                    'meta_deleted' => true,
                    'db_deleted' => false,
                    'meta_response' => $metaResponse,
                ];
            }
        } catch (\Exception $e) {
            whatsapp_log('Template deletion failed', 'error', [
                'template_name' => $templateName,
                'template_id' => $templateId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);

            return [
                'status' => false,
                'message' => 'Template deletion failed: '.$e->getMessage(),
                'meta_deleted' => false,
                'db_deleted' => false,
                'error_details' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete multiple templates with proper rollback handling
     *
     * @param  array  $templates  Array of templates with 'name' and 'id' keys
     */
    public function deleteMultipleTemplates(array $templates): array
    {
        $tenant_id = $this->getWaTenantId();
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $rollbackNeeded = [];

        try {
            foreach ($templates as $template) {
                $templateName = $template['name'] ?? '';
                $templateId = $template['id'] ?? '';

                if (empty($templateName) || empty($templateId)) {
                    $failureCount++;
                    $results[] = [
                        'template_name' => $templateName,
                        'template_id' => $templateId,
                        'status' => false,
                        'message' => 'Invalid template data provided',
                    ];

                    continue;
                }

                $result = $this->deleteTemplate($templateName, $templateId);

                if ($result['status']) {
                    $successCount++;
                    if ($result['meta_deleted']) {
                        $rollbackNeeded[] = $template; // For potential rollback
                    }
                } else {
                    $failureCount++;
                }

                $results[] = array_merge($result, [
                    'template_name' => $templateName,
                    'template_id' => $templateId,
                ]);
            }

            whatsapp_log('Bulk template deletion completed', 'info', [
                'total_templates' => count($templates),
                'successful' => $successCount,
                'failed' => $failureCount,
                'tenant_id' => $tenant_id,
            ], null, $tenant_id);

            return [
                'status' => $failureCount === 0,
                'message' => "Deletion completed: {$successCount} successful, {$failureCount} failed",
                'total_processed' => count($templates),
                'successful' => $successCount,
                'failed' => $failureCount,
                'results' => $results,
                'rollback_available' => ! empty($rollbackNeeded),
            ];
        } catch (\Exception $e) {
            whatsapp_log('Bulk template deletion failed', 'error', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);

            return [
                'status' => false,
                'message' => 'Bulk deletion failed: '.$e->getMessage(),
                'total_processed' => count($results),
                'successful' => $successCount,
                'failed' => $failureCount,
                'results' => $results,
                'error_details' => $e->getMessage(),
            ];
        }
    }

    /**
     * Log flow activity to database
     */
    protected function logFlowActivity($to, $response, $messageData, $category)
    {
        $data = [
            'response_code' => $response['responseCode'] ?? 200,
            'category' => $category,
            'category_id' => $messageData['flow_id'] ?? 0,
            'rel_type' => $messageData['rel_type'] ?? '',
            'rel_id' => $messageData['rel_id'] ?? '',
            'category_params' => json_encode($messageData),
            'response_data' => json_encode($response['responseData'] ?? []),
            'raw_data' => json_encode($response['data'] ?? []),
            'phone_number_id' => get_setting('whatsapp.wm_default_phone_number_id'),
            'access_token' => get_setting('whatsapp.wm_access_token'),
            'business_account_id' => get_setting('whatsapp.wm_business_account_id'),
        ];

        WmActivityLog::create($data);
    }

    /**
     * Upload resumable media for template examples (Fixed WhatsJet Pattern)
     * This method properly handles the upload and returns the handle needed for templates
     */
    protected function uploadResumableMediaForTemplate(string $mediaUrl): ?string
    {
        try {
            $tenant_id = $this->getWaTenantId();
            $accessToken = $this->getToken();
            $facebookAppId = $this->getFBAppID();

            whatsapp_log('Starting resumable media upload for template', 'debug', [
                'media_url' => $mediaUrl,
                'facebook_app_id' => $facebookAppId,
            ], null, $tenant_id);

            // Step 1: Download the media file
            $fileResponse = Http::timeout(30)->get($mediaUrl);
            if ($fileResponse->failed()) {
                throw new \Exception('Failed to download media file from: '.$mediaUrl);
            }

            $fileContents = $fileResponse->body();
            $mimeType = $fileResponse->header('Content-Type');

            // If Content-Type header is not available, try to detect from URL extension
            if (! $mimeType) {
                $extension = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
                $mimeType = match ($extension) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'mp4' => 'video/mp4',
                    'pdf' => 'application/pdf',
                    default => 'application/octet-stream'
                };
            }

            $fileLength = strlen($fileContents);

            whatsapp_log('Media file downloaded', 'debug', [
                'file_length' => $fileLength,
                'mime_type' => $mimeType,
            ], null, $tenant_id);

            // Step 2: Create upload session
            $uploadSessionResponse = Http::withToken($accessToken)
                ->post(self::getBaseUrl()."{$facebookAppId}/uploads", [
                    'file_length' => $fileLength,
                    'file_type' => $mimeType,
                ]);

            if ($uploadSessionResponse->failed()) {
                $errorData = $uploadSessionResponse->json();
                throw new \Exception('Failed to create upload session: '.($errorData['error']['message'] ?? $uploadSessionResponse->body()));
            }

            $uploadSessionData = $uploadSessionResponse->json();
            $uploadSessionId = $uploadSessionData['id'];

            whatsapp_log('Upload session created', 'debug', [
                'session_id' => $uploadSessionId,
            ], null, $tenant_id);

            // Step 3: Upload the file using cURL (like WhatsJet)
            $uploadUrl = self::getBaseUrl().$uploadSessionId;

            $ch = curl_init();

            // Create a temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'whatsapp_upload_');
            file_put_contents($tempFile, $fileContents);

            $postFields = [
                'file' => new \CURLFile($tempFile, $mimeType, basename($mediaUrl)),
                'type' => $mimeType,
            ];

            $headers = [
                'Authorization: OAuth '.$accessToken,
                'file_offset: 0',
            ];

            curl_setopt_array($ch, [
                CURLOPT_URL => $uploadUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 60,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // Clean up temp file
            unlink($tempFile);

            if ($result === false || ! empty($curlError)) {
                throw new \Exception('cURL error: '.$curlError);
            }

            if ($httpCode !== 200) {
                throw new \Exception('Upload failed with HTTP code: '.$httpCode.', Response: '.$result);
            }

            $resultData = json_decode($result, true);

            if (! $resultData) {
                throw new \Exception('Invalid JSON response: '.$result);
            }

            if (isset($resultData['error'])) {
                throw new \Exception('Upload API error: '.($resultData['error']['message'] ?? json_encode($resultData['error'])));
            }

            // WhatsJet returns the 'h' field for resumable uploads
            $uploadHandle = $resultData['h'] ?? null;

            if (! $uploadHandle) {
                throw new \Exception('No upload handle returned. Response: '.$result);
            }

            whatsapp_log('Resumable media uploaded successfully for template', 'info', [
                'original_url' => $mediaUrl,
                'upload_handle' => $uploadHandle,
                'tenant_id' => $tenant_id,
            ], null, $tenant_id);

            return $uploadHandle;
        } catch (\Exception $e) {
            whatsapp_log('Resumable media upload failed', 'error', [
                'media_url' => $mediaUrl,
                'error' => $e->getMessage(),
                'tenant_id' => $this->getWaTenantId(),
            ], $e, $this->getWaTenantId());

            return null;
        }
    }

    /**
     * Create a new WhatsApp template (Fixed version)
     */
    public function createTemplate(array $templateData): array
    {
        $tenant_id = $this->getWaTenantId();
        $accessToken = $this->getToken();
        $accountId = $this->getAccountID();

        try {
            $url = self::getBaseUrl()."{$accountId}/message_templates";

            // Build template components from the incoming data structure
            $components = [];
            $data = $templateData['data'] ?? [];

            // Add header component if provided
            if (! empty($data['header'])) {
                $headerComponent = [
                    'type' => 'HEADER',
                ];

                if ($data['header']['type'] === 'TEXT') {
                    $headerComponent['format'] = 'TEXT';
                    $headerComponent['text'] = $data['header']['text'];

                    // Add header example if there are variables
                    if (preg_match('/\{\{\d+\}\}/', $data['header']['text'])) {
                        $headerComponent['example'] = [
                            'header_text' => ['Sample Header Value'],
                        ];
                    }
                } elseif (in_array($data['header']['type'], ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                    $headerComponent['format'] = $data['header']['type'];

                    $mediaUrl = $data['header']['media_url'] ?? null;

                    if (! empty($mediaUrl)) {
                        // Upload media and get handle
                        $uploadedHandle = $this->uploadResumableMediaForTemplate($mediaUrl);

                        if ($uploadedHandle) {
                            $headerComponent['example'] = [
                                'header_handle' => [$uploadedHandle],
                            ];

                            whatsapp_log('Using upload handle for template header', 'info', [
                                'upload_handle' => $uploadedHandle,
                                'media_url' => $mediaUrl,
                                'header_type' => $data['header']['type'],
                            ], null, $tenant_id);
                        } else {
                            // If upload fails, we cannot create IMAGE/VIDEO/DOCUMENT header without example
                            return [
                                'status' => false,
                                'message' => 'Failed to upload media for template header. Media upload is required for '.$data['header']['type'].' headers.',
                                'error_details' => 'Media upload failed',
                            ];
                        }
                    }
                }

                $components[] = $headerComponent;
            }

            // Add body component (required)
            if (! empty($data['body'])) {
                $bodyComponent = [
                    'type' => 'BODY',
                    'text' => $data['body'],
                ];

                // Add body parameters example if there are variables
                if (preg_match_all('/\{\{\d+\}\}/', $data['body'], $matches)) {
                    $paramCount = count(array_unique($matches[0]));
                    $examples = [];
                    for ($i = 1; $i <= $paramCount; $i++) {
                        $examples[] = "Sample Value {$i}";
                    }
                    $bodyComponent['example'] = [
                        'body_text' => [$examples],
                    ];
                }

                $components[] = $bodyComponent;
            }

            // Add footer component if provided
            if (! empty($data['footer'])) {
                $components[] = [
                    'type' => 'FOOTER',
                    'text' => $data['footer'],
                ];
            }

            // Add buttons component if provided
            if (! empty($data['buttons']) && is_array($data['buttons'])) {
                $buttonsComponent = [
                    'type' => 'BUTTONS',
                    'buttons' => [],
                ];

                foreach ($data['buttons'] as $button) {
                    $buttonData = [
                        'type' => $button['type'] ?? 'QUICK_REPLY',
                    ];

                    if ($button['type'] === 'QUICK_REPLY') {
                        $buttonData['text'] = $button['text'] ?? '';
                    } elseif ($button['type'] === 'PHONE_NUMBER') {
                        $buttonData['text'] = $button['text'] ?? '';
                        $buttonData['phone_number'] = $button['phone_number'] ?? '';
                    } elseif ($button['type'] === 'URL') {
                        $buttonData['text'] = $button['text'] ?? '';
                        $buttonData['url'] = $button['url'] ?? '';

                        // Check if URL has variables
                        if (preg_match('/\{\{\d+\}\}/', $button['url'])) {
                            $buttonData['example'] = ['https://example.com/sample'];
                        }
                    } elseif ($button['type'] === 'COPY_CODE') {
                        $buttonData['text'] = $button['text'] ?? '';
                        $buttonData['copy_code'] = $button['copy_code'] ?? '';
                    }

                    $buttonsComponent['buttons'][] = $buttonData;
                }

                $components[] = $buttonsComponent;
            }

            // Prepare the request payload
            $payload = [
                'name' => $templateData['template_name'],
                'language' => $templateData['language'] ?? 'en_US',
                'category' => $templateData['category'] ?? 'MARKETING',
                'components' => $components,
            ];

            // Add allow_category_change if specified
            if (isset($templateData['allow_category_change'])) {
                $payload['allow_category_change'] = $templateData['allow_category_change'];
            }

            // Debug: Log the payload before sending
            whatsapp_log('Template creation payload', 'debug', [
                'template_name' => $templateData['template_name'],
                'payload' => $payload,
                'components_count' => count($components),
                'tenant_id' => $tenant_id,
            ], null, $tenant_id);

            $response = Http::withToken($accessToken)->post($url, $payload);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Failed to create template';

                whatsapp_log('Template creation failed', 'error', [
                    'template_name' => $templateData['template_name'],
                    'response_code' => $response->status(),
                    'error_message' => $errorMessage,
                    'full_error' => $errorData,
                    'payload' => $payload,
                    'tenant_id' => $tenant_id,
                ], null, $tenant_id);

                return [
                    'status' => false,
                    'message' => "Template creation failed: {$errorMessage}",
                    'error_code' => $response->status(),
                    'error_details' => $errorData,
                ];
            }

            $responseData = $response->json();

            whatsapp_log('Template created successfully', 'info', [
                'template_name' => $templateData['template_name'],
                'template_id' => $responseData['id'] ?? 'unknown',
                'status' => $responseData['status'] ?? 'unknown',
                'tenant_id' => $tenant_id,
            ], null, $tenant_id);

            return [
                'status' => true,
                'message' => 'Template created successfully',
                'data' => $responseData,
                'template_id' => $responseData['id'] ?? null,
                'template_status' => $responseData['status'] ?? 'PENDING',
            ];
        } catch (\Exception $e) {
            whatsapp_log('Template creation exception', 'error', [
                'template_name' => $templateData['template_name'] ?? 'unknown',
                'error' => $e->getMessage(),
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);

            return [
                'status' => false,
                'message' => 'Template creation failed: '.$e->getMessage(),
                'error_details' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update an existing WhatsApp template in Meta (Fixed version)
     *
     * @param  string  $templateId  Template ID to update
     * @param  array  $templateData  Data to update
     * @return array Response containing status and updated data
     */
    public function updateTemplate(string $templateId, array $templateData): array
    {
        $tenant_id = $this->getWaTenantId();
        $accessToken = $this->getToken();

        try {
            // First, try to get the template from local database to get Meta template ID
            $localTemplate = null;
            $metaTemplateId = $templateId;

            // Check if this is a local database ID (numeric) vs Meta template ID
            if (is_numeric($templateId)) {
                $localTemplate = WhatsappTemplate::where('template_id', $templateId)
                    ->where('tenant_id', $tenant_id)
                    ->first();

                if (! $localTemplate || ! $localTemplate->template_id) {
                    return [
                        'status' => false,
                        'message' => 'Template not found or missing Meta template ID',
                    ];
                }

                $metaTemplateId = $localTemplate->template_id;
            } else {
                // If it's already a Meta template ID, get the local template
                $localTemplate = WhatsappTemplate::where('template_id', $templateId)
                    ->where('tenant_id', $tenant_id)
                    ->first();
            }

            // Check if template can be edited (only certain statuses can be edited)
            if ($localTemplate && in_array($localTemplate->status, ['REJECTED', 'DISABLED'])) {
                return [
                    'status' => false,
                    'message' => ucfirst(strtolower($localTemplate->status)).' templates cannot be edited. Please create a new template.',
                    'error_code' => 'TEMPLATE_NOT_EDITABLE',
                ];
            }

            // Use the correct Meta API endpoint for template updates
            // The endpoint should be the template ID directly, not account/template_id
            $url = self::getBaseUrl().$metaTemplateId;

            // Build template components from the incoming data structure
            $components = [];
            $data = $templateData['data'] ?? [];

            // Add header component if provided
            if (! empty($data['header'])) {
                $headerComponent = [
                    'type' => 'HEADER',
                ];

                if ($data['header']['type'] === 'TEXT') {
                    $headerComponent['format'] = 'TEXT';
                    $headerComponent['text'] = $data['header']['text'];

                    // Add header example if there are variables
                    if (preg_match('/\{\{\d+\}\}/', $data['header']['text'])) {
                        $headerComponent['example'] = [
                            'header_text' => ['Sample Header Value'],
                        ];
                    }
                } elseif (in_array($data['header']['type'], ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                    $headerComponent['format'] = $data['header']['type'];

                    $mediaUrl = $data['header']['media_url'] ?? null;

                    if (! empty($mediaUrl)) {
                        // Upload media and get handle for updates too
                        $uploadedHandle = $this->uploadResumableMediaForTemplate($mediaUrl);

                        if ($uploadedHandle) {
                            $headerComponent['example'] = [
                                'header_handle' => [$uploadedHandle],
                            ];

                            whatsapp_log('Using upload handle for template update', 'info', [
                                'upload_handle' => $uploadedHandle,
                                'media_url' => $mediaUrl,
                                'template_id' => $metaTemplateId,
                            ], null, $tenant_id);
                        } else {
                            // If upload fails, we cannot update with IMAGE/VIDEO/DOCUMENT header
                            return [
                                'status' => false,
                                'message' => 'Failed to upload media for template header update. Media upload is required for '.$data['header']['type'].' headers.',
                                'error_details' => 'Media upload failed',
                            ];
                        }
                    }
                }

                $components[] = $headerComponent;
            }

            // Add body component (required)
            if (! empty($data['body'])) {
                $bodyComponent = [
                    'type' => 'BODY',
                    'text' => $data['body'],
                ];

                // Add body parameters example if there are variables
                if (preg_match_all('/\{\{\d+\}\}/', $data['body'], $matches)) {
                    $paramCount = count(array_unique($matches[0]));
                    $examples = [];
                    for ($i = 1; $i <= $paramCount; $i++) {
                        $examples[] = "Sample Value {$i}";
                    }
                    $bodyComponent['example'] = [
                        'body_text' => [$examples],
                    ];
                }

                $components[] = $bodyComponent;
            }

            // Add footer component if provided
            if (! empty($data['footer'])) {
                $components[] = [
                    'type' => 'FOOTER',
                    'text' => $data['footer'],
                ];
            }

            // Add buttons component if provided
            if (! empty($data['buttons']) && is_array($data['buttons'])) {
                $buttonsComponent = [
                    'type' => 'BUTTONS',
                    'buttons' => [],
                ];

                foreach ($data['buttons'] as $button) {
                    $buttonData = [
                        'type' => $button['type'] ?? 'QUICK_REPLY',
                    ];

                    if ($button['type'] === 'QUICK_REPLY') {
                        $buttonData['text'] = $button['text'] ?? '';
                    } elseif ($button['type'] === 'PHONE_NUMBER') {
                        $buttonData['text'] = $button['text'] ?? '';
                        $buttonData['phone_number'] = $button['phone_number'] ?? '';
                    } elseif ($button['type'] === 'URL') {
                        $buttonData['text'] = $button['text'] ?? '';
                        $buttonData['url'] = $button['url'] ?? '';

                        // Check if URL has variables
                        if (preg_match('/\{\{\d+\}\}/', $button['url'])) {
                            $buttonData['example'] = ['https://example.com/sample'];
                        }
                    } elseif ($button['type'] === 'COPY_CODE') {
                        $buttonData['text'] = $button['text'] ?? '';
                        $buttonData['copy_code'] = $button['copy_code'] ?? '';
                    }

                    $buttonsComponent['buttons'][] = $buttonData;
                }

                $components[] = $buttonsComponent;
            }

            // Prepare the update payload - Following WhatsJet pattern
            $payload = [
                'components' => $components,
            ];

            // For updates, we can also include the template name if it's being changed
            if (isset($templateData['template_name'])) {
                $payload['name'] = $templateData['template_name'];
            }

            // Category can be updated too
            if (isset($templateData['category'])) {
                $payload['category'] = $templateData['category'];
            }

            // Debug: Log the payload before sending
            whatsapp_log('Template update payload', 'debug', [
                'template_id' => $metaTemplateId,
                'payload' => $payload,
                'components_count' => count($components),
                'tenant_id' => $tenant_id,
            ], null, $tenant_id);

            // Send the update request to Meta
            $response = Http::withToken($accessToken)->post($url, $payload);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Failed to update template';

                whatsapp_log('Template update failed', 'error', [
                    'template_id' => $metaTemplateId,
                    'response_code' => $response->status(),
                    'error_message' => $errorMessage,
                    'payload' => $payload,
                    'full_error' => $errorData,
                    'tenant_id' => $tenant_id,
                ], null, $tenant_id);

                return [
                    'status' => false,
                    'message' => "Template update failed: {$errorMessage}",
                    'error_code' => $response->status(),
                    'error_details' => $errorData,
                ];
            }

            $responseData = $response->json();

            // Update local database with the updated template data
            if ($localTemplate) {
                $updateData = [
                    'status' => $responseData['status'] ?? 'PENDING',
                    'updated_at' => now(),
                ];

                // Update name and category if they were provided
                if (isset($templateData['template_name'])) {
                    $updateData['template_name'] = $templateData['template_name'];
                }
                if (isset($templateData['category'])) {
                    $updateData['category'] = $templateData['category'];
                }
                if (isset($templateData['language'])) {
                    $updateData['language'] = $templateData['language'];
                }

                $localTemplate->update($updateData);
            }

            whatsapp_log('Template updated successfully', 'info', [
                'template_id' => $metaTemplateId,
                'template_name' => $templateData['template_name'] ?? $localTemplate->template_name ?? 'unknown',
                'status' => $responseData['status'] ?? 'unknown',
                'tenant_id' => $tenant_id,
            ], null, $tenant_id);

            return [
                'status' => true,
                'message' => 'Template updated successfully',
                'data' => $responseData,
                'template_id' => $metaTemplateId,
                'template_status' => $responseData['status'] ?? 'PENDING',
            ];
        } catch (\Exception $e) {
            whatsapp_log('Template update exception', 'error', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);

            return [
                'status' => false,
                'message' => 'Template update failed: '.$e->getMessage(),
                'error_details' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get template details from Meta
     *
     * @param  string  $templateId  Template ID
     * @return array Template details
     */
    public function getTemplate(string $templateId): array
    {
        $tenant_id = $this->getWaTenantId();
        $accessToken = $this->getToken();
        $accountId = $this->getAccountID();

        try {
            $url = self::getBaseUrl()."{$accountId}/message_templates/{$templateId}";

            $response = Http::withToken($accessToken)->get($url);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Failed to get template';

                whatsapp_log('Get template failed', 'error', [
                    'template_id' => $templateId,
                    'response_code' => $response->status(),
                    'error_message' => $errorMessage,
                    'tenant_id' => $tenant_id,
                ], null, $tenant_id);

                return [
                    'status' => false,
                    'message' => "Failed to get template: {$errorMessage}",
                    'error_code' => $response->status(),
                ];
            }

            $templateData = $response->json();

            return [
                'status' => true,
                'data' => $templateData,
                'message' => 'Template retrieved successfully',
            ];
        } catch (\Exception $e) {
            whatsapp_log('Get template exception', 'error', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);

            return [
                'status' => false,
                'message' => 'Failed to get template: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Update template Meta information (template_id and status) in local database
     *
     * @param  int  $localId  Local database template ID (primary key)
     * @param  string  $metaTemplateId  Meta template ID to store in template_id column
     * @param  string  $status  Template status from Meta
     */
    protected function updateTemplateMetaInfo(int $localId, string $metaTemplateId, string $status): void
    {
        $tenant_id = $this->getWaTenantId();

        try {
            $updated = WhatsappTemplate::where('id', $localId)
                ->where('tenant_id', $tenant_id)
                ->update([
                    'template_id' => $metaTemplateId,
                    'status' => $status,
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            whatsapp_log('Failed to update template Meta info in database', 'error', [
                'local_id' => $localId,
                'meta_template_id' => $metaTemplateId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenant_id,
            ], $e, $tenant_id);
        }
    }
}
