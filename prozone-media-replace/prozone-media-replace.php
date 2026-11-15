<?php
/**
 * Plugin Name: PROZONE Media Replace
 * Description: Zamjenjuje fizički fajl u uploads folderu novim fajlom ISTOG imena (URL ostaje isti). Uz backup, vraćanje originala, brisanje backupa i LiteSpeed Purge All. Alati → PROZONE Media Replace.
 * Version: 1.1.0
 * Requires PHP: 7.2
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_management_page( 'PROZONE Media Replace', 'PROZONE Media Replace', 'upload_files', 'prozone-media-replace', 'pzmr_page' );
} );

function pzmr_base() {
	$up = wp_upload_dir();
	return wp_normalize_path( $up['basedir'] );
}

function pzmr_purge() {
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
		return true;
	}
	return false;
}

/** Nađi sve fajlove u uploads folderu s datim imenom (bez backup foldera). */
function pzmr_find( $basename, $base ) {
	$found = array();
	$it    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $it as $f ) {
		$p = wp_normalize_path( $f->getPathname() );
		if ( $f->getFilename() === $basename && false === strpos( $p, '/pz-replace-backup/' ) ) {
			$found[] = $p;
		}
	}
	return $found;
}

/** Svi backupi: array( id => array(batch, bfile, rel, size) ). id = batch|bfile */
function pzmr_backups() {
	$out  = array();
	$root = pzmr_base() . '/pz-replace-backup';
	if ( ! is_dir( $root ) ) {
		return $out;
	}
	$batches = glob( $root . '/*', GLOB_ONLYDIR );
	rsort( $batches );
	foreach ( $batches as $dir ) {
		$batch = basename( $dir );
		$mf    = $dir . '/manifest.json';
		$map   = is_file( $mf ) ? json_decode( file_get_contents( $mf ), true ) : array();
		foreach ( (array) $map as $bfile => $rel ) {
			if ( is_file( $dir . '/' . $bfile ) ) {
				$out[ $batch . '|' . $bfile ] = array( $batch, $bfile, $rel, filesize( $dir . '/' . $bfile ) );
			}
		}
	}
	return $out;
}

function pzmr_save_manifest( $dir, $bfile, $rel ) {
	$mf  = $dir . '/manifest.json';
	$map = is_file( $mf ) ? json_decode( file_get_contents( $mf ), true ) : array();
	$map = is_array( $map ) ? $map : array();
	$map[ $bfile ] = $rel;
	file_put_contents( $mf, wp_json_encode( $map ) );
}

function pzmr_remove_manifest_entry( $dir, $bfile ) {
	$mf  = $dir . '/manifest.json';
	$map = is_file( $mf ) ? json_decode( file_get_contents( $mf ), true ) : array();
	unset( $map[ $bfile ] );
	if ( empty( $map ) ) {
		@unlink( $mf );
		@rmdir( $dir );
	} else {
		file_put_contents( $mf, wp_json_encode( $map ) );
	}
}

