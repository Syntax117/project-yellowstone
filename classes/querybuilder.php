<?php
class QueryBuilder
{
    /* Storage for ongoing query */
    private $current_query = array();
    /* Holds tables currently used in the query where a value of false implies that it hasn't been joined yet. */
    private $unjoined_tables = array();
    /* Holds the order of a standard query and whether it is necessary for the query to execute */
    private $mandatory_clauses = array();
    /* Holds errors found in the query */
    private $query_errors = array();
    /* Holds parameters to be bound later when provided with a PDOStatement */
    public $bind_parameters = array();

    /**
     * @param $type
     * @throws Exception
     */
    public function initialise($type)
    {
        /* Reset arrays */
        $this->current_query = array();
        $this->unjoined_tables = array();
        $this->mandatory_clauses = array();
        $this->query_errors = array();
        $this->bind_parameters = array();
        /* Setup clause order and specify whether they are mandatory or optional */
        switch ($type) {
            case 'SELECT':
                $this->mandatory_clauses = array(
                    'SELECT' => true,
                    'FROM' => true,
                    'LEFT JOIN' => false,
                    'WHERE' => false,
                    'HAVING' => false,
                    'GROUP BY' => false,
                    'ORDER BY' => false,
                    'LIMIT' => false
                );
                break;
            case 'UPDATE':
                $this->mandatory_clauses = array('UPDATE' => true, 'SET' => true, 'WHERE' => true);
                break;
            case 'INSERT':
                $this->mandatory_clauses = array('INSERT' => true, 'VALUES' => true);
                break;
            case 'DELETE':
                $this->mandatory_clauses = array('DELETE' => true, 'WHERE' => true);
                break;
            default:
                throw new Exception('Incorrect initialisation parameter. ');
        }
    }

    /**
     * Check if the query has all the expected parts (clauses and tables)
     */
    private function checkIntegrity()
    {
        foreach ($this->unjoined_tables as $table => $joined)
            if (!$joined)
                $this->query_errors[] = "$table is required but not joined to the query.";
        foreach ($this->mandatory_clauses as $clause => $mandatory)
            if ($mandatory && !isset($this->current_query[$clause]))
                $this->query_errors[] = "$clause clause is required but not present in output query.";
    }

    /**
     * Appends prefix to cell if it is not empty, otherwise ensure it is set as null.
     * @param string $cell
     */
    private function appendPrefix(&$cell)
    {
        if (!empty($cell))
            $cell .= ", ";
        else
            $cell = null;
    }

    /* Main Functions */
    /**
     * Bring select_fields, select_custom and where functions together for a standard SELECT
     * @param array $fields
     * @param null $custom_fields
     * @param null $from
     * @param null $where
     */
    public function select(array $fields, $custom_fields = null, $from = null, $where = null)
    {
        /* Standard fields of variant table.column are added to the query here. */
        if (count($fields) > 0)
            $this->select_fields($fields);
        /* Custom fields, such as GROUP_CONCAT, are added to the query here. */
        if (is_array($custom_fields) && count($custom_fields) > 0)
            $this->select_custom($custom_fields);
        /* WHERE clause */
        if (is_array($where) && count($where) > 0)
            $this->where($where);
        /* Add FROM clause if appropriate */
        if (!is_null($from)) {
            $this->unjoined_tables[$from] = true;
            $this->current_query['FROM'] = $from;
        }
    }

    /**
     * Select columns from multiple tables and move into current query SELECT
     * @param array $fields
     */
    public function select_fields(array $fields)
    {
        $this->appendPrefix($this->current_query['SELECT']);
        /* Get last field and table */
        $end_table = $this::array_end($fields);
        $end_field = $this::array_end($fields[$end_table]);
        /* Iterate through fields and add them to the query */
        foreach ($fields as $table => $field_array) {
            /* Inform the class that there is a new table that will require joining*/
            $this->requireTable($table);
            foreach ($field_array as $key => $value) {
                /* Aliases will not be numeric. */
                if (is_numeric($key)) {
                    /* Where $value is the column name */
                    $field = "$table.$value";
                } else {
                    /* Where $value is the alias */
                    $field = "$table.$key AS $value";
                }
                if ($table == $end_table && $key == $end_field) {
                    /* If the current field is the last one, do not add a comma.*/
                    $this->current_query['SELECT'] .= $field;
                } else {
                    $this->current_query['SELECT'] .= "$field, ";
                }
            }
        }
    }

