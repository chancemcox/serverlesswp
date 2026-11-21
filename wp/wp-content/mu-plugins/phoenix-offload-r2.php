<?php
/**
 * Plugin Name: Phoenix Offload R2
 * Description: Wires WP Offload Media Lite to Cloudflare R2 (bucket "wp-upload") with credentials from the OFFLOAD_R2_ACCESS_KEY_ID / OFFLOAD_R2_SECRET_ACCESS_KEY environment variables. Completely inert when those variables are not set.
 * Author: Phoenix
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Credentials come from the environment only (Vercel env vars). Never hardcode keys here.
$phoenix_offload_r2_key_id = getenv( 'OFFLOAD_R2_ACCESS_KEY_ID' );
if ( false === $phoenix_offload_r2_key_id && isset( $_ENV['OFFLOAD_R2_ACCESS_KEY_ID'] ) ) {
	$phoenix_offload_r2_key_id = $_ENV['OFFLOAD_R2_ACCESS_KEY_ID'];
}

$phoenix_offload_r2_secret = getenv( 'OFFLOAD_R2_SECRET_ACCESS_KEY' );
if ( false === $phoenix_offload_r2_secret && isset( $_ENV['OFFLOAD_R2_SECRET_ACCESS_KEY'] ) ) {
	$phoenix_offload_r2_secret = $_ENV['OFFLOAD_R2_SECRET_ACCESS_KEY'];
}

if (
	is_string( $phoenix_offload_r2_key_id ) && '' !== $phoenix_offload_r2_key_id &&
	is_string( $phoenix_offload_r2_secret ) && '' !== $phoenix_offload_r2_secret &&
	! defined( 'AS3CF_SETTINGS' )
) {
	// WP Offload Media reads this constant in Settings_Trait::get_defined_settings()
	// and merges it over the saved settings in Settings_Trait::filter_settings().
	// Only keys listed in Amazon_S3_And_CloudFront::get_allowed_settings_keys()
	// are honoured, and every key used below is in that list.
	define(
		'AS3CF_SETTINGS',
		serialize(
			array(
				// Storage: the "aws" provider, speaking S3 to the R2 endpoint
				// injected by the filter at the bottom of this file.
				'provider'               => 'aws',
				'access-key-id'          => $phoenix_offload_r2_key_id,
				'secret-access-key'      => $phoenix_offload_r2_secret,
				'bucket'                 => 'wp-upload',
				// R2's S3 API signs requests with region "auto". A region defined
				// via AS3CF_SETTINGS is trusted for the configured bucket
				// (Amazon_S3_And_CloudFront::maybe_get_defined_bucket_region()).
				'region'                 => 'auto',
				// R2 does not support object ACLs. Setting this to false keeps
				// WP Offload Media from sending ACL headers on upload
				// (Amazon_S3_And_CloudFront::use_acl_for_intermediate_size())
				// and from probing the bucket's ACL configuration.
				'use-bucket-acls'        => false,
				'copy-to-s3'             => true,
				// Delivery: rewrite media URLs and serve them from the bucket's
				// public r2.dev domain. The "other" delivery provider allows a
				// custom delivery domain; the default "storage" provider does not.
				'serve-from-s3'          => true,
				'force-https'            => true,
				'delivery-provider'      => 'other',
				'enable-delivery-domain' => true,
				'delivery-domain'        => 'pub-8f92a8543bd1415994d6dcabff88ad1a.r2.dev',
			)
		)
	);

	// Point the AWS provider's S3 client at Cloudflare R2 (see below).
	add_filter( 'as3cf_aws_init_client_args', 'phoenix_offload_r2_aws_client_args' );
}

unset( $phoenix_offload_r2_key_id, $phoenix_offload_r2_secret );

/**
 * Inject the Cloudflare R2 endpoint into WP Offload Media's AWS client args.
 *
 * Storage_Provider::_init_client() runs the merged client args through the
 * 'as3cf_aws_init_client_args' filter before the AWS SDK client is built, and
 * the SDK accepts a custom "endpoint" argument — the supported route for
 * S3-compatible storage such as R2.
 *
 * @param array $args AWS SDK client args.
 * @return array
 */
function phoenix_offload_r2_aws_client_args( $args ) {
	$args['endpoint'] = 'https://f40039e2cea20df45e5ef1289b3760b4.r2.cloudflarestorage.com';

	// Follow Cloudflare's R2 guidance for the AWS SDK: only calculate and
	// validate checksums when an operation requires them. The SDK defaults
	// ("when_supported") attach trailing checksums that R2 does not accept.
	$args['request_checksum_calculation'] = 'when_required';
	$args['response_checksum_validation'] = 'when_required';

	return $args;
}
