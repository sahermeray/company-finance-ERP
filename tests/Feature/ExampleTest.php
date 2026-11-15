<?php

test('the application sends visitors to the dashboard', function () {
    $response = $this->get('/');

    $response->assertRedirect('/dashboard');
});
