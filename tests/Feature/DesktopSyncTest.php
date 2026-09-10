<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DesktopSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function token(User $user, array $abilities = ['desktop:sync']): string
    {
        return $user->createToken('test-desktop', $abilities)->plainTextToken;
    }

    private function upload(string $token, string $root, string $path = 'docs/hello.txt', string $content = 'hello', ?string $base = null): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/desktop/files', [
            'root' => $root, 'path' => $path, 'base_hash' => $base,
            'file' => UploadedFile::fake()->createWithContent(basename($path), $content),
        ]);
    }

    public function test_login_issues_scoped_token_and_invalid_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/v1/desktop/login', [
            'email' => $user->email, 'password' => 'incorrect', 'device_name' => 'PC',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $response = $this->postJson('/api/v1/desktop/login', [
            'email' => $user->email, 'password' => 'password', 'device_name' => 'PC',
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(['desktop:sync'], $user->tokens()->first()->abilities);
        $this->assertNotNull($user->tokens()->first()->expires_at);
    }

    public function test_upload_requires_login_and_correct_scope(): void
    {
        $this->getJson('/api/v1/desktop/files?root='.Str::uuid())->assertUnauthorized();
        $this->withToken($this->token(User::factory()->create(), ['unrelated']))
            ->getJson('/api/v1/desktop/me')->assertForbidden();
    }

    public function test_upload_is_idempotent_and_requires_matching_version_for_updates(): void
    {
        $token = $this->token(User::factory()->create());
        $root = (string) Str::uuid();
        $response = $this->upload($token, $root)->assertOk();
        $uuid = $response->json('file.uuid');
        $hash = $response->json('file.sync_hash');
        $this->assertSame(hash('sha256', 'hello'), $hash);
        $oldKey = Book::first()->storageKey();
        Storage::assertExists($oldKey);
        $this->upload($token, $root)->assertOk()->assertJsonPath('file.uuid', $uuid);
        $this->assertDatabaseCount('books', 1);
        $this->upload($token, $root, content: 'changed')->assertConflict();
        $this->assertSame('hello', Storage::get($oldKey));
        $this->upload($token, $root, content: 'changed', base: $hash)->assertOk()->assertJsonPath('file.uuid', $uuid);
        $this->assertDatabaseCount('books', 1);
        Storage::assertMissing($oldKey);
        $this->withToken($token)->get('/api/v1/desktop/files/'.$uuid.'/content')->assertOk()->assertStreamedContent('changed');
    }

    public function test_other_account_cannot_list_download_or_modify_desktop_files(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $root = (string) Str::uuid();
        $uuid = $this->upload($this->token($owner), $root)->assertOk()->json('file.uuid');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($other))->getJson('/api/v1/desktop/files?root='.$root)
            ->assertOk()->assertJsonCount(0, 'files');
        $this->getJson('/api/v1/desktop/files/'.$uuid.'/content')->assertNotFound();
        $this->actingAs($other)->get('/drive/files/'.$uuid.'/content')->assertNotFound();
        $this->actingAs($other)->delete('/drive/files/'.$uuid)->assertNotFound();
        $this->actingAs($other)->get('/')->assertInertia(fn (Assert $page) => $page->has('books.data', 0));
        $this->assertDatabaseCount('books', 1);
    }

    public function test_private_files_are_visible_to_owner_but_not_guests(): void
    {
        $owner = User::factory()->create();
        $this->upload($this->token($owner), (string) Str::uuid())->assertOk();
        $this->actingAs($owner)->get('/')->assertInertia(fn (Assert $page) => $page->has('books.data', 1));
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->get('/')->assertInertia(fn (Assert $page) => $page->has('books.data', 0));
    }

    public function test_rejects_unsafe_paths_and_oversized_files(): void
    {
        $token = $this->token(User::factory()->create());
        foreach (['../escape.txt', '/absolute.txt', 'C:/escape.txt', 'a/../b.txt', 'file:stream'] as $path) {
            $this->upload($token, (string) Str::uuid(), $path)->assertUnprocessable()->assertJsonValidationErrors('path');
        }
        $this->withToken($token)->postJson('/api/v1/desktop/files', [
            'root' => (string) Str::uuid(), 'path' => 'big.bin',
            'file' => UploadedFile::fake()->create('big.bin', 102401),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('books', 0);
    }

    public function test_logout_revokes_current_device_token(): void
    {
        $user = User::factory()->create();
        $this->withToken($this->token($user))->postJson('/api/v1/desktop/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/desktop/me')->assertUnauthorized();
    }

    public function test_expired_device_token_cannot_access_api(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['desktop:sync'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/desktop/me')->assertUnauthorized();
    }
}
