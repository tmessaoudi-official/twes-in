# twes-in

Invoicing and billing for small companies, Tunisia first and France second. A Symfony 8.1 / API Platform 5
API and an Angular 22 web app over PostgreSQL 18. AGPL-3.0-or-later with a commercial licence
(`LICENSING.md`).

`docs/SPEC.md` is what the product is, what has been ruled and where the build stands. `CLAUDE.md` is how
work is delivered here.

## Run it

```sh
make up          # web http://localhost:8090 · api http://localhost:8091/api · mailpit http://localhost:8092
```

- **`docs/START.md`**: bringing the stack up, signing in, creating every kind of user, what data a new stack
  holds, starting clean (`make reset`) and running the checks (`make gate`, `make e2e`), and the production image
  (§ 10, `compose.prod.yaml`); deploying to a server (§ 14) and installing on a shop's own computer (§ 16:
  `scripts/onprem/install.ps1` on Windows, `scripts/onprem/twes install` on Linux).
- **`docs/RUNBOOK.md`**: releasing, rolling back and restoring a running instance or a shop.
- **`docs/UPDATE.md`**: every version the project depends on, where it is written and how to bump it
  (`make versions` prints the current ones).
