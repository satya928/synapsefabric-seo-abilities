<?php
/**
 * Internal-link suggestions: related-post ranking (TF-IDF cosine) and anchor-text matching.
 *
 * Pure PHP (no WordPress calls) so it can be unit-tested without a site.
 *
 * @package SynapseFabric_SEO_Abilities
 */

defined( 'ABSPATH' ) || defined( 'SFSA_TESTING' ) || exit;

/**
 * Link suggester.
 */
class SFSA_Linker {

	/**
	 * Common English words ignored when ranking and building anchors.
	 *
	 * @var string[]
	 */
	private static $stop = array( 'a', 'about', 'after', 'all', 'also', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'because', 'been', 'but', 'by', 'can', 'do', 'does', 'for', 'from', 'get', 'had', 'has', 'have', 'how', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'more', 'most', 'no', 'not', 'of', 'on', 'one', 'or', 'other', 'our', 'out', 'so', 'some', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'to', 'up', 'use', 'was', 'we', 'what', 'when', 'which', 'who', 'will', 'with', 'you', 'your' );

	/**
	 * Whether a word is a stopword.
	 *
	 * @param string $word Lowercase word.
	 * @return bool
	 */
	public static function is_stop( $word ) {
		return in_array( $word, self::$stop, true );
	}

	/**
	 * Weighted term-frequency vector (title counts 3x).
	 *
	 * @param string $title   Title.
	 * @param string $content Content HTML.
	 * @return array<string,float>
	 */
	private static function counts( $title, $content ) {
		$counts = array();
		foreach ( array( array( $title, 3 ), array( $content, 1 ) ) as $pair ) {
			foreach ( SFSA_Similarity::tokens( $pair[0] ) as $w ) {
				if ( strlen( $w ) > 2 && ! self::is_stop( $w ) ) {
					$counts[ $w ] = ( $counts[ $w ] ?? 0 ) + $pair[1];
				}
			}
		}
		return $counts;
	}

	/**
	 * Rank other documents by topical similarity to the target.
	 *
	 * @param array<int,array{title:string,content:string}> $docs      id => doc (must include the target).
	 * @param int                                           $target_id Target document id.
	 * @param int                                           $limit     Max results.
	 * @param float                                         $min_score Minimum cosine score.
	 * @return array<int,float> id => score, best first.
	 */
	public static function related( array $docs, $target_id, $limit = 10, $min_score = 0.08 ) {
		if ( ! isset( $docs[ $target_id ] ) ) {
			return array();
		}
		$tf = array();
		$df = array();
		foreach ( $docs as $id => $doc ) {
			$tf[ $id ] = self::counts( $doc['title'], $doc['content'] );
			foreach ( array_keys( $tf[ $id ] ) as $w ) {
				$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
			}
		}
		$n      = count( $docs );
		$vector = function ( $counts ) use ( $df, $n ) {
			$v = array();
			foreach ( $counts as $w => $c ) {
				$v[ $w ] = ( 1 + log( $c ) ) * ( log( ( $n + 1 ) / ( $df[ $w ] + 1 ) ) + 1 );
			}
			return $v;
		};
		$norm   = function ( $v ) {
			return sqrt(
				array_sum(
					array_map(
						function ( $x ) {
							return $x * $x;
						},
						$v
					)
				)
			);
		};

		$t      = $vector( $tf[ $target_id ] );
		$t_norm = $norm( $t );
		$scores = array();
		if ( $t_norm > 0 ) {
			foreach ( $tf as $id => $counts ) {
				if ( $id === $target_id ) {
					continue;
				}
				$v   = $vector( $counts );
				$dot = 0.0;
				foreach ( $t as $w => $x ) {
					if ( isset( $v[ $w ] ) ) {
						$dot += $x * $v[ $w ];
					}
				}
				$v_norm = $norm( $v );
				$score  = $v_norm > 0 ? $dot / ( $t_norm * $v_norm ) : 0.0;
				if ( $score >= $min_score ) {
					$scores[ $id ] = round( $score, 3 );
				}
			}
		}
		arsort( $scores );
		return array_slice( $scores, 0, $limit, true );
	}

	/**
	 * Candidate anchor phrases for a target, longest and most specific first.
	 *
	 * @param array{title:string,keyword?:string} $target Target post.
	 * @return string[]
	 */
	public static function candidate_phrases( array $target ) {
		$phrases = array();
		if ( ! empty( $target['keyword'] ) ) {
			$kw = implode( ' ', SFSA_Similarity::tokens( $target['keyword'] ) );
			if ( strlen( $kw ) >= 6 ) {
				$phrases[] = $kw;
			}
		}
		$words = SFSA_Similarity::tokens( $target['title'] );
		$count = count( $words );
		for ( $len = min( $count, 5 ); $len >= 2; $len-- ) {
			for ( $i = 0; $i <= $count - $len; $i++ ) {
				$slice = array_slice( $words, $i, $len );
				if ( self::is_stop( $slice[0] ) || self::is_stop( $slice[ $len - 1 ] ) ) {
					continue;
				}
				$phrase = implode( ' ', $slice );
				if ( strlen( $phrase ) >= 8 ) {
					$phrases[] = $phrase;
				}
			}
		}
		return array_slice( array_values( array_unique( $phrases ) ), 0, 40 );
	}

	/**
	 * Find text already present in a document that could carry a link to the target.
	 *
	 * Text inside existing links is ignored.
	 *
	 * @param string                              $html   Source document HTML.
	 * @param array{title:string,keyword?:string} $target Target post.
	 * @return array{anchor:string,context:string}|null
	 */
	public static function find_anchor( $html, array $target ) {
		$html = preg_replace( '/<!--.*?-->/s', ' ', (string) $html );
		$html = preg_replace( '/<a\b[^>]*>.*?<\/a>/is', ' ', $html );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure class, no WordPress functions.
		$text = html_entity_decode( strip_tags( preg_replace( '/<\/(p|h[1-6]|li|div)>/i', '$0 ', $html ) ), ENT_QUOTES, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ), " \t\n\r\0\x0B\f" );
		if ( '' === $text ) {
			return null;
		}
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text );

		foreach ( self::candidate_phrases( $target ) as $phrase ) {
			$re = '/(?<![\p{L}\p{N}])' . str_replace( '\ ', '\s+', preg_quote( $phrase, '/' ) ) . '(?![\p{L}\p{N}])/iu';
			foreach ( $sentences as $sentence ) {
				if ( preg_match( $re, $sentence, $m ) ) {
					return array(
						'anchor'  => $m[0],
						'context' => mb_substr( $sentence, 0, 240 ),
					);
				}
			}
		}
		return null;
	}
}
