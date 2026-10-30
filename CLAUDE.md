# CLAUDE.md

Guidance for Claude Code in the J-ERP repo. Machine-specific setup (PHP path, local DB, how to run) lives in `CLAUDE.local.md` when present — read it first.

## What this is

J-ERP ("Java Core System") — property-developer ERP for PT. MUP / Java Residence, rebuilt from a legacy ASP.NET system (eProperty). Laravel 12 + Filament 3 + Livewire 3 + Tailwind 4, MySQL 8.4, Redis, Reverb, Meilisearch, deployed with Docker.

- **UI language is Indonesian.** Labels, navigation, validation messages, and most code comments are in Indonesian. Keep new UI text in Indonesian and match the existing tone.
- PHP target is **8.4** (`composer.lock` pins `phpoffice/phpspreadsheet`, which does not install on 8.5).
- `docs/CLAUDE.md` is an early *planning* document written before the code existed. Use it and `docs/PRD_cloning.md` / `docs/ERD_cloning.md` for business context, but trust the code over it: things it describes such as `php artisan module:make`, `composer check`, and `routes/modules/fo/*` do not exist.

## Layout

| Path | What lives there |
| --- | --- |
| `app/Modules/{FO,BO,BankLoan,Backup,System,Eprop}/` | Domain code. FO is split by business area: `Approval`, `MarketingSales`, `FinanceAR`, `Collection`, `Komisi`, `Launching`, `Adminjual`, `Booked`, `Komunikasi`, `Survei`, `Shared`. Each area has `Models/`, `Services/`, and sometimes `Controllers/`, `Exports/`. |
| `app/Filament/{Fo,Bo,Bankloan,Backup,System}/` | UI. **Every menu — including FO — is a Filament panel** (`app/Providers/Filament/*PanelProvider.php`). New screens are Filament Resources/Pages here, not Blade routes. |
| `app/Livewire/` | Only the non-panel pages: auth/login, public NUP registration, Booked. |
| `app/Models/` | Shared models (`User`, `Tenant`, `Company`, `Project`, `Numerator`, …) and `Concerns/`. |
| `config/akses.php`, `config/access_matrix.php` | Permission mapping for Filament resources. |
| `config/approval.php`, `config/ipro.php`, `config/sso.php` | Approval rules; FO↔BO integration. |
| `marger/ipro-fo_1/ipro-fo/` | The separate BO/Bankloan app (`ipro-fo`). Not runnable from this app's server. |
| `mobile/` | Mobile approval client. |
| `tests/Feature/{FO,BO,Backup,E2E}/` | Pest feature tests. `tests/Manual/` holds ad-hoc verification scripts, not part of the suite. |

## Conventions for new features

- **Models**: tenant-scoped domain models extend `App\Models\Concerns\TenantAwareModel` (adds `TenantScope`, auto-fills `tenant_id`, `SoftDeletes`, Spatie activity log). Put them in `app/Modules/FO/<Area>/Models/`.
- **Business logic** goes in a Service in `app/Modules/FO/<Area>/Services/`, not in Filament resources or Livewire components.
- **Filament resources** live in `app/Filament/Fo/Resources/` and `use App\Filament\Concerns\MematuhiIzin;`. Then map the resource to permissions in `config/akses.php`. Unmapped resources stay open and show up in `php artisan akses:audit` as unguarded.
- **Approvals**: a new approval type is a subclass of `ApprovalResource` with its own `$jenisScope`, `$slug`, and labels (see `ApprovalBltResource`). The workflow itself goes through `app/Modules/FO/Approval/Services/ApprovalService.php` / `ApprovalExecutionService.php`.
- **Tables** use module prefixes: `fo_*`, `bo_*`, `bankloan_*`.
- **Migrations**: always add a **new** migration file; never edit an existing one (other people's databases have already run it). Data migrations must not assume seeded rows exist — on a fresh DB, migrations run before seeders. `2026_07_01_000010_register_spr_numerator` already breaks this, which is why a fresh install needs `db:seed --class=TenantSeeder` partway through.
- **Document numbers** come from the `Numerator` service (`app/Services/Numerator`), not hand-built strings.
- Match the surrounding code: Indonesian identifiers where the area uses them (`MematuhiIzin`, `kolomIdentitas`, `jenisScope`), and comment density similar to the neighbouring files.

## Team rules (from `PANDUAN-TIM.md`)

- Never work directly on `main`. Work on a branch (`feat/<name>`), merge `origin/main` into it often, and open a Pull Request. Only Yogi merges to `main`.
- Stay inside your own area's folders. **Shared zones — change only after talking to the team:** `app/Modules/BO/IproBridge/`, `config/ipro.php`, `config/sso.php`, and the `ipro_bo` schema.
- Approval area owns `app/Modules/FO/Approval/`, `app/Filament/Fo/Resources/Approval*`, and `mobile/`.

## Commands

Prefix with the PHP 8.4 binary if the default `php` is a different version (see `CLAUDE.local.md`).

```bash
php artisan test                         # whole suite (in-memory SQLite, per phpunit.xml)
php artisan test tests/Feature/FO/SkemaWizardTest.php
php artisan test --filter="name of test"
./vendor/bin/pint                        # format
./vendor/bin/phpstan analyse             # Larastan (phpstan.neon)
php artisan migrate                      # apply new migrations
php artisan akses:audit                  # list resources/users without permission mapping
npm run dev                              # Vite (needed for Tailwind/JS changes)
```

Docker is the team's standard way to run everything (`docker compose up -d`, app on :8081); see `README.md` and `DEPLOY.md`.

Dev login (seeded by `InitialAdminSeeder`): `admin@jerp.test`, password per the README.

## Things that need confirmation first

- Anything touching the shared zones above, deploy scripts in `marger/`, or production `.env` values.
- `migrate:fresh`, dropping tables or columns, or editing data by hand — write a migration or an artisan command instead.
- Turning on `AKSES_TEGAKKAN=true` (it can lock out users who have no permission mapping yet).
