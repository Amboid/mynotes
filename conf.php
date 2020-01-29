<?php

function conf($key) {
    $conf = [
        'DB_LINK' => 'mysql://mynotes:mn:pss@localhost/mynotes',
        'TB_PREFIX' => 'mn_',
        'ATTACH_DIR' => './attaches',
        'AUTH' => 'yaaaa:h100794',
    ];
    return isset($conf[$key]) ? $conf[$key] : false;
}