<?php

// Routes, loaded when the module starts (unless Laravel's routes are cached).
if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
