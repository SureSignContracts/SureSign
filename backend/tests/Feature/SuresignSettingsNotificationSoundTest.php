<?php

namespace Tests\Feature;

use App\Models\SuresignSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Notification Sound System — the platform-wide, uploadable notification
 * sound asset (`suresign_settings.notification_sound_path`), managed
 * exactly like logo/favicon via the existing SureSign Branding settings
 * hub. Super Admin AND Admin (both platform-wide roles) may manage it —
 * see routes/api.php's `role:Super Admin|Admin` group.
 */
class SuresignSettingsNotificationSoundTest extends TestCase
{
    use RefreshDatabase;

    private string $testDiskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolates these tests from the real `public` disk — matches the
        // established convention elsewhere (e.g. SupportTicketControllerTest)
        // of not writing real files under storage/app/public during a test
        // run. Deliberately NOT Storage::fake('public') here: this sandbox's
        // storage/framework/testing/disks/public/suresign directory is a
        // pre-existing, root-owned artifact from an earlier (Docker-as-root)
        // test run — unrelated to this feature — which makes Storage::fake()
        // itself throw a permission error while trying to reset it. Pointing
        // the disk at a fresh, uniquely-named, this-user-owned directory
        // under storage/app/ sidesteps that entirely.
        $this->testDiskRoot = storage_path('app/testing-notification-sound-' . uniqid());
        config(['filesystems.disks.public.root' => $this->testDiskRoot]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDiskRoot)) {
            File::deleteDirectory($this->testDiskRoot);
        }
        parent::tearDown();
    }

    /** A minimal, real, finfo-detectable 44-byte silent PCM WAV file — not a
     *  base64 fixture, so its correctness doesn't depend on transcription. */
    private function minimalWavBytes(): string
    {
        $sampleRate = 8000;
        $bitsPerSample = 16;
        $channels = 1;
        $byteRate = (int) ($sampleRate * $channels * $bitsPerSample / 8);
        $blockAlign = (int) ($channels * $bitsPerSample / 8);
        $dataSize = 0;
        $riffSize = 36 + $dataSize;

        return 'RIFF' . pack('V', $riffSize) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', $channels)
            . pack('V', $sampleRate) . pack('V', $byteRate) . pack('v', $blockAlign) . pack('v', $bitsPerSample)
            . 'data' . pack('V', $dataSize);
    }

    private function actingAsAdmin(string $role = 'Super Admin'): User
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_super_admin_can_upload_a_valid_wav_notification_sound(): void
    {
        $this->actingAsAdmin('Super Admin');
        $file = UploadedFile::fake()->createWithContent('chime.wav', $this->minimalWavBytes());

        $response = $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $file]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.notification_sound_url'));
        $path = SuresignSetting::instance()->fresh()->notification_sound_path;
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_role_can_also_upload_a_notification_sound(): void
    {
        $this->actingAsAdmin('Admin');
        $file = UploadedFile::fake()->createWithContent('chime.wav', $this->minimalWavBytes());

        $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $file])
            ->assertOk();
    }

    public function test_upload_rejects_a_disallowed_extension(): void
    {
        $this->actingAsAdmin();
        $file = UploadedFile::fake()->createWithContent('chime.exe', $this->minimalWavBytes());

        $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $file])
            ->assertStatus(422);
    }

    public function test_upload_rejects_content_that_does_not_match_its_extension(): void
    {
        $this->actingAsAdmin();
        // A .wav extension whose actual bytes are plain text — must fail
        // the MIME/magic-byte cross-check, not just the extension check.
        $file = UploadedFile::fake()->createWithContent('chime.wav', '<?php system($_GET["c"]); ?>');

        $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $file])
            ->assertStatus(422);
    }

    public function test_upload_replaces_the_previous_asset(): void
    {
        $this->actingAsAdmin();
        $first = UploadedFile::fake()->createWithContent('one.wav', $this->minimalWavBytes());
        $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $first])->assertOk();
        $firstPath = SuresignSetting::instance()->fresh()->notification_sound_path;

        $second = UploadedFile::fake()->createWithContent('two.wav', $this->minimalWavBytes());
        $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $second])->assertOk();
        $secondPath = SuresignSetting::instance()->fresh()->notification_sound_path;

        $this->assertNotEmpty($firstPath);
        $this->assertNotEmpty($secondPath);
        $this->assertNotSame($firstPath, $secondPath);
        // The first file's storage path must no longer exist — deleteOld()
        // removed it as part of replacing it with the second upload.
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_notification_sound_can_be_removed(): void
    {
        $this->actingAsAdmin();
        $file = UploadedFile::fake()->createWithContent('chime.wav', $this->minimalWavBytes());
        $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $file])->assertOk();

        $response = $this->deleteJson('/api/admin/suresign-settings/notification-sound');

        $response->assertOk();
        $this->assertNull($response->json('data.notification_sound_url'));
        $this->assertNull(SuresignSetting::instance()->fresh()->notification_sound_path);
    }

    public function test_a_client_role_user_cannot_manage_the_notification_sound(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->createWithContent('chime.wav', $this->minimalWavBytes());

        $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $file])
            ->assertStatus(403);
    }

    public function test_public_settings_endpoint_exposes_null_when_nothing_is_configured(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/settings');

        $response->assertOk();
        $this->assertArrayHasKey('notification_sound_url', $response->json('data'));
        $this->assertNull($response->json('data.notification_sound_url'));
    }

    public function test_public_settings_endpoint_exposes_the_configured_asset_url(): void
    {
        $admin = $this->actingAsAdmin();
        $file = UploadedFile::fake()->createWithContent('chime.wav', $this->minimalWavBytes());
        $this->postJson('/api/admin/suresign-settings/notification-sound', ['notification_sound' => $file])->assertOk();

        $client = User::factory()->create();
        Sanctum::actingAs($client);

        $response = $this->getJson('/api/settings');
        $url = $response->json('data.notification_sound_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('.wav', $url);
    }
}
