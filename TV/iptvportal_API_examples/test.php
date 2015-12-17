<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
// Пакеты программ
$packages['498'] = 'Расширенный';
$packages['499'] = 'Наш футбол';
$packages['500'] = 'Amedia Premium';
$packages['501'] = 'Взрослый';

//$p_package = '498';

if (isset($packages[$p_package])) {
   echo $packages[$p_package];
} else {
   echo "Not set";
}

