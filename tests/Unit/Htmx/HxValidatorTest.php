<?php

namespace Tests\Unit\Htmx;

use Nitro\Htmx\Support\HxValidationErrors;
use Nitro\Htmx\Support\HxValidator;
use PHPUnit\Framework\TestCase;

/**
 * Rule-by-rule coverage for the HTMX form validator.
 *
 * HxValidator is a pure, container-free unit: validate($data, $rules, $files)
 * returns an HxValidationErrors bag. Scalar rules read from $data; the
 * file rules (file/image/mimes/max_size and required-when-uploaded) read
 * ONLY from the $files argument — never $_FILES directly. The seam wiring
 * that sources $files from the request is proved in HasValidationFileSeamTest.
 */
class HxValidatorTest extends TestCase
{
    private HxValidator $v;

    protected function setUp(): void
    {
        $this->v = new HxValidator();
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $files */
    private function validateWith(array $data, array $rules, array $files = []): HxValidationErrors
    {
        return $this->v->validate($data, $rules, $files);
    }
    // (helper renamed from run() — PHPUnit's TestCase::run() is final)

    private function upload(string $name = 'photo.png', int $size = 1024, int $error = UPLOAD_ERR_OK, string $tmp = ''): array
    {
        return ['name' => $name, 'size' => $size, 'error' => $error, 'tmp_name' => $tmp];
    }

    // ── required ─────────────────────────────────────────────────────────

    public function test_required_flags_empty_values(): void
    {
        foreach (['', null, []] as $empty) {
            $errors = $this->validateWith(['field' => $empty], ['field' => 'required']);
            $this->assertTrue($errors->has('field'), 'empty value should fail required');
        }
    }

    public function test_required_passes_with_a_value(): void
    {
        $this->assertFalse($this->validateWith(['field' => 'x'], ['field' => 'required'])->any());
        $this->assertFalse($this->validateWith(['field' => '0'], ['field' => 'required'])->any());
        $this->assertFalse($this->validateWith(['field' => 0], ['field' => 'required'])->any());
    }

    public function test_required_is_satisfied_by_an_uploaded_file(): void
    {
        // Field is empty in $data but present as an upload → required passes.
        $errors = $this->validateWith(
            ['avatar' => null],
            ['avatar' => 'required'],
            ['avatar' => $this->upload()],
        );
        $this->assertFalse($errors->any());
    }

    public function test_rules_accept_array_syntax_not_just_pipe_strings(): void
    {
        $errors = $this->validateWith(['email' => 'nope'], ['email' => ['required', 'email']]);
        $this->assertTrue($errors->has('email'));
    }

    public function test_first_rule_to_fail_short_circuits_the_field(): void
    {
        $errors = $this->validateWith(['email' => ''], ['email' => 'required|email']);
        // Only the required error is recorded; email is not also evaluated.
        $this->assertCount(1, $errors->get('email'));
        $this->assertStringContainsString('required', $errors->first('email'));
    }

    // ── string / format rules ────────────────────────────────────────────

    public function test_email_rule(): void
    {
        $this->assertTrue($this->validateWith(['e' => 'bad'], ['e' => 'email'])->has('e'));
        $this->assertFalse($this->validateWith(['e' => 'a@b.co'], ['e' => 'email'])->any());
        // Empty is allowed by non-required format rules.
        $this->assertFalse($this->validateWith(['e' => ''], ['e' => 'email'])->any());
    }

    public function test_url_rule(): void
    {
        $this->assertTrue($this->validateWith(['u' => 'not a url'], ['u' => 'url'])->has('u'));
        $this->assertFalse($this->validateWith(['u' => 'https://nitro.test/x'], ['u' => 'url'])->any());
    }

    public function test_numeric_and_integer_rules(): void
    {
        $this->assertTrue($this->validateWith(['n' => 'abc'], ['n' => 'numeric'])->has('n'));
        $this->assertFalse($this->validateWith(['n' => '12.5'], ['n' => 'numeric'])->any());

        $this->assertTrue($this->validateWith(['i' => '12.5'], ['i' => 'integer'])->has('i'));
        $this->assertFalse($this->validateWith(['i' => '42'], ['i' => 'integer'])->any());
    }

    public function test_alpha_and_alpha_numeric_rules(): void
    {
        $this->assertTrue($this->validateWith(['a' => 'abc123'], ['a' => 'alpha'])->has('a'));
        $this->assertFalse($this->validateWith(['a' => 'abc'], ['a' => 'alpha'])->any());

        $this->assertTrue($this->validateWith(['a' => 'abc 123'], ['a' => 'alpha_numeric'])->has('a'));
        $this->assertFalse($this->validateWith(['a' => 'abc123'], ['a' => 'alpha_numeric'])->any());
    }

    public function test_regex_rule(): void
    {
        $this->assertTrue($this->validateWith(['z' => 'abc'], ['z' => 'regex:/^\d+$/'])->has('z'));
        $this->assertFalse($this->validateWith(['z' => '123'], ['z' => 'regex:/^\d+$/'])->any());
    }

    public function test_in_and_not_in_rules(): void
    {
        $this->assertTrue($this->validateWith(['c' => 'x'], ['c' => 'in:a,b,c'])->has('c'));
        $this->assertFalse($this->validateWith(['c' => 'b'], ['c' => 'in:a,b,c'])->any());

        $this->assertTrue($this->validateWith(['c' => 'b'], ['c' => 'not_in:a,b,c'])->has('c'));
        $this->assertFalse($this->validateWith(['c' => 'z'], ['c' => 'not_in:a,b,c'])->any());
    }

    // ── min / max (dual numeric vs. string-length) ───────────────────────

    public function test_min_and_max_measure_length_for_strings(): void
    {
        $this->assertTrue($this->validateWith(['s' => 'ab'], ['s' => 'min:3'])->has('s'));
        $this->assertFalse($this->validateWith(['s' => 'abc'], ['s' => 'min:3'])->any());

        $this->assertTrue($this->validateWith(['s' => 'abcd'], ['s' => 'max:3'])->has('s'));
        $this->assertFalse($this->validateWith(['s' => 'abc'], ['s' => 'max:3'])->any());
    }

    public function test_min_and_max_compare_magnitude_for_numbers(): void
    {
        $this->assertTrue($this->validateWith(['n' => 2], ['n' => 'min:5'])->has('n'));
        $this->assertFalse($this->validateWith(['n' => 9], ['n' => 'min:5'])->any());

        $this->assertTrue($this->validateWith(['n' => 9], ['n' => 'max:5'])->has('n'));
        $this->assertFalse($this->validateWith(['n' => 3], ['n' => 'max:5'])->any());
    }

    // ── cross-field rules ────────────────────────────────────────────────

    public function test_confirmed_rule(): void
    {
        $ok = $this->validateWith(
            ['password' => 'secret', 'password_confirmation' => 'secret'],
            ['password' => 'confirmed'],
        );
        $this->assertFalse($ok->any());

        $bad = $this->validateWith(
            ['password' => 'secret', 'password_confirmation' => 'nope'],
            ['password' => 'confirmed'],
        );
        $this->assertTrue($bad->has('password'));
    }

    public function test_same_rule(): void
    {
        $ok = $this->validateWith(['a' => '1', 'b' => '1'], ['a' => 'same:b']);
        $this->assertFalse($ok->any());

        $bad = $this->validateWith(['a' => '1', 'b' => '2'], ['a' => 'same:b']);
        $this->assertTrue($bad->has('a'));
    }

    public function test_unknown_rule_is_a_noop(): void
    {
        $this->assertFalse($this->validateWith(['x' => 'y'], ['x' => 'totally_made_up'])->any());
    }

    // ── file rules (read from $files, not $_FILES) ───────────────────────

    public function test_file_rule_reports_upload_errors(): void
    {
        $errors = $this->validateWith([], ['doc' => 'file'], ['doc' => $this->upload(error: UPLOAD_ERR_INI_SIZE)]);
        $this->assertTrue($errors->has('doc'));
        $this->assertStringContainsString('size limit', $errors->first('doc'));
    }

    public function test_file_rule_passes_for_a_clean_upload_and_ignores_absent(): void
    {
        $this->assertFalse($this->validateWith([], ['doc' => 'file'], ['doc' => $this->upload()])->any());
        // No file at all → file rule is a no-op (use required to force presence).
        $this->assertFalse($this->validateWith([], ['doc' => 'file'], [])->any());
    }

    public function test_mimes_rule_checks_the_extension(): void
    {
        $files = ['doc' => $this->upload('report.pdf')];
        $this->assertFalse($this->validateWith([], ['doc' => 'mimes:pdf,docx'], $files)->any());

        $files = ['doc' => $this->upload('malware.exe')];
        $errors = $this->validateWith([], ['doc' => 'mimes:pdf,docx'], $files);
        $this->assertTrue($errors->has('doc'));
        $this->assertStringContainsString('pdf', $errors->first('doc'));
    }

    public function test_max_size_rule_parses_units(): void
    {
        // 2kb limit; a 3kb upload must fail, a 1kb upload must pass.
        $this->assertTrue($this->validateWith([], ['f' => 'max_size:2kb'], ['f' => $this->upload(size: 3 * 1024)])->has('f'));
        $this->assertFalse($this->validateWith([], ['f' => 'max_size:2kb'], ['f' => $this->upload(size: 1 * 1024)])->any());
    }

    public function test_image_rule_uses_real_file_content(): void
    {
        // 1x1 transparent PNG written to a real temp file so finfo can sniff it.
        $tmp = tempnam(sys_get_temp_dir(), 'hxv');
        file_put_contents($tmp, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMDAgGdmQ4AAAAASUVORK5CYII='
        ));

        try {
            $ok = $this->validateWith([], ['avatar' => 'image'], ['avatar' => $this->upload('a.png', tmp: $tmp)]);
            $this->assertFalse($ok->any(), 'a real PNG should pass the image rule');

            // Same bytes but claim it via a non-image: content wins, still an image,
            // so instead prove a non-image file fails by pointing at a text tmp file.
            $txt = tempnam(sys_get_temp_dir(), 'hxv');
            file_put_contents($txt, 'just text, not an image');
            $bad = $this->validateWith([], ['avatar' => 'image'], ['avatar' => $this->upload('a.png', tmp: $txt)]);
            $this->assertTrue($bad->has('avatar'), 'a text file should fail the image rule');
            @unlink($txt);
        } finally {
            @unlink($tmp);
        }
    }
}
