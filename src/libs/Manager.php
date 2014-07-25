<?php

namespace doublemcz\dibiorm;

class Manager
{
	const FLAG_INSTANCE_INSERT = 1;
	const FLAG_INSTANCE_DELETE = 2;
	const FLAG_INSTANCE_UPDATE = 3;

	/** @var \DibiConnection */
	protected $dibiConnection;
	/** @var string */
	protected $entityNamespace = NULL;
	/** @var array */
	protected $managedClasses = array();
	/** @var string */
	protected $proxiesPath;

	public function __construct($parameters, $cacheStorage)
	{
		$this->dibiConnection = new \DibiConnection($parameters['database']);
		if (empty($parameters['proxiesPath']) || !is_dir($parameters['proxiesPath'])) {
			throw new MissingArgumentException('You have to set valid proxy path. It\'s parameter proxiesPath');
		} else {
			$this->proxiesPath = $parameters['proxiesPath'];
		}

		if (!empty($parameters['entityNamespace'])) {
			$this->entityNamespace = $parameters['entityNamespace'];
		}
	}

	/**
	 * Finds an entity by given id. For multiple primary key you can pass next parameters by order definition in your entity.
	 *
	 * @param string $entityName
	 * @param mixed $id
	 * @throws \RuntimeException
	 * @return mixed
	 */
	public function find($entityName, $id)
	{
		$this->handleConnection();
		$entityAttributes = $this->createEntityAttributes($entityName);
		$args = func_get_args();
		unset($args[0]);
		if (count($entityAttributes->getPrimaryKey()) != count(array_values($args))) {
			throw new \RuntimeException('You try to find and entity with full primary key. Did you forget to specify an another value as an argument?');
		}

		$primaryKey = array_combine($entityAttributes->getPrimaryKey(), array_values($args));
		$data = $this->dibiConnection->select(array_keys($entityAttributes->getProperties()))
			->from($entityAttributes->getTable())
			->where($primaryKey)
			->fetch();

		$instance = DataHelperLoader::CreateFlatClass($this, $entityAttributes, $data);
		if ($instance) {
			$this->registerClass($instance, $entityAttributes, self::FLAG_INSTANCE_UPDATE);
		}

		return $instance;
	}

	/**
	 * @param object $entity
	 * @throws \RuntimeException
	 */
	public function persist($entity)
	{
		if (!is_object($entity)) {
			throw new \RuntimeException('Given value is not an object.');
		}

		$entityAttributes = $this->createEntityAttributes($entity);
		$this->registerClass($entity, $entityAttributes, self::FLAG_INSTANCE_INSERT);
	}

	/**
	 * @param object $entity
	 * @throws \RuntimeException
	 */
	public function delete($entity)
	{
		if (!is_object($entity)) {
			throw new \RuntimeException('Given value is not an object');
		}

		$classKey = spl_object_hash($entity);
		if (FALSE === array_key_exists($classKey, $this->managedClasses)) {
			throw new \RuntimeException('You are trying to delete an entity that is not persisted. Did you fetch it from database?');
		}

		$this->managedClasses[$classKey]['flag'] = self::FLAG_INSTANCE_DELETE;
	}

	/**
	 * @param object $instance
	 */
	public function flush($instance = NULL)
	{
		if ($instance) {
			$classContainer = $this->getInstanceFromManagedClasses($instance);
			$this->processInstanceChanges(
				$instance,
				$classContainer['flag'],
				!empty($classContainer['valueHash'])
					? $classContainer['valueHash']
					: NULL
			);
		} else {
			foreach ($this->managedClasses as $class) {
				$this->processInstanceChanges($class['instance'], $class['flag'], $class['valueHash']);
			}
		}
	}

	/**
	 * @param string $entityName And identifier like 'User', 'Article', etc...
	 * @return RepositoryManager
	 */
	public function getRepository($entityName)
	{
		return new RepositoryManager($this, $this->createEntityAttributes($entityName));
	}

	/**
	 * @param object $instance
	 * @return array
	 * @throws \RuntimeException
	 */
	private function getInstanceFromManagedClasses($instance)
	{
		$classKey = spl_object_hash($instance);
		if (!array_key_exists($classKey, $this->managedClasses)) {
			throw new \RuntimeException('You try to get instance flag of class that is not managed');
		}

		return $this->managedClasses[$classKey];
	}

	/**
	 * @param object $instance
	 * @param int $flag
	 * @param null $valueHash
	 * @return mixed
	 * @throws \RuntimeException
	 */
	private function processInstanceChanges($instance, $flag, $valueHash = NULL)
	{
		$entityAttributes = $this->createEntityAttributes($instance);
		switch ($flag) {
			case self::FLAG_INSTANCE_INSERT :
				return $this->insertItem($instance, $entityAttributes);
			case self::FLAG_INSTANCE_DELETE :
				return $this->deleteItem($instance, $entityAttributes);
			case self::FLAG_INSTANCE_UPDATE :
				return $this->updateItem($instance, $entityAttributes, $valueHash);
			default:
				throw new \RuntimeException(sprintf('Unknown flag action. Given %s' . $flag ? : ' NULL'));
		}
	}

