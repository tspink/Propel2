<?php

/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information please see
 * <http://propel.phpdb.org>.
 */
 
 
/**
 * Behavior to adds nested set tree structure columns and abilities
 *
 * @author     François Zaninotto
 * @author     heltem <heltem@o2php.com>
 * @package    propel.generator.behavior.nestedset
 */
class NestedSetBehaviorObjectBuilderModifier
{
	protected $behavior, $table, $builder, $objectClassname, $peerClassname;
	
	public function __construct($behavior)
	{
		$this->behavior = $behavior;
		$this->table = $behavior->getTable();
	}
	
	protected function getParameter($key)
	{
		return $this->behavior->getParameter($key);
	}
	
	protected function getColumnAttribute($name)
	{
		return strtolower($this->behavior->getColumnForParameter($name)->getName());
	}

	protected function getColumnPhpName($name)
	{
		return $this->behavior->getColumnForParameter($name)->getPhpName();
	}
	
	protected function setBuilder($builder)
	{
		$this->builder = $builder;
		$this->objectClassname = $builder->getStubObjectBuilder()->getClassname();
		$this->peerClassname = $builder->getStubPeerBuilder()->getClassname();
	}
		
	/*
	public function objectFilter(&$script, $builder)
	{
		$script = str_replace('implements Persistent', 'implements Persistent, NodeObject', $script);
	}
	*/
	
	public function objectAttributes($builder)
	{
		$objectClassname = $builder->getStubObjectBuilder()->getClassname();
		return "
/**
 * Store if node has prev sibling
 * @var        bool
 */
protected \$hasPrevSibling = null;

/**
 * Store node if has prev sibling
 * @var        $objectClassname
 */
protected \$prevSibling = null;

/**
 * Store if node has next sibling
 * @var        bool
 */
protected \$hasNextSibling = null;

/**
 * Store node if has next sibling
 * @var        $objectClassname
 */
protected \$nextSibling = null;

/**
 * The parent node for this node.
 * @var        $objectClassname
 */
protected \$parentNode = null;

/**
 * Queries to be executed in the save transaction
 * @var        array
 */
protected \$nestedSetQueries = array();
";
	}
	
	public function preSave($builder)
	{
		return "\$this->processNestedSetQueries(\$con);";
	}
		
