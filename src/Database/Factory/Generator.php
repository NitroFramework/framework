<?php

namespace Nitro\Database\Factory;

/**
 * Tiny built-in fake-data generator. Covers the ~15 methods that account
 * for ~95% of factory usage. Drop-in compatible with the most common
 * fakerphp/faker calls so swapping later is a one-line change.
 *
 *   $faker->name();
 *   $faker->email();
 *   $faker->unique()->email();
 *   $faker->numberBetween(1, 100);
 *   $faker->randomElement(['draft', 'published']);
 *
 * If you outgrow this (need localization, schemes for credit cards,
 * specific number formats, etc.) `composer require fakerphp/faker` and
 * replace the Generator binding in your Factory subclasses.
 */
class Generator
{
    private const FIRST_NAMES = [
        'Aiden', 'Alex', 'Amelia', 'Aria', 'Asher', 'Aurora', 'Avery', 'Ben',
        'Bella', 'Caleb', 'Camila', 'Charlotte', 'Chloe', 'Daniel', 'David',
        'Elena', 'Eli', 'Elijah', 'Emma', 'Ethan', 'Evelyn', 'Felix', 'Grace',
        'Hannah', 'Henry', 'Ibrahim', 'Isabella', 'Jack', 'Jacob', 'James',
        'Jasmine', 'John', 'Layla', 'Leo', 'Liam', 'Lily', 'Lucas', 'Maya',
        'Mia', 'Mohammed', 'Nathan', 'Noah', 'Nora', 'Oliver', 'Olivia',
        'Owen', 'Priya', 'Ravi', 'Riley', 'Sara', 'Sofia', 'Theo', 'Wren',
        'Xavier', 'Yuki', 'Zara', 'Zeeshan',
    ];

    private const LAST_NAMES = [
        'Ahmed', 'Anderson', 'Brown', 'Chen', 'Davis', 'Garcia', 'Gonzalez',
        'Hernandez', 'Ito', 'Jackson', 'Johnson', 'Jones', 'Khan', 'Kim',
        'Kumar', 'Lee', 'Lopez', 'Martin', 'Martinez', 'Miller', 'Mohammed',
        'Moore', 'Nakamura', 'Nguyen', 'Oliveira', 'Patel', 'Perez', 'Petrov',
        'Reyes', 'Robinson', 'Rodriguez', 'Sato', 'Schmidt', 'Sharma', 'Silva',
        'Smith', 'Taylor', 'Thomas', 'Wang', 'Williams', 'Wilson', 'Wu',
        'Yamamoto', 'Yang', 'Yilmaz', 'Zhang',
    ];

    private const WORDS = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing',
        'elit', 'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore',
        'et', 'dolore', 'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam',
        'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi',
        'aliquip', 'ex', 'ea', 'commodo', 'consequat', 'duis', 'aute',
    ];

    private const DOMAINS = [
        'example.com', 'example.org', 'example.net', 'demo.test', 'sample.dev',
    ];

    public function name(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
    }

    public function firstName(): string
    {
        return self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
    }

    public function lastName(): string
    {
        return self::LAST_NAMES[array_rand(self::LAST_NAMES)];
    }

    public function email(): string
    {
        return strtolower($this->firstName() . '.' . $this->lastName()) . random_int(1, 9999) . '@' . $this->domainName();
    }

    public function domainName(): string
    {
        return self::DOMAINS[array_rand(self::DOMAINS)];
    }

    public function url(): string
    {
        return 'https://' . $this->domainName() . '/' . $this->slug();
    }

    public function slug(int $words = 3): string
    {
        return implode('-', $this->wordList($words));
    }

    public function word(): string
    {
        return self::WORDS[array_rand(self::WORDS)];
    }

    /**
     * @return string|array<int,string>
     */
    public function words(int $count = 3, bool $asText = false): string|array
    {
        $list = $this->wordList($count);
        return $asText ? implode(' ', $list) : $list;
    }

    public function sentence(int $words = 6): string
    {
        $list = $this->wordList(max(1, $words));
        $list[0] = ucfirst($list[0]);
        return implode(' ', $list) . '.';
    }

    public function paragraph(int $sentences = 3): string
    {
        $out = [];
        for ($i = 0; $i < $sentences; $i++) {
            $out[] = $this->sentence(random_int(5, 12));
        }
        return implode(' ', $out);
    }

    public function text(int $maxChars = 200): string
    {
        $out = '';
        while (strlen($out) < $maxChars) {
            $out .= $this->sentence(random_int(4, 10)) . ' ';
        }
        return rtrim(substr($out, 0, $maxChars));
    }

    public function numberBetween(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return random_int($min, $max);
    }

    public function boolean(int $percentChanceOfTrue = 50): bool
    {
        return random_int(1, 100) <= $percentChanceOfTrue;
    }

    /**
     * @template T
     * @param  array<int, T> $values
     * @return T
     */
    public function randomElement(array $values): mixed
    {
        return $values[array_rand($values)];
    }

    public function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12),
        );
    }

    public function date(string $format = 'Y-m-d', int $maxDaysAgo = 365): string
    {
        return date($format, time() - random_int(0, $maxDaysAgo * 86400));
    }

    public function dateTime(string $format = 'Y-m-d H:i:s', int $maxDaysAgo = 365): string
    {
        return date($format, time() - random_int(0, $maxDaysAgo * 86400));
    }

    public function phoneNumber(): string
    {
        return sprintf(
            '+1-%03d-%03d-%04d',
            random_int(200, 999),
            random_int(100, 999),
            random_int(0, 9999),
        );
    }

    /**
     * Get a UniqueGenerator that retries calls until each value is novel
     * for the lifetime of THIS unique-generator instance. Matches Faker:
     *
     *   $faker->unique()->email();   // never returns the same email twice
     */
    public function unique(): UniqueGenerator
    {
        return new UniqueGenerator($this);
    }

    private function wordList(int $count): array
    {
        $count = max(1, $count);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->word();
        }
        return $out;
    }
}
