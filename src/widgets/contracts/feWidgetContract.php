<?php
namespace feiron\felaraframe\widgets\contracts;

interface feWidgetContract{

    //get WidgetName for the Widget Manager
    public function WidgetName(): string;

    //render widget contents
    public function render();

    //responsible for building widget specific data as part of the widget output. for parameter [WidgetData]
    public function dataFunction();

    //responsible for returning ajax data.
    public function renderAjax($request);

    //responsible for polymorphic classes to build their ajax data
    public function getAjaxData($request);

    //set the control ID
    public function SetID($name);

    //front end settings available to users.
    public static function userSettingOutlet();

    public function getWidgetSettings();

    public function getHeaderScripts(): array;
    public function getHeaderStyle(): array;
    public function getFooterScripts(): array;
    public function getFooterStyle(): array;
}
?>