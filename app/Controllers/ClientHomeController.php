<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Settings;
use App\Models\Package;

/** C1 Home & Packages. */
final class ClientHomeController
{
    public function home(): void
    {
        view('client/home', [
            'title'     => 'Douffer Photography',
            'screen'    => 'C1',
            'navActive' => 'home',
            'packages'  => Package::bookable(),
            'services'  => Package::otherServices(),
            'messenger' => Settings::get('studio_messenger_url'),
        ], 'client');
    }
}
