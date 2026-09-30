<?php
/**
 * Featured image: AI generation with Pexels fallback, then skip.
 *
 * @package SCP
 */

namespace SCP\Generation;

use SCP\LLM\OpenAI_Client;
use SCP\Logger;
use SCP\Settings;

/**
 * Creates a media attachment suitable as a post thumbnail.
 */
class Featured_Image {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create an attachment for the article. Null when every source fails.
	 *
	 * @param string $keyword Target keyword (also used as alt text base).
	 * @param string $title   Article title.
	 * @return int|null Attachment ID.
	 */
	public function create( string $keyword, string $title ): ?int {
		$alt = trim( $keyword . ' — ' . $title, ' —' );

		// 1. AI generation (OpenAI images).
		$llm_settings = $this->settings->all()['llm'] ?? array();
		if ( '' !== $this->settings->get_key( 'openai' ) ) {
			try {
				$client = new OpenAI_Client( $this->settings->get_key( 'openai' ) );
				$prompt = sprintf(
					'Photorealistic, modern blog featured image for an article titled "%s" about "%s". Clean composition, natural lighting, no text, no watermark.',
					$title,
					$keyword
				);
				$bytes  = $client->generate_image(
					$prompt,
					'1536x1024',
					(string) ( $llm_settings['image_model'] ?? 'gpt-image-1' )
				);
				$id     = $this->sideload_bytes( $bytes, sanitize_title( $keyword ) . '.png', $alt );
				if ( $id ) {
					return $id;
				}
			} catch ( \Throwable $e ) {
				Logger::warning( 'AI image failed, trying Pexels: ' . $e->getMessage(), 'image' );
			}
		}

		// 2. Pexels stock photo fallback.
		$pexels = $this->settings->get_key( 'pexels' );
		if ( '' !== $pexels ) {
			try {
				$id = $this->from_pexels( $keyword, $pexels, $alt );
				if ( $id ) {
					return $id;
				}
			} catch ( \Throwable $e ) {
				Logger::warning( 'Pexels image failed: ' . $e->getMessage(), 'image' );
			}
		}

		// 3. Skip silently — the article is fine without a thumbnail.
		return null;
	}

	/**
	 * Fetch a stock photo from Pexels and sideload it.
	 *
	 * @param string $keyword Search query.
	 * @param string $api_key Pexels API key.
	 * @param string $alt     Alt text.
	 * @return int|null Attachment ID.
	 */
	private function from_pexels( string $keyword, string $api_key, string $alt ): ?int {
		$response = wp_remote_get(
			add_query_arg(
				array(
					'query'    => $keyword,
					'per_page' => 1,
					'orientation' => 'landscape',
				),
				'https://api.pexels.com/v1/search'
			),
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => $api_key ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$url     = $decoded['photos'][0]['src']['large'] ?? null;
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		$img = wp_remote_get( $url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $img ) || 200 !== (int) wp_remote_retrieve_response_code( $img ) ) {
			return null;
		}
		return $this->sideload_bytes(
			(string) wp_remote_retrieve_body( $img ),
			sanitize_title( $keyword ) . '.jpg',
			$alt
		);
	}

	/**
	 * Turn raw bytes into a media attachment.
	 *
	 * @param string $bytes    Image bytes.
	 * @param string $filename Target filename.
	 * @param string $alt      Alt text.
	 * @return int|null Attachment ID.
	 */
	private function sideload_bytes( string $bytes, string $filename, string $alt ): ?int {
		if ( '' === $bytes ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $bytes );

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			0
		);

		if ( is_wp_error( $attachment_id ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp );
			Logger::warning( 'Image sideload failed: ' . $attachment_id->get_error_message(), 'image' );
			return null;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		return (int) $attachment_id;
	}
}
