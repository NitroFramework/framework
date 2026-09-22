<?php

namespace Nitro\Http\Controller\Concerns;

use Nitro\Validation\Validator;

/**
 * Validating input from inside an action.
 *
 * Standalone: it reads the request through the `request()` helper rather than
 * a sibling trait, so opting into it brings nothing else with it. For the
 * envelope a failure is reported in, see
 * {@see RespondsWithJson::validateRequest()}.
 */
trait PerformsValidation
{
    /**
     * Validate data against rules, returning one message per failing field.
     *
     * @param  array<string, string|array<int, string>> $rules    Field to rule string, rules separated by `|`.
     * @param  array<string, mixed>|null                $data     Defaults to the request input.
     * @param  array<string, string>                    $messages Replacements for the default wording.
     * @return array<string, string> Empty when the input is valid.
     */
    protected function validate(array $rules, ?array $data = null, array $messages = []): array
    {
        /*
         * request()->all(), not input(): the latter takes a key and returns
         * one value, so calling it bare to mean "everything" is an argument
         * error rather than the whole input — which made the documented
         * default here throw on every use.
         */
        $validator = $this->makeValidator($data ?? request()->all(), $rules, $messages);
        $validator->validate();

        $errors = [];

        foreach ($validator->errors()->all() as $field => $messagesForField) {
            $errors[$field] = $messagesForField[0] ?? '';
        }

        return $errors;
    }

    /**
     * A validator to drive directly, when the rules are not the whole story.
     *
     * @param array<string, mixed>                     $data
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string>                    $messages
     */
    protected function makeValidator(array $data, array $rules, array $messages = []): Validator
    {
        return new Validator($data, $rules, $messages);
    }
}
