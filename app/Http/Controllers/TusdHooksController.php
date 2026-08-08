<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\File;
use App\Models\UploadSession;
use App\Utils\FileHelper;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use App\Services\SettingsService;

class TusdHooksController extends Controller
{
    /**
     * Handle incoming tusd hook requests
     * tusd sends different hook types: pre-create, post-create, post-receive, post-finish, post-terminate
     *
     * Security: This endpoint should only be called by the tusd process.
     * We validate the request comes from localhost or internal Docker network IPs.
     */
    public function handleHook(Request $request)
    {
        // Security: Verify request is from internal network (tusd process)
        $clientIp = $request->ip();
        $allowedNetworks = [
            '172.', // Docker bridge networks
            '10.',  // Private network
            '192.168.', // Private network
            '127.0.0.1', // Localhost IPv4
            '::1', // Localhost IPv6
        ];

        $isAllowed = false;
        foreach ($allowedNetworks as $network) {
            if (str_starts_with($clientIp, $network)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            Log::warning('tusd hook rejected: unauthorized source IP', [
                'ip' => $clientIp
            ]);
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $payload = $request->all();
        // Hook type is in the payload's Type field, not in headers
        $hookName = $payload['Type'] ?? null;

        Log::debug('tusd hook received', [
            'hook' => $hookName
        ]);

        switch ($hookName) {
            case 'pre-create':
                return $this->preCreate($request, $payload);
            case 'post-create':
                return $this->postCreate($request, $payload);
            case 'post-finish':
                return $this->postFinish($request, $payload);
            case 'post-terminate':
                return $this->postTerminate($request, $payload);
            default:
                // Other hooks (post-receive) - just acknowledge
                return response()->json(['ok' => true]);
        }
    }

    /**
     * Pre-create hook: Validate JWT, check user permissions, and enforce max upload size
     * Note: Upload ID is not available yet at this stage
     */
    protected function preCreate(Request $request, array $payload)
    {
        try {
            // Extract authorization header from the upload request metadata
            $authHeader = $payload['Event']['HTTPRequest']['Header']['Authorization'][0] ?? null;

            if (!$authHeader) {
                Log::warning('tusd pre-create: No authorization header');
                return $this->rejectUpload(401, 'Unauthorized: No authorization header');
            }

            // Extract token from "Bearer <token>"
            $token = str_replace('Bearer ', '', $authHeader);

            // Validate the JWT token
            $user = JWTAuth::setToken($token)->authenticate();

            if (!$user) {
                Log::warning('tusd pre-create: Invalid token');
                return $this->rejectUpload(401, 'Unauthorized: Invalid token');
            }

            $upload = $payload['Event']['Upload'] ?? [];
            $isPartial = $upload['IsPartial'] ?? false;
            $fileSize = $upload['Size'] ?? 0;
            $metadata = $upload['MetaData'] ?? [];

            $settingsService = app(SettingsService::class);
            $maxUploadSize = $settingsService->getMaxUploadSize();

            if ($maxUploadSize) {
                // For partial uploads (created by parallel/concatenated uploads) tusd
                // only reports the size of this single part. The full file size is sent
                // by the client in the upload metadata so the per-file limit can be
                // enforced before all parts have been transferred.
                $effectiveSize = $fileSize;
                if ($isPartial && isset($metadata['filesize']) && is_numeric($metadata['filesize'])) {
                    $effectiveSize = (int) $metadata['filesize'];
                }

                // Check individual file size
                if ($effectiveSize > $maxUploadSize) {
                    $maxSizeFormatted = $this->formatBytes($maxUploadSize);
                    Log::warning('tusd pre-create: File exceeds max upload size', [
                        'user_id' => $user->id,
                        'file_size' => $effectiveSize,
                        'max_size' => $maxUploadSize
                    ]);
                    return $this->rejectUpload(413, "File size exceeds maximum allowed size of {$maxSizeFormatted}");
                }

                // Check cumulative size of all staged uploads for this user.
                // This prevents malicious users from bypassing frontend validation
                // by uploading multiple files that together exceed the limit.
                //
                // Partial uploads are NOT tracked as upload sessions (see postCreate)
                // and the final concatenated upload reports the full file size, so
                // every staged byte is counted exactly once here. Checking partial
                // uploads as well would count the same bytes multiple times.
                if (!$isPartial) {
                    $pendingUploadsSize = UploadSession::where('user_id', $user->id)
                        ->whereIn('status', ['pending', 'complete'])
                        ->sum('filesize');

                    $totalSizeAfterUpload = $pendingUploadsSize + $effectiveSize;

                    if ($totalSizeAfterUpload > $maxUploadSize) {
                        $maxSizeFormatted = $this->formatBytes($maxUploadSize);
                        $currentSizeFormatted = $this->formatBytes($pendingUploadsSize);

                        Log::warning('tusd pre-create: Cumulative upload size exceeds max', [
                            'user_id' => $user->id,
                            'file_size' => $effectiveSize,
                            'pending_size' => $pendingUploadsSize,
                            'total_would_be' => $totalSizeAfterUpload,
                            'max_size' => $maxUploadSize
                        ]);

                        // Only reject this upload. Do NOT delete the user's other
                        // staged uploads here: destroying them used to kill entire
                        // in-progress batches. Stale uploads expire via maintainDb.
                        return $this->rejectUpload(413, "Total upload size would exceed maximum allowed size of {$maxSizeFormatted}. Current staged uploads: {$currentSizeFormatted}.");
                    }
                }
            }

            Log::info('tusd pre-create: Upload authorized', [
                'user_id' => $user->id,
                'file_size' => $fileSize
            ]);

            return response()->json(['ok' => true]);

        } catch (JWTException $e) {
            // Invalid or expired token - reject cleanly. Retrying would never succeed.
            Log::warning('tusd pre-create: JWT error', [
                'error' => $e->getMessage()
            ]);
            return $this->rejectUpload(401, 'Unauthorized: ' . $e->getMessage());

        } catch (\Exception $e) {
            // Unexpected internal error: respond with non-2XX so tusd treats this as
            // an internal hook failure (hook is retried, then a 500 is sent to the
            // client which tus clients may retry - appropriate for transient errors).
            Log::error('tusd pre-create error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Internal error while validating upload'
            ], 500);
        }
    }

    /**
     * Build a hook response that rejects the upload with a meaningful error.
     *
     * tusd only honours hook responses with a 2XX status code; a non-2XX hook
     * response is treated as an internal hook failure and surfaced to the client
     * as a generic 500 (which tus clients blindly retry without ever showing the
     * real reason). Returning 200 with RejectUpload + HTTPResponse makes tusd
     * forward the given status and body to the client immediately instead.
     */
    private function rejectUpload(int $status, string $message)
    {
        return response()->json([
            'HTTPResponse' => [
                'StatusCode' => $status,
                'Body' => json_encode(['message' => $message]),
                'Header' => [
                    'Content-Type' => 'application/json',
                ],
            ],
            'RejectUpload' => true,
        ]);
    }

    /**
     * Post-create hook: Create upload session after tusd has assigned an ID
     */
    protected function postCreate(Request $request, array $payload)
    {
        try {
            $upload = $payload['Event']['Upload'] ?? [];

            // Partial uploads (created by parallel/concatenated uploads) are only
            // temporary parts of a larger file and are never referenced by the
            // client. They must not be tracked as upload sessions: the final
            // concatenated upload gets its own session, so tracking partials would
            // count the same bytes multiple times and leak sessions indefinitely.
            if ($upload['IsPartial'] ?? false) {
                return response()->json(['ok' => true]);
            }

            // Extract authorization header to get user
            $authHeader = $payload['Event']['HTTPRequest']['Header']['Authorization'][0] ?? null;
            $token = str_replace('Bearer ', '', $authHeader);
            $user = JWTAuth::setToken($token)->authenticate();

            // Get metadata from the upload
            $metadata = $upload['MetaData'] ?? [];
            $filename = $metadata['filename'] ?? 'unknown';
            $filesize = $upload['Size'] ?? 0;
            $filetype = $metadata['filetype'] ?? 'application/octet-stream';
            $uploadId = $upload['ID'] ?? null;

            // Security: Validate upload ID is a safe hex string
            if (!$uploadId || !preg_match('/^[a-f0-9]+$/i', $uploadId)) {
                Log::warning('tusd post-create: Invalid upload ID format', [
                    'upload_id' => $uploadId
                ]);
                return response()->json(['ok' => true]);
            }

            // Create an upload session to track this upload
            $session = UploadSession::create([
                'upload_id' => $uploadId,
                'user_id' => $user->id,
                'filename' => $filename,
                'filesize' => $filesize,
                'filetype' => $filetype,
                'total_chunks' => 1, // tusd handles chunking internally
                'chunks_received' => 0,
                'status' => 'pending'
            ]);

            Log::info('tusd post-create: Upload session created', [
                'user_id' => $user->id,
                'upload_id' => $uploadId,
                'filename' => $filename
            ]);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd post-create error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ok' => true]); // Don't fail the upload
        }
    }

    /**
     * Post-finish hook: Create File record in database after upload completes
     */
    protected function postFinish(Request $request, array $payload)
    {
        try {
            $upload = $payload['Event']['Upload'] ?? [];

            // Partial uploads (from parallel/concatenated uploads) are not tracked
            // as upload sessions (see postCreate), so there is nothing to do. Skipping
            // them here also avoids creating orphaned File records for partial data.
            if ($upload['IsPartial'] ?? false) {
                return response()->json(['ok' => true]);
            }

            $uploadId = $upload['ID'] ?? null;

            // Security: Validate upload ID is a safe hex string (tusd generates 32-char hex IDs)
            if (!$uploadId || !preg_match('/^[a-f0-9]+$/i', $uploadId)) {
                Log::warning('tusd post-finish: Invalid upload ID format', [
                    'upload_id' => $uploadId
                ]);
                return response()->json(['ok' => true]);
            }

            $metadata = $upload['MetaData'] ?? [];
            $filename = $metadata['filename'] ?? 'unknown';
            $filesize = $upload['Size'] ?? 0;
            $filetype = $metadata['filetype'] ?? 'application/octet-stream';
            $isBundle = ($metadata['isBundle'] ?? 'false') === 'true';

            // Find the upload session
            // For very small/fast uploads, post-finish may arrive before post-create completes
            // So we retry a few times with a small delay
            $session = null;
            $maxRetries = 10;
            for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                $session = UploadSession::where('upload_id', $uploadId)->first();
                if ($session) {
                    break;
                }
                if ($attempt < $maxRetries - 1) {
                    usleep(100000); // 100ms
                }
            }

            if (!$session) {
                Log::warning('tusd post-finish: Upload session not found after retries', [
                    'upload_id' => $uploadId,
                    'attempts' => $maxRetries
                ]);
                return response()->json(['ok' => true]);
            }

            // Handle bundle uploads differently
            if ($isBundle) {
                return $this->handleBundleUpload($uploadId, $session, $metadata);
            }

            // Sanitize filename for storage
            $sanitizedFilename = FileHelper::sanitizeFilename($filename);

            // Create file record
            $file = File::create([
                'name' => $sanitizedFilename,
                'original_name' => $filename,
                'type' => $filetype,
                'size' => $filesize,
                'temp_path' => 'uploads/' . $uploadId // Path relative to storage/app
            ]);

            // Update session
            $session->status = 'complete';
            $session->chunks_received = 1;
            $session->file_id = $file->id;
            $session->save();

            Log::info('tusd post-finish: File record created', [
                'file_id' => $file->id,
                'upload_id' => $uploadId,
                'filename' => $filename
            ]);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd post-finish error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ok' => true]); // Don't fail the upload
        }
    }

