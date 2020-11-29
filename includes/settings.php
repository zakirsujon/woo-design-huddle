<?php
register_setting( $this->prefix, $this->prefix .'_settings' );

add_settings_section(
  $this->prefix .'_section',
  __( '', 'woo-design-huddle' ),
  [$this, 'section'],
  $this->prefix
);

add_settings_field(
  'store_url',
  __( 'Store URL', 'woo-design-huddle' ),
  [$this, 'field'],
  $this->prefix,
  $this->prefix .'_section',
  ['label_for' => 'store_url']
);

add_settings_field(
  'client_id',
  __( 'Client ID', 'woo-design-huddle' ),
  [$this, 'field'],
  $this->prefix,
  $this->prefix .'_section',
  ['label_for' => 'client_id']
);

add_settings_field(
  'client_secret',
  __( 'Client Secret', 'woo-design-huddle' ),
  [$this, 'field'],
  $this->prefix,
  $this->prefix .'_section',
  ['label_for' => 'client_secret']
);

add_settings_field(
  'access_token',
  __( 'Token', 'woo-design-huddle' ),
  [$this, 'access_token'],
  $this->prefix,
  $this->prefix .'_section'
);