<?php
namespace CsrDelft\Orm\Entity;

/**
 * Sparse.interface.php
 * 
 * @author P.W.G. Brussee <brussee@live.nl>
 *
 * Object that may not have all its persistent attributes retrieved.
 * 
 */
interface Sparse {

	/**
	 * Are there any attributes not yet retrieved?
	 * Requires tracking of retrieved attributes to discern invalid values.
	 * 
	 * @param array $attributes to check for
	 * @return boolean
	 */
	public function isSparse(array $attributes);
}
