# empira-frankenphp

FrankenPHP worker mode for [Empira](https://github.com/iam-adty/empira-principle),
as a module.

**Optional.** An Empira application does not need this package to run. It runs
under nginx + php-fpm, apache, or PHP's built-in server, with no image to build.

```bash
composer require iam-adty/empira-frankenphp
```

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
// bootstrap/modules.php
use Empira\Auth\AuthModule;
use Empira\FrankenPhp\FrankenPhpModule;

return function (Application $app): array {
    return [
        new AuthModule([
            new SessionGuard($app->session(), new DoctrineUserProvider($app->entities())),
        ]),
        new FrankenPhpModule(),
    ];
};
```

Then boot the worker with this package's entrypoint rather than your front
controller:

```bash
vendor/bin/frankenphp-worker --app=/path/to/app
# or, directly:
frankenphp php-server run -f vendor/iam-adty/empira-frankenphp/worker.php
```

Or with the image:

```bash
docker compose -f frankenphp/docker/compose.yml up -d --build
```

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