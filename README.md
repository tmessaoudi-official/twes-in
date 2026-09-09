# twes-in

Invoicing and billing for small companies, Tunisia first and France second. A Symfony 8.1 / API Platform 4
API and an Angular 22 web app over PostgreSQL 18. AGPL-3.0-or-later with a commercial licence
(`LICENSING.md`).

`docs/SPEC.md` is what the product is, what has been ruled and where the build stands. `CLAUDE.md` is how
work is delivered here.

## Run it

Needs Docker with Compose, PHP 8.5 with Composer, and Node 26 (`web/.nvmrc`).

```sh
make up          # web http://localhost:8090 · api http://localhost:8091/api · mailpit http://localhost:8092
make gate        # licence gates, API gate (style, PHPStan, PHPUnit), web gate (lint, format, tests, build)
make e2e         # Playwright against the running stack
```

Host ports come from `.env`; override them in your shell or a copy of that file.
