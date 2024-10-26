<?php

function dataManipulation($conn, $data)
{
    $manipulatedData = array(
        'headers' => [],
        'data' => []
    );


    //getData
    function getData($manipulatedData, $data)
    {
        //getExtraColumnsAndValues
        $extraColumnsAndValues = [];
        if ($data['payload']) {
            foreach ($data['payload'] as $key => $task) {
                //Put extraColumnsAndValues and task data together
                $filteredTask = [];
                foreach ($task as $key => $value) {
                    switch ($key) {
                        // case 'extra_column':
                        //     break;

                        // case 'extra_column_value':
                        //     break;

                        case 'responsibles_string':
                            $filteredTask['responsibles'] = $task['responsibles_string'] ? explode(",", $task['responsibles_string']) : [];
                            break;

                        default:
                            $filteredTask[$key] = $value;
                            break;
                    }
                }

                $taskIds = array_column($manipulatedData['data'], 'id');
                if (!in_array($task['id'], $taskIds)) {
                    $manipulatedData['data'][] = $filteredTask;
                }
            }
            return $manipulatedData;
        } else {
            $manipulatedData = array(
                'status' => 500,
                'errorInfo' => 'Nincsen feladat'
            );
            return $manipulatedData;
        }
    }

    //getHeaders
    function getHeaders($manipulatedData, $data)
    {
        if ($manipulatedData['data']) {
            foreach ($manipulatedData['data'] as $key => $task) {
                foreach ($task as $key => $header) {
                    switch ($key) {
                        case 'extra_column_value':
                            break;

                        case 'extra_column_permission':
                            break;

                        case 'status_color':
                            break;

                        case 'id';
                            break;

                        case 'responsibles_string':
                            break;

                        case 'type':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Típus',
                                    'dbTable' => 'Task_types',
                                    'dbColumn' => 'name',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'status_partner':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Státusz(partner)',
                                    'dbTable' => 'Task_statuses',
                                    'dbColumn' => 'name',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'status_exohu':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Státusz(exohu)',
                                    'dbTable' => 'Task_statuses',
                                    'dbColumn' => 'name',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'zip':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Zip',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'zip',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'city':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Település',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'city',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'address':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Cím',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'address',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'location_type':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Lokáció típus',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'type',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'fixing_method':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Rögzítési mód',
                                    'dbTable' => 'Task_locations',
                                    'dbColumn' => 'fixing_method',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'responsibles':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Megbízottak',
                                    'dbTable' => 'Task_responsibles',
                                    'dbColumn' => 'id',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'planned_delivery_date':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Kivitelezési dátum(terv)',
                                    'dbTable' => 'Task_dates',
                                    'dbColumn' => 'planned_delivery_date',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'delivery_date':
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'value'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => 'Kivitelezési dátum(tény)',
                                    'dbTable' => 'Task_dates',
                                    'dbColumn' => 'delivery_date',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;

                        case 'extra_column':
                            $exists = in_array($task[$key], array_column($manipulatedData['headers'], 'value'));
                            if (!$exists && $task[$key] != NULL) {
                                $manipulatedData['headers'][] = array(
                                    'text' => $header,
                                    'dbTable' => 'Task_additional_info',
                                    'dbColumn' => 'name',
                                    'isReadonly' => false,
                                    'align' => 'start',
                                    'filterable' => true,
                                    'value' => $task[$key]
                                );
                            }
                            break;

                        default:
                            $exists = in_array($key, array_column($manipulatedData['headers'], 'text'));
                            if (!$exists) {
                                $manipulatedData['headers'][] = array(
                                    'text' => $key,
                                    'dbTable' => 'Task_additional_info',
                                    'dbColumn' => 'name',
                                    'align' => 'start',
                                    'isReadonly' => false,
                                    'filterable' => true,
                                    'value' => $key
                                );
                            }
                            break;
                    }
                }
            }
            return $manipulatedData;
        } else {
            $manipulatedData = array(
                'status' => 500,
                'errorInfo' => 'Nincsen feladat'
            );
            return $manipulatedData;
        }
    }

    $manipulatedData = getData($manipulatedData, $data);
    $manipulatedData = getHeaders($manipulatedData, $data);

    return $manipulatedData;
}
