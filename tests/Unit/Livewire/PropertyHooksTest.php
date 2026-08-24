<?php

namespace Tests\Unit\Livewire;

use Nitro\Livewire\Component;
use Nitro\Livewire\Exceptions\CannotUpdateLockedProperty;
use Nitro\Livewire\Attributes\Locked;
use Nitro\Livewire\Hooks\PropertyHooks;
use PHPUnit\Framework\TestCase;

/**
 * The property-hook family: the name a wire:model update derives, the
 * updating/updated fan-out around the write, and the #[Locked] guard in front
 * of it.
 *
 * The nested case is the one that used to be broken: 'form.email' was studlied
 * whole into 'Form.email', so `updatingForm.email` — never a legal method name —
 * was looked up and no hook on a nested binding ever ran.
 */
class PropertyHooksTest extends TestCase
{
    private function hooks(): PropertyHooks
    {
        return new PropertyHooks();
    }

    public function test_flat_key_derives_a_studly_hook_name(): void
    {
        $this->assertSame(['Title', null], $this->hooks()->hookName('title'));
        $this->assertSame(['FirstName', null], $this->hooks()->hookName('first_name'));
        $this->assertSame(['FirstName', null], $this->hooks()->hookName('first-name'));
    }

    public function test_nested_key_derives_the_root_plus_the_remaining_path(): void
    {
        $this->assertSame(['Form', 'email'], $this->hooks()->hookName('form.email'));
        $this->assertSame(['Form', 'address.city'], $this->hooks()->hookName('form.address.city'));
    }

    public function test_flat_hooks_fire_around_the_write(): void
    {
        $c = new HookProbe();
        $this->hooks()->update($c, 'title', 'Hello');

        $this->assertSame('Hello', $c->title);
        $this->assertSame(
            ['updating:title=Hello', 'updatingTitle:Hello', 'updated:title=Hello', 'updatedTitle:Hello'],
            $c->log
        );
    }

    public function test_nested_hooks_fire_with_the_sub_path_as_the_second_argument(): void
    {
        $c = new HookProbe();
        $this->hooks()->update($c, 'form.email', 'a@b.c');

        $this->assertSame('a@b.c', $c->form['email']);
        $this->assertSame(
            ['updating:form.email=a@b.c', 'updatingForm:a@b.c/email', 'updated:form.email=a@b.c', 'updatedForm:a@b.c/email'],
            $c->log
        );
    }

    public function test_an_updating_hook_may_transform_the_value_it_is_handed(): void
    {
        $c = new HookProbe();
        $this->hooks()->update($c, 'email', '  ALICE@EXAMPLE.COM  ');

        $this->assertSame('alice@example.com', $c->email);
    }

    public function test_a_locked_property_is_rejected_before_any_hook_runs(): void
    {
        $c = new HookProbe();

        try {
            $this->hooks()->update($c, 'id', 99);
            $this->fail('A #[Locked] property must not be writable from a commit.');
        } catch (CannotUpdateLockedProperty) {
            $this->assertSame(1, $c->id);
            $this->assertSame([], $c->log);
        }
    }
}

class HookProbe extends Component
{
    #[Locked]
    public int $id = 1;

    public string $title = '';
    public string $email = '';
    public array $form = [];

    /** @var string[] */
    public array $log = [];

    public function updating(string $key, mixed $value): void
    {
        $this->log[] = "updating:{$key}=" . (is_scalar($value) ? $value : gettype($value));
    }

    public function updated(string $key, mixed $value): void
    {
        $this->log[] = "updated:{$key}=" . (is_scalar($value) ? $value : gettype($value));
    }

    public function updatingTitle(mixed $value): void
    {
        $this->log[] = "updatingTitle:{$value}";
    }

    public function updatedTitle(mixed $value): void
    {
        $this->log[] = "updatedTitle:{$value}";
    }

    public function updatingForm(mixed $value, string $key): void
    {
        $this->log[] = "updatingForm:{$value}/{$key}";
    }

    public function updatedForm(mixed $value, string $key): void
    {
        $this->log[] = "updatedForm:{$value}/{$key}";
    }

    /** A non-null return replaces the value that gets written. */
    public function updatingEmail(string $value): string
    {
        return strtolower(trim($value));
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
