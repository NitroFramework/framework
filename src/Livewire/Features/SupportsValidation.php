<?php

namespace Nitro\Livewire\Features;

use Nitro\Livewire\Hooks\ComponentHook;
use Nitro\Livewire\Hooks\UserHooks;
use Nitro\Livewire\Support\ErrorBag;
use Nitro\Validation\ValidationException;
use Nitro\Validation\Validator;

/**
 * Validation for a component: the rules lookup, the validate()/validateOnly()
 * entry points, and the error bag itself.
 *
 * The bag lives HERE rather than on Component — it is per-component feature
 * state, and the hook instance is per-component, so it round-trips through the
 * snapshot memo via this hook's hydrate()/dehydrate().
 */
class SupportsValidation extends ComponentHook
{
    /** @var array<string, string[]> field => messages */
    protected array $errors = [];

    /** Restore the error bag a previous request left in the snapshot memo. */
    public function hydrate(array $memo): void
    {
        $this->errors = (array) ($memo['errors'] ?? []);
    }

    /** Carry the error bag across to the next request. */
    public function dehydrate(array &$memo): void
    {
        $memo['errors'] = $this->errors;
    }

    /**
     * Validate the component's public state against its rules (argument, then a
     * rules() method, then a $rules property). On failure the errors are recorded
     * and a ValidationException is thrown; on success the validated subset is
     * returned and the error bag cleared.
     */
    public function validate(?array $rules = null): array
    {
        $validator = validator($this->component->all(), $rules ?? $this->rules());

        if ($validator->fails()) {
            $this->errors = $validator->errors()->all();
            throw new ValidationException($validator->errors());
        }

        $this->errors = [];

        return $validator->validated();
    }

    /** Validate a single field (used for real-time wire:model.live validation). */
    public function validateOnly(string $field, ?array $rules = null): void
    {
        $rules = $rules ?? $this->rules();
        $subset = isset($rules[$field]) ? [$field => $rules[$field]] : [];

        $validator = validator($this->component->all(), $subset);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $field => $messages) {
                $this->errors[$field] = $messages;
            }
            throw new ValidationException($validator->errors());
        }

        unset($this->errors[$field]);
    }

    /**
     * Rules from a rules() method or a $rules property. Read reflectively so a
     * component may declare either as protected — the conventional shape.
     */
    public function rules(): array
    {
        if (UserHooks::has($this->component, 'rules')) {
            return (array) UserHooks::invoke($this->component, 'rules');
        }

        if (property_exists($this->component, 'rules')) {
            return (array) UserHooks::read($this->component, 'rules');
        }

        return [];
    }

    /** The current validation errors, exposed to the view as $errors. */
    public function errors(): ErrorBag
    {
        return new ErrorBag($this->errors);
    }

    /** Raw error array for snapshot persistence. */
    public function toArray(): array
    {
        return $this->errors;
    }

    /** Replace the error bag (snapshot restore, or an app clearing it). */
    public function setErrors(array $errors): void
    {
        $this->errors = $errors;
    }

    /**
     * Put a message against a field without running the rules.
     *
     * Plenty of failures are not validation failures: a payment the gateway
     * declined, an email somebody else already holds, a seat that ran out
     * between the page loading and the button being pressed. They still belong
     * beside the field they are about, and the alternative is every component
     * inventing its own error channel for the view to render separately.
     */
    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /** Drop every error, or just one field's. */
    public function resetValidation(?string $field = null): void
    {
        if ($field === null) {
            $this->errors = [];

            return;
        }

        unset($this->errors[$field]);
    }
}
