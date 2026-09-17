<?php

namespace Tests\Unit\Database;

use Nitro\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * Change tracking, attribute access, mass assignment, serialisation control,
 * identity and route binding on the model.
 */
class ModelSurfaceTest extends TestCase
{
    private function model(array $attributes = [], bool $exists = true): Model
    {
        $model = new SurfaceModel();
        $model->setRawAttributes($attributes, true);

        if ($exists) {
            $this->markAsExisting($model);
        }

        return $model;
    }

    private function markAsExisting(Model $model): void
    {
        (new \ReflectionProperty(Model::class, 'exists'))->setValue($model, true);
    }

    private function existsOn(Model $model): bool
    {
        return (new \ReflectionProperty(Model::class, 'exists'))->getValue($model);
    }

    // ─── Change tracking ──────────────────────────────────

    public function test_is_clean_is_the_inverse_of_is_dirty(): void
    {
        $model = $this->model(['name' => 'first']);

        $this->assertTrue($model->isClean());
        $this->assertFalse($model->isDirty());

        $model->name = 'second';

        $this->assertTrue($model->isDirty());
        $this->assertFalse($model->isClean());
        $this->assertTrue($model->isDirty('name'));
        $this->assertTrue($model->isClean('other'));
    }

    public function test_sync_changes_records_what_changed(): void
    {
        $model = $this->model(['name' => 'first', 'role' => 'user']);

        $model->name = 'second';
        $model->syncChanges();

        $this->assertSame(['name' => 'second'], $model->getChanges());
        $this->assertTrue($model->wasChanged());
        $this->assertTrue($model->wasChanged('name'));
        $this->assertFalse($model->wasChanged('role'));
        $this->assertTrue($model->wasChanged(['role', 'name']));
    }

    public function test_was_changed_is_false_before_a_save(): void
    {
        $model = $this->model(['name' => 'first']);
        $model->name = 'second';

        $this->assertFalse($model->wasChanged());
    }

    public function test_discard_changes_restores_the_loaded_values(): void
    {
        $model = $this->model(['name' => 'first']);

        $model->name = 'second';
        $model->discardChanges();

        $this->assertSame('first', $model->name);
        $this->assertTrue($model->isClean());
    }

    public function test_sync_original_attribute(): void
    {
        $model = $this->model(['name' => 'first', 'role' => 'user']);

        $model->name = 'second';
        $model->role = 'admin';
        $model->syncOriginalAttribute('name');

        $this->assertFalse($model->isDirty('name'));
        $this->assertTrue($model->isDirty('role'));
    }

    public function test_original_is_equivalent(): void
    {
        $model = $this->model(['name' => 'first']);

        $this->assertTrue($model->originalIsEquivalent('name'));

        $model->name = 'second';

        $this->assertFalse($model->originalIsEquivalent('name'));
    }

    // ─── Attributes ───────────────────────────────────────

    public function test_raw_attribute_access(): void
    {
        $model = $this->model(['name' => 'Ada', 'role' => 'admin']);

        $this->assertSame(['name' => 'Ada', 'role' => 'admin'], $model->getAttributes());
        $this->assertTrue($model->hasAttribute('name'));
        $this->assertFalse($model->hasAttribute('nope'));
        $this->assertSame('Ada', $model->getAttributeValue('name'));
    }

    public function test_only_and_except(): void
    {
        $model = $this->model(['name' => 'Ada', 'role' => 'admin', 'age' => 36]);

        $this->assertSame(['name' => 'Ada'], $model->only('name'));
        $this->assertSame(['name' => 'Ada', 'role' => 'admin'], $model->only(['name', 'role']));
        $this->assertSame(['age' => 36], $model->except(['name', 'role']));
    }

    public function test_has_cast_and_merge_casts(): void
    {
        $model = $this->model(['count' => '5']);

        $this->assertFalse($model->hasCast('count'));

        $model->mergeCasts(['count' => 'integer']);

        $this->assertTrue($model->hasCast('count'));
        $this->assertTrue($model->hasCast('count', 'integer'));
        $this->assertFalse($model->hasCast('count', 'boolean'));
        $this->assertSame(5, $model->count);
    }

    // ─── Mass assignment ──────────────────────────────────

    public function test_is_fillable_respects_the_fillable_list(): void
    {
        $model = new SurfaceModel();
        $model->fillable(['name']);

        $this->assertTrue($model->isFillable('name'));
        $this->assertFalse($model->isFillable('role'));
    }

