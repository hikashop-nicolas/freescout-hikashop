<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\HikaShop\Http\Controllers'], function () {
    Route::post('/hikashop/ajax', ['uses' => 'HikaShopController@ajax', 'middleware' => ['auth']])->name('hikashop.ajax');

    Route::get('/mailbox/hikashop/{id}', ['uses' => 'HikaShopController@mailboxSettings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.hikashop');
    Route::post('/mailbox/hikashop/{id}', ['uses' => 'HikaShopController@mailboxSettingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.hikashop.save');
});
