@php($rows = $getState() ?? [])
@include('filament.admin.audit.changes-table', ['rows' => $rows])
