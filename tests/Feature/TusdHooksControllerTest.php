<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Setting;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class TusdHooksControllerTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_SIZE = 1073741824; // 1 GB (max_share_size=1, unit=GB)

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => str_repeat('test-jwt-secret-', 4)]);

        Setting::create(['key' => 'max_share_size', 'value' => '1', 'group' => 'system.shares']);
        Setting::create(['key' => 'max_share_size_unit', 'value' => 'GB', 'group' => 'system.shares']);
        app(SettingsService::class)->clearCache();
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }

    private function hookPayload(string $type, array $uploadOverrides = [], ?string $token = null): array
    {
        return [
            'Type' => $type,
            'Event' => [
                'Upload' => array_merge([
                    'ID' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
                    'Size' => 1024,
                    'SizeIsDeferred' => false,
                    'Offset' => 0,
                    'MetaData' => ['filename' => 'video.mp4', 'filetype' => 'video/mp4'],
                    'IsPartial' => false,
                    'IsFinal' => false,
                    'PartialUploads' => null,
                    'Storage' => ['Type' => 'filestore', 'Path' => '/tmp/x', 'InfoPath' => '/tmp/x.info'],
                ], $uploadOverrides),
                'HTTPRequest' => [
                    'Method' => 'POST',
                    'URI' => '/files/',
                    'RemoteAddr' => '127.0.0.1:8080',
                    'Header' => $token ? ['Authorization' => ['Bearer ' . $token]] : [],
                ],
            ],
        ];
    }

    private function stagedSession(User $user, string $uploadId, int $size, string $status = 'complete'): UploadSession
    {
        return UploadSession::create([
            'upload_id' => $uploadId,
            'user_id' => $user->id,
            'filename' => 'staged.bin',
            'filesize' => $size,
            'filetype' => 'application/octet-stream',
            'total_chunks' => 1,
            'chunks_received' => 1,
            'status' => $status,
        ]);
    }

    public function test_pre_create_rejects_missing_token_with_hook_response(): void
    {
        $response = $this->postJson('/api/tusd-hooks', $this->hookPayload('pre-create'));

        $response->assertOk();
        $response->assertJsonPath('RejectUpload', true);
        $response->assertJsonPath('HTTPResponse.StatusCode', 401);
    }

    public function test_pre_create_rejects_invalid_token_with_hook_response(): void
    {
        $response = $this->postJson('/api/tusd-hooks', $this->hookPayload('pre-create', [], 'not-a-token'));

        $response->assertOk();
        $response->assertJsonPath('RejectUpload', true);
        $response->assertJsonPath('HTTPResponse.StatusCode', 401);
    }

    public function test_pre_create_allows_upload_within_limits(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('pre-create', ['Size' => self::MAX_SIZE - 1], $this->tokenFor($user))
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonMissingPath('RejectUpload');
    }

    public function test_pre_create_rejects_file_exceeding_max_size(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('pre-create', ['Size' => self::MAX_SIZE + 1], $this->tokenFor($user))
        );

        $response->assertOk();
        $response->assertJsonPath('RejectUpload', true);
        $response->assertJsonPath('HTTPResponse.StatusCode', 413);
    }

    public function test_pre_create_rejects_when_staged_uploads_exceed_limit_without_deleting_them(): void
    {
        $user = User::factory()->create();
        $this->stagedSession($user, 'b1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', 500 * 1024 * 1024);
        $this->stagedSession($user, 'c1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', 400 * 1024 * 1024);

        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('pre-create', ['Size' => 200 * 1024 * 1024], $this->tokenFor($user))
        );

        $response->assertOk();
        $response->assertJsonPath('RejectUpload', true);
        $response->assertJsonPath('HTTPResponse.StatusCode', 413);

        // Previously this path deleted ALL of the user's staged uploads,
        // destroying entire in-progress batches. They must be kept.
        $this->assertSame(2, UploadSession::where('user_id', $user->id)->count());
    }

    public function test_pre_create_skips_cumulative_check_for_partial_uploads(): void
    {
        $user = User::factory()->create();
        // 900 MB already staged; a cumulative check would reject any further upload.
        $this->stagedSession($user, 'b1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', 500 * 1024 * 1024);
        $this->stagedSession($user, 'c1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', 400 * 1024 * 1024);

        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('pre-create', [
                'IsPartial' => true,
                'Size' => 200 * 1024 * 1024,
                'MetaData' => [
                    'filename' => 'video.mp4',
                    'filetype' => 'video/mp4',
                    'filesize' => (string) (600 * 1024 * 1024),
                ],
            ], $this->tokenFor($user))
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonMissingPath('RejectUpload');
    }

    public function test_pre_create_enforces_full_file_size_for_partial_uploads(): void
    {
        $user = User::factory()->create();

        // The partial itself is small, but the full file exceeds the limit.
        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('pre-create', [
                'IsPartial' => true,
                'Size' => 100 * 1024 * 1024,
                'MetaData' => [
                    'filename' => 'video.mp4',
                    'filetype' => 'video/mp4',
                    'filesize' => (string) (2 * self::MAX_SIZE),
                ],
            ], $this->tokenFor($user))
        );

        $response->assertOk();
        $response->assertJsonPath('RejectUpload', true);
        $response->assertJsonPath('HTTPResponse.StatusCode', 413);
    }

    public function test_post_create_skips_partial_uploads(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('post-create', ['IsPartial' => true], $this->tokenFor($user))
        );

        $response->assertOk();
        $this->assertSame(0, UploadSession::count());
    }

    public function test_post_create_creates_session_for_regular_upload(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('post-create', ['Size' => 12345], $this->tokenFor($user))
        );

        $response->assertOk();
        $this->assertDatabaseHas('upload_sessions', [
            'upload_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'user_id' => $user->id,
            'filename' => 'video.mp4',
            'filesize' => 12345,
            'status' => 'pending',
        ]);
    }

    public function test_post_finish_skips_partial_uploads(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('post-finish', ['IsPartial' => true], $this->tokenFor($user))
        );

        $response->assertOk();
        $this->assertSame(0, UploadSession::count());
        $this->assertSame(0, File::count());
    }

    public function test_post_finish_creates_file_and_completes_session(): void
    {
        $user = User::factory()->create();
        $session = $this->stagedSession($user, 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', 12345, 'pending');

        $response = $this->postJson(
            '/api/tusd-hooks',
            $this->hookPayload('post-finish', ['Size' => 12345], $this->tokenFor($user))
        );

        $response->assertOk();

        $session->refresh();
        $this->assertSame('complete', $session->status);
        $this->assertNotNull($session->file_id);
        $this->assertDatabaseHas('files', [
            'id' => $session->file_id,
            'original_name' => 'video.mp4',
            'size' => 12345,
            'temp_path' => 'uploads/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ]);
    }
}
