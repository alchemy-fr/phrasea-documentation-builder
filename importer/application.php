<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use App\Command\build;
use Symfony\Component\Console\Application;

$application = new Application();

// ... register commands

$application->add(new build());

$application->run();
