<?php

it('exports an OpenAPI 3 document covering the app and server APIs', function () {
    $path = storage_path('framework/testing/openapi-test.json');
    $this->artisan('scramble:export', ['--path' => $path])->assertSuccessful();
    $doc = json_decode((string) file_get_contents($path), true);
    @unlink($path);

    expect($doc['openapi'])->toStartWith('3.')
        ->and(array_keys($doc['components']['securitySchemes']))->toEqualCanonicalizing(['userToken', 'appSecret']);

    foreach ([
        '/apps/{app_key}/devices', '/apps/{app_key}/auth/login', '/apps/{app_key}/me/devices/link', '/apps/{app_key}/push/opened',
        '/apps/{app_key}/notices', '/apps/{app_key}/videos/{video}/play', '/apps/{app_key}/playback-sessions/{session}/heartbeat',
        '/server/apps/{app_key}/users/{external_user_id}', '/server/apps/{app_key}/users/{external_user_id}/tokens', '/server/apps/{app_key}/users/attributes',
    ] as $p) {
        expect($doc['paths'])->toHaveKey($p);
    }
    expect($doc['paths']['/server/apps/{app_key}/users/attributes']['post']['security'])->toBe([['appSecret' => []]]);
});
