<?php

namespace feiron\felaraframe\lib\BluePrints\builders;

use feiron\felaraframe\lib\BluePrints\BluePrintsMethodBuilderBase;

class DisplayCrudTable extends BluePrintsMethodBuilderBase {

    public function __construct($MethodDefinition = null, $ModelList){
        parent::__construct(($MethodDefinition??[]), $ModelList);
        $this->prefixTableName = true;
    }

    public function BuildMethod(): string{
        switch($this->MethodDefinition['usage']??''){
            case "crud_Create":
                return $this->buildCreateEdit();
            case "crud_Update":
                return $this->buildCreateEdit(true);
            case "crud_Delete":
                return $this->buildDelete();
        }
        return '';
    }

    private function buildCreateEdit($isUpdate=false){
        $primary = $this->ModelList[$this->MethodDefinition['model']->name]->getPrimary();
        return $this->buildValidator($isUpdate). '
                    $res=$this->validateRequest($validator, $request);
                    if($res===true){
                        $keyID=$request->input("td_identification");
                        $request->replace($request->only('. (count($this->inputList)>1?('['.join(',',array_map(function($input){return ("'".$input."'");}, $this->inputList)).']'):"'". $this->inputList[0]."'").'));
                        '.($isUpdate===false? '
                        $withData=$this->CRUD_Create($request,'. self::modelClassPrefix . $this->MethodDefinition['model']->name . '::class);
                        ': '
                        $request->merge(["' . $primary . '"=>$keyID]);
                        $withData=$this->CRUD_Update($request,"' . $primary . '",'. self::modelClassPrefix . $this->MethodDefinition['model']->name . '::class);'). '
                    }else{
                        return $res;
                    }
                    
        ';
    }

    private function buildDelete(){
        $primary = $this->ModelList[$this->MethodDefinition['model']->name]->getPrimary();
        return '
                    if($request->filled("td_identification")){
                        $request->merge(["' . $primary . '"=>$request->input("td_identification")]);
                    } 
                    $validator = Validator::make($request->all(), [
                        "' . $primary . '"=>["required"]
                    ]);
                    $res=$this->validateRequest($validator, $request);
                    if($res===true){
                        $withData=$this->CRUD_Delete($request,"' . $primary . '",' . self::modelClassPrefix . $this->MethodDefinition['model']->name . '::class);
                    }else{
                        return $res;
                    }
                    
        ';
    }
}