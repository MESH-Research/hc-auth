<?php
/**
 * Customizations to buddypress register functionality
 *
 * @package Hc_Auth
 */

/**
 * Filter the register url to be society specific
 *
 * @since HCommons
 *
 * @param string $register_url url to sign up page.
 * @return string $register_url Modified url.
 */
function hc_custom_bp_get_signup_page( $register_url ) {
	$register_url  = '/begin/';
	return $register_url;
};

add_filter( 'bp_get_signup_page', 'hc_custom_bp_get_signup_page' );

/**
 * Add shortcode to get the enrollment url
 *
 * @since HCommons
 * @param array $atts passed in.
 * @return string env url.
 */
function hcommons_get_society_enrollment_url( $atts ) {
	$a = shortcode_atts( array(
		'id'    => '',
		'class' => '',
		'text'  => '',
	), $atts );
	if ( empty( $a['text'] ) ) {
		return;
	}
	$id = ( ! empty( $a['id'] ) ? $a['id'] : '');
	$class = ( ! empty( $a['class'] ) ? $a['class'] : '');
	if ( class_exists( 'Humanities_Commons' ) && ! empty( Humanities_Commons::$society_id ) && defined( strtoupper( Humanities_Commons::$society_id ) . '_ENROLLMENT_URL' ) ) {
		$enrollment_url = constant( strtoupper( Humanities_Commons::$society_id ) . '_ENROLLMENT_URL' ) . '/done:core';
		$env_url = sprintf( '<a href="%s" id="%s" class="%s">%s</a>', $enrollment_url, $id, $class, $a['text'] );
		return $env_url;
	}
}
add_shortcode( 'hcommons_society_enrollment_url', 'hcommons_get_society_enrollment_url' );
