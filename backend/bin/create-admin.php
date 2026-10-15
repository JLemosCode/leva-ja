<?php
// php bin/create-admin.php email senha "Nome"
require __DIR__ . '/../src/bootstrap.php';
[$_, $email, $pw, $name] = $argv + [null, null, null, 'Administrador'];
if (!$email || strlen((string)$pw) < 8) exit("Uso: php bin/create-admin.php email senha(min 8) [nome]\n");
App\Core\Db::run("INSERT INTO users (role,name,email,password_hash) VALUES ('admin',?,?,?)", [$name, strtolower($email), password_hash($pw, PASSWORD_DEFAULT)]);
echo "Admin criado.\n";
