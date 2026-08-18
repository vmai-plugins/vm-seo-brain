<?php
/**
 * Exercises VMSB_AI_Router::diagnose_json_failure() - the classifier that
 * decides which retry instruction generate_json() sends back to the model
 * after a JSON parse failure. Wrong classification means the retry tells
 * the model to fix a problem it doesn't have, wasting the one retry this
 * plugin allows itself per call.
 *
 * The method is private (internal decision logic, not part of the class's
 * public contract), so this reaches it via Reflection rather than widening
 * the API just to test it.
 */
define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/includes/class-vmsb-ai-router.php';

$method = new ReflectionMethod( 'VMSB_AI_Router', 'diagnose_json_failure' );
$method->setAccessible( true );

$pass = 0;
$fail = 0;

function diagnose( $method, $text ) {
	return $method->invoke( null, $text );
}

function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) {
		$pass++;
		echo "  ok   {$label}\n";
	} else {
		$fail++;
		echo "  FAIL {$label}\n       got:  " . var_export( $got, true ) . "\n       want: " . var_export( $want, true ) . "\n";
	}
}

echo "=== VMSB_AI_Router::diagnose_json_failure() ===\n\n";

check( 'pure prose, no braces at all',
	diagnose( $method, "I'm sorry, I cannot complete this request without more information." ),
	'no_json_attempted' );

check( 'clarifying question before any JSON',
	diagnose( $method, 'Could you provide the GSC export first? {"post_title":""}' ),
	'commentary' );

check( 'refusal phrase, no question mark',
	diagnose( $method, "I don't have enough information to complete this. {\"post_title\":\"\"}" ),
	'commentary' );

check( 'object opened but never closed (truncated mid-field)',
	diagnose( $method, '{"post_title":"Guide","content_html":"<p>Some text that just stops' ),
	'truncated' );

check( 'array opened but never closed',
	diagnose( $method, '{"inline_image_prompts":["one","two"' ),
	'truncated' );

check( 'nested object left open (one extra unclosed brace)',
	diagnose( $method, '{"post_title":"x","faq":[{"q":"x","a":"x"' ),
	'truncated' );

check( 'structurally balanced but still broken (the residual case after parse_json\'s own fix)',
	diagnose( $method, '{"post_title":"x","content_html":"<p>He said "hi" oddly</p>","seo_score":80}' ),
	'malformed' );

check( 'balanced Gutenberg-shaped JSON is NOT flagged as truncated',
	diagnose( $method, '{"content_html":"<!-- wp:heading {"level":2} --><h2>x</h2><!-- /wp:heading -->","seo_score":80}' ),
	'malformed' );

check( 'well-formed JSON that would actually parse still classifies as malformed, not commentary',
	diagnose( $method, '{"post_title":"x","seo_score":80}' ),
	'malformed' );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}
