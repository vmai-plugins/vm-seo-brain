<?php
/**
 * Standalone exercise of VMSB_AI_Router::parse_json() against the exact
 * failure a real producer run hit: Gutenberg block attributes like
 * {"level":2} embedded in the content_html string value without the model
 * escaping the inner quotes for the outer JSON envelope.
 *
 * parse_json() is pure PHP (no WP functions), so this runs the real method
 * from the real file directly - no stubs, no bootstrap.
 */
define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/includes/class-vmsb-ai-router.php';

$pass = 0;
$fail = 0;

function check_exact( $label, $data, $field, $want_value ) {
	global $pass, $fail;
	$got = $data[ $field ] ?? '__MISSING__';
	if ( $got === $want_value ) {
		$pass++;
		echo "  ok   {$label}\n";
	} else {
		$fail++;
		echo "  FAIL {$label}\n       got:  " . var_export( $got, true ) . "\n       want: " . var_export( $want_value, true ) . "\n";
	}
}

function check( $label, $data, $want_pass, array $want_contains = array() ) {
	global $pass, $fail;
	$got_pass = ( null !== $data );
	$ok       = ( $got_pass === $want_pass );
	$missing  = array();
	if ( $ok && $want_pass ) {
		foreach ( $want_contains as $needle ) {
			if ( false === strpos( (string) ( $data['content_html'] ?? '' ), $needle ) ) {
				$ok        = false;
				$missing[] = $needle;
			}
		}
	}
	if ( $ok ) {
		$pass++;
		echo "  ok   {$label}\n";
	} else {
		$fail++;
		echo "  FAIL {$label}  (parsed=" . ( $got_pass ? 'yes' : 'no' ) . ", wanted=" . ( $want_pass ? 'yes' : 'no' ) . ')'
			. ( $missing ? ' missing: ' . implode( ', ', $missing ) : '' ) . "\n";
	}
	return $data;
}

// ---------------------------------------------------------------- fixtures

// 1. Well-formed: every inner quote correctly escaped. Must keep working.
$wellformed = <<<'JSON'
{"post_title":"Naimisharanya Yatra Guide","slug":"naimisharanya-yatra-guide","content_html":"<!-- wp:paragraph --><p>Intro paragraph.</p><!-- /wp:paragraph --><!-- wp:heading {\"level\":2} --><h2>Best Time to Visit</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Winter months are ideal.</p><!-- /wp:paragraph -->","excerpt":"A guide.","seo_title":"Guide","meta_description":"Everything.","featured_image_prompt":"A temple","inline_image_prompts":["A river"],"faq":[{"q":"When?","a":"Winter."}],"suggested_category":"Pilgrimage","suggested_tags":"Naimisharanya","seo_score":94}
JSON;

// 2. The exact bug: one unescaped wp:heading attribute.
$broken_heading = <<<'JSON'
{"post_title":"Naimisharanya Yatra Guide","slug":"naimisharanya-yatra-guide","content_html":"<!-- wp:paragraph --><p>Intro paragraph.</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2>Best Time to Visit</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Winter months are ideal for this yatra.</p><!-- /wp:paragraph -->","excerpt":"A guide.","seo_title":"Guide","meta_description":"Everything.","featured_image_prompt":"A temple","inline_image_prompts":["A river"],"faq":[{"q":"When?","a":"Winter."}],"suggested_category":"Pilgrimage","suggested_tags":"Naimisharanya","seo_score":94}
JSON;

// 3. An image block attribute, unescaped, with a numeric id and a second
//    attribute - checks the fix handles more than one key inside {...}.
$broken_image = <<<'JSON'
{"post_title":"Temple Guide","slug":"temple-guide","content_html":"<!-- wp:paragraph --><p>Intro.</p><!-- /wp:paragraph --><!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class=\"wp-block-image\"><img src=\"x.jpg\" alt=\"Temple\"/></figure><!-- /wp:image --><!-- wp:paragraph --><p>More text.</p><!-- /wp:paragraph -->","excerpt":"A guide.","seo_title":"Guide","meta_description":"Everything.","featured_image_prompt":"Exterior","inline_image_prompts":["Interior"],"faq":[{"q":"Cost?","a":"Free."}],"suggested_category":"Travel","suggested_tags":"Temple","seo_score":90}
JSON;

