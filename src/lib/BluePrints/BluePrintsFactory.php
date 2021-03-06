<?php

namespace feiron\felaraframe\lib\BluePrints;

use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use feiron\felaraframe\lib\BluePrints\BluePrintsModelFactory;
use feiron\felaraframe\lib\BluePrints\BluePrintsViewFactory;
use feiron\felaraframe\lib\BluePrints\BluePrintsControllerFactory;
class BluePrintsFactory {

    private $blueprint; //stores the contents of the target blueprint.
    private $projectPath;
    private $BlueprintStorage;
    private $RootStorage;
    private $command;

    private $ModelList;
    private $relations;
    private $PageList;
    private $routeList;
    private $ViewList;
    private $ControllerList;
    private $liveModelList;

    private $ViewFactory;
    private $ControllerFactory;

    private $counter;
    
    private const migrationPath="database/migrations/";
    protected const routePath = "routes/BluePrints/BluePrintsRoute.php";
    protected const ControllerClassPostfix = '_FeBp_Controller';

    public function __construct($target,$storage,$command){
        $this->BlueprintStorage=$storage;
        $this->RootStorage = Storage::createLocalDriver(['root' => base_path()]);
        // $this->command= $command;
        $this->projectPath = str_replace($this->BlueprintStorage->getAdapter()->getPathPrefix(), '', dirname($this->BlueprintStorage->path($target)));
        $this->ModelList=[];
        $this->relations=[];
        $this->PageList=[];
        $this->ViewList = [];
        $this->ControllerList = [];
        $this->routeList=[];
        $this->liveModelList=[];
        $this->counter=['tableMigrated'=>0];
        $this->ViewFactory = new BluePrintsViewFactory();
        $this->ControllerFactory = new BluePrintsControllerFactory();
        try{
            $this->blueprint = json_decode($this->BlueprintStorage->get($target));
        }catch(Exception $e){
            throw new Exception("Error Processing blueprint file. Please make sure it's in a correct format.", 1);
        }
    }

    public function addController($name, $controllerDefinition){
        $this->ControllerList[$name] = $controllerDefinition;
    }
    public function addView($name, $viewDefinition){
        $this->ViewList[$name] = $viewDefinition;
    }
    public function addRoute($routeDefinition){
        array_push($this->routeList, $routeDefinition);
    }
    public function addPage($name,$pageDefinition){
        $this->PageList[$name]= $pageDefinition;
    }

