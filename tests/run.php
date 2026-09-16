<?php
/**
 * Runs the standalone suite. `php tests/run.php` from the plugin root.
 *
 * @package DGL
 */

declare( strict_types=1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/test-policy.php';
require __DIR__ . '/test-state-machine.php';
require __DIR__ . '/test-schema.php';
require __DIR__ . '/test-brand.php';
require __DIR__ . '/test-logo.php';
require __DIR__ . '/test-schema-fields.php';
require __DIR__ . '/test-planner.php';
require __DIR__ . '/test-digest.php';
require __DIR__ . '/test-email.php';
require __DIR__ . '/test-invites.php';
require __DIR__ . '/test-content.php';
require __DIR__ . '/test-uploads.php';

Harness::finish();
