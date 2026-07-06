<?php

namespace Nitro\Htmx\Concerns;

use Nitro\Htmx\Support\HxValidationErrors;
use Nitro\Htmx\Support\HxValidator;

trait HasValidation
{
    /**
     * Validate request data against rules.
     *
     * Uses $this->get() to pull values, so works with both GET/POST and
     * encrypted hx-vals data.
     *
     *   $errors = $this->validate([
     *       'name'  => 'required|min:2|max:100',
     *       'email' => 'required|email',
     *   ]);
     *
     *   if ($errors->any()) {
     *       return $this->view('form', ['errors' => $errors]);
     *   }
     */
    protected function validate(array $rules, ?array $data = null): HxValidationErrors
    {
        if ($data === null) {
            $data = [];
            foreach (array_keys($rules) as $field) {
                $data[$field] = $this->get($field);
            }
        }

        $validator = new HxValidator();
        return $validator->validate($data, $rules);
    }
}