    public function ExtractInfo(){
        // dd($this->ModelList);
        foreach ($this->blueprint->pages as $pageDefinition) {
            $pageRouteList=[];
            $controllerDefinition = [
                'name' => $pageDefinition->name,
                'useModels' => [],
                'uses'=>[],
                'useLib' => [],
                'methods' => []
            ];

            if (false == array_key_exists($pageDefinition->name, $this->ViewList)) {
                $pageDefinition->style = $pageDefinition->style ?? 'singular';
                $this->addView($pageDefinition->name, [
                    'name' => $pageDefinition->name,
                    "style" => $pageDefinition->style,
                    "usage" => ($pageDefinition->usage ?? 'display'),
                    'title' => ($pageDefinition->title ?? ''),
                    'subtext' => ($pageDefinition->subtext ?? ''),
                    'html' => ($pageDefinition->html ?? ''),
                    "FieldList" => [],
                ]);
                if(in_array(strtolower($pageDefinition->style),['table', 'crud'])){//creating route for dataTable Ajax source and Crud Table Style
                    
                    array_push($pageDefinition->routes,(object)[
                        'name'=>'dTable_sr_'. $pageDefinition->name,
                        'type'=>'POST',
                        'style'=>'table'
                    ]);
                    array_push($controllerDefinition['uses'], [
                        'name'=>"DataTables",
                        'target'=>'feiron\felaraframe\lib\traits\DataTables'
                        ]);

                    $this->ViewList[$pageDefinition->name]['headerSearch']= ($pageDefinition->headerSearch ?? false);
                }
                if (in_array(strtolower($pageDefinition->style), ['crud', 'crudsingleton'])) {
                    array_push($pageDefinition->routes, (object) [
                        'name' => 'bp_crud_' . $pageDefinition->name . '_Create',
                        'usage' => 'crud_Create',
                        'type' => 'POST'
                    ]);
                    array_push($pageDefinition->routes, (object) [
                        'name' => 'bp_crud_' . $pageDefinition->name . '_Update',
                        'usage' => 'crud_Update',
                        'type' => 'POST'
                    ]);
                    if('crudsingleton'== strtolower($pageDefinition->style)){
                        array_push($pageDefinition->routes, (object) [
                            'name' => 'bp_crud_' . $pageDefinition->name . '_Delete',
                            'usage' => 'crud_Delete',
                            'type' => 'GET'
                        ]);
                    }else{
                        array_push($pageDefinition->routes, (object) [
                            'name' => 'bp_crud_' . $pageDefinition->name . '_Delete',
                            'usage' => 'crud_Delete',
                            'type' => 'POST'
                        ]);
                    }
                    array_push($controllerDefinition['uses'], [
                        'name' => "crudActions",
                        'target' => 'feiron\felaraframe\lib\traits\crudActions'
                    ]);
                    array_push($controllerDefinition['useLib'], 'Illuminate\Support\Facades\Validator');
                }
            }

            if (isset($pageDefinition->model) && array_key_exists($pageDefinition->model->name, $this->ModelList)) {

                $pageDefinition->model->eager=[];

                if (!in_array($pageDefinition->model->name, $controllerDefinition['useModels'])) array_push($controllerDefinition['useModels'], $pageDefinition->model->name);

                if (is_string($pageDefinition->model->fields) === true && strtolower($pageDefinition->model->fields) == 'all') {
                    $pageDefinition->model->fields = $this->ModelList[$pageDefinition->model->name]->getFieldNames();
                }
                array_push($this->ViewList[$pageDefinition->name]['FieldList'], [
                    "modelName" => $pageDefinition->model->name,
                    "Fields" => $pageDefinition->model->fields
                ]);

                foreach ($pageDefinition->model->with ?? [] as $withModel) {//eager loadings...
                    if (true === array_key_exists($withModel->name, $this->ModelList) && $this->ModelList[$pageDefinition->model->name]->isRelatedTo($withModel->name) === true) {
                        if (is_string($withModel->fields) === true && strtolower($withModel->fields) == 'all') {
                            $withModel->fields = $this->ModelList[$withModel->name]->getFieldNames();
                        }
                        if(false=== in_array($withModel->name, $pageDefinition->model->eager)){
                            array_push($pageDefinition->model->eager, $withModel->name);
                        }
                        array_push($this->ViewList[$pageDefinition->name]['FieldList'], [
                            "modelName" => $withModel->name,
                            "label" => $withModel->label??$withModel->name,
                            "Fields" => $withModel->fields,
                            "type" => 'with'
                        ]);
                    }
                }
                foreach ($pageDefinition->model->join ?? [] as $joinModel) {
                    if (isset($joinModel->on) && is_array($joinModel->on) && !empty($joinModel->on) && true === array_key_exists($joinModel->name, $this->ModelList)) {
                        if (is_string($joinModel->fields) === true && strtolower($joinModel->fields) == 'all') {
                            $joinModel->fields = $this->ModelList[$joinModel->name]->getFieldNames();
                        }
                        array_push($this->ViewList[$pageDefinition->name]['FieldList'], [
                            "modelName" => $joinModel->name,
                            "Fields" => $joinModel->fields,
                            "type" => 'join',
                            "on" => $joinModel->on,
                            "modifier" => ($joinModel->modifier ?? '')
                        ]);
                    }
                }
            }
            $counter=1;
            foreach(($pageDefinition->routes??([(object)['name'=> ($pageDefinition->name.'_'. $counter)]])) as $route){
                $counter++;
                $route->name= $route->name?? ($pageDefinition->name . '_' . $counter);
                $methodName=(is_string($route->name)? $route->name: $pageDefinition->name);
                $methodName= strtoupper(($route->type??'GET')[0]).'_'. $methodName;
                $method=[
                    'name'=>$methodName,
                    'view'=> $pageDefinition->name,
                    'type' => ($route->type ?? 'GET'),
                    'style'=> ($route->style??$pageDefinition->style??'singular'),
                    'usage' => $route->usage??'',
                    'model'=> $pageDefinition->model,
                    'params'=>[],
                    'useModel'=>[]
                ];
                if(isset($pageDefinition->tableFilter) && !empty($pageDefinition->tableFilter)){
                    $method['tableFilter']= $pageDefinition->tableFilter;
                }
                $routeDefinition=[
                    'name'=> $route->name,
                    'type' => ($route->type ?? 'GET'),
                    'url' => strtolower($route->slug ?? $route->name),
                    'targetMethod'=> $methodName,
                    'targetController' => $pageDefinition->name.self::ControllerClassPostfix,
                    'input'=>[]
                ];
                $optionalParamList=[];
                foreach(($route->input ?? []) as $in){
                    $in->optional= ($in->optional??false);
                    if(array_key_exists(($in->onModel??''), $this->ModelList)){
                        if(is_string($in->onModel) && !empty($in->onModel)){
                            if (!in_array($in->onModel, $controllerDefinition['useModels'])) array_push($controllerDefinition['useModels'], $in->onModel);
                            if (!in_array($in->onModel, $method['useModel'])) array_push($method['useModel'], $in->onModel);
                        }
                    }
                    if($in->optional===false){
                        array_push($method['params'], $in);
                    }else{
                        array_push($optionalParamList,$in);
                    }
                }
                $method['params']=array_merge($method['params'], $optionalParamList);
                $routeDefinition['input']=$method['params'];
                $this->addRoute($routeDefinition);
                array_push($pageRouteList, $routeDefinition);
                array_push($controllerDefinition['methods'], $method);
            }
            $this->addController($pageDefinition->name, $controllerDefinition);
            $this->addPage($pageDefinition->name, [
                'controller' => $controllerDefinition,
                'view' => $this->ViewList[$pageDefinition->name],
                'routes' => $pageRouteList,
            ]);
        }
        return $this->PageList;
    }

//=================================Model Related Operations=============================