	/**
	 * @param object $instance
	 * @param EntityAttributes $entityAttributes
	 * @return \DibiResult|int
	 */
	private function deleteItem($instance, EntityAttributes $entityAttributes)
	{
		$affectedRows = $this->dibiConnection
			->delete($entityAttributes->getTable())
			->where($this->buildPrimaryKey($instance, $entityAttributes))
			->execute(\dibi::AFFECTED_ROWS);

		unset($this->managedClasses[spl_object_hash($instance)]);

		return $affectedRows;
	}

	/**
	 * @param object $instance
	 * @param EntityAttributes $entityAttributes
	 * @throws \RuntimeException
	 * @return \DibiResult|int
	 */
	private function insertItem($instance, EntityAttributes $entityAttributes)
	{
		$values = $this->getInstanceValueMap($instance, $entityAttributes);
		$insertId = $this->dibiConnection->insert($entityAttributes->getTable(), $values)->execute(\dibi::IDENTIFIER);
		if ($entityAttributes->getAutoIncrementFieldName()) {
			if (!$insertId) {
				throw new \RuntimeException('Entity has set autoIncrement flag but no incremented values was returned from DB.');
			}

			DataHelperLoader::setPropertyValue($instance, $entityAttributes->getAutoIncrementFieldName(), $insertId);
		}

		$classKey = spl_object_hash($instance);
		$this->managedClasses[$classKey]['flag'] = self::FLAG_INSTANCE_UPDATE;
		$this->managedClasses[$classKey]['valueHash'] = $this->getInstanceValuesHash($instance, $entityAttributes);

		return $insertId;
	}

	/**
	 * @param object $instance
	 * @param EntityAttributes $entityAttributes
	 * @param string $originValueHash
	 * @return bool
	 */
	private function updateItem($instance, EntityAttributes $entityAttributes, $originValueHash)
	{
		if ($originValueHash == $this->getInstanceValuesHash($instance, $entityAttributes)) {
			return FALSE;
		}

		$values = $this->getInstanceValueMap($instance, $entityAttributes);

		return $this->dibiConnection->update($entityAttributes->getTable(), $values)->where($this->buildPrimaryKey($instance, $entityAttributes))->execute(\dibi::AFFECTED_ROWS) == 1;
	}

	private function getInstanceValueMap($instance, EntityAttributes $entityAttributes)
	{
		$values = array();
		foreach (array_keys($entityAttributes->getProperties()) as $propertyName) {
			$values[$propertyName] = (string)DataHelperLoader::getPropertyValue($instance, $propertyName);
		}

		return $values;
	}

	public function registerClass($instance, EntityAttributes $entityAttributes, $flag)
	{
		$hashedKey = spl_object_hash($instance);
		$this->managedClasses[$hashedKey] = array(
			'instance' => $instance,
			'valueHash' => $this->getInstanceValuesHash($instance, $entityAttributes),
			'flag' => $flag,
		);
	}

	/**
	 * @param object $instance
	 * @param EntityAttributes $entityAttributes
	 * @return string
	 */
	protected function getInstanceValuesHash($instance, EntityAttributes $entityAttributes)
	{
		$values = array();
		foreach (array_keys($entityAttributes->getProperties()) as $propertyName) {
			$values[] = (string)DataHelperLoader::getPropertyValue($instance, $propertyName);
		}

		return md5(serialize($values));
	}

	/**
	 * @param object $instance
	 * @param EntityAttributes $entityAttributes
	 * @return array
	 */
	protected function buildPrimaryKey($instance, EntityAttributes $entityAttributes)
	{
		$primaryKey = $entityAttributes->getPrimaryKey();
		$values = array();
		foreach ($primaryKey as $propertyName) {
			$values[] = DataHelperLoader::getPropertyValue($instance, $propertyName);
		}

		return array_combine($entityAttributes->getPrimaryKey(), $values);
	}

	/**
	 * Returns instance of EntityAttributes based on given argument
	 *
	 * @param string|object $entityName Can be name of the class of instance itself
	 * @return EntityAttributes
	 */
	protected function createEntityAttributes($entityName)
	{
		return new EntityAttributes($this->getEntityClassName($entityName));
	}

	public function createProxy($className)
	{
		if (!class_exists($className)) {
			throw new ClassNotFoundException('You have to pass valid class name');
		}
	}

	/**
	 * @param string|object $entityName
	 * @return string
	 */
	public function getEntityClassName($entityName)
	{
		if (is_object($entityName)) {
			$className = get_class($entityName);
		} else {
			$className = $this->entityNamespace
				? ($this->entityNamespace . '\\' . $entityName)
				: $entityName;
		}

		return $className;
	}

	public function createQuery()
	{
		return new QueryBuilder($this);
	}

	/**
	 * @return \DibiConnection
	 */
	public function getDibiConnection()
	{
		return $this->dibiConnection;
	}

	private function handleConnection()
	{
		if (!$this->dibiConnection->isConnected()) {
			$this->dibiConnection->connect();
		}
	}

	/**
	 * @param string $namespace
	 */
	public function setEntityNamespace($namespace)
	{
		$this->entityNamespace = $namespace;
	}

	/**
	 * @return string
	 */
	public function getProxiesPath()
	{
		return $this->proxiesPath;
	}
}