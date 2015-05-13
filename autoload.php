<?php

spl_autoload_register(function($class){
    if (false !== stripos($class, 'Zhimei\LaravelWechat')) {
        require_once __DIR__ ."/src/". str_replace('\\', '/', substr($class, 21)) . ".php";
    }
	
});

require_once __DIR__."/src/helps.php";