<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Set up a real install: one company, one administrator, no invented people.
 *
 * This replaces the demo seeder as the way a new install is started. The old
 * route was `db:seed`, which produced a fictional company and `admin@hrms.test`
 * with the password `password` — fine for a sandbox, wrong everywhere else, and
 * the deployment guide's answer was a page of PHP to paste into `tinker`. That
 * is a bad instruction to give somebody on a live server: it runs unvalidated,
 * it is easy to half-finish, and forgetting the RolePermissionSeeder line
 * leaves an administrator with no permissions and no obvious reason why.
 *
 * So the same job is done here, where it can be checked. The one input worth
 * dwelling on is the timezone: attendance is judged against shift times in it,
 * so a wrong value marks the entire workforce late every morning and produces
 * plausible data rather than an error. It is validated against the real list.
 *
 * Safe to run on production. Refuses to run twice unless told to.
 */
class Install extends Command
{
    protected $signature = 'hrms:install
                            {--company= : Company name}
                            {--company-id= : Attach the administrator to this existing company instead of creating one}
                            {--timezone= : IANA timezone, e.g. America/New_York}
                            {--currency=USD : ISO currency code}
                            {--name= : The administrator\'s full name}
                            {--email= : The administrator\'s email address}
                            {--password= : The administrator\'s password (prompted for if omitted)}
                            {--force : Create a second company even though this database already has one}';

    protected $description = 'Create the company and first administrator for a new install';

    public function handle(): int
    {
        $this->line('');
        $this->line('  <options=bold>HR & Attendance — install</>');
        $this->line('');

        $existing = $this->resolveExistingCompany();

        if ($existing === false) {
            return self::FAILURE;
        }

        // An existing company keeps its name, timezone and currency. They are
        // already what every shift and report is judged against, and changing
        // them here — while ostensibly just adding a user — would re-time the
        // whole workforce.
        $company  = $existing?->name ?? $this->given('company', 'Company name');
        $timezone = $existing?->timezone ?? $this->askTimezone();
        $currency = $existing?->currency ?? strtoupper((string) ($this->option('currency') ?: 'USD'));

        $this->line('');
        $this->line('  <fg=gray>Now the administrator account — the one that can reach every page.</>');
        $this->line('');

        $name     = $this->given('name', "Administrator's name");
        $email    = $this->askEmail();
        $password = $this->askPassword();

        $errors = Validator::make(
            compact('company', 'name', 'email'),
            [
                'company' => 'required|string|max:255',
                'name'    => 'required|string|max:255',
                'email'   => 'required|email|max:255',
            ]
        )->errors();

        if ($errors->isNotEmpty()) {
            foreach ($errors->all() as $message) {
                $this->error('  ' . $message);
            }

            return self::FAILURE;
        }

        $this->line('');
        $this->table([], [
            ['Company', $company . ($existing ? "  (existing, #{$existing->id})" : '  (new)')],
            ['Timezone', $timezone],
            ['Currency', $currency],
            ['Administrator', "{$name} <{$email}>"],
        ]);

        if (! $this->option('no-interaction') && ! $this->confirm('Create these?', true)) {
            $this->line('  Nothing was created.');

            return self::SUCCESS;
        }

        try {
            // One transaction: a company with no administrator is a database
            // nobody can sign in to, and the only fix is the tinker session
            // this command exists to avoid.
            $user = DB::transaction(function () use ($existing, $company, $timezone, $currency, $name, $email, $password) {
                $record = $existing ?: Company::create([
                    'name'      => $company,
                    'timezone'  => $timezone,
                    'currency'  => $currency,
                    'is_active' => true,
                ]);

                $user = User::create([
                    'name'       => $name,
                    'email'      => $email,
                    'password'   => Hash::make($password),
                    'company_id' => $record->id,
                    'is_active'  => true,
                ]);

                // Before assignRole, or there is no 'admin' role to assign.
                // Idempotent, so --force on an existing install is harmless.
                $this->callSilent('db:seed', [
                    '--class' => RolePermissionSeeder::class,
                    '--force' => true,
                ]);

                $user->assignRole('admin');

                return $user;
            });
        } catch (Throwable $e) {
            $this->error('  Nothing was created: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info("  Done. Sign in as {$user->email}");
        $this->line('');
        $this->line('  <fg=gray>Next: add offices, departments and shifts, then employees.</>');
        $this->line('  <fg=gray>Check the install with: php artisan hrms:preflight</>');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Which company the new administrator joins: an existing one, or a new one.
     *
     * The case this exists for is an install that already has staff but no
     * administrator — after a purge, or when the only admin account was lost.
     * Creating a fresh company there would be the worst possible outcome and
     * the hardest to spot: the sign-in works, the dashboard loads, and it is
     * empty, because every employee is on the other company and companies
     * cannot see each other. So an existing company is the default, and a
     * second one has to be asked for by name.
     *
     * @return Company|null|false  the company to join, null to create one,
     *                             false to abort
     */
    protected function resolveExistingCompany(): Company|null|false
    {
        if ($id = $this->option('company-id')) {
            $company = Company::find($id);

            if (! $company) {
                $this->error("  No company has id {$id}.");
                $this->listCompanies();

                return false;
            }

            return $company;
        }

        if ($this->option('force') || ! Company::exists()) {
            return null;   // deliberately new, or genuinely a fresh database
        }

        $companies = Company::orderBy('id')->get();

        if ($this->option('no-interaction')) {
            $this->error('  This database already has a company, and no --company-id was given.');
            $this->line('  <fg=gray>Adding an administrator to the wrong company gives them an empty</>');
            $this->line('  <fg=gray>dashboard, because staff on other companies are invisible to them.</>');
            $this->listCompanies();
            $this->line('  <fg=gray>Pass --company-id=N, or --force to create a second company.</>');
            $this->line('');

            return false;
        }

        $choices = $companies
            ->mapWithKeys(fn (Company $c) => [
                (string) $c->id => "#{$c->id}  {$c->name}  ({$c->employees()->count()} employees)",
            ])
            ->all();

        $choices['new'] = 'Create a new company instead';

        $answer = $this->choice(
            'This database already has a company. Which one is this administrator for?',
            $choices,
            (string) $companies->first()->id,
        );

        // choice() gives back the label, not the key.
        $picked = array_search($answer, $choices, true);

        return $picked === 'new' ? null : $companies->firstWhere('id', (int) $picked);
    }

    protected function listCompanies(): void
    {
        $this->line('');

        foreach (Company::orderBy('id')->get() as $company) {
            $this->line("    <fg=gray>#{$company->id}</>  {$company->name}  <fg=gray>({$company->employees()->count()} employees)</>");
        }

        $this->line('');
    }

    /**
     * The option if it was given, otherwise the prompt.
     *
     * `ask()` with a default still stops and waits, which would hang a deploy
     * script that had already passed every value on the command line.
     */
    protected function given(string $option, string $question): string
    {
        return (string) ($this->option($option) ?: $this->ask($question));
    }

    /**
     * The setting most worth getting right, so it is not accepted until it is a
     * real identifier. A typo here does not error — it silently falls back to
     * UTC and mismarks every shift from then on.
     */
    protected function askTimezone(): string
    {
        $valid = timezone_identifiers_list();
        $given = $this->option('timezone');

        while (true) {
            $timezone = $given ?: $this->ask('Company timezone (this decides what "09:00" means)', 'UTC');

            if (in_array($timezone, $valid, true)) {
                return $timezone;
            }

            $this->error("  '{$timezone}' is not an IANA timezone. Examples: America/New_York, Europe/London, Asia/Karachi");

            if ($given) {
                // Given on the command line and wrong. Looping on the same bad
                // value would spin forever under --no-interaction.
                $given = null;
            }
        }
    }

    protected function askEmail(): string
    {
        $given = $this->option('email');

        while (true) {
            $email = $given ?: $this->ask("Administrator's email address");

            if ($email && ! User::where('email', $email)->exists()) {
                return $email;
            }

            $this->error($email
                ? "  {$email} is already taken by another account."
                : '  An email address is required.');

            $given = null;
        }
    }

    protected function askPassword(): string
    {
        if ($given = $this->option('password')) {
            return $given;
        }

        while (true) {
            $password = $this->secret("Administrator's password (at least 8 characters)");
            $confirm  = $this->secret('Confirm the password');

            if ($password !== $confirm) {
                $this->error('  Those did not match.');

                continue;
            }

            if (strlen((string) $password) < 8) {
                $this->error('  Too short — at least 8 characters.');

                continue;
            }

            // The exact string hrms:preflight fails a production deploy for,
            // so it is better refused now than discovered at go-live.
            if ($password === 'password') {
                $this->error('  Not that one. It is the seeded default and the first thing anybody tries.');

                continue;
            }

            return $password;
        }
    }
}