	public function preDelete($builder)
	{
		$peerClassname = $builder->getStubPeerBuilder()->getClassname();
		return "if (\$this->isRoot()) {
	throw new PropelException('Deletion of a root node is disabled for nested sets. Use $peerClassname::deleteTree(" . ($this->behavior->useScope() ? '$scope' : '') . ") instead to delete an entire tree');
}
\$this->deleteDescendants(\$con);
";
	}

	public function postDelete($builder)
	{
		$peerClassname = $builder->getStubPeerBuilder()->getClassname();
		return "// fill up the room that was used by the node
$peerClassname::shiftRLValues(-2, \$this->getRightValue() + 1, null" . ($this->behavior->useScope() ? ", \$this->getScopeValue()" : "") . ", \$con);
";
	}
	
	public function objectMethods($builder)
	{
		$this->setBuilder($builder);
		$script = '';
		
		$this->addProcessNestedSetQueries($script);
		
		$this->addGetLeft($script);
		$this->addGetRight($script);
		$this->addGetLevel($script);
		if ($this->getParameter('use_scope') == 'true')
		{
			$this->addGetScope($script);
		}

		$this->addSetLeft($script);
		$this->addSetRight($script);
		$this->addSetLevel($script);
		if ($this->getParameter('use_scope') == 'true')
		{
			$this->addSetScope($script);
		}
		
		$this->addMakeRoot($script);
		
		$this->addIsInTree($script);
		$this->addIsRoot($script);
		$this->addIsLeaf($script);
		$this->addIsDescendantOf($script);
		$this->addIsAncestorOf($script);
		
		$this->addSetParent($script);
		$this->addHasParent($script);
		$this->addGetParent($script);
		
		$this->addSetPrevSibling($script);
		$this->addHasPrevSibling($script);
		$this->addGetPrevSibling($script);
		
		$this->addSetNextSibling($script);
		$this->addHasNextSibling($script);
		$this->addGetNextSibling($script);
		
		$this->addHasChildren($script);
		$this->addGetChildren($script);
		$this->addGetNumberOfChildren($script);
		$this->addGetFirstChild($script);
		$this->addGetLastChild($script);
		$this->addGetSiblings($script);
		$this->addGetDescendants($script);
		$this->addGetNumberOfDescendants($script);
		$this->addGetBranch($script);
		$this->addGetDescendantsCriteria($script);
		$this->addGetAncestors($script);
		
		$this->addAddChild($script);
		$this->addInsertAsFirstChildOf($script);
		$this->addInsertAsLastChildOf($script);
		$this->addInsertAsPrevSiblingOf($script);
		$this->addInsertAsNextSiblingOf($script);
		
		$this->addMoveToFirstChildOf($script);
		$this->addMoveToLastChildOf($script);
		$this->addMoveToPrevSiblingOf($script);
		$this->addMoveToNextSiblingOf($script);
		$this->addMoveSubtreeTo($script);
		
		$this->addDeleteDescendants($script);
		
		$this->addGetIterator($script);
		
		if ($this->getParameter('method_proxies') == 'true')
		{
			$this->addCompatibilityProxies($script);
		}
		
		return $script;
	}

	protected function addProcessNestedSetQueries(&$script)
	{
		$script .= "
/**
 * Execute queries that were saved to be run inside the save transaction
 */
protected function processNestedSetQueries(\$con)
{
	foreach (\$this->nestedSetQueries as \$query) {
		\$query['arguments'][]= \$con;
		call_user_func_array(\$query['callable'], \$query['arguments']);
	}
	\$this->nestedSetQueries = array();
}
";
	}
	protected function addGetLeft(&$script)
	{
		$script .= "
/**
 * Wraps the getter for the nested set left value
 *
 * @return     int
 */
public function getLeftValue()
{
	return \$this->{$this->getColumnAttribute('left_column')};
}
";
	}

	protected function addGetRight(&$script)
	{
		$script .= "
/**
 * Wraps the getter for the nested set right value
 *
 * @return     int
 */
public function getRightValue()
{
	return \$this->{$this->getColumnAttribute('right_column')};
}
";
	}
	
	protected function addGetLevel(&$script)
	{
		$script .= "
/**
 * Wraps the getter for the nested set level
 *
 * @return     int
 */
public function getLevel()
{
	return \$this->{$this->getColumnAttribute('level_column')};
}
";
	}

	protected function addGetScope(&$script)
	{
		$script .= "
/**
 * Wraps the getter for the scope value
 *
 * @return     int or null if scope is disabled
 */
public function getScopeValue()
{
	return \$this->{$this->getColumnAttribute('scope_column')};
}
";
	}

	protected function addSetLeft(&$script)
	{
		$script .= "
/**
 * Set the value left column
 *
 * @param      int \$v new value
 * @return     {$this->objectClassname} The current object (for fluent API support)
 */
public function setLeftValue(\$v)
{
	return \$this->set{$this->getColumnPhpName('left_column')}(\$v);
}
";
	}

	protected function addSetRight(&$script)
	{
		$script .= "
/**
 * Set the value of right column
 *
 * @param      int \$v new value
 * @return     {$this->objectClassname} The current object (for fluent API support)
 */
public function setRightValue(\$v)
{
	return \$this->set{$this->getColumnPhpName('right_column')}(\$v);
}
";
	}

	protected function addSetLevel(&$script)
	{
		$script .= "
/**
 * Set the value of level column
 *
 * @param      int \$v new value
 * @return     {$this->objectClassname} The current object (for fluent API support)
 */
public function setLevel(\$v)
{
	return \$this->set{$this->getColumnPhpName('level_column')}(\$v);
}
";
	}
	
	protected function addSetScope(&$script)
	{
		$script .= "
/**
 * Set the value of scope column
 *
 * @param      int \$v new value
 * @return     {$this->objectClassname} The current object (for fluent API support)
 */
public function setScopeValue(\$v)
{
	return \$this->set{$this->getColumnPhpName('scope_column')}(\$v);
}
";
	}

	protected function addMakeRoot(&$script)
	{
		$script .= "
/**
 * Creates the supplied node as the root node.
 *
 * @return     {$this->objectClassname} The current object (for fluent API support)
 * @throws     PropelException
 */
public function makeRoot()
{
	if (\$this->getLeftValue() || \$this->getRightValue()) {
		throw new PropelException('Cannot turn an existing node into a root node.');
	}

	\$this->setLeftValue(1);
	\$this->setRightValue(2);
	\$this->setLevel(0);
	return \$this;
}
";
	}

	protected function addIsInTree(&$script)
	{
		$script .= "
/**
 * Tests if onbject is a node, i.e. if it is inserted in the tree
 *
 * @return     bool
 */
public function isInTree()
{
	return \$this->getLeftValue() > 0 && \$this->getRightValue() > \$this->getLeftValue();
}
";
	}

	protected function addIsRoot(&$script)
	{
		$script .= "
/**
 * Tests if node is a root
 *
 * @return     bool
 */
public function isRoot()
{
	return \$this->isInTree() && \$this->getLeftValue() == 1;
}
";
	}
	
	protected function addIsLeaf(&$script)
	{
		$script .= "
/**
 * Tests if node is a leaf
 *
 * @return     bool
 */
public function isLeaf()
{
	return \$this->isInTree() &&  (\$this->getRightValue() - \$this->getLeftValue()) == 1;
}
";
	}
	
	protected function addIsDescendantOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$script .= "
/**
 * Tests if node is a descendant of another node
 *
 * @param      $objectClassname \$node Propel node object
 * @return     bool
 */
public function isDescendantOf($objectClassname \$parent)
{";
		if ($this->behavior->useScope()) {
			$script .= "
	if (\$this->getScopeValue() !== \$parent->getScopeValue()) {
		throw new PropelException('Comparing two nodes of different trees');
	}";
		}
		$script .= "
	return \$this->isInTree() && \$this->getLeftValue() > \$parent->getLeftValue() && \$this->getRightValue() < \$parent->getRightValue();
}
";
	}
	
	protected function addIsAncestorOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$script .= "
/**
 * Tests if node is a ancestor of another node
 *
 * @param      $objectClassname \$node Propel node object
 * @return     bool
 */
