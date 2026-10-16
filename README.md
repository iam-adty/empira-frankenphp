# empira-frankenphp

FrankenPHP worker mode for [Empira](https://github.com/iam-adty/empira-principle),
as a module.

**Optional.** An Empira application does not need this package to run. It runs
under nginx + php-fpm, apache, or PHP's built-in server, with no image to build.

Not on Packagist yet, so Composer has to be told where to look. Add both packages
to your `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/iam-adty/empira-principle.git" },
        { "type": "vcs", "url": "https://github.com/iam-adty/empira-frankenphp.git" }
    ]
}
```

Then require **both**, naming the framework explicitly:

```bash
composer require iam-adty/empira-principle:dev-main iam-adty/empira-frankenphp:dev-main
```

Naming the framework is not ceremony: Composer's default `minimum-stability` is
`stable`, so a transitive dependency on a `dev-` branch is rejected, and this
package's dependency on `empira-principle` is exactly that.

`:dev-main` is needed because there is no release to satisfy; `^0.1@dev` works too,
since both packages alias their branch as `0.1.x-dev`. When these reach Packagist,
the repository block and the suffixes both go away.

## Why it is a package

The worker loop used to live in the application's `public/index.php`:

```php
if (getenv('APP_RUNTIME') !== 'worker') {
    $app->send();   // fine
}
// …then forty lines of FrankenPHP-specific loop nobody else would run
```

So every application shipped a FrankenPHP dependency whether or not it used one,
and an application on nginx had to remember to delete half its own front
controller. The loop is runtime, and runtime does not belong to your application.

## Use it

```php
use Empira\Application\Modules;
use Empira\FrankenPhp\FrankenPhpModule;

#[Modules(FrankenPhpModule::class)]
final class App extends Application
{
    // …
}
```

The container builds each named module, so a class with a default for every
constructor argument installs itself.

Then boot the worker with this package's entrypoint rather than your front
controller:

```bash
vendor/bin/frankenphp-worker --app=/path/to/app
# or, directly, from the application root:
frankenphp php-server run -f vendor/iam-adty/empira-frankenphp/worker.php
```

Run it from the application root. The worker finds your autoloader and your
`bootstrap/app.php` relative to the working directory, because those are the two
layouts it has to serve and they share nothing to be relative to. Set
`EMPIRA_AUTOLOAD` or `EMPIRA_BOOTSTRAP` if your working directory is somewhere else.

## About images

This package ships no image, and that is a decision rather than an omission.

A stock PHP cannot become FrankenPHP at runtime — the extension has to exist
before PHP starts, which is unlike an ordinary extension you can point `-d` at
after the fact. So someone wanting worker mode needs a FrankenPHP PHP, and
producing one is an application and deployment decision: what else goes in the
image, what runs alongside it, where it is built.

An earlier version of this package shipped a `Dockerfile` and a compose file. They
were removed because they could not be verified — they built an application that
does not install this package, so the entrypoint they named did not exist. An
unbuilt image in a published package is worse than no image: it reads as tested.

If you want one, you now know exactly what it has to do.

## It refuses to load where it cannot work

```php
FrankenPhpModule
// LogicException: FrankenPhpModule was installed, but this PHP is not FrankenPHP
```

This looks like it does nothing — the framework resets its per-request state
either way — and that is exactly why it is there. An application that believes it
is running in a worker and is not has a session and an identity map that *look*
handled, until the second request lands on the same thread and is served the
first one's state. Nothing fails before that, and nothing is in the log.

If you see this, you want the classic runtime. Delete the module.

## Recycle the worker

PHP was not built to run forever, and code outside your application leaks in ways
it cannot reach. The worker recycles after `MAX_REQUESTS` (500 by default):

```bash
MAX_REQUESTS=100 vendor/bin/frankenphp-worker --app=/path/to/app
```

## What the framework does for you

`Application::handle()` resets everything per-visitor before every dispatch — the
session, the identity map, the auth context — whether or not a worker is
present. It costs nothing on a fresh process, and doing it unconditionally is what
lets one application run under both runtimes without knowing which it is under.

The rules for your own code are in
[principle/docs/workers.md](https://github.com/iam-adty/empira-principle/blob/main/docs/workers.md),
and they are worth reading before you install this. Most of them are about not
holding per-visitor state in a process that serves thousands of visitors.

## Licence

MIT. See [LICENSE](LICENSE).