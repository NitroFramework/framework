# Low coupling: four tasks

Changing one layer should not cascade into the others. These four came out of
moving the container onto `illuminate/container` on 2026-09-22 and measuring
what the rest of the framework actually depends on.

Start state: **3054 tests, 15 red** (7 errors, 8 failures) · **phpstan level 0 clean**

| | Task | Status |
|---|---|---|
| 1 | Finish the container swap — get the suite green | ☑ 3049 green · phpstan clean |
| 2 | Split `Support/Helpers/` out — remove the only dependency cycle | ✗ withdrawn — the cycle was a measurement error |
| 3 | Make `PathRegistry` a contract | ☑ 43 files repointed · found a live bug |
| 4 | `illuminate/encryption` + `illuminate/hashing` | ✗ withdrawn — hardened in place instead |

## Console: closing the gaps against Laravel

Measured first. Nitro's console is 6,964 lines, but **5,200 of those are
commands and only 1,764 is infrastructure** — `illuminate/console` is 78 files
that include Scheduling and the termwind view layer, neither of which lives in
Nitro's console. The one unambiguous win: **Nitro's console imports nothing
outside the framework**, where Laravel's pulls symfony/console,
laravel/prompts, termwind, symfony/process and illuminate/view.

Fidelity is the best of any layer compared so far: all twelve Laravel
signature forms parse *and behave* — required, optional, variadic, defaults,
boolean flags, `--opt=value`, `--S|shortcut`, array options, inline
descriptions. No silent divergence.

| | Gap | Status |
|---|---|---|
| 1 | `$this->call()` / `callSilently()` | ☑ |
| 2 | Verbosity levels | ☑ |
| 3 | Console events | ☑ |
| 4 | Command isolation | ☑ |
| 5 | Prompts for missing input, progress bars | ☑ |
| 6 | `GeneratorCommand` base class | ☑ |

**1 and 2 share one seam.** `Verbosity` is an enum read from `-q`/`-v`/`-vv`/
`-vvv`, checked by `Command::writeln()` and `Components::write()` before
anything is emitted, and stripped from the arguments before a command parses
its own signature. `callSilently()` then falls out of it: the same call at
`Verbosity::Quiet`. A command reaches others through `CommandRunner`, a
one-method contract rather than the whole manager, and arguments may be
written either way — `['--queue' => 'high']` or `['--queue=high']` — because
`Support\Arguments::flatten()` accepts both.

**3** follows the routing layer's convention exactly: `ConsoleEvents` owns the
vocabulary, `CommandEvent` carries the payload, and the manager implements
`ReceivesDispatcher`. `command.finished` is raised in a `finally`, so a command
that throws is still reported — those are the runs worth hearing about. The
dispatcher is taken by the console `Kernel` rather than wired in a provider's
`boot()`, because resolving the `CommandManager` builds it and building it
scans the filesystem; only a console run should pay for that.

**4** locks in the cache rather than a file, so two servers running the same
scheduled command see one lock between them. The lock carries a token and only
its holder can release it — a run that overruns its expiry must not be able to
delete a lock a later run has since taken. `--isolated` with no cache
configured refuses to start rather than running unlocked, since a command that
asked not to overlap and silently overlapped is the worse outcome.

**5** turns prompting on per command through `PromptsForMissingInput`, and only
prompts when three things hold: the command opted in, the invocation did not
pass `--no-interaction`, and STDIN is a terminal. Without the last one it
raises the same "not enough arguments" error as before — a prompt in CI is a
job that hangs until something kills it, which is worse than one that fails in
the first second. The progress bar is gated the same way: with no terminal the
callback still runs and nothing is drawn, so a cron log does not fill with
redraw sequences.

**6** `GeneratorCommand` takes a namespace, a suffix and a stub, and handles
the rest: name to class to path, directory creation, refusing to overwrite
without `--force`. Names are studly-cased per segment — `monthly-report`
becomes `MonthlyReportService` — because a hyphen left in place produces a
class name PHP cannot parse, and the file is written before anyone finds out.
The result is checked against a valid-identifier pattern before anything is
written.

