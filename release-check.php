<?php

/**
 * Release preflight for the nitro/framework + nitro/nitro pair.
 *
 *   php release-check.php 0.22.0 [--skeleton=../nitro-nitro]
 *
 * The two packages ship under the same tag, and `create-project` serves the
 * skeleton's latest stable tag — so if the skeleton's constraint does not point
 * at the framework version being cut, new users get a stale framework. That is
 * not visible in either repo on its own, which is why it needs a check that
 * reads both.
 *
 * Exits non-zero if anything would produce a broken release. Prints the exact
 * command sequence when it does not.
 */

$argv = $_SERVER['argv'];
array_shift($argv);

$version = null;
$skeletonPath = '../nitro-nitro';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--skeleton=')) {
        $skeletonPath = substr($argument, 11);
        continue;
    }
    $version ??= ltrim($argument, 'v');
}

if ($version === null) {
    fwrite(STDERR, "usage: php release-check.php <version> [--skeleton=PATH]\n");
    exit(2);
}

$frameworkPath = __DIR__;
$failures = [];
$warnings = [];

function git(string $repo, string $command): string
{
    $escaped = escapeshellarg($repo);
    return trim((string) shell_exec("git -C {$escaped} {$command} 2>&1"));
}

/**
 * $ok === null marks something that is wrong but cannot be repaired, so it is
 * reported without counting as a blocker.
 */
function check(string $label, ?bool $ok, string $detail = ''): ?bool
{
    $marker = $ok === null ? 'note' : ($ok ? ' ok ' : 'FAIL');
    printf("  [%s] %s%s\n", $marker, $label, $detail === '' ? '' : "\n         {$detail}");
    return $ok;
}

function readConstraint(string $composerJson): ?string
{
    $data = json_decode($composerJson, true);
    return $data['require']['nitro/framework'] ?? null;
}

echo "\nRelease preflight for v{$version}\n";
echo str_repeat('-', 64) . "\n";

// ---------------------------------------------------------------- version ---
echo "\nVersion\n";

if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    $failures[] = 'version';
    check('X.Y.Z format', false, "got '{$version}'");
} else {
    check('X.Y.Z format', true);

    if (str_starts_with($version, '0.')) {
        check('still pre-1.0 (0.x)', true);
    } else {
        $warnings[] = 'Leaving 0.x is a deliberate decision — ReleaseWorkflow.md says stay on 0.x.';
        check('still pre-1.0 (0.x)', true, 'NOTE: this is a 1.x tag');
    }
}

// -------------------------------------------------------------- skeleton ----
$skeletonReal = realpath($skeletonPath);

echo "\nSkeleton (nitro/nitro)\n";

if ($skeletonReal === false || ! is_dir($skeletonReal . '/.git')) {
    $failures[] = 'skeleton';
    check('repository found', false, "no git repo at '{$skeletonPath}'");
    $skeletonReal = null;
} else {
    check('repository found', true, $skeletonReal);

    $skeletonComposer = $skeletonReal . '/composer.json';
    $constraint = readConstraint((string) @file_get_contents($skeletonComposer));
    $wanted = '^' . $version;

    if ($constraint === $wanted) {
        check("composer.json requires {$wanted}", true);
    } else {
        $failures[] = 'constraint';
        check("composer.json requires {$wanted}", false, "currently: " . var_export($constraint, true));
    }

    // A path repository left in place would publish a skeleton nobody can install.
    $skeletonData = json_decode((string) @file_get_contents($skeletonComposer), true) ?: [];
    $hasPathRepo = false;
    foreach ($skeletonData['repositories'] ?? [] as $repository) {
        if (($repository['type'] ?? '') === 'path') {
            $hasPathRepo = true;
        }
    }
    if ($hasPathRepo) {
        $failures[] = 'pathrepo';
    }
    check('no local path repository left in composer.json', ! $hasPathRepo);

    $dirty = git($skeletonReal, 'status --porcelain');
    // The constraint bump itself is expected to be uncommitted at this point.
    $dirtyLines = array_values(array_filter(explode("\n", $dirty)));
    $onlyComposer = true;
    foreach ($dirtyLines as $line) {
        $file = trim(substr($line, 2));
        if (! in_array($file, ['composer.json', 'composer.lock'], true)) {
            $onlyComposer = false;
        }
    }
    if ($dirtyLines === []) {
        check('working tree clean', true);
    } elseif ($onlyComposer) {
        check('working tree clean', true, 'only composer.json/lock pending — that is the bump, commit it in step 5');
    } else {
        $failures[] = 'skeleton-dirty';
        check('working tree clean', false, count($dirtyLines) . ' uncommitted path(s) beyond composer.json');
    }

    $existing = git($skeletonReal, "tag -l v{$version}");
    if ($existing !== '') {
        $failures[] = 'skeleton-tag';
    }
    check("tag v{$version} not already used", $existing === '');
}

