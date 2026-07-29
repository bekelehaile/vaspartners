<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Services\Migration\MvasDumpMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvasMigratedContactPortalReadyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_migrated_contacts_portal_ready_activates_inactive_non_banned(): void
    {
        $inactive = new Contact;
        $inactive->forceFill([
            'sub' => 'mvas-contact-9001',
            'name' => 'Inactive Migrated',
            'phone_number' => '911111111',
            'is_active' => false,
            'is_banned' => false,
            'legacy_mvas_id' => 9001,
            'identification_type' => '2',
            'identification_number' => 'mvas-contact-9001',
        ])->save();

        $banned = new Contact;
        $banned->forceFill([
            'sub' => 'mvas-contact-9002',
            'name' => 'Banned Migrated',
            'phone_number' => '922222222',
            'is_active' => false,
            'is_banned' => true,
            'legacy_mvas_id' => 9002,
            'identification_type' => '2',
            'identification_number' => 'mvas-contact-9002',
        ])->save();

        $activated = app(MvasDumpMigrationService::class)->ensureMigratedContactsPortalReady(false);

        $this->assertSame(1, $activated);
        $this->assertTrue($inactive->fresh()->is_active);
        $this->assertFalse($banned->fresh()->is_active);
        $this->assertTrue($banned->fresh()->is_banned);
    }
}
