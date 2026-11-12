<?php

it('responds to the health check', function () {
    $this->get('/health')->assertOk();
});
