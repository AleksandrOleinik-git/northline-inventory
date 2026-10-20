<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: CSV import page, VIN decode AJAX, admin assets.
 */
class NL_Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_ajax_nl_decode_vin', [ __CLASS__, 'ajax_decode_vin' ] );
		add_action( 'admin_post_nl_import_csv', [ __CLASS__, 'handle_import' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . NL_Post_Types::POST_TYPE, 'Import CSV', 'Import CSV', 'edit_posts', 'nl-import', [ __CLASS__, 'render_import_page' ] );
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== NL_Post_Types::POST_TYPE ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'nl-admin', NL_INV_URL . 'assets/admin.css', [], NL_INV_VERSION );
		wp_enqueue_script( 'nl-admin', NL_INV_URL . 'assets/admin.js', [ 'jquery', 'jquery-ui-sortable' ], NL_INV_VERSION, true );
		wp_localize_script( 'nl-admin', 'nlAdmin', [ 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'nl_admin' ) ] );
	}

	public static function ajax_decode_vin(): void {
		check_ajax_referer( 'nl_admin', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Not allowed' ], 403 );
		}
		$vin    = sanitize_text_field( wp_unslash( $_POST['vin'] ?? '' ) );
		$result = NL_Vin_Decoder::decode( $vin );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( $result );
	}

	public static function render_import_page(): void {
		$report = get_transient( 'nl_import_report_' . get_current_user_id() );
		if ( $report ) {
			delete_transient( 'nl_import_report_' . get_current_user_id() );
		}
		?>
		<div class="wrap nl-import">
			<h1>Import vehicles from CSV</h1>
			<?php if ( is_array( $report ) ) : ?>
				<div class="notice notice-<?php echo $report['errors'] ? 'warning' : 'success'; ?>">
					<p><strong><?php echo $report['dry_run'] ? 'Dry run finished.' : 'Import finished.'; ?></strong>
						Created: <?php echo (int) $report['created']; ?>, updated: <?php echo (int) $report['updated']; ?>,
						skipped: <?php echo (int) $report['skipped']; ?>, photos added: <?php echo (int) $report['photos']; ?>.</p>
					<?php if ( $report['errors'] ) : ?>
						<ul class="nl-import__errors">
							<?php foreach ( array_slice( $report['errors'], 0, 50 ) as $e ) : ?>
								<li><?php echo esc_html( $e ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'nl_import_csv' ); ?>
				<input type="hidden" name="action" value="nl_import_csv">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nl_csv">CSV file</label></th>
						<td><input type="file" name="nl_csv" id="nl_csv" accept=".csv,text/csv" required>
							<p class="description">UTF-8, comma or semicolon separated. <a href="<?php echo esc_url( NL_INV_URL . 'sample-data/vehicles-sample.csv' ); ?>">Download a sample file</a>.</p></td>
					</tr>
					<tr>
						<th scope="row">Options</th>
						<td>
							<label><input type="checkbox" name="dry_run" value="1"> Dry run (validate only, change nothing)</label><br>
							<label><input type="checkbox" name="decode_vin" value="1"> Decode 17-character VINs via NHTSA to fill empty fields</label><br>
							<label><input type="checkbox" name="photos" value="1" checked> Download photos from the <code>photos</code> column</label>
						</td>
					</tr>
				</table>
				<p class="description">Rows are matched to existing vehicles by VIN, then by stock number, so you can re-import the same file to update prices and statuses.</p>
				<?php submit_button( 'Import' ); ?>
			</form>
			<h2>Columns</h2>
			<p><code><?php echo esc_html( implode( ', ', NL_Importer::COLUMNS ) ); ?></code></p>
			<p>Multiple photos and features are separated with a pipe <code>|</code>. Photos may be URLs or paths relative to the uploads folder.</p>
		</div>
		<?php
	}

	public static function handle_import(): void {
		check_admin_referer( 'nl_import_csv' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		$file = $_FILES['nl_csv'] ?? null;
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_die( 'Upload failed' );
		}
		$rows     = NL_Importer::read( $file['tmp_name'] );
		$importer = new NL_Importer( ! empty( $_POST['dry_run'] ), ! empty( $_POST['decode_vin'] ), ! empty( $_POST['photos'] ) );
		set_time_limit( 0 );
		$report            = $importer->run( $rows );
		$report['dry_run'] = ! empty( $_POST['dry_run'] );
		set_transient( 'nl_import_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . NL_Post_Types::POST_TYPE . '&page=nl-import' ) );
		exit;
	}
}
