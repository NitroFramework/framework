<?php

namespace Nitro\Process;

use RuntimeException;

/**
 * Answers the same command differently each time it is run.
 *
 *     Process::fake(['git pull' => Process::sequence()
 *         ->push(Process::result(exitCode: 1))
 *         ->push(Process::result('Already up to date.'))]);
 *
 * A single stub answers every call identically, which cannot express the case
 * a retry exists for: fails, then succeeds. Without this, testing a retry
 * means testing that it ran twice rather than that it recovered.
 */
class ResultSequence
{
    /** @var array<int, ProcessResult|string> */
    protected array $results;

    /** What to answer once the sequence is spent; null throws instead. */
    protected ProcessResult|string|null $whenEmpty = null;

    protected bool $failWhenEmpty = true;

    /** @param array<int, ProcessResult|string> $results */
    public function __construct(array $results = [])
    {
        $this->results = array_values($results);
    }

    /** Add a result to the end. */
    public function push(ProcessResult|string $result): static
    {
        $this->results[] = $result;

        return $this;
    }

    /** Answer this once the sequence is spent, rather than failing. */
    public function whenEmpty(ProcessResult|string $result): static
    {
        $this->whenEmpty = $result;
        $this->failWhenEmpty = false;

        return $this;
    }

    /**
     * Keep answering the last result rather than failing.
     *
     * For a command run an unknown number of times, where only the first few
     * answers matter.
     */
    public function dontFailWhenEmpty(): static
    {
        $this->failWhenEmpty = false;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    /**
     * The next result.
     *
     * Running out is an error by default: it means the code under test ran the
     * command more often than the test accounted for, which is worth hearing
     * about rather than quietly answering the same thing forever.
     *
     * @throws RuntimeException
     */
    public function next(): ProcessResult|string
    {
        if (! $this->isEmpty()) {
            return array_shift($this->results);
        }

        if ($this->whenEmpty !== null) {
            return $this->whenEmpty;
        }

        if ($this->failWhenEmpty) {
            throw new RuntimeException(
                'The process result sequence is empty — the command ran more times than the sequence allows. '
                . 'Add another result, or call dontFailWhenEmpty().'
            );
        }

        return new ProcessResult('', 0, '', '');
    }
}
