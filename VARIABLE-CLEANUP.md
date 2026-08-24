# Variable naming in `src/`

Variables in `src/` use descriptive names. The short names listed below are the
only exceptions, and each is deliberate.

Check the current state at any time:

```
nitro audit:variables src              # writes storage/variable-audit.txt
nitro audit:variables src --max=3      # widen what counts as short
nitro audit:variables src --all        # include $id/$ip/$to/... too
```

The command is `src/Console/Commands/VariableAuditCommand.php`. It scans PHP
tokens rather than text, so a `$c` that is only string data is not counted and a
variable interpolated into `"a $string"` is. Tests:
`tests/Unit/Console/VariableAuditCommandTest.php`.

## Names that stay short

| Name | Count | Why |
|---|---|---|
| `$i` | 145 | Loop counter |
| `$a` / `$b` | 8 | `uasort(fn($a, $b) => …)` — the sort idiom |
| `$_` | 3 | Deliberately unused binding, e.g. `foreach ($slots as $name => $_)` |
| `$cc` | 1 | `Mail\Message::$cc` — public property, carbon copy |
| `$as` | 2 | `Query\Queries::$as` and `Livewire\Attributes\Url::$as` |

`$cc` and `$as` are public API, not locals. `Url::$as` is used as a named
argument, so `#[Url(as: 'page')]` in an application depends on the name.

Short names that are short because the word is short are not in scope at all:
`$id $ip $to $ok $iv $ns $db $os $up`.

## Open: the compiled container

`ContainerCompiler` emits `static fn($c) => …` and `$c->createOrResolve(…)` as
generated source (lines 54, 107, 112, and the docblock examples at 15 and 21),
so a compiled container reads `$c` where the rest of the codebase reads
`$container`.

It is generated output that nobody edits, and the file can run to thousands of
lines. Against that, it is read when debugging a compiled container, and the
docblocks in that file are documentation. Changing it also updates two
assertions in `tests/Unit/Container/ContainerCompilerTest.php`.

## If you rename variables again

Rename through `token_get_all()`, not a regex. A whole-file rename is safe
because PHP variables are function-scoped — provided the target name is not
already used anywhere in the file. Refuse the file otherwise and scope the
rename to one function by line range.

| Hazard | Handling |
|---|---|
| Target name already in the file | Refuse; scope to one function instead |
| `compact()` / `extract()` / `get_defined_vars()` | Refuse — a rename silently changes what these see |
| `$$variable` | Refuse |
| `use ($x)`, `"text $x"`, heredocs | Token rename handles all three |
| `'$x'` as string data | Token rename correctly skips it |

`View/Engine/ViewRenderer.php` shows why `extract()` matters: its catch blocks
sit in functions calling `extract($data, EXTR_SKIP)`, so view data can supply
any name. Those use `$templateError`, which no view will collide with.

## Enforcing it

The count is at a floor of 20, all of them listed above. A CI ceiling therefore
fails on any newly introduced short name, with nothing to explain away:

```
nitro audit:variables src --max=2
```

Not wired up.