// ------------------------------------------------------------- framework ----
echo "\nFramework (nitro/framework)\n";

$frameworkData = json_decode((string) @file_get_contents($frameworkPath . '/composer.json'), true) ?: [];
$hasVersionField = array_key_exists('version', $frameworkData);
if ($hasVersionField) {
    $failures[] = 'version-field';
}
check('composer.json has no hardcoded "version"', ! $hasVersionField, $hasVersionField ? 'Packagist derives the version from the tag; remove it' : '');

$dirty = array_values(array_filter(explode("\n", git($frameworkPath, 'status --porcelain'))));
if ($dirty === []) {
    check('working tree clean', true);
} else {
    $failures[] = 'framework-dirty';
    check('working tree clean', false, count($dirty) . ' uncommitted path(s) — commit before tagging');
}

$existing = git($frameworkPath, "tag -l v{$version}");
if ($existing !== '') {
    $failures[] = 'framework-tag';
}
check("tag v{$version} not already used", $existing === '');

$ahead = git($frameworkPath, 'rev-list --count @{u}..HEAD');
if (is_numeric($ahead) && (int) $ahead > 0) {
    $warnings[] = "Framework is {$ahead} commit(s) ahead of origin/main — step 3 pushes main before tagging.";
}

// -------------------------------------- regression: what is published now ---
if ($skeletonReal !== null) {
    echo "\nAlready published\n";

    $tags = array_values(array_filter(explode("\n", git($skeletonReal, 'tag --sort=-v:refname'))));
    $broken = [];

    foreach (array_slice($tags, 0, 5) as $tag) {
        $composer = git($skeletonReal, "show {$tag}:composer.json");
        $constraint = readConstraint($composer);
        $expected = '^' . ltrim($tag, 'v');

        if ($constraint !== null && $constraint !== $expected) {
            $broken[] = "{$tag} requires {$constraint}, should be {$expected}";
        }
    }

    if ($broken === []) {
        check('recent skeleton tags point at their paired framework', true);
    } else {
        // Reported, never blocking: Packagist refs are immutable, so a published
        // mismatch can only be superseded by the next pair, never repaired.
        check('recent skeleton tags point at their paired framework', null, implode("\n         ", $broken));
        $warnings[] = 'Those tags cannot be repaired (Packagist refs are immutable) — cutting this release supersedes them.';
    }
}

// ------------------------------------------------------------------ done ----
echo "\n" . str_repeat('-', 64) . "\n";

foreach ($warnings as $warning) {
    echo "\n  ! {$warning}\n";
}

if ($failures !== []) {
    echo "\nNOT READY — " . count($failures) . " blocker(s) above.\n\n";
    exit(1);
}

echo <<<NEXT

READY. Run, in order:

  # framework
  composer test
  git push origin main
  git tag -a v{$version} -m "…"
  git push origin v{$version}

  # wait for Packagist to index the tag, then in {$skeletonPath}
  composer update nitro/framework
  composer validate
  git commit -am "chore: require framework ^{$version}"
  git push origin main
  git tag -a v{$version} -m "…"
  git push origin v{$version}

Re-run this script after the skeleton commit to confirm the pair matches.

NEXT;

echo "\n";
