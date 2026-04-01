<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {

    //      :)
    $response = Http::withToken(env('BITECH_TOKEN'))->withoutVerifying()
        ->get("https://illuminate.bitech.com.sa/api/challenge/ssh-key");

    dd($response->json());
});
