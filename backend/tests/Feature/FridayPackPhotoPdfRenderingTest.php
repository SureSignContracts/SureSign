<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\FridayPack\FridayPackPhotoPdfOptimisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1G.1 — photo optimisation for the schema-2
 * PDF. Uses the already-installed `intervention/image` (^4.1, GD driver —
 * confirmed installed via `php -m`) — no new dependency.
 */
class FridayPackPhotoPdfRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeUpload(string $bytes, string $mime = 'image/jpeg'): FileUpload
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-photo-opt']);
        $editor = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project']);

        $path = 'projects/' . $project->id . '/uploads/' . uniqid() . '.jpg';
        Storage::disk('local')->put($path, $bytes);

        return FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $editor->id,
            'original_name' => 'photo.jpg', 'stored_name' => basename($path), 'file_path' => $path,
            'mime_type' => $mime, 'file_size' => strlen($bytes), 'disk' => 'local',
        ]);
    }

    private function largeJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 100, 150, 200));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_large_landscape_image_is_downscaled_to_max_dimension(): void
    {
        $upload = $this->makeUpload($this->largeJpeg(3000, 2000));

        $tempPath = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);

        $this->assertNotNull($tempPath);
        $this->assertFileExists($tempPath);
        [$width, $height] = getimagesize($tempPath);
        $this->assertLessThanOrEqual(1600, $width);
        $this->assertLessThanOrEqual(1600, $height);
        // Aspect ratio preserved (3:2 source).
        $this->assertEqualsWithDelta(1.5, $width / $height, 0.02);

        @unlink($tempPath);
    }

    public function test_large_portrait_image_is_downscaled_preserving_aspect_ratio(): void
    {
        $upload = $this->makeUpload($this->largeJpeg(2000, 3000));

        $tempPath = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);

        [$width, $height] = getimagesize($tempPath);
        $this->assertLessThanOrEqual(1600, $width);
        $this->assertLessThanOrEqual(1600, $height);
        $this->assertEqualsWithDelta(1.5, $height / $width, 0.02);

        @unlink($tempPath);
    }

    public function test_small_image_is_never_upscaled(): void
    {
        $upload = $this->makeUpload($this->largeJpeg(200, 150));

        $tempPath = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);

        [$width, $height] = getimagesize($tempPath);
        $this->assertSame(200, $width);
        $this->assertSame(150, $height);

        @unlink($tempPath);
    }

    public function test_original_file_is_never_mutated(): void
    {
        $bytes = $this->largeJpeg(3000, 2000);
        $upload = $this->makeUpload($bytes);
        $originalBytesBefore = Storage::disk('local')->get($upload->file_path);

        $tempPath = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);

        $this->assertSame($originalBytesBefore, Storage::disk('local')->get($upload->file_path));
        $this->assertSame(strlen($bytes), $upload->fresh()->file_size);

        @unlink($tempPath);
    }

    public function test_transparent_png_encodes_safely_to_jpeg_on_white_background(): void
    {
        $image = imagecreatetruecolor(100, 100);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        ob_start();
        imagepng($image);
        $pngBytes = ob_get_clean();
        imagedestroy($image);

        $upload = $this->makeUpload($pngBytes, 'image/png');
        $tempPath = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);

        $this->assertNotNull($tempPath);
        $this->assertFileExists($tempPath);
        $this->assertStringEndsWith('.jpg', $tempPath);
        // A real, decodable JPEG — no exception, no corrupt output.
        $this->assertNotFalse(getimagesize($tempPath));

        @unlink($tempPath);
    }

    /**
     * Post-Deploy Photo Hardening, P1 — proves GD can now DECODE a genuine
     * WebP source image (`imagecreatefromwebp()`, via Intervention's own
     * format-detecting `decodePath()`), producing the exact same output
     * contract as every other source format: a real, decodable JPEG
     * rendition. The PDF's own output format is deliberately unchanged —
     * this is "give GD the ability to read WebP input," never "embed WebP
     * bytes in the PDF." Real WebP fixture bytes via `imagewebp()` —
     * this test only has meaning in an environment where that function
     * exists (this repo's production Dockerfile, post-P1); mirrors this
     * file's own established real-magic-bytes testing convention.
     */
    public function test_webp_source_decodes_and_encodes_safely_to_jpeg(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('This environment\'s GD build has no WebP encode support (imagewebp) to construct a real fixture with.');
        }

        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 90, 160));
        ob_start();
        imagewebp($image, null, 90);
        $webpBytes = ob_get_clean();
        imagedestroy($image);

        // Confirm the fixture itself is genuinely WebP (RIFF....WEBP), not
        // a false-positive from a fallback encoder.
        $this->assertStringStartsWith('RIFF', $webpBytes);
        $this->assertStringContainsString('WEBP', substr($webpBytes, 8, 4));

        $upload = $this->makeUpload($webpBytes, 'image/webp');
        $tempPath = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);

        $this->assertNotNull($tempPath, 'Expected a genuine WebP source to decode successfully now that GD has WebP support.');
        $this->assertFileExists($tempPath);
        $this->assertStringEndsWith('.jpg', $tempPath, 'The output rendition must still be JPEG — the PDF output format is unchanged.');
        [$width, $height] = getimagesize($tempPath);
        $this->assertSame(400, $width);
        $this->assertSame(300, $height);

        @unlink($tempPath);
    }

    public function test_missing_file_returns_null_not_exception(): void
    {
        $upload = $this->makeUpload($this->largeJpeg(100, 100));
        Storage::disk('local')->delete($upload->file_path);

        $result = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);

        $this->assertNull($result);
    }

    public function test_corrupt_unsupported_image_returns_null_not_exception(): void
    {
        $upload = $this->makeUpload("this is not a real image file at all", 'image/jpeg');

        $result = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);

        $this->assertNull($result);
    }

    public function test_cleanup_removes_temporary_renditions(): void
    {
        $upload = $this->makeUpload($this->largeJpeg(2000, 2000));
        $tempPath = app(FridayPackPhotoPdfOptimisationService::class)->prepare($upload);
        $this->assertFileExists($tempPath);

        app(FridayPackPhotoPdfOptimisationService::class)->cleanup([$tempPath]);

        $this->assertFileDoesNotExist($tempPath);
    }

    public function test_cleanup_is_safe_for_already_missing_or_null_paths(): void
    {
        // Must never throw — mirrors the finally-block cleanup contract.
        app(FridayPackPhotoPdfOptimisationService::class)->cleanup([null, '/nonexistent/path.jpg', '']);
        $this->assertTrue(true);
    }
}
