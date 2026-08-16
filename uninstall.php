<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Customer and payment records are intentionally preserved.
// Permanent data removal will be added later as an explicit opt-in setting.

// Connection secrets must not remain after the plugin itself is removed.
delete_option( 'afc_olt_settings' );
delete_option( 'afc_olt_last_status' );
delete_option( 'afc_olt_last_snapshot' );
delete_transient( 'afc_olt_rx_snapshot' );