    public function test_is_guarded(): void
    {
        $model = new SurfaceModel();
        $model->fillable([]);
        $model->guard(['role']);

        $this->assertTrue($model->isGuarded('role'));
        $this->assertFalse($model->isGuarded('name'));
        $this->assertTrue($model->isFillable('name'));
    }

    public function test_totally_guarded(): void
    {
        $model = new SurfaceModel();
        $model->fillable([]);
        $model->guard(['*']);

        $this->assertTrue($model->totallyGuarded());
    }

    public function test_merge_fillable_and_guarded(): void
    {
        $model = new SurfaceModel();
        $model->fillable(['name']);
        $model->mergeFillable(['role']);

        $this->assertSame(['name', 'role'], $model->getFillable());

        $model->guard(['a']);
        $model->mergeGuarded(['b']);

        $this->assertSame(['a', 'b'], $model->getGuarded());
    }

    public function test_unguarded_runs_a_callback_without_protection(): void
    {
        $model = new SurfaceModel();
        $model->fillable(['name']);

        $this->assertFalse($model->isFillable('role'));

        $seen = SurfaceModel::unguarded(fn (): bool => $model->isFillable('role'));

        $this->assertTrue($seen);
        $this->assertFalse(SurfaceModel::isUnguarded(), 'protection must be restored');
        $this->assertFalse($model->isFillable('role'));
    }

