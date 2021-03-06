<?php

namespace feiron\felaraframe\lib\BluePrints;

use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class BluePrintsModelFactory {
    private $ModelDefinition;
    private $FieldList;
    private $editableFields;
    private $myRelations;
    private $RelatedModels;
    private $PrimaryKey;
    private $RootStorage;
    private const FieldsWithSize = [
        'char',
        'string'
    ];
    private const FieldsWithModifier = [
        'decimal',
        'double',
        'float',
        'unsignedDecimal'
    ];
    private const FieldsWithCollection = [
        'enum',
        'set'
    ];
    private const Defaults =[
        'dataType' => 'string',
        'size' => '175',
        'default' => null,
        'nullable' => true,
        'autoIncrement' => false,
        'visible' => true,
        'editable' => true
    ];

    private const ModelClassPrefix= 'fe_bp_';
    private const migrationPath = "database/migrations/";
    private const modelPath = "app/model/";

    public function __construct($definition=null){
        $this->FieldList=[];
        $this->myRelations=[];
        $this->RelatedModels=[];
        $this->editableFields=[];
        $this->PrimaryKey=null;
        $this->RootStorage = Storage::createLocalDriver(['root' => base_path()]);
        $ModelDefinition=[
            'modelName'=>'',
            'engine'=> 'InnoDB',
            'charset'=> 'utf8',
            'collation'=> 'utf8_unicode_ci',
            'withTimeStamps'=>true,
            'withCRUD'=>false
        ];
        $this->ModelDefinition= array_merge($ModelDefinition, ($definition??[]));
        if (array_key_exists('index', $this->ModelDefinition)) {
            $this->SetPrimary($this->ModelDefinition['index']);
        }
    }

    public function getModelName(){
        return $this->ModelDefinition['modelName'];
    }
    
    public function getRelations(){
        return $this->myRelations;
    }
    
    public function getRelationType($modelName){
        return (($this->isRelatedTo($modelName)===true)?$this->RelatedModels[$modelName]['type']:null);
    }

    public function getRelationTarget($modelName){
        return (($this->isRelatedTo($modelName)===true)?$this->RelatedModels[$modelName]['on']:null);
    }

    public function getRelationRemoteTarget($modelName){
        return (($this->isRelatedTo($modelName)===true)?$this->RelatedModels[$modelName]['targetReference']:null);
    }

    private function getRelationModifier($relation,$reverse=false){
        switch(strtolower($relation->type)){
            case "onetoone":
                return ($reverse? "belongsTo": 'hasOne') . ("('App\model\\" . self::ModelClassPrefix.$relation->target . "','" . $relation->targetReference . "','" . $relation->sourceReference . "' )");
                break;
            case "onetomany":
                return ($reverse ? "belongsTo" : "hasMany") . ("('App\model\\" . self::ModelClassPrefix.$relation->target . "','" . $relation->targetReference . "','" . $relation->sourceReference . "' )");
                break;
            case "manytoone":
                return ($reverse ? "hasMany" : "belongsTo") . ("('App\model\\" . self::ModelClassPrefix.$relation->target . "','" . $relation->targetReference . "','" . $relation->sourceReference . "' )");
                break;
            case "manytomany":
                $tableName = [];
                array_push($tableName, $this->ModelDefinition['modelName'], $relation->target);
                sort($tableName);
                $tableName = 'MtoM_' . join('_', $tableName);
                return "belongsToMany('App\model\\" . self::ModelClassPrefix . $relation->target."', '$tableName', '" . $this->ModelDefinition['modelName'] . '_' . $relation->sourceReference . "','" . $relation->target.'_'.$relation->targetReference . "', '" . $relation->sourceReference . "', '" . $relation->targetReference . "')";
                break;
        }
        return false;
    }

    public function getPrimary(){
        return $this->PrimaryKey;
    }

    private function getFieldModifier($field){

        if(in_array($field['dataType'], self::FieldsWithSize)){
            return ("," . ($field['size'] ?? self::Defaults['size']));
        } elseif(in_array($field['dataType'], self::FieldsWithModifier)){
            return ("," . ($field['modifier'] ?? '8,2'));
        } elseif (in_array($field['dataType'], self::FieldsWithCollection)) {
            $field['modifier']=array_map(function($f){return ("'". $f."'");}, ($field['modifier']??['']));
            return (",[" . (join(',',$field['modifier'])).']');
        }
        return "";
    }

    public function getFieldDefinition($fieldName){
        return $this->FieldList[$fieldName]??[];
    }

    public function getFieldNames(){
        return array_map(function($f){return (object)['name'=> $f];}, array_keys($this->FieldList));
    }

    public function getFields(){
        return $this->FieldList;
    }

    public function getModelDefition($definitionName){
        return $this->ModelDefinition[$definitionName]??'';
    }

    public function addField($definition){
        if (null===($this->PrimaryKey) && ($definition->dataType == 'bigIncrements' || (isset($definition->primary) && true === $definition->primary))) {
            $this->SetPrimary($definition->name);
        }
        $this->FieldList[$definition->name]= array_merge(self::Defaults, (array) $definition);
        if(($this->PrimaryKey!== $definition->name) && (($definition->editable ?? true) === true)){
            $this->editableFields[$definition->name] = array_merge(self::Defaults, (array) $definition);
        }
    }

    public function addRelation($relation){
        array_push($this->myRelations,$relation);
        if(array_key_exists($relation->target,$this->RelatedModels)===false){
            $this->RelatedModels[$relation->target]=[
                "target"=> $relation->target,
                "type"=> $relation->type,
                "targetReference" => $relation->targetReference,
                "on" => $relation->sourceReference
            ];
        }
    }

    public function SetModelDefinition($key,$value){
        if(array_key_exists($key,$this->ModelDefinition)){
            $this->ModelDefinition[$key]=$value;
        }
    }

    public function SetPrimary($keyName){
        $this->PrimaryKey=$keyName;
    }

    private function createDBField($field,$namePrefix='',$skipIndex=false, $forceType = false){
        if(false!==$forceType){
            $field['dataType']= $forceType;
        }
        return '
            $table->' . ($field['dataType'] ?? self::Defaults['dataType']) . '("'.$namePrefix.$field['name'].'"' . $this->getFieldModifier($field) . ')'
            . ((isset($field['nullable']) && false === $field['nullable']) ? "->nullable(false)" : "->nullable(true)")
            . ($skipIndex === false && isset($field['autoIncrement']) && true === $field['autoIncrement'] ? "->autoIncrement()" : "")
            . (isset($field['unsigned']) && true === $field['unsigned'] ? "->unsigned()" : "")
            . (false === empty($field['default']) ? ("->default('".$field['default']."')") : "")
            . (false === empty($field['charset']) ? ("->charset(".$field['charset'].")") : "")
            . (false === empty($field['collation']) ?("->collation(".$field['collation'].")") : "")
            . ($skipIndex === false && isset($field['unique']) && true === $field['unique'] ? "->unique()" : "")
            . ($skipIndex === false && isset($field['index']) && true === $field['index'] ? "->index()" : "")
            . ($skipIndex === false && isset($field['spatialIndex']) && true === $field['spatialIndex'] ? "->spatialIndex()" : "")
            . ($skipIndex === false && isset($field['primary']) &&  true === $field['primary'] && $field['dataType'] != 'bigIncrements' ? "->primary()" : "")
            . ';';
    }

    public function renderDBField($fieldName,$prefix='', $skipIndex = false,$forceType=false){
        if(array_key_exists($fieldName, $this->FieldList)){
            return $this->createDBField($this->FieldList[$fieldName], $prefix, $skipIndex);
        }
        return '';
    }

    public function buildMigrations(){
        try {
            $className = 'create_' . $this->ModelDefinition['modelName'] . '_table';
            $target = self::migrationPath . date('Y_m_d_his') . '_' . $className . '.php';
            if (Schema::hasTable(strtolower($this->ModelDefinition['modelName'])) === false) {
                $fieldList = "";

                if (array_key_exists('index',$this->ModelDefinition)) {
                    $fieldList .= '
                    $table->bigIncrements("' . $this->ModelDefinition['index'] . '");
                    ';
                }
                foreach ($this->FieldList as $fieldName=>$field) {

                    $fieldList .= $this->createDBField($field);

                }

                if ($this->PrimaryKey == null) {
                    $fieldList = '
                    $table->bigIncrements("idx");
                    ' . $fieldList;
                    $this->PrimaryKey = 'idx';
                }
                if (($this->ModelDefinition['withTimeStamps'] ?? false) === true) {
                    $fieldList .= '
                    $table->timestamps();';
                }
                if (strlen($fieldList) > 0) {
                    $contents = '
                        <?php
                
                        use Illuminate\Database\Migrations\Migration;
                        use Illuminate\Database\Schema\Blueprint;
                        use Illuminate\Support\Facades\Schema;
                
                        class ' . str_replace('_', '', $className) . ' extends Migration
                        {
                            public function up()
                            {
                                if(false===Schema::hasTable("' . strtolower($this->ModelDefinition['modelName']) . '")){
                                    Schema::create("' . $this->ModelDefinition['modelName'] . '", function (Blueprint $table) {
                                        $table->engine = "' . ($this->ModelDefinition['engine'] ?? 'InnoDB') . '";
                                        $table->charset = "' . ($this->ModelDefinition['charset'] ?? 'utf8') . '";
                                        $table->collation = "' . ($this->ModelDefinition['collation'] ?? 'utf8_unicode_ci') . '";
                                        ' . $fieldList . '
                                    });
                                }
                            }
                
                            public function down()
                            {
                                Schema::dropIfExists("' . $this->ModelDefinition['modelName'] . '");
                            }
                        }
                        ?>';
                    $this->RootStorage->put($target, $contents);
                    return true;
                } else {
                    throw new Exception("Model contains no fields definition [".$this->ModelDefinition['modelName']."]");
                }
                return false;
            }
        } catch (Exception $e) {
            throw new Exception("Error Creating Migrations" . $e->getMessage(), 1);
        }
        return false;
    }

    public function BuildModel($targetPath=null){
        $className = self::ModelClassPrefix . $this->ModelDefinition['modelName'];
        $target = ($targetPath??(self::modelPath)) . $className . '.php';
        $this->PrimaryKey=($this->PrimaryKey ?? 'idx');
        $guarded = [$this->PrimaryKey];
        $hidden = [];
        $relations = "";
        foreach ($this->FieldList as $field) {
            if (($field['visible'] ?? true) == false) {
                if (in_array($field['name'], $hidden) === false)
                    array_push($hidden, $field['name']);
            }
            if (($field['editable'] ?? true) == false) {
                if (in_array($field['name'], $guarded) === false)
                    array_push($guarded, $field['name']);
            }
        }

        foreach ($this->myRelations as $relation) {
            $modifier = $this->getRelationModifier($relation);
            if (false !== $modifier) {
                $relations .= '
                        public function ' . $relation->target . 's()
                        {
                            return $this->' . $modifier . ';
                        }
                    ';
            }
        }
        $contents = '<?php
        namespace App\model;

        use Illuminate\Database\Eloquent\Model;

        class ' . $className . ' extends Model
        {
            protected $table = "' . strtolower($this->ModelDefinition['modelName']) . '";
            protected $primaryKey = "' . $this->PrimaryKey . '";
            ' . (($this->ModelDefinition['withTimeStamps'] ?? false) ? "" : 'public $timestamps = false;') . '
            ' . (!empty($guarded) ? ('protected $guarded = [' . join(',', array_map(function ($g) { return ("'" . $g . "'"); }, $guarded)) . '];') : "") . '
            ' . (!empty($hidden) ? ('protected $hidden = [' . join(',', array_map(function ($h) { return ("'" . $h . "'"); }, $hidden)) . '];') : "") . '
            '. $relations.'
        }
        ';
        $this->RootStorage->put($target, $contents);
    }

    public function extractDataFields($list){
        $fieldList=[];
        foreach ((explode(';', $list) ?? []) as $field) {
            preg_match('/(.*):(\w*)(?:\((.*)\))?(?:\[(.*)\])?(?:\<(.*)\>)?/i', $field, $fieldDef);
            $Definition = [ //Process name and type
                "name" => $fieldDef[1],
                "dataType" => $fieldDef[2]
            ];

            if (!empty($fieldDef[3])) { //Process options
                if (stripos(trim($fieldDef[3], ','), ',') === false) {
                    $Definition['size'] = $fieldDef[3];
                } elseif (in_array($fieldDef[2], ['set', 'enum']) === true) {
                    $Definition['modifier'] = explode(',', trim($fieldDef[3], ','));
                } else {
                    $Definition['modifier'] = $fieldDef[3];
                }
            }

            if (!empty($fieldDef[4])) { //Process Modifiers
                foreach (explode(',', trim($fieldDef[4], ',')) ?? [] as $modifier) {
                    if ((stripos($modifier, '=') === false)) {
                        $Definition[$modifier] = true;
                        if ($modifier == 'primary') {
                            $this->PrimaryKey = $fieldDef[1];
                        }
                    } else {
                        $modifier = explode('=', $modifier);
                        $Definition[$modifier[0]] = $modifier[1];
                    }
                }
            }

            if (!empty($fieldDef[5])) {
                $Definition['default'] = $fieldDef[5];
            }
            $Definition['nullable'] = $Definition['nullable'] ?? false;
            $this->addField((object) $Definition);
            array_push($fieldList, $Definition);
        }
        return $fieldList;
    }

    public function isRelatedTo($modelName){
        return (array_key_exists($modelName, $this->RelatedModels)===true);
    }

    public function Describe(){
        echo "Model Definition:\r\n";
        dump($this->ModelDefinition);
        echo "Model FieldList:\r\n";
        dump($this->FieldList);
    }
}

?>