<?php

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';

if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

if ( ! class_exists( 'FP_CLI' ) ) {
	return;
}

FP_CLI::add_command( 'super-cache', 'FP_Super_Cache_Command' );
