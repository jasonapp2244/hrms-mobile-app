# A2.10 — Multi-company tenancy: what is actually left

**Assessed 15 September 2026, by reading and testing the code rather than the
roadmap.** Every claim here is backed by something that runs.

---

## The short version

**A2.10 is mis-labelled.** The board carries it as ⬜ *Multi-company (SaaS
tenancy) support*, which reads as a data-model project. It is not one. Two
companies can already coexist in this database, be administered separately, and
cannot see each other — and that is now proven rather than assumed.

What is genuinely missing is **onboarding**: creating a company is a
command-line operation. And one latent hazard has to be closed before a second
company is put on a live box.

So the remaining work is closer to *"SaaS onboarding"* than to *"SaaS tenancy"*,
and one part of it is a **product decision rather than an engineering task**.

---

## 1. What already works

### The schema is company-scoped

25 business tables carry `company_id`. The 17 that do not are framework tables
(`cache`, `jobs`, `sessions`, `migrations`), `companies` itself, Spatie's
`roles` / `permissions` tables, or rows scoped through a user
(`notifications`, `push_devices`, `personal_access_tokens`).

### Two companies can be created and administered

`php artisan emp:install` already handles it:

| Flag | What it does |
|---|---|
| *(none, empty database)* | Creates the company and its first administrator |
| `--company-id=N` | Attaches a new administrator to an existing company |
| `--force` | Creates a **second** company on a database that already has one |

The command already refuses to guess: on a database with a company and no
`--company-id`, it stops and lists what exists, because attaching an
administrator to the wrong company gives them an empty dashboard rather than an
error.

### They cannot see each other — tested, not assumed

`tests/Feature/CrossCompanyIsolationTest` stands up two whole companies — each
with an administrator, a manager, a direct report, and one of everything worth
reaching — and attempts **36 real crossings**:

- **As an administrator**: opening, editing and deleting the other company's
  employees, departments, designations, offices, shifts, holidays, leave types
  and announcements; publishing their announcement; deciding their leave;
  reading their employee's document vault and onboarding checklist
- **As a manager**: opening another company's employee, deciding their swap and
  their leave, and three of the manager's own screens that must not *list* the
  other company
- **As an employee**: withdrawing another company's leave, correction and swap —
  where the guard is ownership rather than company, which is stronger but is
  the *only* thing standing there, since those three controllers carry no
  company check at all
- **Over the API**: the document download, leave read and cancel, correction
  cancel, the colleague directory, and the three `team/*` endpoints — which
  answer for "my team" with no id in the URL, so the crossing to test is not a
  refusal but an **absence**
- **A user with no company of their own**, since the fallback closed in §2
- Seven listings — which leak by *including* rather than by answering

All refuse. **The suite was mutation-checked rather than trusted for passing
first time**: removing the guard from `EmployeeDocumentController` and
`DepartmentController` fails exactly the right three tests, one of them on a
`200` for another company's document list.

> Three guard idioms are in use across the controllers — `authorizeCompany`,
> `authoriseCompany`, and a bare `abort_unless($x->company_id === ...)` — plus
> ownership checks in self-service paths and team checks in manager ones.
> Reading one controller proves nothing about the next one somebody writes,
> which is why this is a test and not a review. **Extend it when you add a route
> that takes a bound model.**

---

## 2. The one thing that had to be fixed first — **done 15 September 2026**

> **Closed.** The fallback is gone from all 23 sites and `companyId()` now lives
> once, on the base `Controller`, failing closed. Three tests in
> `CrossCompanyIsolationTest` cover it, and they were verified by restoring the
> old behaviour: a company-less administrator then gets **`200` on the dashboard
> and `200` on the employee list** — another company's data, exactly as
> described below. The 19 identical copies of the method went with it.
>
> The rest of this section is kept as the record of what the problem was.

### The `?? Office::value('company_id')` fallback — 23 call sites

Every controller resolves the current company like this:

```php
return auth()->user()->company_id ?? Office::value('company_id');
```

