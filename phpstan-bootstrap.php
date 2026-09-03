<?php

require_once __DIR__.'/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/.env');