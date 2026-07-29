<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path has no public page — it hands guests to the login screen and
     * signed-in users to whichever home their role resolves to. The stub this
     * replaces asserted a 200 and had been failing since the routes were written.
     */
    public function test_the_root_path_sends_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
