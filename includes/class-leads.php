<?php
defined( 'ABSPATH' ) || exit;

/**
 * Lead capture: "Request this car" and generic enquiry forms.
 * Stores leads as a private post type and emails the admin.
 * Protection: nonce, honeypot field, minimum fill time, per-IP rate limit.
 */
class NL_Leads {

	public const POST_TYPE = 'vehicle_lead';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'admin_post_nopriv_nl_lead', [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_nl_lead', [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_nopriv_nl_lead', [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_nl_lead', [ __CLASS__, 'handle' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
	}

	public static function register(): void {
		register_post_type( self::POST_TYPE, [
			'labels'          => [ 'name' => 'Leads', 'singular_name' => 'Lead', 'menu_name' => 'Leads', 'edit_item' => 'Lead' ],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'edit.php?post_type=' . NL_Post_Types::POST_TYPE,
			'supports'        => [ 'title', 'editor' ],
			'capability_type' => 'post',
			'capabilities'    => [ 'create_posts' => 'do_not_allow' ],
			'map_meta_cap'    => true,
		] );
	}

	/** Render the enquiry form. $vehicle_id = 0 for a generic form. */
	public static function form( int $vehicle_id = 0, string $button = 'Send request' ): string {
		ob_start();
		?>
		<form class="nl-lead-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<?php wp_nonce_field( 'nl_lead', 'nl_lead_nonce' ); ?>
			<input type="hidden" name="action" value="nl_lead">
			<input type="hidden" name="vehicle_id" value="<?php echo (int) $vehicle_id; ?>">
			<input type="hidden" name="ts" value="<?php echo (int) time(); ?>">
			<input type="hidden" name="redirect" value="<?php echo esc_url( add_query_arg( 'sent', '1', $vehicle_id ? get_permalink( $vehicle_id ) : home_url( '/' ) ) ); ?>">
			<p class="nl-lead-form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
			<div class="nl-lead-form__row">
				<label>Your name<input type="text" name="name" required maxlength="80" autocomplete="name"></label>
				<label>Phone or WhatsApp<input type="tel" name="phone" required maxlength="40" autocomplete="tel"></label>
			</div>
			<label>Email<input type="email" name="email" maxlength="120" autocomplete="email"></label>
			<label>Message<textarea name="message" rows="3" maxlength="2000" placeholder="<?php echo $vehicle_id ? 'Questions about this car, shipping destination, budget…' : 'What are you looking for? Make, model, year, budget, destination port…'; ?>"></textarea></label>
			<label class="nl-lead-form__consent"><input type="checkbox" name="consent" value="1" required> I agree to be contacted about my request.</label>
			<button type="submit" class="btn btn--primary"><?php echo esc_html( $button ); ?></button>
			<p class="nl-lead-form__status" role="status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public static function handle(): void {
		$is_ajax = wp_doing_ajax();
		$fail    = static function ( string $msg, int $code = 400 ) use ( $is_ajax ) {
			if ( $is_ajax ) {
				wp_send_json_error( [ 'message' => $msg ], $code );
			}
			wp_die( esc_html( $msg ), 'Request not sent', [ 'response' => $code, 'back_link' => true ] );
		};
		if ( ! isset( $_POST['nl_lead_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nl_lead_nonce'] ), 'nl_lead' ) ) {
			$fail( 'Security check failed, please reload the page and try again.' );
		}
		if ( ! empty( $_POST['website'] ) ) {
			$fail( 'Spam detected.' );
		}
		if ( time() - (int) ( $_POST['ts'] ?? 0 ) < 3 ) {
			$fail( 'Form submitted too quickly, please try again.' );
		}
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'nl_lead_rl_' . md5( $ip );
		if ( (int) get_transient( $key ) >= 5 ) {
			$fail( 'Too many requests, please try again later.', 429 );
		}
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$phone   = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$vehicle = (int) ( $_POST['vehicle_id'] ?? 0 );
		if ( $name === '' || $phone === '' ) {
			$fail( 'Name and phone are required.' );
		}
		$vehicle_title = $vehicle ? get_the_title( $vehicle ) : '';
		$lead_id       = wp_insert_post( [
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'private',
			'post_title'   => $name . ( $vehicle_title ? ' — ' . $vehicle_title : ' — general enquiry' ),
			'post_content' => $message,
			'meta_input'   => [ '_nl_phone' => $phone, '_nl_email' => $email, '_nl_vehicle' => $vehicle, '_nl_ip' => $ip, '_nl_page' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ) ],
		] );
		$body = "Name: $name\nPhone: $phone\nEmail: $email\n" . ( $vehicle ? "Vehicle: $vehicle_title\n" . get_permalink( $vehicle ) . "\n" : '' ) . "\n$message\n\nLead #$lead_id";
		wp_mail( get_option( 'admin_email' ), '[' . get_bloginfo( 'name' ) . '] New lead from ' . $name, $body );
		do_action( 'nl_lead_created', $lead_id );

		if ( $is_ajax ) {
			wp_send_json_success( [ 'message' => 'Thank you! We will contact you shortly.' ] );
		}
		$redirect = esc_url_raw( wp_unslash( $_POST['redirect'] ?? home_url( '/' ) ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function columns( array $c ): array {
		return [ 'cb' => $c['cb'], 'title' => 'Lead', 'nl_phone' => 'Phone', 'nl_email' => 'Email', 'nl_vehicle' => 'Vehicle', 'date' => 'Date' ];
	}

	public static function column_content( string $col, int $id ): void {
		switch ( $col ) {
			case 'nl_phone':
				echo esc_html( (string) get_post_meta( $id, '_nl_phone', true ) );
				break;
			case 'nl_email':
				echo esc_html( (string) get_post_meta( $id, '_nl_email', true ) );
				break;
			case 'nl_vehicle':
				$v = (int) get_post_meta( $id, '_nl_vehicle', true );
				echo $v ? '<a href="' . esc_url( get_edit_post_link( $v ) ) . '">' . esc_html( get_the_title( $v ) ) . '</a>' : '—';
				break;
		}
	}
}
