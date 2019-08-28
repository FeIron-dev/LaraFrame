@extends('felaraframe::layout')

@prepend('footerscripts')
<script type="text/javascript" src="{{asset('/feiron/felaraframe/plugins/SortableMaster/Sortable.min.js')}}"></script> <!-- Sortable  MANDATORY-->
<script type="text/javascript" src="{{asset('/feiron/felaraframe/js/dashboard.js')}}"></script> <!-- DashBoard driver MANDATORY -->
<script type="text/javascript" src="{{asset('/feiron/felaraframe/widgets/WidgetAjax.js')}}"></script> <!-- Widget control script -->
@endprepend

@push('headerstyles')
<link href="{{asset('/feiron/felaraframe/css/dashboard.css')}}" rel="stylesheet"> <!-- MANDATORY -->
@endpush

@section('content')
<div class="widgetArea" id="fe_widgetArea">
    <div class="list-group" id="fe_widgetCtrls">
        @yield('Widget_Area')
    </div>
</div>
@endsection