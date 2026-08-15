<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\SocialChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real-MySQL verification of the Landing Page's admin-managed social
 * channels: public visibility rules (enabled + non-empty value only) and
 * admin-only CRUD access.
 */
class SocialChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'QA Admin', 'email' => 'qa_admin_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    public function test_migration_seeds_the_five_default_disabled_channels(): void
    {
        $platforms = SocialChannel::query()->pluck('platform')->sort()->values()->all();

        $this->assertEquals(['facebook', 'instagram', 'tiktok', 'whatsapp', 'youtube'], $platforms);
        $this->assertTrue(SocialChannel::all()->every(fn (SocialChannel $c) => !$c->is_enabled && $c->value === null));
    }

    public function test_public_company_settings_endpoint_hides_disabled_channels(): void
    {
        $response = $this->getJson('/api/company-settings');

        $response->assertOk();
        $this->assertEmpty($response->json('data.social_channels'));
    }

    public function test_public_company_settings_endpoint_only_shows_enabled_channels_with_a_value(): void
    {
        SocialChannel::where('platform', 'facebook')->update(['value' => 'https://facebook.com/goldenperfume', 'is_enabled' => true]);
        SocialChannel::where('platform', 'instagram')->update(['value' => 'https://instagram.com/goldenperfume', 'is_enabled' => false]); // enabled=false → hidden
        SocialChannel::where('platform', 'tiktok')->update(['value' => null, 'is_enabled' => true]); // no value → hidden

        $response = $this->getJson('/api/company-settings');

        $channels = collect($response->json('data.social_channels'))->pluck('platform');
        $this->assertEquals(['facebook'], $channels->all());
    }

    public function test_public_endpoint_never_leaks_the_channel_id_or_enabled_flag(): void
    {
        SocialChannel::where('platform', 'whatsapp')->update(['value' => '01000000001', 'is_enabled' => true]);

        $response = $this->getJson('/api/company-settings');

        $row = collect($response->json('data.social_channels'))->first();
        $this->assertArrayHasKey('platform', $row);
        $this->assertArrayHasKey('label', $row);
        $this->assertArrayHasKey('value', $row);
        $this->assertArrayNotHasKey('id', $row);
        $this->assertArrayNotHasKey('is_enabled', $row);
    }

    public function test_admin_can_list_every_channel_including_disabled_ones(): void
    {
        $this->actingAs($this->admin, 'api');

        $response = $this->getJson('/api/social-channels');

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));
    }

    public function test_admin_can_enable_a_channel_and_set_its_value(): void
    {
        $this->actingAs($this->admin, 'api');
        $channel = SocialChannel::where('platform', 'whatsapp')->first();

        $response = $this->putJson("/api/social-channels/{$channel->id}", [
            'value' => '01000000001',
            'is_enabled' => true,
        ]);

        $response->assertOk();
        $this->assertEquals('01000000001', $channel->fresh()->value);
        $this->assertTrue($channel->fresh()->is_enabled);
    }

    public function test_admin_can_disable_a_channel(): void
    {
        $this->actingAs($this->admin, 'api');
        $channel = SocialChannel::where('platform', 'facebook')->first();
        $channel->update(['value' => 'https://facebook.com/x', 'is_enabled' => true]);

        $response = $this->putJson("/api/social-channels/{$channel->id}", [
            'value' => 'https://facebook.com/x',
            'is_enabled' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($channel->fresh()->is_enabled);
    }

    public function test_listing_channels_requires_admin_authentication(): void
    {
        $response = $this->getJson('/api/social-channels');

        $response->assertStatus(401);
    }

    public function test_updating_a_channel_requires_admin_authentication(): void
    {
        $channel = SocialChannel::where('platform', 'facebook')->first();

        $response = $this->putJson("/api/social-channels/{$channel->id}", [
            'value' => 'https://facebook.com/x',
            'is_enabled' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_updating_a_channel_rejects_a_non_boolean_is_enabled(): void
    {
        $this->actingAs($this->admin, 'api');
        $channel = SocialChannel::where('platform', 'facebook')->first();

        $response = $this->putJson("/api/social-channels/{$channel->id}", [
            'value' => 'https://facebook.com/x',
            'is_enabled' => 'not-a-boolean',
        ]);

        $response->assertStatus(422);
    }
}
