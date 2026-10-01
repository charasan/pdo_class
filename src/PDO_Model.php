<?php

    namespace PDO_Model;

    use ArgumentCountError;
    use InvalidArgumentException;
    use PDO;
    use PDOException;
    use PDOStatement;
    use Throwable;

    /**
     * Class PDO_Model
     *
     * @package classes
     */
    abstract class PDO_Model
    {
        protected const int QUERY_TYPE_UPDATE = 0;
        protected const int QUERY_TYPE_INSERT = 1;

        public const int ROWSTATE_DELETED_ROW = 999;
        public const int ROWSTATE_PUBLISHED = 1;
        public const int ROWSTATE_UNPUBLISHED = 0;

        public const int PDO_ERROR_CODE_SQLSTATE = 0;
        public const int PDO_ERROR_CODE_DRIVER_SPECIFIC = 1;
        public const int PDO_ERROR_CODE_MESSAGE = 2;

        public const int RETURN_TYPE_SINGLE_VALUE = 0;
        public const int RETURN_TYPE_ARRAY = 1;
        public const int RETURN_TYPE_STATEMENT = 2;
        public const int RETURN_TYPE_RUN_ONLY = 3;

        public const string COMPARISON_LIKE = 'LIKE';
        public const string COMPARISON_IS_NULL = 'IS NULL';
        public const string COMPARISON_IS_NOT_NULL = 'IS NOT NULL';

        public const array COMPARISON_OPERATORS = [
            '=',
            '!=',
            '<>',
            '<=>',
            '>',
            '<',
            '<=',
            '>=',
            'is null',
            'is not null',
            'like'
        ];

        public const string ORDER_BY_DIR_DESC = 'DESC';
        public const string ORDER_BY_DIR_ASC = 'ASC';

        public const string WHERE_CLAUSE_WHERE = 'WHERE';
        public const string WHERE_CLAUSE_AND = 'AND';
        public const string WHERE_CLAUSE_OR = 'OR';
        public const array WHERE_CLAUSE_TYPES = [
            self::WHERE_CLAUSE_AND,
            self::WHERE_CLAUSE_WHERE,
            self::WHERE_CLAUSE_OR
        ];

        // A column or table name, optionally qualified: user_id, u.user_id
        private const string IDENTIFIER_PATTERN = '/^[A-Za-z_][\w.]*$/';
        // One item of a select list: *, u.*, a column, or a simple aggregate, with an optional alias
        private const string SELECT_ITEM_PATTERN =
            '/^(\*|[A-Za-z_][\w.]*(\.\*)?|(COUNT|SUM|MIN|MAX|AVG)\((\*|[A-Za-z_][\w.]*)\))(\s+AS\s+[A-Za-z_]\w*)?$/i';

        public PDO $DBObj;

        /**
         * The table this model is keyed to. Concrete subclasses set this, either
         * as a property default or in their own constructor.
         */
        protected string $table = '';
        protected string $select = '*';
        protected string $where = '';
        protected string $join = '';
        protected string $orderBy = '';
        protected string $groupBy = '';
        protected string $limit = '';
        protected array $bindParams = [];
        protected PDOStatement $_stmt;

        /**
         * PDO_Model constructor.
         *
         * @param array $connectionOptions
         * @param array $dbOptions
         */
        public function __construct(array $connectionOptions = [], array $dbOptions = [])
        {
            $connectionInfo = [
                'Host' => '',
                'Port' => '',
                'Database' => '',
                'User' => '',
                'Password' => '',
                'Driver' => 'mysql'
            ];

            $connectionInfo = array_replace($connectionInfo, $connectionOptions);

            if (empty($connectionOptions)) { // use the env values
                $connectionInfo['Host'] = $_SERVER['CHAR_DB_HOST'] ?? '';
                $connectionInfo['User'] = $_SERVER['CHAR_DB_USER'] ?? '';
                $connectionInfo['Password'] = $_SERVER['CHAR_DB_PASS'] ?? '';
                $connectionInfo['Database'] = $_SERVER['CHAR_DB_DBNAME'] ?? '';
            }
            $dsn = $connectionInfo['Driver'] . ':host=' . $connectionInfo['Host'] . (!empty($connectionInfo['Port']) ?
                    (';port=' . $connectionInfo['Port']) : '') . ';dbname=' . $connectionInfo['Database'] . ';charset=utf8mb4';

            $defaultOptions = [
                PDO::ATTR_EMULATE_PREPARES => false, // turn off emulation mode for "real" prepared statements
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, //turn on errors in the form of exceptions
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, //make the default fetch be an associative array
            ];
            $options = array_replace($defaultOptions, $dbOptions);

            try {
                $this->DBObj = new PDO($dsn, $connectionInfo['User'], $connectionInfo['Password'], $options);
            } catch (Throwable $throwable) {
                error_log($throwable->getMessage() . ": " . $throwable->getTraceAsString());
                throw new PDOException('Unable to connect with our database at this time. Please try again later.');
            }
        }

        /**
         * @param string $str
         *
         * @return string
         */
        final public function cleanData(string $str = ''): string
        {
            $quoted = $this->DBObj->quote($str);

            if ($quoted === false) {
                throw new PDOException('The current driver cannot quote values.');
            }

            return $quoted;
        }


        /**
         * USE WITH CAUTION
         * This takes a raw SQL query and runs it AS IS, with no binding or checks.
         *
         * @param string $sql
         *
         * @return array Empty when the query matched no rows.
         */
        final public function queryRaw(string $sql = ''): array
        {
            return $this->DBObj->query($sql)->fetchAll();
        }

        /**
         * Set specific selects for your query.
         * Defaults to '*'
         *
         * @param string $select
         *
         * @return PDO_Model
         */
        final public function addSelect(string $select = '*'): PDO_Model
        {
            $select = preg_replace('/^select /i', '', trim($select), 1) ?? '';
            foreach (explode(',', $select) as $item) {
                if (!preg_match(self::SELECT_ITEM_PATTERN, trim($item))) {
                    throw new InvalidArgumentException('Invalid select item: ' . $item);
                }
            }
            $this->select = $select;
            return $this;
        }

        /**
         * @param string $groupBy
         *
         * @return $this
         */
        final public function addGroupBy(string $groupBy): PDO_Model
        {
            $cols = trim(preg_replace('/^group by/i', '', $groupBy, 1) ?? '');
            $this->groupBy = ($cols !== '') ? 'GROUP BY ' . $this->checkColumnList($cols) : '';
            return $this;
        }

        /**
         * Puts together WHERE statements in the form of '$col $comparator $val'
         * Such as, 'value = 1' - WHERE and AND are auto included - use the $clause
         * option for others
         *
         * @param string $col
         * @param string $comparator
         * @param mixed $val
         * @param string $clause
         *
         * @return PDO_Model|null
         */
        final public function addWhere(
            string $col,
            string $comparator = '=',
            mixed $val = false,
            string $clause = ''
        ): ?PDO_Model
        {
            $col = preg_replace('/^where /i', '', $col, 1) ?? '';
            $col = preg_replace('/^and /i', '', $col, 1) ?? '';
            $col = $this->checkIdentifier(preg_replace('/^or /i', '', $col, 1) ?? '');

            // test the comparison being passed in
            if (!in_array(strtolower($comparator), self::COMPARISON_OPERATORS)) {
                throw new PDOException('For more complex queries, use one of the more advanced methods.');
            }
            $isNullCheck = in_array(
                strtoupper($comparator),
                [self::COMPARISON_IS_NULL, self::COMPARISON_IS_NOT_NULL]
            );
            if (strtoupper($comparator) == self::COMPARISON_LIKE) {
                $val = '%' . $val . '%';
            } elseif ($isNullCheck && !empty($val)) {
                throw new PDOException('Value should not be passed for this.');
            }

            // we stripped any passed in where/and/or/etc before
            // Now *we* add it so we can keep this under control.
            if (empty($this->where)) {
                $this->where .= self::WHERE_CLAUSE_WHERE . ' ';
            } elseif (empty($clause)) {
                $this->where .= self::WHERE_CLAUSE_AND . ' ';
            } elseif (!in_array(strtoupper($clause), self::WHERE_CLAUSE_TYPES)) {
                throw new PDOException('Invalid conditional type passed to where statement.');
            } else {
                $this->where .= strtoupper($clause) . ' ';
            }
            $this->where .= $col . ' ' . $comparator . ' ';
            if (!$isNullCheck) {
                // IS NULL takes no value, so there's nothing to bind
                $this->where .= ':' . $this->addBindParam($col, $val) . ' ';
            }

            return $this;
        }

        /**
         * @param string $joinedTable The table to be joined in the statement
         * @param string $tableNickname Optional alias for the joined table
         * @param array $onStatement Array of conditions for the ON statement of the join - added to bound parameters
         *
         * @return PDO_Model
         * @todo Expand onStatement to allow more comparisons beyond the forced equal
         */
        final public function addJoin(
            string $joinedTable,
            string $tableNickname = '',
            array $onStatement = []
        ): PDO_Model {
            $joinedTable = $this->checkIdentifier(preg_replace('/^join /i', '', trim($joinedTable), 1) ?? '');
            $this->join .= ' JOIN ' . $joinedTable;
            if ($tableNickname !== '') {
                $this->join .= ' ' . $this->checkIdentifier($tableNickname);
            }

            $conditions = [];
            foreach ($onStatement as $col => $joinParam) {
                $col = $this->checkIdentifier($col);
                $conditions[] = $col . ' = :' . $this->addBindParam($col, $joinParam);
            }
            if (!empty($conditions)) {
                $this->join .= ' ON ' . implode(' AND ', $conditions);
            }
            return $this;
        }

        /**
         * @param string $cols
         * @param string $dir
         *
         * @return $this|null
         */
        final public function addOrderBy(string $cols, string $dir = self::ORDER_BY_DIR_DESC): PDO_Model
        {
            $dir = strtoupper($dir);
            if (!in_array($dir, [self::ORDER_BY_DIR_ASC, self::ORDER_BY_DIR_DESC])) {
                throw new InvalidArgumentException('Order by direction must be ASC or DESC.');
            }
            $this->orderBy = 'ORDER BY ' . $this->checkColumnList($cols) . ' ' . $dir;
            return $this;
        }

        /**
         * @param int $limitStart
         * @param int $limitEnd
         *
         * @return $this
         */
        final public function addLimit(int $limitStart, int $limitEnd = -1): PDO_Model
        {
            $this->limit = 'LIMIT ' . $limitStart;
            if ($limitEnd >= 0) {
                $this->limit .= ',' . $limitEnd;
            }
            return $this;
        }

        /**
         * @param string $sql
         * @param array $data
         * @param int $retType
         *
         * @return mixed
         */
        final public function preparedQuery(string $sql, array $data, int $retType = self::RETURN_TYPE_ARRAY): mixed
        {
            $stmt = $this->DBObj->prepare($sql);
            if ($success = $stmt->execute($data)) {
                $this->_stmt = $stmt;
            }
            return match ($retType) {
                self::RETURN_TYPE_SINGLE_VALUE => $stmt->fetch(),
                self::RETURN_TYPE_ARRAY => $stmt->fetchAll(),
                self::RETURN_TYPE_STATEMENT => $stmt,
                self::RETURN_TYPE_RUN_ONLY => $success,
                default => false,
            };
        }

        /**
         * @param string $sql
         * @param array $data
         *
         * @return mixed
         */
        final public function preparedQueryScalar(string $sql, array $data): mixed
        {
            return $this->preparedQuery($sql, $data, self::RETURN_TYPE_SINGLE_VALUE);
        }

        /**
         * @param string $sql
         * @param array $data
         *
         * @return bool
         */
        final public function runPreparedQuery(string $sql, array $data): bool
        {
            $ret = $this->preparedQuery($sql, $data, self::RETURN_TYPE_RUN_ONLY);
            $this->clearMethodVars();
            return $ret;
        }

        final public function getResultScalar(): ?array
        {
            $sql = $this->buildSelectQuery();
            $ret = $this->preparedQuery($sql, $this->bindParams, self::RETURN_TYPE_SINGLE_VALUE);
            $this->clearMethodVars();
            // fetch() returns false when no row matched
            return ($ret === false) ? null : $ret;
        }

        final public function isPublished(): PDO_Model
        {
            return $this->addWhere('rowstate', '=', self::ROWSTATE_PUBLISHED);
        }

        final public function isNotPublished(): PDO_Model
        {
            return $this->addWhere('rowstate', '=', self::ROWSTATE_UNPUBLISHED);
        }

        final public function isDeleted(): PDO_Model
        {
            return $this->addWhere('rowstate', '=', self::ROWSTATE_DELETED_ROW);
        }

        final public function isNotDeleted(): PDO_Model
        {
            return $this->addWhere('rowstate', '<>', self::ROWSTATE_DELETED_ROW);
        }

        /**
         * Use this instead of getResultsObject to remove an item.
         * TODO: organize this so the methods can be strung together in a logical LTR readable fashion
         *
         * @return int
         */
        final public function deleteData(): int
        {
            if (empty($this->where)) {
                throw new PDOException('This tool cannot be used to delete data indiscriminately.');
            }
            // In this library we set the rowstate to 999 rather than deleting, to preserve data.
            return $this->simpleUpdate(['rowstate'], [self::ROWSTATE_DELETED_ROW]);
        }

        /**
         * @param string $classObj
         *
         * @return object
         */
        final public function getResultsObject(string $classObj = ''): object
        {
            $sql = $this->buildSelectQuery();
            $ret = $this->preparedQuery($sql, $this->bindParams);
            if ($classObj !== '') {
                if (class_exists($classObjName = 'mappers\\' . $classObj)) {
                    $ret = call_user_func([$classObjName, 'getInstance'], $ret);
                }
            }
            $this->clearMethodVars();
            return (object)$ret;
        }

        /**
         * A simple way to do simple inserts. More advanced should use the long way.
         *  Arg1 is an array of the columns to be inserted - nothing is assumed (outside of auto_increment)
         *  Arg2 is the array of values. These should line up between the two arrays.
         *
         * @param array $cols
         * @param array $vals
         *
         * @return int The newly created auto_increment ID is returned.
         */
        final public function simpleInsert(array $cols, array $vals): int
        {
            if (count($cols) !== count($vals)) {
                throw new ArgumentCountError('Mismatch of values in INSERT statement.');
            }
            $placeholders = [];
            foreach (array_values($cols) as $x => $colName) {
                $this->checkIdentifier($colName);
                $placeholders[] = ':' . $this->addBindParam($colName, $vals[$x]);
            }
            $sql = 'INSERT INTO ' . $this->tableName() . ' (' . implode(',', $cols) . ') VALUES(' .
                implode(',', $placeholders) . ');';
            return $this->runSimpleQueries($sql, self::QUERY_TYPE_INSERT);
        }

        /**
         * Runs very simple updates by passing in arrays of column names along with updated values
         * Returns affected rows count
         *
         * @param array $cols
         * @param array $vals
         *
         * @return int The number rows updated is returned
         */
        final public function simpleUpdate(array $cols, array $vals): int
        {
            if (empty($this->where)) {
                throw new PDOException('This tool cannot be used to update all table data indiscriminately.');
            }
            if (count($cols) !== count($vals)) {
                throw new ArgumentCountError('Mismatch of values in UPDATE statement');
            }

            // addBindParam() hands back a key that doesn't collide with the WHERE
            // params, so SET rowstate and WHERE rowstate each get their own value.
            $setParts = [];
            foreach (array_values($cols) as $x => $colName) {
                $setParts[] = $this->checkIdentifier($colName) . '=:' . $this->addBindParam($colName, $vals[$x]);
            }
            $sql = 'UPDATE ' . $this->tableName() . ' SET ' . implode(',', $setParts) . ' ' . $this->where;
            return $this->runSimpleQueries($sql, self::QUERY_TYPE_UPDATE);
        }

        /**
         * Internal method to run simple insert/update and return results
         * Returns lastInsertID for inserts, and affected rows count for updates.
         *
         * @param string $sql
         * @param int $queryType
         *
         * @return int
         */
        private function runSimpleQueries(string $sql, int $queryType): int
        {
            $this->runPreparedQuery($sql, $this->bindParams);
            if ($queryType === self::QUERY_TYPE_INSERT) {
                return $this->DBObj->lastInsertId();
            } else {
                return $this->_stmt->rowCount();
            }
        }

        /**
         * @return string
         */
        private function buildSelectQuery(): string
        {
            $sql = 'SELECT ' . trim($this->select) . ' ';
            $sql .= 'FROM ' . $this->tableName() . ' ';
            if (!empty($this->join)) {
                $sql .= trim($this->join) . ' ';
            }
            $sql .= trim($this->where) . ' ';

            if (!empty($this->groupBy)) {
                $sql .= trim($this->groupBy) . ' ';
            }

            if (!empty($this->orderBy)) {
                $sql .= trim($this->orderBy) . ' ';
            }

            if (!empty($this->limit)) {
                $sql .= trim($this->limit) . ' ';
            }
            return $sql;
        }

        private function getBindedPlaceholder(string $colName): string
        {
            return str_replace([' ', '_', '-', '.'], '', ucwords($colName));
        }

        /**
         * Binds a value and returns the placeholder key it was stored under.
         * Callers must use the returned key in their SQL: if the column is
         * already bound (rowstate twice, say) a number is appended so the
         * earlier value isn't overwritten.
         *
         * @param string $col
         * @param mixed $val
         *
         * @return string
         */
        private function addBindParam(string $col, mixed $val): string
        {
            $baseKey = $this->getBindedPlaceholder($col);
            $colKey = $baseKey;
            $inc = 1;
            while (array_key_exists($colKey, $this->bindParams)) {
                $colKey = $baseKey . (++$inc);
            }

            $this->bindParams[$colKey] = $val;
            return $colKey;
        }

        /**
         * Clear out the instance so a fresh query can be ran.
         */
        private function clearMethodVars(): void
        {
            $this->select = '*';
            $this->where = '';
            $this->bindParams = [];
            $this->join = '';
            $this->limit = '';
            $this->orderBy = '';
            $this->groupBy = '';
        }

        /**
         * Guards against a subclass that never set $table.
         *
         * @return string
         */
        private function tableName(): string
        {
            if ($this->table === '') {
                throw new PDOException(
                    static::class . ' must set the protected $table property before running a query.'
                );
            }

            return $this->table;
        }

        /**
         * Identifiers (columns, tables) can't be bound, so anything that ends up
         * in the SQL as a name has to match IDENTIFIER_PATTERN.
         *
         * @param string $name
         *
         * @return string The trimmed name
         */
        private function checkIdentifier(string $name): string
        {
            $name = trim($name);
            if (!preg_match(self::IDENTIFIER_PATTERN, $name)) {
                throw new InvalidArgumentException('Invalid identifier: ' . $name);
            }
            return $name;
        }

        /**
         * Checks a comma separated list of columns, as used by ORDER BY and GROUP BY.
         *
         * @param string $cols
         *
         * @return string
         */
        private function checkColumnList(string $cols): string
        {
            return implode(', ', array_map([$this, 'checkIdentifier'], explode(',', $cols)));
        }
    }