All six are covered by `tests/Unit/Console/ConsoleFeaturesTest.php`, including
the two cases that matter most and are easiest to get wrong: a command that
throws still raising `command.finished`, and a missing argument failing rather
than prompting when nothing can answer.

### Laravel's console cosmetics, adopted without the packages

`nunomaduro/termwind` is 63 files and `laravel/prompts` is 147, and **both
require symfony/console** — more than the whole of Nitro's console
infrastructure, for a layer that currently imports nothing outside the
framework.

Termwind is an authoring model, not a look: it renders HTML with Tailwind
class names to ANSI. Nitro's `Components` already draws the same badges,
dotted-leader detail lines, task lines, bullet lists and boxed alerts in 194
lines, so there was nothing to gain from it.

What `laravel/prompts` had and Nitro did not was interaction, and that is what
was taken:

- **`select()` and `multiselect()`** with arrow-key navigation, a highlighted
  row and space-to-toggle. `Support\Terminal` owns the raw-mode handling —
  `stty -icanon -echo`, escape-sequence decoding, and restoring the terminal
  in a `finally`, since a command interrupted mid-menu must not leave a shell
  without echo.
- **Validation on `ask()`** — a callback returning an error message keeps the
  question open, capped at three attempts so a validator that never passes
  cannot spin.
- Both fall back to the existing numbered prompt wherever keys cannot be read
  one at a time: Windows, a pipe, CI.

**And a password leak, fixed.** `secret()` called `stty -echo`, which does not
exist on Windows, then fell through to a plain read — so the password typed
itself across the screen. It now hides input through PowerShell's
`Read-Host -AsSecureString` on Windows, and says *"(input will be visible)"*
out loud when nothing can hide it, before the typing starts rather than after.

**One bug written and caught here.** The first version of `readHidden()` started
PowerShell unconditionally, and `Read-Host` waits for a keypress that a
redirected stdin never delivers — so `secret()` hung instead of finishing. It
was found by running the check with stdin closed, which is the CI condition.
Every prompt is now asserted to return rather than wait, and
`Terminal::restore()` is a no-op unless that instance actually changed the
terminal, since shelling out to a `stty` that does not exist prints the
shell's complaint past anything PHP can suppress.

### Terminal detection, fixed

Looking at `Style::terminalSupportsColor()` for the progress bar's sake turned
up two faults rather than one:

- **Windows never asked whether there was a terminal at all.** It checked only
  for ANSICON, Windows Terminal, VS Code or vt100 support, so a redirected
  file was coloured as readily as a console — `nitro route:list > routes.txt`
  wrote escape sequences into the file.
- **Unix fell back to `true`** when the posix extension was absent.

`stream_isatty` answers on every platform, where `posix_isatty` needs an
extension Windows does not have. It is now asked first, on all platforms, and
the Windows environment checks run only once there is a terminal to ask about.

`OutputFormatter` had the same hole and worse reach: `color()` emitted ANSI
unconditionally, and that is what every grouped command prints through. It now
honours the same detection, resolved lazily on first use — the class is built
without a constructor in places, and a typed property with no value is a fatal
the moment anything prints.

Verified against the real application: `nitro route:list > file` and
`nitro key:generate > file` both now produce readable text with no escape
sequences, and forced decoration still colours.

Each task ends with the full suite and `phpstan analyse` both clean before the
next one starts.

---

## 1. Finish the container swap

The swap left 15 red tests. None are behaviour regressions; they are tests
written against a container that no longer exists.

- **7 errors** — tests reaching for private members that were removed:
  `$reflectionCache` (×5), `$aliasTargets`, `Nitro\Container\ContextualBindingBuilder`.
