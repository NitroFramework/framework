<?php

namespace Nitro\View\Compiler\Concerns;

use LogicException;

/**
 * Compiles the directives that repeat markup.
 *
 * Beyond PHP's own loops these maintain the `$loop` variable, which is why the
 * compiled output calls back into the renderer at each boundary.
 */
trait CompilesLoops
{
    /**
     * Nesting depth of open `@forelse` blocks, so each gets its own flag.
     */
    protected int $forElseCounter = 0;

    /** Nesting depth of open `@loop` blocks, for the same reason. */
    protected int $loopElseCounter = 0;

    // ─── @loop ────────────────────────────────────────────

    /**
     * `@loop($students as $pupil)` or `@loop($students)` — iterate, naming the
     * item explicitly or letting the collection's name imply it.
     */
    protected function compileLoop(string $args): string
    {
        $empty = '$__loopEmpty_' . ++$this->loopElseCounter;

        if (preg_match('/\( *\$(\w+) +as +\$(\w+) *\)$/is', $args, $matches)) {
            $collection = trim($matches[1]);
            $singular = trim($matches[2]);
        } elseif (preg_match('/\( *\$(\w+) *\)$/is', $args, $matches)) {
            $collection = trim($matches[1]);
            $singular = $this->singularize($collection);
        } else {
            return '<?php /* Invalid @loop syntax */ ?>';
        }

        return "<?php {$empty} = true; \$__currentLoopData = \${$collection}; \$this->addLoop(\$__currentLoopData); foreach(\$__currentLoopData as \${$singular}): \$this->incrementLoopIndices(); \$loop = \$this->getLastLoop(); {$empty} = false; ?>";
    }

    /**
     * `@endloop` — closes `@loop` and restores the enclosing loop's `$loop`.
     */
    protected function compileEndloop(string $args): string
    {
        $empty = '$__loopEmpty_' . $this->loopElseCounter--;

        return "<?php endforeach; \$this->popLoop(); \$loop = \$this->getLastLoop(); if ({$empty}): ?><?php endif; ?>";
    }