function pzmr_page() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( 'Nemate dozvolu.' );
	}
	$log  = array();
	$base = pzmr_base();
	$bkup = $base . '/pz-replace-backup';
	$nonce_ok = isset( $_POST['pzmr_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pzmr_nonce'] ) ), 'pzmr_action' );

	if ( $nonce_ok && isset( $_POST['pzmr_do'] ) ) {
		$do = sanitize_key( wp_unslash( $_POST['pzmr_do'] ) );

		// 1) Zamjena.
		if ( 'replace' === $do && ! empty( $_FILES['pzmr_files'] ) ) {
			$bk      = $bkup . '/' . gmdate( 'Ymd-His' );
			$f       = $_FILES['pzmr_files'];
			$allowed = array( 'webp', 'jpg', 'jpeg', 'png', 'gif', 'avif' );
			$did     = 0;
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
				$matches = pzmr_find( $name, $base );
				if ( 0 === count( $matches ) ) {
					$log[] = array( 'err', $name . ': ne postoji u uploads folderu (preskočeno).' );
					continue;
				}
				if ( count( $matches ) > 1 ) {
					$log[] = array( 'err', $name . ': pronađeno ' . count( $matches ) . ' fajlova s istim imenom, preskočeno.' );
					continue;
				}
				$target = $matches[0];
				if ( 0 !== strpos( $target, $base . '/' ) ) {
					continue;
				}
				$rel   = substr( $target, strlen( $base ) + 1 );
				$bfile = str_replace( '/', '_', $rel );
				wp_mkdir_p( $bk );
				$old = filesize( $target );
				copy( $target, $bk . '/' . $bfile );
				pzmr_save_manifest( $bk, $bfile, $rel );
				if ( move_uploaded_file( $f['tmp_name'][ $i ], $target ) ) {
					@chmod( $target, 0644 );
					++$did;
					$log[] = array( 'ok', sprintf( '%s zamijenjen (%s KB → %s KB)', $rel, round( $old / 1024, 1 ), round( filesize( $target ) / 1024, 1 ) ) );
				} else {
					$log[] = array( 'err', $name . ': zamjena nije uspjela (dozvole?).' );
				}
			}
			if ( $did && ! empty( $_POST['pzmr_purge_after'] ) ) {
				$log[] = pzmr_purge() ? array( 'ok', 'LiteSpeed Purge All izvršen.' ) : array( 'err', 'LiteSpeed Cache nije aktivan, purge preskočen.' );
			}
		}

		// 2) Purge all.
		if ( 'purge' === $do ) {
			$log[] = pzmr_purge() ? array( 'ok', 'LiteSpeed Purge All izvršen.' ) : array( 'err', 'LiteSpeed Cache nije aktivan.' );
		}

		// 3) Vrati / obriši backupe.
		if ( in_array( $do, array( 'restore', 'delete' ), true ) ) {
			$sel  = isset( $_POST['pzmr_sel'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['pzmr_sel'] ) ) : array();
			$all  = pzmr_backups();
			$done = 0;
			foreach ( $sel as $id ) {
				if ( ! isset( $all[ $id ] ) ) {
					continue; // samo id-evi iz manifesta, nikad sirove putanje.
				}
				list( $batch, $bfile, $rel ) = $all[ $id ];
				$src    = $bkup . '/' . $batch . '/' . $bfile;
				$target = wp_normalize_path( $base . '/' . $rel );
				if ( false !== strpos( $rel, '..' ) || 0 !== strpos( $target, $base . '/' ) ) {
					continue;
				}
				if ( 'restore' === $do ) {
					if ( copy( $src, $target ) ) {
						++$done;
						$log[] = array( 'ok', $rel . ' vraćen na original.' );
					} else {
						$log[] = array( 'err', $rel . ': vraćanje nije uspjelo.' );
					}
				} else {
					if ( @unlink( $src ) ) {
						pzmr_remove_manifest_entry( $bkup . '/' . $batch, $bfile );
						++$done;
						$log[] = array( 'ok', 'Backup obrisan: ' . $rel . ' (' . $batch . ')' );
					}
				}
			}
			if ( 'restore' === $do && $done && ! empty( $_POST['pzmr_purge_after'] ) ) {
				$log[] = pzmr_purge() ? array( 'ok', 'LiteSpeed Purge All izvršen.' ) : array( 'err', 'LiteSpeed Cache nije aktivan.' );
			}
			if ( ! $sel ) {
				$log[] = array( 'err', 'Ništa nije označeno.' );
			}
		}
	}

	$backups = pzmr_backups();
	?>
	<div class="wrap">
		<h1>PROZONE Media Replace</h1>
		<?php foreach ( $log as $l ) : ?>
			<div class="notice notice-<?php echo 'ok' === $l[0] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $l[1] ); ?></p></div>
		<?php endforeach; ?>

		<h2>1. Zamjena slika</h2>
		<p>Svaki odabrani fajl se upari s fajlom <b>istog imena</b> u uploads folderu i prepiše ga (URL ostaje isti). Stari fajl se sprema kao backup.</p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'pzmr_action', 'pzmr_nonce' ); ?>
			<input type="hidden" name="pzmr_do" value="replace">
			<input type="file" name="pzmr_files[]" multiple accept="image/*" required>
			<p><label><input type="checkbox" name="pzmr_purge_after" value="1" checked> Poslije zamjene izvrši LiteSpeed Purge All</label></p>
			<?php submit_button( 'Zamijeni', 'primary', 'submit', false ); ?>
		</form>

		<h2>2. LiteSpeed cache</h2>
		<form method="post">
			<?php wp_nonce_field( 'pzmr_action', 'pzmr_nonce' ); ?>
			<input type="hidden" name="pzmr_do" value="purge">
			<?php submit_button( 'Purge All', 'secondary', 'submit', false ); ?>
		</form>

		<h2>3. Backupi originala</h2>
		<?php if ( ! $backups ) : ?>
			<p>Nema backupa.</p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'pzmr_action', 'pzmr_nonce' ); ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.pzmr-cb').forEach(c=>c.checked=this.checked)"></td><th>Fajl</th><th>Backup (KB)</th><th>Trenutno (KB)</th><th>Datum zamjene (UTC)</th></tr></thead>
					<tbody>
					<?php foreach ( $backups as $id => $b ) :
						$cur = is_file( $base . '/' . $b[2] ) ? round( filesize( $base . '/' . $b[2] ) / 1024, 1 ) : '—';
						?>
						<tr>
							<th class="check-column"><input type="checkbox" class="pzmr-cb" name="pzmr_sel[]" value="<?php echo esc_attr( $id ); ?>"></th>
							<td><?php echo esc_html( $b[2] ); ?></td>
							<td><?php echo esc_html( round( $b[3] / 1024, 1 ) ); ?></td>
							<td><?php echo esc_html( $cur ); ?></td>
							<td><?php echo esc_html( $b[0] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><label><input type="checkbox" name="pzmr_purge_after" value="1" checked> Poslije vraćanja izvrši LiteSpeed Purge All</label></p>
				<p>
					<button type="submit" name="pzmr_do" value="restore" class="button button-primary" onclick="return confirm('Vratiti označene slike na original?');">Vrati označene</button>
					<button type="submit" name="pzmr_do" value="delete" class="button" onclick="return confirm('Trajno obrisati označene BACKUPE? Slike na sajtu ostaju.');">Obriši označene backupe</button>
				</p>
			</form>
			<p class="description">„Obriši“ briše samo backup kopiju (oslobađa prostor); slika na sajtu se ne dira. Backupi su u <code>uploads/pz-replace-backup/</code>.</p>
		<?php endif; ?>
	</div>
	<?php
}
