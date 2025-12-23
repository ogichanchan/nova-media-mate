<?php
/**
 * Plugin Name: Nova Media Mate
 * Plugin URI: https://github.com/ogichanchan/nova-media-mate
 * Description: A unique PHP-only WordPress utility. A nova style media plugin acting as a mate. Focused on simplicity and efficiency.
 * Version: 1.0.0
 * Author: ogichanchan
 * Author URI: https://github.com/ogichanchan
 * License: GPLv2 or later
 * Text Domain: nova-media-mate
 */

// Exit if accessed directly to prevent security vulnerabilities.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Nova_Media_Mate' ) ) {
	/**
	 * Main plugin class for Nova Media Mate.
	 *
	 * This class handles all plugin functionality, including adding duplicate links
	 * for media items and processing the duplication action in a single PHP file.
	 */
	class Nova_Media_Mate {

		/**
		 * Constructor for Nova_Media_Mate.
		 *
		 * Hooks into WordPress actions and filters to initialize plugin features.
		 */
		public function __construct() {
			// Add duplicate link to media row actions in list table (e.g., Media -> Library).
			add_filter( 'media_row_actions', array( $this, 'add_duplicate_link_to_media_row' ), 10, 2 );

			// Add duplicate link to the publish meta box on the attachment edit screen.
			add_action( 'post_submitbox_misc_actions', array( $this, 'add_duplicate_link_to_attachment_edit_screen' ) );

			// Hook into WordPress's 'admin_action_' system to handle the duplication request.
			add_action( 'admin_action_nova_media_mate_duplicate', array( $this, 'handle_media_duplication_action' ) );

			// Display admin notices (success/error messages) after redirection.
			add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
		}

		/**
		 * Adds a "Duplicate" link to the media item's row actions in the Media Library list table.
		 *
		 * @param array   $actions An array of existing action links for the media item.
		 * @param WP_Post $post    The post object for the current media item.
		 * @return array Modified array of action links, including the "Duplicate" link.
		 */
		public function add_duplicate_link_to_media_row( $actions, $post ) {
			// Check if the current user has the 'upload_files' capability and can edit the specific post.
			if ( current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $post->ID ) ) {
				// Generate a nonce-protected URL for the duplication action.
				$duplicate_url = wp_nonce_url(
					admin_url( 'admin.php?action=nova_media_mate_duplicate&post=' . absint( $post->ID ) ),
					'nova_media_mate_duplicate_' . absint( $post->ID ),
					'nova_media_mate_nonce'
				);

				// Add the "Duplicate" link to the actions array.
				$actions['nova_media_mate_duplicate'] = sprintf(
					'<a href="%s" title="%s">%s</a>',
					esc_url( $duplicate_url ),
					esc_attr__( 'Duplicate this media item', 'nova-media-mate' ),
					esc_html__( 'Duplicate', 'nova-media-mate' )
				);
			}
			return $actions;
		}

		/**
		 * Adds a "Duplicate Media" button to the publish meta box on the attachment edit screen.
		 */
		public function add_duplicate_link_to_attachment_edit_screen() {
			global $post;

			// Ensure we are on an attachment edit screen.
			if ( empty( $post ) || 'attachment' !== $post->post_type ) {
				return;
			}

			// Check user capabilities before displaying the button.
			if ( current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $post->ID ) ) {
				// Generate a nonce-protected URL for the duplication action.
				$duplicate_url = wp_nonce_url(
					admin_url( 'admin.php?action=nova_media_mate_duplicate&post=' . absint( $post->ID ) ),
					'nova_media_mate_duplicate_' . absint( $post->ID ),
					'nova_media_mate_nonce'
				);
				?>
				<div class="misc-pub-section misc-pub-nova-media-mate">
					<strong><?php esc_html_e( 'Nova Media Mate', 'nova-media-mate' ); ?>:</strong>
					<a href="<?php echo esc_url( $duplicate_url ); ?>" class="button-secondary alignright" title="<?php esc_attr_e( 'Duplicate this media item', 'nova-media-mate' ); ?>">
						<?php esc_html_e( 'Duplicate Media', 'nova-media-mate' ); ?>
					</a>
					<style>
						/* Critical Rule: PHP ONLY - Inline CSS for styling the button. */
						.misc-pub-nova-media-mate .alignright {
							margin-top: -3px; /* Adjust vertical alignment for the button */
						}
					</style>
				</div>
				<?php
			}
		}

		/**
		 * Handles the actual media duplication process when the duplicate link is clicked.
		 *
		 * This function performs security checks, duplicates the media file, creates
		 * a new attachment post, and updates its metadata.
		 */
		public function handle_media_duplication_action() {
			$original_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
			$nonce       = isset( $_GET['nova_media_mate_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nova_media_mate_nonce'] ) ) : '';

			// Step 1: Security checks - nonce verification and post ID validation.
			if ( empty( $original_id ) || ! wp_verify_nonce( $nonce, 'nova_media_mate_duplicate_' . $original_id ) ) {
				wp_die( esc_html__( 'Security check failed or no media item specified.', 'nova-media-mate' ) );
			}

			// Step 2: Capability check - ensure the user has permission to upload and edit media.
			if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $original_id ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to duplicate media items.', 'nova-media-mate' ) );
			}

			// Step 3: Retrieve original media item data.
			$original_post = get_post( $original_id );
			if ( ! $original_post || 'attachment' !== $original_post->post_type ) {
				$this->redirect_with_notice( 'error', esc_html__( 'Original media item not found or is not an attachment.', 'nova-media-mate' ) );
				exit;
			}

			$original_file_path = get_attached_file( $original_id );
			if ( ! file_exists( $original_file_path ) ) {
				$this->redirect_with_notice( 'error', esc_html__( 'Original media file does not exist on the server.', 'nova-media-mate' ) );
				exit;
			}

			// Step 4: Prepare arguments for the new attachment post.
			$new_post_args = array(
				'post_title'     => sprintf( /* translators: %s: Original media title */ esc_html__( 'Copy of %s', 'nova-media-mate' ), $original_post->post_title ),
				'post_status'    => 'inherit', // Attachments usually have 'inherit' status.
				'post_type'      => 'attachment',
				'post_parent'    => $original_post->post_parent, // Keep same parent if any.
				'post_mime_type' => $original_post->post_mime_type,
				'guid'           => '', // GUID will be updated after file copy.
			);

			// Step 5: Insert the new attachment post into the database.
			$new_id = wp_insert_post( $new_post_args, true );
			if ( is_wp_error( $new_id ) ) {
				$this->redirect_with_notice( 'error', sprintf( esc_html__( 'Failed to create new attachment post: %s', 'nova-media-mate' ), $new_id->get_error_message() ) );
				exit;
			}

			// Step 6: Handle file duplication.
			$upload_dir_info = wp_upload_dir(); // Get WordPress upload directory info.
			$path_parts      = pathinfo( $original_file_path );
			$filename        = $path_parts['filename'];
			$extension       = $path_parts['extension'];

			// Generate a unique file name for the duplicated file (e.g., 'image-copy.jpg', 'image-copy-1.jpg').
			$new_unique_filename = wp_unique_filename( $upload_dir_info['path'], $filename . '-copy.' . $extension );
			$new_file_path       = $upload_dir_info['path'] . DIRECTORY_SEPARATOR . $new_unique_filename;

			// Copy the original file to its new unique location.
			if ( ! copy( $original_file_path, $new_file_path ) ) {
				wp_delete_post( $new_id, true ); // Clean up the newly created post if file copy fails.
				$this->redirect_with_notice( 'error', esc_html__( 'Failed to copy the media file.', 'nova-media-mate' ) );
				exit;
			}

			// Step 7: Update post meta for the new attachment with its file path.
			// '_wp_attached_file' meta stores the path relative to the uploads base directory.
			update_post_meta( $new_id, '_wp_attached_file', _wp_relative_upload_path( $new_file_path ) );

			// Step 8: Generate and update attachment metadata.
			// This is crucial for images to generate thumbnails and store image dimensions,
			// and for other media types to store relevant metadata.
			// These files provide necessary functions like wp_generate_attachment_metadata.
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
			require_once( ABSPATH . 'wp-admin/includes/media.php' );

			$new_meta = wp_generate_attachment_metadata( $new_id, $new_file_path );
			if ( ! empty( $new_meta ) ) {
				wp_update_attachment_metadata( $new_id, $new_meta );
			} else {
				// If wp_generate_attachment_metadata returns empty (e.g., for some non-processable files),
				// we proceed without specific metadata. The _wp_attached_file is the main required meta.
				// No complex manual metadata copying/path adjusting here for simplicity and robustness.
			}

			// Step 9: Update the GUID for the new attachment post.
			// The GUID should reflect the URL of the newly copied file.
			global $wpdb;
			$wpdb->update(
				$wpdb->posts,
				array( 'guid' => $upload_dir_info['url'] . '/' . $new_unique_filename ),
				array( 'ID' => $new_id )
			);

			// Step 10: Success! Redirect to the edit screen of the new attachment with a success message.
			$this->redirect_with_notice( 'success', esc_html__( 'Media item duplicated successfully!', 'nova-media-mate' ), admin_url( 'post.php?post=' . $new_id . '&action=edit' ) );
			exit;
		}

		/**
		 * Redirects the user with an admin notice (success or error).
		 * Stores the notice in a transient to display after redirection.
		 *
		 * @param string $type         Type of notice ('success' or 'error').
		 * @param string $message      The message to display to the user.
		 * @param string $redirect_url Optional. The URL to redirect to. Defaults to the Media Library.
		 */
		private function redirect_with_notice( $type, $message, $redirect_url = '' ) {
			// Add the notice to WordPress's settings errors system.
			add_settings_error(
				'nova_media_mate_messages', // Unique setting ID for our notices.
				'nova_media_mate_' . $type, // Unique code for this specific error/message.
				$message,                   // The message content.
				$type                       // Type of notice (e.g., 'success', 'error').
			);
			// Store all current settings errors in a transient to retrieve after page reload/redirect.
			set_transient( 'settings_errors', get_settings_errors(), 30 ); // Keep for 30 seconds.

			// Determine the redirection URL.
			$fallback_url = admin_url( 'upload.php' ); // Default to Media Library.
			$redirect     = ! empty( $redirect_url ) ? $redirect_url : $fallback_url;

			// Perform a safe redirect.
			wp_safe_redirect( $redirect );
			exit; // Always exit after a redirect.
		}

		/**
		 * Displays admin notices that were set via `redirect_with_notice`.
		 * This function is hooked to 'admin_notices'.
		 */
		public function display_admin_notices() {
			// Retrieve any stored settings errors from the transient.
			if ( $errors = get_transient( 'settings_errors' ) ) {
				foreach ( (array) $errors as $error ) {
					// Check if the error belongs to our plugin's notice group.
					if ( 'nova_media_mate_messages' === $error['setting'] ) {
						// Determine the CSS class for the notice based on its type.
						$class = 'notice notice-' . $error['type'] . ' is-dismissible';
						// Output the notice.
						printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $error['message'] ) );
					}
				}
				// Delete the transient after displaying notices to prevent them from showing again.
				delete_transient( 'settings_errors' );
			}
		}

	} // End class Nova_Media_Mate.

	// Instantiate the plugin class on the 'plugins_loaded' action.
	// This ensures all WordPress functions are available.
	add_action( 'plugins_loaded', function() {
		new Nova_Media_Mate();
	} );

} // End if ( ! class_exists( 'Nova_Media_Mate' ) ).