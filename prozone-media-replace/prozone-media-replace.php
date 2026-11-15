<?php
/**
 * Plugin Name: PROZONE Media Replace
 * Description: Zamjenjuje fizički fajl u uploads folderu novim fajlom ISTOG imena (URL ostaje isti). Uz backup, vraćanje originala, brisanje backupa, LiteSpeed Purge All i pretragu neiskorištenih slika (karantena). Alati → PROZONE Media Replace.
 * Version: 1.2.0
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

/* ------------------------------------------------------------------
 * Neiskorištene slike: skeniranje, karantena, vraćanje, trajno brisanje
 * ------------------------------------------------------------------ */

function pzmr_opt_set( $k, $v ) {
	delete_option( $k );
	add_option( $k, $v, '', 'no' );
}

/** Normalizira putanju slike na "stem" (bez ekstenzije, -WxH, -scaled, -eNNN). */
function pzmr_stem( $rel ) {
	$rel = strtolower( rawurldecode( (string) $rel ) );
	$dir = dirname( $rel );
	$f   = basename( $rel );
	do {
		$prev = $f;
		$f    = preg_replace( '/\.(jpe?g|png|gif|webp|avif|svg|bmp)$/', '', $f );
		$f    = preg_replace( '/-\d+x\d+$/', '', $f );
		$f    = preg_replace( '/-scaled$/', '', $f );
		$f    = preg_replace( '/-e\d{10,}$/', '', $f );
	} while ( $f !== $prev );
	return ( '.' === $dir ? '' : $dir . '/' ) . $f;
}

function pzmr_addids( $str, &$ids ) {
	if ( preg_match_all( '/\d+/', (string) $str, $m ) ) {
		foreach ( $m[0] as $n ) {
			$ids[ (int) $n ] = 1;
		}
	}
}

/** Iz teksta izvuci upload URL-ove (stemove) i ID-eve priloga. */
function pzmr_extract( $text, &$stems, &$ids ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return;
	}
	$text = str_replace( '\\/', '/', $text );
	if ( false !== stripos( $text, 'uploads/' ) && preg_match_all( '#uploads/((?:\d{4}/\d{2}/)?[^/"\'\s)\\\\,?<>;|]+\.(?:jpe?g|png|gif|webp|avif|svg))#i', $text, $m ) ) {
		foreach ( $m[1] as $u ) {
			$stems[ pzmr_stem( $u ) ] = 1;
		}
	}
	if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
		pzmr_addids( implode( ',', $m[1] ), $ids );
	}
	if ( preg_match_all( '/[a-z_\-]*(?:img|image|bg|ids?|logo|thumb|gallery|photo|icon|background)[a-z_\-]*\s*=\s*["\']([\d,\s]+)["\']/i', $text, $m ) ) {
		pzmr_addids( implode( ',', $m[1] ), $ids );
	}
	if ( preg_match_all( '/"(?:id|ids|mediaId|imageId|attachmentId|attachment_id)"\s*:\s*\[?\s*([\d,\s]+)/i', $text, $m ) ) {
		pzmr_addids( implode( ',', $m[1] ), $ids );
	}
}