// 4. The real-world case: a full Hindi article, several unescaped headings
//    at different levels - the closest reproduction of the actual failure.
$broken_multi = <<<'JSON'
{"post_title":"नैमिषारण्य कब जाना चाहिए","slug":"naimisharanya-best-time","content_html":"<!-- wp:paragraph --><p>आध्यात्मिक शांति की खोज में नैमिषारण्य एक प्रमुख तीर्थ स्थल है।</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2>यात्रा का सबसे अच्छा समय</h2><!-- /wp:heading --><!-- wp:paragraph --><p>अक्टूबर से मार्च के बीच मौसम सुहावना रहता है।</p><!-- /wp:paragraph --><!-- wp:heading {"level":3} --><h3>सर्दियों में यात्रा</h3><!-- /wp:heading --><!-- wp:paragraph --><p>सर्दियों में भीड़ कम होती है।</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2>कैसे पहुंचे</h2><!-- /wp:heading --><!-- wp:paragraph --><p>निकटतम रेलवे स्टेशन सीतापुर है।</p><!-- /wp:paragraph -->","excerpt":"नैमिषारण्य यात्रा गाइड।","seo_title":"नैमिषारण्य गाइड","meta_description":"संपूर्ण जानकारी।","featured_image_prompt":"A temple at sunrise","inline_image_prompts":["A river scene","A path"],"faq":[{"q":"कब जाएं?","a":"सर्दियों में।"}],"suggested_category":"Pilgrimage Guides","suggested_tags":"Naimisharanya, Pilgrimage, UP Tourism","seo_score":96}
JSON;

// 5. Existing salvage coverage - smart quotes. Must keep working.
// (Double-quoted PHP string deliberately: \u{...} only expands inside one -
// a single-quoted literal would embed the six raw characters "\u{201C}"
// instead of an actual curly-quote character, silently testing nothing.)
$smart_quotes = "{\"post_title\":\"A Guide\",\"slug\":\"a-guide\",\"content_html\":\"<p>He said \u{201C}hello\u{201D} to me.</p>\",\"excerpt\":\"x\",\"seo_title\":\"x\",\"meta_description\":\"x\",\"featured_image_prompt\":\"x\",\"inline_image_prompts\":[],\"faq\":[],\"suggested_category\":\"x\",\"suggested_tags\":\"x\",\"seo_score\":80}";

// 6. Existing salvage coverage - raw literal newline inside a string value.
$raw_newline = "{\"post_title\":\"A Guide\",\"slug\":\"a-guide\",\"content_html\":\"<p>Line one.\nLine two.</p>\",\"excerpt\":\"x\",\"seo_title\":\"x\",\"meta_description\":\"x\",\"featured_image_prompt\":\"x\",\"inline_image_prompts\":[],\"faq\":[],\"suggested_category\":\"x\",\"suggested_tags\":\"x\",\"seo_score\":80}";

// 7. Existing salvage coverage - trailing comma before a closing brace.
$trailing_comma = '{"post_title":"A Guide","slug":"a-guide","content_html":"<p>Text.</p>","excerpt":"x","seo_title":"x","meta_description":"x","featured_image_prompt":"x","inline_image_prompts":[],"faq":[],"suggested_category":"x","suggested_tags":"x","seo_score":80,}';

// 8. A quote inside plain prose text (not a Gutenberg attribute) - must NOT
//    be "fixed" by a Gutenberg-specific salvage step; this should remain a
//    genuine, unrecoverable parse failure exactly as it does today, so the
//    new step must be narrowly scoped to <!-- wp:...{...}--> and nothing else.
$broken_prose_quote = '{"post_title":"A Guide","slug":"a-guide","content_html":"<p>He said "hello" to me.</p>","excerpt":"x","seo_title":"x","meta_description":"x","featured_image_prompt":"x","inline_image_prompts":[],"faq":[],"suggested_category":"x","suggested_tags":"x","seo_score":80}';

