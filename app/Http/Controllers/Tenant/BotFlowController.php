<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\BotFlow;
use Corbital\ModuleManager\Facades\ModuleManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BotFlowController extends Controller
{
    public function edit($subdomain, $id)
    {

        $flow = BotFlow::where('tenant_id', tenant_id())->findOrFail($id);
        $isAiAssistantModuleEnabled = ModuleManager::isActive('AiAssistant');

        return view('tenant.bot-flows.edit', compact('flow', 'isAiAssistantModuleEnabled'));
    }

    public function upload(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'file' => 'required|file',
                'type' => 'required|string|in:image,video,audio,document',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Get the file
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());

            // Define allowed extensions for each media type
            $allowedExtensions = get_meta_allowed_extension();

            // Check if extension is allowed for this media type
            if (! isset($allowedExtensions[$request->type]['extension']) || ! in_array('.'.$extension, explode(', ', $allowedExtensions[$request->type]['extension']))) {
                return response()->json([
                    'message' => "Invalid file type. Allowed types for {$request->type} are: ".$allowedExtensions[$request->type]['extension'],
                ], 422);
            }

            // Generate a unique filename
            $filename = Str::uuid().'.'.$extension;

            // Define the storage path based on media type
            $path = "bot_flow/{$request->type}s"; // e.g., images, videos, audios, documents

            // Store the file in the public disk
            $storedPath = $file->storeAs($path, $filename, 'public');

            // Verify the file was stored successfully
            if (! $storedPath) {
                Log::error('File upload failed', [
                    'original_filename' => $file->getClientOriginalName(),
                    'path' => $path,
                    'filename' => $filename,
                ]);

                return response()->json([
                    'message' => 'File upload failed',
                ], 500);
            }

            // Generate the public URL for the file
            $url = Storage::disk('public')->url($storedPath);

            // Return the URL to the frontend
            return response()->json([
                'url' => $url,
                'fileName' => $file->getClientOriginalName(),
            ]);
        } catch (\Exception $e) {
            // Log the full error for server-side debugging
            Log::error('File upload error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return a detailed error response
            return response()->json([
                'message' => 'An error occurred during file upload',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function save(Request $request, $subdomain)
    {

        $validator = Validator::make($request->all(), [
            'flow_data' => 'required|json',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $flowData = [
            'name' => $request->name,
            'flow_data' => $request->flow_data,
            'is_active' => $request->has('is_active') ? 1 : 0,
        ];
        if ($request->id) {
            $flow = BotFlow::where('tenant_id', tenant_id())->findOrFail($request->id);
            $flow->update($request->all());
            $message = t('flow_updated_successfully');
        } else {
            $flow = BotFlow::create($flowData);
            $message = t('flow_created_successfully');
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'flow_id' => $flow->id,
        ]);
    }

    public function get($subdomain, $id)
    {

        $flow = BotFlow::where('tenant_id', tenant_id())->findOrFail($id);

        return response()->json([
            'success' => true,
            'flow' => json_decode($flow->flow_data),
        ]);
    }

    public function delete($id)
    {
        if (! checkPermission(['bot_flows.delete'])) {
            return response()->json([
                'success' => false,
                'message' => t('access_denied'),
            ], 403);
        }

        $flow = BotFlow::where('tenant_id', tenant_id())->findOrFail($id);
        $flow->delete();

        return response()->json([
            'success' => true,
            'message' => t('flow_deleted_successfully'),
        ]);
    }
}
