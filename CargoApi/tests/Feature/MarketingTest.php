<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\MarketingDraft;
use App\Domain\Marketing\Services\PhilippinesTrends;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    app(CompanyProvisioner::class)->provision($this->company);
    $this->admin = User::create([
        'name' => 'Marketing Admin', 'email' => 'marketing@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);
    $this->actingAs($this->admin);
    $this->brand = [
        'mission' => 'Make hauling easy for local businesses.',
        'vision' => 'Connect businesses across Luzon.',
        'voice' => 'Friendly and practical',
        'audience' => 'Retailers in Luzon',
    ];

    $this->draftContent = [
        'format' => 'reel',
        'hook' => 'Planning your next delivery?',
        'caption' => 'Book a truck for your next shipment.',
        'cta' => 'Ask us for a quote.',
        'visual_brief' => 'Film a truck being loaded.',
    ];
});

it('uses the saved brand and a current trend to generate editable drafts without exposing the key', function (): void {
    $key = 'sk-test-marketing-key';
    $this->putJson('/api/v1/assistant/settings', ['llm_provider' => 'openai', 'api_key' => $key])->assertOk();
    $this->putJson('/api/v1/marketing/brand', $this->brand)->assertOk();

    Http::preventStrayRequests();
    Http::fake([
        PhilippinesTrends::RSS => Http::response('<rss><channel><item><title>Retail deliveries</title></item></channel></rss>'),
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['drafts' => [
                [...$this->draftContent, 'trend_index' => 0],
                [...$this->draftContent, 'hook' => 'Shipping this weekend?', 'trend_index' => null],
            ]], JSON_THROW_ON_ERROR)]]],
        ]),
    ]);

    $created = $this->postJson('/api/v1/marketing/drafts', [
        'topic' => 'Weekend hauling', 'use_trends' => true,
    ])->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.trend.topic', 'Retail deliveries')
        ->assertJsonPath('data.1.trend', null)
        ->assertJsonMissingPath('data.0.company_id');

    Http::assertSent(function (Request $request) use ($key): bool {
        if ($request->url() !== 'https://api.openai.com/v1/chat/completions') {
            return false;
        }

        $brief = json_decode($request['messages'][1]['content'], true);

        return $request->hasHeader('Authorization', 'Bearer '.$key)
            && $brief['brand']['mission'] === $this->brand['mission']
            && $brief['post_topic'] === 'Weekend hauling'
            && $brief['philippines_search_topics'] === ['Retail deliveries'];
    });

    $id = $created->json('data.0.id');
    $this->putJson('/api/v1/marketing/drafts/'.$id, [...$this->draftContent, 'hook' => 'Ready to ship?'])
        ->assertOk()
        ->assertJsonPath('data.content.hook', 'Ready to ship?');

    $dashboard = $this->getJson('/api/v1/marketing')->assertOk()
        ->assertJsonPath('data.ai_configured', true)
        ->assertJsonCount(2, 'data.drafts');
    expect($dashboard->getContent())->not->toContain($key);
});

it('scopes brand and drafts to the signed-in company', function (): void {
    $this->putJson('/api/v1/marketing/brand', $this->brand)->assertOk();
    $draft = MarketingDraft::query()->create([
        'topic' => 'Private launch', 'content' => $this->draftContent,
    ]);
    $other = $this->makeCompany('Other Haulage');
    app(CompanyProvisioner::class)->provision($other);
    $otherAdmin = $this->asCompany($other, fn () => User::create([
        'name' => 'Other Admin', 'email' => 'other-marketing@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]));

    $this->actingAs($otherAdmin)->getJson('/api/v1/marketing')->assertOk()
        ->assertJsonPath('data.brand', null)
        ->assertJsonCount(0, 'data.drafts');
    $this->putJson('/api/v1/marketing/drafts/'.$draft->id, $this->draftContent)->assertNotFound();
});

it('reports missing AI configuration before making external requests', function (): void {
    Http::preventStrayRequests();
    $this->putJson('/api/v1/marketing/brand', $this->brand)->assertOk();
    $this->postJson('/api/v1/marketing/drafts', ['topic' => 'Test offer'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('generation');
    Http::assertNothingSent();
});

it('saves, previews, replaces and removes an image on a marketing draft', function (): void {
    $disk = Storage::fake('local');
    $draft = MarketingDraft::query()->create(['topic' => 'Offer', 'content' => $this->draftContent]);
    $path = '/api/v1/marketing/drafts/'.$draft->id;

    $this->post($path, ['_method' => 'PUT', ...$this->draftContent, 'image' => UploadedFile::fake()->image('first.jpg')])
        ->assertOk()
        ->assertJsonPath('data.has_image', true)
        ->assertJsonMissingPath('data.image_path');
    $first = $draft->refresh()->image_path;
    $disk->assertExists($first);

    $this->get($path.'/image')->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
    $this->putJson($path, $this->draftContent)->assertOk()->assertJsonPath('data.has_image', true);

    $this->post($path, ['_method' => 'PUT', ...$this->draftContent, 'image' => UploadedFile::fake()->image('next.png')])
        ->assertOk()
        ->assertJsonPath('data.has_image', true);
    $second = $draft->refresh()->image_path;
    $disk->assertMissing($first);
    $disk->assertExists($second);

    $this->post($path, ['_method' => 'PUT', ...$this->draftContent, 'image' => UploadedFile::fake()->create('script.svg')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    $other = $this->makeCompany('Other Image Haulage');
    app(CompanyProvisioner::class)->provision($other);
    $otherAdmin = $this->asCompany($other, fn () => User::create([
        'name' => 'Other Image Admin', 'email' => 'other-image@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]));
    $this->actingAs($otherAdmin)->get($path.'/image')->assertNotFound();

    $this->actingAs($this->admin)->post($path, ['_method' => 'PUT', ...$this->draftContent, 'remove_image' => '1'])
        ->assertOk()
        ->assertJsonPath('data.has_image', false);
    $disk->assertMissing($second);
    $this->get($path.'/image')->assertNotFound();
});
