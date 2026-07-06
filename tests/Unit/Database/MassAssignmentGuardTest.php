<?php

namespace Tests\Unit\Database;

use Nitro\Database\Model\BaseModel;
use PHPUnit\Framework\TestCase;

/**
 * Mass-assignment protection: with no $fillable declared, $guarded is the
 * blacklist and the default ['*'] sentinel means "totally guarded" — nothing
 * is mass-assignable until the developer opts in via $fillable. This mirrors
 * Laravel's safe default and closes the old ['id']-only gap.
 */
class MassAssignmentGuardTest extends TestCase
{
    public function test_default_guarded_star_blocks_all_mass_assignment(): void
    {
        $m = (new TotallyGuardedModel())->fill([
            'name'     => 'Alice',
            'is_admin' => true,
        ]);

        $this->assertNull($m->name);
        $this->assertNull($m->is_admin);
    }

    public function test_explicit_guarded_columns_block_only_those(): void
    {
        $m = (new ColumnGuardedModel())->fill([
            'name'   => 'Alice',
            'secret' => 'nope',
        ]);

        $this->assertSame('Alice', $m->name);
        $this->assertNull($m->secret);
    }

    public function test_fillable_whitelist_wins(): void
    {
        $m = (new FillableModel())->fill([
            'name'  => 'Alice',
            'email' => 'a@example.com',
            'role'  => 'admin',
        ]);

        $this->assertSame('Alice', $m->name);
        $this->assertSame('a@example.com', $m->email);
        $this->assertNull($m->role);
    }
}

class TotallyGuardedModel extends BaseModel
{
    protected string $table = 'stub';
}

class ColumnGuardedModel extends BaseModel
{
    protected string $table = 'stub';
    protected array $guarded = ['secret'];
}

class FillableModel extends BaseModel
{
    protected string $table = 'stub';
    protected array $fillable = ['name', 'email'];
}
