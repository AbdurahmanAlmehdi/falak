<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sends a nonce-based Content-Security-Policy with panel pages, and every script carries the nonce', function () {
    $this->withCookie('appearance', 'system'); // renders the inline theme script too

    $response = $this->get('/login')->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'self'")
        ->toContain('connect-src \'self\' wss: https://coolicon.falak.sh');

    preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);
    preg_match_all('/<script\b[^>]*>/i', $response->getContent(), $scripts);
    expect($scripts[0])->not->toBeEmpty();
    foreach ($scripts[0] as $tag) {
        expect($tag)->toContain('nonce="'.$m[1].'"');
    }
});

it('leaves JSON responses alone', function () {
    $this->getJson('/up')->assertHeaderMissing('Content-Security-Policy');
});