public function isAncestorOf($objectClassname \$child)
{
	return \$child->isDescendantOf(\$this);
}
";
	}
	
	protected function addSetParent(&$script)
	{
		$objectClassname = $this->objectClassname;
		$script .= "
/**
 * Sets the parentNode of the node in the tree
 * Only used to cache the result of a parent search
 *
 * @param      $objectClassname \$parent Propel node object
 * @return     $objectClassname The current object (for fluent API support)
 */
public function setParent($objectClassname \$parent = null)
{
	\$this->parentNode = {$this->peerClassname}::isValid(\$parent) ? \$parent : null;
	return \$this;
}
";
	}

	protected function addHasParent(&$script)
	{
		$script .= "
/**
 * Tests if object has an ancestor
 *
 * @param      PropelPDO \$con Connection to use.
 * @return     bool
 */
public function hasParent(PropelPDO \$con = null)
{
	return \$this->getLevel() > 0;
}
";
	}

	protected function addGetParent(&$script)
	{
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets ancestor for the given node if it exists
 * The result is cached so further calls to the same method don't issue any queries
 *
 * @param      PropelPDO \$con Connection to use.
 * @return     mixed 		Propel object if exists else false
 */
public function getParent(PropelPDO \$con = null)
{
	if(!\$this->hasParent()) {
		return null;
	} elseif (null === \$this->parentNode) {
		\$c = new Criteria($peerClassname::DATABASE_NAME);
		\$c->add($peerClassname::LEFT_COL, \$this->getLeftValue(), Criteria::LESS_THAN);
		\$c->add($peerClassname::RIGHT_COL, \$this->getRightValue(), Criteria::GREATER_THAN);";
		if ($this->behavior->useScope()) {
			$script .= "
		\$c->add($peerClassname::SCOPE_COL, \$this->getScopeValue(), Criteria::EQUAL);";
		}
		$script .= "		
		\$c->addAscendingOrderByColumn($peerClassname::RIGHT_COL);
		\$parent = $peerClassname::doSelectOne(\$c, \$con);
		\$this->setParent(\$parent);
	}
	return \$this->parentNode;
}
";
	}
	
	protected function addSetPrevSibling(&$script)
	{
		$objectClassname = $this->objectClassname;
		$script .= "
/**
 * Sets the previous sibling of the node in the tree
 * Only used to cache the result of a sibling search
 *
 * @param      $objectClassname \$node Propel node object
 * @return     $objectClassname The current object (for fluent API support)
 */
public function setPrevSibling($objectClassname \$node = null)
{
	\$this->hasPrevSibling = {$this->peerClassname}::isValid(\$node);
	\$this->prevSibling = \$this->hasPrevSibling ? \$node : null;
	return \$this;
}
";
	}

	protected function addHasPrevSibling(&$script)
	{
		$script .= "
/**
 * Determines if the node has previous sibling
 *
 * @param      PropelPDO \$con Connection to use.
 * @return     bool
 */
public function hasPrevSibling(PropelPDO \$con = null)
{
	if (null === \$this->hasPrevSibling) {
		if (!{$this->peerClassname}::isValid(\$this)) {
			return false;
		}
		\$this->getPrevSibling(\$con);
	}
	return \$this->hasPrevSibling;
}
";
	}

	protected function addGetPrevSibling(&$script)
	{
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets previous sibling for the given node if it exists
 * The result is cached so further calls to the same method don't issue any queries
 *
 * @param      PropelPDO \$con Connection to use.
 * @return     mixed 		Propel object if exists else false
 */
public function getPrevSibling(PropelPDO \$con = null)
{
	if (null === \$this->hasPrevSibling) {
		\$c = new Criteria($peerClassname::DATABASE_NAME);
		\$c->add($peerClassname::RIGHT_COL, \$this->getLeftValue() - 1, Criteria::EQUAL);";
		if ($this->behavior->useScope()) {
			$script .= "
		\$c->add($peerClassname::SCOPE_COL, \$this->getScopeValue(), Criteria::EQUAL);";
		}
		$script .= "		
		\$prevSibling = $peerClassname::doSelectOne(\$c, \$con);

		\$this->setPrevSibling(\$prevSibling);
	}
	return \$this->prevSibling;
}
";
	}

	protected function addSetNextSibling(&$script)
	{
		$objectClassname = $this->objectClassname;
		$script .= "
/**
 * Sets the next sibling of the node in the tree
 * Only used to cache the result of a sibling search
 *
 * @param      $objectClassname \$node Propel node object
 * @return     $objectClassname The current object (for fluent API support)
 */
public function setNextSibling($objectClassname \$node = null)
{
	\$this->hasNextSibling = {$this->peerClassname}::isValid(\$node);
	\$this->nextSibling = \$this->hasNextSibling ? \$node : null;
	return \$this;
}
";
	}

	protected function addHasNextSibling(&$script)
	{
		$script .= "
/**
 * Determines if the node has next sibling
 *
 * @param      PropelPDO \$con Connection to use.
 * @return     bool
 */
public function hasNextSibling(PropelPDO \$con = null)
{
	if (null === \$this->hasNextSibling) {
		if (!{$this->peerClassname}::isValid(\$this)) {
			return false;
		}
		\$this->getNextSibling(\$con);
	}
	return \$this->hasNextSibling;
}
";
	}

	protected function addGetNextSibling(&$script)
	{
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets next sibling for the given node if it exists
 * The result is cached so further calls to the same method don't issue any queries
 *
 * @param      PropelPDO \$con Connection to use.
 * @return     mixed 		Propel object if exists else false
 */
public function getNextSibling(PropelPDO \$con = null)
{
	if (null === \$this->hasNextSibling) {
		\$c = new Criteria($peerClassname::DATABASE_NAME);
		\$c->add($peerClassname::LEFT_COL, \$this->getRightValue() + 1, Criteria::EQUAL);";
		if ($this->behavior->useScope()) {
			$script .= "
		\$c->add($peerClassname::SCOPE_COL, \$this->getScopeValue(), Criteria::EQUAL);";
		}
		$script .= "		
		\$nextSibling = $peerClassname::doSelectOne(\$c, \$con);

		\$this->setNextSibling(\$nextSibling);
	}
	return \$this->nextSibling;
}
";
	}
	
	protected function addHasChildren(&$script)
	{
		$script .= "
/**
 * Tests if node has children
 *
 * @return     bool
 */
public function hasChildren()
{
	return (\$this->getRightValue() - \$this->getLeftValue()) > 1;
}
";
	}

	protected function addGetChildren(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets the children of the given node
 *
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 * @return     array 		List of $objectClassname objects
 */
public function getChildren(Criteria \$criteria = null, PropelPDO \$con = null)
{
	if(\$this->isLeaf()) {
		// save one query
		return array();
	}
	\$criteria = \$this->getDescendantsCriteria(\$criteria);
	\$criteria->add($peerClassname::LEVEL_COL, \$this->getLevel() + 1, Criteria::EQUAL);

	return $peerClassname::doSelect(\$criteria, \$con);
}
";
	}

	protected function addGetNumberOfChildren(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets number of children for the given node
 *
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 * @return     int 		Number of children
 */
public function getNumberOfChildren(Criteria \$criteria = null, PropelPDO \$con = null)
{
	if(\$this->isLeaf()) {
		// save one query
		return 0;
	}
	\$criteria = \$this->getDescendantsCriteria(\$criteria);
	\$criteria->add($peerClassname::LEVEL_COL, \$this->getLevel() + 1, Criteria::EQUAL);
	
	return $peerClassname::doCount(\$criteria, \$con);
}
";
	}

	protected function addGetFirstChild(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets the first child of the given node
 *
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 * @return     array 		List of $objectClassname objects
 */
public function getFirstChild(Criteria \$criteria = null, PropelPDO \$con = null)
{
	if(\$this->isLeaf()) {
		// save one query
		return array();
	}
	\$criteria = \$this->getDescendantsCriteria(\$criteria);
	\$criteria->add($peerClassname::LEVEL_COL, \$this->getLevel() + 1, Criteria::EQUAL);

	return $peerClassname::doSelectOne(\$criteria, \$con);
}
";
	}

	protected function addGetLastChild(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets the last child of the given node
 *
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 * @return     array 		List of $objectClassname objects
 */
public function getLastChild(Criteria \$criteria = null, PropelPDO \$con = null)
{
	if(\$this->isLeaf()) {
		// save one query
		return array();
	}
	if (\$criteria === null) {
		\$criteria = new Criteria($peerClassname::DATABASE_NAME);
	}
	\$criteria->addDescendingOrderByColumn($peerClassname::RIGHT_COL);

	return \$this->getFirstChild(\$criteria, \$con);
}
";
	}

	protected function addGetSiblings(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets the siblings of the given node
 *
 * @param      bool			\$includeNode Whether to include the current node or not
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 *
 * @return     array 		List of $objectClassname objects
 */
public function getSiblings(\$includeNode = false, Criteria \$criteria = null, PropelPDO \$con = null)
{
	if(\$this->isRoot()) {
		// save one query
		return array();
	}
	\$parent = \$this->getParent(\$con);
	\$siblings = array();
	foreach (\$parent->getChildren(\$criteria, \$con) as \$sibling) {
		if (\$this->equals(\$sibling) && !\$includeNode) {
			continue;
		}
		\$siblings[] = \$sibling;
	}
	return \$siblings;
}
";
	}
	
	protected function addGetDescendants(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets descendants for the given node
 *
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 * @return     array 		List of $objectClassname objects
 */
public function getDescendants(Criteria \$criteria = null, PropelPDO \$con = null)
{
	if(\$this->isLeaf()) {
		// save one query
		return array();
	}
	\$criteria = \$this->getDescendantsCriteria(\$criteria);
	
	return $peerClassname::doSelect(\$criteria, \$con);
}
";
	}

	protected function addGetNumberOfDescendants(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets number of descendants for the given node
 *
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 * @return     int 		Number of descendants
 */
public function getNumberOfDescendants(Criteria \$criteria = null, PropelPDO \$con = null)
{
	if(\$this->isLeaf()) {
		// save one query
		return 0;
	}
	\$criteria = \$this->getDescendantsCriteria(\$criteria);
	
	return $peerClassname::doCount(\$criteria, \$con);
}
";
	}

	protected function addGetBranch(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets descendants for the given node, plus the current node
 *
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 * @return     array 		List of $objectClassname objects
 */
public function getBranch(Criteria \$criteria = null, PropelPDO \$con = null)
{
	if (\$criteria === null) {
		\$criteria = new Criteria($peerClassname::DATABASE_NAME);
	}
	\$criteria->add($peerClassname::LEFT_COL, \$this->getLeftValue(), Criteria::GREATER_EQUAL);
	\$criteria->add($peerClassname::RIGHT_COL, \$this->getRightValue(), Criteria::LESS_EQUAL);";
	if ($this->behavior->useScope()) {
		$script .= "
	\$criteria->add($peerClassname::SCOPE_COL, \$this->getScopeValue(), Criteria::EQUAL);";
	}
	$script .= "		
	\$criteria->addAscendingOrderByColumn($peerClassname::LEFT_COL);
		
	return $peerClassname::doSelect(\$criteria, \$con);
}
";
	}
	
	protected function addGetDescendantsCriteria(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets a Criteria for the descendants for the given node
 *
 * @param      Criteria \$criteria Criteria to filter results
 * 
 * @return     Criteria Criteria to find the descendants
 */
protected function getDescendantsCriteria(Criteria \$criteria = null)
{
	if (\$criteria === null) {
		\$criteria = new Criteria($peerClassname::DATABASE_NAME);
	}
	\$criteria->add($peerClassname::LEFT_COL, \$this->getLeftValue(), Criteria::GREATER_THAN);
	\$criteria->add($peerClassname::RIGHT_COL, \$this->getRightValue(), Criteria::LESS_THAN);";
	if ($this->behavior->useScope()) {
		$script .= "
	\$criteria->add($peerClassname::SCOPE_COL, \$this->getScopeValue(), Criteria::EQUAL);";
	}
	$script .= "		
	\$criteria->addAscendingOrderByColumn($peerClassname::LEFT_COL);

	return \$criteria;
}
";
	}
	
	protected function addGetAncestors(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Gets ancestors for the given node, starting with the root node
 * Use it for breadcrumb paths for instance
 *
 * @param      Criteria \$criteria Criteria to filter results. 
 * @param      PropelPDO \$con Connection to use.
 * @return     array 		List of $objectClassname objects
 */
public function getAncestors(Criteria \$criteria = null, PropelPDO \$con = null)
{
	if(\$this->isRoot()) {
		// save one query
		return array();
	}
	if (\$criteria === null) {
		\$criteria = new Criteria($peerClassname::DATABASE_NAME);
	}
	if (\$con === null) {
		\$con = Propel::getConnection($peerClassname::DATABASE_NAME, Propel::CONNECTION_READ);
	}
	\$criteria->add($peerClassname::LEFT_COL, \$this->getLeftValue(), Criteria::LESS_THAN);
	\$criteria->add($peerClassname::RIGHT_COL, \$this->getRightValue(), Criteria::GREATER_THAN);";
	if ($this->behavior->useScope()) {
		$script .= "
	\$criteria->add($peerClassname::SCOPE_COL, \$this->getScopeValue(), Criteria::EQUAL);";
	}
	$script .= "		
	\$criteria->addAscendingOrderByColumn($peerClassname::LEFT_COL);

	return $peerClassname::doSelect(\$criteria, \$con);
}
";
	}
	
	protected function addAddChild(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Inserts the given \$child node as first child of current
 * The modifications in the current object and the tree
 * are not persisted until the child object is saved.
 *
 * @param      $objectClassname \$child	Propel object for child node
 *
 * @return     $objectClassname The current Propel object
 */
public function addChild($objectClassname \$child)
{
	if (\$this->isNew()) {
		throw new PropelException('A $objectClassname object must not be new to accept children.');
	}
	\$child->insertAsFirstChildOf(\$this);
	return \$this;
}
";
	}
	
	protected function addInsertAsFirstChildOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Inserts the current node as first child of given \$parent node
 * The modifications in the current object and the tree
 * are not persisted until the current object is saved.
 *
 * @param      $objectClassname \$parent	Propel object for parent node
 *
 * @return     $objectClassname The current Propel object
 */
