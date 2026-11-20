<?php
/**
 * Private file storage under wp-content/uploads/primeplaced-withdrawal-button.
 *
 * @package PrimePlaced\WithdrawalButton
 */

namespace PrimePlaced\WithdrawalButton\Pdf;

use RuntimeException;

/**
 * Class PrivateStorage
 *
 * Confirmation PDFs, proofs of shipment and labels. The directory denies direct
 * access on Apache and IIS (.htaccess, web.config) and has an empty index.php
 * against directory listing. Files get random 32-character names, so on servers
 * that ignore those rules (nginx) a file can only be reached by a URL that is
 * never published. They are served only through controllers that check
 * permissions or a signed link.
 */
final class PrivateStorage {

	public const DIR = 'primeplaced-withdrawal-button';

	/**
	 * Extensions accepted for storage. Nothing executable can be written.
	 */
	private const EXTENSIONS = array( 'pdf', 'jpg', 'jpeg', 'png' );

	/**
	 * Absolute directory path, created with its guards on first use.
	 *
	 * @throws RuntimeException When the directory cannot be created.
	 * @return string
	 */
	public function directory(): string {
		$dir = self::base_dir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( esc_html( 'Could not create the private upload directory.' ) );
		}

		$guards = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.php'  => '',
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $guards as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard files in our own uploads directory.
			}
		}

		return $dir;
	}

	/**
	 * Writes content to a new random file.
	 *
	 * @param string $content   File content.
	 * @param string $extension File extension without dot: pdf, jpg, jpeg or png.
	 * @throws RuntimeException When the extension is not allowed or writing fails.
	 * @return string Relative file name, stored in the database.
	 */
	public function put( string $content, string $extension ): string {
		$extension = strtolower( $extension );
		if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
			throw new RuntimeException( esc_html( 'This file type cannot be stored.' ) );
		}

		$name = wp_generate_password( 32, false ) . '.' . $extension;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private storage write in our own uploads directory.
		if ( false === file_put_contents( $this->directory() . '/' . $name, $content ) ) {
			throw new RuntimeException( esc_html( 'Could not write the private file.' ) );
		}

		return $name;
	}

	/**
	 * Absolute path of a stored file, or null when it is missing or unsafe.
	 *
	 * @param string $name Relative name from the database.
	 * @return string|null
	 */
	public function path( string $name ): ?string {
		if ( '' === $name || basename( $name ) !== $name || ! in_array( self::extension( $name ), self::EXTENSIONS, true ) ) {
			return null;
		}

		$path = $this->directory() . '/' . $name;

		return is_readable( $path ) ? $path : null;
	}

	/**
	 * Contents of a stored file, or null when missing.
	 *
	 * @param string $name Relative name.
	 * @return string|null
	 */
	public function read( string $name ): ?string {
		$path = $this->path( $name );
		if ( null === $path ) {
			return null;
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private storage read.

		return false === $content ? null : $content;
	}

	/**
	 * Extension of a stored file ("pdf" for "x.pdf").
	 *
	 * @param string $name Relative name.
	 * @return string
	 */
	public static function extension( string $name ): string {
		return strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	}

	/**
	 * Deletes a stored file.
	 *
	 * @param string $name Relative name.
	 * @return void
	 */
	public function delete( string $name ): void {
		$path = $this->path( $name );
		if ( null !== $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Removes every stored file and the directory (uninstall with data removal).
	 *
	 * @return void
	 */
	public function delete_all(): void {
		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		// scandir() also lists .htaccess; GLOB_BRACE does not exist on every system (musl).
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( is_string( $file ) && is_file( $dir . '/' . $file ) ) {
				wp_delete_file( $dir . '/' . $file );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Directory owned by the plugin.
	}

	/**
	 * Directory path resolved at runtime from wp_upload_dir(), without creating it.
	 *
	 * @return string
	 */
	public static function base_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return trailingslashit( (string) $uploads['basedir'] ) . self::DIR;
	}
}
