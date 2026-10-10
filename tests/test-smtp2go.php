<?php
/**
 * SMTP2GO's answer, read without a network.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Email\Smtp2go;

Harness::group( 'SMTP2GO: the activity answer is flattened, and a refusal is an error with its words' );

$reply = [ 'data' => [ 'events' => [
	[ 'time' => '2026-10-10T20:01:00Z', 'event' => 'delivered', 'subject' => 'Approved: Coffee morning', 'recipient' => 'hello@example.org', 'sender' => 'hello@example.org', 'smtp_response' => '250 OK' ],
	[ 'time' => '2026-10-10T20:00:59Z', 'event' => 'processed', 'subject' => 'Approved: Coffee morning', 'recipient' => 'hello@example.org' ],
] ] ];
$events = Smtp2go::summarise( $reply );
Harness::assert_same( 2, count( $events ), 'two events' );
Harness::assert_same( 'delivered', $events[0]['event'], 'the event name comes through' );
Harness::assert_same( '250 OK', $events[0]['detail'], 'and the SMTP response as the detail' );
Harness::assert_same( '', $events[1]['detail'], 'blank when there is none' );
Harness::assert_same( [], Smtp2go::summarise( [ 'data' => [ 'events' => [] ] ] ), 'nothing recorded is an empty list, not an error' );
$refused = Smtp2go::summarise( [ 'data' => [ 'error' => 'Invalid API key', 'error_code' => 'E_ApiResponseCodes.API_KEY_INVALID' ] ], 403 );
Harness::assert_true( is_wp_error( $refused ), 'a refusal is an error' );
Harness::assert_true( str_contains( $refused->get_error_message(), 'Invalid API key' ) && str_contains( $refused->get_error_message(), '403' ), 'carrying the service\'s words and the status' );
