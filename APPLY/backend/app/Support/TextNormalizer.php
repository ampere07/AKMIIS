<?php

namespace App\Support;

use Normalizer;

/**
 * Folds "styled font" text that phone keyboards produce back into plain characters.
 *
 * Customers routinely paste names and addresses written with Unicode Mathematical
 * Alphanumeric Symbols and friends, e.g.
 *
 *     𝙰𝚜𝚒𝚊 𝙱𝙻𝙳𝙶 #2 𝙱𝙾𝚂𝚂 𝙹𝙼 𝙲𝙴𝙻𝙻𝙿𝙷𝙾𝙽𝙴 𝚂𝙷𝙾𝙿
 *
 * Those are distinct codepoints, not a font, so they survive into the database and
 * break search, sorting, SMS and exports. This class stores them as:
 *
 *     Asia BLDG #2 BOSS JM CELLPHONE SHOP
 *
 * The heavy lifting is Unicode NFKC (compatibility composition), which is the
 * standard mapping from "styled variant" to "the character it stands for". It
 * already covers bold, italic, script, fraktur, double-struck, sans, monospace,
 * fullwidth, circled, squared, parenthesised, super/subscript, letterlike symbols
 * and ligatures — while leaving genuinely accented letters (José, Muñoz) intact.
 *
 * Only the gaps NFKC deliberately leaves alone are patched by hand below.
 */
class TextNormalizer
{
    /**
     * Invisible characters that carry no meaning in a name or address but do survive
     * into the column, where they corrupt exact-match lookups and make values that
     * look identical compare unequal. Zero-width joiners are included: outside emoji
     * sequences they exist only to pad or obfuscate text.
     */
    private const INVISIBLE = [
        "\u{00AD}", // soft hyphen
        "\u{200B}", // zero width space
        "\u{200C}", // zero width non-joiner
        "\u{200D}", // zero width joiner
        "\u{200E}", // left-to-right mark
        "\u{200F}", // right-to-left mark
        "\u{2060}", // word joiner
        "\u{2061}", "\u{2062}", "\u{2063}", "\u{2064}", // invisible maths operators
        "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", // bidi embedding/override
        "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}", // bidi isolates
        "\u{FEFF}", // byte order mark
    ];

    /**
     * Small-capital letters, drawn from the IPA and Phonetic Extensions blocks. The
     * "small caps" generators every fancy-text website offers use exactly these, and
     * NFKC leaves them untouched because they are real phonetic letters, not styled
     * duplicates. In a customer-facing form they are never phonetics.
     *
     * These fold to uppercase: they are capital letterforms and carry no case
     * information of their own, so "ʙᴏꜱꜱ ᴊᴍ" restores as "BOSS JM".
     *
     * Note that Unicode has no small capital X, so the generators emit a plain ASCII
     * "x" for it; that character is already normal and simply passes through in
     * whatever case it arrived.
     *
     * Deliberately absent: U+01EB (ǫ), which is a legitimate o-with-ogonek in several
     * languages and would be corrupted by folding it to "Q".
     */
    private const SMALL_CAPS = [
        "\u{1D00}" => 'A', "\u{0299}" => 'B', "\u{1D04}" => 'C', "\u{1D05}" => 'D',
        "\u{1D07}" => 'E', "\u{A730}" => 'F', "\u{0262}" => 'G', "\u{029C}" => 'H',
        "\u{026A}" => 'I', "\u{1D0A}" => 'J', "\u{1D0B}" => 'K', "\u{029F}" => 'L',
        "\u{1D0D}" => 'M', "\u{0274}" => 'N', "\u{1D0F}" => 'O', "\u{1D18}" => 'P',
        "\u{A7AF}" => 'Q', "\u{0280}" => 'R', "\u{A731}" => 'S', "\u{1D1B}" => 'T',
        "\u{1D1C}" => 'U', "\u{1D20}" => 'V', "\u{1D21}" => 'W', "\u{028F}" => 'Y',
        "\u{1D22}" => 'Z',
        "\u{1D01}" => 'AE', "\u{1D03}" => 'B', "\u{1D0C}" => 'L', "\u{1D2C}" => 'A',
        "\u{0261}" => 'g', // script g, the usual stand-in for a small-cap-styled g
    ];

    /**
     * Contiguous A-Z runs that NFKC has no decomposition for: negative (filled)
     * circled and negative squared Latin capitals, both popular in "bubble" and
     * "block" text generators.
     *
     * Regional indicator symbols (U+1F1E6-U+1F1FF) are intentionally NOT folded —
     * they are how flag emoji are encoded, and turning 🇵🇭 into "PH" would silently
     * rewrite an emoji rather than restore styled text.
     *
     * @var array<int, array{0:int, 1:int}> [firstCodepoint, lastCodepoint] => A..Z
     */
    private const CAPITAL_RUNS = [
        [0x1F150, 0x1F169], // 🅐-🅩 negative circled
        [0x1F170, 0x1F189], // 🅰-🆉 negative squared
    ];

    /** Lazily-built lookup table for {@see self::SMALL_CAPS} plus {@see self::CAPITAL_RUNS}. */
    private static ?array $replacements = null;

    /**
     * Normalize a single value.
     *
     * Non-strings (ints, bools, null, uploaded files) are returned untouched so this
     * is safe to run blindly over mixed request input.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public static function normalize($value)
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Invalid UTF-8 would make the normalizer bail and return false; leave such
        // input exactly as it arrived rather than silently emptying the field.
        if (! preg_match('//u', $value)) {
            return $value;
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        if ($normalized === false || $normalized === null) {
            $normalized = $value;
        }

        $normalized = strtr($normalized, self::replacements());

        // C0/C1 control characters, keeping tab, newline and carriage return so that
        // genuinely multi-line fields (installation_address) survive intact.
        $normalized = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', '', $normalized);

        return $normalized ?? $value;
    }

    /**
     * Normalize every string in an array, recursing into nested arrays.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $except  Keys to leave exactly as they are.
     * @return array<array-key, mixed>
     */
    public static function normalizeArray(array $data, array $except = []): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $except, true)) {
                continue;
            }

            $data[$key] = is_array($value)
                ? self::normalizeArray($value, $except)
                : self::normalize($value);
        }

        return $data;
    }

    /** Build (once) the strtr table of everything NFKC does not already handle. */
    private static function replacements(): array
    {
        if (self::$replacements !== null) {
            return self::$replacements;
        }

        $map = self::SMALL_CAPS;

        foreach (self::INVISIBLE as $char) {
            $map[$char] = '';
        }

        foreach (self::CAPITAL_RUNS as [$first, $last]) {
            for ($codepoint = $first; $codepoint <= $last; $codepoint++) {
                $map[mb_chr($codepoint, 'UTF-8')] = chr(ord('A') + ($codepoint - $first));
            }
        }

        return self::$replacements = $map;
    }
}