/** Jedan korak skeniranja korištenja slika u bazi. */
function pzmr_scan_step( $reset ) {
	global $wpdb;
	$state = get_option( 'pzmr_scan_state' );
	if ( $reset || ! is_array( $state ) ) {
		$state = array( 'phase' => 'posts', 'last' => 0, 'stems' => array(), 'ids' => array(), 'rows' => 0 );
	}
	$phase = $state['phase'];
	$last  = (int) $state['last'];
	$rows  = array();

	if ( 'posts' === $phase ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID AS pk, post_content AS a, post_excerpt AS b FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' ORDER BY ID LIMIT 150", $last ) );
		foreach ( $rows as $r ) {
			pzmr_extract( $r->a, $state['stems'], $state['ids'] );
			pzmr_extract( $r->b, $state['stems'], $state['ids'] );
		}
	} elseif ( 'postmeta' === $phase ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id AS pk, meta_key AS k, meta_value AS a FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_edit_lock','_edit_last','_price','_regular_price','_sale_price','_stock','total_sales') ORDER BY meta_id LIMIT 500", $last ) );
		foreach ( $rows as $r ) {
			pzmr_extract( $r->a, $state['stems'], $state['ids'] );
			if ( preg_match( '/^[\d,\s]+$/', (string) $r->a ) ) {
				pzmr_addids( $r->a, $state['ids'] );
			}
		}
	} elseif ( 'options' === $phase ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id AS pk, option_name AS k, option_value AS a FROM {$wpdb->options} WHERE option_id > %d AND option_name NOT LIKE '\\_transient%%' AND option_name NOT LIKE '\\_site\\_transient%%' AND option_name NOT LIKE 'pzmr\\_%%' ORDER BY option_id LIMIT 200", $last ) );
		foreach ( $rows as $r ) {
			pzmr_extract( $r->a, $state['stems'], $state['ids'] );
			if ( in_array( $r->k, array( 'site_icon', 'site_logo', 'woocommerce_placeholder_image' ), true ) ) {
				pzmr_addids( $r->a, $state['ids'] );
			} elseif ( 0 === strpos( $r->k, 'theme_mods' ) || 0 === strpos( $r->k, 'widget_' ) ) {
				if ( preg_match_all( '/(?:i:(\d+);|s:\d+:"(\d+)";)/', (string) $r->a, $m ) ) {
					pzmr_addids( implode( ',', array_merge( $m[1], $m[2] ) ), $state['ids'] );
				}
			}
		}
	} elseif ( 'termmeta' === $phase ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id AS pk, meta_value AS a FROM {$wpdb->termmeta} WHERE meta_id > %d ORDER BY meta_id LIMIT 500", $last ) );
		foreach ( $rows as $r ) {
			pzmr_extract( $r->a, $state['stems'], $state['ids'] );
			if ( preg_match( '/^[\d,\s]+$/', (string) $r->a ) ) {
				pzmr_addids( $r->a, $state['ids'] );
			}
		}
	} elseif ( 'terms' === $phase ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id AS pk, description AS a FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d ORDER BY term_taxonomy_id LIMIT 500", $last ) );
		foreach ( $rows as $r ) {
			pzmr_extract( $r->a, $state['stems'], $state['ids'] );
		}
	}

	$state['rows'] += count( $rows );
	if ( $rows ) {
		$state['last'] = (int) end( $rows )->pk;
		pzmr_opt_set( 'pzmr_scan_state', $state );
		return array( 'done' => false, 'msg' => sprintf( 'Skeniram bazu (%s): %d redova…', $phase, $state['rows'] ) );
	}
	$order = array( 'posts', 'postmeta', 'options', 'termmeta', 'terms', 'compute' );
	$next  = $order[ array_search( $phase, $order, true ) + 1 ];
	if ( 'compute' !== $next ) {
		$state['phase'] = $next;
		$state['last']  = 0;
		pzmr_opt_set( 'pzmr_scan_state', $state );
		return array( 'done' => false, 'msg' => 'Skeniram bazu (' . $next . ')…' );
	}
	$n = pzmr_compute( $state );
	delete_option( 'pzmr_scan_state' );
	return array( 'done' => true, 'msg' => 'Gotovo. Neiskorištenih grupa: ' . $n );
}

/** Prođi uploads folder i usporedi s skupom korištenih slika. */
function pzmr_compute( $state ) {
	global $wpdb;
	$base  = pzmr_base();
	$idmap = array();
	foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" ) as $r ) {
		$idmap[ pzmr_stem( $r->meta_value ) ] = (int) $r->post_id;
	}
	$dirs   = (array) glob( $base . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]', GLOB_ONLYDIR );
	$dirs[] = $base;
	$groups = array();
	foreach ( $dirs as $dir ) {
		foreach ( new DirectoryIterator( $dir ) as $f ) {
			if ( ! $f->isFile() || ! preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $f->getFilename() ) ) {
				continue;
			}
			$rel  = ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( $base ) ), '/' );
			$stem = pzmr_stem( $rel );
			if ( ! isset( $groups[ $stem ] ) ) {
				$groups[ $stem ] = array( 'files' => array(), 'bytes' => 0, 'm' => 0 );
			}
			$groups[ $stem ]['files'][] = $rel;
			$groups[ $stem ]['bytes']  += $f->getSize();
			$groups[ $stem ]['m']       = max( $groups[ $stem ]['m'], $f->getMTime() );
		}
	}
	$unused = array();
	foreach ( $groups as $stem => $g ) {
		$id = isset( $idmap[ $stem ] ) ? $idmap[ $stem ] : 0;
		if ( isset( $state['stems'][ $stem ] ) || ( $id && isset( $state['ids'][ $id ] ) ) ) {
			continue;
		}
		$g['id']          = $id;
		$unused[ $stem ] = $g;
	}
	uasort( $unused, function ( $a, $b ) {
		return $b['bytes'] - $a['bytes'];
	} );
	pzmr_opt_set( 'pzmr_unused', array( 'time' => time(), 'total' => count( $groups ), 'groups' => $unused ) );
	return count( $unused );
}

