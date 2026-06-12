<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$suffix = time();
$user = new App\Models\User();
$user->name = 'Test';
$user->username = 'testuser' . $suffix;
$user->email = 'test' . $suffix . '@example.com';
$user->password = Illuminate\Support\Facades\Hash::make('secret1234');
$user->save();

$token = $user->createToken('x')->plainTextToken;

echo $token;