// 9. Mixed escaping within ONE block: the model correctly escaped the first
//    attribute but not the second. Must not double-escape the correct one
//    (which would render as a literal backslash-quote in the saved post)
//    and must still fix the incorrect one.
$broken_mixed_escaping = <<<'JSON'
{"post_title":"Mixed","slug":"mixed","content_html":"<!-- wp:image {\"id\":7,"sizeSlug":"large"} --><figure></figure><!-- /wp:image -->","excerpt":"x","seo_title":"x","meta_description":"x","featured_image_prompt":"x","inline_image_prompts":[],"faq":[],"suggested_category":"x","suggested_tags":"x","seo_score":80}
JSON;

// 10. Bare block comments with no attributes at all (the majority of any
//     real article: wp:paragraph, /wp:paragraph, /wp:heading, ...) - must
//     pass through completely untouched, since the regex requires a { and
//     these have none.
$bare_comments_only = <<<'JSON'
{"post_title":"Bare","slug":"bare","content_html":"<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph -->","excerpt":"x","seo_title":"x","meta_description":"x","featured_image_prompt":"x","inline_image_prompts":[],"faq":[],"suggested_category":"x","suggested_tags":"x","seo_score":80}
JSON;

// 11. No space between the block name and the opening brace - a plausible
//     model formatting slip, distinct from the missing-escape bug but worth
//     covering since it costs nothing extra to handle.
$broken_no_space = <<<'JSON'
{"post_title":"NoSpace","slug":"no-space","content_html":"<!-- wp:heading{"level":2} --><h2>Title</h2><!-- /wp:heading -->","excerpt":"x","seo_title":"x","meta_description":"x","featured_image_prompt":"x","inline_image_prompts":[],"faq":[],"suggested_category":"x","suggested_tags":"x","seo_score":80}
JSON;