public function insertAsFirstChildOf($objectClassname \$parent)
{
	if (\$this->isInTree()) {
		throw new PropelException('A $objectClassname object must not already be in the tree to be inserted. Use the moveToFirstChildOf() instead.');
	}
	\$left = \$parent->getLeftValue() + 1;
	// Update node properties
	\$this->setLeftValue(\$left);
	\$this->setRightValue(\$left + 1);
	\$this->setLevel(\$parent->getLevel() + 1);";
		if ($useScope)
		{
			$script .= "
	\$scope = \$parent->getScopeValue();
	\$this->setScopeValue(\$scope);";
		}
		$script .= "
	\$this->setParent(\$parent);
	// Keep the tree modification query for the save() transaction
	\$this->nestedSetQueries []= array(
		'callable'  => array('$peerClassname', 'makeRoomForLeaf'),
		'arguments' => array(\$left" . ($useScope ? ", \$scope" : "") . ")
	);
	return \$this;
}
";
	}

	protected function addInsertAsLastChildOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Inserts the current node as last child of given \$parent node
 * The modifications in the current object and the tree
 * are not persisted until the current object is saved.
 *
 * @param      $objectClassname \$parent	Propel object for parent node
 *
 * @return     $objectClassname The current Propel object
 */
public function insertAsLastChildOf($objectClassname \$parent)
{
	if (\$this->isInTree()) {
		throw new PropelException('A $objectClassname object must not already be in the tree to be inserted. Use the moveToLastChildOf() instead.');
	}
	\$left = \$parent->getRightValue();
	// Update node properties
	\$this->setLeftValue(\$left);
	\$this->setRightValue(\$left + 1);
	\$this->setLevel(\$parent->getLevel() + 1);";
		if ($useScope)
		{
			$script .= "
	\$scope = \$parent->getScopeValue();
	\$this->setScopeValue(\$scope);";
		}
		$script .= "
	\$this->setParent(\$parent);
	// Keep the tree modification query for the save() transaction
	\$this->nestedSetQueries []= array(
		'callable'  => array('$peerClassname', 'makeRoomForLeaf'),
		'arguments' => array(\$left" . ($useScope ? ", \$scope" : "") . ")
	);
	return \$this;
}
";
	}

	protected function addInsertAsPrevSiblingOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Inserts the current node as prev sibling given \$sibling node
 * The modifications in the current object and the tree
 * are not persisted until the current object is saved.
 *
 * @param      $objectClassname \$sibling	Propel object for parent node
 *
 * @return     $objectClassname The current Propel object
 */