On a single-company install that fallback is a harmless convenience. **With two
companies it is a cross-tenant read**: a user whose `company_id` is null is
silently handed whichever company owns the first office row — in practice,
company #1.

**Current exposure: latent, not live.**

- `users.company_id` is nullable in the schema.
- Both paths that create a user — `emp:install` and the **Sign-in Account**
  panel (`EmployeeAccountController`) — set it.
- No user in the database has a null `company_id`.

So nothing reaches it today. But it is 23 unguarded doors held shut by the
absence of a key, and the moment a second company exists on a live box, the
cost of somebody creating a user another way stops being theoretical.

**Recommended fix — fail closed, do not guess.** Resolve the company once, in
one place, and refuse when there is none rather than substituting a neighbour's.
A user with no company has no dashboard to show; an empty screen with an honest
message is correct, and a silent read of somebody else's data is not.

*Effort: half a day, mostly mechanical. Do it before a second company goes near
production, not as part of the onboarding work.*

---

## 3. The product decision

**Creating a company is a command-line operation.** There is no sign-up route —
`route:list` has none, and that is deliberate rather than missing: this system
is deployed for one client, and a public "create your company" form on
`hrams.devonlinetestserver.com` would let anyone on the internet create tenants
on the client's server.

So the question is not *how* to build onboarding but **whether it is wanted**:

| Shape | What it means | Suits |
|---|---|---|
| **Stay CLI-only** | New companies are provisioned by whoever runs the server | A product sold and installed per client — what this is today |
| **Invite-only** | An operator generates a signed invitation; the recipient completes setup in the browser | A managed service with a handful of client companies |
| **Open sign-up** | Anyone can create a company | A self-serve SaaS — and a different product, with billing, abuse handling and support attached |

**Recommendation: invite-only, if anything.** It is the only one of the three
that adds real convenience without also adding an unauthenticated write that
creates database rows. Open sign-up should not be built without the commercial
decisions that go with it.

**None of this should be built until the client says which.** Building the wrong
one is worse than building none: open sign-up on a client's box is a liability,
and CLI-only is already working.

---

## 4. Also decide: are roles global or per-company?

Spatie's `roles` and `permissions` tables carry no `company_id`, so all
companies share one set of four roles and 19 permissions.

**Global is defensible and is the current behaviour** — the roles mean the same
thing in every company, and per-company duplicates would be 19 identical rows
per tenant with no way for them to diverge usefully.

It is recorded here because it should be a **decision rather than a discovery**.
If a company ever needs a role of its own, that is a schema change and a
migration, not a configuration screen.

---

## 5. What A2.10 would actually consist of

In the order they should happen:

1. ~~**Close the fallback** (§2).~~ **Done 15 September 2026.** One
   `companyId()` on the base `Controller`, failing closed; 19 duplicated copies
   deleted; three tests, verified by restoring the old behaviour and watching
   them fail on a `200`.
2. **Get the product decision** (§3). Not an engineering task.
3. ~~**Extend the isolation suite** to the manager-scoped and self-service
   routes.~~ **Done 15 September 2026** — 36 crossings now, up from 22. Adds the
   manager area (team screens, swap and leave decisions, and three of their own
   screens that must not *list* another company), the self-service withdrawals
   where the guard is ownership rather than company, and the manager API
   endpoints, which answer for "my team" with no id in the URL — so the crossing
   to test there is not a refusal but an **absence**: nobody else's name may
   appear in the payload. Mutation-checked again, one guard from each new group.
4. **Build the chosen onboarding shape**, if any. Invite-only is roughly two
   days: a signed invitation, a setup form that creates the company and its
   first administrator through the same code path `emp:install` already uses,
   and an expiry.
5. **Record the roles decision** (§4) in `CLAUDE.md`, whichever way it goes.

Steps 1 and 3 are worth doing regardless of whether 4 ever happens. Step 4
should not start before step 2.

---

*Compiled from `RolePermissionSeeder`, the route table, `emp:install`, the
controller guards, the live schema, and `tests/Feature/CrossCompanyIsolationTest`.
Nothing in it is inferred from the roadmap.*
 