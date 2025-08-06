<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use App\Command\import;
use Symfony\Component\Console\Application;

$application = new Application();

// ... register commands

$application->add(new import());

$application->run();
