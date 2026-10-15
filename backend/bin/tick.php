<?php
// Cron (a cada minuto): php /home/USUARIO/app/bin/tick.php [--loop]
require __DIR__ . '/../src/bootstrap.php';
use App\Core\Db; use App\Services\{Dispatch, Settings};
$end = in_array('--loop', $argv ?? [], true) ? time() + 50 : 0;   // --loop: repete a cada 10 s durante o minuto
do {
    Dispatch::tick();
    if (date('i') === '00' || !$end) {
        $d = Settings::int('gps_retention_days') ?: 30;
        Db::run("DELETE FROM courier_location_history WHERE received_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 5000", [$d]);
        Db::run("DELETE FROM webhook_events WHERE received_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }
    if ($end) sleep(10);
} while ($end && time() < $end);
