<?php

namespace Nitro\Database\Query\Concerns;

use Nitro\Support\Carbon;

/**
 * Where clauses about now, without spelling out what now is.
 *
 *     ->wherePast('expires_at')
 *     ->whereToday('created_at')
 *     ->whereFuture(['starts_at', 'ends_at'])
 *
 * The comparison is the same one written by hand, and that is the point: a
 * query written as `where('expires_at', '<', Carbon::now())` says how, and one
 * written as `wherePast('expires_at')` says what — which is the thing a reader
 * is trying to find out.
 *
 * Each takes one column or several; several are ANDed together, so a row has
 * to satisfy all of them.
 */
trait BuildsWhereDateClauses
{
    // ─── Against this moment ──────────────────────────────

    /** @param array<int, string>|string $columns */
    public function wherePast(array|string $columns): static
    {
        return $this->whereAgainstNow($columns, '<', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWherePast(array|string $columns): static
    {
        return $this->whereAgainstNow($columns, '<', 'OR');
    }

    /** @param array<int, string>|string $columns */
    public function whereNowOrPast(array|string $columns): static
    {
        return $this->whereAgainstNow($columns, '<=', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWhereNowOrPast(array|string $columns): static
    {
        return $this->whereAgainstNow($columns, '<=', 'OR');
    }

    /** @param array<int, string>|string $columns */
    public function whereFuture(array|string $columns): static
    {
        return $this->whereAgainstNow($columns, '>', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWhereFuture(array|string $columns): static
    {
        return $this->whereAgainstNow($columns, '>', 'OR');
    }

    /** @param array<int, string>|string $columns */
    public function whereNowOrFuture(array|string $columns): static
    {
        return $this->whereAgainstNow($columns, '>=', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWhereNowOrFuture(array|string $columns): static
    {
        return $this->whereAgainstNow($columns, '>=', 'OR');
    }

    // ─── Against today's date ─────────────────────────────

    /**
     * The date part only, so a timestamp from earlier today still counts.
     *
     * @param array<int, string>|string $columns
     */
    public function whereToday(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '=', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWhereToday(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '=', 'OR');
    }

    /** @param array<int, string>|string $columns */
    public function whereBeforeToday(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '<', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWhereBeforeToday(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '<', 'OR');
    }

    /** @param array<int, string>|string $columns */
    public function whereTodayOrBefore(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '<=', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWhereTodayOrBefore(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '<=', 'OR');
    }

    /** @param array<int, string>|string $columns */
    public function whereAfterToday(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '>', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWhereAfterToday(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '>', 'OR');
    }

    /** @param array<int, string>|string $columns */
    public function whereTodayOrAfter(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '>=', 'AND');
    }

    /** @param array<int, string>|string $columns */
    public function orWhereTodayOrAfter(array|string $columns): static
    {
        return $this->whereAgainstToday($columns, '>=', 'OR');
    }

    // ─── Internals ────────────────────────────────────────

    /**
     * Compare each column to this moment.
     *
     * @param array<int, string>|string $columns
     */
    protected function whereAgainstNow(array|string $columns, string $operator, string $boolean): static
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');

        foreach ((array) $columns as $column) {
            $this->where($column, $operator, $now, $boolean);
        }

        return $this;
    }

    /**
     * Compare the date part of each column to today.
     *
     * @param array<int, string>|string $columns
     */
    protected function whereAgainstToday(array|string $columns, string $operator, string $boolean): static
    {
        $today = Carbon::today()->format('Y-m-d');

        foreach ((array) $columns as $column) {
            $this->whereDate($column, $operator, $today, $boolean);
        }

        return $this;
    }
}
