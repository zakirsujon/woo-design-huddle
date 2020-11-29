<?php
$order_items = $order->get_items( 'line_item' );

foreach( $order_items as $order_item ){
  $item_id     = $order_item->get_id();
  $item_name   = str_replace(' ', '_', $order_item->get_name());
  $project_id  = wc_get_order_item_meta($order_item->get_id(), '_dh_project_id');

  // get project pages
  $response = wp_remote_get(
    $this->settings['store_url'] .'/partners/api/projects/'. $project_id,
    [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Bearer '. $this->settings['token']['access_token']
      ]
    ]
  );

  $project = json_decode( wp_remote_retrieve_body( $response ), true );

  // bail if no project found
  if( empty($project['data']['pages']) ){
  	error_log('No project found! '. print_r($project, true) );
  	continue;
  }


  // request an export job
	foreach( $project['data']['pages'] as $num => $page ){
    $response = wp_remote_post(
      $this->settings['store_url'] .'/partners/api/projects/'. $project_id .'/export',
      [
        'headers' => [
          'Authorization' => 'Bearer '. $this->settings['token']['access_token']
        ],
        'body' => json_encode([
          'format' => 'pdf_flattened',
          'filename' => 'dh_'. $item_id .'_'. $item_name. '.pdf',
          'page_id' => $page['page_id']
        ])
      ]
    );

    $export = json_decode( wp_remote_retrieve_body( $response ), true );

    // bail if unable to set a job
	  if( empty($export['data']['job_id']) ){
	  	error_log('Failed to set an export job! '. print_r($export, true) );
	  	continue;
	  }


		// request an PDF download
	  do {
	    $response = wp_remote_get(
	      $this->settings['store_url'] .'/partners/api/projects/'. $project_id .'/export/jobs/' . $export['data']['job_id'],
	      [
	      	'timeout' => 20,
	        'headers' => [
	          'Authorization' => 'Bearer '. $this->settings['token']['access_token']
	        ]
	      ]
	    );

	    $download = json_decode( wp_remote_retrieve_body( $response ), true );

	    // set "recheck" flag to make sure file ready to download
		  if( empty($download['data']['completed']) ){
		  	$recheck = true;

		  	// bail if no progress
		  	if( empty($download['data']['progress_percentage']) ){
		  		error_log('Failed get the PDF file! '. print_r($download, true) );
		  		$recheck = false;
		  	}
		  } else {
		  	$recheck = false;
		  }

    } while ( $recheck );


    if( empty($download['data']['download_url']) ){
    	error_log('Failed get the PDF file! '. print_r($download, true) );
	  	continue;
    }

    $side = $num == 0 ? 'front' : 'back';
    
    $upload_dir = wp_get_upload_dir();
    $rpep_path  = $upload_dir['basedir'] . '/rpep-uploads/artFiles';
		$file_path  = $order_id .'/'. $item_id .'/'. $side;
		$filename  	= 'dh_'. $item_id .'_'. $item_name. '.pdf';
		$file  			= $rpep_path .'/'. $file_path .'/'. $filename;

    if( wp_mkdir_p( $rpep_path .'/'. $file_path ) ){
    	$status = file_put_contents($file, file_get_contents($download['data']['download_url']));
	    if( $status ){
	      wc_add_order_item_meta($item_id, '_dh_exported_file_'. $side, $file_path .'/'. $filename);
	    } else {
	      error_log('Failed to save file! '. $file);
	    }
    }

	}
}
