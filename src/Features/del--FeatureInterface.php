<?php
namespace Saltus\WP\Framework\Features;

/**
 * A conceptual service.
 *
 * Splitting your logic up into independent services makes the approach of
 * assembling a plugin more systematic and scalable and lowers the cognitive
 * load when the code base increases in size.
 */
interface FeaturesInterface {

	public function get_new();
}
