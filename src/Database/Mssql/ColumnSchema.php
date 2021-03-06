<?php
namespace DreamFactory\Core\Database\Mssql;

/**
 * ColumnSchema class describes the column meta data of a MSSQL table.
 */
class ColumnSchema extends \DreamFactory\Core\Database\ColumnSchema
{
    /**
     * Extracts the PHP type from DB type.
     *
     * @param string $dbType DB type
     */
    public function extractType($dbType)
    {
        parent::extractType($dbType);

        if ((false !== strpos($dbType, 'varchar')) && (null === $this->size)) {
            $this->type = static::TYPE_TEXT;
        }
    }

    /**
     * Extracts the default value for the column.
     * The value is typecasted to correct PHP type.
     *
     * @param mixed $defaultValue the default value obtained from metadata
     */
    public function extractDefault($defaultValue)
    {
        if ($defaultValue == '(NULL)') {
            $this->defaultValue = null;
        } elseif ($this->type === static::TYPE_BOOLEAN) {
            if ('((1))' === $defaultValue) {
                $this->defaultValue = true;
            } elseif ('((0))' === $defaultValue) {
                $this->defaultValue = false;
            } else {
                $this->defaultValue = null;
            }
        } elseif ($this->type === static::TYPE_TIMESTAMP) {
            $this->defaultValue = null;
        } else {
            parent::extractDefault(str_replace(array('(', ')', "'"), '', $defaultValue));
        }
    }

    /**
     * Extracts size, precision and scale information from column's DB type.
     * We do nothing here, since sizes and precisions have been computed before.
     *
     * @param string $dbType the column's DB type
     */
    public function extractLimit($dbType)
    {
    }

    /**
     * Converts the input value to the type that this column is of.
     *
     * @param mixed $value input value
     *
     * @return mixed converted value
     */
    public function typecast($value)
    {
        if ($this->phpType === 'boolean') {
            return $value ? 1 : 0;
        } else {
            return parent::typecast($value);
        }
    }

    public function parseFieldForSelect($as_quoted_string = false)
    {
        $field = ($as_quoted_string) ? $this->quoteColumnName($this->name) : $this->name;
        $alias = ($as_quoted_string) ? $this->quoteColumnName($this->getName(true)) : $this->getName(true);
        switch ($this->dbType) {
            case 'datetime':
            case 'datetimeoffset':
                return "(CONVERT(nvarchar(30), $field, 127)) AS $alias";
            case 'geometry':
            case 'geography':
            case 'hierarchyid':
                return "($field.ToString()) AS $alias";
            default :
                return parent::parseFieldForSelect($as_quoted_string);
        }
    }
}
