<?php
/**
 * Safe_AI_Change class.
 *
 * @package Safe_AI_Change
 */

defined( 'ABSPATH' ) || exit;

/**
 * Changes one product short description from a written request, with a preview, store checks and an automatic rollback.
 */
final class Safe_AI_Change {

	const MODEL           = 'claude-haiku-5-5';
	const API_URL         = 'https://api.anthropic.com/v1/messages';
	const MAX_REQUEST     = 500;
	const MAX_TEXT        = 400;
	const TTL             = 600;
	const LOG_SOURCE      = 'safe-ai-change';
	const SNAPSHOT_OPTION = 'sac_last_snapshot';

	/**
	 * Main plugin file, needed to declare WooCommerce feature compatibility.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * True once the current Apply reached a verdict, so the shutdown guard stays idle.
	 *
	 * @var bool
	 */
	private $settled = false;

	/**
	 * Stores the main plugin file.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Registers the hooks.
	 */
	public function register() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ), 99 );
		add_action( 'admin_post_sac_propose', array( $this, 'handle_propose' ) );
		add_action( 'admin_post_sac_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_sac_cancel', array( $this, 'handle_cancel' ) );
	}

	/**
	 * Declares HPOS compatibility. The plugin never reads or writes orders.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->plugin_file, true );
		}
	}

	/**
	 * Adds the page under the WooCommerce menu.
	 */
	public function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Safe AI Change', 'safe-ai-change' ),
			__( 'Safe AI Change', 'safe-ai-change' ),
			'manage_woocommerce', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			'safe-ai-change',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handles a new request: asks the AI, validates the answer and stores a preview.
	 */
	public function handle_propose() {
		$this->require_capability( 'sac_propose' );
		if ( ! isset( $_POST['sac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sac_nonce'] ) ), 'sac_propose' ) ) {
			$this->deny_nonce( 'sac_propose' );
		}

		$user_id = get_current_user_id();
		$request = isset( $_POST['sac_request'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sac_request'] ) ) : '';

		// A new request replaces any preview still waiting.
		delete_transient( 'sac_proposal_' . $user_id );
		$this->log(
			'info',
			'Request received.',
			array(
				'user'    => $user_id,
				'request' => $request,
			)
		);

		if ( '' === $request ) {
			$this->finish( 'REFUSED', __( 'The request is empty.', 'safe-ai-change' ) );
		}
		if ( mb_strlen( $request ) > self::MAX_REQUEST ) {
			/* translators: %d: maximum number of characters. */
			$this->finish( 'REFUSED', sprintf( __( 'The request is longer than %d characters.', 'safe-ai-change' ), self::MAX_REQUEST ) );
		}

		$tool_use = $this->ask_ai( $request );
		if ( is_wp_error( $tool_use ) ) {
			$this->finish( 'REFUSED', $tool_use->get_error_message() );
		}

		$proposal = $this->validate_proposal( $request, $tool_use );
		if ( is_wp_error( $proposal ) ) {
			$this->finish( 'REFUSED', $proposal->get_error_message() );
		}

		$proposal['token']   = wp_generate_password( 32, false );
		$proposal['request'] = $request;
		$proposal['created'] = time();
		set_transient( 'sac_proposal_' . $user_id, $proposal, self::TTL );
		$this->log(
			'info',
			'Preview stored for 10 minutes.',
			array(
				'user'       => $user_id,
				'product_id' => $proposal['product_id'],
				'before'     => $proposal['before'],
				'after'      => $proposal['after'],
			)
		);

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Handles Apply: checks the token, applies the change, checks the store and rolls back on failure.
	 */
	public function handle_apply() {
		$this->require_capability( 'sac_apply' );
		if ( ! isset( $_POST['sac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sac_nonce'] ) ), 'sac_apply' ) ) {
			$this->deny_nonce( 'sac_apply' );
		}

		$token    = isset( $_POST['sac_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sac_token'] ) ) : '';
		$proposal = $this->take_proposal( $token );
		if ( is_wp_error( $proposal ) ) {
			$this->finish( 'REFUSED', $proposal->get_error_message() );
		}

		// Up to three rounds of loopback checks; a time limit must not stop the request between the save and the rollback.
		wc_set_time_limit( 0 );
		$this->log(
			'info',
			'Apply started.',
			array(
				'user'       => get_current_user_id(),
				'product_id' => $proposal['product_id'],
			)
		);

		$outcome = $this->apply_proposal( $proposal );
		$this->finish( $outcome['verdict'], $outcome['reason'], $outcome['checks'] );
	}

	/**
	 * Handles Cancel: uses up the token and drops the preview.
	 */
	public function handle_cancel() {
		$this->require_capability( 'sac_cancel' );
		if ( ! isset( $_POST['sac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sac_nonce'] ) ), 'sac_cancel' ) ) {
			$this->deny_nonce( 'sac_cancel' );
		}

		$token    = isset( $_POST['sac_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sac_token'] ) ) : '';
		$proposal = $this->take_proposal( $token );
		if ( is_wp_error( $proposal ) ) {
			$this->finish( 'REFUSED', $proposal->get_error_message() );
		}

		$this->log(
			'info',
			'Preview cancelled.',
			array(
				'user'       => get_current_user_id(),
				'product_id' => $proposal['product_id'],
			)
		);
		$this->finish( '', __( 'The preview was cancelled. Nothing was changed.', 'safe-ai-change' ) );
	}

	/**
	 * Treats the AI tool call as untrusted input and turns it into a proposal, or refuses it.
	 *
	 * @param string $request  Request written by the manager.
	 * @param array  $tool_use The tool_use block from the AI.
	 * @return array|WP_Error Proposal with product_id, product_name, before and after.
	 */
	public function validate_proposal( $request, $tool_use ) {
		$name  = isset( $tool_use['name'] ) && is_string( $tool_use['name'] ) ? $tool_use['name'] : '';
		$input = isset( $tool_use['input'] ) && is_array( $tool_use['input'] ) ? $tool_use['input'] : null;

		if ( null === $input || ! in_array( $name, array( 'update_short_description', 'refuse' ), true ) ) {
			return $this->refusal( __( 'The AI answered with an unknown tool.', 'safe-ai-change' ) );
		}

		if ( 'refuse' === $name ) {
			$reason = array( 'reason' ) === array_keys( $input ) && is_string( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : '';
			if ( '' === $reason ) {
				$reason = __( 'no reason given', 'safe-ai-change' );
			}
			/* translators: %s: reason given by the AI. */
			return $this->refusal( sprintf( __( 'The AI refused: %s', 'safe-ai-change' ), $reason ) );
		}

		$keys = array_keys( $input );
		sort( $keys );
		if ( array( 'new_text', 'product' ) !== $keys || ! is_string( $input['product'] ) || ! is_string( $input['new_text'] ) ) {
			return $this->refusal( __( 'The AI answer does not have the expected fields.', 'safe-ai-change' ) );
		}

		$identifier = $input['product'];
		$text       = $input['new_text'];

		$text_at = '' === $text ? false : strpos( $request, $text );
		if ( false === $text_at ) {
			return $this->refusal( __( 'The new text is not written word for word in the request.', 'safe-ai-change' ) );
		}
		// The product must be named outside the new text, so a name quoted inside the text never picks the product.
		$rest = substr( $request, 0, $text_at ) . "\n" . substr( $request, $text_at + strlen( $text ) );
		if ( '' === $identifier || false === strpos( $rest, $identifier ) ) {
			return $this->refusal( __( 'The product named by the AI is not written in the request, outside the new text.', 'safe-ai-change' ) );
		}
		if ( sanitize_text_field( $text ) !== $text ) {
			return $this->refusal( __( 'Sanitizing would change the new text (tags, line breaks, extra spaces or encoded characters), so it was refused.', 'safe-ai-change' ) );
		}
		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			/* translators: %d: maximum number of characters. */
			return $this->refusal( sprintf( __( 'The new text is longer than %d characters.', 'safe-ai-change' ), self::MAX_TEXT ) );
		}

		$ids = $this->find_products( $identifier );
		if ( 0 === count( $ids ) ) {
			return $this->refusal( __( 'No published product has this exact name or SKU.', 'safe-ai-change' ) );
		}
		if ( 1 !== count( $ids ) ) {
			/* translators: %d: number of products. */
			return $this->refusal( sprintf( __( '%d published products match this name or SKU, so the request is ambiguous.', 'safe-ai-change' ), count( $ids ) ) );
		}

		$product_id = $ids[0];
		$longer     = $this->find_longer_match( $identifier, $product_id, $rest );
		if ( '' !== $longer ) {
			/* translators: %s: product name or SKU. */
			return $this->refusal( sprintf( __( 'The request also names "%s", a longer product name or SKU, so the request is ambiguous.', 'safe-ai-change' ), $longer ) );
		}

		$product = $this->fresh_product( $product_id );
		if ( ! $product ) {
			return $this->refusal( __( 'The product could not be loaded.', 'safe-ai-change' ) );
		}

		$before = (string) $product->get_short_description( 'edit' );
		if ( $before === $text ) {
			return $this->refusal( __( 'This text is already the current short description.', 'safe-ai-change' ) );
		}

		$this->log(
			'info',
			'Proposal passed every validation rule.',
			array(
				'product_id' => $product_id,
				'identifier' => $identifier,
			)
		);

		return array(
			'product_id'   => $product_id,
			'product_name' => $product->get_name(),
			'before'       => $before,
			'after'        => $text,
		);
	}

	/**
	 * Runs the guard when WordPress shows its critical error screen during an Apply.
	 *
	 * @param string $message Error screen message, returned unchanged.
	 * @return string
	 */
	public function guard_before_error_screen( $message ) {
		$snapshot = get_option( self::SNAPSHOT_OPTION );
		if ( is_array( $snapshot ) && isset( $snapshot['product_id'] ) ) {
			$this->shutdown_guard( (int) $snapshot['product_id'], get_current_user_id() );
		}
		return $message;
	}

	/**
	 * Runs at shutdown after a change was saved. When the request stopped before a verdict (fatal error, exit),
	 * it writes the snapshot text back without hooks and records FAILED, since nothing could be checked.
	 *
	 * @param int $product_id Product ID.
	 * @param int $user_id    Manager who clicked Apply.
	 */
	public function shutdown_guard( $product_id, $user_id ) {
		global $wpdb;

		if ( $this->settled ) {
			return;
		}
		$snapshot = get_option( self::SNAPSHOT_OPTION );
		if ( ! is_array( $snapshot ) || (int) $snapshot['product_id'] !== (int) $product_id ) {
			return;
		}
		// Runs once, even when both the filter and the shutdown function fire.
		$this->settled = true;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emergency restore without hooks, caches cleared right after.
		$wpdb->update( $wpdb->posts, array( 'post_excerpt' => (string) $snapshot['post']['post_excerpt'] ), array( 'ID' => $product_id ), array( '%s' ), array( '%d' ) );
		clean_post_cache( $product_id );
		$last_error = error_get_last();
		$this->log(
			'critical',
			'Apply stopped before a verdict. The snapshot text was written back directly.',
			array(
				'product_id' => $product_id,
				'php_error'  => is_array( $last_error ) ? $last_error['message'] : '',
			)
		);
		set_transient(
			'sac_result_' . $user_id,
			array(
				'verdict' => 'FAILED',
				'reason'  => __( 'The request stopped before the checks finished. The previous text was written back directly, but nothing could be checked. Check the product and the WooCommerce logs now.', 'safe-ai-change' ),
				'checks'  => array(),
			),
			self::TTL
		);
	}

	/**
	 * Renders the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			wp_die( esc_html__( 'You are not allowed to do this.', 'safe-ai-change' ), '', array( 'response' => 403 ) );
		}

		$user_id  = get_current_user_id();
		$result   = get_transient( 'sac_result_' . $user_id );
		$proposal = get_transient( 'sac_proposal_' . $user_id );
		if ( false !== $result ) {
			delete_transient( 'sac_result_' . $user_id );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Safe AI Change', 'safe-ai-change' ); ?></h1>
			<?php
			if ( is_array( $result ) ) {
				$this->render_result( $result );
			}
			if ( is_array( $proposal ) && ! empty( $proposal['token'] ) ) {
				$this->render_preview( $proposal );
			}
			$this->render_request_form();
			?>
		</div>
		<?php
	}

	/**
	 * Returns the admin page URL.
	 *
	 * @return string
	 */
	private function page_url() {
		return admin_url( 'admin.php?page=safe-ai-change' );
	}

	/**
	 * Writes one line to the WooCommerce log. Never pass the API key or the preview token here.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $data    Extra data, logged as JSON.
	 */
	private function log( $level, $message, array $data = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( $data ) {
			$message .= ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}

	/**
	 * Stops the request when the user lacks the capability.
	 *
	 * @param string $action Handler name, for the log.
	 */
	private function require_capability( $action ) {
		if ( current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		$this->log(
			'warning',
			'Blocked: missing capability.',
			array(
				'action' => $action,
				'user'   => get_current_user_id(),
			)
		);
		wp_die( esc_html__( 'You are not allowed to do this.', 'safe-ai-change' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Stops the request after a failed nonce check.
	 *
	 * @param string $action Handler name, for the log.
	 */
	private function deny_nonce( $action ) {
		$this->log(
			'warning',
			'Blocked: invalid nonce.',
			array(
				'action' => $action,
				'user'   => get_current_user_id(),
			)
		);
		wp_die( esc_html__( 'This form has expired. Go back and try again.', 'safe-ai-change' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Stores the verdict for this user, logs it and goes back to the page.
	 *
	 * @param string $verdict APPLIED, REFUSED, ROLLED BACK, FAILED, or an empty string for a plain notice.
	 * @param string $reason  Explanation shown to the manager.
	 * @param array  $checks  Check results.
	 */
	private function finish( $verdict, $reason, array $checks = array() ) {
		$levels = array(
			'APPLIED'     => 'info',
			'REFUSED'     => 'notice',
			'ROLLED BACK' => 'warning',
			'FAILED'      => 'error',
		);
		$this->log(
			isset( $levels[ $verdict ] ) ? $levels[ $verdict ] : 'info',
			'' === $verdict ? 'Done.' : 'Verdict: ' . $verdict . '.',
			array(
				'user'   => get_current_user_id(),
				'reason' => $reason,
			)
		);
		set_transient(
			'sac_result_' . get_current_user_id(),
			array(
				'verdict' => $verdict,
				'reason'  => $reason,
				'checks'  => $checks,
			),
			self::TTL
		);
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Returns the preview of the current user and makes its token unusable for any later request.
	 *
	 * @param string $token Token sent with the form.
	 * @return array|WP_Error
	 */
	private function take_proposal( $token ) {
		$key      = 'sac_proposal_' . get_current_user_id();
		$proposal = get_transient( $key );

		if ( ! is_array( $proposal ) || empty( $proposal['token'] ) ) {
			$this->log( 'notice', 'Token refused: no preview waiting.' );
			return new WP_Error( 'sac_expired', __( 'No preview is waiting, or it expired after 10 minutes. Nothing was changed.', 'safe-ai-change' ) );
		}
		if ( '' === $token || ! hash_equals( (string) $proposal['token'], $token ) ) {
			$this->log( 'notice', 'Token refused: it does not match the preview.' );
			return new WP_Error( 'sac_token', __( 'This preview token is not valid. Nothing was changed.', 'safe-ai-change' ) );
		}
		// Only one request can delete the row, so a token never works twice, even for two clicks at once.
		if ( ! delete_transient( $key ) ) {
			$this->log( 'notice', 'Token refused: already used.' );
			return new WP_Error( 'sac_used', __( 'This preview was already used. Nothing was changed.', 'safe-ai-change' ) );
		}

		$this->log( 'info', 'Token accepted and used up.' );
		return $proposal;
	}

	/**
	 * Returns the system prompt. It describes the job only; no store data goes to the AI.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return 'You turn one request from a WooCommerce shop manager into at most one tool call. You never see store data.'
			. "\n\n"
			. 'Call update_short_description only when the request asks to replace the short description of one product and the request itself contains both the exact product name or SKU and the exact new text. Copy both values character for character from the request, without the quotes around them. Do not fix, shorten, translate or add anything.'
			. "\n\n"
			. 'In every other case, for example a request to change something else, a request about several products, or a request with missing information, call refuse with a short reason.';
	}

	/**
	 * Returns the two strict tools offered to the AI.
	 *
	 * @return array
	 */
	private function tools() {
		return array(
			array(
				'name'         => 'update_short_description',
				'description'  => 'Replace the short description of one product. Use it only when the request contains the exact product name or SKU and the exact new text.',
				'strict'       => true,
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'product'  => array(
							'type'        => 'string',
							'description' => 'The exact product name or SKU, copied from the request.',
						),
						'new_text' => array(
							'type'        => 'string',
							'description' => 'The exact new short description, copied from the request.',
						),
					),
					'required'             => array( 'product', 'new_text' ),
					'additionalProperties' => false,
				),
			),
			array(
				'name'         => 'refuse',
				'description'  => 'Decline the request and say why.',
				'strict'       => true,
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'reason' => array(
							'type'        => 'string',
							'description' => 'A short reason for the shop manager.',
						),
					),
					'required'             => array( 'reason' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Sends the request text, and nothing else, to the AI and returns its single tool call.
	 *
	 * @param string $request Request written by the manager.
	 * @return array|WP_Error The tool_use block.
	 */
	private function ask_ai( $request ) {
		if ( ! defined( 'SAC_ANTHROPIC_API_KEY' ) || '' === (string) SAC_ANTHROPIC_API_KEY ) {
			$this->log( 'error', 'The API key constant is missing.' );
			return new WP_Error( 'sac_no_key', __( 'The constant SAC_ANTHROPIC_API_KEY is not set in wp-config.php.', 'safe-ai-change' ) );
		}

		$body = array(
			'model'         => self::MODEL,
			'max_tokens'    => 4096,
			'system'        => $this->system_prompt(),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $request,
				),
			),
			'tools'         => $this->tools(),
			'tool_choice'   => array(
				'type'                      => 'auto',
				'disable_parallel_tool_use' => true,
			),
			'output_config' => array( 'effort' => 'low' ),
		);

		$this->log( 'info', 'Sending the request text to the AI.', array( 'model' => self::MODEL ) );
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'x-api-key'         => SAC_ANTHROPIC_API_KEY,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'The AI request failed.', array( 'error' => $response->get_error_message() ) );
			return new WP_Error( 'sac_ai_unreachable', __( 'The AI service could not be reached. Nothing was changed.', 'safe-ai-change' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			$type = is_array( $data ) && isset( $data['error']['type'] ) ? sanitize_key( $data['error']['type'] ) : 'unknown';
			$this->log(
				'error',
				'The AI answered with an error.',
				array(
					'status' => $code,
					'type'   => $type,
				)
			);
			/* translators: 1: HTTP status code, 2: error type. */
			return new WP_Error( 'sac_ai_http', sprintf( __( 'The AI service answered with HTTP %1$d (%2$s). Nothing was changed.', 'safe-ai-change' ), $code, $type ) );
		}

		$stop      = isset( $data['stop_reason'] ) ? (string) $data['stop_reason'] : '';
		$tool_uses = array();
		foreach ( isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array() as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
				$tool_uses[] = $block;
			}
		}
		$this->log(
			'info',
			'The AI answered.',
			array(
				'stop_reason'   => $stop,
				'tool_calls'    => count( $tool_uses ),
				'tool'          => isset( $tool_uses[0]['name'] ) ? (string) $tool_uses[0]['name'] : '',
				'input'         => isset( $tool_uses[0]['input'] ) ? $tool_uses[0]['input'] : null,
				'input_tokens'  => isset( $data['usage']['input_tokens'] ) ? (int) $data['usage']['input_tokens'] : 0,
				'output_tokens' => isset( $data['usage']['output_tokens'] ) ? (int) $data['usage']['output_tokens'] : 0,
			)
		);

		if ( 'refusal' === $stop ) {
			return new WP_Error( 'sac_ai_refusal', __( 'The AI declined this request. Nothing was changed.', 'safe-ai-change' ) );
		}
		if ( 'tool_use' !== $stop || 1 !== count( $tool_uses ) ) {
			/* translators: %s: stop reason returned by the AI. */
			return new WP_Error( 'sac_ai_no_tool', sprintf( __( 'The AI did not make exactly one tool call (stop reason: %s). Nothing was changed.', 'safe-ai-change' ), $stop ) );
		}

		return $tool_uses[0];
	}

	/**
	 * Builds a refusal and logs it.
	 *
	 * @param string $message Reason shown to the manager.
	 * @return WP_Error
	 */
	private function refusal( $message ) {
		$this->log( 'notice', 'Proposal refused: ' . $message );
		return new WP_Error( 'sac_refused', $message );
	}

	/**
	 * Returns the IDs of published products whose exact name or exact SKU equals the identifier.
	 *
	 * @param string $identifier Name or SKU.
	 * @return int[]
	 */
	private function find_products( $identifier ) {
		global $wpdb;

		// Titles can be stored raw, with kses entities (&amp; only) or fully escaped, so every form is fetched, then compared exactly.
		$forms = $this->title_forms( $identifier );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact match on fresh data.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_title IN ( %s, %s, %s )",
				$forms[0],
				$forms[1],
				$forms[2]
			)
		);
		$ids  = array();
		foreach ( $rows as $row ) {
			if ( html_entity_decode( $row->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === $identifier ) {
				$ids[] = (int) $row->ID;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact match on fresh data.
		$by_sku = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_key = '_sku' AND BINARY m.meta_value = %s",
				$identifier
			)
		);

		return array_values( array_unique( array_merge( $ids, array_map( 'intval', $by_sku ) ) ) );
	}

	/**
	 * Returns the forms a title can take in the database: raw, with kses entities, and fully escaped.
	 *
	 * @param string $identifier Name.
	 * @return string[] Three forms.
	 */
	private function title_forms( $identifier ) {
		return array( $identifier, wp_kses_normalize_entities( $identifier ), esc_html( $identifier ) );
	}

	/**
	 * Returns another published product or variation name or SKU that contains the identifier, is longer, and is written in the request.
	 *
	 * @param string $identifier Name or SKU returned by the AI.
	 * @param int    $product_id Matched product.
	 * @param string $request    Request without the new text.
	 * @return string Empty when there is none.
	 */
	private function find_longer_match( $identifier, $product_id, $request ) {
		global $wpdb;

		$forms = $this->title_forms( $identifier );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh data.
		$titles = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_title FROM {$wpdb->posts} WHERE post_type IN ( 'product', 'product_variation' ) AND post_status = 'publish' AND ID <> %d AND ( post_title LIKE %s OR post_title LIKE %s OR post_title LIKE %s )",
				$product_id,
				'%' . $wpdb->esc_like( $forms[0] ) . '%',
				'%' . $wpdb->esc_like( $forms[1] ) . '%',
				'%' . $wpdb->esc_like( $forms[2] ) . '%'
			)
		);
		$skus   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type IN ( 'product', 'product_variation' ) AND p.post_status = 'publish' AND p.ID <> %d AND m.meta_key = '_sku' AND m.meta_value LIKE %s",
				$product_id,
				'%' . $wpdb->esc_like( $identifier ) . '%'
			)
		);
		// phpcs:enable

		$candidates = array();
		foreach ( $titles as $title ) {
			$candidates[] = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		foreach ( array_merge( $candidates, $skus ) as $candidate ) {
			$candidate = (string) $candidate;
			if ( mb_strlen( $candidate ) > mb_strlen( $identifier ) && false !== strpos( $candidate, $identifier ) && false !== strpos( $request, $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Loads a product after clearing its caches.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|null
	 */
	private function fresh_product( $product_id ) {
		clean_post_cache( $product_id );
		$product = wc_get_product( $product_id );
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Runs the whole Apply sequence and returns the verdict.
	 *
	 * @param array $proposal Preview data.
	 * @return array Verdict, reason and checks.
	 */
	private function apply_proposal( array $proposal ) {
		$product_id = (int) $proposal['product_id'];
		$product    = $this->fresh_product( $product_id );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return $this->outcome( 'REFUSED', __( 'The product is no longer published. Nothing was changed.', 'safe-ai-change' ) );
		}
		if ( (string) $product->get_short_description( 'edit' ) !== $proposal['before'] ) {
			$this->log( 'notice', 'The short description changed since the preview.', array( 'product_id' => $product_id ) );
			return $this->outcome( 'REFUSED', __( 'The short description changed since the preview. Nothing was changed.', 'safe-ai-change' ) );
		}

		try {
			$checks = $this->run_checks( $product_id, __( 'Before change', 'safe-ai-change' ) );
		} catch ( Throwable $e ) {
			$checks = array( $this->check( __( 'Before change', 'safe-ai-change' ), __( 'Store checks', 'safe-ai-change' ), false, $this->throwable_detail( $e ) ) );
		}
		if ( $this->has_failure( $checks ) ) {
			return $this->outcome( 'REFUSED', __( 'The store checks failed before any change, so nothing was changed.', 'safe-ai-change' ), $checks );
		}

		// The baseline checks take time, so compare again right before the change.
		$snapshot = $this->take_snapshot( $product_id );
		if ( ! isset( $snapshot['post']['post_excerpt'] ) || (string) $snapshot['post']['post_excerpt'] !== $proposal['before'] ) {
			$this->log( 'notice', 'The short description changed during the checks before the change.', array( 'product_id' => $product_id ) );
			return $this->outcome( 'REFUSED', __( 'The short description changed since the preview. Nothing was changed.', 'safe-ai-change' ), $checks );
		}
		update_option( self::SNAPSHOT_OPTION, $snapshot, false );
		// The WordPress fatal error handler exits in its own shutdown function, before later ones run, so the guard also runs from its message filter.
		add_filter( 'wp_php_error_message', array( $this, 'guard_before_error_screen' ) );
		register_shutdown_function( array( $this, 'shutdown_guard' ), $product_id, get_current_user_id() );
		$this->log(
			'info',
			'Snapshot saved.',
			array(
				'product_id' => $product_id,
				'before'     => $snapshot['post']['post_excerpt'],
			)
		);

		$after_phase = __( 'After change', 'safe-ai-change' );
		$error       = $this->save_short_description( $product_id, $proposal['after'] );
		if ( '' !== $error ) {
			$checks[] = $this->check( $after_phase, __( 'Save the short description', 'safe-ai-change' ), false, $error );
			return $this->settle_apply( $this->rollback( $product_id, $snapshot, $checks, __( 'Saving the product failed.', 'safe-ai-change' ) ) );
		}
		$checks[] = $this->check( $after_phase, __( 'Save the short description', 'safe-ai-change' ), true, __( 'Saved with the WooCommerce product object.', 'safe-ai-change' ) );

		try {
			$after_checks = $this->run_checks( $product_id, $after_phase, $snapshot, $proposal['after'] );
		} catch ( Throwable $e ) {
			$after_checks = array( $this->check( $after_phase, __( 'Store checks', 'safe-ai-change' ), false, $this->throwable_detail( $e ) ) );
		}
		$checks = array_merge( $checks, $after_checks );

		if ( $this->has_failure( $after_checks ) ) {
			return $this->settle_apply( $this->rollback( $product_id, $snapshot, $checks, __( 'A check failed after the change.', 'safe-ai-change' ) ) );
		}

		return $this->settle_apply( $this->outcome( 'APPLIED', __( 'The short description was changed and every check passed.', 'safe-ai-change' ), $checks ) );
	}

	/**
	 * Marks the Apply as finished for the shutdown guard. The snapshot option is kept only after FAILED, for manual recovery.
	 *
	 * @param array $outcome Verdict, reason and checks.
	 * @return array The same outcome.
	 */
	private function settle_apply( array $outcome ) {
		$this->settled = true;
		if ( 'FAILED' !== $outcome['verdict'] ) {
			delete_option( self::SNAPSHOT_OPTION );
		}
		return $outcome;
	}

	/**
	 * Reads the stored short description straight from the database.
	 *
	 * @param int $product_id Product ID.
	 * @return string|null
	 */
	private function stored_excerpt( $product_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass every cache.
		return $wpdb->get_var( $wpdb->prepare( "SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $product_id ) );
	}

	/**
	 * Restores the snapshot text, proves the product matches the snapshot and runs the checks again.
	 *
	 * @param int    $product_id Product ID.
	 * @param array  $snapshot   Snapshot taken before the change.
	 * @param array  $checks     Checks so far.
	 * @param string $why        Why the rollback happens.
	 * @return array Verdict, reason and checks.
	 */
	private function rollback( $product_id, array $snapshot, array $checks, $why ) {
		global $wpdb;

		$phase    = __( 'Rollback', 'safe-ai-change' );
		$old_text = (string) $snapshot['post']['post_excerpt'];
		$this->log(
			'warning',
			'Rolling back.',
			array(
				'product_id' => $product_id,
				'why'        => $why,
			)
		);

		// A save can report success without writing (wp_update_post errors are not thrown), so read the column back.
		$error = $this->save_short_description( $product_id, $old_text );
		if ( '' === $error && $this->stored_excerpt( $product_id ) === $old_text ) {
			$checks[] = $this->check( $phase, __( 'Restore with the product object', 'safe-ai-change' ), true, __( 'The snapshot text was saved back.', 'safe-ai-change' ) );
		} else {
			$checks[] = $this->check( $phase, __( 'Restore with the product object', 'safe-ai-change' ), false, '' !== $error ? $error : __( 'The save returned, but post_excerpt still differs from the snapshot.', 'safe-ai-change' ) );
			// The product object failed, so write the column directly, without hooks, then clear the caches.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Last resort restore, caches cleared right after.
			$updated = $wpdb->update( $wpdb->posts, array( 'post_excerpt' => $old_text ), array( 'ID' => $product_id ), array( '%s' ), array( '%d' ) );
			clean_post_cache( $product_id );
			wc_delete_product_transients( $product_id );
			$checks[] = $this->check(
				$phase,
				__( 'Restore with a direct database update', 'safe-ai-change' ),
				false !== $updated,
				false !== $updated ? __( 'The post_excerpt column was written.', 'safe-ai-change' ) : __( 'The database update failed.', 'safe-ai-change' )
			);
		}

		$proof_phase = __( 'After rollback', 'safe-ai-change' );
		try {
			$proof = $this->run_checks( $product_id, $proof_phase, $snapshot, $old_text );
		} catch ( Throwable $e ) {
			$proof = array( $this->check( $proof_phase, __( 'Store checks', 'safe-ai-change' ), false, $this->throwable_detail( $e ) ) );
		}
		$checks = array_merge( $checks, $proof );

		if ( $this->has_failure( $proof ) ) {
			return $this->outcome( 'FAILED', $why . ' ' . __( 'The rollback could not be proven or a check still fails. Check the product and the WooCommerce logs now.', 'safe-ai-change' ), $checks );
		}
		return $this->outcome( 'ROLLED BACK', $why . ' ' . __( 'The previous text was restored, the product matches the snapshot and every check passed again.', 'safe-ai-change' ), $checks );
	}

	/**
	 * Saves a short description with the WooCommerce product object.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $text       New short description.
	 * @return string Empty on success, otherwise the error.
	 */
	private function save_short_description( $product_id, $text ) {
		try {
			$product = $this->fresh_product( $product_id );
			if ( ! $product ) {
				return __( 'The product could not be loaded.', 'safe-ai-change' );
			}
			$product->set_short_description( $text );
			$product->save();
		} catch ( Throwable $e ) {
			$this->log(
				'error',
				'Saving the product threw.',
				array(
					'product_id' => $product_id,
					'error'      => $this->throwable_detail( $e ),
				)
			);
			return $this->throwable_detail( $e );
		}
		$this->log( 'info', 'Short description saved.', array( 'product_id' => $product_id ) );
		return '';
	}

	/**
	 * Describes a Throwable for the log and the screen.
	 *
	 * @param Throwable $e Error.
	 * @return string
	 */
	private function throwable_detail( Throwable $e ) {
		return get_class( $e ) . ': ' . $e->getMessage();
	}

	/**
	 * Builds an outcome array.
	 *
	 * @param string $verdict Verdict.
	 * @param string $reason  Reason.
	 * @param array  $checks  Checks.
	 * @return array
	 */
	private function outcome( $verdict, $reason, array $checks = array() ) {
		return array(
			'verdict' => $verdict,
			'reason'  => $reason,
			'checks'  => $checks,
		);
	}

	/**
	 * Builds one check result and logs it.
	 *
	 * @param string $phase  Phase label.
	 * @param string $name   Check name.
	 * @param bool   $pass   Whether it passed.
	 * @param string $detail Detail.
	 * @return array
	 */
	private function check( $phase, $name, $pass, $detail ) {
		$this->log( $pass ? 'info' : 'warning', ( $pass ? 'PASS ' : 'FAIL ' ) . $phase . ' / ' . $name . ': ' . $detail );
		return array(
			'phase'  => $phase,
			'name'   => $name,
			'pass'   => (bool) $pass,
			'detail' => $detail,
		);
	}

	/**
	 * Tells whether any check failed.
	 *
	 * @param array $checks Checks.
	 * @return bool
	 */
	private function has_failure( array $checks ) {
		foreach ( $checks as $check ) {
			if ( empty( $check['pass'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads the product row, its meta and its terms straight from the database.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function take_snapshot( $product_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The snapshot must bypass every cache.
		$post      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $product_id ), ARRAY_A );
		$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", $product_id ), ARRAY_A );
		$terms     = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id", $product_id ) );
		// phpcs:enable

		$meta = array();
		foreach ( $meta_rows as $row ) {
			$meta[ $row['meta_key'] ][] = $row['meta_value'];
		}

		return array(
			'product_id' => $product_id,
			'taken_at'   => time(),
			'post'       => is_array( $post ) ? $post : array(),
			'meta'       => $meta,
			'terms'      => $terms,
		);
	}

	/**
	 * Lists the fields that differ between two snapshots, leaving out the ones WooCommerce or the editor write on their own.
	 *
	 * @param array $previous Snapshot before.
	 * @param array $current  Snapshot now.
	 * @return string[]
	 */
	private function snapshot_diff( array $previous, array $current ) {
		$changed = array();

		// WordPress stamps these on every save.
		$ignored_columns = array( 'post_modified', 'post_modified_gmt' );
		foreach ( array_unique( array_merge( array_keys( $previous['post'] ), array_keys( $current['post'] ) ) ) as $column ) {
			if ( in_array( $column, $ignored_columns, true ) ) {
				continue;
			}
			if ( ! isset( $previous['post'][ $column ], $current['post'][ $column ] ) || (string) $previous['post'][ $column ] !== (string) $current['post'][ $column ] ) {
				$changed[] = 'posts.' . $column;
			}
		}

		foreach ( array_unique( array_merge( array_keys( $previous['meta'] ), array_keys( $current['meta'] ) ) ) as $key ) {
			$before = isset( $previous['meta'][ $key ] ) ? $previous['meta'][ $key ] : null;
			$after  = isset( $current['meta'][ $key ] ) ? $current['meta'][ $key ] : null;
			if ( $before === $after ) {
				continue;
			}
			// WooCommerce writes its own version on every product save.
			if ( '_product_version' === $key && array( (string) WC_VERSION ) === $after ) {
				continue;
			}
			// The block editor refreshes its lock while someone has the product open; it is not product data.
			if ( '_edit_lock' === $key ) {
				continue;
			}
			$changed[] = 'meta.' . $key;
		}

		if ( $previous['terms'] !== $current['terms'] ) {
			$changed[] = 'terms';
		}

		return $changed;
	}

	/**
	 * Runs the store checks. With a snapshot, it also checks the database and the text on the product page.
	 *
	 * @param int         $product_id    Product ID.
	 * @param string      $phase         Phase label.
	 * @param array|null  $snapshot      Snapshot taken before the change, or null for the baseline.
	 * @param string|null $expected_text Short description the database and the product page must show.
	 * @return array
	 */
	private function run_checks( $product_id, $phase, $snapshot = null, $expected_text = null ) {
		$checks   = array();
		$restored = null !== $snapshot && (string) $snapshot['post']['post_excerpt'] === $expected_text;

		if ( null !== $snapshot ) {
			$checks[] = $this->check_database( $product_id, $snapshot, $expected_text, $phase, $restored );
		}

		$product = $this->fresh_product( $product_id );
		if ( ! $product ) {
			$checks[] = $this->check( $phase, __( 'Product', 'safe-ai-change' ), false, __( 'The product could not be loaded.', 'safe-ai-change' ) );
			return $checks;
		}

		if ( null === $expected_text ) {
			$label = __( 'Product page', 'safe-ai-change' );
		} elseif ( $restored ) {
			$label = __( 'Product page shows the restored text', 'safe-ai-change' );
		} else {
			$label = __( 'Product page shows the new text', 'safe-ai-change' );
		}
		$checks[] = $this->check_page( $phase, $label, get_permalink( $product_id ), $expected_text );
		$checks[] = $this->check_page( $phase, __( 'Home page', 'safe-ai-change' ), home_url( '/' ) );
		$checks[] = $this->check_page( $phase, __( 'Shop page', 'safe-ai-change' ), wc_get_page_permalink( 'shop' ) );
		$checks[] = $this->check_page( $phase, __( 'Cart page', 'safe-ai-change' ), wc_get_cart_url() );

		return array_merge( $checks, $this->check_store_api( $product, $phase ) );
	}

	/**
	 * Compares the database with the snapshot.
	 *
	 * @param int    $product_id    Product ID.
	 * @param array  $snapshot      Snapshot taken before the change.
	 * @param string $expected_text Text the short description must hold.
	 * @param string $phase         Phase label.
	 * @param bool   $restored      True when the expected text is the snapshot text.
	 * @return array
	 */
	private function check_database( $product_id, array $snapshot, $expected_text, $phase, $restored ) {
		$now     = $this->take_snapshot( $product_id );
		$diff    = $this->snapshot_diff( $snapshot, $now );
		$wanted  = $restored ? array() : array( 'posts.post_excerpt' );
		$text_ok = isset( $now['post']['post_excerpt'] ) && (string) $now['post']['post_excerpt'] === $expected_text;
		$pass    = $text_ok && $wanted === $diff;
		$name    = $restored ? __( 'Database: product matches the snapshot', 'safe-ai-change' ) : __( 'Database: only the short description changed', 'safe-ai-change' );

		if ( $pass ) {
			$detail = $restored ? __( 'Every field matches the snapshot.', 'safe-ai-change' ) : __( 'Only post_excerpt differs from the snapshot.', 'safe-ai-change' );
		} else {
			$detail = $text_ok ? '' : __( 'post_excerpt does not hold the expected text.', 'safe-ai-change' ) . ' ';
			/* translators: %s: list of changed database fields. */
			$detail .= $diff ? sprintf( __( 'Changed fields: %s', 'safe-ai-change' ), implode( ', ', $diff ) ) : __( 'No field changed.', 'safe-ai-change' );
		}

		return $this->check( $phase, $name, $pass, $detail );
	}

	/**
	 * Sends an anonymous loopback request to this site.
	 *
	 * @param string $method GET or POST.
	 * @param string $url    URL.
	 * @param array  $args   Extra request arguments.
	 * @return array|WP_Error
	 */
	private function loopback( $method, $url, array $args = array() ) {
		$headers = isset( $args['headers'] ) ? $args['headers'] : array();
		$args    = array_merge(
			array(
				'timeout'     => 15,
				'redirection' => 0,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'     => array(),
			),
			$args
		);

		$args['headers'] = array_merge( array( 'Cache-Control' => 'no-cache' ), $headers );

		return 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
	}

	/**
	 * Returns the HTTP status of a response, or 0 for a transport error.
	 *
	 * @param array|WP_Error $response Response.
	 * @return int
	 */
	private function http_code( $response ) {
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Decodes a JSON response body.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array|null
	 */
	private function decode_json( $response ) {
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Describes a response status for the check table.
	 *
	 * @param array|WP_Error $response Response.
	 * @return string
	 */
	private function http_detail( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'HTTP %d', 'safe-ai-change' ), $this->http_code( $response ) );
	}

	/**
	 * Checks that a page answers 200, with no redirect and no critical error, and optionally shows a text.
	 *
	 * @param string      $phase         Phase label.
	 * @param string      $label         Check name.
	 * @param string      $url           Page URL.
	 * @param string|null $expected_text Text the page must show, or null.
	 * @return array
	 */
	private function check_page( $phase, $label, $url, $expected_text = null ) {
		// A random argument keeps a page cache from serving an older copy.
		$response = $this->loopback( 'GET', add_query_arg( 'sac_nocache', wp_generate_password( 12, false ), $url ) );
		if ( is_wp_error( $response ) ) {
			return $this->check( $phase, $label, false, $response->get_error_message() );
		}

		$code = $this->http_code( $response );
		if ( 200 !== $code ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? implode( ', ', $location ) : (string) $location;
			/* translators: %s: redirect target. */
			$detail = $this->http_detail( $response ) . ( '' !== $location ? ' ' . sprintf( __( 'redirect to %s', 'safe-ai-change' ), $location ) : '' );
			return $this->check( $phase, $label, false, $detail );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( $this->body_has_critical_error( $body ) ) {
			return $this->check( $phase, $label, false, __( 'HTTP 200, but the page shows a critical error.', 'safe-ai-change' ) );
		}

		if ( null !== $expected_text && '' !== $this->normalize_text( $expected_text ) ) {
			if ( ! $this->page_shows_text( $body, $expected_text ) ) {
				return $this->check( $phase, $label, false, __( 'HTTP 200, but the expected short description is not on the page.', 'safe-ai-change' ) );
			}
			return $this->check( $phase, $label, true, __( 'HTTP 200, no redirect, no critical error, text found.', 'safe-ai-change' ) );
		}

		return $this->check( $phase, $label, true, __( 'HTTP 200, no redirect, no critical error.', 'safe-ai-change' ) );
	}

	/**
	 * Detects the WordPress critical error screen or a printed PHP fatal error.
	 *
	 * @param string $body Page HTML.
	 * @return bool
	 */
	private function body_has_critical_error( $body ) {
		// The wp_die() template always prints this body ID, whatever the site language.
		$markers = array( 'id="error-page"', 'There has been a critical error on this website', '<b>Fatal error</b>', 'PHP Fatal error' );
		foreach ( $markers as $marker ) {
			if ( false !== strpos( $body, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reduces HTML to visible text with single spaces.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function normalize_text( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	/**
	 * Tells whether the page text contains the expected text, as written or as WordPress formats it.
	 *
	 * @param string $body Page HTML.
	 * @param string $text Expected text.
	 * @return bool
	 */
	private function page_shows_text( $body, $text ) {
		$haystack = $this->normalize_text( $body );
		// Raw for block themes, texturized, and through the classic template filters (smilies, shortcodes, wpautop).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter.
		foreach ( array( $text, wptexturize( $text ), apply_filters( 'woocommerce_short_description', $text ) ) as $candidate ) {
			$needle = $this->normalize_text( $candidate );
			if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns an item to test the cart with: the product, or its first purchasable variation.
	 *
	 * @param WC_Product $product Product.
	 * @return int 0 when nothing can be added to the cart.
	 */
	private function cart_test_item_id( WC_Product $product ) {
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && 'publish' === $child->get_status() && $child->is_purchasable() && $child->is_in_stock() ) {
					return (int) $child_id;
				}
			}
			return 0;
		}
		return $product->is_purchasable() && $product->is_in_stock() ? $product->get_id() : 0;
	}

	/**
	 * Builds a Store API URL.
	 *
	 * @param string $path Route after wc/store/v1/.
	 * @return string
	 */
	private function store_api_url( $path ) {
		return rest_url( 'wc/store/v1/' . $path );
	}

	/**
	 * Adds an item to an anonymous cart, reads the checkout, then removes the item.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $phase   Phase label.
	 * @return array
	 */
	private function check_store_api( WC_Product $product, $phase ) {
		$checks  = array();
		$item_id = $this->cart_test_item_id( $product );
		if ( ! $item_id ) {
			$checks[] = $this->check( $phase, __( 'Store API: add to cart', 'safe-ai-change' ), false, __( 'This product has no purchasable item in stock to test the cart with.', 'safe-ai-change' ) );
			return $checks;
		}

		// A Cart-Token header carries the cart session, so these requests need no cookie and no nonce.
		$response = $this->loopback( 'GET', $this->store_api_url( 'cart' ) );
		$token    = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'cart-token' );
		if ( 200 !== $this->http_code( $response ) || '' === $token ) {
			$checks[] = $this->check( $phase, __( 'Store API: cart session', 'safe-ai-change' ), false, $this->http_detail( $response ) );
			return $checks;
		}
		$headers = array(
			'Cart-Token'   => $token,
			'Content-Type' => 'application/json',
		);

		$response = $this->loopback(
			'POST',
			$this->store_api_url( 'cart/add-item' ),
			array(
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'id'       => $item_id,
						'quantity' => 1,
					)
				),
			)
		);
		$data     = $this->decode_json( $response );
		$key      = '';
		foreach ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array() as $item ) {
			if ( isset( $item['id'], $item['key'] ) && (int) $item['id'] === $item_id ) {
				$key = (string) $item['key'];
			}
		}
		$checks[] = $this->check(
			$phase,
			__( 'Store API: add to cart', 'safe-ai-change' ),
			in_array( $this->http_code( $response ), array( 200, 201 ), true ) && '' !== $key,
			/* translators: 1: HTTP status, 2: product or variation ID. */
			sprintf( __( '%1$s, item %2$d', 'safe-ai-change' ), $this->http_detail( $response ), $item_id )
		);
		if ( '' === $key ) {
			return $checks;
		}

		// A GET on checkout builds the checkout from the cart without creating an order.
		$response = $this->loopback( 'GET', $this->store_api_url( 'checkout' ), array( 'headers' => $headers ) );
		$data     = $this->decode_json( $response );
		$checks[] = $this->check(
			$phase,
			__( 'Store API: checkout', 'safe-ai-change' ),
			200 === $this->http_code( $response ) && is_array( $data ) && ! isset( $data['code'] ),
			$this->http_detail( $response ) . ( isset( $data['code'] ) ? ' ' . sanitize_key( $data['code'] ) : '' )
		);

		$response = $this->loopback(
			'POST',
			$this->store_api_url( 'cart/remove-item' ),
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( array( 'key' => $key ) ),
			)
		);
		$data     = $this->decode_json( $response );
		$empty    = isset( $data['items'] ) && array() === $data['items'];
		$checks[] = $this->check(
			$phase,
			__( 'Store API: test item removed', 'safe-ai-change' ),
			200 === $this->http_code( $response ) && $empty,
			$this->http_detail( $response ) . ( $empty ? ', ' . __( 'cart empty', 'safe-ai-change' ) : ', ' . __( 'cart not empty', 'safe-ai-change' ) )
		);

		return $checks;
	}

	/**
	 * Renders the verdict and the check table.
	 *
	 * @param array $result Stored result.
	 */
	private function render_result( array $result ) {
		$classes = array(
			'APPLIED'     => 'notice-success',
			'REFUSED'     => 'notice-warning',
			'ROLLED BACK' => 'notice-warning',
			'FAILED'      => 'notice-error',
		);
		$verdict = isset( $result['verdict'] ) ? (string) $result['verdict'] : '';
		$class   = isset( $classes[ $verdict ] ) ? $classes[ $verdict ] : 'notice-info';
		$checks  = isset( $result['checks'] ) && is_array( $result['checks'] ) ? $result['checks'] : array();
		?>
		<div class="notice inline <?php echo esc_attr( $class ); ?>">
			<p>
				<?php if ( '' !== $verdict ) : ?>
					<strong><?php echo esc_html( $verdict ); ?></strong>
				<?php endif; ?>
				<?php echo esc_html( isset( $result['reason'] ) ? (string) $result['reason'] : '' ); ?>
			</p>
		</div>
		<?php if ( $checks ) : ?>
			<h2><?php esc_html_e( 'Checks', 'safe-ai-change' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Phase', 'safe-ai-change' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Check', 'safe-ai-change' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'safe-ai-change' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Detail', 'safe-ai-change' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $checks as $check ) : ?>
						<tr>
							<td><?php echo esc_html( $check['phase'] ); ?></td>
							<td><?php echo esc_html( $check['name'] ); ?></td>
							<td><strong><?php echo esc_html( $check['pass'] ? 'PASS' : 'FAIL' ); ?></strong></td>
							<td><?php echo esc_html( $check['detail'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the preview with Apply and Cancel.
	 *
	 * @param array $proposal Preview data.
	 */
	private function render_preview( array $proposal ) {
		$product_id = (int) $proposal['product_id'];
		$expires    = (int) $proposal['created'] + self::TTL;
		?>
		<h2><?php esc_html_e( 'Preview', 'safe-ai-change' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Request', 'safe-ai-change' ); ?></th>
					<td style="white-space:pre-wrap"><?php echo esc_html( $proposal['request'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Product', 'safe-ai-change' ); ?></th>
					<td>
						<?php echo esc_html( $proposal['product_name'] ); ?>
						(<a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>"><?php esc_html_e( 'view', 'safe-ai-change' ); ?></a>,
						<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>"><?php esc_html_e( 'edit', 'safe-ai-change' ); ?></a>)
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Before', 'safe-ai-change' ); ?></th>
					<td style="white-space:pre-wrap"><?php echo '' === $proposal['before'] ? esc_html__( '(empty)', 'safe-ai-change' ) : esc_html( $proposal['before'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'After', 'safe-ai-change' ); ?></th>
					<td style="white-space:pre-wrap"><?php echo esc_html( $proposal['after'] ); ?></td>
				</tr>
			</tbody>
		</table>
		<p>
			<?php
			/* translators: %s: time when the preview expires. */
			echo esc_html( sprintf( __( 'This preview expires at %s and can be used one time only.', 'safe-ai-change' ), wp_date( get_option( 'time_format' ), $expires ) ) );
			?>
		</p>
		<div style="display:flex;gap:8px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sac_apply">
				<input type="hidden" name="sac_token" value="<?php echo esc_attr( $proposal['token'] ); ?>">
				<?php wp_nonce_field( 'sac_apply', 'sac_nonce' ); ?>
				<?php submit_button( __( 'Apply', 'safe-ai-change' ), 'primary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sac_cancel">
				<input type="hidden" name="sac_token" value="<?php echo esc_attr( $proposal['token'] ); ?>">
				<?php wp_nonce_field( 'sac_cancel', 'sac_nonce' ); ?>
				<?php submit_button( __( 'Cancel', 'safe-ai-change' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the request form.
	 */
	private function render_request_form() {
		?>
		<h2><?php esc_html_e( 'New request', 'safe-ai-change' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sac_propose">
			<?php wp_nonce_field( 'sac_propose', 'sac_nonce' ); ?>
			<p>
				<label for="sac-request"><?php esc_html_e( 'Which product short description should change, and to what?', 'safe-ai-change' ); ?></label>
			</p>
			<textarea id="sac-request" name="sac_request" class="large-text" rows="5" maxlength="<?php echo esc_attr( (string) self::MAX_REQUEST ); ?>" required></textarea>
			<p class="description">
				<?php
				/* translators: 1: maximum request length, 2: maximum text length. */
				echo esc_html( sprintf( __( 'Write the exact product name or SKU and the exact new short description, in %1$d characters or fewer. The new text can be %2$d characters at most. Only this text is sent to the AI, never any store data.', 'safe-ai-change' ), self::MAX_REQUEST, self::MAX_TEXT ) );
				?>
			</p>
			<?php submit_button( __( 'Prepare preview', 'safe-ai-change' ) ); ?>
		</form>
		<?php
	}
}
