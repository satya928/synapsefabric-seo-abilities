<?php
// php tests/test-analyzer.php
require __DIR__ . '/bootstrap.php';

$html = '<!-- wp:heading --><h2>One</h2><!-- /wp:heading --><h3>Two</h3>
<img src="/a.jpg" alt="A cat"><img src="/b.jpg"><img src="/c.jpg" alt=""><img src=\'/d.jpg\' alt=\'single quoted\'>
<a href="/foo/">internal <b>link</b></a> <a href="https://Example.com/bar/?x=1">abs internal</a>
<a href="https://other.org/x">external</a> <a href="mailto:a@b.c">mail</a> <a href="#top">anchor</a> <a href="/p?a=1&amp;b=2">amp</a>';
$a = SFSA_Analyzer::analyze( $html );

check( 'counts headings', 1 === $a['headings']['h2'] && 1 === $a['headings']['h3'] && 0 === $a['headings']['h1'] );
check( 'finds 4 images', 4 === count( $a['images'] ) );
check( 'alt: present, missing, empty, single-quoted', array( true, false, false, true ) === array_column( $a['images'], 'has_alt' ) );
check( 'single-quoted alt text read', 'single quoted' === $a['images'][3]['alt'] );
check( 'finds 6 links', 6 === count( $a['links'] ) );
check( 'link text strips tags', 'internal link' === $a['links'][0]['text'] );
check( 'entities decoded in href', '/p?a=1&b=2' === $a['links'][5]['href'] );

check( 'relative is internal', SFSA_Analyzer::is_internal( '/foo/', 'example.com' ) );
check( 'absolute same host (case, www) is internal', SFSA_Analyzer::is_internal( 'https://WWW.Example.com/x', 'example.com' ) );
check( 'protocol-relative same host is internal', SFSA_Analyzer::is_internal( '//example.com/x', 'example.com' ) );
check( 'other host is external', ! SFSA_Analyzer::is_internal( 'https://other.org/x', 'example.com' ) );
check( 'lookalike host is external', ! SFSA_Analyzer::is_internal( 'https://example.com.evil.io/x', 'example.com' ) );
check( 'mailto/tel/#/javascript/empty not links', ! SFSA_Analyzer::is_internal( 'mailto:a@b.c', 'e.com' ) && ! SFSA_Analyzer::is_internal( 'tel:1', 'e.com' ) && ! SFSA_Analyzer::is_internal( '#x', 'e.com' ) && ! SFSA_Analyzer::is_internal( 'javascript:void(0)', 'e.com' ) && ! SFSA_Analyzer::is_internal( '', 'e.com' ) );

check( 'path_key ignores host, slashes, case, query', 'my-post' === SFSA_Analyzer::path_key( 'https://Example.com/My-Post/?utm=1' ) && 'my-post' === SFSA_Analyzer::path_key( '/my-post' ) );
check( 'path_key ?p=ID form', 'p:42' === SFSA_Analyzer::path_key( 'https://e.com/?p=42' ) && 'p:7' === SFSA_Analyzer::path_key( '/?page_id=7' ) );
check( 'path_key home page is empty', '' === SFSA_Analyzer::path_key( 'https://e.com/' ) );
check( 'path_key percent-decodes', 'café' === SFSA_Analyzer::path_key( '/caf%C3%A9/' ) );
check( 'empty html', 0 === count( SFSA_Analyzer::analyze( '' )['links'] ) );
finish();
