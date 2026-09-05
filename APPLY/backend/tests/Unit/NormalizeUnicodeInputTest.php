<?php

namespace Tests\Unit;

use App\Http\Middleware\NormalizeUnicodeInput;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class NormalizeUnicodeInputTest extends TestCase
{
    private function pass(Request $request): Request
    {
        return (new NormalizeUnicodeInput())->handle($request, fn ($req) => $req);
    }

    public function test_it_normalizes_form_posted_application_fields(): void
    {
        $request = $this->pass(Request::create('/api/application/store', 'POST', [
            'firstName'           => '𝙹𝚞𝚊𝚗',
            'lastName'            => '𝘿𝙚𝙡𝙖 𝘾𝙧𝙪𝙯',
            'installationAddress' => '𝙰𝚜𝚒𝚊 𝙱𝙻𝙳𝙶 #2 𝙱𝙾𝚂𝚂 𝙹𝙼 𝙲𝙴𝙻𝙻𝙿𝙷𝙾𝙽𝙴 𝚂𝙷𝙾𝙿',
            'landmark'            => "\u{0274}\u{1D07}\u{1D00}\u{0280} \u{1D1B}\u{029C}\u{1D07} \u{1D04}\u{029C}\u{1D1C}\u{0280}\u{1D04}\u{029C}",
            'mobile'              => '𝟢𝟫𝟣𝟩𝟣𝟤𝟥𝟦𝟧𝟨𝟩',
        ]));

        $this->assertSame('Juan', $request->input('firstName'));
        $this->assertSame('Dela Cruz', $request->input('lastName'));
        $this->assertSame(
            'Asia BLDG #2 BOSS JM CELLPHONE SHOP',
            $request->input('installationAddress')
        );
        $this->assertSame('NEAR THE CHURCH', $request->input('landmark'));

        // Styled digits become real digits, so the mobile regex now sees a valid number.
        $this->assertSame('09171234567', $request->input('mobile'));
        $this->assertMatchesRegularExpression('/^09[0-9]{9}$/', $request->input('mobile'));
    }

    public function test_it_normalizes_json_bodies_too(): void
    {
        $request = $this->pass(Request::create(
            '/api/application/store',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['firstName' => '𝙹𝚞𝚊𝚗', 'nested' => ['landmark' => '𝙱𝙾𝚂𝚂 𝙹𝙼']])
        ));

        $this->assertSame('Juan', $request->input('firstName'));
        $this->assertSame('BOSS JM', $request->input('nested.landmark'));
    }

    public function test_it_leaves_passwords_untouched(): void
    {
        $request = $this->pass(Request::create('/api/login', 'POST', [
            'email'    => 'ｕｓｅｒ@example.com',
            'password' => '𝙿𝚊𝚜𝚜𝚠𝟢𝚛𝚍',
        ]));

        $this->assertSame('user@example.com', $request->input('email'));
        $this->assertSame('𝙿𝚊𝚜𝚜𝚠𝟢𝚛𝚍', $request->input('password'));
    }

    public function test_it_leaves_ordinary_submissions_unchanged(): void
    {
        $payload = [
            'firstName'           => 'Juan',
            'lastName'            => 'Dela Cruz',
            'email'               => 'juan@example.com',
            'installationAddress' => 'Blk 5, Lot 12 (Phase 2) — Purok 3 & 4',
            'coordinates'         => '14.5995,120.9842',
        ];

        $request = $this->pass(Request::create('/api/application/store', 'POST', $payload));

        foreach ($payload as $key => $value) {
            $this->assertSame($value, $request->input($key));
        }
    }
}
