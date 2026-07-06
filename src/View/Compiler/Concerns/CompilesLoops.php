<?php

namespace Nitro\View\Compiler\Concerns;

/**
 * Blade compiler concern: @foreach/@for/@while/@forelse loops.
 */
trait CompilesLoops
{
    protected int $forElseCounter = 0;
    protected int $loopElseCounter = 0;

    // ==========================================
    // @loop
    // ==========================================

    protected function compileLoop(string $args): string
    {
        $empty = '$__loopEmpty_' . ++$this->loopElseCounter;

        // @loop($students as $pupil)
        if (preg_match('/\( *\$(\w+) +as +\$(\w+) *\)$/is', $args, $matches)) {
            $collection = trim($matches[1]);
            $singular = trim($matches[2]);
        }
        // @loop($students)
        elseif (preg_match('/\( *\$(\w+) *\)$/is', $args, $matches)) {
            $collection = trim($matches[1]);
            $singular = $this->singularize($collection);
        } else {
            return "<?php /* Invalid @loop syntax */ ?>";
        }

        return "<?php {$empty} = true; \$__currentLoopData = \${$collection}; \$this->addLoop(\$__currentLoopData); foreach(\$__currentLoopData as \${$singular}): \$this->incrementLoopIndices(); \$loop = \$this->getLastLoop(); {$empty} = false; ?>";
    }

    protected function compileEndloop(string $args): string
    {
        $empty = '$__loopEmpty_' . $this->loopElseCounter--;
        return "<?php endforeach; \$this->popLoop(); \$loop = \$this->getLastLoop(); if ({$empty}): ?><?php endif; ?>";
    }

    // When @empty is used inside @loop, it acts like forelse
    // This is handled by the existing compileEmpty method below

    // ==========================================
    // Singularize helper
    // ==========================================

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
            '/ies$/i'   => 'y',      // companies → company
            '/ves$/i'   => 'fe',     // lives → life
            '/oes$/i'   => 'o',      // heroes → hero
            '/sses$/i'  => 'ss',     // classes → class
            '/xes$/i'   => 'x',      // boxes → box
            '/zes$/i'   => 'z',      // quizzes handled by irregulars
            '/shes$/i'  => 'sh',     // dishes → dish
            '/ches$/i'  => 'ch',     // churches → church
            '/s$/i'     => '',       // students → student
        ];

        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $word)) {
                return preg_replace($pattern, $replacement, $word);
            }
        }

        return $word;
    }

    protected function compileForeach(string $args): string
    {
        preg_match('/\( *(.+) +as +(.*)\)$/is', $args, $matches);

        if (count($matches) === 0) {
            return "<?php /* Invalid @foreach syntax */ ?>";
        }

        $iteratee  = trim($matches[1]);
        $iteration = trim($matches[2]);

        return "<?php \$__currentLoopData = {$iteratee}; \$this->addLoop(\$__currentLoopData); foreach(\$__currentLoopData as {$iteration}): \$this->incrementLoopIndices(); \$loop = \$this->getLastLoop(); ?>";
    }

    protected function compileEndforeach(string $args): string
    {
        return '<?php endforeach; $this->popLoop(); $loop = $this->getLastLoop(); ?>';
    }

    protected function compileForelse(string $args): string
    {
        $empty = '$__empty_' . ++$this->forElseCounter;

        preg_match('/\( *(.+) +as +(.*)\)$/is', $args, $matches);

        if (count($matches) === 0) {
            return "<?php /* Invalid @forelse syntax */ ?>";
        }

        $iteratee  = trim($matches[1]);
        $iteration = trim($matches[2]);

        return "<?php {$empty} = true; \$__currentLoopData = {$iteratee}; \$this->addLoop(\$__currentLoopData); foreach(\$__currentLoopData as {$iteration}): \$this->incrementLoopIndices(); \$loop = \$this->getLastLoop(); {$empty} = false; ?>";
    }

    protected function compileEmpty(string $args): string
    {
        if (!empty($args)) {
            return "<?php if(empty{$args}): ?>";
        }

        // Only treat as @forelse companion when an @forelse is actually open.
        // Without this guard, a stray @empty corrupts the counter for any
        // @forelse that follows.
        if ($this->forElseCounter < 1) {
            throw new \LogicException('@empty without an argument can only follow @forelse.');
        }

        $empty = '$__empty_' . $this->forElseCounter--;
        return "<?php endforeach; \$this->popLoop(); \$loop = \$this->getLastLoop(); if ({$empty}): ?>";
    }

    protected function compileEndforelse(string $args): string
    {
        return '<?php endif; ?>';
    }

    protected function compileFor(string $args): string
    {
        return "<?php for{$args}: ?>";
    }

    protected function compileEndfor(string $args): string
    {
        return '<?php endfor; ?>';
    }

    protected function compileWhile(string $args): string
    {
        return "<?php while{$args}: ?>";
    }

    protected function compileEndwhile(string $args): string
    {
        return '<?php endwhile; ?>';
    }

    protected function compileBreak(string $args): string
    {
        if (!empty($args)) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $args, $matches);
            return $matches
                ? '<?php break ' . max(1, $matches[1]) . '; ?>'
                : "<?php if{$args} break; ?>";
        }
        return '<?php break; ?>';
    }

    protected function compileContinue(string $args): string
    {
        if (!empty($args)) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $args, $matches);
            return $matches
                ? '<?php continue ' . max(1, $matches[1]) . '; ?>'
                : "<?php if{$args} continue; ?>";
        }
        return '<?php continue; ?>';
    }
}