// 12. Namespaced block name (contains a slash) with unescaped attrs.
$broken_namespaced = <<<'JSON'
{"post_title":"Embed","slug":"embed","content_html":"<!-- wp:core-embed/youtube {"url":"https://youtube.com/x"} --><figure>embed</figure><!-- /wp:core-embed/youtube -->","excerpt":"x","seo_title":"x","meta_description":"x","featured_image_prompt":"x","inline_image_prompts":[],"faq":[],"suggested_category":"x","suggested_tags":"x","seo_score":80}
JSON;

// ---------------------------------------------------------------- run

echo "=== VMSB_AI_Router::parse_json() ===\n\n";

check( 'well-formed JSON with escaped Gutenberg attrs', VMSB_AI_Router::parse_json( $wellformed ), true, array( 'Best Time to Visit', 'Winter months' ) );
check( 'unescaped wp:heading attribute (the reported bug)', VMSB_AI_Router::parse_json( $broken_heading ), true, array( 'Best Time to Visit', 'Winter months are ideal for this yatra' ) );
check( 'unescaped wp:image attributes (id + sizeSlug)', VMSB_AI_Router::parse_json( $broken_image ), true, array( 'wp:image', 'More text' ) );
check( 'real-world multi-heading Hindi article', VMSB_AI_Router::parse_json( $broken_multi ), true, array( 'यात्रा का सबसे अच्छा समय', 'सर्दियों में यात्रा', 'कैसे पहुंचे', 'सीतापुर' ) );
check( 'smart quotes (regression check)', VMSB_AI_Router::parse_json( $smart_quotes ), true );
check( 'raw newline in string (regression check)', VMSB_AI_Router::parse_json( $raw_newline ), true );
check( 'trailing comma (regression check)', VMSB_AI_Router::parse_json( $trailing_comma ), true );
check( 'quote in plain prose stays a genuine failure', VMSB_AI_Router::parse_json( $broken_prose_quote ), false );

// ---- byte-exact checks: prove the fix does not corrupt what it touches ----

$d = check( 'mixed escaping within one block parses', VMSB_AI_Router::parse_json( $broken_mixed_escaping ), true );
if ( $d ) {
	// Decoded value must show single, correct escaping - not the model's
	// original bare quote, and not double-escaped from re-processing the
	// attribute the model already got right.
	check_exact( '  -> mixed-escaping block decodes to exactly one clean form',
		$d, 'content_html',
		'<!-- wp:image {"id":7,"sizeSlug":"large"} --><figure></figure><!-- /wp:image -->'
	);
}

$d = check( 'bare block comments (no attrs) parse', VMSB_AI_Router::parse_json( $bare_comments_only ), true );
if ( $d ) {
	check_exact( '  -> bare comments pass through byte-for-byte untouched',
		$d, 'content_html',
		'<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph -->'
	);
}

$d = check( 'no space before opening brace still repaired', VMSB_AI_Router::parse_json( $broken_no_space ), true );
if ( $d ) {
	// The fix also normalises to WordPress's own canonical spacing
	// (get_comment_delimited_block_content() always inserts one space
	// before the attributes) rather than preserving the model's malformed
	// no-space variant verbatim.
	check_exact( '  -> no-space block decodes correctly and is normalised',
		$d, 'content_html',
		'<!-- wp:heading {"level":2} --><h2>Title</h2><!-- /wp:heading -->'
	);
}

$d = check( 'namespaced block name (core-embed/youtube) repaired', VMSB_AI_Router::parse_json( $broken_namespaced ), true );
if ( $d ) {
	check_exact( '  -> namespaced block decodes correctly',
		$d, 'content_html',
		'<!-- wp:core-embed/youtube {"url":"https://youtube.com/x"} --><figure>embed</figure><!-- /wp:core-embed/youtube -->'
	);
}

// The real-world multi-heading article: verify EVERY field survived, not
// just content_html - a fix scoped to content_html could still be masking
// collateral damage to sibling keys if the regex over-matched.
$multi = VMSB_AI_Router::parse_json( $broken_multi );
if ( null !== $multi ) {
	check_exact( 'multi-heading article: post_title exact', $multi, 'post_title', 'नैमिषारण्य कब जाना चाहिए' );
	check_exact( 'multi-heading article: seo_score exact (type-preserved int)', $multi, 'seo_score', 96 );
	check_exact( 'multi-heading article: suggested_tags exact', $multi, 'suggested_tags', 'Naimisharanya, Pilgrimage, UP Tourism' );
	check_exact( 'multi-heading article: content_html exact byte-for-byte',
		$multi, 'content_html',
		'<!-- wp:paragraph --><p>आध्यात्मिक शांति की खोज में नैमिषारण्य एक प्रमुख तीर्थ स्थल है।</p><!-- /wp:paragraph -->'
		. '<!-- wp:heading {"level":2} --><h2>यात्रा का सबसे अच्छा समय</h2><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>अक्टूबर से मार्च के बीच मौसम सुहावना रहता है।</p><!-- /wp:paragraph -->'
		. '<!-- wp:heading {"level":3} --><h3>सर्दियों में यात्रा</h3><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>सर्दियों में भीड़ कम होती है।</p><!-- /wp:paragraph -->'
		. '<!-- wp:heading {"level":2} --><h2>कैसे पहुंचे</h2><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>निकटतम रेलवे स्टेशन सीतापुर है।</p><!-- /wp:paragraph -->'
	);
	check_exact( 'multi-heading article: faq array survived (count)', $multi, 'faq', array( array( 'q' => 'कब जाएं?', 'a' => 'सर्दियों में।' ) ) );
} else {
	$fail++;
	echo "  FAIL multi-heading article: parse returned null, cannot verify field integrity\n";
}

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	exit( 1 );
}
