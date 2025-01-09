<?php

namespace KurtRank;

use function trailingslashit;
use function wp_remote_get;
use function wp_register_script_module;
use function wp_enqueue_script_module;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_parse_args;

class WP_Vite_Assets {
	private array $enqueued_entries = array();

	private bool $is_dev = false;

	private ?array $manifest;
	public string $base_url;
	public string $dev_url;
	public string $prefix;

	public function __construct( string $prefix, string $manifest_file, string $base_url, string $dev_url ) {
		$this->prefix = $prefix;

		$this->dev_url = trailingslashit( $dev_url );

		$this->base_url = trailingslashit( $base_url );

		$this->is_dev = $this->check_is_dev();

		$this->manifest = $this->load_manifest( $manifest_file );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function __get( $prop ) {
		if ( 'is_dev' === $prop ) {
			return $this->is_dev;
		}
	}

	private function check_is_dev() {
		$res = wp_remote_get( $this->dev_url );
		return gettype( $res ) !== 'object' || 'WP_Error' !== get_class( $res );
	}

	private function load_manifest( $manifest_file ) {
		$manifest = null;

		if ( ! file_exists( realpath( $manifest_file ) ) ) {
			throw new \Exception( "Manifest file does not exist: $manifest_file" );
		}

		try {
			$manifest = json_decode(
				file_get_contents( $manifest_file ),
				true
			);

		} catch ( \Throwable $error_message ) {
			throw new \Exception( "Failed loading manifest: $error_message" );
		}

		return $manifest;
	}

	public function enqueue_assets() {
		if ( $this->is_dev ) {
			wp_enqueue_script_module( "{$this->prefix}/vite-dev", "{$this->dev_url}@vite/client", array(), null );
		}

		foreach ( $this->enqueued_entries as $path => $args ) {

			if ( preg_match( '/\.js$/', $path ) ) {
				// TODO: check if enqueue as non-module
				if ( $args['module'] ) {
					$this->enqueue_module_with_deps( $path, $args, true );
				} else {
					$this->enqueue_script( $path, $args );

				}
				// wp_enqueue_script_module( "{$this->prefix}/$handle", "{$url}/{$path}", $deps, null );
			} elseif ( preg_match( '/\.css$/', $path ) ) {
				$this->enqueue_style( $path, $args );
			}
		}
	}

	public function enqueue_module_with_deps( $name, $args = array(), $is_entry = false ) {

		$url = $this->is_dev ? $this->dev_url : $this->base_url;

		$data = $this->manifest[ $name ];

		$handle = $args['handle'] ?? $data['name'];

		$external_deps = $args['deps'] ?? array();

		if ( $this->is_dev && $is_entry ) {
			// only worry about top level modules
			wp_enqueue_script_module( "{$this->prefix}/{$handle}", "{$url}{$name}", $external_deps, null );
			return;
		}

		if ( ! $data ) {
			return '';
		}

		$deps = array();

		if ( isset( $data['imports'] ) ) {
			foreach ( $data['imports'] as $import ) {
				$deps[] = $this->enqueue_module_with_deps( $import );
			}
		}

		if ( isset( $data['dynamicImports'] ) ) {
			foreach ( $data['dynamicImports'] as $import ) {
				$deps[] = array(
					'id'     => $this->enqueue_module_with_deps( $import ),
					'import' => 'dynamic',
				);
			}
		}

		$deps = array(
			...$deps,
			...$external_deps,
		);

		$ns_handle = "{$this->prefix}/{$handle}";

		if ( isset( $data['css'] ) ) {
			foreach ( $data['css'] as $k => $file ) {
				wp_enqueue_style( "{$ns_handle}-{$k}", "{$url}{$file}", array(), null );
			}
		}

		if ( $is_entry ) {
			wp_enqueue_script_module( $ns_handle, "{$url}{$data['file']}", $deps, null );
		} else {
			wp_register_script_module( $ns_handle, "{$url}{$data['file']}", $deps, null );
		}

		return $ns_handle;
	}

	public function enqueue_script( $name, $args = array() ) {

		$url = $this->is_dev ? $this->dev_url : $this->base_url;

		$data = $this->manifest[ $name ];

		$path = $this->is_dev ? $name : $data['file'];

		$handle = $args['handle'] ?? $data['name'];

		$external_deps = $args['deps'] ?? array();

		$wp_args = $args['wp_args'] ?? array();

		wp_enqueue_script( "{$this->prefix}/{$handle}", "{$url}{$path}", $external_deps, null, $wp_args );
	}
	public function enqueue_style( $name, $args = array() ) {
		$url = $this->is_dev ? $this->dev_url : $this->base_url;

		$data = $this->manifest[ $name ];

		$path = $this->is_dev ? $name : $data['file'];

		$handle = $args['handle'] ?? $data['name'] ?? $this->get_handle_from_path( $name );

		$external_deps = $args['deps'] ?? array();

		wp_enqueue_style( "{$this->prefix}/$handle", "{$url}{$path}", $external_deps, null );
	}

	public function get_handle_from_path( $path, $ext = 'css' ) {
		$r = false;

		$matches = array();

		$found = preg_match( "/.*\/(.*)\.{$ext}$/", $path, $matches );

		if ( $found ) {
			$r = $matches[1];
		}

		return $r;
	}

	public function enqueue( $entry_file_path, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'handle' => null,
				'deps'   => array(),
				'module' => true,
			)
		);

		$this->enqueued_entries[ $entry_file_path ] = $args;
	}
}