- **4 failures** — exception hierarchy. Tests expect `RuntimeException`;
  Illuminate's `BindingResolutionException` and `EntryNotFoundException` extend
  `Exception`. This cannot be bridged — PHP has single inheritance, and
  re-parenting Nitro's onto `RuntimeException` breaks the parent's own
  `catch` in `resolveClass()`, which is what lets an optional class-typed
  dependency fall back to its default.
- **4 failures** — API differences where Illuminate and Nitro genuinely
  disagree: `tag()` argument order, `rebinding()` callback signature,
  `build()` taking constructor parameters, `forgetInstances()` leaving
  `resolved()` true.

### The naming decision, settled

The rule: **diverge where failure is loud, match where failure is silent.**

`resolve()` stays. Someone writing `make()` from Laravel habit gets an
immediate `Call to undefined method` — the divergence announces itself, costs
one correction, and `resolve()` is the more accurate word anyway: `make()`
promises construction, but a singleton's second resolution constructs nothing.
Illuminate agrees internally — its `resolve()` is the real method and `make()`
is a public wrapper over it.

`alias()` flipped to Illuminate's `alias($abstract, $alias)`. This was the one
divergence that failed *silently*: both frameworks write
`alias('name', Some::class)` and meant opposite things by it, so the same code
wired a service backwards with no error. An audit of the 14 methods Nitro
shares with Illuminate found `alias()` was the only `REVERSED` one — the other
13 are identical or widened. It is now 0 of 14.

`make()` and `makeWith()` need no shim: they are inherited, and they route
through Nitro's `resolve()` override, so the deferred-provider hook, the cycle
guard and the resolution observer all apply. Locked in by a test, because that
is now a guarantee rather than an accident.

39 call sites flipped. Verified beyond the suite: a booted application resolves
all ten short-name aliases (`db`, `router`, `view`, `log`, `cache`, `queue`,
`schedule`, `cookie`, `encrypter`, `redis`) to the right classes, and every
alias reaches the same instance as its target — except `pipeline`, which is
transient by design and correctly hands out a fresh one each time. No
application code outside the framework calls `alias()`.

### Done — 3049 tests green, phpstan level 0 clean

Two of the four exception failures turned out to be fixable rather than
cosmetic, and the container is better for it:

- `build()` and `notInstantiable()` now raise
  `Nitro\Container\Exceptions\BindingResolutionException`, so a missing or
  abstract class reports in the framework's own vocabulary. It still extends
  Illuminate's type, which is what keeps an optional class-typed dependency
  falling back to its default — the parent catches that type internally.
- `NotFoundException` is a `RuntimeException` again *and* implements PSR-11's
  `NotFoundExceptionInterface`, so both spellings of the catch work.

`build()` regained its second argument — constructor overrides for one build,
matched by name or position — since it is construction and belongs here.

Tests updated rather than bridged, where Nitro's shape had no callers left:
`tag(['one','two'], 'things')`, `tagged()` returning a generator, a
`rebinding()` callback taking the container first, `forgetInstances()` leaving
`resolved()` true, and `when()` returning Illuminate's builder contract.
`ContainerReflectionCacheTest` was removed: the container no longer caches
reflection per abstract, and in production the compiled factories skip
reflection entirely. The two exception cases it held moved to
`KnownResolutionRegressionsTest`.

## 2. Split `Support/Helpers/` out

`src/Support` is 4,815 lines of leaf utilities (`Arr`, `Str`, `Collection`,
`Carbon`, `Pipeline`, `Macroable`) plus 2,292 lines of global helpers across 23
files that import Http (×7), Auth, Session, View, Database, Cookie and
Translation.

So `Nitro\Support` sits at the bottom of the stack and reaches into the top:
12 layers depend on it, and it depends on 11 layers. Changing `Http\Request`
breaks `Support/Helpers/request.php`, which implicates everything that depends
on Support.

### Withdrawn — there was no cycle