    private function getInverseRelation($relationSource,$target,$targetReference){
        $relation = clone $relationSource;
        $relation->sourceReference = $relation->targetReference;
        $relation->target = $target;
        $relation->targetReference = $targetReference;
        if(isset($relation->onDelete)) 
            unset($relation->{'onDelete'});
        if(strcasecmp($relation->type, 'OneToMany')===0){
            $relation->type= 'ManyToOne';
        }elseif(strcasecmp($relation->type, 'ManyToOne') === 0){
            $relation->type = 'OneToMany';
        }
        return $relation;
    }

    public function ImportModels($liveModelList=[]){
        if(false=== $this->RootStorage->exists('app/model')){
            $this->RootStorage->makeDirectory('app/model');
        }
        $this->liveModelList= $liveModelList;
        $modelFiles= preg_grep('/^.*\.(mbp)$/i',$this->BlueprintStorage->files($this->projectPath.'/models'));
        if(empty($modelFiles)){
            // $this->command->info("There are no model files in the sub direcotry [models]");
        }else{
            foreach($modelFiles as $model){
                $m =json_decode($this->BlueprintStorage->get($model));
                foreach ($m as $model) {
                    if (isset($model->modelName)) {
                        $this->processModels($model);
                    }
                }  
            }
            // $this->command->line("Model blueprints imported. Now generating files...");
            $this->BuildModel();
            try {
                // $this->command->info('Now Migrating database to the server...');
                Artisan::call('migrate');
            } catch (Exception $e) {
                throw $e;
            }
            
        }
    }

