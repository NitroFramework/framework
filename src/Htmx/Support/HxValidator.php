<?php

namespace Nitro\Htmx\Support;

class HxValidator
{
    private array $errors = [];

    public function validate(array $data, array $rules): HxValidationErrors
    {
        $this->errors = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $data[$field] ?? null;
            $label = $this->humanize($field);

            foreach ($fieldRules as $rule) {
                $params = [];

                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $error = $this->check($rule, $field, $value, $params, $label, $data);

                if ($error) {
                    $this->errors[$field][] = $error;
                    break;
                }
            }
        }

        return new HxValidationErrors($this->errors);
    }

    private function check(string $rule, string $field, mixed $value, array $params, string $label, array $data): ?string
    {
        return match ($rule) {
            'required' => $this->isEmpty($value) && !$this->hasUploadedFile($field)
                ? "{$label} is required."
                : null,

            'file' => $this->checkFile($field, $label),

            'image' => $this->checkImage($field, $label),

            'mimes' => $this->checkMimes($field, $params, $label),

            'max_size' => $this->checkMaxSize($field, $params[0] ?? '0', $label),

            'email' => (!$this->isEmpty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL))
                ? "{$label} must be a valid email address."
                : null,

            'numeric' => (!$this->isEmpty($value) && !is_numeric($value))
                ? "{$label} must be a number."
                : null,

            'integer' => (!$this->isEmpty($value) && !ctype_digit((string) $value))
                ? "{$label} must be a whole number."
                : null,

            'min' => $this->checkMin($value, $params[0] ?? 0, $label),

            'max' => $this->checkMax($value, $params[0] ?? 0, $label),

            'in' => (!$this->isEmpty($value) && !in_array($value, $params, true))
                ? "{$label} must be one of: " . implode(', ', $params) . "."
                : null,

            'not_in' => (!$this->isEmpty($value) && in_array($value, $params, true))
                ? "{$label} must not be: " . implode(', ', $params) . "."
                : null,

            'url' => (!$this->isEmpty($value) && !filter_var($value, FILTER_VALIDATE_URL))
                ? "{$label} must be a valid URL."
                : null,

            'alpha' => (!$this->isEmpty($value) && !ctype_alpha((string) $value))
                ? "{$label} must contain only letters."
                : null,

            'alpha_numeric' => (!$this->isEmpty($value) && !ctype_alnum((string) $value))
                ? "{$label} must contain only letters and numbers."
                : null,

            'regex' => (!$this->isEmpty($value) && !preg_match($params[0] ?? '//', (string) $value))
                ? "{$label} format is invalid."
                : null,

            'confirmed' => ($value !== ($data[$field . '_confirmation'] ?? null))
                ? "{$label} confirmation does not match."
                : null,

            'same' => ($value !== ($data[$params[0] ?? ''] ?? null))
                ? "{$label} must match {$params[0]}."
                : null,

            default => null,
        };
    }

    private function checkMin(mixed $value, int|string $min, string $label): ?string
    {
        if ($this->isEmpty($value)) return null;

        $min = (int) $min;

        if (is_numeric($value)) {
            return (float) $value < $min
                ? "{$label} must be at least {$min}."
                : null;
        }

        return mb_strlen((string) $value) < $min
            ? "{$label} must be at least {$min} characters."
            : null;
    }

    private function checkMax(mixed $value, int|string $max, string $label): ?string
    {
        if ($this->isEmpty($value)) return null;

        $max = (int) $max;

        if (is_numeric($value)) {
            return (float) $value > $max
                ? "{$label} must be no more than {$max}."
                : null;
        }

        return mb_strlen((string) $value) > $max
            ? "{$label} must be no more than {$max} characters."
            : null;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function humanize(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }

    // ── File-upload rules ──────────────────────────────────────────────

    private function hasUploadedFile(string $field): bool
    {
        return isset($_FILES[$field]['error']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK;
    }

    private function checkFile(string $field, string $label): ?string
    {
        if (!isset($_FILES[$field])) return null;
        $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
        return match ($err) {
            UPLOAD_ERR_OK         => null,
            UPLOAD_ERR_NO_FILE    => null, // empty upload — covered by required rule
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => "{$label} exceeds the upload size limit.",
            UPLOAD_ERR_PARTIAL    => "{$label} was only partially uploaded.",
            default               => "{$label} failed to upload.",
        };
    }

    private function checkImage(string $field, string $label): ?string
    {
        if (!$this->hasUploadedFile($field)) return null;
        $mime = $this->detectMime($_FILES[$field]['tmp_name'] ?? '');
        return ($mime && str_starts_with($mime, 'image/'))
            ? null
            : "{$label} must be an image.";
    }

    private function checkMimes(string $field, array $allowed, string $label): ?string
    {
        if (!$this->hasUploadedFile($field) || empty($allowed)) return null;
        $name = $_FILES[$field]['name'] ?? '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, array_map('strtolower', $allowed), true)
            ? null
            : "{$label} must be a file of type: " . implode(', ', $allowed) . '.';
    }

    /**
     * max_size:500kb  /  max_size:2mb  /  max_size:1024 (bytes)
     */
    private function checkMaxSize(string $field, string $spec, string $label): ?string
    {
        if (!$this->hasUploadedFile($field)) return null;
        $size  = (int) ($_FILES[$field]['size'] ?? 0);
        $limit = $this->parseSize($spec);
        return $size > $limit
            ? "{$label} must be no larger than {$spec}."
            : null;
    }

    private function parseSize(string $spec): int
    {
        $spec = strtolower(trim($spec));
        if (preg_match('/^(\d+)\s*(kb|mb|gb|b)?$/', $spec, $m)) {
            $n = (int) $m[1];
            return match ($m[2] ?? 'b') {
                'kb'    => $n * 1024,
                'mb'    => $n * 1024 * 1024,
                'gb'    => $n * 1024 * 1024 * 1024,
                default => $n,
            };
        }
        return 0;
    }

    private function detectMime(string $path): ?string
    {
        if ($path === '' || !is_file($path)) return null;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) return null;
        $mime = finfo_file($finfo, $path) ?: null;
        finfo_close($finfo);
        return $mime;
    }
}