The finding was an artefact of the measurement. `grep '^use Nitro' src/Support`
swept in `src/Support/Helpers/*.php`, and **those files have no namespace** —
they declare global functions. There is no `Nitro\Support\Helpers` namespace,
so `Nitro\Support\Collection` never depended on `Nitro\Http\Request`. The
coupling was between directories on disk, not between namespaces.

Laravel keeps `helpers.php` at the top level of `illuminate/support` for the
same reason. The helpers stay where they were.

### The bundle, measured

An older version concatenated the 23 helper files into one, and
`OptimizeCommand` still deletes the leftover so a stale copy cannot shadow the
real ones. Worth re-checking whether the concatenation was worth it:

| | opcache off | opcache warm |
|---|---|---|
| 23 separate files | +15.43 ms | +0.59 ms |
| one bundled file | +1.23 ms | −0.03 ms |
| bundling saves | 14.21 ms | **0.62 ms** |

The large number is parse-and-compile cost that no production server pays.
`optimize` already writes an opcache preload script covering 824 files, and
under worker mode the helpers load once per worker rather than once per
request — so the saving there is zero. Not revived: it buys ~0.6 ms under
php-fpm and costs back the shadowing bug it was removed for.

## 3. Make `PathRegistry` a contract

Foundation has 26 dependents, and nearly all of them take a narrow thing:
`Contracts\ConfigRepository`, `Providers\ServiceProvider`,
`Contracts\ResetsBetweenRequests`. Only six files outside Foundation import
`Application` itself.

`PathRegistry` is the exception — concrete, and taken by ~18 files. Giving it
a contract matches what `ConfigRepository` already does.

### Done

`Nitro\Foundation\Contracts\PathRegistry` declares the 20 path methods;
`Nitro\Foundation\PathRegistry` implements it. The container binds `'paths'`,
the class and the contract to one instance, and `Application::paths()` returns
the contract.

43 files were repointed at the interface. Nine kept the class on purpose:
`Application`, which constructs the registry and so is the one place that must
know which implementation runs, and eight tests that build a fake by
constructing or subclassing it.

**It uncovered a real bug.** `CommandExitCodeTest` walks every command class
and calls `handle()` with a signature it does not own, asserting a non-zero
code. `MigrationCommands` had been skipped by that walk because the test could
not build it; once the contract made it constructible, the assertion ran and
failed. Its `handle()` discarded the `match` result and returned
`ExitCode::SUCCESS` unconditionally — so an unknown migration signature *and a
failed migration* both reported success to the shell. That is the exact failure
`CommandManager`'s own docblock warns about. The result is now returned.

## 4. `illuminate/encryption` + `illuminate/hashing`

Not a coupling change. `src/Encryption` is 370 lines of AES and HMAC —
`openssl_encrypt`, `hash_hmac`, `hash_equals`, IV handling, key rotation. That
is the class of code where being almost right is still a security hole and no
test fails.

### Withdrawn — and the premise was wrong

Two findings, both against doing it.

**The code is not hand-rolled.** Compared method by method against
`illuminate/encryption`: `hash()` is algorithmically identical — same
`hash_hmac('sha256', $iv.$value, $key)`, same concatenation order — and the
payload keys are the same four, so a payload written by one is readable by the
other. This is a faithful port, not novel cryptography. The risk it carries is
transcription error, which review catches, rather than design error, which it
does not.

**Both packages require `illuminate/support`.** Taking them would put
Illuminate's `Str`, `Arr`, `Collection` and `Carbon` in the process beside
Nitro's own 4,815-line equivalents — the external coupling this whole file
exists to avoid.

### Taken instead: redact secrets from stack traces

The comparison did surface one thing Illuminate has and Nitro did not.
`#[\SensitiveParameter]` is now on the six Encrypter signatures that carry a
key or a plaintext, and on `Hash::make()` and `Hash::check()`.

Without it, any exception thrown while one of those frames is on the stack
prints the key — or the password — into the trace, and from there into a log
or an error page. Verified: a frame that would have shown the key now reads
`leaky(Object(SensitiveParameterValue))`. No new dependency.
