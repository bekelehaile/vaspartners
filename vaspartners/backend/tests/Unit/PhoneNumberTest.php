<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('validEthiopianNumbers')]
    public function test_normalizes_ethiopian_mobiles(string $input, string $expectedNine): void
    {
        $this->assertTrue(PhoneNumber::isValidLocalMobile($input));
        $this->assertSame($expectedNine, PhoneNumber::normalize($input));
        $this->assertSame('251'.$expectedNine, PhoneNumber::toMsisdn251($input));
        $this->assertSame('+251'.$expectedNine, PhoneNumber::toE164($input));
    }

    public static function validEthiopianNumbers(): array
    {
        return [
            'local 09' => ['0912345678', '912345678'],
            'local 9' => ['912345678', '912345678'],
            'plus 251' => ['+251912345678', '912345678'],
            '251 prefix' => ['251912345678', '912345678'],
            'spaces' => ['+251 91 234 5678', '912345678'],
            '08 local' => ['0812345678', '812345678'],
            'plus 251 8' => ['+251812345678', '812345678'],
        ];
    }

    public function test_portal_otp_accepts_09_and_08_only(): void
    {
        $this->assertTrue(PhoneNumber::isValidEthioTelecomMobile('0912345678'));
        $this->assertTrue(PhoneNumber::isValidEthioTelecomMobile('+251912345678'));
        $this->assertTrue(PhoneNumber::isValidEthioTelecomMobile('0812345678'));
        $this->assertTrue(PhoneNumber::isValidEthioTelecomMobile('+251812345678'));
        $this->assertFalse(PhoneNumber::isValidEthioTelecomMobile('0712345678'));
        $this->assertFalse(PhoneNumber::isValidEthioTelecomMobile('+251712345678'));
        // 07 is no longer a valid Ethiopian mobile prefix anywhere.
        $this->assertFalse(PhoneNumber::isValidLocalMobile('0712345678'));
    }

    #[DataProvider('invalidNumbers')]
    public function test_rejects_non_ethiopian_or_invalid(string $input): void
    {
        $this->assertFalse(PhoneNumber::isValidLocalMobile($input));
        $this->assertNull(PhoneNumber::toMsisdn251($input));
        $this->assertNull(PhoneNumber::toE164($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'us' => ['+12025550123'],
            'kenya' => ['+254712345678'],
            'empty' => [''],
            'too short' => ['91234567'],
            'landline-ish' => ['0111234567'],
            '07 local now rejected' => ['0712345678'],
        ];
    }
}
