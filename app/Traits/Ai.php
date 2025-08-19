<?php

namespace App\Traits;

use App\Exceptions\WhatsAppException;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;
use OpenAI;

trait Ai
{
    /**
     * Store AI settings to avoid multiple database calls
     */
    protected $aiSettings;

    /**
     * Load all AI settings in a single batch call
     */
    protected function loadAiSettings()
    {
        if (! isset($this->aiSettings)) {
            $this->aiSettings = get_batch_settings([
                'whats-mark.chat_model',
            ]);
        }

        return $this->aiSettings;
    }

    public function listModel(): array
    {
        try {
            $openAiKey = $this->getOpenAiKey();
            $openAi = new OpenAI;
            $client = $openAi->client($openAiKey);
            $response = $client->models()->list();

            if ($response === null || ! is_object($response)) {
                throw new \RuntimeException('Invalid response format from OpenAI API.');
            }

            if (property_exists($response, 'error')) {
                save_tenant_setting('whats-mark', 'is_open_ai_key_verify', false);

                return [
                    'status' => false,
                    'message' => $response->error->message ?? 'Unknown error occurred.',
                ];
            }

            save_tenant_setting('whats-mark', 'is_open_ai_key_verify', true);

            return [
                'status' => true,
                'data' => 'Model list fetched successfully.',
            ];
        } catch (\Throwable $th) {
            save_tenant_setting('whats-mark', 'is_open_ai_key_verify', false);
            whatsapp_log('OpenAI Model List Error', 'error', [
                'error' => $th->getMessage(),
            ], $th);

            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
            throw new WhatsAppException($th->getMessage());
        }
    }

    /**
     * Sends a request to the OpenAI API to get a response based on provided data.
     *
     * @param  array  $data  The data to be sent to the OpenAI API.
     * @return array Contains status and message of the response.
     */
    public function aiResponse(array $data)
    {
        try {
            $config = new OpenAIConfig;
            $config->apiKey = $this->getOpenAiKey();

            // Load settings and use from batch
            $this->loadAiSettings();
            $config->model = $this->aiSettings['whats-mark.chat_model'] ?? 'gpt-3.5-turbo';

            $chat = new OpenAIChat($config);
            $message = $data['input_msg'];
            $menuItem = $data['menu'];
            $submenuItem = $data['submenu'];
            $status = true;

            $prompt = match ($menuItem) {
                'Simplify Language' => 'You will be provided with statements, and your task is to convert them to Simplify Language. but don\'t change inputed language.',
                'Fix Spelling & Grammar' => 'You will be provided with statements, and your task is to convert them to standard Language. but don\'t change inputed language.',
                'Translate' => 'You will be provided with a sentence, and your task is to translate it into '.$submenuItem.', only give translated sentence',
                'Change Tone' => 'You will be provided with statements, and your task is to change tone into '.$submenuItem.'. but don\'t change inputed language.',
                'Custom Prompt' => $submenuItem,
            };

            $messages = [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $message],
            ];

            // Send the structured messages to OpenAI's chat API
            $response = $chat->generateChat($messages);
        } catch (\Throwable $th) {
            whatsapp_log('OpenAI Chat Generation Error', 'error', [
                'error' => $th->getMessage(),
            ], $th);

            $status = false;
            $message = $th->getMessage();
        }

        return [
            'status' => $status,
            'message' => $status ? $response : $message,
        ];
    }

    /**
     * Retrieves the OpenAI API key from the options.
     *
     * @return string|null The OpenAI API key.
     */
    public function getOpenAiKey()
    {
        return get_tenant_setting_from_db('whats-mark', 'openai_secret_key');
    }
}
