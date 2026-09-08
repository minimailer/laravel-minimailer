<?php

declare(strict_types=1);

/**
 * Tripwire: every Guzzle client built in the test suite must carry a mock
 * `handler`, otherwise the transport would go live against the real API on
 * first use. Scans the test files themselves so a future test cannot forget.
 */
it('never constructs an unmocked Guzzle client anywhere in the test suite', function (): void {
    $files = array_merge(
        glob(__DIR__.'/*.php') ?: [],
        glob(dirname(__DIR__).'/*.php') ?: [],
    );

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        preg_match_all('/new\s+GuzzleClient\s*(\(([^;]*?)\))?\s*;/s', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            expect(str_contains($match[2] ?? '', 'handler'))
                ->toBeTrue($file.' constructs a Guzzle client without a mock handler');
        }
    }
});
