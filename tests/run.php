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
require __DIR__ . '/test-topic-suggest.php';
require __DIR__ . '/test-logo.php';
require __DIR__ . '/test-schema-fields.php';
require __DIR__ . '/test-planner.php';
require __DIR__ . '/test-digest.php';
require __DIR__ . '/test-email.php';
require __DIR__ . '/test-invites.php';
require __DIR__ . '/test-content.php';
require __DIR__ . '/test-directory.php';
require __DIR__ . '/test-chrome.php';
require __DIR__ . '/test-recurrence.php';
require __DIR__ . '/test-repeat-field.php';
require __DIR__ . '/test-ics.php';
require __DIR__ . '/test-filters.php';
require __DIR__ . '/test-help-pdf.php';
require __DIR__ . '/test-links.php';
require __DIR__ . '/test-uploads.php';
require __DIR__ . '/test-joining.php';
require __DIR__ . '/test-topics.php';

Harness::finish();