add_action( 'wp_ajax_pzmr_scan', function () {
	check_ajax_referer( 'pzmr_scan', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemate dozvolu.' );
	}
	@set_time_limit( 120 );
	wp_send_json_success( pzmr_scan_step( ! empty( $_POST['reset'] ) ) );
} );

/** Dodatna provjera neposredno prije karantene (LIKE po bazi). */
function pzmr_quick_used( $stem ) {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( $stem ) . '%';
	$q    = array(
		"SELECT 1 FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND (post_content LIKE %s OR post_excerpt LIKE %s) LIMIT 1",
		"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes') LIMIT 1",
		"SELECT 1 FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE 'pzmr\\_%%' AND option_name NOT LIKE '\\_transient%%' LIMIT 1",
		"SELECT 1 FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT 1",
	);
	foreach ( $q as $sql ) {
		$args = ( false !== strpos( $sql, 'post_excerpt LIKE' ) ) ? array( $like, $like ) : array( $like );
		if ( $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) ) {
			return true;
		}
	}
	return false;
}

/** Karantena: id => array(batch, tfile, rel, aid, size). id = batch|tfile */
function pzmr_trash() {
	$out  = array();
	$root = pzmr_base() . '/pz-unused-trash';
	if ( ! is_dir( $root ) ) {
		return $out;
	}
	$batches = glob( $root . '/*', GLOB_ONLYDIR );
	rsort( $batches );
	foreach ( $batches as $dir ) {
		$batch = basename( $dir );
		$mf    = $dir . '/manifest.json';
		$map   = is_file( $mf ) ? json_decode( file_get_contents( $mf ), true ) : array();
		foreach ( (array) $map as $tfile => $e ) {
			if ( is_array( $e ) && isset( $e['rel'] ) && is_file( $dir . '/' . $tfile ) ) {
				$out[ $batch . '|' . $tfile ] = array( $batch, $tfile, $e['rel'], (int) $e['aid'], filesize( $dir . '/' . $tfile ) );
			}
		}
	}
	return $out;
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

		// 4) Neiskorištene slike → karantena; karantena → vrati / trajno obriši.
		if ( in_array( $do, array( 'quarantine', 'trash_restore', 'trash_delete' ), true ) && current_user_can( 'manage_options' ) ) {
			$sel   = isset( $_POST['pzmr_sel'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['pzmr_sel'] ) ) : array();
			$trash = $base . '/pz-unused-trash';
			$moved = 0;
			if ( ! $sel ) {
				$log[] = array( 'err', 'Ništa nije označeno.' );
			}
			if ( 'quarantine' === $do ) {
				$res = get_option( 'pzmr_unused' );
				$bk  = $trash . '/' . gmdate( 'Ymd-His' );
				foreach ( $sel as $stem ) {
					if ( ! is_array( $res ) || ! isset( $res['groups'][ $stem ] ) ) {
						continue; // samo grupe iz rezultata skeniranja, nikad sirove putanje.
					}
					$g = $res['groups'][ $stem ];
					if ( pzmr_quick_used( $stem ) ) {
						$log[] = array( 'err', $stem . ': nađena referenca u bazi, preskočeno.' );
						continue;
					}
					wp_mkdir_p( $bk );
					foreach ( $g['files'] as $rel ) {
						$src = wp_normalize_path( $base . '/' . $rel );
						if ( false !== strpos( $rel, '..' ) || 0 !== strpos( $src, $base . '/' ) || ! is_file( $src ) ) {
							continue;
						}
						$tfile = str_replace( '/', '_', $rel );
						if ( @rename( $src, $bk . '/' . $tfile ) ) {
							pzmr_save_manifest( $bk, $tfile, array( 'rel' => $rel, 'aid' => (int) $g['id'] ) );
							++$moved;
						}
					}
					unset( $res['groups'][ $stem ] );
					$log[] = array( 'ok', $stem . ': premješteno u karantenu (' . count( $g['files'] ) . ' fajl.).' );
				}
				if ( is_array( $res ) ) {
					pzmr_opt_set( 'pzmr_unused', $res );
				}
			} else {
				$all  = pzmr_trash();
				$aids = array();
				foreach ( $sel as $id ) {
					if ( ! isset( $all[ $id ] ) ) {
						continue;
					}
					list( $batch, $tfile, $rel, $aid ) = $all[ $id ];
					$src    = $trash . '/' . $batch . '/' . $tfile;
					$target = wp_normalize_path( $base . '/' . $rel );
					if ( false !== strpos( $rel, '..' ) || 0 !== strpos( $target, $base . '/' ) ) {
						continue;
					}
					if ( 'trash_restore' === $do ) {
						if ( file_exists( $target ) ) {
							$log[] = array( 'err', $rel . ': već postoji, preskočeno.' );
						} elseif ( @rename( $src, $target ) ) {
							pzmr_remove_manifest_entry( $trash . '/' . $batch, $tfile );
							++$moved;
							$log[] = array( 'ok', $rel . ' vraćen.' );
						}
					} elseif ( @unlink( $src ) ) {
						pzmr_remove_manifest_entry( $trash . '/' . $batch, $tfile );
						++$moved;
						if ( $aid ) {
							$aids[ $aid ] = 1;
						}
						$log[] = array( 'ok', $rel . ' trajno obrisan.' );
					}
				}
				if ( 'trash_delete' === $do && $aids ) {
					$left = array();
					foreach ( pzmr_trash() as $t ) {
						$left[ $t[3] ] = 1;
					}
					foreach ( array_keys( $aids ) as $aid ) {
						if ( ! isset( $left[ $aid ] ) && 'attachment' === get_post_type( $aid ) ) {
							wp_delete_attachment( $aid, true );
							$log[] = array( 'ok', 'Prilog #' . $aid . ' uklonjen iz Media Library.' );
						}
					}
				}
			}
			if ( $moved && ! empty( $_POST['pzmr_purge_after'] ) ) {
				$log[] = pzmr_purge() ? array( 'ok', 'LiteSpeed Purge All izvršen.' ) : array( 'err', 'LiteSpeed Cache nije aktivan.' );
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

		<?php if ( current_user_can( 'manage_options' ) ) :
			$unused = get_option( 'pzmr_unused' );
			$trash  = pzmr_trash();
			$upurl  = wp_upload_dir();
			$upurl  = $upurl['baseurl'];
			?>
			<h2>4. Neiskorištene slike</h2>
			<p>Skenira bazu (sadržaj stranica, UX Builder, meta, opcije teme/widgeta, kategorije) i traži slike u <code>uploads/GGGG/MM</code> koje se nigdje ne spominju. Slike se <b>ne brišu</b>, nego idu u karantenu iz koje ih možete vratiti. Rezultat je procjena: obavezno pregledajte listu.</p>
			<p>
				<button type="button" id="pzmr-scan" class="button button-primary" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pzmr_scan' ) ); ?>">Pokreni skeniranje</button>
				<span id="pzmr-scan-msg" style="margin-left:10px"></span>
			</p>
			<?php if ( is_array( $unused ) ) :
				$tot = 0;
				foreach ( $unused['groups'] as $g ) {
					$tot += $g['bytes'];
				}
				?>
				<p><b><?php echo esc_html( count( $unused['groups'] ) ); ?></b> neiskorištenih grupa od <?php echo esc_html( $unused['total'] ); ?> (ukupno <?php echo esc_html( round( $tot / 1048576, 1 ) ); ?> MB). Skenirano: <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $unused['time'] ), 'd.m.Y H:i' ) ); ?></p>
				<?php if ( $unused['groups'] ) : ?>
					<form method="post">
						<?php wp_nonce_field( 'pzmr_action', 'pzmr_nonce' ); ?>
						<table class="widefat striped" style="max-width:1000px">
							<thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.pzmr-ub').forEach(c=>c.checked=this.checked)"></td><th>Slika</th><th>Naziv</th><th>Fajlova</th><th>KB</th><th>Media Library</th><th>Zadnja izmjena</th></tr></thead>
							<tbody>
							<?php
							$shown = 0;
							foreach ( $unused['groups'] as $stem => $g ) :
								if ( ++$shown > 500 ) {
									break;
								}
								$thumb = $g['files'][0];
								?>
								<tr>
									<th class="check-column"><input type="checkbox" class="pzmr-ub" name="pzmr_sel[]" value="<?php echo esc_attr( $stem ); ?>"></th>
									<td><img src="<?php echo esc_url( $upurl . '/' . $thumb ); ?>" loading="lazy" alt="" style="width:56px;height:56px;object-fit:cover"></td>
									<td><?php echo esc_html( $stem ); ?></td>
									<td><?php echo esc_html( count( $g['files'] ) ); ?></td>
									<td><?php echo esc_html( round( $g['bytes'] / 1024 ) ); ?></td>
									<td><?php echo $g['id'] ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $g['id'] . '&action=edit' ) ) . '">#' . (int) $g['id'] . '</a>' : 'nije u biblioteci'; ?></td>
									<td><?php echo esc_html( gmdate( 'd.m.Y', $g['m'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( count( $unused['groups'] ) > 500 ) : ?>
							<p class="description">Prikazano prvih 500 (po veličini). Nakon karantene pokrenite skeniranje ponovo.</p>
						<?php endif; ?>
						<p><label><input type="checkbox" name="pzmr_purge_after" value="1" checked> Poslije izvrši LiteSpeed Purge All</label></p>
						<p><button type="submit" name="pzmr_do" value="quarantine" class="button button-primary" onclick="return confirm('Premjestiti označene u karantenu?');">Premjesti označene u karantenu</button></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>

			<h2>5. Karantena</h2>
			<?php if ( ! $trash ) : ?>
				<p>Karantena je prazna.</p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'pzmr_action', 'pzmr_nonce' ); ?>
					<table class="widefat striped" style="max-width:900px">
						<thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.pzmr-tb').forEach(c=>c.checked=this.checked)"></td><th>Fajl</th><th>KB</th><th>Karantena (UTC)</th></tr></thead>
						<tbody>
						<?php foreach ( $trash as $id => $t ) : ?>
							<tr>
								<th class="check-column"><input type="checkbox" class="pzmr-tb" name="pzmr_sel[]" value="<?php echo esc_attr( $id ); ?>"></th>
								<td><?php echo esc_html( $t[2] ); ?></td>
								<td><?php echo esc_html( round( $t[4] / 1024, 1 ) ); ?></td>
								<td><?php echo esc_html( $t[0] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p><label><input type="checkbox" name="pzmr_purge_after" value="1" checked> Poslije izvrši LiteSpeed Purge All</label></p>
					<p>
						<button type="submit" name="pzmr_do" value="trash_restore" class="button button-primary">Vrati označene</button>
						<button type="submit" name="pzmr_do" value="trash_delete" class="button" onclick="return confirm('TRAJNO obrisati označene fajlove (i prilog iz Media Library kad su svi fajlovi obrisani)? Ovo se ne može poništiti.');">Trajno obriši označene</button>
					</p>
				</form>
			<?php endif; ?>

			<script>
			(function () {
				var b = document.getElementById('pzmr-scan'), m = document.getElementById('pzmr-scan-msg');
				if (!b) { return; }
				function step(reset) {
					var fd = new FormData();
					fd.append('action', 'pzmr_scan'); fd.append('nonce', b.dataset.nonce);
					if (reset) { fd.append('reset', '1'); }
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (j) {
							if (!j.success) { m.textContent = 'Greška: ' + (j.data || ''); b.disabled = false; return; }
							m.textContent = j.data.msg;
							if (j.data.done) { location.reload(); } else { step(false); }
						})
						.catch(function (e) { m.textContent = 'Greška: ' + e; b.disabled = false; });
				}
				b.addEventListener('click', function () { b.disabled = true; m.textContent = 'Počinjem…'; step(true); });
			})();
			</script>
		<?php endif; ?>
	</div>
	<?php
}
