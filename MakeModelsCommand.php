<?php

namespace App\Console\Commands;


use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Pluralizer;
use Illuminate\Console\GeneratorCommand;


class MakeModelsCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'self:make:models {connection} {databases} {tables?*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '创建模型 connection|databases|tables(可选)';

    protected $connection = '';

    protected $databases = '';

    protected $tables = [];

    protected $extends = 'Illuminate\Database\Eloquent\Model';

    protected $prefix = '/Models/';


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $this->connection = $this->argument('connection'); //指定数据库链接

        if(empty($this->connection)){
            return $this->error('请指定数据库链接');
        }
        $this->databases = $this->argument('databases'); //指定数据库
        if(empty($this->connection)){
            return $this->error('请指定指定数据库名称');
        }

        $this->tables = array_get($this->arguments('tables'),'tables'); //指定数据库表--可选

        $this->prefix = $this->prefix.ucfirst($this->databases).'/';
        if(empty($this->tables)){
            $this->tables = $this->getSchemaTables();
        }
        foreach ($this->tables as $tablename) {
            //$tablename = (object)$tablename;
            $tableName = isset($tablename->name) ?  $tablename->name : $tablename;
            $this->generateTable($tableName);
        }
    }



    public function  getSchemaTables()
    {
        $tables = [];
        try {
            $tables = DB::connection("$this->connection")->select("SELECT table_name AS name FROM information_schema.tables WHERE table_type='BASE TABLE' and TABLE_SCHEMA='{$this->databases}'");

        } catch (QueryException $e) {
            $this->error('数据库出错-table：' . $e->getMessage());
        }
        return $tables;
    }


    /**
     * Get table columns.
     *
     * @param $table
     *
     * @return array
     */
    protected function getTableColumns($table)
    {
        $columns = [];
        try {
            $columns = DB::connection("$this->connection")->select("SELECT COLUMN_NAME as `name` FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$this->databases}' AND TABLE_NAME = '{$table}'");
        } catch (QueryException $e) {
            $this->error('数据库出错-Columns：' . $e->getMessage());
        }
        return $columns;
    }

    /**
     * Get table primary key column.
     *
     * @param $table
     *
     * @return string
     */

    protected function getTablePrimaryKey($table)
    {
        $primaryKey = [];
        try {
            $primaryKey = DB::connection("$this->connection")->select("SELECT COLUMN_NAME FROM information_schema.COLUMNS  WHERE  TABLE_SCHEMA = '{$this->databases}'  AND  TABLE_NAME = '{$table}' AND  COLUMN_KEY = 'PRI'");
        } catch (QueryException $e) {
            $this->error('数据库出错-PrimaryKey：' . $e->getMessage());
        }

        if (count($primaryKey) == 1) {
            $table = (object)$primaryKey[0];
            return $table->COLUMN_NAME;
        }

        return null;
    }

    /**
     * Fill up $fillable/$guarded/$timestamps properties based on table columns.
     *
     * @param $table
     *
     * @return array
     */
    protected function getTableProperties($table)
    {
        $primaryKey = $this->getTablePrimaryKey($table);
        $primaryKey = $primaryKey != 'id' ? $primaryKey : null;
        $fillable = [];
        $guarded = [];
        $timestamps = false;
        $columns = $this->getTableColumns($table);
        foreach ($columns as $column) {
            $column = (object)$column;
            //priotitze guarded properties and move to fillable
            //if ($this->ruleProcessor->check($this->option('fillable'), $column->name)) {
            if (!in_array($column->name, array_merge(['id', 'created_at', 'updated_at', 'deleted_at'], $primaryKey ? [$primaryKey] : []))) {
                $fillable[] = $column->name;
            }
            // }


            //check if this model is timestampable
//            if ($this->ruleProcessor->check($this->option('timestamps'), $column->name)) {
//                $timestamps = true;
//            }
            $timestamps = false;
        }
        return ['primaryKey' => $primaryKey, 'fillable' => $fillable, 'guarded' => $guarded, 'timestamps' => $timestamps];
    }


    /**
     * Generate a model file from a database table.
     *
     * @param $table
     * @return void
     */
    protected function generateTable($table)
    {
        //prefix is the sub-directory within app
        $prefix = $this->prefix;
        $prefixRemovedTableName = $table;

        $class = $this->convertTableNameToClassName($prefixRemovedTableName);

        if (method_exists($this, 'qualifyClass')) {
            $name = Pluralizer::singular($this->qualifyClass($prefix . $class));
        } else {
            $name = Pluralizer::singular($this->parseName($prefix . $class));
        }
        //var_dump($this->getPath($name));
        if ($this->files->exists($path = $this->getPath($name))) {
            // return $this->error($this->extends . ' for ' . $table . ' already exists!');
        }

        $this->makeDirectory($path);

        $this->files->put($path, $this->replaceTokens($name, $table));

        $this->info($this->extends . ' for ' . $table . ' created successfully.');
    }


    protected function replaceTokens($name, $table)
    {

        //$class = $this->buildClass($name);
        $stub = $this->getmodel();

        $class = $this->replaceNamespace($stub, $name)->replaceClass($stub, $name);
        $properties = $this->getTableProperties($table);

        $class = str_replace('{{extends}}', $this->extends, $class);
        $class = str_replace('{{shortNameExtends}}', explode('\\', $this->extends)[count(explode('\\', $this->extends)) - 1], $class);

        $class = str_replace('{{table}}', 'protected $table = \'' . $table . '\';', $class);

        $class = str_replace('{{primaryKey}}', $properties['primaryKey'] ? ('protected $primaryKey = \'' . $properties['primaryKey'] . '\';' . "\r\n\r\n\t") : '', $class);

        $class = str_replace('{{fillable}}', 'protected $fillable = ' . $this->convertArrayToString($properties['fillable']) . ';', $class);
        $class = str_replace('{{guarded}}', 'protected $guarded = ' . $this->convertArrayToString($properties['guarded']) . ';', $class);
        $class = str_replace('{{timestamps}}', 'public $timestamps = ' . $this->convertBooleanToString($properties['timestamps']) . ';', $class);

        return $class;
    }

    /**
     * Get stub file location.
     *
     * @return string
     */
    public function getStub()
    {
        return __DIR__ ;
    }


    /**
     * Convert a PHP array into a string version.
     *
     * @param $array
     *
     * @return string
     */
    public static function convertArrayToString($array)
    {
        $string = '[';
        if (!empty($array)) {
            $string .= "\n        '";
            $string .= implode("',\n        '", $array);
            $string .= "'\n    ";
        }
        $string .= ']';

        return $string;
    }

    public static function convertTableNameToClassName($table)
    {
        $string = str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));

        return $string;
    }


    /**
     * Convert a boolean into a string.
     *
     * @param $boolean
     *
     * @return string true|false
     */
    public static function convertBooleanToString($boolean)
    {
        $string = $boolean ? 'true' : 'false';

        return $string;
    }

    public function  getmodel()
    {
        $str ="<?php

namespace DummyNamespace;

use {{extends}};

/**
 * Class DummyClass
 */
class DummyClass extends {{shortNameExtends}}
{
    {{table}}

    {{primaryKey}}{{timestamps}}

    {{fillable}}

    {{guarded}}



}";
        return $str;
    }


}