    /**
     * Handle a bundle upload - extract the zip and create File records for each file
     */
    protected function handleBundleUpload(string $uploadId, UploadSession $session, array $metadata)
    {
        try {
            $bundlePath = storage_path('app/uploads/' . $uploadId);
            $extractDir = storage_path('app/uploads/' . $uploadId . '_extracted');

            Log::info('tusd post-finish: Processing bundle upload', [
                'upload_id' => $uploadId,
                'bundle_path' => $bundlePath
            ]);

            // Create extraction directory
            if (!file_exists($extractDir)) {
                mkdir($extractDir, 0755, true);
            }

            // Open and extract the zip
            $zip = new \ZipArchive();
            $result = $zip->open($bundlePath);

            if ($result !== true) {
                Log::error('tusd post-finish: Failed to open bundle zip', [
                    'upload_id' => $uploadId,
                    'error_code' => $result
                ]);
                $session->status = 'failed';
                $session->save();
                return response()->json(['ok' => true]);
            }

            // Read the manifest
            $manifestContent = $zip->getFromName('__erugo_manifest__.json');
            if (!$manifestContent) {
                Log::error('tusd post-finish: Bundle manifest not found', [
                    'upload_id' => $uploadId
                ]);
                $zip->close();
                $session->status = 'failed';
                $session->save();
                return response()->json(['ok' => true]);
            }

            $manifest = json_decode($manifestContent, true);
            if (!$manifest || !isset($manifest['files'])) {
                Log::error('tusd post-finish: Invalid bundle manifest', [
                    'upload_id' => $uploadId
                ]);
                $zip->close();
                $session->status = 'failed';
                $session->save();
                return response()->json(['ok' => true]);
            }

            // Extract all files
            $zip->extractTo($extractDir);
            $zip->close();

            // Delete the manifest file from extracted directory
            $manifestPath = $extractDir . '/__erugo_manifest__.json';
            if (file_exists($manifestPath)) {
                unlink($manifestPath);
            }

            // Create File records for each file in the manifest
            $fileIds = [];
            foreach ($manifest['files'] as $fileInfo) {
                $filePath = $fileInfo['path'];

                // Sanitize the file path to prevent path traversal attacks
                // Remove ../ sequences and normalize path
                $safePath = $this->sanitizeBundlePath($filePath);
                if ($safePath === null) {
                    Log::warning('tusd post-finish: Skipping file with dangerous path', [
                        'upload_id' => $uploadId,
                        'file_path' => $filePath
                    ]);
                    continue;
                }

                $extractedFilePath = $extractDir . '/' . $safePath;

                // Verify the resolved path is within the extraction directory
                $resolvedPath = realpath($extractedFilePath);
                $resolvedExtractDir = realpath($extractDir);

                if ($resolvedPath === false || $resolvedExtractDir === false ||
                    ($resolvedPath !== $resolvedExtractDir && strpos($resolvedPath, $resolvedExtractDir . DIRECTORY_SEPARATOR) !== 0)) {
                    Log::warning('tusd post-finish: Path traversal attempt in bundle', [
                        'upload_id' => $uploadId,
                        'file_path' => $filePath,
                        'resolved_path' => $resolvedPath
                    ]);
                    continue;
                }

                if (!file_exists($extractedFilePath)) {
                    Log::warning('tusd post-finish: Bundle file not found after extraction', [
                        'upload_id' => $uploadId,
                        'file_path' => $safePath
                    ]);
                    continue;
                }

                $sanitizedFilename = FileHelper::sanitizeFilename($fileInfo['originalName']);

                // Create file record - store relative path for bundle files
                $file = File::create([
                    'name' => $sanitizedFilename,
                    'original_name' => $fileInfo['originalName'],
                    'type' => $fileInfo['type'] ?? 'application/octet-stream',
                    'size' => $fileInfo['size'],
                    'temp_path' => 'uploads/' . $uploadId . '_extracted/' . $safePath
                ]);

                $fileIds[] = $file->id;
            }

            // Update session with bundle info
            // We store the file IDs as JSON in a special way - the first file_id points to a pseudo-record
            // and we store the actual IDs in the session metadata
            $session->status = 'complete';
            $session->chunks_received = 1;
            $session->is_bundle = true;
            $session->bundle_file_ids = json_encode($fileIds);
            $session->save();

            // Delete the original zip file to save space
            if (file_exists($bundlePath)) {
                unlink($bundlePath);
            }
            // Also delete the .info file
            $infoPath = $bundlePath . '.info';
            if (file_exists($infoPath)) {
                unlink($infoPath);
            }

            Log::info('tusd post-finish: Bundle extracted successfully', [
                'upload_id' => $uploadId,
                'file_count' => count($fileIds)
            ]);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd post-finish: Bundle extraction error', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $session->status = 'failed';
            $session->save();

            return response()->json(['ok' => true]);
        }
    }

