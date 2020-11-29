<?php
// wp-content/rpep-uploads/artFiles/$orderID/$lineItemID/front
$upload_dir   = wp_get_upload_dir();
$rpep_path    = $upload_dir['basedir'] . '/rpep-uploads/artFiles';
$file_path    = $rpep_path . '/index.php';
$file_content = '<?php // Silence is golden.';

$create = false;

if ( wp_mkdir_p( $rpep_path ) && ! file_exists( $file_path ) ) {
  $create = true;
} else {
  $current_content = @file_get_contents( $file_path );

  if ( $current_content !== $file_content ) {
    unlink( $file_path );
    $create = true;
  }
}

if ( $create ) {
  $file_handle = @fopen( $file_path, 'wb' );
  if ( $file_handle ) {
    fwrite( $file_handle, $file_content );
    fclose( $file_handle );
  }
}