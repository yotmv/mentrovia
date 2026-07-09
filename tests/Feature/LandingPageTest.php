<?php

test('guests can visit the branded landing page', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('A clearer path for the business you are building.')
        ->assertSee('What Mentrovia organizes')
        ->assertSee('Guidance that makes its limits clear.');
});