    public function processModels($model){
        $fieldDefinitions= $model->modelFields??[];
        unset($model->modelFields);
        //<------------------------------------Handle Model Views Definition, Deferred implementation, Needs attention later. 
        unset($model->view);
        $MyModel= new BluePrintsModelFactory((array) $model);
        $this->ModelList[$model->modelName]=$MyModel;
        foreach($fieldDefinitions as $field){
            if(isset($field->relation) && isset($field->relation->target) && isset($field->relation->type)){
                $field->index=true;
                $field->relation->sourceReference = $field->name;
                $MyModel->addRelation($field->relation);//add relation defined in the blueprint

                //if target inverse model is already imported
                if(array_key_exists($field->relation->target, $this->ModelList)){
                    $this->ModelList[$field->relation->target]->addRelation($this->getInverseRelation($field->relation, $MyModel->getModelName(), $field->name));
                }else{//only add to relation list if it's not processed.
                    //add inverse relation for models imported later.
                    $this->relations[$field->relation->target] = $this->getInverseRelation($field->relation, $MyModel->getModelName(), $field->name);
                }
                //if current model has inverse relation
                if(array_key_exists($MyModel->getModelName(),$this->relations)){
                    $MyModel->addRelation($this->relations[$MyModel->getModelName()]);
                }
                unset($field->relation);
            }
            $MyModel->addField($field);
        }
        if(($model->withCRUD??false)===true){
            array_push($this->blueprint->pages,(object)[
                "name"=> "CRUD_". $model->modelName,
                "style"=> "crudSingleton",
                "title" => "'.(isset($". $MyModel->getPrimary().") ? 'Updating information' : 'Creating a new record').'",
                "model"=> (object)[
                    "name"=> $model->modelName,
                    "fields"=> "all"
                ],
                "routes"=> [
                    (object)[
                        "name"=> "crudSingleton_". $model->modelName, 
                            "input" => [(object) [
                            "name" => $MyModel->getPrimary(),
                            "onModel" => $model->modelName,
                            "optional" => true // empty for adding new
                        ]]]
                ]
            ]);
            array_push($this->blueprint->pages, (object) [
                "name" => "CRUDList_" . $model->modelName,
                "style" => "crudSingletonList",
                "title"=> '<a href="\'.route(\'bpr_crudSingleton_'. $model->modelName.'\').\'" class="btn btn-success pull-right">Create New</a><div class="clearfix"></div>',
                "model" => (object) [
                    "name" => $model->modelName,
                    "fields" => "all"
                ],
                "routes" => [
                    (object) [
                        "name" => "crudSingletonList_" . $model->modelName,
                        "input" => []
                    ]
                ]
            ]);
        }
    }

    private function BuildModel(){
        $relations=[];
        foreach ($this->ModelList as $modelName => $model) {
            if($model->buildMigrations()===true){
                $this->counter['tableMigrated']++;
            }

            $relation=$model->getRelations();
            if(!empty($relation) && !array_key_exists($modelName, $this->liveModelList)){
                $relations[$modelName]=$relation;
            }

            $model->BuildModel();
            
        }
        $this->createRelationMigration($relations);
        $this->ViewFactory->setModelList($this->ModelList);
        $this->ControllerFactory->setModelList($this->ModelList);
    }

