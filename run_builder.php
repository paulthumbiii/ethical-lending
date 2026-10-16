<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Console\Commands\GenerateCoreBankingModels;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$input = new ArrayInput([]);
$output = new ConsoleOutput();

$command = new GenerateCoreBankingModels();
$command->setOutput(new OutputStyle($input, $output));

$command->handle();