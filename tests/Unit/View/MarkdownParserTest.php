<?php

namespace Tests\Unit\View;

use Nitro\View\Markdown\FrontMatter;
use Nitro\View\Markdown\Parser;
use PHPUnit\Framework\TestCase;

/**
 * What the bundled Markdown parser produces, construct by construct.
 *
 * The parser is Nitro's own rather than a dependency, so the subset it covers
 * is defined here and nowhere else. The security cases matter most: a Markdown
 * document is often the least trusted text on a page, and raw HTML and
 * executable URL schemes are the two ways it becomes script.
 */
class MarkdownParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new Parser();
    }

    // ─── Blocks ───────────────────────────────────────────

    public function test_atx_headings_carry_their_level(): void
    {
        $this->assertStringContainsString('<h1>Title</h1>', $this->parser->toHtml('# Title'));
        $this->assertStringContainsString('<h4>Deep</h4>', $this->parser->toHtml('#### Deep'));
    }

    public function test_a_closing_run_of_hashes_is_not_part_of_the_heading(): void
    {
        $this->assertStringContainsString('<h2>Title</h2>', $this->parser->toHtml('## Title ##'));
    }

    public function test_blank_lines_separate_paragraphs(): void
    {
        $html = $this->parser->toHtml("one\n\ntwo");

        $this->assertStringContainsString('<p>one</p>', $html);
        $this->assertStringContainsString('<p>two</p>', $html);
    }

    public function test_an_unordered_list_becomes_list_items(): void
    {
        $this->assertStringContainsString(
            "<ul>\n<li>one</li>\n<li>two</li>\n</ul>",
            $this->parser->toHtml("- one\n- two")
        );
    }

    public function test_an_ordered_list_keeps_the_number_it_starts_at(): void
    {
        $this->assertStringContainsString('<ol start="3">', $this->parser->toHtml("3. three\n4. four"));
    }

    /** An indented marker belongs to the item above it, not beside it. */
    public function test_an_indented_list_nests(): void
    {
        $html = $this->parser->toHtml("- one\n  - deep\n- two");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>deep</li>', $html);
        $this->assertSame(2, substr_count($html, '<ul>'));
    }

    public function test_a_blank_line_between_items_makes_the_list_loose(): void
    {
        $this->assertStringContainsString('<p>one</p>', $this->parser->toHtml("- one\n\n- two"));
    }

    public function test_a_blockquote_parses_its_contents_as_blocks(): void
    {
        $this->assertStringContainsString(
            "<blockquote>\n<p>quoted</p>\n</blockquote>",
            $this->parser->toHtml('> quoted')
        );
    }

    public function test_a_fenced_block_keeps_its_language(): void
    {
        $this->assertStringContainsString(
            '<pre><code class="language-php">$a = 1;</code></pre>',
            $this->parser->toHtml("```php\n\$a = 1;\n```")
        );
    }

    public function test_four_spaces_is_a_code_block(): void
    {
        $this->assertStringContainsString('<pre><code>indented</code></pre>', $this->parser->toHtml('    indented'));
    }

    public function test_a_thematic_break_is_a_rule(): void
    {
        $this->assertStringContainsString('<hr>', $this->parser->toHtml("a\n\n---\n\nb"));
    }

    public function test_a_pipe_table_becomes_a_table(): void
    {
        $html = $this->parser->toHtml("| a | b |\n|---|---|\n| 1 | 2 |");

        $this->assertStringContainsString('<th>a</th>', $html);
        $this->assertStringContainsString('<td>2</td>', $html);
    }

    public function test_a_colon_in_the_delimiter_row_aligns_the_column(): void
    {
        $this->assertStringContainsString('text-align:right', $this->parser->toHtml("| a |\n|--:|\n| 1 |"));
    }

    // ─── Inline ───────────────────────────────────────────

    public function test_emphasis_and_strong(): void
    {
        $this->assertStringContainsString('<strong>bold</strong>', $this->parser->toHtml('a **bold** b'));
        $this->assertStringContainsString('<em>slanted</em>', $this->parser->toHtml('a *slanted* b'));
        $this->assertStringContainsString('<del>gone</del>', $this->parser->toHtml('a ~~gone~~ b'));
    }

    /** The reason underscores are only markers at a word boundary. */
    public function test_an_underscore_inside_a_word_is_not_emphasis(): void
    {
        $this->assertStringContainsString('snake_case_name', $this->parser->toHtml('a snake_case_name b'));
    }

    public function test_a_code_span_escapes_what_is_inside_it(): void
    {
        $this->assertStringContainsString('<code>&lt;b&gt;</code>', $this->parser->toHtml('use `<b>` here'));
    }

    public function test_links_and_images(): void
    {
        $this->assertStringContainsString(
            '<a href="https://nitro.test">docs</a>',
            $this->parser->toHtml('[docs](https://nitro.test)')
        );

        $this->assertStringContainsString(
            '<img src="/a.png" alt="alt">',
            $this->parser->toHtml('![alt](/a.png)')
        );
    }

    public function test_a_link_definition_is_removed_and_resolved(): void
    {
        $html = $this->parser->toHtml("[docs][k]\n\n[k]: https://nitro.test");

        $this->assertStringContainsString('<a href="https://nitro.test">docs</a>', $html);
        $this->assertStringNotContainsString('[k]:', $html);
    }

    public function test_an_angle_bracket_url_is_a_link(): void
    {
        $this->assertStringContainsString(
            '<a href="https://a.test">https://a.test</a>',
            $this->parser->toHtml('<https://a.test>')
        );
    }

    public function test_a_backslash_disarms_a_marker(): void
    {
        $html = $this->parser->toHtml('\*not em\*');

        $this->assertStringContainsString('*not em*', $html);
        $this->assertStringNotContainsString('<em>', $html);
    }

    /** Two trailing spaces, which is why a paragraph line is not right-trimmed. */
    public function test_two_trailing_spaces_end_the_line(): void
    {
        $this->assertStringContainsString('<br>', $this->parser->toHtml("a  \nb"));
    }

    // ─── Security ─────────────────────────────────────────

    public function test_raw_html_is_escaped_by_default(): void
    {
        $html = $this->parser->toHtml('<script>alert(1)</script>');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_raw_html_passes_through_only_when_it_is_turned_on(): void
    {
        $permissive = new Parser(allowHtml: true);

        $this->assertStringContainsString('<div class="note">', $permissive->toHtml("<div class=\"note\">\n\nkept\n\n</div>"));
    }

    public function test_a_script_url_is_dropped_from_a_link(): void
    {
        $this->assertStringContainsString('href=""', $this->parser->toHtml('[x](javascript:alert(1))'));
    }

    public function test_a_script_url_is_dropped_from_an_image(): void
    {
        $this->assertStringContainsString('src=""', $this->parser->toHtml('![x](javascript:alert(1))'));
    }

    public function test_a_relative_url_is_left_alone(): void
    {
        $this->assertStringContainsString('href="/docs/start"', $this->parser->toHtml('[x](/docs/start)'));
        $this->assertStringContainsString('href="#section"', $this->parser->toHtml('[x](#section)'));
    }

    public function test_html_inside_a_fence_stays_text(): void
    {
        $this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $this->parser->toHtml("```\n<b>hi</b>\n```"));
    }

    // ─── Internal link attributes ─────────────────────────

    /**
     * A link a document writes is a plain anchor, so following one is a full
     * page load. Attributes are configured rather than named here, so the
     * parser carries no opinion about what is doing the navigating.
     */
    private function navigating(?string $baseUrl = null, bool $fragments = false): Parser
    {
        return new Parser(
            linkAttributes: ['wire:navigate' => true],
            baseUrl: $baseUrl,
            fragments: $fragments,
        );
    }

    public function test_a_root_relative_link_is_internal(): void
    {
        $this->assertStringContainsString(
            '<a href="/docs/install" wire:navigate>',
            $this->navigating()->toHtml('[guide](/docs/install)')
        );
    }

    public function test_a_relative_link_is_internal(): void
    {
        $this->assertStringContainsString(
            '<a href="install" wire:navigate>',
            $this->navigating()->toHtml('[guide](install)')
        );
    }

    public function test_an_external_link_is_left_plain(): void
    {
        $html = $this->navigating('https://nitro.test')->toHtml('[out](https://example.test/page)');

        $this->assertStringContainsString('<a href="https://example.test/page">', $html);
        $this->assertStringNotContainsString('wire:navigate', $html);
    }

    public function test_an_absolute_link_to_our_own_host_is_internal(): void
    {
        $this->assertStringContainsString(
            'wire:navigate',
            $this->navigating('https://nitro.test')->toHtml('[home](https://nitro.test/docs)')
        );
    }

    /** With no base URL configured, an absolute link cannot be judged ours. */
    public function test_an_absolute_link_is_external_when_no_base_url_is_set(): void
    {
        $this->assertStringNotContainsString(
            'wire:navigate',
            $this->navigating()->toHtml('[home](https://nitro.test/docs)')
        );
    }

    public function test_a_fragment_is_skipped_unless_it_is_turned_on(): void
    {
        $this->assertStringNotContainsString('wire:navigate', $this->navigating()->toHtml('[top](#top)'));
        $this->assertStringContainsString('wire:navigate', $this->navigating(null, true)->toHtml('[top](#top)'));
    }

    public function test_a_mail_link_never_navigates(): void
    {
        $html = $this->navigating()->toHtml('[mail](mailto:a@b.test)');

        $this->assertStringContainsString('href="mailto:a@b.test"', $html);
        $this->assertStringNotContainsString('wire:navigate', $html);
    }

    public function test_a_dropped_script_url_never_navigates(): void
    {
        $this->assertStringNotContainsString('wire:navigate', $this->navigating()->toHtml('[x](javascript:alert(1))'));
    }

    public function test_a_reference_link_navigates_too(): void
    {
        $this->assertStringContainsString(
            'wire:navigate',
            $this->navigating()->toHtml("[guide][k]\n\n[k]: /docs/install")
        );
    }

    public function test_an_autolink_to_our_own_host_navigates(): void
    {
        $this->assertStringContainsString(
            'wire:navigate',
            $this->navigating('https://nitro.test')->toHtml('<https://nitro.test/docs>')
        );
    }

    public function test_an_image_never_gets_link_attributes(): void
    {
        $this->assertStringNotContainsString('wire:navigate', $this->navigating()->toHtml('![alt](/a.png)'));
    }

    public function test_a_valued_attribute_is_rendered_with_its_value(): void
    {
        $parser = new Parser(linkAttributes: ['wire:navigate.hover' => true, 'data-kind' => 'doc']);

        $this->assertStringContainsString(
            '<a href="/docs" wire:navigate.hover data-kind="doc">',
            $parser->toHtml('[d](/docs)')
        );
    }

    /** Names go into markup unquoted, so they are checked rather than trusted. */
    public function test_an_unusable_attribute_name_is_dropped(): void
    {
        $parser = new Parser(linkAttributes: ['onclick="x" bad' => true]);

        $this->assertStringContainsString('<a href="/docs">', $parser->toHtml('[d](/docs)'));
    }

    public function test_nothing_is_added_when_no_attributes_are_configured(): void
    {
        $this->assertStringContainsString('<a href="/docs">', $this->parser->toHtml('[d](/docs)'));
    }

    // ─── Front matter ─────────────────────────────────────

    public function test_front_matter_is_split_off_and_typed(): void
    {
        [$matter, $body] = FrontMatter::split("---\ntitle: Start\ndraft: false\norder: 3\ntags: [a, b]\n---\n# Heading\n");

        $this->assertSame('Start', $matter['title']);
        $this->assertFalse($matter['draft']);
        $this->assertSame(3, $matter['order']);
        $this->assertSame(['a', 'b'], $matter['tags']);
        $this->assertSame("# Heading\n", $body);
    }

    public function test_a_document_without_front_matter_is_left_whole(): void
    {
        [$matter, $body] = FrontMatter::split("# Heading\n");

        $this->assertSame([], $matter);
        $this->assertSame("# Heading\n", $body);
    }

    /** A rule at the top of a document is not an unterminated front matter block. */
    public function test_a_leading_thematic_break_is_not_front_matter(): void
    {
        [$matter] = FrontMatter::split("---\n\n# Heading\n");

        $this->assertSame([], $matter);
    }
}
