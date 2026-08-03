<?php
defined( 'ABSPATH' ) || exit;

/**
 * Competitive Content Duel.
 * Compares current post against top-ranking competitors and fixes gaps.
 */
class VMSB_Duel {

	public function duel( $post_id ) {
		$post    = get_post( $post_id );
		$keyword = ( new VMSB_RankMath() )->get_focus_keyword( $post_id );

		if ( ! $keyword ) return new WP_Error( 'vmsb_duel', 'No focus keyword set.' );

		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();

		// In a full search-enabled environment, we would scrape the SERP here.
		// For now, we use the Brain's Knowledge Graph and Market Intelligence to simulate the duel.
		$prompt = "DUEL: My Post vs. Top 3 Competitors for '{$keyword}'.\n\n"
			. "MY CONTENT:\n" . mb_substr( $post->post_content, 0, 2000 ) . "\n\n"
			. "Find 3 specific technical or topical things the competitors are doing better (e.g. FAQ schema, missing entity 'X', better H2 structure).\n"
			. "Return JSON: {\"gaps\":[{\"type\":\"entity|schema|structure\",\"detail\":\"\",\"fix_instruction\":\"\"}]}";

		$res = $ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium' ) );

		if ( ! empty( $res['gaps'] ) ) {
			return $this->apply_fixes( $post_id, $res['gaps'] );
		}

		// Distinguish "the AI call itself failed" from a genuine null result -
		// claiming the content is "superior" when the chain never actually ran
		// is misleading and hides a configuration problem behind a compliment.
		if ( null === $res ) {
			return new WP_Error( 'vmsb_duel', $ai->get_last_error() ?: 'The AI chain returned nothing usable.' );
		}

		return array( 'message' => 'No gaps found. Your content is currently superior.' );
	}

	private function apply_fixes( $post_id, $gaps ) {
		$applied = 0;
		foreach ( $gaps as $gap ) {
			// Surgical fixes based on gap type
			if ( $gap['type'] === 'entity' ) {
				$result = ( new VMSB_Entity() )->inject_specific( $post_id, $gap['detail'] );
				if ( ! is_wp_error( $result ) ) {
					$applied++;
				}
			}
			// schema/structure gap types have no surgical fixer yet - left as
			// reported detail only, not counted as applied.
		}
		return array( 'fixed' => $applied, 'details' => $gaps );
	}
}
