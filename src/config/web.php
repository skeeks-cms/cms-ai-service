<?php

use skeeks\cms\ai\service\TaskToolProvider;

return [
    'params' => [
        'cmsMcp' => ['toolProviders' => [TaskToolProvider::class]],
    ],
];
