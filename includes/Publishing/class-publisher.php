<?php
/**
 * Post creation: draft / publish / scheduled (future).
 *
 * @package SCP
 */

namespace SCP\Publishing;

use SCP\Logger;
use SCP\SEO\SEO_Meta;

/**
 * Turns a generated article into a WordPress post.
 */
class Publisher {

	/**
	 * Create a post from a generated article.
	 *
	 * @param array $article  Article payload: title, slug, content, excerpt, meta, keyword, keyword_id.
	 * @param array $settings Plugin settings.
	 * @return int Post ID.
	 * @throws \RuntimeException When insert fails.
	 */
	public function create_post( array $article, array $settings ): int {
		$mode = $settings['publish']['mode'] ?? 'draft';

		// Content is self-generated (not user input) and intentionally keeps the
		// FAQ JSON-LD <script> tag, which wp_kses_post() would strip.
		$content = (string) ( $article['content'] ?? '' );
		if ( ! empty( $article['meta']['faq'] ) && is_array( $article['meta']['faq'] ) ) {
			$content .= "\n" . $this->faq_block( $article['meta']['faq'] );
			$content .= "\n" . $this->faq_schema( $article['meta']['faq'] );
		}
		// E-E-A-T author byline (§8.3) — only when the author has a bio.
		if ( ! empty( $settings['geo']['author_box'] ) ) {
			$content .= $this->author_box( (int) ( $settings['content']['author_id'] ?? 0 ) ?: get_current_user_id() );
		}

		$postarr = array(
			'post_title'   => sanitize_text_field( (string) ( $article['title'] ?? '' ) ),
			'post_name'    => sanitize_title( (string) ( $article['slug'] ?? $article['title'] ?? '' ) ),
			'post_content' => $content,
			'post_excerpt' => sanitize_textarea_field( (string) ( $article['meta']['excerpt'] ?? '' ) ),
			'post_status'  => $this->status_for( $mode ),
			'post_type'    => 'post',
			'post_author'  => (int) ( $settings['content']['author_id'] ?? 0 ) ?: get_current_user_id(),
		);

		if ( 'future' === $postarr['post_status'] ) {
			$slot                     = ( new Scheduler( $settings ) )->next_slot();
			$postarr['post_date']     = $slot['local'];
			$postarr['post_date_gmt'] = $slot['gmt'];
		}

		$category = (int) ( $settings['content']['default_category'] ?? 0 );
		if ( $category > 0 ) {
			$postarr['post_category'] = array( $category );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'wp_insert_post failed: ' . $post_id->get_error_message() );
		}

		if ( ! empty( $article['meta']['meta_title'] ) ) {
			update_post_meta( $post_id, '_scp_meta_title', sanitize_text_field( $article['meta']['meta_title'] ) );
		}
		if ( ! empty( $article['meta']['meta_description'] ) ) {
			update_post_meta( $post_id, '_scp_meta_description', sanitize_textarea_field( $article['meta']['meta_description'] ) );
		}
		if ( ! empty( $article['keyword'] ) ) {
			update_post_meta( $post_id, '_scp_keyword', sanitize_text_field( $article['keyword'] ) );
		}

		// Featured image from the pipeline's image step.
		if ( ! empty( $article['attachment_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $article['attachment_id'] );
		}

		// Fan meta out to Yoast / Rank Math when active.
		SEO_Meta::write( $post_id, $article );

		$this->log_publish( $post_id, (string) ( $article['keyword'] ?? '' ), (string) ( $postarr['post_date'] ?? current_time( 'mysql' ) ) );

		Logger::info(
			'Post created',
			'publisher',
			array(
				'post_id' => $post_id,
				'status'  => $postarr['post_status'],
				'keyword' => $article['keyword'] ?? '',
			)
		);

		return (int) $post_id;
	}

	/**
	 * Map publish mode to post status.
	 *
	 * @param string $mode draft|publish|schedule.
	 * @return string
	 */
	private function status_for( string $mode ): string {
		switch ( $mode ) {
			case 'publish':
				return 'publish';
			case 'schedule':
				return 'future';
			default:
				return 'draft';
		}
	}

	/**
	 * Record the publish event.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $keyword Keyword.
	 * @param string $when    Local datetime (actual or scheduled).
	 * @return void
	 */
	private function log_publish( int $post_id, string $keyword, string $when ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'scp_publish_log',
			array(
				'post_id'      => $post_id,
				'keyword'      => $keyword,
				'published_at' => $when,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * E-E-A-T author box appended to the article (§8.3). Empty when the
	 * author has no biographical description set.
	 *
	 * @param int $author_id WP user id.
	 * @return string HTML or empty string.
	 */
	private function author_box( int $author_id ): string {
		$user = get_userdata( $author_id );
		if ( ! $user ) {
			return '';
		}
		$description = trim( (string) get_user_meta( $author_id, 'description', true ) );
		if ( '' === $description ) {
			return '';
		}
		return "\n" . sprintf(
			'<div class="scp-author-box"><p><strong>%s</strong> — %s</p></div>',
			esc_html( $user->display_name ),
			esc_html( $description )
		);
	}

	/**
	 * Visible FAQ section appended to the article body.
	 *
	 * @param array $faq FAQ pairs.
	 * @return string HTML.
	 */
	private function faq_block( array $faq ): string {
		$items = array();
		foreach ( $faq as $pair ) {
			$q = trim( (string) ( $pair['question'] ?? '' ) );
			$a = trim( (string) ( $pair['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$items[] = '<h3>' . esc_html( $q ) . '</h3>' . "\n" . wpautop( esc_html( $a ) );
		}
		if ( empty( $items ) ) {
			return '';
		}
		return '<h2>Frequently Asked Questions</h2>' . "\n" . implode( "\n", $items );
	}

	/**
	 * FAQ JSON-LD schema script.
	 *
	 * @param array $faq FAQ pairs.
	 * @return string Script tag.
	 */
	private function faq_schema( array $faq ): string {
		$entities = array();
		foreach ( $faq as $pair ) {
			$q = trim( (string) ( $pair['question'] ?? '' ) );
			$a = trim( (string) ( $pair['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $a,
				),
			);
		}
		if ( empty( $entities ) ) {
			return '';
		}
		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
		return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>';
	}
}