    private function createRelationMigration($models){
        if($this->counter['tableMigrated']>0){
            $className = 'create_Relations_table'. date('Ymdhis');
            $target = self::migrationPath . date('Y_m_d_his', strtotime('+ 1 second')) . '_' . $className . '.php';
            if ($this->RootStorage->exists($target) === false) {
                try {
                    $relationList = '';
                    $dropList = '';
                    $M2MList='';
                    $M2MTables=[];
                    foreach($models as $model=>$relations){
                        $relationString='';
                        $dropString='';
                        foreach($relations as $relation){
                            $relationString.= '
                            $table->foreign("' . $relation->sourceReference . '") 
                                ->references("' . $relation->targetReference . '")
                                ->on("' . $relation->target . '")'.
                                (isset($relation->onDelete) ? ('->onDelete("' . $relation->onDelete . '")') : '') . ';
                            ';
                            $dropString .= '
                                $table->dropForeign(["' . $relation->sourceReference . '"]);
                            ';
                            if($relation->type == 'ManyToMany'){
                                $tableName = [];
                                array_push($tableName, $model, $relation->target);
                                sort($tableName);
                                $tableName = 'MtoM_' . join('_', $tableName);
                                if(!in_array($tableName, $M2MTables)){
                                    $newField= $this->ModelList[$relation->target]->renderDBField($relation->targetReference, $relation->target . '_', true);
                                    $M2MList .= '
                                    if(false===Schema::hasTable("' . $tableName . '")){
                                            Schema::create("' . $tableName . '", function (Blueprint $table) {
                                                $table->engine = "' . ($this->ModelList[$model]->getModelDefition('engine') ?? 'InnoDB') . '";
                                                $table->charset = "' . ($this->ModelList[$model]->getModelDefition('charset') ?? 'utf8') . '";
                                                $table->collation = "' . ($this->ModelList[$model]->getModelDefition('collation') ?? 'utf8_unicode_ci') . '";
                                                $table->bigIncrements("id");
                                                ' . str_replace(($relation->target . '_' . $relation->targetReference), ($model . '_' . $relation->sourceReference), $newField) . '
                                                ' . $newField . '
                                                
                                                $table->foreign("' . $model . '_' . $relation->sourceReference . '") 
                                                        ->references("' . $relation->sourceReference . '")
                                                        ->on("' . $model . '")->onDelete("cascade");
    
                                                $table->foreign("' . $relation->target . '_' . $relation->targetReference . '") 
                                                        ->references("' . $relation->targetReference . '")
                                                        ->on("' . $relation->target . '")->onDelete("cascade");    
                                                $table->unique(["' . $model . '_' . $relation->sourceReference.'", "' . $relation->target . '_' . $relation->targetReference.'"]);
                                            });
                                        }
                                    ';
                                    $dropList .= '
                                        Schema::dropIfExists("' . $tableName . '");
                                    ';
                                    array_push($M2MTables, $tableName);
                                }
                            }
                        }
                        $relationList .= '
                                if(false!==Schema::hasTable("' . $model . '")){
                                    Schema::table("' . $model . '", function (Blueprint $table) {
                                            '. $relationString .'
                                    });
                                }
                            ';
                        $dropList .= '
                                if(false!==Schema::hasTable("' . $model . '")){
                                    Schema::table("' . $model . '", function (Blueprint $table) {
                                        '.$dropString.'
                                    });
                                }
                            ';
                    }
                    if(strlen($relationList)>0 || strlen($M2MList) > 0){
                        $contents = '
                        <?php
                
                        use Illuminate\Database\Migrations\Migration;
                        use Illuminate\Database\Schema\Blueprint;
                        use Illuminate\Support\Facades\Schema;
                
                        class ' . str_replace('_', '', $className) . ' extends Migration
                        {
                            public function up()
                            {
                                ' . $relationList . '
                                ' . $M2MList . '
                            }
                
                            public function down()
                            {
                                ' . $dropList . '
                            }
                        }
                        ?>';
                        $this->RootStorage->put($target, $contents);
                    }
                } catch (Exception $e) {
                    throw new Exception("Error Creating Migration Relations " . $e->getMessage(), 1);
                }
            }
        }
        // $this->command->line("+ migration created for table relations. ");
    }

//=================================End Model Operation Section=========================


//=================================View and Controller Related Operations=============================


    public function BuildViews(){
        
        foreach($this->ViewList as $viewDefinition){
            $this->ViewFactory->loadDefinition($viewDefinition);
            $this->ViewFactory->buildView();
            // $this->command->line("+ View file generated: ".self::routePath.$viewDefinition['name']);
        }
    }