public function insertAsPrevSiblingOf($objectClassname \$sibling)
{
	if (\$this->isInTree()) {
		throw new PropelException('A $objectClassname object must not already be in the tree to be inserted. Use the moveToPrevSiblingOf() instead.');
	}
	\$left = \$sibling->getLeftValue();
	// Update node properties
	\$this->setLeftValue(\$left);
	\$this->setRightValue(\$left + 1);
	\$this->setLevel(\$sibling->getLevel());";
		if ($useScope)
		{
			$script .= "
	\$scope = \$sibling->getScopeValue();
	\$this->setScopeValue(\$scope);";
		}
		$script .= "
	\$this->setNextSibling(\$sibling);
	\$sibling->setPrevSibling(\$this);
	// Keep the tree modification query for the save() transaction
	\$this->nestedSetQueries []= array(
		'callable'  => array('$peerClassname', 'makeRoomForLeaf'),
		'arguments' => array(\$left" . ($useScope ? ", \$scope" : "") . ")
	);
	return \$this;
}
";
	}

	protected function addInsertAsNextSiblingOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Inserts the current node as next sibling given \$sibling node
 * The modifications in the current object and the tree
 * are not persisted until the current object is saved.
 *
 * @param      $objectClassname \$sibling	Propel object for parent node
 *
 * @return     $objectClassname The current Propel object
 */
