<?php

namespace Tests\Feature;

use App\Models\ProductUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "What's New in SureSign" — see App\Models\ProductUpdate's docblock.
 * Covers the full spec checklist: draft/published/archived lifecycle,
 * per-user dismissal identity, new-update-appears-despite-old-dismissal,
 * edit-does-not-reset-dismissal, and authorization.
 */
class ProductUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role, ?int $organizationId = null): User
    {
        $user = User::factory()->create(['organization_id' => $organizationId]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    private function published(array $overrides = []): ProductUpdate
    {
        return ProductUpdate::create(array_merge([
            'title' => 'Drawing Coordination',
            'summary' => 'Link records to the exact drawing revision and location.',
            'content' => 'Full body content here.',
            'category' => ProductUpdate::CATEGORY_NEW_FEATURE,
            'audience' => ProductUpdate::AUDIENCE_CLIENT,
            'status' => ProductUpdate::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ], $overrides));
    }

    // 1. Super Admin can create Draft Product Update.
    public function test_super_admin_can_create_draft_update(): void
    {
        $this->actingAsRole('Super Admin');

        $this->postJson('/api/admin/product-updates', [
            'title' => 'New Feature', 'summary' => 'Summary text', 'content' => 'Body',
            'category' => 'new_feature',
        ])->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('product_updates', ['title' => 'New Feature', 'status' => 'draft']);
    }

    // 2. Client cannot create Product Update.
    public function test_client_cannot_create_update(): void
    {
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);

        $this->postJson('/api/admin/product-updates', [
            'title' => 'New Feature', 'summary' => 'Summary text', 'content' => 'Body', 'category' => 'new_feature',
        ])->assertForbidden();
    }

    // 3. Draft is not returned to customers.
    public function test_draft_is_not_returned_to_pending_or_history(): void
    {
        $this->published(['status' => ProductUpdate::STATUS_DRAFT, 'published_at' => null]);
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);

        $this->getJson('/api/product-updates/pending')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/product-updates/history')->assertOk()->assertJsonCount(0, 'data');
    }

    // 4. Published update is returned.
    public function test_published_update_is_returned_to_pending(): void
    {
        $update = $this->published();
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);

        $this->getJson('/api/product-updates/pending')->assertOk()->assertJsonPath('data.0.id', $update->id);
    }

    // 5. Unpublished (archived) update is not returned automatically.
    public function test_archived_update_is_not_returned_to_pending(): void
    {
        $this->published(['status' => ProductUpdate::STATUS_ARCHIVED]);
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);

        $this->getJson('/api/product-updates/pending')->assertOk()->assertJsonCount(0, 'data');
    }

    // 6. User without acknowledgement sees new update.
    public function test_user_without_dismissal_sees_update(): void
    {
        $update = $this->published();
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);

        $this->getJson('/api/product-updates/pending')->assertJsonCount(1, 'data');
    }

    // 7 & 8. User can dismiss an update; dismissed update stops appearing automatically.
    public function test_dismissing_an_update_stops_it_appearing_in_pending(): void
    {
        $update = $this->published();
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);

        $this->postJson("/api/product-updates/{$update->id}/dismiss")->assertOk()->assertJsonPath('success', true);
        $this->getJson('/api/product-updates/pending')->assertJsonCount(0, 'data');
    }

    // 9 & 10. Dismissal belongs to correct User; another user still sees the update.
    public function test_dismissal_is_scoped_to_the_dismissing_user_only(): void
    {
        $update = $this->published();
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $userA = $this->actingAsRole('Client', $org->id);
        $this->postJson("/api/product-updates/{$update->id}/dismiss")->assertOk();

        $this->actingAsRole('Client', $org->id);
        $this->getJson('/api/product-updates/pending')->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('product_update_dismissals', ['product_update_id' => $update->id, 'user_id' => $userA->id]);
    }

    // 11. New Product Update appears despite older update dismissal.
    public function test_new_update_appears_despite_older_update_being_dismissed(): void
    {
        $old = $this->published(['title' => 'Old', 'published_at' => now()->subDays(5)]);
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);
        $this->postJson("/api/product-updates/{$old->id}/dismiss")->assertOk();

        $new = $this->published(['title' => 'New', 'published_at' => now()]);

        $response = $this->getJson('/api/product-updates/pending')->assertJsonCount(1, 'data');
        $this->assertSame($new->id, $response->json('data.0.id'));
    }

    // 12. Editing old Product Update does not reset dismissal.
    public function test_editing_a_published_update_does_not_reset_dismissals(): void
    {
        $update = $this->published();
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);
        $this->postJson("/api/product-updates/{$update->id}/dismiss")->assertOk();

        $this->actingAsRole('Super Admin');
        $this->putJson("/api/admin/product-updates/{$update->id}", [
            'title' => 'Drawing Coordination (typo fixed)', 'summary' => $update->summary, 'content' => $update->content,
            'category' => $update->category, 'status' => 'published',
        ])->assertOk();

        $this->assertDatabaseHas('product_update_dismissals', ['product_update_id' => $update->id]);
        $this->assertSame(1, \App\Models\ProductUpdateDismissal::count());
    }

    // 13. Cross-user acknowledgement manipulation rejected — dismiss always
    // records the AUTHENTICATED user, never a caller-supplied user id.
    public function test_dismiss_endpoint_ignores_any_caller_supplied_user_id(): void
    {
        $update = $this->published();
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $me = $this->actingAsRole('Client', $org->id);
        $other = User::factory()->create(['organization_id' => $org->id]);

        $this->postJson("/api/product-updates/{$update->id}/dismiss", ['user_id' => $other->id])->assertOk();

        $this->assertDatabaseHas('product_update_dismissals', ['product_update_id' => $update->id, 'user_id' => $me->id]);
        $this->assertDatabaseMissing('product_update_dismissals', ['product_update_id' => $update->id, 'user_id' => $other->id]);
    }

    // 14. Super Admin publish authorization works (Admin too — platform-wide role).
    public function test_admin_can_publish_an_update(): void
    {
        $this->actingAsRole('Admin');
        $update = ProductUpdate::create([
            'title' => 'Draft item', 'summary' => 'S', 'content' => 'C', 'category' => 'tip', 'status' => 'draft',
        ]);

        $this->putJson("/api/admin/product-updates/{$update->id}", [
            'title' => $update->title, 'summary' => $update->summary, 'content' => $update->content,
            'category' => $update->category, 'status' => 'published',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertNotNull($update->fresh()->published_at);
    }

    // 15. CTA safety validation works.
    public function test_cta_url_rejects_unsafe_schemes(): void
    {
        $this->actingAsRole('Super Admin');

        $this->postJson('/api/admin/product-updates', [
            'title' => 'X', 'summary' => 'S', 'content' => 'C', 'category' => 'tip',
            'cta_label' => 'Go', 'cta_url' => 'javascript:alert(1)',
        ])->assertUnprocessable();

        $this->postJson('/api/admin/product-updates', [
            'title' => 'X', 'summary' => 'S', 'content' => 'C', 'category' => 'tip',
            'cta_label' => 'Go', 'cta_url' => '//evil.example.com',
        ])->assertUnprocessable();

        $this->postJson('/api/admin/product-updates', [
            'title' => 'X', 'summary' => 'S', 'content' => 'C', 'category' => 'tip',
            'cta_label' => 'Explore Drawings', 'cta_url' => '/app/drawings',
        ])->assertCreated();
    }

    // 16. Archive/history behaviour: dismissed updates remain visible in history.
    public function test_dismissed_update_still_appears_in_history(): void
    {
        $update = $this->published();
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);
        $this->postJson("/api/product-updates/{$update->id}/dismiss")->assertOk();

        $this->getJson('/api/product-updates/history')->assertJsonCount(1, 'data');
    }

    // Audience: an 'operator'-only update never reaches a Client, and a
    // 'client'-only update never reaches a platform operator.
    public function test_audience_targeting_is_enforced(): void
    {
        $this->published(['title' => 'Operator only', 'audience' => ProductUpdate::AUDIENCE_OPERATOR]);
        $this->published(['title' => 'Client only', 'audience' => ProductUpdate::AUDIENCE_CLIENT]);

        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);
        $clientView = $this->getJson('/api/product-updates/pending')->json('data');
        $this->assertCount(1, $clientView);
        $this->assertSame('Client only', $clientView[0]['title']);

        $this->actingAsRole('Super Admin');
        $operatorView = $this->getJson('/api/product-updates/pending')->json('data');
        $this->assertCount(1, $operatorView);
        $this->assertSame('Operator only', $operatorView[0]['title']);
    }

    // Customer-facing responses never leak internal-only fields.
    public function test_customer_facing_response_excludes_internal_fields(): void
    {
        $this->published();
        $org = \App\Models\Organization::create(['name' => 'Acme', 'slug' => 'acme-'.random_int(1, 999999), 'timezone' => 'UTC']);
        $this->actingAsRole('Client', $org->id);

        $response = $this->getJson('/api/product-updates/pending')->json('data.0');
        $this->assertArrayNotHasKey('audience', $response);
        $this->assertArrayNotHasKey('status', $response);
        $this->assertArrayNotHasKey('created_by', $response);
    }
}
