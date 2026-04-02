<?php
// ─────────────────────────────────────────────────────────────────────────────
// FILE: tests/Feature/AuthTest.php
// Run: php artisan test --filter AuthTest
// ─────────────────────────────────────────────────────────────────────────────
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed'); // seeds languages, channels, roles, plans
    }

    /** @test */
    public function user_can_register()
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Tariq',
            'last_name'  => 'Hussain',
            'email'      => 'tariq@cni.co.uk',
            'password'   => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['id', 'email', 'display_name'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'tariq@cni.co.uk']);
        $this->assertDatabaseHas('memberships', ['status' => 'active']); // auto free plan
        $this->assertDatabaseHas('user_role_map', []); // member role assigned
    }

    /** @test */
    public function user_cannot_register_with_duplicate_email()
    {
        User::factory()->create(['email' => 'tariq@cni.co.uk']);

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Tariq',
            'last_name'  => 'Hussain',
            'email'      => 'tariq@cni.co.uk',
            'password'   => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['email']);
    }

    /** @test */
    public function user_can_login()
    {
        $user = User::factory()->create([
            'email'        => 'test@cni.co.uk',
            'password_hash'=> bcrypt('Password123!'),
            'status'       => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'test@cni.co.uk',
            'password' => 'Password123!',
        ]);

        $response->assertOk()->assertJsonStructure(['data', 'token']);
    }

    /** @test */
    public function suspended_user_cannot_login()
    {
        User::factory()->create([
            'email'        => 'suspended@cni.co.uk',
            'password_hash'=> bcrypt('Password123!'),
            'status'       => 'suspended',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'suspended@cni.co.uk',
            'password' => 'Password123!',
        ])->assertStatus(403);
    }

    /** @test */
    public function authenticated_user_can_get_their_profile()
    {
        $user  = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
             ->getJson('/api/v1/me')
             ->assertOk()
             ->assertJsonPath('data.email', $user->email);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FILE: tests/Feature/ArticleTest.php
// Run: php artisan test --filter ArticleTest
// ─────────────────────────────────────────────────────────────────────────────

class ArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
    }

    private function makeJournalist(): array
    {
        $user  = User::factory()->create(['status' => 'active']);
        $roleId = \DB::table('roles')->where('slug', 'journalist')->value('id');
        $chanId = \DB::table('channels')->where('slug', 'cni-news')->value('id');
        \DB::table('user_role_map')->insert([
            'user_id'    => $user->id,
            'role_id'    => $roleId,
            'channel_id' => $chanId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function makeEditor(): array
    {
        $user  = User::factory()->create(['status' => 'active']);
        $roleId = \DB::table('roles')->where('slug', 'editor')->value('id');
        $chanId = \DB::table('channels')->where('slug', 'cni-news')->value('id');
        \DB::table('user_role_map')->insert([
            'user_id'    => $user->id,
            'role_id'    => $roleId,
            'channel_id' => $chanId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$user, $user->createToken('test')->plainTextToken];
    }

    /** @test */
    public function journalist_can_create_a_draft_article()
    {
        [$user, $token] = $this->makeJournalist();
        $langId = \DB::table('languages')->where('code', 'en')->value('id');
        $catId  = \DB::table('categories')->where('slug', 'pakistan')->value('id');

        $response = $this->withToken($token)->postJson('/api/v1/articles', [
            'title'            => 'Pakistan Elections 2025',
            'body'             => 'The elections took place across the country...',
            'summary'          => 'Summary of the elections.',
            'language_id'      => $langId,
            'main_category_id' => $catId,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('articles', [
            'author_user_id' => $user->id,
            'status'         => 'draft',
        ]);

        $this->assertDatabaseHas('article_translations', [
            'title' => 'Pakistan Elections 2025',
        ]);

        // Version 1 should be auto-created
        $this->assertDatabaseHas('article_versions', [
            'version_number'   => 1,
            'saved_by_user_id' => $user->id,
        ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_create_article()
    {
        $langId = \DB::table('languages')->where('code', 'en')->value('id');
        $catId  = \DB::table('categories')->where('slug', 'pakistan')->value('id');

        $this->postJson('/api/v1/articles', [
            'title'            => 'Test',
            'body'             => 'Test body content here.',
            'language_id'      => $langId,
            'main_category_id' => $catId,
        ])->assertStatus(401);
    }

    /** @test */
    public function editor_can_publish_article()
    {
        [$journalist, ] = $this->makeJournalist();
        [$editor, $editorToken] = $this->makeEditor();

        $langId = \DB::table('languages')->where('code', 'en')->value('id');
        $catId  = \DB::table('categories')->where('slug', 'pakistan')->value('id');
        $chanId = \DB::table('channels')->where('slug', 'cni-news')->value('id');

        $article = \App\Models\Article::factory()->create([
            'channel_id'          => $chanId,
            'author_user_id'      => $journalist->id,
            'primary_language_id' => $langId,
            'main_category_id'    => $catId,
            'status'              => 'pending_review',
        ]);

        $this->withToken($editorToken)
             ->postJson("/api/v1/articles/{$article->id}/publish")
             ->assertOk()
             ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('articles', [
            'id'     => $article->id,
            'status' => 'published',
        ]);

        // Audit log entry should exist
        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'article_published',
            'target_type' => 'article',
            'target_id'   => $article->id,
        ]);
    }

    /** @test */
    public function journalist_cannot_publish_article_without_permission()
    {
        [$journalist, $token] = $this->makeJournalist();
        $langId = \DB::table('languages')->where('code', 'en')->value('id');
        $catId  = \DB::table('categories')->where('slug', 'pakistan')->value('id');
        $chanId = \DB::table('channels')->where('slug', 'cni-news')->value('id');

        $article = \App\Models\Article::factory()->create([
            'channel_id'          => $chanId,
            'author_user_id'      => $journalist->id,
            'primary_language_id' => $langId,
            'main_category_id'    => $catId,
            'status'              => 'draft',
        ]);

        $this->withToken($token)
             ->postJson("/api/v1/articles/{$article->id}/publish")
             ->assertStatus(403);
    }

    /** @test */
    public function article_update_saves_a_new_version()
    {
        [$journalist, $token] = $this->makeJournalist();
        $langId = \DB::table('languages')->where('code', 'en')->value('id');
        $catId  = \DB::table('categories')->where('slug', 'pakistan')->value('id');
        $chanId = \DB::table('channels')->where('slug', 'cni-news')->value('id');

        $article = \App\Models\Article::factory()->create([
            'channel_id'          => $chanId,
            'author_user_id'      => $journalist->id,
            'primary_language_id' => $langId,
            'main_category_id'    => $catId,
            'status'              => 'draft',
        ]);

        \App\Models\ArticleTranslation::factory()->create([
            'article_id'  => $article->id,
            'language_id' => $langId,
        ]);

        \App\Models\ArticleVersion::create([
            'article_id'       => $article->id,
            'language_id'      => $langId,
            'version_number'   => 1,
            'title'            => 'Original',
            'body'             => 'Original body',
            'saved_by_user_id' => $journalist->id,
        ]);

        $this->withToken($token)->patchJson("/api/v1/articles/{$article->id}", [
            'title'       => 'Updated Title',
            'body'        => 'Updated body content.',
            'language_id' => $langId,
            'change_summary' => 'Updated headline',
        ])->assertOk();

        // Should now have version 2
        $this->assertDatabaseHas('article_versions', [
            'article_id'     => $article->id,
            'version_number' => 2,
            'change_summary' => 'Updated headline',
        ]);
    }

    /** @test */
    public function published_articles_are_visible_publicly()
    {
        $langId = \DB::table('languages')->where('code', 'en')->value('id');
        $catId  = \DB::table('categories')->where('slug', 'pakistan')->value('id');
        $chanId = \DB::table('channels')->where('slug', 'cni-news')->value('id');
        $userId = \DB::table('users')->first()->id ?? 1;

        $article = \App\Models\Article::factory()->create([
            'channel_id'          => $chanId,
            'author_user_id'      => $userId,
            'primary_language_id' => $langId,
            'main_category_id'    => $catId,
            'status'              => 'published',
            'published_at'        => now()->subHour(),
            'slug'                => 'test-article-slug',
        ]);

        $this->getJson('/api/v1/articles/test-article-slug')
             ->assertOk()
             ->assertJsonPath('data.slug', 'test-article-slug');
    }
}