public function insertAsNextSiblingOf($objectClassname \$sibling)
{
	if (\$this->isInTree()) {
		throw new PropelException('A $objectClassname object must not already be in the tree to be inserted. Use the moveToNextSiblingOf() instead.');
	}
	\$left = \$sibling->getRightValue() + 1;
	// Update node properties
	\$this->setLeftValue(\$left);
	\$this->setRightValue(\$left + 1);
	\$this->setLevel(\$sibling->getLevel());";
		if ($useScope)
		{
			$script .= "
	\$scope = \$sibling->getScopeValue();
	\$this->setScopeValue(\$scope);";
		}
		$script .= "
	\$this->setPrevSibling(\$sibling);
	\$sibling->setNextSibling(\$this);
	// Keep the tree modification query for the save() transaction
	\$this->nestedSetQueries []= array(
		'callable'  => array('$peerClassname', 'makeRoomForLeaf'),
		'arguments' => array(\$left" . ($useScope ? ", \$scope" : "") . ")
	);
	return \$this;
}
";
	}

	protected function addMoveToFirstChildOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Moves current node and its subtree to be the first child of \$parent
 * The modifications in the current object and the tree are immediate
 *
 * @param      $objectClassname \$parent	Propel object for parent node
 * @param      PropelPDO \$con	Connection to use.
 *
 * @return     $objectClassname The current Propel object
 */
public function moveToFirstChildOf($objectClassname \$parent, PropelPDO \$con = null)
{
	if (!\$this->isInTree()) {
		throw new PropelException('A $objectClassname object must be already in the tree to be moved. Use the insertAsFirstChildOf() instead.');
	}";
	if ($this->behavior->useScope()) {
		$script .= "
	if (\$parent->getScopeValue() != \$this->getScopeValue()) {
		throw new PropelException('Moving nodes across trees is not supported');
	}";
	}
	$script .= "
	if (\$parent->isDescendantOf(\$this)) {
		throw new PropelException('Cannot move a node as child of one of its subtree nodes.');
	}
	
	\$this->moveSubtreeTo(\$parent->getLeftValue() + 1, \$parent->getLevel() - \$this->getLevel() + 1, \$con);
	\$this->setParent(\$parent);
	
	return \$this;
}
";
	}

	protected function addMoveToLastChildOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Moves current node and its subtree to be the last child of \$parent
 * The modifications in the current object and the tree are immediate
 *
 * @param      $objectClassname \$parent	Propel object for parent node
 * @param      PropelPDO \$con	Connection to use.
 *
 * @return     $objectClassname The current Propel object
 */