    /**
     * Allows the addition of abnormal fields to SELECT
     * @param array $custom_fields
     */
    public function select_custom(array $custom_fields)
    {
        $this->appendPrefix($this->current_query['SELECT']);
        $end_value = $this::array_end($custom_fields[1], true);
        /* Require all tables found in the array */
        foreach ($custom_fields[0] as $table)
            $this->requireTable($table);
        /* Add the items to the clause, adding a comma if necessary (not the end value) */
        foreach ($custom_fields[1] as $field)
            $this->current_query['SELECT'] .= ($field == $end_value) ? $field : "$field, ";
    }

    /**
     * Add standard parts for a UPDATE statement
     * @param array $fields
     * @param string $table
     * @param array $where
     */
    public function update (array $fields, string $table, array $where) {
        /* Inform the builder that the table is present in the query */
        $this->unjoined_tables[$table] = true;
        $this->current_query['UPDATE'] = $table;
        $end_key = $this::array_end($fields);
        /* Initialise the SET clause */
        $this->current_query['SET'] = NULL;
        foreach ($fields as $column=>$value) {
            /* Add column to bind parameters with the provided value */
            $this->bind_parameters[":$column"] = $value;
            /* Add field to SET clause*/
            $this->current_query['SET'] .= ($column == $end_key) ? "$column = :$column" : "$column = :$column, ";
        }
        $this->where($where);
    }

    /**
     * Add standard parts for an INSERT statement
     * @param array $fields
     * @param string $table
     */
    public function insert (array $fields, string $table) {
        /* Inform the builder that the table is present in the query */
        $this->unjoined_tables[$table] = true;
        /* Begin query and open parenthesis */
        $this->current_query['INSERT'] = "INTO $table (";
        $end_key = $this::array_end($fields);
        $this->current_query['VALUES'] = '(';
        foreach ($fields as $column=>$value) {
            /* Add column to bind parameters */
            $this->bind_parameters[":$column"] = $value;
            $isEnd = ($column == $end_key);
            /* Add columns and bind parameters to their respective locations */
            $this->current_query['INSERT'] .= $isEnd ? $column : "$column, ";
            $this->current_query['VALUES'] .= $isEnd ? ":$column" : ":$column, ";
        }
        /* Close query and parenthesis */
        $this->current_query['INSERT'] .= ')';
        $this->current_query['VALUES'] .= ')';
    }

    /**
     * Add standard parts for a DELETE statement
     * @param string $from
     * @param array $where
     */
    public function delete (string $from, array $where) {
        /* Inform builder that the FROM table is already joined */
        $this->unjoined_tables[$from] = true;
        /* Append standard DELETE parameter (FROM tablename) */
        $this->current_query['DELETE'] = "FROM $from";
        /* Include WHERE clause */
        $this->where($where);
    }

    /**
     * Adds a standard WHERE clause to current query (allowing for LIKE and EXACT matches)
     * @param array $where
     * @param string $prefix
     */
    public function where(array $where, string $prefix = 'AND')
    {
        $this->appendPrefix($this->current_query['WHERE']);
        /* Find separators and remove from where array*/
        $separators = (isset($where['SEPARATOR'])) ? $where['SEPARATOR'] : array ();
        unset($where['SEPARATOR']);
        /* Find ends */
        $end_type = $this::array_end($where);
        $end_table = $this::array_end($where[$end_type]);
        $end_column = $this::array_end($where[$end_type][$end_table]);
        /* Iterate */
        $i = 0;
        foreach ($where as $type => $filters) {
            /* Set operator to be used throughout */
            $operator = null;
            switch ($type) {
                case 'LIKE':
                    $operator = 'LIKE';
                    break;
                case 'EXACT':
                    $operator = '=';
                    break;
            }
            foreach ($filters as $table => $columns) {
                $this->requireTable($table);
                foreach ($columns as $column => $value) {
                    /* Generate parameter name which doesn't conflict with other tables */
                    $parameter_key = ':' . $i . '_' . $table . '_' . $column;
                    /* Add to clause */
                    $this->current_query['WHERE'] .= "$table.$column $operator $parameter_key";
                    /* Add parameter to bind parameters */
                    $this->bind_parameters[$parameter_key] = $value;
                    /* If the field is not at the very end, add the suffix. */
                    if (!($type == $end_type && $table == $end_table && $column == $end_column)) {
                        /* If the caller has provided a separator manually then use it, otherwise default to AND. */
                        if (isset($separators[$i]))
                            $this->current_query['WHERE'] .= " {$separators[$i]} ";
                        else
                            $this->current_query['WHERE'] .= ' AND ';
                    }
                    $i++;
                }
            }
        }
    }

