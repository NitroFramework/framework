<?php

namespace Tests\Unit\View;

use Nitro\Foundation\Application;
use Nitro\View\Contracts\ViewFinder;
use Nitro\View\Factory;
use PHPUnit\Framework\TestCase;

/**
 * A `.md` file rendered as a view.
 *
 * The view layer used to hold one extension and one compiler, so a template
 * could only ever be Blade. Markdown compiles through Blade first and converts
 * what that rendered, which is what lets a document use `{{ }}` and `@if` and
 * still extend a layout — and is why the code a document quotes has to be kept
 * out of the compiler's reach.
 */
class MarkdownViewTest extends TestCase
{
    private string $tmp;
    private Factory $views;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/nitro_md_view_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);

        $application = new Application(dirname(__DIR__, 3));
        $application->bootstrap();

        restore_error_handler();
        restore_exception_handler();

        $container = $application->getContainer();
        $container->resolve(ViewFinder::class)->prependLocation($this->tmp);

        $this->views = $container->resolve(Factory::class);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmp);

        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->tmp . '/' . $name, $contents);
    }

    // ─── Resolution ───────────────────────────────────────

    public function test_md_is_searched_for_alongside_blade(): void
    {
        $this->write('note.md', '# Note');

        $this->assertStringContainsString('<h1>Note</h1>', $this->views->make('note')->render());
    }

    /** The stated precedence: within one directory, the earlier extension wins. */
    public function test_blade_wins_when_both_extensions_exist(): void
    {
        $this->write('page.blade.php', 'blade wins');
        $this->write('page.md', '# md loses');

        $this->assertSame('blade wins', trim($this->views->make('page')->render()));
    }

    // ─── Compilation ──────────────────────────────────────

    public function test_an_expression_is_interpolated_inside_markdown(): void
    {
        $this->write('greet.md', '# Hello {{ $name }}');

        $this->assertStringContainsString(
            '<h1>Hello Zeeshan</h1>',
            $this->views->make('greet', ['name' => 'Zeeshan'])->render()
        );
    }

    /**
     * A controller returns a view name, never a filename, so the same call
     * reaches a `.md` page and passes it data the same way.
     */
    public function test_a_controller_passes_data_to_a_markdown_view(): void
    {
        $this->write('report.md', <<<'MD'
            # {{ $heading }}

            @foreach($rows as $row)
            - **{{ $row['name'] }}** — {{ $row['score'] }}
            @endforeach

            {{ count($rows) }} in total.
            MD);

        $heading = 'Quarterly scores';
        $rows    = [
            ['name' => 'Ayesha', 'score' => 92],
            ['name' => 'Bilal',  'score' => 87],
        ];

        $html = $this->views->make('report', compact('heading', 'rows'))->render();

        $this->assertStringContainsString('<h1>Quarterly scores</h1>', $html);
        $this->assertStringContainsString('<li><strong>Ayesha</strong> — 92</li>', $html);
        $this->assertStringContainsString('<li><strong>Bilal</strong> — 87</li>', $html);
        $this->assertStringContainsString('<p>2 in total.</p>', $html);
    }

    public function test_a_directive_runs_inside_markdown(): void
    {
        $this->write('list.md', "@foreach(\$items as \$item)\n- {{ \$item }}\n@endforeach");

        $html = $this->views->make('list', ['items' => ['one', 'two']])->render();

        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
    }

    // ─── Quoted code ──────────────────────────────────────

    /** A page documenting Blade has to be able to print an expression. */
    public function test_an_expression_inside_a_code_span_is_not_compiled(): void
    {
        $this->write('docs.md', 'Write `{{ $name }}` to print it.');

        $this->assertStringContainsString('<code>{{ $name }}</code>', $this->views->make('docs')->render());
    }

    public function test_a_directive_inside_a_fence_is_not_compiled(): void
    {
        $this->write('fence.md', "```blade\n@if(\$user)\n  {{ \$user->name }}\n@endif\n```");

        $html = $this->views->make('fence')->render();

        $this->assertStringContainsString('class="language-blade"', $html);
        $this->assertStringContainsString('@if($user)', $html);
        $this->assertStringContainsString('{{ $user-&gt;name }}', $html);
    }

    // ─── Front matter ─────────────────────────────────────

    public function test_front_matter_puts_the_page_in_a_layout(): void
    {
        $this->write('layout.blade.php', "<main>@yield('content')</main>");
        $this->write('post.md', "---\nextends: layout\n---\n# Posted");

        $html = $this->views->make('post')->render();

        $this->assertStringContainsString('<main>', $html);
        $this->assertStringContainsString('<h1>Posted</h1>', $html);
    }

    /** What the pageData carry is for: the layout reads a title the page declared. */
    public function test_front_matter_reaches_the_layout_it_extends(): void
    {
        $this->write('titled.blade.php', "<title>{{ \$title }}</title>@yield('content')");
        $this->write('page2.md', "---\nextends: titled\ntitle: From Front Matter\n---\n# Body");

        $this->assertStringContainsString('<title>From Front Matter</title>', $this->views->make('page2')->render());
    }

    public function test_front_matter_is_available_to_the_document_itself(): void
    {
        $this->write('self.md', "---\ntitle: Declared\n---\n# {{ \$title }}");

        $this->assertStringContainsString('<h1>Declared</h1>', $this->views->make('self')->render());
    }

    /** A controller computing a value knows more than the file does. */
    public function test_passed_data_wins_over_front_matter(): void
    {
        $this->write('beaten.md', "---\ntitle: From File\n---\n# {{ \$title }}");

        $this->assertStringContainsString(
            '<h1>From Caller</h1>',
            $this->views->make('beaten', ['title' => 'From Caller'])->render()
        );
    }

    public function test_front_matter_names_the_section_it_fills(): void
    {
        $this->write('two.blade.php', "[@yield('aside')][@yield('content')]");
        $this->write('side.md', "---\nextends: two\nsection: aside\n---\nAside text");

        $html = $this->views->make('side')->render();

        $this->assertStringContainsString('Aside text', $html);
        $this->assertStringStartsWith('[<p>Aside text</p>', trim($html));
    }

    // ─── Links ────────────────────────────────────────────

    /**
     * The configured attributes reach a rendered page, so following a link a
     * document wrote does not drop out of client-side navigation.
     */
    public function test_an_internal_link_in_a_document_carries_the_configured_attributes(): void
    {
        $this->write('linked.md', 'Read the [guide](/docs/install) or go [out](https://example.test).');

        $html = $this->views->make('linked')->render();

        $this->assertStringContainsString('<a href="/docs/install" wire:navigate>', $html);
        $this->assertStringContainsString('<a href="https://example.test">', $html);
    }

    // ─── Caching ──────────────────────────────────────────

    public function test_a_second_render_matches_the_first(): void
    {
        $this->write('warm.md', "---\ntitle: Warm\n---\n# {{ \$title }}\n\nBody.");

        $first = $this->views->make('warm')->render();

        $this->assertSame($first, $this->views->make('warm')->render());
    }
}