public function moveToLastChildOf($objectClassname \$parent, PropelPDO \$con = null)
{
	if (!\$this->isInTree()) {
		throw new PropelException('A $objectClassname object must be already in the tree to be moved. Use the insertAsLastChildOf() instead.');
	}";
	if ($this->behavior->useScope()) {
		$script .= "
	if (\$parent->getScopeValue() != \$this->getScopeValue()) {
		throw new PropelException('Moving nodes across trees is not supported');
	}";
	}
	$script .= "
	if (\$parent->isDescendantOf(\$this)) {
		throw new PropelException('Cannot move a node as child of one of its subtree nodes.');
	}
	
	\$this->moveSubtreeTo(\$parent->getRightValue(), \$parent->getLevel() - \$this->getLevel() + 1, \$con);
	\$this->setParent(\$parent);
	
	return \$this;
}
";
	}

	protected function addMoveToPrevSiblingOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Moves current node and its subtree to be the previous sibling of \$sibling
 * The modifications in the current object and the tree are immediate
 *
 * @param      $objectClassname \$sibling	Propel object for sibling node
 * @param      PropelPDO \$con	Connection to use.
 *
 * @return     $objectClassname The current Propel object
 */
public function moveToPrevSiblingOf($objectClassname \$sibling, PropelPDO \$con = null)
{
	if (!\$this->isInTree()) {
		throw new PropelException('A $objectClassname object must be already in the tree to be moved. Use the insertAsPrevSiblingOf() instead.');
	}
	if (\$sibling->isRoot()) {
		throw new PropelException('Cannot move to previous sibling of a root node.');
	}";
	if ($this->behavior->useScope()) {
		$script .= "
	if (\$sibling->getScopeValue() != \$this->getScopeValue()) {
		throw new PropelException('Moving nodes across trees is not supported');
	}";
	}
	$script .= "
	if (\$sibling->isDescendantOf(\$this)) {
		throw new PropelException('Cannot move a node as sibling of one of its subtree nodes.');
	}
	
	\$this->moveSubtreeTo(\$sibling->getLeftValue(), \$sibling->getLevel() - \$this->getLevel(), \$con);
	\$this->setNextSibling(\$sibling);
	\$sibling->setPrevSibling(\$this);
	
	return \$this;
}
";
	}

	protected function addMoveToNextSiblingOf(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$script .= "
/**
 * Moves current node and its subtree to be the next sibling of \$sibling
 * The modifications in the current object and the tree are immediate
 *
 * @param      $objectClassname \$sibling	Propel object for sibling node
 * @param      PropelPDO \$con	Connection to use.
 *
 * @return     $objectClassname The current Propel object
 */
public function moveToNextSiblingOf($objectClassname \$sibling, PropelPDO \$con = null)
{
	if (!\$this->isInTree()) {
		throw new PropelException('A $objectClassname object must be already in the tree to be moved. Use the insertAsNextSiblingOf() instead.');
	}
	if (\$sibling->isRoot()) {
		throw new PropelException('Cannot move to next sibling of a root node.');
	}";
	if ($this->behavior->useScope()) {
		$script .= "
	if (\$sibling->getScopeValue() != \$this->getScopeValue()) {
		throw new PropelException('Moving nodes across trees is not supported');
	}";
	}
	$script .= "
	if (\$sibling->isDescendantOf(\$this)) {
		throw new PropelException('Cannot move a node as sibling of one of its subtree nodes.');
	}
	
	\$this->moveSubtreeTo(\$sibling->getRightValue() + 1, \$sibling->getLevel() - \$this->getLevel(), \$con);
	\$this->setPrevSibling(\$sibling);
	\$sibling->setNextSibling(\$this);
	
	return \$this;
}
";
	}
	
	protected function addMoveSubtreeTo(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Move current node and its children to location \$destLeft and updates rest of tree
 *
 * @param      int	\$destLeft Destination left value
 * @param      int	\$levelDelta Delta to add to the levels
 * @param      PropelPDO \$con		Connection to use.
 */
protected function moveSubtreeTo(\$destLeft, \$levelDelta, PropelPDO \$con = null)
{
	\$left  = \$this->getLeftValue();
	\$right = \$this->getRightValue();";
		if ($useScope) {
			$script .= "
	\$scope = \$this->getScopeValue();";
		}
		$script .= "

	\$treeSize = \$right - \$left +1;
	
	if (\$con === null) {
		\$con = Propel::getConnection($peerClassname::DATABASE_NAME, Propel::CONNECTION_WRITE);
	}
		
	\$con->beginTransaction();
	try {
		// make room next to the target for the subtree
		$peerClassname::shiftRLValues(\$treeSize, \$destLeft, null" . ($useScope ? ", \$scope" : "") . ", \$con);
	
		if (\$left >= \$destLeft) { // src was shifted too?
			\$left += \$treeSize;
			\$right += \$treeSize;
		}
		
		if (\$levelDelta) {
			// update the levels of the subtree
			$peerClassname::shiftLevel(\$levelDelta, \$left, \$right" . ($useScope ? ", \$scope" : "") . ", \$con);
		}
		
		// move the subtree to the target
		$peerClassname::shiftRLValues(\$destLeft - \$left, \$left, \$right" . ($useScope ? ", \$scope" : "") . ", \$con);
	
		// remove the empty room at the previous location of the subtree
		$peerClassname::shiftRLValues(-\$treeSize, \$right + 1, null" . ($useScope ? ", \$scope" : "") . ", \$con);
		
		// update all loaded nodes
		$peerClassname::updateLoadedNodes(\$con);
		
		\$con->commit();
	} catch (PropelException \$e) {
		\$con->rollback();
		throw \$e;
	}
}
";
	}

	protected function addDeleteDescendants(&$script)
	{
		$objectClassname = $this->objectClassname;
		$peerClassname = $this->peerClassname;
		$useScope = $this->behavior->useScope();
		$script .= "
/**
 * Deletes all descendants for the given node
 * Instance pooling is wiped out by this command, 
 * so existing $objectClassname instances are probably invalid (except for the current one)
 *
 * @param      PropelPDO \$con Connection to use.
 *
 * @return     int 		number of deleted nodes
 */
public function deleteDescendants(PropelPDO \$con = null)
{
	if(\$this->isLeaf()) {
		// save one query
		return;
	}
	if (\$con === null) {
		\$con = Propel::getConnection($peerClassname::DATABASE_NAME, Propel::CONNECTION_READ);
	}
	\$left = \$this->getLeftValue();
	\$right = \$this->getRightValue();";
		if ($useScope) {
			$script .= "
	\$scope = \$this->getScopeValue();";
		}
		$script .= "
	\$criteria = new Criteria($peerClassname::DATABASE_NAME);
	\$criteria->add($peerClassname::LEFT_COL, \$left, Criteria::GREATER_THAN);
	\$criteria->add($peerClassname::RIGHT_COL, \$right, Criteria::LESS_THAN);";
		if ($useScope) {
			$script .= "
	\$criteria->add($peerClassname::SCOPE_COL, \$scope, Criteria::EQUAL);";
		}
		$script .= "
	\$con->beginTransaction();
	try {
		// delete descendant nodes (will empty the instance pool)
		\$ret = $peerClassname::doDelete(\$criteria, \$con);
		
		// fill up the room that was used by descendants
		$peerClassname::shiftRLValues(\$left - \$right + 1, \$right, null" . ($useScope ? ", \$scope" : "") . ", \$con);
		
		// fix the right value for the current node, which is now a leaf
		\$this->setRightValue(\$left + 1);
		
		\$con->commit();
	} catch (Exception \$e) {
		\$con->rollback();
		throw \$e;
	}
	
	return \$ret;
}
";
	}

	protected function addGetIterator(&$script)
	{
		$script .= "
/**
 * Returns a pre-order iterator for this node and its children.
 *
 * @return     RecursiveIterator
 */
public function getIterator()
{
	return new NestedSetRecursiveIterator(\$this);
}
";
	}

	protected function addCompatibilityProxies(&$script)
	{
		$objectClassname = $this->objectClassname;
		$script .= "
/**
 * Alias for makeRoot(), for BC with Propel 1.4 nested sets
 *
 * @deprecated since 1.5
 * @see        makeRoot
 */
public function createRoot()
{
	return \$this->makeRoot();
}

/**
 * Alias for getParent(), for BC with Propel 1.4 nested sets
 *
 * @deprecated since 1.5
 * @see        getParent
 */
public function retrieveParent(PropelPDO \$con = null)
{
	return \$this->getParent(\$con);
}

/**
 * Alias for setParent(), for BC with Propel 1.4 nested sets
 *
 * @deprecated since 1.5
 * @see        setParent
 */
public function setParentNode($objectClassname \$parent = null)
{
	return \$this->setParent(\$parent);
}

/**
 * Alias for getPrevSibling(), for BC with Propel 1.4 nested sets
 *
 * @deprecated since 1.5
 * @see        getParent
 */
public function retrievePrevSibling(PropelPDO \$con = null)
{
	return \$this->getPrevSibling(\$con);
}

/**
 * Alias for getNextSibling(), for BC with Propel 1.4 nested sets
 *
 * @deprecated since 1.5
 * @see        getParent
 */
public function retrieveNextSibling(PropelPDO \$con = null)
{
	return \$this->getNextSibling(\$con);
}

/**
 * Alias for getFirstChild(), for BC with Propel 1.4 nested sets
 *
 * @deprecated since 1.5
 * @see        getParent
 */
public function retrieveFirstChild(PropelPDO \$con = null)
{
	return \$this->getFirstChild(null, \$con);
}

/**
 * Alias for getLastChild(), for BC with Propel 1.4 nested sets
 *
 * @deprecated since 1.5
 * @see        getParent
 */
public function retrieveLastChild(PropelPDO \$con = null)
{
	return \$this->getLastChild(null, \$con);
}

/**
 * Alias for getAncestors(), for BC with Propel 1.4 nested sets
 *
 * @deprecated since 1.5
 * @see        getAncestors
 */
public function getPath(PropelPDO \$con = null)
{
	return \$this->getAncestors(null, \$con);
}
";
	}
}