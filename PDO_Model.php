<?php

    namespace PDO_Model;

    use ArgumentCountError;
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
        protected const QUERY_TYPE_UPDATE = 0;
        protected const QUERY_TYPE_INSERT = 1;

        public const ROWSTATE_DELETED_ROW = 999;
        public const ROWSTATE_PUBLISHED = 1;
        public const ROWSTATE_UNPUBLISHED = 0;

        public const PDO_ERROR_CODE_SQLSTATE = 0;
        public const PDO_ERROR_CODE_DRIVER_SPECIFIC = 1;
        public const PDO_ERROR_CODE_MESSAGE = 2;

        public const RETURN_TYPE_SINGLE_VALUE = 0;
        public const RETURN_TYPE_ARRAY = 1;
        public const RETURN_TYPE_STATEMENT = 2;
        public const RETURN_TYPE_RUN_ONLY = 3;

        public const COMPARISON_LIKE = 'LIKE';
        public const COMPARISON_IS_NULL = 'IS NULL';
        public const COMPARISON_IS_NOT_NULL = 'IS NOT NULL';

        public const COMPARISON_OPERATORS = [
            '=',
            '!=',
            '<>',
            '<=>',
            '>',
            '<',
            '<=',
            '>=',
            'in',
            'not',
            'between',
            'is null',
            'is not null',
            'like',
            'exists'
        ];

        public const ORDER_BY_DIR_DESC = 'DESC';
        public const ORDER_BY_DIR_ASC = 'ASC';

        public const WHERE_CLAUSE_WHERE = 'WHERE';
        public const WHERE_CLAUSE_AND = 'AND';
        public const WHERE_CLAUSE_OR = 'OR';
        public const WHERE_CLAUSE_IN = 'IN';
        public const WHERE_CLAUSE_NOT_IN = 'NOT IN';
        public const WHERE_CLAUSE_TYPES = [
            self::WHERE_CLAUSE_AND,
            self::WHERE_CLAUSE_WHERE,
            self::WHERE_CLAUSE_IN,
            self::WHERE_CLAUSE_NOT_IN,
            self::WHERE_CLAUSE_OR
        ];

        public PDO $DBObj;

        protected string $table;
        public bool $allowLogging = true; // user tables get a no
        protected string $select = '*';
        protected string $where = '';
        protected string $join = '';
        protected string $orderBy = '';
        protected string $groupBy = '';
        protected string $limit = '';
        protected array $bindParams = [];
        private bool $isDelete = false;
        protected PDOStatement $_stmt;

        /**
         * PDO_Model constructor.
         *
         * @param array $connectionOptions
         * @param array $dbOptions
         */
        public function __construct(array $connectionOptions = [], array $dbOptions = [])
        {
            $this->allowLogging = (bool)$_SERVER['SITE_DEBUG'];

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
                try {
                    $connectionInfo['Host'] = $_SERVER['CHAR_DB_HOST'];
                    $connectionInfo['User'] = $_SERVER['CHAR_DB_USER'];
                    $connectionInfo['Password'] = $_SERVER['CHAR_DB_PASS'];
                    $connectionInfo['Database'] = $_SERVER['CHAR_DB_DBNAME'];
                } catch (Throwable $throwable) {
                    error_log($throwable->getMessage() . ": " . $throwable->getTraceAsString());
                    exit('Unable to retrieve database credentials at this time. Please try again later.');
                }
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
            return $this->DBObj->quote($str);
        }


        /**
         * USE WITH CAUTION
         * This takes a raw SQL query and runs it AS IS.
         * The query in question is logged.
         *
         * @param string $sql
         *
         * @return array|bool
         */
        final public function queryRaw(string $sql = ''): array
        {
            $res = $this->DBObj->query($sql)->fetchAll();

            if (!$res) {
                return false;
            }
            return $res;
        }

        /**
         * Set specific selects for your query.
         * Defaults to '*'
         *
         * @param string $select
         *
         * @return PDO_Model|null
         */
        final public function addSelect(string $select = '*'): ?PDO_Model
        {
            if ($this->passesSecurityCheck($select, 'SELECT')) {
                $this->select = preg_replace('/^select /i', '', $select, 1) ?? '*';
                return $this;
            }
            return null;
        }

        /**
         * @param string $groupBy
         *
         * @return $this|null
         */
        final public function addGroupBy(string $groupBy): ?PDO_Model
        {
            if ($this->passesSecurityCheck($groupBy, 'GROUP_BY')) {
                $this->groupBy = preg_replace('/^group by/i', '', $groupBy, 1) ?? '';
                return $this;
            }
            return null;
        }

        /**
         * Puts together WHERE statements in the form of '$col $comparator $val'
         * Such as, 'value = 1' - WHERE and AND are auto included - use the $clause
         * option for others
         *
         * @param string $col
         * @param string $comparator
         * @param string|bool $val
         * @param string $clause
         *
         * @return PDO_Model|null
         */
        final public function addWhere(string $col, string $comparator = '=', $val = false, $clause = ''): ?PDO_Model
        {
            $closePar = false;
            $col = preg_replace('/^where /i', '', $col, 1) ?? '';
            $col = preg_replace('/^and /i', '', $col, 1) ?? '';
            $col = preg_replace('/^or /i', '', $col, 1) ?? '';

            // test the comparison being passed in
            if (!in_array(strtolower($comparator), self::COMPARISON_OPERATORS)) {
                throw new PDOException('For more complex queries, use one of the more advanced methods.');
            }
            if (strtoupper($comparator) == self::COMPARISON_LIKE) {
                $val = '%' . $val . '%';
            } elseif ((strtoupper($comparator) === self::COMPARISON_IS_NOT_NULL || strtoupper(
                        $comparator
                    ) === self::COMPARISON_IS_NULL) && !empty($val)) {
                throw new PDOException('Value should not be passed for this.');
            }

            // we stripped any passed in where/and/or/etc before
            // Now *we* add it so we can keep this under control.
            if (empty($this->where)) {
                $this->where .= self::WHERE_CLAUSE_WHERE . ' ';
            } elseif (empty($clause)) {
                $this->where .= self::WHERE_CLAUSE_AND . ' ';
            } elseif (in_array(strtoupper($clause), [self::WHERE_CLAUSE_NOT_IN, self::WHERE_CLAUSE_IN])) {
                $this->where .= strtoupper($clause) . ' (';
                $closePar = true;
            } elseif (!in_array(strtoupper($clause), self::WHERE_CLAUSE_TYPES)) {
                throw new PDOException('Invalid conditional type passed to where statement.');
            } else {
                $this->where .= strtoupper($clause) . ' ';
            }
            $this->addBindParam($col, $val);
            $this->where .= $col . ' ' . $comparator . ' ' . (($val !== false) ? $this->getBindedPlaceholder(
                    $col,
                    true
                ) : '') . ' ';
            if ($closePar) {
                $this->where .= ')';
            }

            return $this;
        }

        /**
         * @param string $joinedTable The table to be joined in the statement - can/should add alias with a space in name
         * @param string $tableNickname
         * @param array $onStatement Array of conditions for the ON statement of the join - added to bound parameters
         *
         * @return PDO_Model|null
         * @todo Expand onStatement to allow more comparisons beyond the forced equal
         */
        final public function addJoin(
            string $joinedTable,
            string $tableNickname = '',
            array $onStatement = []
        ): ?PDO_Model {
            if ($this->passesSecurityCheck($joinedTable, 'JOIN')) {
                $this->join = preg_replace('/^join /i', '', $joinedTable, 1) ?? '*';
                foreach ($onStatement as $col => $joinParam) {
                    $this->join .= 'JOIN ' . $joinedTable . (!empty($tableNickname) ? $tableNickname : '') .
                        ' ON ' . $col . ' = ' . $this->getBindedPlaceholder($col);
                    $this->addBindParam($col, $joinParam);
                }
                return $this;
            }
            return null;
        }

        /**
         * @param string $cols
         * @param string $dir
         *
         * @return $this|null
         */
        final public function addOrderBy(string $cols, $dir = self::ORDER_BY_DIR_DESC): ?PDO_Model
        {
            $this->orderBy = 'ORDER BY ' . $cols . ' ' . $dir;
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
         * @return mixed|array|bool
         */
        final public function preparedQuery(string $sql, array $data, int $retType = self::RETURN_TYPE_ARRAY)
        {
            $stmt = $this->DBObj->prepare($sql);
            if ($success = $stmt->execute($data)) {
                $this->_stmt = $stmt;
            }
            switch ($retType) {
                case self::RETURN_TYPE_SINGLE_VALUE:
                    return $stmt->fetch();
                case self::RETURN_TYPE_ARRAY:
                    $ret = $stmt->fetchAll();
                    if (sizeof($ret) == 1) {
                        $ret = $ret[0];
                    }
                    return $ret;
                case self::RETURN_TYPE_RUN_ONLY:
                    return $success;
            }
            return false;
        }

        /**
         * @param string $sql
         * @param array $data
         *
         * @return mixed
         */
        final public function preparedQueryScalar(string $sql, array $data)
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
            return $ret;
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
            return $this->simpleUpdate(['rowstate'], [self::ROWSTATE_DELETED_ROW]);
            /*          // For a formal delete - in this library we set the rowstate to 999 to preserve data.
                        $this->isDelete = true;
                        $sql = $this->buildSelectQuery();
                        $this->isDelete = false;
                        $this->runPreparedQuery($sql, $this->bindParams);
                        return $this->_stmt->rowCount(); */
        }

        /**
         * @param string $classObj
         *
         * @return array|object
         */
        final public function getResultsObject(string $classObj = '')
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
            if (sizeof($cols) !== sizeof($vals)) {
                throw new ArgumentCountError('Mismatch of values in INSERT statement.');
            }
            $sql = 'INSERT INTO ' . $this->table . ' (' . implode(',', $cols) . ') VALUES(';
            $valStr = '';
            foreach ($cols as $colName) {
                if (!empty($valStr)) {
                    $valStr .= ',';
                }
                $valStr .= $this->getBindedPlaceholder($colName, true);
            }
            $sql .= $valStr . ');';
            return $this->runSimpleQueries($sql, $cols, $vals, self::QUERY_TYPE_INSERT);
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
            if (sizeof($cols) !== sizeof($vals)) {
                throw new ArgumentCountError('Mismatch of values in UPDATE statement');
            }

            $sql = 'UPDATE ' . $this->table . ' SET ';
            $setSet = '';
            foreach ($cols as $colName) {
                if (!empty($setSet)) {
                    $setSet .= ',';
                    $setSet .= $colName . '=' . $this->getBindedPlaceholder($colName);
                }
            }
            $sql .= $setSet . ' ' . $this->where;
            return $this->runSimpleQueries($sql, $cols, $vals, self::QUERY_TYPE_UPDATE);
        }

        /**
         * Internal method to run simple insert/update and return results
         * Returns lastInsertID for inserts, and affected rows count for updates.
         *
         * @param string $sql
         * @param array $cols
         * @param array $vals
         * @param int $queryType
         *
         * @return int
         */
        private function runSimpleQueries(string $sql, array $cols, array $vals, int $queryType): int
        {
            $params = [];
            for ($x = 0; $x < sizeof($cols); $x++) {
                $params[$this->getBindedPlaceholder($cols[$x])] = $vals[$x];
            }
            $this->runPreparedQuery($sql, $params);
            if ($queryType === self::QUERY_TYPE_INSERT) {
                return $this->DBObj->lastInsertId();
            } else {
                return $this->_stmt->rowCount();
            }
        }

        /**
         * @param bool $isDelete
         *
         * @return string
         */
        final private function buildSelectQuery(bool $isDelete = false): string
        {
            // start with the select
            if ($isDelete && $isDelete === $this->isDelete) {
                $sql = 'DELETE ';
            } else {
                $sql = 'SELECT ' . trim($this->select) . ' ';
            }
            $sql .= 'FROM ' . $this->table . ' ';
            if (!empty($this->join)) {
                $sql .= trim($this->join) . ' ';
            }
            $sql .= trim($this->where) . ' ';

            if (!empty($this->orderBy)) {
                $sql .= trim($this->orderBy) . ' ';
            }

            if (!empty($this->limit)) {
                $sql .= trim($this->limit) . ' ';
            }
            return $sql;
        }

        /**
         * This should be used for LOGGING ONLY
         *
         * Manually replaced binding placeholders with actual values so a full
         * query can be logged if necessary.
         *
         * @param string $query
         * @param array $params
         *
         * @return string
         */
        final private function interpolateQuery(string $query, array $params): string
        {
            $keys = [];

            # build a regular expression for each parameter
            foreach ($params as $key => $value) {
                if (is_string($key)) {
                    $keys[] = '/:' . $key . '/';
                } else {
                    $keys[] = '/[?]/';
                }
            }

            $query = preg_replace($keys, $params, $query, 1, $count);

            #trigger_error('replaced '.$count.' keys');

            return $query;
        }

        final private function getBindedPlaceholder(string $colName, bool $inQuery = false): string
        {
            return ($inQuery ? ':' : '') . str_replace([' ', '_', '-', '.'], '', ucwords($colName));
        }

        final private function addBindParam(string $col, $val): bool
        {
            $colKey = $this->getBindedPlaceholder($col);
            if (array_key_exists($colKey, $this->bindParams)) {
                $matches = [];
                if (preg_match('/(\d+)$/', $colKey, $matches)) {
                    $inc = (int)($matches[1] ?? 0);
                    $colKey = trim(str_replace($inc, '', $colKey));
                    $colKey = $colKey . (++$inc);
                }
            }

            $this->bindParams[$colKey] = $val;
            return true;
        }

        /**
         * Clear out the instance so a fresh query can be ran.
         */
        final private function clearMethodVars(): void
        {
            $this->select = '*';
            $this->where = '';
            $this->bindParams = [];
            $this->join = '';
            $this->limit = '';
            $this->orderBy = '';
        }

        /**
         * Check for common attack vectors and cut them off.
         * Probably not super helpful, but can't hurt.
         *
         * @param string $str
         * @param string $type
         *
         * @return bool
         */
        final private function passesSecurityCheck(string $str, string $type)
        {
            if (strstr(strtolower($str), 'delete from') !== false || strstr(strtolower($str), 'drop table') !== false) {
                return false;
            }
            return true;
        }
    }
