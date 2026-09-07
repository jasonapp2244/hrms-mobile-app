<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Employee;
use App\Services\ManagerScope;

/**
 * Shared ground for the manager area.
 *
 * The whole area is already behind `role:manager` and `permission:view-team` in
 * the route table, but neither of those knows *whose* team. That second half —
 * resolving the signed-in user to the employee record their reporting line
 * hangs off — is here, so no manager screen can accidentally skip it.
 *
 * The scope itself lives in ManagerScope. This class only makes it reachable.
 */
abstract class Controller extends BaseController
{
    public function __construct(protected ManagerScope $scope)
    {
    }

    /**
     * The employee record behind the signed-in manager.
     *
     * A manager login with no employee row has no reporting line, so there is
     * nothing for any screen in this area to be about — the same refusal the
     * portal and the mobile API already give, in the same words.
     */
    protected function manager(): Employee
    {
        return $this->scope->employeeFor(auth()->user());
    }

    /**
     * The zone this manager's times are expressed in.
     *
     * Per-company via Company::tz(), never config('app.timezone') directly —
     * that is deliberately UTC and reading it as a wall clock would show a
     * manager punches an hour out from the ones their staff saw.
     */
    protected function timezone(Employee $manager): string
    {
        return $manager->company?->tz() ?? config('app.timezone');
    }
}