    public function BuildControllers(){
        foreach ($this->ControllerList as $controllerDefinition) {
            $this->ControllerFactory->loadDefinition($controllerDefinition);
            $this->ControllerFactory->buildController();
            // $this->command->line("+ Controller file generated: ".self::controllerPath.$controllerDefinition['name']);
        }    
    }

//=================================End View and Controller Related Operations=============================

//=================================Route Building Section=========================
    public function BuildRoutes(){
        
        $this->RootStorage->put(self::routePath, "<?php
        /*
        |--------------------------------------------------------------------------
        | Web Routes : This route file is generated by FelaraFrame BluePrints. 
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:This route file is loaded within Boot() method located at /Vendor/Feiron/FelaraFrame/FeLaraFrameServiceProvider.php
        |
        | It is recommended to use Laravel's build in route file for WEB requests located at /routes/web.php
        | Use this file only to make changes to those controllers/files generated by the blueprints.
        |
        */

        Route::group(['namespace' => 'App\Http\Controllers\BluePrints', 'middleware' => ['web']], function () {
            ".join('',array_map(function($routeDefinition){
                return '
                Route::'.strtolower($routeDefinition['type']??'GET').'("'. $this->url(strtolower($routeDefinition['url'])).(count($routeDefinition['input'] ?? [])>0?'/':'').join('/',
                    array_map(function($in){
                        return ('{'. $this->url($in->name).(($in->optional??false)===false?'':'?') .'}');
                    },($routeDefinition['input']??[]))
                ).'", "'. $routeDefinition['targetController']. '@' . $routeDefinition['targetMethod'] . '")->name("bpr_' . $routeDefinition['name'] . '");
                ';
            },($this->routeList??[])))."
        });
        ");
        // $this->command->line("Route file is generated and stored at: ".self::routePath);
    }
//=================================End Route Building Section=========================

    public function buildPageTemplate(){
        $path='resources/views/';
        $contents= "
            @extends('felaraframe::page')

            " . ((!empty($this->blueprint->siteName) ? ("
            @php
            config(['app.name'=>\"" . $this->blueprint->siteName . "\"]);
            @endphp
            ") : "")) .
            "

            " . ((!empty($this->blueprint->favIcon) ? ("
            @section('favicon')
            " . ($this->blueprint->favIcon) . "
            @endsection
            ") : "")) .
            "

            @section('user_name')
            {{Auth::user()->name}}
            @endsection

            ".((!empty($this->blueprint->siteTitle)?("
            @section('title')
            " . $this->blueprint->siteTitle . "
            @endsection
            "):"")).
            "

            " . ((!empty($this->blueprint->siteAuthor) ? ("
            @section('site_author')
            " . $this->blueprint->siteAuthor . "
            @endsection
            ") : "")) .
            "
            
            " . ((!empty($this->blueprint->siteFooter) ? ("
            @section('footer')
            " . ($this->blueprint->siteFooter->footerText??"") . "
            @endsection
            ") : "")) .
            "
            
        ";

        try {
            $this->RootStorage->put($path."page.blade.php",$contents);
        } catch (Exception $e) {
            throw new Exception("Error creating page template.", 1);
            return false;
        }
        return true;
    }

    private function url($url){
        $url = trim($url, "-");
        $url = iconv("utf-8", "us-ascii//TRANSLIT", $url);
        $url = preg_replace('/[^0-9_a-z?]+/iu', '', $url);
        return $url;
    }

    public function PublishTemplate(){
        $sourcePath= dirname($this->BlueprintStorage->path('app')) . '/' . $this->projectPath;
        $targetPath = dirname($this->BlueprintStorage->path('app')) . '/blueprints/cached/live';
        if ($this->BlueprintStorage->exists('/blueprints/cached/live') === false) {
            mkdir($targetPath, 0777, true);
        }

        if (file_exists($sourcePath . "/models")){
            foreach (preg_grep('/^.*\.mbp$/i', scandir($sourcePath . "/models"))??[] as $file) {
                copy($sourcePath . "/models/". $file, $targetPath . "/models/" . $file);
            }
        }
        if (file_exists($sourcePath . "/resources")) {
            foreach (preg_grep('/^.*\.[a-zA-Z]+$/i', scandir($sourcePath . "/resources")) ?? [] as $file) {
                copy($sourcePath . "/resources/" . $file, $targetPath . "/resources/" . $file);
            }
        }

        $this->BlueprintStorage->put('/blueprints/cached/live/Live.bp', json_encode($this->blueprint, JSON_PRETTY_PRINT));
    }
    
}
?>