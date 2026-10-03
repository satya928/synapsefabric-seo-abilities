<?php
/**
 * Static analysis of post HTML (headings, images, links) and URL helpers.
 *
 * Pure PHP (no WordPress calls) so it can be unit-tested without a site.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || defined( 'SFSA_TESTING' ) || exit;

/**
 * Content analyzer.
 */
class SFSA_Analyzer {

	/**
	 * Analyse post HTML.
	 *
	 * @param string $html Post content.
	 * @return array{headings:array<string,int>,images:array,links:array}
	 */
	public static function analyze( $html ) {
		$html     = preg_replace( '/<!--.*?-->/s', '', (string) $html );
		$headings = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$headings[ 'h' . $i ] = preg_match_all( '/<h' . $i . '\b/i', $html );
		}

		$images = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$alt      = '';
				$has_attr = preg_match( '/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $a );
				if ( $has_attr ) {
					$alt = isset( $a[2] ) && '' !== $a[2] ? $a[2] : $a[1];
				}
				$src      = preg_match( '/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $s ) ? ( '' !== $s[1] ? $s[1] : ( $s[2] ?? '' ) ) : '';
				$images[] = array(
					'src'     => $src,
					'alt'     => trim( $alt, " \t\n\r\0\x0B\f" ),
					'has_alt' => '' !== trim( $alt, " \t\n\r\0\x0B\f" ),
				);
			}
		}

		$links = array();
		if ( preg_match_all( '/<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$href    = html_entity_decode( '' !== $row[1] ? $row[1] : ( $row[2] ?? '' ), ENT_QUOTES, 'UTF-8' );
				$links[] = array(
					'href' => trim( $href, " \t\n\r\0\x0B\f" ),
					'text' => trim( html_entity_decode( strip_tags( $row[3] ), ENT_QUOTES, 'UTF-8' ), " \t\n\r\0\x0B\f" ), // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure class, no WordPress functions.
				);
			}
		}

		return array(
			'headings' => $headings,
			'images'   => $images,
			'links'    => $links,
		);
	}

	/**
	 * Whether an href points at the site itself.
	 *
	 * @param string $href Link target.
	 * @param string $host Site host (e.g. "example.com").
	 * @return bool
	 */
	public static function is_internal( $href, $host ) {
		$href = trim( (string) $href, " \t\n\r\0\x0B\f" );
		if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript|data|sms):/i', $href ) ) {
			return false;
		}
		$norm = function ( $h ) {
			return preg_replace( '/^www\./i', '', strtolower( (string) $h ) );
		};
		if ( 0 === strpos( $href, '//' ) || preg_match( '#^https?://#i', $href ) ) {
			$parts = self::parse( 0 === strpos( $href, '//' ) ? 'https:' . $href : $href );
			return isset( $parts['host'] ) && $norm( $parts['host'] ) === $norm( $host );
		}
		return true; // Relative URL.
	}

	/**
	 * Normalised comparison key for a URL: lowercase path without slashes, or "p:ID" for ?p=ID URLs.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function path_key( $url ) {
		$parts = self::parse( trim( (string) $url, " \t\n\r\0\x0B\f" ) );
		if ( ! $parts ) {
			return '';
		}
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			foreach ( array( 'p', 'page_id' ) as $k ) {
				if ( isset( $q[ $k ] ) && ctype_digit( (string) $q[ $k ] ) ) {
					return 'p:' . $q[ $k ];
				}
			}
		}
		$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
		return strtolower( trim( $path, '/' ) );
	}

	/**
	 * URL parser (uses wp_parse_url inside WordPress).
	 *
	 * @param string $url URL.
	 * @return array|false
	 */
	private static function parse( $url ) {
		return function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