    /**
     * Get the singular of a collection name, for `@loop`'s short form.
     */
    protected function singularize(string $word): string
    {
        $irregulars = [
            'people'     => 'person',
            'children'   => 'child',
            'men'        => 'man',
            'women'      => 'woman',
            'mice'       => 'mouse',
            'categories' => 'category',
            'statuses'   => 'status',
            'classes'    => 'class',
            'addresses'  => 'address',
            'quizzes'    => 'quiz',
            'buses'      => 'bus',
            'heroes'     => 'hero',
            'potatoes'   => 'potato',
            'tomatoes'   => 'tomato',
            'analyses'   => 'analysis',
            'criteria'   => 'criterion',
            'data'       => 'datum',
            'indices'    => 'index',
            'matrices'   => 'matrix',
            'vertices'   => 'vertex',
            'lives'      => 'life',
            'wives'      => 'wife',
            'knives'     => 'knife',
            'shelves'    => 'shelf',
            'series'     => 'series',
            'species'    => 'species',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        $rules = [
            '/ies$/i'  => 'y',
            '/ves$/i'  => 'fe',
            '/oes$/i'  => 'o',
            '/sses$/i' => 'ss',
            '/xes$/i'  => 'x',
            '/zes$/i'  => 'z',
            '/shes$/i' => 'sh',
            '/ches$/i' => 'ch',
            '/s$/i'    => '',
        ];

        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $word)) {
                return preg_replace($pattern, $replacement, $word);
            }
        }

        return $word;
    }

    // ─── foreach ──────────────────────────────────────────

    /**
     * `@foreach($items as $item)` — iterate, with `$loop` available inside.
     */
    protected function compileForeach(string $args): string
    {
        preg_match('/\( *(.+) +as +(.*)\)$/is', $args, $matches);

        if (count($matches) === 0) {
            return '<?php /* Invalid @foreach syntax */ ?>';
        }

        $iteratee  = trim($matches[1]);
        $iteration = trim($matches[2]);

        return "<?php \$__currentLoopData = {$iteratee}; \$this->addLoop(\$__currentLoopData); foreach(\$__currentLoopData as {$iteration}): \$this->incrementLoopIndices(); \$loop = \$this->getLastLoop(); ?>";
    }

    /**
     * `@endforeach` — closes `@foreach` and restores the enclosing `$loop`.
     */
    protected function compileEndforeach(string $args): string
    {
        return '<?php endforeach; $this->popLoop(); $loop = $this->getLastLoop(); ?>';
    }

    // ─── forelse ──────────────────────────────────────────

    /**
     * `@forelse($items as $item)` — iterate, with an `@empty` branch.
     *
     * Emptiness is flagged rather than counted, since the collection may be a
     * generator.
     */
    protected function compileForelse(string $args): string
    {
        $empty = '$__empty_' . ++$this->forElseCounter;

        preg_match('/\( *(.+) +as +(.*)\)$/is', $args, $matches);

        if (count($matches) === 0) {
            return '<?php /* Invalid @forelse syntax */ ?>';
        }

        $iteratee  = trim($matches[1]);
        $iteration = trim($matches[2]);

        return "<?php {$empty} = true; \$__currentLoopData = {$iteratee}; \$this->addLoop(\$__currentLoopData); foreach(\$__currentLoopData as {$iteration}): \$this->incrementLoopIndices(); \$loop = \$this->getLastLoop(); {$empty} = false; ?>";
    }

    /**
     * `@empty($value)` as a test, or bare `@empty` as `@forelse`'s companion.
     *
     * @throws LogicException When bare `@empty` appears outside `@forelse`.
     */
    protected function compileEmpty(string $args): string
    {
        if (! empty($args)) {
            return "<?php if(empty{$args}): ?>";
        }

        if ($this->forElseCounter < 1) {
            throw new LogicException('@empty without an argument can only follow @forelse.');
        }

        $empty = '$__empty_' . $this->forElseCounter--;

        return "<?php endforeach; \$this->popLoop(); \$loop = \$this->getLastLoop(); if ({$empty}): ?>";
    }

    /**
     * `@endforelse` — closes the `@empty` branch that `@forelse` opened.
     */
    protected function compileEndforelse(string $args): string
    {
        return '<?php endif; ?>';
    }

    // ─── for / while ──────────────────────────────────────

    /**
     * `@for($i = 0; $i < 10; $i++)` — PHP's own loop, no `$loop` variable.
     */
    protected function compileFor(string $args): string
    {
        return "<?php for{$args}: ?>";
    }

    /**
     * `@endfor` — closes `@for`.
     */
    protected function compileEndfor(string $args): string
    {
        return '<?php endfor; ?>';
    }

    /**
     * `@while($condition)` — repeat while the condition holds.
     */
    protected function compileWhile(string $args): string
    {
        return "<?php while{$args}: ?>";
    }

    /**
     * `@endwhile` — closes `@while`.
     */
    protected function compileEndwhile(string $args): string
    {
        return '<?php endwhile; ?>';
    }

    // ─── break / continue ─────────────────────────────────

    /**
     * `@break`, `@break(2)` to leave nested loops, or `@break($condition)`.
     */
    protected function compileBreak(string $args): string
    {
        if (! empty($args)) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $args, $matches);

            return $matches
                ? '<?php break ' . max(1, $matches[1]) . '; ?>'
                : "<?php if{$args} break; ?>";
        }

        return '<?php break; ?>';
    }

    /**
     * `@continue`, `@continue(2)` or `@continue($condition)`.
     */
    protected function compileContinue(string $args): string
    {
        if (! empty($args)) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $args, $matches);

            return $matches
                ? '<?php continue ' . max(1, $matches[1]) . '; ?>'
                : "<?php if{$args} continue; ?>";
        }

        return '<?php continue; ?>';
    }
}
