<?php
/**
 * Global-namespace bootstrap for Reply_Status_Controller tests.
 *
 * The controller `use`s root-namespace WP_REST_* classes; the PHPUnit
 * bootstrap already defines WP_REST_Controller, WP_REST_Server, and
 * WP_REST_Response, but not WP_REST_Request. Declare a minimal
 * stand-in here so `$request['id']` works the way the controller uses it.
 *
 * @package AI_Feedback\Tests
 */

namespace {

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		/**
		 * Minimal WP_REST_Request stand-in used by controller tests.
		 *
		 * Only models the ArrayAccess surface (`$request['id']`) that the
		 * controller reads.
		 */
		class WP_REST_Request implements \ArrayAccess {
			private array $data;

			public function __construct( array $data = array() ) {
				$this->data = $data;
			}
			public function offsetExists( $offset ): bool {
				return isset( $this->data[ $offset ] );
			}
			public function offsetGet( $offset ): mixed {
				return $this->data[ $offset ] ?? null;
			}
			public function offsetSet( $offset, $value ): void {
				$this->data[ $offset ] = $value;
			}
			public function offsetUnset( $offset ): void {
				unset( $this->data[ $offset ] );
			}
		}
	}
}
