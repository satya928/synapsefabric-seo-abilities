<?php
// php tests/test-linker.php
require __DIR__ . '/bootstrap.php';

$docs = array(
	1 => array( 'title' => 'Feeding a sourdough starter', 'content' => '<p>A sourdough starter needs flour and water twice a day. Feed the starter until it doubles. Baking sourdough bread at home works best with an active starter.</p>' ),
	2 => array( 'title' => 'Baking sourdough bread at home', 'content' => '<p>Mix flour, water and starter. Bake the sourdough bread in a hot dutch oven for a golden crust.</p>' ),
	3 => array( 'title' => 'WordPress caching explained', 'content' => '<p>Page caching and object caching make WordPress faster for every visitor.</p>' ),
	4 => array( 'title' => 'Cheap hosting for WordPress', 'content' => '<p>Shared hosting is cheap but caching plugins help WordPress run faster on slow servers.</p>' ),
);
$rel = SFSA_Linker::related( $docs, 1, 3 );
check( 'most related to starter post is the bread post', array_key_first( $rel ) === 2 );
check( 'target never related to itself', ! isset( $rel[1] ) );
check( 'unrelated topic below threshold or last', ! isset( $rel[3] ) || $rel[3] < $rel[2] );
check( 'wordpress posts relate to each other', isset( SFSA_Linker::related( $docs, 3, 3 )[4] ) );
check( 'unknown target gives empty', array() === SFSA_Linker::related( $docs, 99 ) );
check( 'scores are rounded floats 0..1', ( function ( $r ) {
	foreach ( $r as $s ) {
		if ( $s < 0 || $s > 1 ) {
			return false;
		}
	}
	return true;
} )( $rel ) );

$m = SFSA_Linker::find_anchor( $docs[1]['content'], array( 'title' => 'Baking sourdough bread at home' ) );
check( 'anchor found in existing text (case preserved)', $m && 'Baking sourdough bread at home' === $m['anchor'] );
check( 'context is the sentence', $m && 0 === strpos( $m['context'], 'Baking sourdough bread at home works best' ) );

$linked = '<p>Read <a href="/bread/">baking sourdough bread at home</a> today.</p>';
check( 'text already linked is not offered again', null === SFSA_Linker::find_anchor( $linked, array( 'title' => 'Baking sourdough bread at home' ) ) );
check( 'no match returns null', null === SFSA_Linker::find_anchor( '<p>Nothing relevant here at all.</p>', array( 'title' => 'Baking sourdough bread at home' ) ) );
check( 'focus keyword preferred', ( function () {
	$m = SFSA_Linker::find_anchor( '<p>Our dutch oven guide covers heat and crusts.</p>', array( 'title' => 'Baking sourdough bread at home', 'keyword' => 'dutch oven guide' ) );
	return $m && 'dutch oven guide' === $m['anchor'];
} )() );
check( 'does not match inside a longer word', null === SFSA_Linker::find_anchor( '<p>Subbaking sourdough breadwinners.</p>', array( 'title' => 'Baking sourdough bread' ) ) );
check( 'ignores markup between sentences', (bool) SFSA_Linker::find_anchor( '<h2>Intro</h2><p>Use <strong>page caching</strong> wisely.</p>', array( 'title' => 'Page caching explained' ) ) );
check( 'no stopword-only phrases', array() === SFSA_Linker::candidate_phrases( array( 'title' => 'How to do it' ) ) );
check( 'single-word titles give no phrases', array() === SFSA_Linker::candidate_phrases( array( 'title' => 'Caching' ) ) );
finish();
