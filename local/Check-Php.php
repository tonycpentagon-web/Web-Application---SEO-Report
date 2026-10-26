<?php
if(PHP_VERSION_ID<80200){fwrite(STDERR,"PHP 8.2 or newer is required.\n");exit(1);}
foreach(['ctype','curl','dom','fileinfo','filter','hash','mbstring','openssl','pdo_sqlite','session','tokenizer','xml','zip'] as $ext)if(!extension_loaded($ext)){fwrite(STDERR,'Enable PHP extension: '.$ext.PHP_EOL);exit(1);}
require dirname(__DIR__).'/backend/vendor/composer/platform_check.php';
echo 'PHP platform/extensions checked.'.PHP_EOL;