    /** Protection is restored even when the callback throws. */
    public function test_unguarded_restores_after_an_exception(): void
    {
        try {
            SurfaceModel::unguarded(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(SurfaceModel::isUnguarded());
    }

    // ─── Serialisation control ────────────────────────────

    public function test_make_hidden_and_visible(): void
    {
        $model = $this->model(['name' => 'Ada', 'secret' => 'x']);

        $this->assertArrayHasKey('secret', $model->toArray());

        $model->makeHidden('secret');

        $this->assertArrayNotHasKey('secret', $model->toArray());

        $model->makeVisible('secret');

        $this->assertArrayHasKey('secret', $model->toArray());
    }

    public function test_set_visible_limits_the_output(): void
    {
        $model = $this->model(['name' => 'Ada', 'role' => 'admin', 'age' => 36]);

        $model->setVisible(['name']);

        $this->assertSame(['name' => 'Ada'], $model->toArray());
        $this->assertSame(['name'], $model->getVisible());
    }

    public function test_conditional_visibility(): void
    {
        $model = $this->model(['name' => 'Ada', 'secret' => 'x']);

        $model->makeHiddenIf(false, 'secret');
        $this->assertArrayHasKey('secret', $model->toArray());

        $model->makeHiddenIf(true, 'secret');
        $this->assertArrayNotHasKey('secret', $model->toArray());
    }

    public function test_appends_add_accessor_output(): void
    {
        $model = $this->model(['first' => 'Ada', 'last' => 'Byron']);

        $model->append('full_name');

        $array = $model->toArray();

        $this->assertSame('Ada Byron', $array['full_name']);
        $this->assertTrue($model->hasAppended('full_name'));
        $this->assertSame(['full_name'], $model->getAppends());

        $model->withoutAppends();

        $this->assertArrayNotHasKey('full_name', $model->toArray());
    }

    public function test_json_serializable(): void
    {
        $model = $this->model(['name' => 'Ada']);

        $this->assertSame('{"name":"Ada"}', json_encode($model));
        $this->assertStringContainsString("\n", $model->toPrettyJson());
    }

    // ─── Identity ─────────────────────────────────────────

    public function test_is_and_is_not(): void
    {
        $first = $this->model(['id' => 1]);
        $same = $this->model(['id' => 1]);
        $other = $this->model(['id' => 2]);

        $this->assertTrue($first->is($same));
        $this->assertFalse($first->is($other));
        $this->assertTrue($first->isNot($other));
        $this->assertFalse($first->is(null));
    }

    /** A model with no key is not the same record as anything. */
    public function test_is_requires_a_key(): void
    {
        $this->assertFalse($this->model()->is($this->model()));
    }

    public function test_qualify_column(): void
    {
        $model = new SurfaceModel();

        $this->assertSame('surfaces.name', $model->qualifyColumn('name'));
        $this->assertSame('other.name', $model->qualifyColumn('other.name'));
        $this->assertSame('surfaces.id', $model->getQualifiedKeyName());
        $this->assertSame(['surfaces.a', 'surfaces.b'], $model->qualifyColumns(['a', 'b']));
    }

    public function test_key_and_page_configuration(): void
    {
        $model = new SurfaceModel();

        $this->assertSame('int', $model->getKeyType());
        $this->assertSame(15, $model->getPerPage());

        $model->setPerPage(50);
        $model->setKeyName('uuid');

        $this->assertSame(50, $model->getPerPage());
        $this->assertSame('uuid', $model->getKeyName());
    }

    // ─── Route binding ────────────────────────────────────

    public function test_route_key(): void
    {
        $model = $this->model(['id' => 7]);

        $this->assertSame('id', $model->getRouteKeyName());
        $this->assertSame(7, $model->getRouteKey());
    }

    // ─── Timestamps ───────────────────────────────────────

    public function test_timestamp_columns(): void
    {
        $model = new SurfaceModel();

        $this->assertSame('created_at', $model->getCreatedAtColumn());
        $this->assertSame('updated_at', $model->getUpdatedAtColumn());
        $this->assertSame('surfaces.created_at', $model->getQualifiedCreatedAtColumn());
    }

    public function test_fresh_timestamp(): void
    {
        $model = new SurfaceModel();

        $this->assertInstanceOf(\Nitro\Support\Carbon::class, $model->freshTimestamp());
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $model->freshTimestampString()
        );
    }

    public function test_update_timestamps_sets_both_on_a_new_model(): void
    {
        $model = new SurfaceModel();
        $model->updateTimestamps();

        $this->assertNotNull($model->created_at);
        $this->assertNotNull($model->updated_at);
    }

    public function test_without_timestamps_suppresses_them(): void
    {
        $seen = SurfaceModel::withoutTimestamps(
            fn (): bool => (new SurfaceModel())->isIgnoringTimestamps()
        );

        $this->assertTrue($seen);
        $this->assertFalse((new SurfaceModel())->isIgnoringTimestamps());
    }

    // ─── Replicate ────────────────────────────────────────

    public function test_replicate_drops_key_and_timestamps(): void
    {
        $model = $this->model([
            'id' => 5,
            'name' => 'Ada',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        $copy = $model->replicate();

        $this->assertSame('Ada', $copy->name);
        $this->assertFalse($copy->hasAttribute('id'));
        $this->assertFalse($copy->hasAttribute('created_at'));
        $this->assertFalse($this->existsOn($copy));
    }

    public function test_replicate_can_skip_more_attributes(): void
    {
        $model = $this->model(['id' => 5, 'name' => 'Ada', 'role' => 'admin']);

        $copy = $model->replicate(['role']);

        $this->assertFalse($copy->hasAttribute('role'));
        $this->assertTrue($copy->hasAttribute('name'));
    }

    // ─── Relations ────────────────────────────────────────

    public function test_relation_accessors(): void
    {
        $model = new SurfaceModel();

        $this->assertSame([], $model->getRelations());

        $model->setRelation('thing', 'value');

        $this->assertSame(['thing' => 'value'], $model->getRelations());
        $this->assertTrue($model->hasRelation('thing'));

        $model->unsetRelation('thing');

        $this->assertFalse($model->hasRelation('thing'));
    }

    public function test_without_relations_leaves_the_original_alone(): void
    {
        $model = new SurfaceModel();
        $model->setRelation('thing', 'value');

        $bare = $model->withoutRelations();

        $this->assertSame([], $bare->getRelations());
        $this->assertSame(['thing' => 'value'], $model->getRelations());
    }

    // ─── ArrayAccess ──────────────────────────────────────

    public function test_array_access(): void
    {
        $model = $this->model(['name' => 'Ada']);

        $this->assertTrue(isset($model['name']));
        $this->assertSame('Ada', $model['name']);

        $model['role'] = 'admin';
        $this->assertSame('admin', $model->role);

        unset($model['role']);
        $this->assertFalse(isset($model['role']));
    }

    // ─── Events ───────────────────────────────────────────

    public function test_without_events_restores_after_an_exception(): void
    {
        try {
            SurfaceModel::withoutEvents(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(SurfaceModel::eventsAreMuted());
    }
}

class SurfaceModel extends Model
{
    protected $table = 'surfaces';

    protected $guarded = [];

    public function getFullNameAttribute(): string
    {
        return trim(($this->first ?? '') . ' ' . ($this->last ?? ''));
    }
}
