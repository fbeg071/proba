<?php
/**
 * Plugin Name: PROZONE Media Replace
 * Description: Zamjenjuje fizički fajl u uploads folderu novim fajlom ISTOG imena (URL ostaje isti). Alati → PROZONE Media Replace.
 * Version: 1.0.0
 * Requires PHP: 7.2
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_management_page( 'PROZONE Media Replace', 'PROZONE Media Replace', 'upload_files', 'prozone-media-replace', 'pzrf_page' );
} );

/** Nađi sve fajlove u uploads folderu s datim imenom. */
function pzrf_find( $basename, $base ) {
	$found = array();
	$it    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $it as $f ) {
		if ( $f->getFilename() === $basename && strpos( $f->getPathname(), 'pz-replace-backup' ) === false ) {
			$found[] = $f->getPathname();
		}
	}
	return $found;
}

function pzrf_page() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( 'Nemate dozvolu.' );
	}
	$log = array();

	if ( isset( $_POST['pzrf_go'] ) && check_admin_referer( 'pzrf_replace', 'pzrf_nonce' ) && ! empty( $_FILES['pzrf_files'] ) ) {
		$up   = wp_upload_dir();
		$base = wp_normalize_path( $up['basedir'] );
		$bk   = $base . '/pz-replace-backup/' . gmdate( 'Ymd-His' );
		$f    = $_FILES['pzrf_files'];
		$allowed = array( 'webp', 'jpg', 'jpeg', 'png', 'gif', 'avif' );

		foreach ( (array) $f['name'] as $i => $name ) {
			$name = sanitize_file_name( wp_unslash( $name ) );
			if ( '' === $name ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== (int) $f['error'][ $i ] || ! is_uploaded_file( $f['tmp_name'][ $i ] ) ) {
				$log[] = array( 'err', $name . ': upload nije uspio.' );
				continue;
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed, true ) || ! @getimagesize( $f['tmp_name'][ $i ] ) ) {
				$log[] = array( 'err', $name . ': nije dozvoljena slika.' );
				continue;
			}
			$matches = pzrf_find( $name, $base );
			if ( 0 === count( $matches ) ) {
				$log[] = array( 'err', $name . ': ne postoji u uploads folderu (preskočeno).' );
				continue;
			}
			if ( count( $matches ) > 1 ) {
				$log[] = array( 'err', $name . ': pronađeno ' . count( $matches ) . ' fajlova s istim imenom, preskočeno: ' . implode( ', ', array_map( 'basename', array_map( 'dirname', $matches ) ) ) );
				continue;
			}
			$target = wp_normalize_path( $matches[0] );
			if ( 0 !== strpos( $target, $base . '/' ) ) {
				continue;
			}
			wp_mkdir_p( $bk );
			$old = filesize( $target );
			copy( $target, $bk . '/' . str_replace( '/', '_', substr( $target, strlen( $base ) + 1 ) ) );
			if ( move_uploaded_file( $f['tmp_name'][ $i ], $target ) ) {
				@chmod( $target, 0644 );
				$log[] = array( 'ok', sprintf( '%s zamijenjen (%s KB → %s KB), %s', $name, round( $old / 1024, 1 ), round( filesize( $target ) / 1024, 1 ), substr( $target, strlen( $base ) + 1 ) ) );
			} else {
				$log[] = array( 'err', $name . ': zamjena nije uspjela (dozvole?).' );
			}
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_purge_all' );
		}
	}
	?>
	<div class="wrap">
		<h1>PROZONE Media Replace</h1>
		<p>Odaberite jedan ili više fajlova. Svaki se upari s fajlom <b>istog imena</b> u uploads folderu i prepiše ga (URL ostaje isti). Stari fajl se sprema u <code>uploads/pz-replace-backup/</code>. Nakon zamjene poziva se LiteSpeed Purge All, ako je plugin aktivan.</p>
		<?php foreach ( $log as $l ) : ?>
			<div class="notice notice-<?php echo 'ok' === $l[0] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $l[1] ); ?></p></div>
		<?php endforeach; ?>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'pzrf_replace', 'pzrf_nonce' ); ?>
			<input type="file" name="pzrf_files[]" multiple accept="image/*" required>
			<?php submit_button( 'Zamijeni', 'primary', 'pzrf_go' ); ?>
		</form>
	</div>
	<?php
}
