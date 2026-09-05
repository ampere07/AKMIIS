<?php

namespace Tests\Unit;

use App\Support\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    /** The exact case reported from the application form. */
    public function test_it_folds_monospace_styled_text_from_the_application_form(): void
    {
        $this->assertSame(
            'Asia BLDG #2 BOSS JM CELLPHONE SHOP',
            TextNormalizer::normalize('𝙰𝚜𝚒𝚊 𝙱𝙻𝙳𝙶 #2 𝙱𝙾𝚂𝚂 𝙹𝙼 𝙲𝙴𝙻𝙻𝙿𝙷𝙾𝙽𝙴 𝚂𝙷𝙾𝙿')
        );
    }

    /**
     * @dataProvider styledVariants
     */
    public function test_it_folds_every_common_styled_variant(string $styled, string $plain): void
    {
        $this->assertSame($plain, TextNormalizer::normalize($styled));
    }

    public static function styledVariants(): array
    {
        return [
            'bold'            => ['𝐀𝐬𝐢𝐚 𝐁𝐋𝐃𝐆', 'Asia BLDG'],
            'italic'          => ['𝐴𝑠𝑖𝑎', 'Asia'],
            'bold italic'     => ['𝑨𝒔𝒊𝒂', 'Asia'],
            'script'          => ['𝒜𝓈𝒾𝒶', 'Asia'],
            'bold script'     => ['𝓐𝓼𝓲𝓪', 'Asia'],
            'fraktur'         => ['𝔄𝔰𝔦𝔞', 'Asia'],
            'double struck'   => ['𝔸𝕤𝕚𝕒', 'Asia'],
            'sans serif'      => ['𝖠𝗌𝗂𝖺', 'Asia'],
            'sans bold'       => ['𝗔𝘀𝗶𝗮', 'Asia'],
            'monospace'       => ['𝙰𝚜𝚒𝚊', 'Asia'],
            'fullwidth'       => ['Ａｓｉａ　ＢＬＤＧ', 'Asia BLDG'],
            'circled'         => ['ⒶⓈⒾⒶ ①②③', 'ASIA 123'],
            'negative circled'=> ['🅐🅑🅒', 'ABC'],
            'squared'         => ['🄰🄱🄲', 'ABC'],
            'negative squared'=> ['🅰🅱🅲', 'ABC'],
            // Small capitals carry no case information, so they fold to uppercase.
            'small caps'      => ["\u{0299}\u{1D0F}\u{A731}\u{A731} \u{1D0A}\u{1D0D} \u{1D04}\u{1D07}\u{029F}\u{029F}\u{1D18}\u{029C}\u{1D0F}\u{0274}\u{1D07}", 'BOSS JM CELLPHONE'],
            'superscript'     => ['ᴬᴮ²³', 'AB23'],
            'subscript'       => ['₁₂₃', '123'],
            'ligature'        => ['ﬁle ﬂow', 'file flow'],
            'styled digits'   => ['𝟢𝟫𝟣𝟤', '0912'],
        ];
    }

    public function test_it_strips_invisible_characters(): void
    {
        $this->assertSame('Asia BLDG', TextNormalizer::normalize("As\u{200B}ia\u{FEFF} B\u{200D}LDG"));
        $this->assertSame('Asia BLDG', TextNormalizer::normalize("Asia\u{00A0}BLDG"));
    }

    public function test_it_preserves_ordinary_text(): void
    {
        foreach ([
            'Asia BLDG #2 BOSS JM CELLPHONE SHOP',
            'José Muñoz-Ñuñez',
            "Blk 5, Lot 12 (Phase 2) — Purok 3 & 4; 50% done",
            'juan.dela_cruz+apply@example.com',
            '09171234567',
            '14.5995,120.9842',
        ] as $value) {
            $this->assertSame($value, TextNormalizer::normalize($value));
        }
    }

    public function test_it_leaves_non_strings_and_odd_input_alone(): void
    {
        $this->assertNull(TextNormalizer::normalize(null));
        $this->assertSame(42, TextNormalizer::normalize(42));
        $this->assertTrue(TextNormalizer::normalize(true));
        $this->assertSame('', TextNormalizer::normalize(''));

        // Invalid UTF-8 must pass through rather than be emptied.
        $invalid = "Asia \xB1\x31 BLDG";
        $this->assertSame($invalid, TextNormalizer::normalize($invalid));
    }

    public function test_it_keeps_flag_emoji_intact(): void
    {
        // Regional indicators encode flags; folding them would rewrite 🇵🇭 as "PH".
        $this->assertSame('🇵🇭', TextNormalizer::normalize('🇵🇭'));
    }

    public function test_it_normalizes_arrays_recursively_and_honours_exceptions(): void
    {
        $result = TextNormalizer::normalizeArray([
            'firstName' => '𝙹𝚞𝚊𝚗',
            'nested'    => ['landmark' => '𝙱𝙾𝚂𝚂 𝙹𝙼'],
            'password'  => '𝙿𝚊𝚜𝚜',
        ], ['password']);

        $this->assertSame('Juan', $result['firstName']);
        $this->assertSame('BOSS JM', $result['nested']['landmark']);
        $this->assertSame('𝙿𝚊𝚜𝚜', $result['password']);
    }
}
