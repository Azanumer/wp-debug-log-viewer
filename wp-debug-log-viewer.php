<?php
/**
 * Plugin Name: WP Debug Log Viewer
 * Plugin URI: https://github.com/Azanumer/wp-debug-log-viewer
 * Description: Read, search and filter wp-content/debug.log from the admin — no more SSH tailing. Severity badges, newest-first, paged, one-click clear.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-debug-log-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WDLV_VERSION', '1.0.0' );
define( 'WDLV_PER_PAGE', 50 );
/** Only the tail of huge logs is read — 512 KB is plenty for a viewer. */
define( 'WDLV_MAX_READ_BYTES', 512 * 1024 );

/** Path of the WordPress debug log. */
function wdlv_log_path() {
	return WP_CONTENT_DIR . '/debug.log';
}

/**
 * Parse the debug log into entries.
 * A line starting with [date] begins a new entry; following lines
 * (stack traces, multi-line messages) belong to the previous entry.
 *
 * @return array List of [ 'time' => string, 'severity' => string, 'message' => string ].
 */
function wdlv_parse_log( $path ) {
	$entries = array();
	if ( ! is_readable( $path ) ) {
		return $entries;
	}

	$size = filesize( $path );
	if ( false === $size || 0 === $size ) {
		return $entries;
	}

	$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
	if ( false === $handle ) {
		return $entries;
	}
	// Read only the tail of a large file.
	if ( $size > WDLV_MAX_READ_BYTES ) {
		fseek( $handle, $size - WDLV_MAX_READ_BYTES );
		// Discard the partial first line so we start on a clean entry.
		fgets( $handle );
	}
	$raw = stream_get_contents( $handle );
	fclose( $handle );
	if ( false === $raw || '' === $raw ) {
		return $entries;
	}

	$current = null;
	foreach ( preg_split( "/\r\n|\n|\r/", $raw ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		// New entry: [08-Nov-2026 10:00:00 UTC] PHP Warning: ...
		if ( preg_match( '/^\[([^\]]+)\]\s*(.*)$/', $line, $m ) ) {
			if ( null !== $current ) {
				$entries[] = $current;
			}
			$severity = 'Other';
			$body     = $m[2];
			if ( preg_match( '/^PHP\s+(Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated|Strict Standards|User Warning|User Notice|User Deprecated)\b/i', $body, $s ) ) {
				$severity = ucwords( strtolower( $s[1] ) );
			} elseif ( stripos( $body, 'WordPress database error' ) === 0 ) {
				$severity = 'DB Error';
			}
			$current = array(
				'time'     => $m[1],
				'severity' => $severity,
				'message'  => $body,
			);
		} elseif ( null !== $current ) {
			// Continuation line (stack trace, etc.) — keep it with its entry.
			$current['message'] .= "\n" . $line;
		}
	}
	if ( null !== $current ) {
		$entries[] = $current;
	}

	return array_reverse( $entries ); // Newest first.
}

add_action( 'admin_menu', 'wdlv_register_menu' );
function wdlv_register_menu() {
	add_management_page(
		__( 'Debug Log', 'wp-debug-log-viewer' ),
		__( 'Debug Log', 'wp-debug-log-viewer' ),
		'manage_options',
		'wdlv-debug-log',
		'wdlv_render_page'
	);
}

function wdlv_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-debug-log-viewer' ) );
	}

	$path   = wdlv_log_path();
	$notice = '';
	$error  = '';

	// Clear the log (nonce-checked, admin only).
	if ( isset( $_POST['wdlv_clear'] ) && check_admin_referer( 'wdlv_clear_log' ) ) {
		$cleared = false;
		if ( is_writable( $path ) ) {
			$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
			if ( false !== $handle ) {
				fclose( $handle );
				$cleared = true;
			}
		}
		if ( $cleared ) {
			$notice = __( 'Debug log cleared.', 'wp-debug-log-viewer' );
		} else {
			$error = __( 'Could not clear the log — check that wp-content/debug.log is writable.', 'wp-debug-log-viewer' );
		}
	}

	$severity_filter = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : 'all';
	$search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$page            = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

	$entries = wdlv_parse_log( $path );

	// Severity counts for the filter links.
	$counts = array();
	foreach ( $entries as $entry ) {
		$counts[ $entry['severity'] ] = isset( $counts[ $entry['severity'] ] ) ? $counts[ $entry['severity'] ] + 1 : 1;
	}

	if ( 'all' !== $severity_filter ) {
		$entries = array_values( array_filter( $entries, function ( $e ) use ( $severity_filter ) {
			return sanitize_key( $e['severity'] ) === $severity_filter;
		} ) );
	}
	if ( '' !== $search ) {
		$entries = array_values( array_filter( $entries, function ( $e ) use ( $search ) {
			return false !== stripos( $e['message'], $search ) || false !== stripos( $e['time'], $search );
		} ) );
	}

	$total       = count( $entries );
	$total_pages = max( 1, (int) ceil( $total / WDLV_PER_PAGE ) );
	$page        = min( $page, $total_pages );
	$shown       = array_slice( $entries, ( $page - 1 ) * WDLV_PER_PAGE, WDLV_PER_PAGE );

	$exists      = file_exists( $path );
	$size_human  = $exists ? size_format( filesize( $path ) ) : '—';
	$debug_on    = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	$base_url    = admin_url( 'tools.php?page=wdlv-debug-log' );

	$severity_colors = array(
		'Fatal Error'            => '#d63638',
		'Parse Error'            => '#d63638',
		'Recoverable Fatal Error' => '#d63638',
		'Warning'                => '#dba617',
		'User Warning'           => '#dba617',
		'Notice'                 => '#2271b1',
		'User Notice'            => '#2271b1',
		'Deprecated'             => '#826eb4',
		'User Deprecated'        => '#826eb4',
		'Db Error'               => '#d63638',
		'Other'                  => '#646970',
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Debug Log', 'wp-debug-log-viewer' ); ?></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<p>
			<code><?php echo esc_html( $path ); ?></code><br>
			<?php
			/* translators: %s: human-readable file size */
			printf( esc_html__( 'Size: %s', 'wp-debug-log-viewer' ), esc_html( $size_human ) );
			echo ' &middot; ';
			/* translators: %d: number of log entries */
			printf( esc_html__( '%d entries', 'wp-debug-log-viewer' ), (int) $total );
			?>
		</p>

		<?php if ( ! $debug_on || ! $log_enabled ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				if ( ! $debug_on ) {
					esc_html_e( 'WP_DEBUG is off, so nothing is being logged.', 'wp-debug-log-viewer' );
				} else {
					esc_html_e( 'WP_DEBUG_LOG is off, so nothing is being written to debug.log.', 'wp-debug-log-viewer' );
				}
				?>
				<?php esc_html_e( 'Add these to wp-config.php:', 'wp-debug-log-viewer' ); ?>
				<code>define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );</code>
			</p></div>
		<?php endif; ?>

		<?php if ( ! $exists ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'No debug.log file exists yet — it is created automatically the first time WordPress logs something.', 'wp-debug-log-viewer' ); ?></p></div>
		<?php else : ?>
			<form method="get" action="<?php echo esc_url( $base_url ); ?>">
				<input type="hidden" name="page" value="wdlv-debug-log">
				<p class="search-box">
					<label class="screen-reader-text" for="wdlv-search"><?php esc_html_e( 'Search log', 'wp-debug-log-viewer' ); ?></label>
					<input type="search" id="wdlv-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search messages…', 'wp-debug-log-viewer' ); ?>">
					<?php submit_button( __( 'Search', 'wp-debug-log-viewer' ), 'secondary', '', false ); ?>
				</p>
			</form>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( array( 'severity' => 'all', 'paged' => 1 ), $base_url ) ); ?>" class="<?php echo 'all' === $severity_filter ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'wp-debug-log-viewer' ); ?> <span class="count">(<?php echo (int) array_sum( $counts ); ?>)</span></a> |</li>
				<?php $i = 0; foreach ( $counts as $sev => $count ) : $i++; ?>
					<li><a href="<?php echo esc_url( add_query_arg( array( 'severity' => sanitize_key( $sev ), 'paged' => 1 ), $base_url ) ); ?>" class="<?php echo sanitize_key( $sev ) === $severity_filter ? 'current' : ''; ?>"><?php echo esc_html( $sev ); ?> <span class="count">(<?php echo (int) $count; ?>)</span></a><?php echo $i < count( $counts ) ? ' |' : ''; ?></li>
				<?php endforeach; ?>
			</ul>

			<form method="post" style="float:right;">
				<?php wp_nonce_field( 'wdlv_clear_log' ); ?>
				<?php submit_button( __( 'Clear log', 'wp-debug-log-viewer' ), 'delete', 'wdlv_clear', false ); ?>
			</form>
			<div style="clear:both;"></div>

			<?php if ( empty( $shown ) ) : ?>
				<p><?php esc_html_e( 'No log entries match your filter.', 'wp-debug-log-viewer' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead><tr>
						<th style="width:170px;"><?php esc_html_e( 'Time', 'wp-debug-log-viewer' ); ?></th>
						<th style="width:150px;"><?php esc_html_e( 'Severity', 'wp-debug-log-viewer' ); ?></th>
						<th><?php esc_html_e( 'Message', 'wp-debug-log-viewer' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $shown as $entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( $entry['time'] ); ?></code></td>
								<td><span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;background:<?php echo esc_attr( isset( $severity_colors[ $entry['severity'] ] ) ? $severity_colors[ $entry['severity'] ] : '#646970' ); ?>;"><?php echo esc_html( $entry['severity'] ); ?></span></td>
								<td><code style="white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( $entry['message'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<?php
						$pagination_base = add_query_arg(
							array(
								'severity' => $severity_filter,
								's'        => $search,
							),
							$base_url
						);
						echo paginate_links( array(
							'base'      => $pagination_base . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $page,
							'total'     => $total_pages,
							'prev_text' => __( '&laquo; Previous', 'wp-debug-log-viewer' ),
							'next_text' => __( 'Next &raquo;', 'wp-debug-log-viewer' ),
						) );
						?>
					</div></div>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
