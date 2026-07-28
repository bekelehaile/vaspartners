<?php

namespace Tests\Unit;

use App\Models\RevenuePartner;
use App\Models\Service;
use App\Services\RevenuePartnerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenuePartnerResolverTest extends TestCase
{
    use RefreshDatabase;

    private RevenuePartnerResolver $resolver;

    private Service $catalogService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RevenuePartnerResolver;
        $this->catalogService = Service::query()->create([
            'name' => 'API',
            'slug' => 'api-test-'.uniqid(),
            'is_active' => true,
        ]);
    }

    public function test_requires_service_id_or_short_code(): void
    {
        $result = $this->resolver->resolve(null, '  ');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('service_id and/or short_code', (string) $result['error']);
    }

    public function test_matches_by_service_id_only(): void
    {
        $partner = RevenuePartner::query()->create([
            'service_id' => 'SID-1',
            'short_code' => '8100',
            'vas_service_id' => $this->catalogService->id,
            'partner_name' => 'Alpha',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolve('SID-1', null);

        $this->assertTrue($result['ok']);
        $this->assertSame($partner->id, $result['partner']?->id);
        $this->assertSame('8100', $result['short_code']);
    }

    public function test_matches_by_short_code_only(): void
    {
        $partner = RevenuePartner::query()->create([
            'service_id' => 'SID-2',
            'short_code' => '8200',
            'vas_service_id' => $this->catalogService->id,
            'partner_name' => 'Beta',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolve(null, '8200');

        $this->assertTrue($result['ok']);
        $this->assertSame($partner->id, $result['partner']?->id);
        $this->assertSame('SID-2', $result['service_id']);
    }

    public function test_matches_when_both_agree(): void
    {
        $partner = RevenuePartner::query()->create([
            'service_id' => 'SID-3',
            'short_code' => '8300',
            'vas_service_id' => $this->catalogService->id,
            'partner_name' => 'Gamma',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolve('SID-3', '8300');

        $this->assertTrue($result['ok']);
        $this->assertSame($partner->id, $result['partner']?->id);
    }

    public function test_rejects_when_keys_point_to_different_partners(): void
    {
        RevenuePartner::query()->create([
            'service_id' => 'SID-A',
            'short_code' => '9100',
            'vas_service_id' => $this->catalogService->id,
            'partner_name' => 'A',
            'is_active' => true,
        ]);
        RevenuePartner::query()->create([
            'service_id' => 'SID-B',
            'short_code' => '9200',
            'vas_service_id' => $this->catalogService->id,
            'partner_name' => 'B',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolve('SID-A', '9200');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('different master partners', (string) $result['error']);
    }

    public function test_rejects_when_service_id_match_has_conflicting_short_code(): void
    {
        RevenuePartner::query()->create([
            'service_id' => 'SID-4',
            'short_code' => '8400',
            'vas_service_id' => $this->catalogService->id,
            'partner_name' => 'Delta',
            'is_active' => true,
        ]);

        $result = $this->resolver->resolve('SID-4', '9999');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('short code', strtolower((string) $result['error']));
    }

    public function test_missing_partner_is_ok_with_null_partner(): void
    {
        $result = $this->resolver->resolve('MISSING', '7777');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['partner']);
        $this->assertSame('MISSING', $result['service_id']);
        $this->assertSame('7777', $result['short_code']);
    }

    public function test_upsert_requires_service_id_for_create(): void
    {
        $result = $this->resolver->resolveForUpsert(null, '8500');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('service_id is required', (string) $result['error']);
    }
}
