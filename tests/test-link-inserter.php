<?php
/**
 * Standalone exercise of VMSB_Link_Inserter's parsing core against the exact
 * content shapes that broke the old preg_replace: existing anchors, image alt
 * text, Gutenberg block JSON, shortcodes and headings.
 */
define( 'ABSPATH', __DIR__ );

// Minimal stubs for the two core functions the pure parsing path touches.
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require dirname( __DIR__ ) . '/includes/class-vmsb-link-inserter.php';

$pass = 0;
$fail = 0;

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

/** Run locate() and return the spliced result, or null when it refuses. */
function splice_link( $content, $anchor, $url = 'https://example.com/target/' ) {
	$blocked = VMSB_Link_Inserter::protected_ranges( $content );
	$hit     = VMSB_Link_Inserter::locate( $content, $anchor, $blocked );
	if ( ! $hit ) {
		return null;
	}
	$matched = substr( $content, $hit['offset'], $hit['length'] );
	return substr( $content, 0, $hit['offset'] )
		. '<a href="' . $url . '">' . $matched . '</a>'
		. substr( $content, $hit['offset'] + $hit['length'] );
}

echo "\n--- 1. the bugs that motivated this class ---\n";

// Anchor text appears first inside an existing link. Old code nested an <a>.
$c = '<p>Read our <a href="/old/">roof repair guide</a> before you book a roof repair guide review.</p>';
$r = splice_link( $c, 'roof repair guide' );
check( 'never nests inside an existing anchor', ( $r !== null && substr_count( $r, '<a ' ) === 2 && strpos( $r, '<a href="/old/"><a' ) === false ), true );
check( '  and picks the later, free occurrence', strpos( $r, 'book a <a href="https://example.com/target/">roof repair guide</a>' ) !== false, true );

// Anchor appears first inside an alt attribute.
$c = '<p><img src="a.jpg" alt="a solar panel install in progress" /> We handle every solar panel install.</p>';
$r = splice_link( $c, 'solar panel install' );
check( 'never matches inside an attribute', strpos( $r, 'alt="a <a' ) === false, true );
check( '  and links the body text instead', strpos( $r, 'every <a href="https://example.com/target/">solar panel install</a>' ) !== false, true );

// Gutenberg block comment carrying the phrase in its JSON.
$c = '<!-- wp:heading {"className":"tax return help"} --><h2>Filing</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>We offer tax return help all year.</p><!-- /wp:paragraph -->';
$r = splice_link( $c, 'tax return help' );
check( 'never corrupts Gutenberg block JSON', strpos( $r, '{"className":"tax return help"}' ) !== false, true );
check( '  and links the paragraph', strpos( $r, 'offer <a href="https://example.com/target/">tax return help</a> all year' ) !== false, true );

// Shortcode argument.
$c = '<p>[gallery title="best hiking boots" ids="1,2"] Our pick of the best hiking boots is below.</p>';
$r = splice_link( $c, 'best hiking boots' );
check( 'never matches inside a shortcode', strpos( $r, 'title="best hiking boots"' ) !== false, true );

// Heading-only occurrence: refuse rather than link a heading.
$c = '<h2>Emergency plumbing services</h2><p>Nothing else here mentions it.</p>';
$r = splice_link( $c, 'emergency plumbing services' );
check( 'refuses when the only match is a heading', $r, null );

echo "\n--- 2. entity and whitespace tolerance ---\n";

// The model returns decoded text; the content holds the entity.
$c = '<p>Our R&amp;D team ships weekly.</p>';
$r = splice_link( $c, 'R&D team' );
check( 'matches R&D across the entity', strpos( $r, '>R&amp;D team</a>' ) !== false, true );
check( '  and does not double-encode it', strpos( $r, '&amp;amp;' ) === false, true );

// Line break inside the phrase in the source.
$c = "<p>We build custom\n   timber decking for gardens.</p>";
$r = splice_link( $c, 'custom timber decking' );
check( 'matches across a newline', $r !== null && strpos( $r, "custom\n   timber decking</a>" ) !== false, true );

// Curly apostrophe in content, straight one from the model.
$c = '<p>The builder’s merchant opens at eight.</p>';
$r = splice_link( $c, "builder's merchant" );
check( 'matches a curly apostrophe', $r !== null && strpos( $r, '>builder’s merchant</a>' ) !== false, true );

echo "\n--- 3. word boundaries ---\n";

$c = '<p>The costume shop is closed; cost is not mentioned elsewhere.</p>';
$r = splice_link( $c, 'cost' );
check( 'does not match inside a longer word', strpos( $r, 'costume' ) !== false && strpos( $r, '>cost</a>' ) !== false, true );

$c = '<p>Only costumes here.</p>';
$r = splice_link( $c, 'cost' );
check( 'refuses when every match would split a word', $r, null );

echo "\n--- 4. links_to() ---\n";

$c = '<p><a href="https://site.com/guide-to-everything/">x</a></p>';
check( 'prefix URL is not a match', VMSB_Link_Inserter::links_to( $c, 'https://site.com/guide/' ), false );
check( 'exact URL is a match', VMSB_Link_Inserter::links_to( $c, 'https://site.com/guide-to-everything/' ), true );
check( 'match ignores trailing slash', VMSB_Link_Inserter::links_to( $c, 'https://site.com/guide-to-everything' ), true );
check( 'plain mention is not a link', VMSB_Link_Inserter::links_to( '<p>see /guide/ for more</p>', 'https://site.com/guide/' ), false );

echo "\n--- 5. protected ranges are merged and sorted ---\n";
$ranges = VMSB_Link_Inserter::protected_ranges( '<p>a</p><script>var x = "<p>";</script><p>b</p>' );
$sorted = $ranges;
usort( $sorted, fn( $a, $b ) => $a[0] <=> $b[0] );
check( 'ranges come back sorted', $ranges, $sorted );
$overlap = false;
for ( $i = 1; $i < count( $ranges ); $i++ ) {
	if ( $ranges[ $i ][0] <= $ranges[ $i - 1 ][1] ) { $overlap = true; }
}
check( 'ranges do not overlap', $overlap, false );

echo "\n" . str_repeat( '-', 46 ) . "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