    /**
     * Post-terminate hook: Clean up when upload is cancelled
     */
    protected function postTerminate(Request $request, array $payload)
    {
        try {
            $uploadId = $payload['Event']['Upload']['ID'] ?? null;

            // Security: Validate upload ID is a safe hex string
            if (!$uploadId || !preg_match('/^[a-f0-9]+$/i', $uploadId)) {
                Log::warning('tusd post-terminate: Invalid upload ID format', [
                    'upload_id' => $uploadId
                ]);
                return response()->json(['ok' => true]);
            }

            // Find and delete the upload session
            $session = UploadSession::where('upload_id', $uploadId)->first();

            if ($session) {
                // If a file was created, delete it
                if ($session->file_id) {
                    $file = File::find($session->file_id);
                    if ($file) {
                        $file->delete();
                    }
                }
                $session->delete();

                Log::info('tusd post-terminate: Upload session cleaned up', [
                    'upload_id' => $uploadId
                ]);
            }

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('tusd post-terminate error', [
                'error' => $e->getMessage()
            ]);

            return response()->json(['ok' => true]);
        }
    }

    /**
     * Sanitize a bundle file path to prevent path traversal attacks
     * Returns null if the path is dangerous and should be skipped
     */
    private function sanitizeBundlePath(string $path): ?string
    {
        // Normalize directory separators
        $path = str_replace('\\', '/', $path);

        // Check for dangerous patterns before sanitization
        if (strpos($path, '..') !== false) {
            return null;
        }

        // Remove leading slashes
        $path = ltrim($path, '/');

        // Remove null bytes
        $path = str_replace("\0", '', $path);

        // Clean up double slashes
        $path = preg_replace('/\/+/', '/', $path);

        // If empty after sanitization, reject
        if (empty($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Format bytes into human readable format
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) { // 1 GB
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) { // 1 MB
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) { // 1 KB
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}