    /**
     * Allows for custom code injection into clauses
     * @param string $clause
     * @param string $query
     */
    public function inject (string $clause, string $query) {
        $this->appendPrefix($this->current_query[$clause]);
        $this->current_query[$clause] .= $query;
    }

    /* Universal Functions */
    /**
     * Gets the end key/value of an array
     * @param $array
     * @param bool $value
     * @return bool|mixed
     */
    private static function array_end (&$array, bool $value = false) {
        /* Ensure it's an array and not null, otherwise this may cause problems.. */
        if (is_array($array)) {
            /* If they want a value, move the pointer to the end and store the value*/
            if ($value)
                $result = end($array);
            else {
                /* They want a key so move the pointer to the end and get the current key */
                end($array);
                $result = key($array);
            }
            /* Reset the array pointer for later*/
            reset($array);
            return $result;
        }
        /* We found nothing */
        return false;
    }

    /**
     * Adds table to query using LEFT JOIN clause with provided ON
     * @param string $table
     * @param array $on
     */
    public function left_join(string $table, array $on)
    {
        $this->unjoined_tables[$table] = true;

        $i = 0;
        $on_query = null;
        foreach ($on as $on_table => $column) {
            $i++;
            $on_query .= ($i == 1) ? "$on_table.$column = " : "$on_table.$column";
        }
        if ($i == 2)
            $this->current_query['LEFT JOIN'][] = "$table ON $on_query";
    }

    /**
     * Adds order/group to current query
     * @param string $table
     * @param string $column
     * @param string $sort_type
     */
    public function sort_by(string $table, string $column, string $sort_type)
    {
        $this->requireTable($table);
        switch ($sort_type) {
            case 'GROUP':
                $this->current_query['GROUP BY'] = "$table.$column";
                break;
            case 'ORDER':
                $this->current_query['ORDER BY'] = "$table.$column";
                break;
            default:
                $this->query_errors[] = 'Unrecognised SORT type.';
        }
    }

    /**
     * Adds table to unjoined if it does not exist already
     * @param string $table
     */
    private function requireTable(string $table)
    {
        if (!isset($this->unjoined_tables[$table]))
            $this->unjoined_tables[$table] = false;
    }

    /**
     * Binds logged parameters to provided statement
     * @param PDOStatement $PDOStatement
     */
    public function bindValues(\PDOStatement $PDOStatement)
    {
        /* Loop through bind parameters and bind the value using the provided PDO statement */
        foreach ($this->bind_parameters as $key => $value) {
            $PDOStatement->bindValue($key, $value);
        }
    }

    /**
     * Adds limit to current query
     * @param $limit
     * @param null $offset
     */
    public function limit($limit, $offset = null)
    {
        if (is_null($offset))
            $this->current_query['LIMIT'] = $limit;
        else
            $this->current_query['LIMIT'] = "$offset, $limit";
    }

    /**
     * Generates query from current query parts
     * @return null|string
     * @throws Exception
     */
    public function generateQuery()
    {
        /* Check the query is as expected */
        $this->checkIntegrity();
        /* Initialise the query variable */
        $query = null;
        /* Throw exceptions for each found query error (these need to be addressed by the developer) */
        if (count($this->query_errors) > 0) {
            $exception_string = null;
            foreach ($this->query_errors as $error)
                throw new Exception($error);
        }
        /* Loop through mandatory clauses */
        foreach ($this->mandatory_clauses as $clause => $mandatory) {
            if (isset($this->current_query[$clause])) {
                /* Get the current query value for this clause */
                $query_bit = $this->current_query[$clause];
                /* If there are multiple, separate as typically accepted in SQL format. */
                if (is_array($query_bit)) {
                    foreach ($query_bit as $value) {
                        $query .= " $clause $value";
                    }
                } else {
                    $query .= " $clause $query_bit";
                }
            }
        }
        return $query;
    }
}